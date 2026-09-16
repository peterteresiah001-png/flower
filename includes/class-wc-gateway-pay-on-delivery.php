<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pay on Delivery - NOT cash. The customer doesn't pay anything at
 * checkout; the order sits on-hold until delivery, and payment is then
 * collected through the marketplace's existing M-Pesa gateways (STK Push
 * or Paybill, see class-wc-gateway-mpesa-stk.php / -mpesa-manual.php) -
 * never as physical cash handed to a rider. This file only decides
 * *whether to offer* Pay on Delivery and hands the actual money-collection
 * off to those two gateways once it's delivery time; it never talks to
 * Daraja itself.
 *
 * Two independent gatekeepers decide whether a customer even sees this
 * option at checkout:
 *
 * - Per vendor: each vendor switches Pay on Delivery on/off for their own
 *   products from their Dokan dashboard (see FMKE_POD_Vendor_Settings
 *   below), since it's their delivery/collection risk to take on.
 * - Per city: a vendor who enables it also lists which cities they'll
 *   accept it in (their own delivery/collection reach), not a blanket
 *   "everywhere".
 *
 * If a cart contains items from more than one vendor, EVERY vendor
 * involved must allow Pay on Delivery for the customer's city, since one
 * order can't be "half pay-on-delivery, half not". If either the STK or
 * Paybill gateway isn't enabled on the site at all, Pay on Delivery is
 * hidden entirely - there would be no legitimate way to actually collect
 * the money.
 */
