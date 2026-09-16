<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * M-Pesa STK Push gateway.
 *
 * Two modes, chosen in the gateway settings:
 *
 * 1. "Daraja Sandbox" - real calls to Safaricom's free Daraja sandbox
 *    (https://sandbox.safaricom.co.ke). Needs free test credentials from
 *    https://developer.safaricom.co.ke. This is what you use for real
 *    end-to-end testing before going live.
 *
 * 2. "Demo Mode" - no credentials needed at all. It mimics the STK Push
 *    UX (order goes "on-hold" > customer sees "Check your phone" > order
 *    auto-confirms as "processing" after a few seconds) purely for demos,
 *    client walkthroughs, or UI testing when you don't have Daraja
 *    credentials handy yet.
 *
 * Payment-safety measures in the Sandbox/Live path:
 *
 * - The Safaricom callback URL carries a secret token (auto-generated,
 *   stored in `fmke_mpesa_callback_secret`) so handle_callback() rejects
 *   any POST that doesn't know it. Daraja doesn't sign its callbacks, so
 *   this is what stops a stranger from guessing the URL and POSTing a
 *   fake "payment successful" for an order they didn't pay for.
 * - handle_callback() is idempotent: once an order is processing/
 *   completed/failed/cancelled, a repeat or late callback is acknowledged
 *   and ignored instead of re-running payment_complete()/update_status().
 * - reconcile_pending_payments() (run every 5 minutes via WP-Cron, see
 *   fmke_bootstrap() in the main plugin file) polls Daraja's status-query
 *   API for any order that's been "on-hold" for a while with no callback,
 *   so a dropped callback doesn't leave an order stuck forever. The same
 *   check is also available as a manual "Check M-Pesa payment status now"
 *   order action for a single stuck order.
 * - Switching the Mode to "Daraja Production" only sticks if the admin
 *   also ticks the production-confirmation checkbox in the same save;
 *   otherwise it's silently reverted to Sandbox (see
 *   process_admin_options()) so a fat-fingered dropdown can't start
 *   taking real customer money with untested credentials.
 */
class WC_Gateway_Mpesa_STK extends WC_Payment_Gateway {

	public $environment;
	public $shortcode;
	public $passkey;
	public $consumer_key;
	public $consumer_secret;

