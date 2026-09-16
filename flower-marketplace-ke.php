<?php
/**
 * Plugin Name: Flower Marketplace KE - Core Add-ons
 * Description: MVP add-ons for a multi-vendor flowers/gifts/event-rentals marketplace on WooCommerce + Dokan. Adds M-Pesa STK Push, IntaSend/Pesapal hosted checkout, manual WhatsApp dispatch, and an 80/20 commission default.
 * Version: 1.0.0
 * Author: Flower Marketplace KE
 * Requires Plugins: woocommerce, dokan-lite
 * Text Domain: fmke
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'FMKE_PATH', plugin_dir_path( __FILE__ ) );
define( 'FMKE_URL', plugin_dir_url( __FILE__ ) );

// Loaded unconditionally (not inside fmke_bootstrap) so its function is
// available even during register_activation_hook, which can run before
// 'plugins_loaded' fires for this file on the very first activation request.
require_once FMKE_PATH . 'includes/class-commission-setup.php';

// Also registered unconditionally, same reasoning: register_activation_hook's
// callback calls wp_schedule_event() with this custom interval, and that
// happens in the SAME request that's activating this very plugin - meaning
// 'plugins_loaded' (where fmke_bootstrap() normally runs) never fires for it
// that request. If this filter lived inside fmke_bootstrap() instead, the
// schedule wouldn't exist yet and wp_schedule_event() would silently fail.
add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['fmke_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => 'Every 5 Minutes (Flower Marketplace KE M-Pesa reconciliation)',
	);
	return $schedules;
} );

/**
 * Creates the "My Wishlist" page (with the [fmke_wishlist] shortcode) if
 * one doesn't already exist. Defined directly in this always-loaded file
 * (not inside class-wishlist.php) so it's guaranteed available during
 * register_activation_hook, same reasoning as the commission-setup fix.
 */
function fmke_create_wishlist_page() {
	$existing_id = get_option( 'fmke_wishlist_page_id' );

	if ( $existing_id && get_post( $existing_id ) ) {
		return; // Already created, don't make a duplicate.
	}

	$page_id = wp_insert_post( array(
		'post_title'   => 'My Wishlist',
		'post_content' => '[fmke_wishlist]',
		'post_status'  => 'publish',
		'post_type'    => 'page',
	) );

	if ( $page_id && ! is_wp_error( $page_id ) ) {
		update_option( 'fmke_wishlist_page_id', $page_id );
	}
}

/**
 * Creates the table that backs vendor withdrawal requests. Defined
 * directly in this always-loaded file (not inside
 * class-vendor-withdrawals.php) so it's guaranteed available during
 * register_activation_hook, same reasoning as the commission-setup and
 * wishlist-page fixes above. dbDelta() is safe to run repeatedly - it
 * only creates what's missing, so this also doubles as the update path
 * for sites that already had the plugin active before this table
 * existed (see FMKE_Vendor_Withdrawals::maybe_create_table()).
 */
function fmke_create_withdrawals_table() {
	global $wpdb;

	$table           = $wpdb->prefix . 'fmke_withdrawals';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		vendor_id BIGINT UNSIGNED NOT NULL,
		amount DECIMAL(19,4) NOT NULL,
		method VARCHAR(20) NOT NULL,
		payment_details TEXT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		vendor_note TEXT NULL,
		admin_note TEXT NULL,
		requested_at DATETIME NOT NULL,
		processed_at DATETIME NULL,
		processed_by BIGINT UNSIGNED NULL,
		PRIMARY KEY  (id),
		KEY vendor_id (vendor_id),
		KEY status (status)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Creates the table that backs vendor (store-level) reviews. Same
 * always-loaded reasoning as fmke_create_withdrawals_table() above.
 */
function fmke_create_vendor_reviews_table() {
	global $wpdb;

	$table           = $wpdb->prefix . 'fmke_vendor_reviews';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		vendor_id BIGINT UNSIGNED NOT NULL,
		customer_id BIGINT UNSIGNED NOT NULL,
		order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		rating TINYINT UNSIGNED NOT NULL,
		comment TEXT NULL,
		vendor_reply TEXT NULL,
		vendor_replied_at DATETIME NULL,
		is_hidden TINYINT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY vendor_customer (vendor_id, customer_id),
		KEY vendor_id (vendor_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Check dependencies before doing anything.
 */
function fmke_check_dependencies() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Flower Marketplace KE:</strong> WooCommerce must be installed and active.</p></div>';
		} );
		return false;
	}
	if ( ! function_exists( 'dokan' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-warning"><p><strong>Flower Marketplace KE:</strong> Dokan (Lite) is not active. Vendor/commission features will not work until it is installed.</p></div>';
		} );
	}
	return true;
}
add_action( 'plugins_loaded', 'fmke_bootstrap', 20 );

