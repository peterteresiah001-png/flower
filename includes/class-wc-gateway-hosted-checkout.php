<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Card / bank hosted-checkout gateway covering IntaSend and Pesapal
 * (pick a provider in settings). Both work the same way: the customer is
 * redirected to a hosted page, comes back to a return URL, and the payment
 * is confirmed server-side via webhook/IPN.
 *
 * "Demo Mode" simulates the redirect with a local page that has
 * "Simulate Success" / "Simulate Failure" buttons, so you can test the full
 * checkout flow without registering for API keys yet.
 *
 * Pesapal uses API 3.0 (JSON):
 *   POST /api/Auth/RequestToken           -> bearer token (valid 5 minutes)
 *   POST /api/URLSetup/RegisterIPN        -> ipn_id (notification_id)
 *   POST /api/Transactions/SubmitOrderRequest -> redirect_url + order_tracking_id
 *   GET  /api/Transactions/GetTransactionStatus?orderTrackingId=...
 *   POST /api/Transactions/RefundRequest
 * Docs: https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/api-reference
 */
class WC_Gateway_Hosted_Checkout extends WC_Payment_Gateway {

	/** Pesapal API 3.0 hosts. */
	const PESAPAL_SANDBOX_URL = 'https://cybqa.pesapal.com/pesapalv3';
	const PESAPAL_LIVE_URL    = 'https://pay.pesapal.com/v3';

	/** Pesapal GetTransactionStatus status_code values. */
	const PESAPAL_INVALID   = 0;
	const PESAPAL_COMPLETED = 1;
	const PESAPAL_FAILED    = 2;
	const PESAPAL_REVERSED  = 3;

	public $provider;
	public $environment;
	public $public_key;
	public $secret_key;