	public function __construct() {
		$this->id                 = 'mpesa_stk';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = 'M-Pesa STK Push';
		$this->method_description = 'Accept payments via Safaricom M-Pesa STK Push (Daraja Sandbox or Demo Mode).';
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->environment     = $this->get_option( 'environment', 'demo' );
		$this->shortcode       = $this->get_option( 'shortcode' );
		$this->passkey         = $this->get_option( 'passkey' );
		$this->consumer_key    = $this->get_option( 'consumer_key' );
		$this->consumer_secret = $this->get_option( 'consumer_secret' );

		// Note: process_admin_options() is overridden below to enforce the
		// production-confirmation checkbox before "Mode: Production" sticks.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Callback URL Safaricom will POST to (Daraja sandbox mode only).
		add_action( 'woocommerce_api_wc_gateway_mpesa_stk', array( $this, 'handle_callback' ) );

		// Demo mode: WP-Cron single event that "completes" the order a few seconds later.
		// (Demo STK screen hooks are registered independently in the main
		// plugin file, not here - see fmke_bootstrap(). This class isn't
		// guaranteed to be instantiated on every request, since WooCommerce
		// only creates gateway objects lazily when a checkout template
		// actually renders, which never happens on admin-post.php.)
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => 'Enable/Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable M-Pesa STK Push',
				'default' => 'yes',
			),
			'title'           => array(
				'title'       => 'Title',
				'type'        => 'text',
				'default'     => 'Pay with M-Pesa',
				'desc_tip'    => true,
				'description' => 'Shown to the customer at checkout.',
			),
			'description'     => array(
				'title'   => 'Description',
				'type'    => 'textarea',
				'default' => 'You will receive an M-Pesa prompt on your phone to enter your PIN and complete payment.',
			),
			'environment'     => array(
				'title'       => 'Mode',
				'type'        => 'select',
				'options'     => array(
					'demo'    => 'Demo Mode (no credentials needed - simulates STK push)',
					'sandbox' => 'Daraja Sandbox (real API, free test credentials)',
					'live'    => 'Daraja Production (real M-Pesa payments - requires Go-Live approval)',
				),
				'default'     => 'demo',
				'description' => 'Start in Demo Mode to test the flow, move to Daraja Sandbox for end-to-end testing, and only switch to Production once Safaricom has approved your Go-Live application and you are using your real Paybill/Till credentials.',
			),
			'live_confirm'    => array(
				'title'       => 'Confirm Production Use',
				'type'        => 'checkbox',
				'label'       => 'I have Safaricom Go-Live approval and have double-checked the shortcode/passkey/consumer key & secret below are my PRODUCTION credentials, not sandbox ones.',
				'default'     => 'no',
				'description' => 'Required to actually switch Mode to "Daraja Production" and start taking real customer payments. If this is left unticked, saving with Mode set to Production will be reverted back to Sandbox automatically.',
			),
			'shortcode'       => array(
				'title'       => 'Business Shortcode (Paybill/Till)',
				'type'        => 'text',
				'description' => 'From your Daraja app. Use 174379 for the standard Safaricom sandbox test paybill.',
			),
			'passkey'         => array(
				'title' => 'Passkey',
				'type'  => 'password',
			),
			'consumer_key'    => array(
				'title' => 'Consumer Key',
				'type'  => 'text',
			),
			'consumer_secret' => array(
				'title' => 'Consumer Secret',
				'type'  => 'password',
			),
		);
	}

	/**
	 * Saves settings as normal, but refuses to let "Mode: Production"
	 * persist unless the "Confirm Production Use" checkbox was ticked in
	 * the same save. Prevents an accidental dropdown change from taking
	 * real customer money against untested/placeholder credentials.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		if ( 'live' === $this->get_option( 'environment' ) && 'yes' !== $this->get_option( 'live_confirm' ) ) {
			$this->update_option( 'environment', 'sandbox' );
			$this->environment = 'sandbox';

			if ( class_exists( 'WC_Admin_Settings' ) ) {
				WC_Admin_Settings::add_error( 'M-Pesa: Mode was reverted to "Daraja Sandbox" because "Confirm Production Use" wasn\'t ticked. Tick it and save again to actually go live.' );
			}
		}

		return $saved;
	}

	/**
	 * Secret token appended to the Daraja callback URL. Safaricom doesn't
	 * sign its STK callbacks, so this is the only thing standing between
	 * handle_callback() and anyone on the internet who finds/guesses the
	 * URL and POSTs a fake "payment successful" result for an order.
	 * Generated once and stored; rotates only if the option is deleted.
	 */
	private function get_callback_secret() {
		$secret = get_option( 'fmke_mpesa_callback_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 32, false, false );
			update_option( 'fmke_mpesa_callback_secret', $secret );
		}
		return $secret;
	}

	/**
	 * Phone number field shown on checkout.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}
		echo '<p class="form-row form-row-wide">';
		echo '<label for="fmke_mpesa_phone">M-Pesa Phone Number (2547XXXXXXXX)</label>';
		echo '<input type="tel" id="fmke_mpesa_phone" name="fmke_mpesa_phone" placeholder="2547XXXXXXXX" required />';
		echo '</p>';
	}

	public function validate_fields() {
		if ( empty( $_POST['fmke_mpesa_phone'] ) ) {
			wc_add_notice( 'Please enter the M-Pesa phone number to receive the STK push.', 'error' );
			return false;
		}
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$phone = sanitize_text_field( $_POST['fmke_mpesa_phone'] );
		$order->update_meta_data( '_fmke_mpesa_phone', $phone );
		$order->save();

		if ( 'demo' === $this->environment ) {
			return $this->process_demo_payment( $order, $phone );
		}

		// Both 'sandbox' and 'live' use the same Daraja flow; api_base_url()
		// is what actually decides which host gets called.
		return $this->process_daraja_payment( $order, $phone );
	}

	/* ---------------- DEMO MODE ---------------- */

	private function process_demo_payment( $order, $phone ) {
		$order->update_status( 'pending', "DEMO MODE: Simulated STK push sent to {$phone}." );

		wc_reduce_stock_levels( $order->get_id() );
		WC()->cart->empty_cart();

		$demo_url = add_query_arg( array(
			'action'   => 'fmke_demo_stk',
			'order_id' => $order->get_id(),
			'key'      => $order->get_order_key(),
		), admin_url( 'admin-post.php' ) );

		return array(
			'result'   => 'success',
			'redirect' => $demo_url,
		);
	}

	/**
	 * Renders a plain page that looks like the "check your phone" moment
	 * of a real STK push, with a button to simulate the customer entering
	 * their PIN - since there's no real phone to send a push to in Demo Mode.
	 */
	public function handle_demo_stk_screen() {
		$order_id = absint( $_GET['order_id'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order || $order->get_order_key() !== ( $_GET['key'] ?? '' ) ) {
			wp_die( 'Invalid order.' );
		}

		if ( isset( $_POST['fmke_demo_stk_action'] ) ) {
			check_admin_referer( 'fmke_demo_stk_' . $order_id );

			if ( 'enter_pin' === $_POST['fmke_demo_stk_action'] ) {
				$this->demo_confirm_order( $order_id );
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			} else {
				$order->update_status( 'cancelled', 'DEMO MODE: Simulated STK push cancelled by customer.' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}
		}

		// Plain output, no CSS/theme styling - just enough to demonstrate the flow.
		echo '<h2>M-Pesa STK Push (Demo Mode)</h2>';
		echo '<p>A payment request for <strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong> has been sent to your phone.</p>';
		echo '<p>Please check your phone and enter your M-Pesa PIN to complete the payment.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'fmke_demo_stk_' . $order_id );
		echo '<button type="submit" name="fmke_demo_stk_action" value="enter_pin">Enter PIN &amp; Confirm Payment</button> ';
		echo '<button type="submit" name="fmke_demo_stk_action" value="cancel">Cancel</button>';
		echo '</form>';
		exit;
	}

	public function demo_confirm_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->has_status( array( 'processing', 'completed', 'cancelled' ) ) ) {
			return;
		}
		$mpesa_receipt = 'DEMO' . strtoupper( wp_generate_password( 8, false ) );
		$order->payment_complete( $mpesa_receipt );
		$order->add_order_note( "DEMO MODE: Simulated M-Pesa payment received. Receipt: {$mpesa_receipt}." );
	}

	/* ---------------- DARAJA SANDBOX / LIVE MODE ---------------- */

	/**
	 * Returns the correct Daraja host for the selected environment.
	 * This is the ONLY place that knows about sandbox vs production URLs -
	 * everything else calls this method, so cutover day is just changing
	 * the "Mode" dropdown in gateway settings, nothing in code.
	 */
	private function api_base_url() {
		return ( 'live' === $this->environment )
			? 'https://api.safaricom.co.ke'
			: 'https://sandbox.safaricom.co.ke';
	}

	private function get_access_token() {
		$url = $this->api_base_url() . '/oauth/v1/generate?grant_type=client_credentials';
		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->consumer_key . ':' . $this->consumer_secret ),
			),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['access_token'] ?? false;
	}

	private function process_daraja_payment( $order, $phone ) {
		$token = $this->get_access_token();
		if ( ! $token ) {
			wc_add_notice( 'Could not connect to M-Pesa. Check your API credentials.', 'error' );
			return array( 'result' => 'fail' );
		}

		$timestamp = date( 'YmdHis' );
		$password  = base64_encode( $this->shortcode . $this->passkey . $timestamp );

		$body = array(
			'BusinessShortCode' => $this->shortcode,
			'Password'          => $password,
			'Timestamp'         => $timestamp,
			'TransactionType'   => 'CustomerPayBillOnline',
			'Amount'            => (int) round( (float) $order->get_total() ),
			'PartyA'            => $phone,
			'PartyB'            => $this->shortcode,
			'PhoneNumber'       => $phone,
			'CallBackURL'       => add_query_arg( 'fmke_token', $this->get_callback_secret(), WC()->api_request_url( 'WC_Gateway_Mpesa_STK' ) ),
			'AccountReference'  => 'Order' . $order->get_id(),
			'TransactionDesc'   => 'Payment for order ' . $order->get_id(),
		);

		$response = wp_remote_post( $this->api_base_url() . '/mpesa/stkpush/v1/processrequest', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 25,
		) );

		if ( is_wp_error( $response ) ) {
			wc_add_notice( 'M-Pesa request failed: ' . $response->get_error_message(), 'error' );
			return array( 'result' => 'fail' );
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $result['CheckoutRequestID'] ) ) {
			$env_label = ( 'live' === $this->environment ) ? 'live Daraja' : 'Daraja sandbox';
			$order->update_meta_data( '_fmke_checkout_request_id', $result['CheckoutRequestID'] );
			$order->update_status( 'on-hold', "STK Push sent to customer phone ({$env_label}). Waiting for callback." );
			$order->save();
			WC()->cart->empty_cart();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		wc_add_notice( 'M-Pesa did not accept the request: ' . ( $result['errorMessage'] ?? 'Unknown error' ), 'error' );
		return array( 'result' => 'fail' );
	}

	/**
	 * Safaricom POSTs the payment result here:
	 * https://yourdomain.com/wc-api/wc_gateway_mpesa_stk
	 */
	public function handle_callback() {
		// Reject anything that doesn't know the secret we put in the
		// callback URL - Daraja doesn't sign its callbacks, so this is
		// the only real check that the caller is actually Safaricom (or
		// at least, someone who was handed our URL) rather than anyone
		// on the internet POSTing a fake success result.
		$token = isset( $_GET['fmke_token'] ) ? sanitize_text_field( wp_unslash( $_GET['fmke_token'] ) ) : '';
		if ( ! hash_equals( $this->get_callback_secret(), $token ) ) {
			status_header( 403 );
			exit;
		}

		$raw  = file_get_contents( 'php://input' );
		$data = json_decode( $raw, true );

		$stk = $data['Body']['stkCallback'] ?? null;
		if ( ! $stk ) {
			status_header( 400 );
			exit;
		}

		$checkout_request_id = $stk['CheckoutRequestID'];
		$result_code          = $stk['ResultCode'];

		$orders = wc_get_orders( array(
			'meta_key'   => '_fmke_checkout_request_id',
			'meta_value' => $checkout_request_id,
			'limit'      => 1,
		) );

		if ( empty( $orders ) ) {
			status_header( 404 );
			exit;
		}

		$order = $orders[0];

		// Idempotency: Safaricom can retry a callback, and the status-
		// reconciliation cron can also land here for the same order. If
		// it's already resolved, acknowledge and stop instead of running
		// payment_complete()/update_status() a second time.
		if ( $order->has_status( array( 'processing', 'completed', 'failed', 'cancelled' ) ) ) {
			status_header( 200 );
			echo wp_json_encode( array( 'ResultCode' => 0, 'ResultDesc' => 'Already processed' ) );
			exit;
		}

		if ( 0 === (int) $result_code ) {
			$receipt = '';
			foreach ( $stk['CallbackMetadata']['Item'] ?? array() as $item ) {
				if ( 'MpesaReceiptNumber' === $item['Name'] ) {
					$receipt = $item['Value'];
				}
			}
			$order->payment_complete( $receipt );
			$order->add_order_note( "M-Pesa payment confirmed. Receipt: {$receipt}." );
		} else {
			$order->update_status( 'failed', 'M-Pesa payment failed or was cancelled by customer: ' . ( $stk['ResultDesc'] ?? '' ) );
		}

		status_header( 200 );
		echo wp_json_encode( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ) );
		exit;
	}

	/* ---------------- CALLBACK-MISSED SAFETY NET ---------------- */

	/**
	 * Cron entry point (hooked to 'fmke_mpesa_reconcile' every 5 minutes,
	 * see fmke_bootstrap() in the main plugin file). Finds M-Pesa orders
	 * that have been "on-hold" for a while with no callback yet, and
	 * polls Daraja directly to find out what actually happened - so a
	 * dropped/late callback (customer closed the app, a network blip,
	 * Safaricom retry logic giving up, etc.) doesn't leave an order (and
	 * the vendor's stock/earnings) stuck in limbo indefinitely.
	 */
	public static function reconcile_pending_payments() {
		$gateway = new self();

		if ( ! $gateway->shortcode || ! $gateway->passkey || ! $gateway->consumer_key || ! $gateway->consumer_secret ) {
			return; // Not configured for Sandbox/Live - nothing to reconcile.
		}

		$orders = wc_get_orders( array(
			'status'         => 'on-hold',
			'payment_method' => 'mpesa_stk',
			'limit'          => 50,
			'meta_key'       => '_fmke_checkout_request_id',
			'meta_compare'   => 'EXISTS',
		) );

		foreach ( $orders as $order ) {
			$modified = $order->get_date_modified();
			if ( ! $modified ) {
				continue;
			}
			$age_minutes = ( time() - $modified->getTimestamp() ) / MINUTE_IN_SECONDS;

			// Give the real callback a few minutes to arrive first so we
			// don't race it, and stop bothering with STK pushes so old
			// they've clearly gone stale either way (Daraja checkout
			// requests themselves expire long before this).
			if ( $age_minutes < 3 || $age_minutes > ( 24 * 60 ) ) {
				continue;
			}

			$gateway->query_and_apply_status( $order );
		}
	}

	/**
	 * Looks up the real status of one order's STK push directly from
	 * Daraja (the stkpushquery endpoint) and applies it, exactly like a
	 * callback would have. Used by both the reconciliation cron above and
	 * the manual "Check M-Pesa payment status now" order action.
	 */
	public function query_and_apply_status( $order ) {
		if ( $order->has_status( array( 'processing', 'completed', 'failed', 'cancelled' ) ) ) {
			return; // Already resolved, maybe the callback just landed.
		}

		$checkout_request_id = $order->get_meta( '_fmke_checkout_request_id' );
		if ( ! $checkout_request_id ) {
			return;
		}

		$token = $this->get_access_token();
		if ( ! $token ) {
			return; // Couldn't authenticate this run; the next run (or manual click) will retry.
		}

		$timestamp = date( 'YmdHis' );
		$password  = base64_encode( $this->shortcode . $this->passkey . $timestamp );

		$response = wp_remote_post( $this->api_base_url() . '/mpesa/stkpushquery/v1/query', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'BusinessShortCode' => $this->shortcode,
				'Password'          => $password,
				'Timestamp'         => $timestamp,
				'CheckoutRequestID' => $checkout_request_id,
			) ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( 'M-Pesa status check failed (network error): ' . $response->get_error_message() );
			return;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		// While Daraja is still waiting on the customer's PIN entry, this
		// query returns an error body (no ResultCode) rather than a
		// result - that just means "still pending", not "failed", so
		// leave the order on-hold and let a later run check again.
		if ( ! isset( $result['ResultCode'] ) ) {
			return;
		}

		if ( 0 === (int) $result['ResultCode'] ) {
			$order->payment_complete( $checkout_request_id );
			$order->add_order_note( 'M-Pesa payment confirmed via status check (Daraja callback never arrived). CheckoutRequestID: ' . $checkout_request_id );
		} else {
			$order->update_status( 'failed', 'M-Pesa payment failed or was cancelled (confirmed via status check): ' . ( $result['ResultDesc'] ?? '' ) );
		}
	}
}