class WC_Gateway_Pay_On_Delivery extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'fmke_pay_on_delivery';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = 'Pay on Delivery (via M-Pesa)';
		$this->method_description = 'No cash: the customer pays nothing at checkout, then pays by M-Pesa (STK Push or Paybill - whichever they pick) when the order is delivered. Only offered where the delivering vendor has enabled it for the customer\'s city; only offered at all while the underlying M-Pesa gateway(s) are enabled.';
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Delivery-time collection UI + actions on the order edit screen.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_order_panel' ) );
		add_action( 'admin_post_fmke_pod_send_stk', array( $this, 'handle_send_stk' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'       => 'Enable/Disable',
				'type'        => 'checkbox',
				'label'       => 'Enable Pay on Delivery',
				'default'     => 'no',
				'description' => 'Master switch. Even when on, a given customer only sees this option if their delivering vendor(s) have enabled it for their city - see WooCommerce Payments is not where that\'s configured; vendors set it from their own Dokan dashboard, under "Pay on Delivery".',
			),
			'title'       => array(
				'title'   => 'Title',
				'type'    => 'text',
				'default' => 'Pay on Delivery (M-Pesa)',
			),
			'description' => array(
				'title'   => 'Description',
				'type'    => 'textarea',
				'default' => 'Pay nothing now. When your order arrives, pay by M-Pesa - either an STK prompt to your phone, or our Paybill number, your choice below.',
			),
		);
	}

	/* ---------------- ELIGIBILITY ---------------- */

	private function get_customer_city() {
		if ( ! WC()->customer ) {
			return '';
		}
		$city = WC()->customer->get_shipping_city();
		return $city ? $city : WC()->customer->get_billing_city();
	}

	/**
	 * Seller (vendor) user ID for a product, the same way Dokan itself
	 * determines it - the product's post author.
	 */
	private function get_product_vendor_id( $product_id ) {
		$vendor_id = (int) get_post_field( 'post_author', $product_id );
		return $vendor_id ?: 0;
	}

	private function get_cart_vendor_ids() {
		$ids = array();
		if ( ! WC()->cart ) {
			return $ids;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$vendor_id = $this->get_product_vendor_id( $item['product_id'] );
			if ( $vendor_id ) {
				$ids[ $vendor_id ] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * True only if BOTH underlying M-Pesa gateways this option can hand
	 * off to have at least one enabled - if neither is, there's no
	 * legitimate ("no cash") way left to actually collect the payment, so
	 * Pay on Delivery has nothing to offer and must not be shown.
	 */
	public function collection_methods_available() {
		$methods       = array();
		$stk_settings  = get_option( 'woocommerce_mpesa_stk_settings', array() );
		$manual_settings = get_option( 'woocommerce_mpesa_manual_settings', array() );

		if ( isset( $stk_settings['enabled'] ) && 'yes' === $stk_settings['enabled'] ) {
			$methods[] = 'stk';
		}
		if ( isset( $manual_settings['enabled'] ) && 'yes' === $manual_settings['enabled'] ) {
			$methods[] = 'paybill';
		}
		return $methods;
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( empty( $this->collection_methods_available() ) ) {
			return false; // Neither STK nor Paybill is enabled - nothing to collect through.
		}
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return parent::is_available();
		}

		$vendor_ids = $this->get_cart_vendor_ids();
		if ( empty( $vendor_ids ) ) {
			return false; // Can't confirm who the seller(s) are (e.g. Dokan not active) - don't guess.
		}

		$city = $this->get_customer_city();
		if ( ! $city ) {
			return false; // Don't know the delivery city yet - hide until the customer fills in an address.
		}

		foreach ( $vendor_ids as $vendor_id ) {
			if ( ! FMKE_POD_Vendor_Settings::vendor_allows_city( $vendor_id, $city ) ) {
				return false;
			}
		}

		return parent::is_available();
	}

	/* ---------------- CHECKOUT ---------------- */

	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}

		$methods = $this->collection_methods_available();
		echo '<p class="form-row form-row-wide">';
		echo '<label for="fmke_pod_method">How would you like to pay when your order is delivered?</label>';
		echo '<select id="fmke_pod_method" name="fmke_pod_method">';
		if ( in_array( 'paybill', $methods, true ) ) {
			echo '<option value="paybill">M-Pesa Paybill - I\'ll pay it myself when the rider arrives</option>';
		}
		if ( in_array( 'stk', $methods, true ) ) {
			echo '<option value="stk">M-Pesa STK Push - send a payment prompt to my phone at delivery</option>';
		}
		echo '</select>';
		echo '</p>';
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$allowed = $this->collection_methods_available();
		$method  = isset( $_POST['fmke_pod_method'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_pod_method'] ) ) : '';
		if ( ! in_array( $method, $allowed, true ) ) {
			$method = $allowed[0] ?? 'paybill';
		}

		$order->update_meta_data( '_fmke_pod_collection_method', $method );

		$label = 'stk' === $method ? 'M-Pesa STK Push' : 'M-Pesa Paybill';
		$order->update_status( 'on-hold', "Pay on Delivery selected (no cash) - customer will pay via {$label} at the point of delivery. This must be collected through the marketplace's M-Pesa system: use the tools in the \"Pay on Delivery\" box on this order once the order is out for delivery." );

		$order->save();
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/* ---------------- DELIVERY-TIME COLLECTION (staff/rider side) ---------------- */

	/**
	 * Box on the order edit screen showing which M-Pesa channel the
	 * customer picked, and the matching tool to actually collect it -
	 * reusing the STK/Paybill gateways rather than re-implementing
	 * anything. Only shown for this gateway's own orders while unpaid.
	 */
	public function render_admin_order_panel( $order ) {
		if ( ! $order || $this->id !== $order->get_payment_method() ) {
			return;
		}
		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return;
		}

		$method = $order->get_meta( '_fmke_pod_collection_method' );
		echo '<h4>Pay on Delivery - collect payment (no cash)</h4>';

		if ( 'stk' === $method ) {
			echo '<p>Customer chose <strong>M-Pesa STK Push</strong>. When you\'re at the door, send the prompt:</p>';
			$url = wp_nonce_url(
				add_query_arg( array( 'action' => 'fmke_pod_send_stk', 'order_id' => $order->get_id() ), admin_url( 'admin-post.php' ) ),
				'fmke_pod_send_stk_' . $order->get_id()
			);
			echo '<p><a href="' . esc_url( $url ) . '" class="button">Send M-Pesa STK Push now</a></p>';
			echo '<p><em>This uses the same M-Pesa STK Push gateway as normal checkout - the order confirms itself automatically once the customer enters their PIN, same as any other STK order.</em></p>';
		} elseif ( 'paybill' === $method ) {
			if ( class_exists( 'WC_Gateway_Mpesa_Manual' ) ) {
				$manual = new WC_Gateway_Mpesa_Manual();
				echo '<p>Customer chose <strong>M-Pesa Paybill</strong>. Read these details out at the door:</p>';
				echo wp_kses_post( $manual->get_instructions_html_public( $order ) );
				echo '<p><em>Once paid, use the "Confirm manual M-Pesa payment" order action above (or wait for automatic confirmation, if enabled) - same as any other Paybill order.</em></p>';
			}
		} else {
			echo '<p><em>No collection method on file for this order.</em></p>';
		}
	}

	public function handle_send_stk() {
		$order_id = absint( $_GET['order_id'] ?? 0 );
		check_admin_referer( 'fmke_pod_send_stk_' . $order_id );

		if ( ! current_user_can( 'edit_shop_orders' ) && ! ( function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( get_current_user_id() ) ) ) {
			wp_die( 'Not allowed.' );
		}

		$order = wc_get_order( $order_id );
		if ( $order && class_exists( 'WC_Gateway_Mpesa_STK' ) ) {
			$stk = new WC_Gateway_Mpesa_STK();
			$stk->trigger_stk_for_order( $order );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}
}

/**
 * Per-vendor "Pay on Delivery" settings: on/off switch plus the list of
 * cities the vendor will accept it in. Lives on its own Dokan dashboard
 * page (/dashboard/pay-on-delivery/) rather than trying to inject fields
 * into Dokan's own Settings tabs, whose exact hook names/args have shifted
 * between Dokan versions - a dedicated custom page (same technique as the
 * Withdrawals page in class-vendor-withdrawals.php) is the one integration
 * point this plugin already relies on elsewhere and knows works.
 *
 * Stored as plain user meta on the vendor's account - no separate table
 * needed for two small values.
 */
class FMKE_POD_Vendor_Settings {

	const QUERY_VAR    = 'pay-on-delivery';
	const META_ENABLED = '_fmke_pod_enabled';
	const META_CITIES  = '_fmke_pod_cities';

	public function __construct() {
		add_filter( 'dokan_query_var_filter', array( $this, 'register_query_var' ) );
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'register_nav_item' ) );
		add_action( 'dokan_load_custom_template', array( $this, 'maybe_render_page' ) );
		add_action( 'admin_post_fmke_save_pod_settings', array( $this, 'handle_save' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );

		// Fallback for admins managing a vendor's account directly from
		// wp-admin (e.g. Dokan not fully set up yet, or admin doing this
		// on the vendor's behalf) - same two settings, plain WP user profile fields.
		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
	}

	public function maybe_flush_rewrites() {
		if ( '1' === get_option( 'fmke_pod_rewrites' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'fmke_pod_rewrites', '1' );
	}

	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function register_nav_item( $urls ) {
		$urls[ self::QUERY_VAR ] = array(
			'title' => 'Pay on Delivery',
			'icon'  => '<i class="fa fa-motorcycle"></i>',
			'url'   => dokan_get_navigation_url( self::QUERY_VAR ),
			'pos'   => 58, // Dokan: Orders 50, FMKE Earnings 55, Withdrawals 57, Coupons 60.
		);
		return $urls;
	}

	public function maybe_render_page( $query_vars ) {
		if ( ! isset( $query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		$this->render_page();
	}

	/* ---------------- helpers used elsewhere (gateway eligibility) ---------------- */

	public static function get_vendor_cities( $vendor_id ) {
		$raw = get_user_meta( $vendor_id, self::META_CITIES, true );
		if ( ! $raw ) {
			return array();
		}
		$list = array_map( 'trim', explode( ',', $raw ) );
		return array_values( array_filter( array_map( 'strtolower', $list ) ) );
	}

	public static function vendor_allows_city( $vendor_id, $city ) {
		if ( 'yes' !== get_user_meta( $vendor_id, self::META_ENABLED, true ) ) {
			return false;
		}
		$cities = self::get_vendor_cities( $vendor_id );
		if ( empty( $cities ) ) {
			return false; // Enabled but no cities listed yet = nothing allowed, safer than "everywhere".
		}
		return in_array( strtolower( trim( $city ) ), $cities, true );
	}

	/* ---------------- Dokan dashboard page ---------------- */

	private function render_page() {
		$vendor_id = get_current_user_id();
		$enabled   = 'yes' === get_user_meta( $vendor_id, self::META_ENABLED, true );
		$cities    = get_user_meta( $vendor_id, self::META_CITIES, true );

		echo '<div class="dokan-dashboard-content">';
		echo '<h1>Pay on Delivery</h1>';
		echo '<p>Let customers order now and pay when it arrives - always by M-Pesa (STK Push or Paybill), never cash. Only offer this in cities where you\'re confident about collecting payment on delivery.</p>';

		if ( isset( $_GET['fmke_saved'] ) ) {
			echo '<div class="dokan-alert dokan-alert-success">Saved.</div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="fmke_save_pod_settings" />';
		wp_nonce_field( 'fmke_save_pod_settings' );

		echo '<p><label><input type="checkbox" name="fmke_pod_enabled" value="yes" ' . checked( $enabled, true, false ) . ' /> Allow Pay on Delivery for my products</label></p>';

		echo '<p><label for="fmke_pod_cities">Cities I will accept Pay on Delivery in (comma-separated)</label><br />';
		echo '<input type="text" id="fmke_pod_cities" name="fmke_pod_cities" style="width:100%;max-width:480px;" value="' . esc_attr( $cities ) . '" placeholder="e.g. Nairobi, Kiambu, Ruiru" /></p>';
		echo '<p><em>Match how customers actually type their city at checkout as closely as you can - matching ignores capitalization but not spelling.</em></p>';

		echo '<button type="submit" class="dokan-btn dokan-btn-theme">Save</button>';
		echo '</form>';
		echo '</div>';
	}

	public function handle_save() {
		check_admin_referer( 'fmke_save_pod_settings' );
		if ( ! is_user_logged_in() ) {
			wp_die( 'Not allowed.' );
		}

		$vendor_id = get_current_user_id();
		$enabled   = ! empty( $_POST['fmke_pod_enabled'] ) ? 'yes' : 'no';
		$cities    = isset( $_POST['fmke_pod_cities'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_pod_cities'] ) ) : '';

		update_user_meta( $vendor_id, self::META_ENABLED, $enabled );
		update_user_meta( $vendor_id, self::META_CITIES, $cities );

		wp_safe_redirect( add_query_arg( 'fmke_saved', '1', wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}

	/* ---------------- wp-admin fallback (user profile screen) ---------------- */

	public function render_profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$enabled = 'yes' === get_user_meta( $user->ID, self::META_ENABLED, true );
		$cities  = get_user_meta( $user->ID, self::META_CITIES, true );
		?>
		<h2>Pay on Delivery (via M-Pesa)</h2>
		<table class="form-table">
			<tr>
				<th><label for="fmke_pod_enabled">Allow for this vendor</label></th>
				<td><label><input type="checkbox" name="fmke_pod_enabled" id="fmke_pod_enabled" value="yes" <?php checked( $enabled ); ?> /> Allow Pay on Delivery for this vendor's products</label></td>
			</tr>
			<tr>
				<th><label for="fmke_pod_cities">Allowed cities</label></th>
				<td>
					<input type="text" name="fmke_pod_cities" id="fmke_pod_cities" class="regular-text" value="<?php echo esc_attr( $cities ); ?>" placeholder="e.g. Nairobi, Kiambu, Ruiru" />
					<p class="description">Comma-separated. Admin-side fallback for the same setting the vendor can set from their own Dokan dashboard &gt; Pay on Delivery.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$enabled = ! empty( $_POST['fmke_pod_enabled'] ) ? 'yes' : 'no';
		$cities  = isset( $_POST['fmke_pod_cities'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_pod_cities'] ) ) : '';

		update_user_meta( $user_id, self::META_ENABLED, $enabled );
		update_user_meta( $user_id, self::META_CITIES, $cities );
	}
}