	public function __construct() {
		$this->id                 = 'fmke_hosted_checkout';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = 'Card / Bank (IntaSend or Pesapal)';
		$this->method_description = 'Redirects the customer to IntaSend or Pesapal hosted checkout for Visa/Mastercard, bank and mobile-money payments. Supports Demo, Sandbox and Production.';
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->provider    = $this->get_option( 'provider', 'intasend' );
		$this->environment = $this->get_option( 'environment', 'demo' );
		$this->public_key  = $this->get_option( 'public_key' );
		$this->secret_key  = $this->get_option( 'secret_key' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Webhook / IPN / redirect-return handlers. One WC API endpoint,
		// routed by query arg - see handle_webhook().
		add_action( 'woocommerce_api_wc_gateway_hosted_checkout', array( $this, 'handle_webhook' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_demo_return' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => 'Enable/Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable Card/Bank Checkout',
				'default' => 'yes',
			),
			'title'       => array(
				'title'   => 'Title',
				'type'    => 'text',
				'default' => 'Pay by Card / Bank',
			),
			'description' => array(
				'title'   => 'Description',
				'type'    => 'textarea',
				'default' => 'Pay securely with Visa, Mastercard, bank transfer or mobile money. You will be redirected to a secure page to complete payment.',
			),
			'provider'    => array(
				'title'   => 'Provider',
				'type'    => 'select',
				'options' => array(
					'intasend' => 'IntaSend',
					'pesapal'  => 'Pesapal',
				),
				'default' => 'intasend',
			),
			'environment' => array(
				'title'       => 'Mode',
				'type'        => 'select',
				'options'     => array(
					'demo'    => 'Demo Mode (no credentials needed - local simulated checkout)',
					'sandbox' => 'Provider Sandbox (real API, free test credentials)',
					'live'    => 'Provider Production (real card/bank payments)',
				),
				'default'     => 'demo',
				'description' => 'Get free sandbox keys at intasend.com or developer.pesapal.com. Both providers are fully wired for Sandbox and Production - cutover is this dropdown, not a code change.',
			),
			'public_key'  => array(
				'title'       => 'Publishable Key / Pesapal Consumer Key',
				'type'        => 'text',
				'description' => 'IntaSend: publishable key. Pesapal: consumer_key.',
			),
			'secret_key'  => array(
				'title'       => 'Secret Key / Pesapal Consumer Secret',
				'type'        => 'password',
				'description' => 'IntaSend: secret key. Pesapal: consumer_secret.',
			),
			'pesapal_ipn' => array(
				'title'       => 'Pesapal IPN',
				'type'        => 'checkbox',
				'label'       => 'Re-register my Pesapal IPN URL on the next payment',
				'default'     => 'no',
				'description' => 'The IPN URL is registered with Pesapal automatically and cached. Tick this after changing your site URL or Pesapal credentials. Current IPN URL: <code>' . esc_html( self::pesapal_ipn_url() ) . '</code>',
			),
		);
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( 'demo' === $this->environment ) {
			return $this->process_demo_checkout( $order );
		}

		if ( 'pesapal' === $this->provider ) {
			return $this->pesapal_checkout_redirect( $order );
		}

		return $this->intasend_checkout_redirect( $order );
	}

	/* ---------------- DEMO MODE ---------------- */

	private function process_demo_checkout( $order ) {
		$order->update_status( 'pending', 'Redirected to simulated hosted checkout (Demo Mode).' );

		$demo_url = add_query_arg( array(
			'fmke_demo_checkout' => 1,
			'order_id'           => $order->get_id(),
			'key'                => $order->get_order_key(),
		), home_url( '/' ) );

		return array(
			'result'   => 'success',
			'redirect' => $demo_url,
		);
	}

	/**
	 * Renders a very plain (no CSS) simulated hosted-checkout page with
	 * Success/Fail buttons, and processes the choice.
	 */
	public function maybe_handle_demo_return() {
		if ( empty( $_GET['fmke_demo_checkout'] ) ) {
			return;
		}

		$order_id = absint( $_GET['order_id'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order || $order->get_order_key() !== ( $_GET['key'] ?? '' ) ) {
			wp_die( 'Invalid order.' );
		}

		if ( isset( $_POST['fmke_demo_action'] ) ) {
			check_admin_referer( 'fmke_demo_checkout_' . $order_id );

			if ( 'success' === $_POST['fmke_demo_action'] ) {
				$txn = 'DEMO-' . strtoupper( wp_generate_password( 10, false ) );
				$order->payment_complete( $txn );
				$order->add_order_note( "DEMO MODE: Simulated {$this->provider} payment succeeded. Ref: {$txn}." );
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			} else {
				$order->update_status( 'failed', "DEMO MODE: Simulated {$this->provider} payment failed/cancelled." );
				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}
		}

		// Plain output, no CSS/theme styling - just enough to test the flow.
		echo '<h2>Simulated ' . esc_html( ucfirst( $this->provider ) ) . ' Checkout (Demo Mode)</h2>';
		echo '<p>Order #' . esc_html( $order_id ) . ' - Total: ' . wp_kses_post( $order->get_formatted_order_total() ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'fmke_demo_checkout_' . $order_id );
		echo '<button type="submit" name="fmke_demo_action" value="success">Simulate Successful Payment</button> ';
		echo '<button type="submit" name="fmke_demo_action" value="fail">Simulate Failed Payment</button>';
		echo '</form>';
		exit;
	}

	/* ---------------- INTASEND ---------------- */

	/**
	 * Returns the correct IntaSend host for the selected environment.
	 * Only place that knows sandbox vs production - cutover day is a
	 * settings dropdown change, not a code edit.
	 */
	private function intasend_base_url() {
		return ( 'live' === $this->environment )
			? 'https://payment.intasend.com'
			: 'https://sandbox.intasend.com';
	}

	private function intasend_checkout_redirect( $order ) {
		$response = wp_remote_post( $this->intasend_base_url() . '/api/v1/checkout/', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->secret_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'public_key'   => $this->public_key,
				'amount'       => (float) $order->get_total(),
				'currency'     => $order->get_currency(),
				'email'        => $order->get_billing_email(),
				'first_name'   => $order->get_billing_first_name(),
				'last_name'    => $order->get_billing_last_name(),
				'api_ref'      => 'order-' . $order->get_id(),
				'redirect_url' => $this->get_return_url( $order ),
			) ),
			'timeout' => 25,
		) );