function fmke_bootstrap() {
	if ( ! fmke_check_dependencies() ) {
		return;
	}

	require_once FMKE_PATH . 'includes/class-wc-gateway-mpesa-stk.php';
	require_once FMKE_PATH . 'includes/class-wc-gateway-hosted-checkout.php';
	require_once FMKE_PATH . 'includes/class-whatsapp-dispatch.php';
	require_once FMKE_PATH . 'includes/class-live-search.php';
	require_once FMKE_PATH . 'includes/class-account-dropdown.php';
	require_once FMKE_PATH . 'includes/class-wishlist.php';
	require_once FMKE_PATH . 'includes/class-cart-counter.php';
	require_once FMKE_PATH . 'includes/class-shop-sidebar.php';
	require_once FMKE_PATH . 'includes/class-category-shortcuts.php';
	require_once FMKE_PATH . 'includes/class-homepage-banners.php';
	require_once FMKE_PATH . 'includes/class-top-categories.php';
	require_once FMKE_PATH . 'includes/class-featured-vendors.php';
	require_once FMKE_PATH . 'includes/class-service-badges.php';
	require_once FMKE_PATH . 'includes/class-trust-badges.php';
	require_once FMKE_PATH . 'includes/class-delivery-timeframe.php';
	require_once FMKE_PATH . 'includes/class-flash-sales.php';
	require_once FMKE_PATH . 'includes/class-best-sellers.php';
	require_once FMKE_PATH . 'includes/class-trending-products.php';
	require_once FMKE_PATH . 'includes/class-site-footer.php';
	require_once FMKE_PATH . 'includes/class-vendor-earnings.php';
	require_once FMKE_PATH . 'includes/class-vendor-withdrawals.php';
	require_once FMKE_PATH . 'includes/class-vendor-reviews.php';
	require_once FMKE_PATH . 'includes/class-order-notifications.php';
	require_once FMKE_PATH . 'includes/class-order-status-notifications.php';

	// Register payment gateways with WooCommerce.
	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WC_Gateway_Mpesa_STK';
		$gateways[] = 'WC_Gateway_Hosted_Checkout';
		return $gateways;
	} );

	// Registered here (not inside the gateway class constructor) because
	// WooCommerce only instantiates gateway objects lazily when a checkout
	// template renders - that never happens on an admin-post.php request,
	// so hooks added only in the constructor would never be registered
	// in time for this handler to fire.
	add_action( 'admin_post_fmke_demo_stk', 'fmke_handle_demo_stk_screen' );
	add_action( 'admin_post_nopriv_fmke_demo_stk', 'fmke_handle_demo_stk_screen' );

	// Init WhatsApp dispatch (order meta box + admin settings).
	new FMKE_Whatsapp_Dispatch();

	// --- M-Pesa payment safety net: reconcile orders whose Daraja
	// callback never arrived. Registered here (not in the gateway
	// class constructor) for the same reason as the demo-STK hooks
	// above: WooCommerce only instantiates gateway objects lazily,
	// which a wp-cron.php request or an order-edit screen action can't
	// rely on. (The 'fmke_five_minutes' cron_schedules filter itself is
	// registered unconditionally near the top of this file - see the
	// comment there for why it can't live in here.) ---

	add_action( 'fmke_mpesa_reconcile', 'fmke_run_mpesa_reconciliation' );

	// Manual "check now" action for a single stuck order, from WP Admin > Orders > [order] > Order actions.
	add_filter( 'woocommerce_order_actions', 'fmke_add_mpesa_check_status_action' );
	add_action( 'woocommerce_order_action_fmke_check_mpesa_status', 'fmke_handle_mpesa_check_status_action' );
}

