<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivery vs Store Pickup at checkout.
 *
 * Two fulfilment options, implemented as real WooCommerce shipping methods
 * rather than a bolted-on checkout radio button, so the cart totals, order
 * shipping line, tax handling and the "does this order need a shipping
 * address" logic all behave correctly for free:
 *
 *   A) Delivery      - flat KES 350 (configurable per shipping zone), with
 *                      an optional free-over-X threshold.
 *   B) Store Pickup   - KES 0, customer collects from the VENDOR'S OWN
 *                      physical store address, not a marketplace depot.
 *
 * Because this is a Dokan multi-vendor marketplace, pickup is vendor-scoped:
 * the address shown is the one the selling vendor set on their own dashboard
 * (Dokan Dashboard > Store Pickup), and pickup is only offered when
 *   - that vendor has switched pickup on and saved a real address, and
 *   - every item in the shipping package comes from that one vendor.
 * A cart mixing three vendors would otherwise mean three different collection
 * points for one order, which is not a thing a customer can act on. Dokan Lite
 * doesn't split shipping packages per vendor, so this reads the package
 * contents directly and works whether or not something else (Dokan Pro, a
 * package-splitting plugin) splits them.
 *
 * Knock-on effects handled here so no other class needs to care:
 *   - Pickup orders don't ask for a shipping address at checkout.
 *   - "Pay on Delivery" is hidden on pickup orders - its whole flow is a
 *     rider collecting M-Pesa at the customer's door, which doesn't exist
 *     when the customer walks into the shop. They pay normally at checkout.
 *   - The collection address + hours are written onto the order and shown on
 *     the thank-you page, the customer's order view, every order email, and
 *     the admin/vendor order screen.
 *   - Pickup eligibility is re-checked at submit, not just at render, so a
 *     vendor switching pickup off mid-session can't produce an order nobody
 *     can fulfil.
 */
class FMKE_Store_Pickup {

	/** Shipping method IDs. */
	const METHOD_PICKUP   = 'fmke_store_pickup';
	const METHOD_DELIVERY = 'fmke_flat_delivery';

	/** Order meta. */
	const META_IS_PICKUP  = '_fmke_is_pickup';
	const META_VENDOR_ID  = '_fmke_pickup_vendor_id';
	const META_STORE_NAME = '_fmke_pickup_store_name';
	const META_ADDRESS    = '_fmke_pickup_address';
	const META_HOURS      = '_fmke_pickup_hours';