		if ( is_wp_error( $response ) ) {
			$this->log( 'IntaSend checkout error: ' . $response->get_error_message() );
			wc_add_notice( 'IntaSend error: ' . $response->get_error_message(), 'error' );
			return array( 'result' => 'fail' );
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $result['url'] ) ) {
			$order->update_status( 'pending', 'Redirected to IntaSend checkout.' );
			return array( 'result' => 'success', 'redirect' => $result['url'] );
		}

		$this->log( 'IntaSend returned no checkout URL: ' . wp_remote_retrieve_body( $response ) );
		wc_add_notice( 'IntaSend did not return a checkout URL.', 'error' );
		return array( 'result' => 'fail' );
	}

	/* ---------------- PESAPAL (API 3.0) ---------------- */

	private function pesapal_base_url() {
		return ( 'live' === $this->environment )
			? self::PESAPAL_LIVE_URL
			: self::PESAPAL_SANDBOX_URL;
	}

	/**
	 * The single WC API endpoint both Pesapal callbacks and IPNs come back
	 * to. Static because init_form_fields() needs to print it before the
	 * object is fully configured.
	 */
	public static function pesapal_ipn_url() {
		return add_query_arg( 'fmke_pesapal_ipn', '1', self::api_endpoint_url() );
	}

	/**
	 * WC()->api_request_url() isn't guaranteed to be callable this early
	 * (init_form_fields() runs from the constructor), so fall back to the
	 * same URL WooCommerce would have built.
	 */
	private static function api_endpoint_url() {
		if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'api_request_url' ) ) {
			return WC()->api_request_url( 'wc_gateway_hosted_checkout' );
		}
		return home_url( '/wc-api/wc_gateway_hosted_checkout/', is_ssl() ? 'https' : 'http' );
	}

	private function pesapal_callback_url() {
		return add_query_arg( 'fmke_pesapal_return', '1', self::api_endpoint_url() );
	}

	/**
	 * Bearer token, cached for 4 minutes (Pesapal expires them at 5).
	 * Cache key includes the environment and a hash of the consumer key so
	 * swapping credentials or flipping sandbox->live can't reuse a stale
	 * token.
	 */
	private function pesapal_get_token() {
		$cache_key = 'fmke_pesapal_token_' . $this->environment . '_' . md5( (string) $this->public_key );
		$cached    = get_transient( $cache_key );

		if ( $cached ) {
			return $cached;
		}

		if ( ! $this->public_key || ! $this->secret_key ) {
			return new WP_Error( 'fmke_pesapal_no_keys', 'Pesapal consumer key/secret are not configured.' );
		}

		$response = wp_remote_post( $this->pesapal_base_url() . '/api/Auth/RequestToken', array(
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'consumer_key'    => $this->public_key,
				'consumer_secret' => $this->secret_key,
			) ),
			'timeout' => 25,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['token'] ) ) {
			$this->log( 'Pesapal auth failed: ' . wp_remote_retrieve_body( $response ) );
			return new WP_Error(
				'fmke_pesapal_auth',
				$body['error']['message'] ?? ( $body['message'] ?? 'Could not authenticate with Pesapal.' )
			);
		}

		set_transient( $cache_key, $body['token'], 4 * MINUTE_IN_SECONDS );
		return $body['token'];
	}

	/**
	 * Pesapal requires the IPN URL to be registered once; it hands back an
	 * ipn_id that every SubmitOrderRequest must carry as notification_id.
	 * Cached in an option keyed by environment + URL hash, so it's only
	 * registered again if the site URL, credentials or mode change (or the
	 * admin ticks "Re-register" in settings).
	 */
	private function pesapal_get_ipn_id( $token ) {
		$ipn_url    = self::pesapal_ipn_url();
		$option_key = 'fmke_pesapal_ipn_' . $this->environment;
		$stored     = get_option( $option_key );
		$fingerprint = md5( $ipn_url . '|' . $this->public_key );

		$force = ( 'yes' === $this->get_option( 'pesapal_ipn', 'no' ) );

		if ( ! $force && is_array( $stored ) && ! empty( $stored['id'] ) && ( $stored['fingerprint'] ?? '' ) === $fingerprint ) {
			return $stored['id'];
		}

		$response = wp_remote_post( $this->pesapal_base_url() . '/api/URLSetup/RegisterIPN', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'url'                   => $ipn_url,
				'ipn_notification_type' => 'POST',
			) ),
			'timeout' => 25,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['ipn_id'] ) ) {
			$this->log( 'Pesapal RegisterIPN failed: ' . wp_remote_retrieve_body( $response ) );
			return new WP_Error(
				'fmke_pesapal_ipn',
				$body['error']['message'] ?? 'Could not register the IPN URL with Pesapal.'
			);
		}

		update_option( $option_key, array(
			'id'          => $body['ipn_id'],
			'url'         => $ipn_url,
			'fingerprint' => $fingerprint,
		), false );

		// One-shot flag: clear it so we don't re-register on every order.
		if ( $force ) {
			$this->update_option( 'pesapal_ipn', 'no' );
		}

		return $body['ipn_id'];
	}

	/**
	 * Submits the order to Pesapal and redirects the customer to the hosted
	 * card/bank/mobile-money page.
	 */
	private function pesapal_checkout_redirect( $order ) {
		$token = $this->pesapal_get_token();
		if ( is_wp_error( $token ) ) {
			wc_add_notice( 'Pesapal error: ' . $token->get_error_message(), 'error' );
			return array( 'result' => 'fail' );
		}

		$ipn_id = $this->pesapal_get_ipn_id( $token );
		if ( is_wp_error( $ipn_id ) ) {
			wc_add_notice( 'Pesapal error: ' . $ipn_id->get_error_message(), 'error' );
			return array( 'result' => 'fail' );
		}

		// Unique per attempt: a customer who fails once and retries must not
		// reuse the same merchant reference. The order ID is recoverable from
		// it with a regex - see order_from_merchant_reference().
		$merchant_ref = 'order-' . $order->get_id() . '-' . time();

		$payload = array(
			'id'              => $merchant_ref,
			'currency'        => $order->get_currency(),
			'amount'          => (float) $order->get_total(),
			'description'     => sprintf( 'Order #%d at %s', $order->get_id(), get_bloginfo( 'name' ) ),
			'callback_url'    => $this->pesapal_callback_url(),
			'notification_id' => $ipn_id,
			'billing_address' => array(
				'email_address' => $order->get_billing_email(),
				'phone_number'  => $order->get_billing_phone(),
				'country_code'  => $order->get_billing_country() ? $order->get_billing_country() : 'KE',
				'first_name'    => $order->get_billing_first_name(),
				'middle_name'   => '',
				'last_name'     => $order->get_billing_last_name(),
				'line_1'        => $order->get_billing_address_1(),
				'line_2'        => $order->get_billing_address_2(),
				'city'          => $order->get_billing_city(),
				'state'         => $order->get_billing_state(),
				'postal_code'   => $order->get_billing_postcode(),
				'zip_code'      => $order->get_billing_postcode(),
			),
		);

		$response = wp_remote_post( $this->pesapal_base_url() . '/api/Transactions/SubmitOrderRequest', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Pesapal SubmitOrderRequest transport error: ' . $response->get_error_message() );
			wc_add_notice( 'Pesapal error: ' . $response->get_error_message(), 'error' );
			return array( 'result' => 'fail' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['redirect_url'] ) ) {
			$this->log( 'Pesapal SubmitOrderRequest failed: ' . wp_remote_retrieve_body( $response ) );
			wc_add_notice(
				'Pesapal error: ' . ( $body['error']['message'] ?? 'no checkout URL was returned.' ),
				'error'
			);
			return array( 'result' => 'fail' );
		}

		$order->update_meta_data( '_fmke_pesapal_merchant_ref', $merchant_ref );
		$order->update_meta_data( '_fmke_pesapal_tracking_id', $body['order_tracking_id'] ?? '' );
		$order->save();

		$order->update_status( 'pending', 'Redirected to Pesapal checkout. Tracking ID: ' . ( $body['order_tracking_id'] ?? 'n/a' ) );

		return array( 'result' => 'success', 'redirect' => $body['redirect_url'] );
	}

	/**
	 * Calls GetTransactionStatus. Returns the decoded body or WP_Error.
	 */
	private function pesapal_query_status( $tracking_id ) {
		$token = $this->pesapal_get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = add_query_arg(
			'orderTrackingId',
			rawurlencode( $tracking_id ),
			$this->pesapal_base_url() . '/api/Transactions/GetTransactionStatus'
		);

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
			'timeout' => 25,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['status_code'] ) ) {
			$this->log( 'Pesapal GetTransactionStatus unexpected body: ' . wp_remote_retrieve_body( $response ) );
			return new WP_Error( 'fmke_pesapal_status', 'Pesapal did not return a transaction status.' );
		}

		return $body;
	}

	/**
	 * Applies a Pesapal status result to an order. Idempotent - safe to run
	 * from the callback, the IPN and the admin "check now" action, which
	 * routinely all fire for the same payment.
	 *
	 * @return bool True if the order is paid after this call.
	 */
	private function pesapal_apply_status( $order, $status ) {
		$code    = (int) $status['status_code'];
		$desc    = $status['payment_status_description'] ?? '';
		$confirm = $status['confirmation_code'] ?? '';
		$method  = $status['payment_method'] ?? '';

		if ( $order->is_paid() ) {
			return true;
		}

		if ( self::PESAPAL_COMPLETED === $code ) {
			// Never mark paid on an amount that doesn't match the order -
			// a tampered or partially-captured payment goes to a human.
			$paid  = (float) ( $status['amount'] ?? 0 );
			$total = (float) $order->get_total();

			if ( abs( $paid - $total ) > 0.01 ) {
				$order->update_status(
					'on-hold',
					sprintf(
						'Pesapal reported a COMPLETED payment of %s but the order total is %s. Held for manual review. Confirmation code: %s.',
						wc_price( $paid ),
						wc_price( $total ),
						$confirm
					)
				);
				return false;
			}

			$order->update_meta_data( '_fmke_pesapal_confirmation_code', $confirm );
			$order->update_meta_data( '_fmke_pesapal_payment_method', $method );
			$order->save();

			$order->payment_complete( $confirm );
			$order->add_order_note( sprintf( 'Pesapal payment confirmed (%s). Confirmation code: %s.', $method ? $method : 'card/bank', $confirm ) );
			return true;
		}

		if ( self::PESAPAL_FAILED === $code ) {
			$order->update_status( 'failed', 'Pesapal payment failed: ' . ( $status['description'] ?? $desc ) );
			return false;
		}

		if ( self::PESAPAL_REVERSED === $code ) {
			$order->update_status( 'cancelled', 'Pesapal payment was reversed. Confirmation code: ' . $confirm );
			return false;
		}

		// 0 = INVALID, or still pending at Pesapal's end. Leave the order
		// alone; the IPN or the admin "check now" action will resolve it.
		$order->add_order_note( 'Pesapal status check: ' . ( $desc ? $desc : 'still pending' ) . '.' );
		return false;
	}

	/**
	 * Recovers the WooCommerce order from a Pesapal merchant reference
	 * ("order-123-1726480000").
	 */
	private function order_from_merchant_reference( $ref ) {
		if ( ! $ref || ! preg_match( '/order-(\d+)/', $ref, $m ) ) {
			return false;
		}
		return wc_get_order( (int) $m[1] );
	}

	/**
	 * Public entry point for the admin "Check card/bank payment status now"
	 * order action, and for anything else that needs to re-poll Pesapal for
	 * one order.
	 */
	public function query_and_apply_status( $order ) {
		if ( 'pesapal' !== $this->provider ) {
			$order->add_order_note( 'Status check skipped: this gateway is currently set to IntaSend. Check the IntaSend dashboard instead.' );
			return false;
		}

		$tracking_id = $order->get_meta( '_fmke_pesapal_tracking_id' );
		if ( ! $tracking_id ) {
			$order->add_order_note( 'Status check skipped: no Pesapal tracking ID is stored on this order.' );
			return false;
		}

		$status = $this->pesapal_query_status( $tracking_id );
		if ( is_wp_error( $status ) ) {
			$order->add_order_note( 'Pesapal status check failed: ' . $status->get_error_message() );
			return false;
		}

		return $this->pesapal_apply_status( $order, $status );
	}

	/* ---------------- WEBHOOK / IPN / RETURN ---------------- */

	/**
	 * Single endpoint at https://yourdomain.com/wc-api/wc_gateway_hosted_checkout
	 * routed three ways:
	 *   ?fmke_pesapal_return=1 - browser coming back from Pesapal
	 *   ?fmke_pesapal_ipn=1    - Pesapal server-to-server IPN
	 *   (neither)              - IntaSend webhook
	 */
	public function handle_webhook() {
		if ( ! empty( $_GET['fmke_pesapal_return'] ) ) {
			$this->handle_pesapal_callback();
			return;
		}

		if ( ! empty( $_GET['fmke_pesapal_ipn'] ) ) {
			$this->handle_pesapal_ipn();
			return;
		}

		$this->handle_intasend_webhook();
	}

	/**
	 * Customer's browser returning from the Pesapal hosted page. The query
	 * params are never trusted for the outcome - we always re-query
	 * GetTransactionStatus server-side before touching the order.
	 */
	private function handle_pesapal_callback() {
		$tracking_id = sanitize_text_field( wp_unslash( $_GET['OrderTrackingId'] ?? '' ) );
		$ref         = sanitize_text_field( wp_unslash( $_GET['OrderMerchantReference'] ?? '' ) );
		$order       = $this->order_from_merchant_reference( $ref );

		if ( ! $order || ! $tracking_id ) {
			$this->add_customer_notice( 'We could not match your payment to an order. Please contact us before paying again.' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Guard against someone replaying a callback with a tracking ID that
		// was never issued for this order.
		$expected = $order->get_meta( '_fmke_pesapal_tracking_id' );
		if ( $expected && ! hash_equals( (string) $expected, $tracking_id ) ) {
			$this->log( 'Pesapal callback tracking ID mismatch for order ' . $order->get_id() );
			$this->add_customer_notice( 'We could not verify your payment. Please contact us before paying again.' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$status = $this->pesapal_query_status( $tracking_id );

		if ( is_wp_error( $status ) ) {
			// The IPN is the safety net here - don't fail the order just
			// because this one lookup didn't go through.
			$order->add_order_note( 'Pesapal callback status lookup failed: ' . $status->get_error_message() );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$paid = $this->pesapal_apply_status( $order, $status );

		if ( $paid || $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$this->add_customer_notice(
			'Your payment was not completed: ' . ( $status['description'] ?? $status['payment_status_description'] ?? 'please try again.' )
		);
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Pesapal server-to-server IPN. Pesapal expects a JSON acknowledgement
	 * with status 200 (received and processed) or 500 (received, but we
	 * couldn't process it) - if it doesn't get one it retries.
	 */
	private function handle_pesapal_ipn() {
		// Registered as POST (JSON body), but handle the GET variant too so
		// a re-registration as GET doesn't silently break confirmations.
		$raw  = file_get_contents( 'php://input' );
		$json = json_decode( $raw, true );
		$data = is_array( $json ) ? array_merge( $_GET, $json ) : $_GET;

		$tracking_id = sanitize_text_field( wp_unslash( $data['OrderTrackingId'] ?? ( $data['orderTrackingId'] ?? '' ) ) );
		$ref         = sanitize_text_field( wp_unslash( $data['OrderMerchantReference'] ?? ( $data['orderMerchantReference'] ?? '' ) ) );
		$type        = sanitize_text_field( wp_unslash( $data['OrderNotificationType'] ?? ( $data['orderNotificationType'] ?? 'IPNCHANGE' ) ) );

		$order = $this->order_from_merchant_reference( $ref );

		if ( ! $order || ! $tracking_id ) {
			$this->log( 'Pesapal IPN could not be matched to an order. Raw: ' . $raw . ' Query: ' . wp_json_encode( $_GET ) );
			$this->pesapal_ipn_response( $type, $tracking_id, $ref, 500 );
		}

		$status = $this->pesapal_query_status( $tracking_id );

		if ( is_wp_error( $status ) ) {
			$this->log( 'Pesapal IPN status lookup failed for order ' . $order->get_id() . ': ' . $status->get_error_message() );
			// 500 tells Pesapal to retry later.
			$this->pesapal_ipn_response( $type, $tracking_id, $ref, 500 );
		}

		$this->pesapal_apply_status( $order, $status );
		$this->pesapal_ipn_response( $type, $tracking_id, $ref, 200 );
	}

	private function pesapal_ipn_response( $type, $tracking_id, $ref, $status ) {
		wp_send_json( array(
			'orderNotificationType'  => $type ? $type : 'IPNCHANGE',
			'orderTrackingId'        => $tracking_id,
			'orderMerchantReference' => $ref,
			'status'                 => $status,
		) );
	}

	/**
	 * IntaSend webhook. Expects api_ref like "order-123".
	 */
	private function handle_intasend_webhook() {
		$raw  = file_get_contents( 'php://input' );
		$data = json_decode( $raw, true );

		$ref   = $data['api_ref'] ?? '';
		$order = $this->order_from_merchant_reference( $ref );

		if ( ! $order ) {
			status_header( 400 );
			exit;
		}

		$state = strtoupper( $data['state'] ?? $data['status'] ?? '' );

		if ( $state && 'COMPLETE' !== $state ) {
			if ( in_array( $state, array( 'FAILED', 'CANCELLED' ), true ) && ! $order->is_paid() ) {
				$order->update_status( 'failed', 'IntaSend reported the payment as ' . $state . '.' );
			}
			status_header( 200 );
			exit;
		}

		if ( ! $order->is_paid() ) {
			$order->payment_complete( $data['invoice_id'] ?? ( $data['transaction_id'] ?? '' ) );
			$order->add_order_note( 'IntaSend payment confirmed via webhook.' );
		}

		status_header( 200 );
		exit;
	}

	/* ---------------- REFUNDS ---------------- */

	/**
	 * Pesapal refunds are keyed off the confirmation code stored when the
	 * payment completed. IntaSend refunds aren't wired up - they're done
	 * from the IntaSend dashboard, so we say so rather than silently
	 * marking the order refunded in WooCommerce only.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( 'pesapal' !== $this->provider || 'demo' === $this->environment ) {
			return new WP_Error(
				'fmke_refund_unsupported',
				'Automatic refunds are only available for Pesapal. Refund this payment in your provider dashboard, then mark the order refunded manually.'
			);
		}

		$confirmation = $order->get_meta( '_fmke_pesapal_confirmation_code' );
		if ( ! $confirmation ) {
			return new WP_Error( 'fmke_refund_no_code', 'No Pesapal confirmation code is stored on this order, so it cannot be refunded automatically.' );
		}

		$token = $this->pesapal_get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$user = wp_get_current_user();

		$response = wp_remote_post( $this->pesapal_base_url() . '/api/Transactions/RefundRequest', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'confirmation_code' => $confirmation,
				'amount'            => (string) ( $amount ? $amount : $order->get_total() ),
				'username'          => $user && $user->exists() ? $user->display_name : get_bloginfo( 'name' ),
				'remarks'           => $reason ? $reason : 'Refund for order #' . $order_id,
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Pesapal answers 200 = refund accepted for processing (it still
		// needs merchant approval on their side), 500 = rejected.
		if ( isset( $body['status'] ) && 200 === (int) $body['status'] ) {
			$order->add_order_note( 'Pesapal refund request submitted for ' . wc_price( $amount ? $amount : $order->get_total() ) . '. It still needs approval in your Pesapal dashboard.' );
			return true;
		}

		$this->log( 'Pesapal refund rejected: ' . wp_remote_retrieve_body( $response ) );
		return new WP_Error( 'fmke_refund_failed', $body['message'] ?? 'Pesapal rejected the refund request.' );
	}

	/* ---------------- UTIL ---------------- */

	/**
	 * wc_add_notice() needs a customer session, which isn't guaranteed on a
	 * /wc-api/ request - guard it so a missing session can't fatal the
	 * return-from-Pesapal redirect.
	 */
	private function add_customer_notice( $message, $type = 'error' ) {
		if ( function_exists( 'wc_add_notice' ) && WC()->session ) {
			wc_add_notice( $message, $type );
		}
	}

	private function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'fmke-hosted-checkout' ) );
		}
	}
}