/**
 * Cron callback: polls Daraja directly for any M-Pesa order that's been
 * on-hold for a while with no callback, so a dropped/late Safaricom
 * callback doesn't leave an order (and the vendor's earnings) stuck
 * forever. See WC_Gateway_Mpesa_STK::reconcile_pending_payments().
 */
function fmke_run_mpesa_reconciliation() {
	if ( ! class_exists( 'WC_Gateway_Mpesa_STK' ) ) {
		return;
	}
	WC_Gateway_Mpesa_STK::reconcile_pending_payments();
}

/**
 * Adds "Check M-Pesa payment status now" to the Order actions dropdown
 * on an order's edit screen, but only for M-Pesa orders still on-hold -
 * lets an admin resolve one stuck order immediately instead of waiting
 * for the next 5-minute cron run.
 */
function fmke_add_mpesa_check_status_action( $actions ) {
	global $theorder;
	if ( $theorder && 'mpesa_stk' === $theorder->get_payment_method() && $theorder->has_status( 'on-hold' ) ) {
		$actions['fmke_check_mpesa_status'] = 'Check M-Pesa payment status now';
	}
	return $actions;
}

function fmke_handle_mpesa_check_status_action( $order ) {
	if ( ! class_exists( 'WC_Gateway_Mpesa_STK' ) ) {
		return;
	}
	$gateway = new WC_Gateway_Mpesa_STK();
	$gateway->query_and_apply_status( $order );
}

/**
 * Renders the demo STK "check your phone" screen. Defined as a standalone
 * function (not a class method call directly) so it works reliably on
 * admin-post.php requests - see the comment in fmke_bootstrap() above.
 */
function fmke_handle_demo_stk_screen() {
	if ( ! class_exists( 'WC_Gateway_Mpesa_STK' ) ) {
		wp_die( 'M-Pesa gateway is not available.' );
	}
	$gateway = new WC_Gateway_Mpesa_STK();
	$gateway->handle_demo_stk_screen();
}

/**
 * On activation:
 * - Create default product categories (Flowers, Gifts, Event Rentals)
 * - Set Dokan commission default to 20% admin / 80% vendor
 */
register_activation_hook( __FILE__, function () {

	// 1. Default marketplace categories.
	$categories = array( 'Flowers', 'Gifts', 'Event Rentals' );
	foreach ( $categories as $cat ) {
		if ( ! term_exists( $cat, 'product_cat' ) ) {
			wp_insert_term( $cat, 'product_cat' );
		}
	}

	// 2. Default commission (also configurable at Dokan > Settings > Selling Options).
	fmke_set_default_commission();

	// 3. Create the wishlist page so [fmke_wishlist] has somewhere to live.
	fmke_create_wishlist_page();

	// 4. Create the table that stores vendor withdrawal requests.
	fmke_create_withdrawals_table();

	// 5. Create the table that stores vendor (store-level) reviews.
	fmke_create_vendor_reviews_table();

	// 6. Schedule the M-Pesa payment reconciliation cron - a safety net
	// for STK Push orders whose Daraja callback never arrives.
	if ( ! wp_next_scheduled( 'fmke_mpesa_reconcile' ) ) {
		wp_schedule_event( time(), 'fmke_five_minutes', 'fmke_mpesa_reconcile' );
	}
} );

/**
 * On deactivation: stop the M-Pesa reconciliation cron so it doesn't
 * keep firing (and failing to find its dependencies) once the plugin
 * providing WC_Gateway_Mpesa_STK is switched off.
 */
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'fmke_mpesa_reconcile' );
} );
