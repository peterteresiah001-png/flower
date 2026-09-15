<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Card / bank hosted-checkout gateway covering IntaSend and Pesapal sandboxes
 * (pick a provider in settings). Both work the same way for an MVP: customer
 * is redirected to a hosted page, then redirected back / pinged via webhook.
 *
 * "Demo Mode" simulates the redirect with a local WooCommerce page that has
 * "Simulate Success" / "Simulate Failure" buttons, so you can test the full
 * checkout flow without registering for API keys yet.
 */
class WC_Gateway_Hosted_Checkout extends WC_Payment_Gateway {

	public $provider;
	public $environment;
	public $public_key;
	public $secret_key;

	public function __construct() {
		$this->id                 = 'fmke_hosted_checkout';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = 'Card / Bank (IntaSend or Pesapal)';
		$this->method_description = 'Redirects the customer to IntaSend or Pesapal hosted checkout for card/bank payments. Supports Sandbox or Demo Mode.';
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->provider    = $this->get_option( 'provider', 'intasend' );
		$this->environment = $this->get_option( 'environment', 'demo' );
		$this->public_key  = $this->get_option( 'public_key' );
		$this->secret_key  = $this->get_option( 'secret_key' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Webhook / redirect return handlers.
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
				'default' => 'You will be redirected to a secure page to complete payment by card or bank transfer.',
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
					'live'    => 'Provider Production (real card/bank payments - IntaSend only, see note)',
				),
				'default'     => 'demo',
				'description' => 'Get free sandbox keys at intasend.com or developer.pesapal.com. NOTE: Production mode is only wired up for IntaSend - Pesapal is a placeholder with no real API calls yet (see pesapal_sandbox_redirect()). Do not pick Pesapal + Production until that is actually built.',
			),
			'public_key'  => array(
				'title' => 'Publishable / Consumer Key',
				'type'  => 'text',
			),
			'secret_key'  => array(
				'title' => 'Secret / Consumer Secret',
				'type'  => 'password',
			),
		);
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( 'demo' === $this->environment ) {
			return $this->process_demo_checkout( $order );
		}

		return $this->process_sandbox_checkout( $order );
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

	/* ---------------- SANDBOX MODE ---------------- */

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

	private function process_sandbox_checkout( $order ) {
		if ( 'pesapal' === $this->provider ) {
			if ( 'live' === $this->environment ) {
				wc_add_notice( 'Pesapal is not yet implemented for production - only a placeholder sandbox flow exists. Switch provider to IntaSend, or finish building Pesapal support before going live.', 'error' );
				return array( 'result' => 'fail' );
			}
			return $this->pesapal_sandbox_redirect( $order );
		}
		return $this->intasend_checkout_redirect( $order );
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
				'currency'     => 'KES',
				'email'        => $order->get_billing_email(),
				'api_ref'      => 'order-' . $order->get_id(),
				'redirect_url' => $this->get_return_url( $order ),
			) ),
			'timeout' => 25,
		) );

		if ( is_wp_error( $response ) ) {
			wc_add_notice( 'IntaSend error: ' . $response->get_error_message(), 'error' );
			return array( 'result' => 'fail' );
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $result['url'] ) ) {
			$order->update_status( 'pending', 'Redirected to IntaSend checkout.' );
			return array( 'result' => 'success', 'redirect' => $result['url'] );
		}

		wc_add_notice( 'IntaSend did not return a checkout URL.', 'error' );
		return array( 'result' => 'fail' );
	}

	private function pesapal_sandbox_redirect( $order ) {
		// Pesapal v3 requires an OAuth-style token request before submitting
		// the order. This is a minimal illustrative sandbox call - see
		// developer.pesapal.com for the full auth + IPN registration flow.
		wc_add_notice( 'Pesapal sandbox: configure your Consumer Key/Secret and IPN URL per developer.pesapal.com, then complete the token + SubmitOrderRequest calls here.', 'notice' );
		$order->update_status( 'pending', 'Awaiting Pesapal sandbox integration credentials.' );
		return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
	}

	/**
	 * Webhook / IPN endpoint for sandbox mode:
	 * https://yourdomain.com/wc-api/wc_gateway_hosted_checkout
	 */
	public function handle_webhook() {
		$raw  = file_get_contents( 'php://input' );
		$data = json_decode( $raw, true );

		$ref = $data['api_ref'] ?? ( $_GET['OrderTrackingId'] ?? '' );
		if ( ! $ref ) {
			status_header( 400 );
			exit;
		}

		// Expecting api_ref like "order-123".
		$order_id = (int) str_replace( 'order-', '', $ref );
		$order    = wc_get_order( $order_id );

		if ( $order && ! $order->is_paid() ) {
			$order->payment_complete( $data['transaction_id'] ?? '' );
			$order->add_order_note( 'Hosted checkout payment confirmed via webhook.' );
		}

		status_header( 200 );
		exit;
	}
}