	public function __construct() {
		// Define + register the two shipping methods.
		add_action( 'woocommerce_shipping_init', 'fmke_load_pickup_shipping_methods' );
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_methods' ) );

		// Checkout behaviour.
		add_filter( 'woocommerce_cart_needs_shipping_address', array( $this, 'maybe_skip_shipping_address' ) );
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'render_pickup_hint' ), 10, 2 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_pickup_still_valid' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_order' ), 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_gateways_for_pickup' ) );

		// Display the collection details everywhere an address would normally show.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_block' ) );
		add_action( 'woocommerce_email_order_meta', array( $this, 'render_email_block' ), 20, 3 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'render_admin_block' ) );

		// Vendor-facing settings (dashboard page + wp-admin fallback).
		new FMKE_Pickup_Vendor_Settings();
	}

	/* ================= shipping method registration ================= */

	public function register_shipping_methods( $methods ) {
		$methods[ self::METHOD_DELIVERY ] = 'WC_Shipping_FMKE_Flat_Delivery';
		$methods[ self::METHOD_PICKUP ]   = 'WC_Shipping_FMKE_Store_Pickup';
		return $methods;
	}

	/* ================= vendor resolution ================= */

	/**
	 * Seller (vendor) user ID for a product - the product's post author, the
	 * same way Dokan itself resolves it. Kept identical to the logic in
	 * class-wc-gateway-pay-on-delivery.php so the two features can never
	 * disagree about who the seller is.
	 *
	 * @param int $product_id
	 * @return int 0 if it can't be resolved.
	 */
	public static function get_product_vendor_id( $product_id ) {
		$vendor_id = (int) get_post_field( 'post_author', $product_id );
		return $vendor_id ?: 0;
	}

	/**
	 * The one vendor selling everything in this shipping package.
	 *
	 * @param array $package WooCommerce shipping package.
	 * @return int Vendor user ID, or 0 if the package is empty, mixes vendors,
	 *             or has an unresolvable seller.
	 */
	public static function get_sole_vendor_for_package( $package ) {
		if ( empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
			return 0;
		}

		$vendor_ids = array();
		foreach ( $package['contents'] as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( ! $product_id ) {
				return 0;
			}
			$vendor_id = self::get_product_vendor_id( $product_id );
			if ( ! $vendor_id ) {
				return 0; // Unknown seller - don't guess a collection address.
			}
			$vendor_ids[ $vendor_id ] = true;
		}

		if ( 1 !== count( $vendor_ids ) ) {
			return 0; // Mixed vendors = more than one shop to collect from.
		}

		// reset()/key() rather than array_key_first(), which is PHP 7.3+ -
		// this plugin doesn't otherwise require anything that new.
		reset( $vendor_ids );
		return (int) key( $vendor_ids );
	}

	/**
	 * Same question, asked of the current cart rather than one package -
	 * used at submit time and when stamping the order, where WooCommerce's
	 * packages aren't the convenient handle.
	 *
	 * @return int
	 */
	public static function get_sole_vendor_for_cart() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return 0;
		}
		return self::get_sole_vendor_for_package( array( 'contents' => WC()->cart->get_cart() ) );
	}

	/* ================= checkout behaviour ================= */

	/**
	 * True when every shipping method the customer has chosen is our pickup
	 * method. "Every" rather than "any" matters: if a package-splitting setup
	 * ever produces a part-pickup/part-delivery order, that still needs a
	 * delivery address for the half being delivered.
	 *
	 * @return bool
	 */
	public static function pickup_is_chosen() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$chosen = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen ) || ! is_array( $chosen ) ) {
			return false;
		}

		foreach ( $chosen as $method ) {
			if ( 0 !== strpos( (string) $method, self::METHOD_PICKUP ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Nobody needs to type a delivery address for an order they're walking in
	 * to collect.
	 */
	public function maybe_skip_shipping_address( $needs_address ) {
		return self::pickup_is_chosen() ? false : $needs_address;
	}

	/**
	 * Show the actual collection address underneath the pickup option in the
	 * cart/checkout totals, so the customer can see where they'd be going
	 * before they commit to it.
	 */
	public function render_pickup_hint( $method, $index ) {
		if ( ! $method instanceof WC_Shipping_Rate || self::METHOD_PICKUP !== $method->get_method_id() ) {
			return;
		}

		$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();
		if ( ! is_array( $chosen ) || ! in_array( $method->get_id(), $chosen, true ) ) {
			return; // Only expand the details for the option actually selected.
		}

		$vendor_id = self::get_sole_vendor_for_cart();
		if ( ! $vendor_id ) {
			return;
		}

		$address = FMKE_Pickup_Vendor_Settings::get_pickup_address( $vendor_id );
		$hours   = FMKE_Pickup_Vendor_Settings::get_pickup_hours( $vendor_id );

		if ( ! $address ) {
			return;
		}

		echo '<div class="fmke-pickup-hint" style="margin-top:6px;font-size:0.9em;line-height:1.5;">';
		echo '<strong>Collect from:</strong><br />';
		echo nl2br( esc_html( $address ) );
		if ( $hours ) {
			echo '<br /><strong>Collection hours:</strong> ' . esc_html( $hours );
		}
		echo '</div>';
	}

	/**
	 * Re-check at submit, not just at render. Between the customer loading
	 * checkout and pressing Place Order, the vendor can switch pickup off or
	 * the customer can add a second vendor's product in another tab - either
	 * would otherwise produce an order with a collection point that no longer
	 * exists.
	 */
	public function validate_pickup_still_valid() {
		if ( ! self::pickup_is_chosen() ) {
			return;
		}

		$vendor_id = self::get_sole_vendor_for_cart();

		if ( ! $vendor_id ) {
			wc_add_notice(
				'Store Pickup is only available when your whole order comes from one vendor. Please switch to Delivery, or place separate orders for each shop.',
				'error'
			);
			return;
		}

		if ( ! FMKE_Pickup_Vendor_Settings::vendor_offers_pickup( $vendor_id ) ) {
			wc_add_notice(
				'This vendor is no longer offering store pickup. Please choose Delivery instead.',
				'error'
			);
		}
	}

	/**
	 * Freeze the collection details onto the order. Copied rather than
	 * referenced so an order placed today still shows the address it was
	 * placed against, even if the vendor moves shop next month.
	 */
	public function stamp_order( $order, $data ) {
		if ( ! self::pickup_is_chosen() ) {
			return;
		}

		$vendor_id = self::get_sole_vendor_for_cart();
		if ( ! $vendor_id ) {
			return;
		}

		$address = FMKE_Pickup_Vendor_Settings::get_pickup_address( $vendor_id );
		$hours   = FMKE_Pickup_Vendor_Settings::get_pickup_hours( $vendor_id );
		$store   = FMKE_Pickup_Vendor_Settings::get_store_name( $vendor_id );

		$order->update_meta_data( self::META_IS_PICKUP, 'yes' );
		$order->update_meta_data( self::META_VENDOR_ID, $vendor_id );
		$order->update_meta_data( self::META_STORE_NAME, $store );
		$order->update_meta_data( self::META_ADDRESS, $address );
		$order->update_meta_data( self::META_HOURS, $hours );

		$note = 'Store Pickup: the customer is collecting this order in person from ' . ( $store ?: 'the vendor' ) . '. Do not dispatch a rider.';
		if ( $address ) {
			$note .= ' Collection address on file: ' . preg_replace( '/\s+/', ' ', $address );
		}
		$order->add_order_note( $note );
	}

	/**
	 * @param WC_Order|int $order
	 * @return bool
	 */
	public static function order_is_pickup( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		return $order ? 'yes' === $order->get_meta( self::META_IS_PICKUP ) : false;
	}

	/**
	 * Pay on Delivery is a rider-at-the-door flow: the customer pays nothing
	 * at checkout, then an STK prompt or Paybill read-out happens on the
	 * doorstep. None of that exists for a walk-in collection, and leaving it
	 * available would create unpaid orders with no defined moment of payment.
	 * Hidden here rather than inside the gateway so the gateway keeps its own
	 * concern (delivery cities) and this file keeps its own (pickup).
	 */
	public function filter_gateways_for_pickup( $gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		if ( ! self::pickup_is_chosen() ) {
			return $gateways;
		}

		unset( $gateways['fmke_pay_on_delivery'] );
		return $gateways;
	}

	/* ================= display ================= */

	/**
	 * @param WC_Order $order
	 * @return array{store:string,address:string,hours:string}|null
	 */
	private static function get_pickup_details( $order ) {
		if ( ! self::order_is_pickup( $order ) ) {
			return null;
		}
		return array(
			'store'   => (string) $order->get_meta( self::META_STORE_NAME ),
			'address' => (string) $order->get_meta( self::META_ADDRESS ),
			'hours'   => (string) $order->get_meta( self::META_HOURS ),
		);
	}

	/** Thank-you page and the customer's My Account > Order view. */
	public function render_customer_block( $order ) {
		$details = self::get_pickup_details( $order );
		if ( ! $details ) {
			return;
		}

		echo '<section class="fmke-pickup-details" style="margin-bottom:2em;">';
		echo '<h2>Collecting your order</h2>';
		echo '<p>This order is for <strong>store pickup</strong> - nothing will be delivered. Please collect it from:</p>';
		echo '<p>';
		if ( $details['store'] ) {
			echo '<strong>' . esc_html( $details['store'] ) . '</strong><br />';
		}
		echo nl2br( esc_html( $details['address'] ) );
		echo '</p>';
		if ( $details['hours'] ) {
			echo '<p><strong>Collection hours:</strong> ' . esc_html( $details['hours'] ) . '</p>';
		}
		echo '<p><em>Please bring your order number (#' . esc_html( $order->get_order_number() ) . ') and wait for the vendor to confirm your order is ready before travelling.</em></p>';
		echo '</section>';
	}

	/** Appended to every order email (customer, vendor and admin alike). */
	public function render_email_block( $order, $sent_to_admin, $plain_text ) {
		$details = self::get_pickup_details( $order );
		if ( ! $details ) {
			return;
		}

		$heading = $sent_to_admin ? 'Store Pickup order - no delivery required' : 'Collecting your order';

		if ( $plain_text ) {
			echo "\n\n" . strtoupper( $heading ) . "\n";
			if ( $details['store'] ) {
				echo $details['store'] . "\n";
			}
			echo $details['address'] . "\n";
			if ( $details['hours'] ) {
				echo 'Collection hours: ' . $details['hours'] . "\n";
			}
			return;
		}

		echo '<h2>' . esc_html( $heading ) . '</h2>';
		echo '<p>';
		if ( $details['store'] ) {
			echo '<strong>' . esc_html( $details['store'] ) . '</strong><br />';
		}
		echo nl2br( esc_html( $details['address'] ) );
		if ( $details['hours'] ) {
			echo '<br /><strong>Collection hours:</strong> ' . esc_html( $details['hours'] );
		}
		echo '</p>';
	}

	/** WP Admin order edit screen, directly under where the shipping address would be. */
	public function render_admin_block( $order ) {
		$details = self::get_pickup_details( $order );
		if ( ! $details ) {
			return;
		}

		echo '<div class="fmke-pickup-admin" style="margin-top:12px;padding:10px;border-left:4px solid #2271b1;background:#f6f7f7;">';
		echo '<p style="margin:0 0 6px;"><strong>Store Pickup - customer collects, do not dispatch a rider.</strong></p>';
		if ( $details['store'] ) {
			echo '<p style="margin:0;"><strong>' . esc_html( $details['store'] ) . '</strong></p>';
		}
		echo '<p style="margin:0;">' . nl2br( esc_html( $details['address'] ) ) . '</p>';
		if ( $details['hours'] ) {
			echo '<p style="margin:6px 0 0;"><em>Collection hours: ' . esc_html( $details['hours'] ) . '</em></p>';
		}
		echo '</div>';
	}

	/* ================= one-time install into shipping zones ================= */

	/**
	 * Adds both methods to the store's shipping zones on first run, so the
	 * options actually appear at checkout without the admin having to know
	 * that a WooCommerce shipping method does nothing until it's placed in a
	 * zone. Runs once (guarded by an option), touches only zones that don't
	 * already have the method, and never removes anything - so an admin who
	 * deliberately deletes one of them doesn't get it silently re-added.
	 */
	public static function maybe_install_into_zones() {
		if ( '1' === get_option( 'fmke_pickup_zones_installed' ) ) {
			return;
		}
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return; // WooCommerce not ready yet; try again on a later request.
		}

		$zone_ids = array( 0 ); // 0 = "Locations not covered by your other zones".
		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone_ids[] = (int) $zone_data['zone_id'];
		}

		foreach ( $zone_ids as $zone_id ) {
			$zone = WC_Shipping_Zones::get_zone( $zone_id );
			if ( ! $zone ) {
				continue;
			}

			$existing = array();
			foreach ( $zone->get_shipping_methods( false ) as $method ) {
				$existing[] = $method->id;
			}

			if ( ! in_array( self::METHOD_DELIVERY, $existing, true ) ) {
				$zone->add_shipping_method( self::METHOD_DELIVERY );
			}
			if ( ! in_array( self::METHOD_PICKUP, $existing, true ) ) {
				$zone->add_shipping_method( self::METHOD_PICKUP );
			}
			$zone->save();
		}

		update_option( 'fmke_pickup_zones_installed', '1' );
	}
}


