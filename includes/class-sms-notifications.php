<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SMS notifications via Africa's Talking, for the two moments phone data
 * beats email for this marketplace: telling a customer their order is
 * confirmed, and telling a vendor a paid order just landed (vendors run
 * their store from a phone, not an inbox).
 *
 * Both fire off the SAME event email already uses for this: the order
 * transitioning to "processing", which is the point payment is actually
 * confirmed (M-Pesa STK and the card/bank gateways both confirm
 * asynchronously after the order is first created, so "order placed" and
 * "order paid" are two different moments - SMS should follow the paid one).
 *
 * Three modes, same pattern as the payment gateways in this plugin:
 *   - Demo:    no API call, no cost - just an order note with the message
 *              that would have been sent, so you can test the trigger
 *              logic before you have an Africa's Talking account.
 *   - Sandbox: real API call against Africa's Talking's free sandbox app
 *              (username "sandbox"), which never actually reaches a
 *              handset but does confirm the integration end-to-end.
 *   - Live:    real send, real cost, requires a registered Sender ID or
 *              messages are dropped or rewritten to a random shortcode by
 *              Safaricom/Airtel/Telkom - see the settings page notice.
 */
class FMKE_SMS {

	const AT_LIVE_URL    = 'https://api.africastalking.com/version1/messaging';
	const AT_SANDBOX_URL = 'https://api.sandbox.africastalking.com/version1/messaging';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Same event, same "skip the already-split parent order" guard as
		// the email notifications in class-order-status-notifications.php,
		// so a multi-vendor order doesn't fire twice for the same payment.
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 10, 4 );
	}

	/* ---------------- Settings ---------------- */

	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			'SMS Notifications',
			'SMS Notifications',
			'manage_woocommerce',
			'fmke-sms-notifications',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'fmke_sms_settings', 'fmke_sms_enabled' );
		register_setting( 'fmke_sms_settings', 'fmke_sms_environment' );
		register_setting( 'fmke_sms_settings', 'fmke_sms_username' );
		register_setting( 'fmke_sms_settings', 'fmke_sms_api_key' );
		register_setting( 'fmke_sms_settings', 'fmke_sms_sender_id' );
		register_setting( 'fmke_sms_settings', 'fmke_sms_default_country_code' );
		register_setting( 'fmke_sms_settings', 'fmke_sms_notify_customer_confirmed' );
		register_setting( 'fmke_sms_settings', 'fmke_sms_notify_vendor_new_order' );
	}

	public function render_settings_page() {
		$environment = get_option( 'fmke_sms_environment', 'demo' );
		?>
		<div class="wrap">
			<h1>SMS Notifications</h1>
			<p>Sends order-confirmation texts via Africa's Talking. Get a free account and sandbox credentials at
				<a href="https://africastalking.com" target="_blank">africastalking.com</a>.</p>

			<?php if ( 'live' === $environment ) : ?>
				<div class="notice notice-warning"><p><strong>Live mode:</strong> Safaricom, Airtel and Telkom all
				require a registered alphanumeric Sender ID (up to 11 characters). An unregistered ID gets silently
				dropped or replaced with a random shortcode - register yours in the Africa's Talking dashboard
				before relying on this in production. Registration typically takes 2-5 business days.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'fmke_sms_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label>Enable SMS Notifications</label></th>
						<td><input type="checkbox" name="fmke_sms_enabled" value="yes" <?php checked( get_option( 'fmke_sms_enabled', 'no' ), 'yes' ); ?> /></td>
					</tr>
					<tr>
						<th><label>Mode</label></th>
						<td>
							<select name="fmke_sms_environment">
								<option value="demo" <?php selected( $environment, 'demo' ); ?>>Demo (no API call - logs to the order notes)</option>
								<option value="sandbox" <?php selected( $environment, 'sandbox' ); ?>>Africa's Talking Sandbox (real call, free, doesn't reach a handset)</option>
								<option value="live" <?php selected( $environment, 'live' ); ?>>Africa's Talking Live (real send, real cost)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label>Africa's Talking Username</label></th>
						<td><input type="text" name="fmke_sms_username" value="<?php echo esc_attr( get_option( 'fmke_sms_username', '' ) ); ?>" placeholder="sandbox" style="width:300px" />
							<p class="description">Use literally <code>sandbox</code> when Mode is Sandbox. Your app username when Mode is Live.</p></td>
					</tr>
					<tr>
						<th><label>API Key</label></th>
						<td><input type="password" name="fmke_sms_api_key" value="<?php echo esc_attr( get_option( 'fmke_sms_api_key', '' ) ); ?>" style="width:300px" /></td>
					</tr>
					<tr>
						<th><label>Sender ID</label></th>
						<td><input type="text" name="fmke_sms_sender_id" value="<?php echo esc_attr( get_option( 'fmke_sms_sender_id', '' ) ); ?>" placeholder="e.g. FLOWERSKE" style="width:300px" />
							<p class="description">Leave blank in Sandbox mode. Required in Live mode and must be registered with Africa's Talking first.</p></td>
					</tr>
					<tr>
						<th><label>Default Country Code</label></th>
						<td><input type="text" name="fmke_sms_default_country_code" value="<?php echo esc_attr( get_option( 'fmke_sms_default_country_code', '254' ) ); ?>" style="width:100px" />
							<p class="description">Used to convert local numbers (e.g. 07XXXXXXXX) to international format (e.g. +2547XXXXXXXX) before sending.</p></td>
					</tr>
					<tr>
						<th colspan="2"><h3>Events</h3></th>
					</tr>
					<tr>
						<th><label>Customer: order confirmed</label></th>
						<td><input type="checkbox" name="fmke_sms_notify_customer_confirmed" value="yes" <?php checked( get_option( 'fmke_sms_notify_customer_confirmed', 'yes' ), 'yes' ); ?> />
							<span class="description"> Sent once, when payment is confirmed (order moves to Processing).</span></td>
					</tr>
					<tr>
						<th><label>Vendor: new paid order</label></th>
						<td><input type="checkbox" name="fmke_sms_notify_vendor_new_order" value="yes" <?php checked( get_option( 'fmke_sms_notify_vendor_new_order', 'yes' ), 'yes' ); ?> />
							<span class="description"> Sent to the vendor when their sub-order is confirmed paid.</span></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------- Trigger ---------------- */

	/**
	 * Same trigger point and same parent-order skip as
	 * FMKE_Order_Status_Notifications::handle() - see that file for why.
	 * Deliberately only acts on the transition INTO "processing": that is
	 * the single moment payment is confirmed, whether the order started as
	 * pending (STK/card) or on-hold, so this can't fire twice for one order
	 * by also matching on-hold -> processing and pending -> on-hold.
	 */
	public function handle_status_change( $order_id, $old_status, $new_status, $order = null ) {
		if ( 'yes' !== get_option( 'fmke_sms_enabled', 'no' ) ) {
			return;
		}

		if ( 'processing' !== $new_status || $old_status === $new_status ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( 'has_sub_order' ) ) {
			return;
		}

		if ( 'yes' === get_option( 'fmke_sms_notify_customer_confirmed', 'yes' ) ) {
			$this->notify_customer_confirmed( $order );
		}

		if ( 'yes' === get_option( 'fmke_sms_notify_vendor_new_order', 'yes' ) ) {
			$this->notify_vendor_new_order( $order );
		}
	}

	private function notify_customer_confirmed( $order ) {
		$phone = $this->format_phone( $order->get_billing_phone() );
		if ( ! $phone ) {
			return;
		}

		$message = sprintf(
			'Hi %1$s, your order #%2$s (%3$s) is confirmed and being prepared for delivery. Track it: %4$s',
			$order->get_billing_first_name(),
			$order->get_id(),
			wp_strip_all_tags( $order->get_formatted_order_total() ),
			$order->get_view_order_url()
		);

		$this->send( $phone, $message, $order, 'customer' );
	}

	private function notify_vendor_new_order( $order ) {
		$seller_id = $this->resolve_seller_id( $order );
		if ( ! $seller_id ) {
			return;
		}

		$vendor = get_userdata( $seller_id );
		if ( ! $vendor ) {
			return;
		}

		// Dokan stores the vendor's own phone in a profile meta array, not
		// user_email/a top-level field - dig it out the same way Dokan's
		// own vendor dashboard does.
		$vendor_phone = '';
		if ( function_exists( 'dokan_get_store_info' ) ) {
			$store_info   = dokan_get_store_info( $seller_id );
			$vendor_phone = $store_info['phone'] ?? '';
		}
		if ( ! $vendor_phone ) {
			$vendor_phone = get_user_meta( $seller_id, 'phone', true );
		}

		$phone = $this->format_phone( $vendor_phone );
		if ( ! $phone ) {
			return;
		}

		$items_count = 0;
		foreach ( $order->get_items() as $item ) {
			$items_count += $item->get_quantity();
		}

		$message = sprintf(
			'New paid order #%1$s: %2$d item(s), %3$s. Customer: %4$s, %5$s. Prepare for dispatch.',
			$order->get_id(),
			$items_count,
			wp_strip_all_tags( $order->get_formatted_order_total() ),
			$order->get_formatted_billing_full_name(),
			$order->get_billing_phone()
		);

		$this->send( $phone, $message, $order, 'vendor' );
	}

	/**
	 * @return int Seller/vendor user ID, or 0. Mirrors
	 *             FMKE_Order_Status_Notifications::resolve_seller_id() -
	 *             duplicated rather than shared to keep this file
	 *             self-contained and safe to drop into other projects.
	 */
	private function resolve_seller_id( $order ) {
		$order_id = $order->get_id();

		if ( function_exists( 'dokan_get_seller_id_by_order' ) ) {
			$seller_id = (int) dokan_get_seller_id_by_order( $order_id );
			if ( $seller_id ) {
				return $seller_id;
			}
		}

		$meta_vendor_id = (int) $order->get_meta( '_dokan_vendor_id' );
		if ( $meta_vendor_id ) {
			return $meta_vendor_id;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dokan_orders';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$seller_id = $wpdb->get_var( $wpdb->prepare( "SELECT seller_id FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			if ( $seller_id ) {
				return (int) $seller_id;
			}
		}

		return 0;
	}

	/**
	 * Converts a Kenyan-style local number (07XXXXXXXX, 01XXXXXXXX, or
	 * already-international +254.../254...) into the +254XXXXXXXXX format
	 * Africa's Talking expects. Returns '' for anything that doesn't look
	 * like a plausible phone number, so a blank/garbage billing phone just
	 * silently skips the SMS rather than sending to a malformed number.
	 */
	private function format_phone( $raw ) {
		$digits = preg_replace( '/[^0-9]/', '', (string) $raw );
		if ( ! $digits ) {
			return '';
		}

		$country_code = preg_replace( '/[^0-9]/', '', get_option( 'fmke_sms_default_country_code', '254' ) );
		$country_code = $country_code ? $country_code : '254';

		if ( 0 === strpos( $digits, $country_code ) ) {
			// Already has the country code, e.g. 2547XXXXXXXX.
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			// Local format, e.g. 07XXXXXXXX -> drop the leading 0.
			$digits = $country_code . substr( $digits, 1 );
		} else {
			$digits = $country_code . $digits;
		}

		// A Kenyan mobile number is 12 digits once the country code is on
		// (254 + 9 digits) - anything wildly off that isn't worth guessing at.
		if ( strlen( $digits ) < 11 || strlen( $digits ) > 13 ) {
			return '';
		}

		return '+' . $digits;
	}

	/* ---------------- Sending ---------------- */

	/**
	 * @param string   $phone   E.164 number, from format_phone().
	 * @param string   $message
	 * @param WC_Order $order   For the order note / log context only.
	 * @param string   $audience 'customer' or 'vendor', for the order note.
	 */
	private function send( $phone, $message, $order, $audience ) {
		$environment = get_option( 'fmke_sms_environment', 'demo' );

		if ( 'demo' === $environment ) {
			$order->add_order_note( sprintf( "DEMO MODE: SMS to %s (%s) not actually sent:\n%s", $phone, $audience, $message ) );
			return;
		}

		$username = get_option( 'fmke_sms_username', '' );
		$api_key  = get_option( 'fmke_sms_api_key', '' );

		if ( ! $username || ! $api_key ) {
			$this->log( 'SMS not sent (missing Africa\'s Talking credentials) to ' . $phone );
			return;
		}

		$url    = ( 'live' === $environment ) ? self::AT_LIVE_URL : self::AT_SANDBOX_URL;
		$body   = array(
			'username' => $username,
			'to'       => $phone,
			'message'  => $message,
		);
		$sender = get_option( 'fmke_sms_sender_id', '' );
		if ( $sender && 'live' === $environment ) {
			$body['from'] = $sender;
		}

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'apiKey'       => $api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $body,
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			$this->log( 'SMS send error to ' . $phone . ': ' . $response->get_error_message() );
			return;
		}

		$result      = json_decode( wp_remote_retrieve_body( $response ), true );
		$recipients  = $result['SMSMessageData']['Recipients'] ?? array();
		$first_status = $recipients[0]['status'] ?? '';

		// Africa's Talking answers 200 for "accepted for delivery" per
		// recipient, not just per request - check the per-recipient status,
		// not merely that the HTTP call succeeded.
		if ( 'Success' !== $first_status ) {
			$this->log( 'SMS to ' . $phone . ' not accepted: ' . wp_remote_retrieve_body( $response ) );
			$order->add_order_note( sprintf( 'SMS to %s (%s) failed to send: %s', $phone, $audience, $first_status ? $first_status : 'unknown error' ) );
			return;
		}

		$order->add_order_note( sprintf( 'SMS sent to %s (%s).', $phone, $audience ) );
	}

	private function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'fmke-sms' ) );
		}
	}
}

new FMKE_SMS();
