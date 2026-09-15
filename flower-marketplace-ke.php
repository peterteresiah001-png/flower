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
	require_once FMKE_PATH . 'includes/class-flash-sales.php';
	require_once FMKE_PATH . 'includes/class-best-sellers.php';
	require_once FMKE_PATH . 'includes/class-trending-products.php';
	require_once FMKE_PATH . 'includes/class-site-footer.php';

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
} );