/**
 * Per-vendor pickup settings: the on/off switch, the physical collection
 * address, and the hours someone can actually turn up.
 *
 * Lives on its own Dokan dashboard page (/dashboard/store-pickup/) rather
 * than injecting fields into Dokan's own Settings tabs, whose hook names and
 * arguments have shifted between Dokan versions - the same reasoning, and the
 * same technique, as the Pay on Delivery and Withdrawals pages already in
 * this plugin.
 *
 * Stored as plain user meta on the vendor's account; three small values don't
 * justify a table.
 */
class FMKE_Pickup_Vendor_Settings {

	const QUERY_VAR    = 'store-pickup';
	const META_ENABLED = '_fmke_pickup_enabled';
	const META_ADDRESS = '_fmke_pickup_address';
	const META_HOURS   = '_fmke_pickup_hours';

	public function __construct() {
		add_filter( 'dokan_query_var_filter', array( $this, 'register_query_var' ) );
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'register_nav_item' ) );
		add_action( 'dokan_load_custom_template', array( $this, 'maybe_render_page' ) );
		add_action( 'admin_post_fmke_save_pickup_settings', array( $this, 'handle_save' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );

		// Fallback for an admin setting this up on a vendor's behalf from
		// wp-admin - same three fields on the user profile screen.
		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
	}

	/**
	 * The dashboard page is a rewrite rule, which only exists after a flush.
	 * Same one-shot pattern as the Pay on Delivery page.
	 */
	public function maybe_flush_rewrites() {
		if ( '1' === get_option( 'fmke_pickup_rewrites' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'fmke_pickup_rewrites', '1' );
	}

	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function register_nav_item( $urls ) {
		$urls[ self::QUERY_VAR ] = array(
			'title' => 'Store Pickup',
			'icon'  => '<i class="fa fa-shopping-bag"></i>',
			'url'   => dokan_get_navigation_url( self::QUERY_VAR ),
			'pos'   => 59, // Dokan: Orders 50, Earnings 55, Withdrawals 57, Pay on Delivery 58, Coupons 60.
		);
		return $urls;
	}

	public function maybe_render_page( $query_vars ) {
		if ( ! isset( $query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		$this->render_page();
	}

	/* ---------------- helpers used by the shipping method ---------------- */

	/**
	 * Pickup is only on offer when the vendor has BOTH ticked the box and
	 * saved a real address. Enabled-with-no-address would otherwise put a
	 * free "Store Pickup" option in front of a customer with nowhere to go.
	 *
	 * @param int $vendor_id
	 * @return bool
	 */
	public static function vendor_offers_pickup( $vendor_id ) {
		if ( 'yes' !== get_user_meta( $vendor_id, self::META_ENABLED, true ) ) {
			return false;
		}
		return '' !== trim( (string) get_user_meta( $vendor_id, self::META_ADDRESS, true ) );
	}

	public static function get_pickup_address( $vendor_id ) {
		return trim( (string) get_user_meta( $vendor_id, self::META_ADDRESS, true ) );
	}

	public static function get_pickup_hours( $vendor_id ) {
		return trim( (string) get_user_meta( $vendor_id, self::META_HOURS, true ) );
	}

	/**
	 * Vendor's shop name, falling back to their display name if Dokan isn't
	 * available or the store has no name set.
	 *
	 * @param int $vendor_id
	 * @return string
	 */
	public static function get_store_name( $vendor_id ) {
		if ( function_exists( 'dokan_get_store_info' ) ) {
			$info = dokan_get_store_info( $vendor_id );
			if ( ! empty( $info['store_name'] ) ) {
				return (string) $info['store_name'];
			}
		}
		$user = get_userdata( $vendor_id );
		return $user ? $user->display_name : '';
	}

	/**
	 * The vendor's Dokan store address, flattened to a single block of text.
	 * Used only to pre-fill the form the first time - the saved pickup
	 * address is deliberately its own field, because a vendor's billing or
	 * registered address is often not the counter a customer walks up to.
	 *
	 * @param int $vendor_id
	 * @return string
	 */
	public static function suggest_address_from_dokan( $vendor_id ) {
		if ( ! function_exists( 'dokan_get_store_info' ) ) {
			return '';
		}

		$info = dokan_get_store_info( $vendor_id );
		if ( empty( $info['address'] ) || ! is_array( $info['address'] ) ) {
			return '';
		}

		$parts = array();
		foreach ( array( 'street_1', 'street_2', 'city', 'zip', 'state' ) as $key ) {
			if ( ! empty( $info['address'][ $key ] ) ) {
				$parts[] = $info['address'][ $key ];
			}
		}
		return implode( "\n", $parts );
	}

	/* ---------------- Dokan dashboard page ---------------- */

	private function render_page() {
		$vendor_id = get_current_user_id();
		$enabled   = 'yes' === get_user_meta( $vendor_id, self::META_ENABLED, true );
		$address   = self::get_pickup_address( $vendor_id );
		$hours     = self::get_pickup_hours( $vendor_id );
		$suggested = $address ? '' : self::suggest_address_from_dokan( $vendor_id );

		echo '<div class="dokan-dashboard-content">';
		echo '<h1>Store Pickup</h1>';
		echo '<p>Let customers skip the KES 350 delivery charge and collect their order from your shop instead. Only switch this on if you have a physical counter someone can actually walk up to.</p>';
		echo '<p><em>Pickup is only offered when the customer\'s whole order comes from your store - a cart mixing several vendors is delivered as normal.</em></p>';

		if ( isset( $_GET['fmke_saved'] ) ) {
			echo '<div class="dokan-alert dokan-alert-success">Saved.</div>';
		}
		if ( isset( $_GET['fmke_error'] ) ) {
			echo '<div class="dokan-alert dokan-alert-danger">Please enter your collection address before switching pickup on.</div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="fmke_save_pickup_settings" />';
		wp_nonce_field( 'fmke_save_pickup_settings' );

		echo '<p><label><input type="checkbox" name="fmke_pickup_enabled" value="yes" ' . checked( $enabled, true, false ) . ' /> Offer Store Pickup on my products</label></p>';

		echo '<p><label for="fmke_pickup_address">Collection address</label><br />';
		echo '<textarea id="fmke_pickup_address" name="fmke_pickup_address" rows="4" style="width:100%;max-width:480px;" placeholder="e.g. Shop 12, Ground Floor, Kenyatta Market&#10;Ngong Road&#10;Nairobi">' . esc_textarea( $address ) . '</textarea></p>';
		echo '<p><em>Write it the way you\'d give directions to a customer on the phone - building, floor, shop number, street, area. This is shown on the order and in the confirmation email.</em></p>';

		if ( $suggested ) {
			echo '<p><em>Your Dokan store address is currently:<br />' . nl2br( esc_html( $suggested ) ) . '<br />Copy it above if that\'s also where customers collect.</em></p>';
		}

		echo '<p><label for="fmke_pickup_hours">Collection hours</label><br />';
		echo '<input type="text" id="fmke_pickup_hours" name="fmke_pickup_hours" style="width:100%;max-width:480px;" value="' . esc_attr( $hours ) . '" placeholder="e.g. Mon-Sat 8am-6pm, Sun closed" /></p>';

		echo '<button type="submit" class="dokan-btn dokan-btn-theme">Save</button>';
		echo '</form>';
		echo '</div>';
	}

	public function handle_save() {
		check_admin_referer( 'fmke_save_pickup_settings' );
		if ( ! is_user_logged_in() ) {
			wp_die( 'Not allowed.' );
		}

		$vendor_id = get_current_user_id();
		$address   = isset( $_POST['fmke_pickup_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fmke_pickup_address'] ) ) : '';
		$hours     = isset( $_POST['fmke_pickup_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_pickup_hours'] ) ) : '';
		$enabled   = ! empty( $_POST['fmke_pickup_enabled'] ) ? 'yes' : 'no';

		// Refuse the combination that produces a pickup option pointing
		// nowhere, and say so, rather than saving it and silently not
		// offering pickup - which would look like a bug to the vendor.
		$redirect_args = array( 'fmke_saved' => '1' );
		if ( 'yes' === $enabled && '' === trim( $address ) ) {
			$enabled       = 'no';
			$redirect_args = array( 'fmke_error' => '1' );
		}

		update_user_meta( $vendor_id, self::META_ENABLED, $enabled );
		update_user_meta( $vendor_id, self::META_ADDRESS, $address );
		update_user_meta( $vendor_id, self::META_HOURS, $hours );

		wp_safe_redirect( add_query_arg( $redirect_args, wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}

	/* ---------------- wp-admin fallback (user profile screen) ---------------- */

	public function render_profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$enabled = 'yes' === get_user_meta( $user->ID, self::META_ENABLED, true );
		$address = self::get_pickup_address( $user->ID );
		$hours   = self::get_pickup_hours( $user->ID );
		?>
		<h2>Store Pickup</h2>
		<table class="form-table">
			<tr>
				<th><label for="fmke_pickup_enabled">Offer pickup</label></th>
				<td><label><input type="checkbox" name="fmke_pickup_enabled" id="fmke_pickup_enabled" value="yes" <?php checked( $enabled ); ?> /> Let customers collect this vendor's orders in person</label></td>
			</tr>
			<tr>
				<th><label for="fmke_pickup_address">Collection address</label></th>
				<td>
					<textarea name="fmke_pickup_address" id="fmke_pickup_address" rows="4" class="regular-text"><?php echo esc_textarea( $address ); ?></textarea>
					<p class="description">Admin-side fallback for the same setting the vendor can set from their own Dokan dashboard &gt; Store Pickup. Pickup stays hidden at checkout until this is filled in.</p>
				</td>
			</tr>
			<tr>
				<th><label for="fmke_pickup_hours">Collection hours</label></th>
				<td><input type="text" name="fmke_pickup_hours" id="fmke_pickup_hours" class="regular-text" value="<?php echo esc_attr( $hours ); ?>" placeholder="e.g. Mon-Sat 8am-6pm" /></td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$address = isset( $_POST['fmke_pickup_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fmke_pickup_address'] ) ) : '';
		$hours   = isset( $_POST['fmke_pickup_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_pickup_hours'] ) ) : '';
		$enabled = ! empty( $_POST['fmke_pickup_enabled'] ) ? 'yes' : 'no';

		if ( 'yes' === $enabled && '' === trim( $address ) ) {
			$enabled = 'no'; // Same guard as the vendor-facing form.
		}

		update_user_meta( $user_id, self::META_ENABLED, $enabled );
		update_user_meta( $user_id, self::META_ADDRESS, $address );
		update_user_meta( $user_id, self::META_HOURS, $hours );
	}
}

/**
 * Defines the two shipping method classes.
 *
 * They extend WC_Shipping_Method, which WooCommerce doesn't load until it
 * boots its shipping system - so they're declared here, on
 * 'woocommerce_shipping_init', rather than at file scope where the parent
 * class wouldn't exist yet. (PHP won't allow a class declaration inside a
 * class method, which is why this is a plain function rather than a method
 * on FMKE_Store_Pickup.) The class_exists guards keep it safe if the hook
 * ever fires more than once.
 */
function fmke_load_pickup_shipping_methods() {
	if ( ! class_exists( 'WC_Shipping_Method' ) ) {
		return;
	}

	if ( ! class_exists( 'WC_Shipping_FMKE_Flat_Delivery' ) ) {
		/**
		 * Option A: flat-rate delivery.
		 */
		class WC_Shipping_FMKE_Flat_Delivery extends WC_Shipping_Method {

			public function __construct( $instance_id = 0 ) {
				$this->id                 = FMKE_Store_Pickup::METHOD_DELIVERY;
				$this->instance_id        = absint( $instance_id );
				$this->method_title       = 'Delivery (flat rate)';
				$this->method_description = 'A single flat delivery charge for the whole order - the default KES 350 for this marketplace. Optionally free once the order is large enough.';
				$this->supports           = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal',
				);

				$this->init();
			}

			public function init() {
				$this->init_form_fields();
				$this->init_settings();

				$this->title = $this->get_option( 'title', 'Delivery' );

				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			public function init_form_fields() {
				$this->instance_form_fields = array(
					'title'     => array(
						'title'       => 'Label shown to customers',
						'type'        => 'text',
						'default'     => 'Delivery',
						'desc_tip'    => true,
						'description' => 'What the customer sees next to the charge at checkout.',
					),
					'cost'      => array(
						'title'       => 'Delivery charge (KES)',
						'type'        => 'text',
						'default'     => '350',
						'desc_tip'    => true,
						'description' => 'Charged once per order, regardless of how many items or vendors are in the cart.',
					),
					'free_over' => array(
						'title'       => 'Free delivery over (KES)',
						'type'        => 'text',
						'default'     => '',
						'desc_tip'    => true,
						'description' => 'Optional. Leave blank to always charge. If set, the charge drops to zero once the cart subtotal reaches this amount.',
					),
				);
			}

			public function calculate_shipping( $package = array() ) {
				$cost = (float) wc_format_decimal( $this->get_option( 'cost', '350' ) );
				if ( $cost < 0 ) {
					$cost = 0;
				}

				$label     = $this->title ?: 'Delivery';
				$free_over = wc_format_decimal( $this->get_option( 'free_over', '' ) );

				// Compare against the package's own contents rather than the
				// whole cart, so this still reads correctly if something
				// splits the cart into per-vendor packages.
				if ( '' !== $free_over && null !== $free_over ) {
					$threshold = (float) $free_over;
					$subtotal  = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0;
					if ( $threshold > 0 && $subtotal >= $threshold ) {
						$cost  = 0;
						$label = $label . ' (free)';
					}
				}

				$this->add_rate( array(
					'id'      => $this->get_rate_id(),
					'label'   => $label,
					'cost'    => $cost,
					'package' => $package,
				) );
			}
		}
	}

	if ( ! class_exists( 'WC_Shipping_FMKE_Store_Pickup' ) ) {
		/**
		 * Option B: collect from the vendor's store.
		 */
		class WC_Shipping_FMKE_Store_Pickup extends WC_Shipping_Method {

			public function __construct( $instance_id = 0 ) {
				$this->id                 = FMKE_Store_Pickup::METHOD_PICKUP;
				$this->instance_id        = absint( $instance_id );
				$this->method_title       = 'Store Pickup (from vendor)';
				$this->method_description = 'Free collection from the selling vendor\'s own shop. Only offered when every item in the order comes from one vendor, and that vendor has turned pickup on and saved a collection address (Dokan Dashboard > Store Pickup).';
				$this->supports           = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal',
				);

				$this->init();
			}

			public function init() {
				$this->init_form_fields();
				$this->init_settings();

				$this->title = $this->get_option( 'title', 'Store Pickup' );

				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			public function init_form_fields() {
				$this->instance_form_fields = array(
					'title'          => array(
						'title'       => 'Label shown to customers',
						'type'        => 'text',
						'default'     => 'Store Pickup',
						'desc_tip'    => true,
						'description' => 'The vendor\'s store name is appended automatically, e.g. "Store Pickup - Petals & Co".',
					),
					'show_store_name' => array(
						'title'       => 'Show the store name in the label',
						'type'        => 'checkbox',
						'label'       => 'Append the vendor\'s store name to the pickup option',
						'default'     => 'yes',
						'description' => 'Turn off if you\'d rather the customer only sees the address after selecting pickup.',
					),
				);
			}

			public function calculate_shipping( $package = array() ) {
				$vendor_id = FMKE_Store_Pickup::get_sole_vendor_for_package( $package );

				// Mixed-vendor cart, or a cart whose seller can't be resolved
				// (Dokan inactive): no single shop to collect from, so don't
				// offer the option at all rather than guessing an address.
				if ( ! $vendor_id ) {
					return;
				}

				if ( ! FMKE_Pickup_Vendor_Settings::vendor_offers_pickup( $vendor_id ) ) {
					return;
				}

				$label = $this->title ?: 'Store Pickup';
				if ( 'yes' === $this->get_option( 'show_store_name', 'yes' ) ) {
					$store_name = FMKE_Pickup_Vendor_Settings::get_store_name( $vendor_id );
					if ( $store_name ) {
						$label .= ' - ' . $store_name;
					}
				}

				$this->add_rate( array(
					'id'        => $this->get_rate_id(),
					'label'     => $label,
					'cost'      => 0,
					'package'   => $package,
					// Copied onto the order's shipping line item by WooCommerce,
					// so the collection point stays readable on the order even
					// if the vendor later edits their address.
					'meta_data' => array(
						'Pickup location' => FMKE_Pickup_Vendor_Settings::get_pickup_address( $vendor_id ),
					),
				) );
			}
		}
	}
}
