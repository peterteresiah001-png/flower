<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual M-Pesa Paybill/Till fallback.
 *
 * For when the STK Push gateway (WC_Gateway_Mpesa_STK) is down, misconfigured,
 * or a customer's phone just won't accept the prompt. Instead of an automated
 * push, the customer is given the Paybill/Till number and Account Number
 * directly and pays from their own M-Pesa menu, the same way they'd pay any
 * other paybill.
 *
 * Two ways an order gets confirmed:
 *
 * 1. AUTOMATIC (Paybill only) - if "Automatic confirmation" below is turned
 *    on and configured with a Daraja app's Consumer Key/Secret, this
 *    registers a Daraja C2B Confirmation URL for the Paybill's shortcode.
 *    Every payment Safaricom receives on that paybill is then pushed to
 *    handle_c2b_confirmation() automatically, which matches it to an order
 *    by the Account Number the customer typed (= the order number) and
 *    calls payment_complete() itself - no human needed.
 *
 *    This ONLY works for a real Paybill. A Till/Buy Goods number has no
 *    "Account Number" step in the M-Pesa menu at all, so there is nothing
 *    for the customer to enter that could identify which order a Till
 *    payment belongs to - Safaricom's C2B callback for a Till payment can't
 *    be matched to a specific order. For Till, or if automatic confirmation
 *    isn't configured, step 2 is the only option.
 *
 * 2. MANUAL - an admin/vendor checks the real M-Pesa statement and clicks
 *    "Confirm manual M-Pesa payment" on the order. This always exists as a
 *    fallback, even when automatic confirmation is on, in case a callback
 *    is missed, misconfigured, or the payment came in as a Till payment.
 *
 * A customer-submitted M-Pesa code (see the checkout field and the
 * post-checkout form) is never enough on its own to auto-complete an order -
 * it's only ever a convenience for whoever is checking the statement, or
 * for helping match an automatic confirmation that came in with a typo'd
 * account number. Only money Safaricom itself confirms (automatically) or a
 * human verifies (manually) completes an order.
 */
class WC_Gateway_Mpesa_Manual extends WC_Payment_Gateway {

	public $business_number;
	public $number_type;
	public $instructions;
	public $c2b_environment;
	public $consumer_key;
	public $consumer_secret;

	public function __construct() {
		$this->id                 = 'mpesa_manual';
		$this->icon               = '';
		$this->has_fields         = true;
		$this->method_title       = 'M-Pesa Paybill (Manual)';
		$this->method_description = 'Fallback for when STK Push is unavailable: the customer pays your Paybill/Till directly from their M-Pesa menu. Can confirm orders automatically via Daraja C2B (Paybill only), or be confirmed manually by an admin/vendor.';
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->business_number = $this->get_option( 'business_number' );
		$this->number_type     = $this->get_option( 'number_type', 'paybill' );
		$this->instructions    = $this->get_option( 'instructions' );
		$this->c2b_environment = $this->get_option( 'c2b_environment', 'off' );
		$this->consumer_key    = $this->get_option( 'consumer_key' );
		$this->consumer_secret = $this->get_option( 'consumer_secret' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Order-received page and emails: show the pay-in details.
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

		// Let the customer submit/update the M-Pesa code after paying, from
		// either the order-received page or My Account > Orders > View Order.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'maybe_render_code_form' ) );

		// Daraja C2B Confirmation/Validation callbacks (automatic confirmation, Paybill only).
		add_action( 'woocommerce_api_wc_gateway_mpesa_manual', array( $this, 'handle_c2b_request' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => 'Enable/Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable manual M-Pesa Paybill payment',
				'default' => 'no',
			),
			'title'           => array(
				'title'       => 'Title',
				'type'        => 'text',
				'default'     => 'Pay via M-Pesa Paybill (Manual)',
				'desc_tip'    => true,
				'description' => 'Shown to the customer at checkout. Consider labelling it clearly as a fallback, e.g. "M-Pesa Paybill (use this if the STK prompt didn\'t arrive)".',
			),
			'description'     => array(
				'title'   => 'Description',
				'type'    => 'textarea',
				'default' => 'Pay directly from your M-Pesa menu using our Paybill number. Full instructions (including your Account Number) are shown on the next page once you place the order.',
			),
			'number_type'     => array(
				'title'       => 'Number type',
				'type'        => 'select',
				'options'     => array(
					'paybill' => 'Paybill (needs an Account Number)',
					'till'    => 'Till / Buy Goods (no Account Number)',
				),
				'default'     => 'paybill',
				'description' => 'Paybill: customer enters your Business Number plus an Account Number (the order number, shown automatically) - this is what makes automatic confirmation possible below. Till/Buy Goods has no Account Number step in the M-Pesa menu, so a Till payment can\'t be automatically matched to an order; Till orders always need the manual "Confirm manual M-Pesa payment" action.',
			),
			'business_number' => array(
				'title'       => 'Paybill / Till Number',
				'type'        => 'text',
				'description' => 'Your real Safaricom Paybill or Till number. This is shown to the customer, so double check it.',
			),
			'instructions'    => array(
				'title'       => 'Extra instructions',
				'type'        => 'textarea',
				'default'     => 'After paying, please enter the M-Pesa confirmation code below (or WhatsApp/call us) so we can verify and confirm your order.',
				'description' => 'Shown under the Paybill/Till details on the order-received page and in the order emails.',
			),
			'c2b_title'       => array(
				'title'       => 'Automatic confirmation',
				'type'        => 'title',
				'description' => 'Optional. Paybill only (see "Number type" above) - lets Safaricom confirm payments to your paybill automatically, via the Daraja C2B API, so most orders never need the manual order action. Needs a Daraja app: get free sandbox keys at developer.safaricom.co.ke.',
			),
			'c2b_environment' => array(
				'title'       => 'Mode',
				'type'        => 'select',
				'options'     => array(
					'off'     => 'Off (manual confirmation only)',
					'sandbox' => 'Daraja Sandbox (real API, free test credentials)',
					'live'    => 'Daraja Production (real automatic confirmation - requires Go-Live approval)',
				),
				'default'     => 'off',
				'description' => 'Saving with this set to Sandbox or Live (and a Paybill number + Consumer Key/Secret filled in below) automatically registers your Confirmation/Validation URLs with Daraja - nothing to copy/paste by hand. If "Number type" is Till, this is ignored and reverted to Off, since Till payments can\'t be matched automatically.',
			),
			'c2b_live_confirm' => array(
				'title'       => 'Confirm Production Use',
				'type'        => 'checkbox',
				'label'       => 'I have Safaricom Go-Live approval and have double-checked the Consumer Key/Secret below are my PRODUCTION credentials, not sandbox ones.',
				'default'     => 'no',
				'description' => 'Required to actually switch Mode to "Daraja Production" for automatic confirmation. If left unticked, saving with Mode set to Production will be reverted back to Sandbox automatically.',
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
	 * Saves settings as normal, but (a) refuses to let "Mode: Production"
	 * persist unless the confirmation checkbox was ticked in the same save,
	 * exactly like WC_Gateway_Mpesa_STK does for the same reason, and (b)
	 * refuses to enable automatic confirmation at all when Number type is
	 * Till, since there's no account reference to match a Till payment to
	 * an order. Then, if automatic confirmation ends up enabled, registers
	 * the Confirmation/Validation URLs with Daraja right away.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		if ( 'till' === $this->get_option( 'number_type' ) && 'off' !== $this->get_option( 'c2b_environment' ) ) {
			$this->update_option( 'c2b_environment', 'off' );
			if ( class_exists( 'WC_Admin_Settings' ) ) {
				WC_Admin_Settings::add_error( 'M-Pesa Paybill (Manual): Automatic confirmation was turned back Off because Number type is Till/Buy Goods - Till payments have no Account Number for Safaricom to match to an order. Automatic confirmation only works with Number type = Paybill.' );
			}
		} elseif ( 'live' === $this->get_option( 'c2b_environment' ) && 'yes' !== $this->get_option( 'c2b_live_confirm' ) ) {
			$this->update_option( 'c2b_environment', 'sandbox' );
			if ( class_exists( 'WC_Admin_Settings' ) ) {
				WC_Admin_Settings::add_error( 'M-Pesa Paybill (Manual): Mode was reverted to "Daraja Sandbox" because "Confirm Production Use" wasn\'t ticked. Tick it and save again to actually go live.' );
			}
		}

		$this->c2b_environment = $this->get_option( 'c2b_environment' );
		$this->business_number = $this->get_option( 'business_number' );
		$this->consumer_key    = $this->get_option( 'consumer_key' );
		$this->consumer_secret = $this->get_option( 'consumer_secret' );

		if ( 'off' !== $this->c2b_environment && $this->business_number && $this->consumer_key && $this->consumer_secret ) {
			$this->register_c2b_urls();
		}

		return $saved;
	}

	/* ---------------- CHECKOUT / DISPLAY (unchanged from manual-only version) ---------------- */

	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}
		echo '<p class="form-row form-row-wide">';
		echo '<label for="fmke_manual_mpesa_code">M-Pesa confirmation code (optional, if you have already paid)</label>';
		echo '<input type="text" id="fmke_manual_mpesa_code" name="fmke_manual_mpesa_code" placeholder="e.g. QGH7XXXXX" style="text-transform:uppercase;" />';
		echo '</p>';
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$code = isset( $_POST['fmke_manual_mpesa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_manual_mpesa_code'] ) ) : '';

		$note = ( 'off' !== $this->c2b_environment && 'paybill' === $this->number_type )
			? 'Awaiting M-Pesa Paybill payment. Should confirm automatically once Safaricom notifies us; use the "Confirm manual M-Pesa payment" order action if it does not.'
			: 'Awaiting manual M-Pesa Paybill payment. Confirm against your M-Pesa statement, then use the "Confirm manual M-Pesa payment" order action.';
		$order->update_status( 'on-hold', $note );

		if ( $code ) {
			$this->save_customer_code( $order, $code, 'at checkout' );
		}

		$order->save();
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	private function save_customer_code( $order, $code, $when ) {
		$order->update_meta_data( '_fmke_manual_mpesa_code', $code );
		$order->save();
		$order->add_order_note( "Customer submitted M-Pesa confirmation code ({$when}): {$code}. This is NOT proof of payment on its own - check it against your M-Pesa statement, or wait for automatic confirmation if enabled." );
	}

	private function get_instructions_html( $order ) {
		if ( ! $this->business_number ) {
			return '<p><strong>This payment method is not fully configured yet - please contact us to complete your payment.</strong></p>';
		}

		$html  = '<h2>M-Pesa Paybill payment details</h2>';
		$html .= '<ul class="woocommerce-order-overview">';
		$html .= '<li>Go to M-Pesa &gt; Lipa na M-Pesa &gt; ' . ( 'till' === $this->number_type ? 'Buy Goods and Services' : 'Pay Bill' ) . '</li>';
		$html .= '<li>' . ( 'till' === $this->number_type ? 'Till Number' : 'Business Number' ) . ': <strong>' . esc_html( $this->business_number ) . '</strong></li>';

		if ( 'till' !== $this->number_type ) {
			$html .= '<li>Account Number: <strong>' . esc_html( $order->get_order_number() ) . '</strong> (please enter this exactly - it is how we match your payment to this order)</li>';
		}

		$html .= '<li>Amount: <strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></li>';
		$html .= '</ul>';

		if ( 'off' !== $this->c2b_environment && 'paybill' === $this->number_type ) {
			$html .= '<p>Your order should update automatically within a minute or two of paying.</p>';
		}

		if ( $this->instructions ) {
			$html .= wpautop( wp_kses_post( $this->instructions ) );
		}

		return $html;
	}

	/**
	 * Public wrapper around get_instructions_html(), so other code - e.g.
	 * the Pay-on-Delivery gateway showing a rider what to read out to the
	 * customer at the door - can reuse the exact same Paybill/Till
	 * instructions instead of duplicating them.
	 */
	public function get_instructions_html_public( $order ) {
		return $this->get_instructions_html( $order );
	}

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			echo wp_kses_post( $this->get_instructions_html( $order ) );
		}
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || $this->id !== $order->get_payment_method() || ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return;
		}
		echo wp_kses_post( $this->get_instructions_html( $order ) );
	}

	public function maybe_render_code_form( $order ) {
		if ( $this->id !== $order->get_payment_method() || ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		$existing = $order->get_meta( '_fmke_manual_mpesa_code' );

		echo '<h2>' . ( $existing ? 'Update' : 'Submit' ) . ' your M-Pesa confirmation code</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="fmke_submit_manual_mpesa_code" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';
		echo '<input type="hidden" name="key" value="' . esc_attr( $order->get_order_key() ) . '" />';
		wp_nonce_field( 'fmke_manual_mpesa_code_' . $order->get_id() );
		echo '<p class="form-row form-row-wide">';
		echo '<input type="text" name="fmke_manual_mpesa_code" style="text-transform:uppercase;" value="' . esc_attr( $existing ) . '" placeholder="e.g. QGH7XXXXX" />';
		echo '</p>';
		echo '<button type="submit">Submit code</button>';
		echo '</form>';
	}

	public static function handle_code_submission() {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );

		if ( ! $order || $order->get_order_key() !== ( $_POST['key'] ?? '' ) ) {
			wp_die( 'Invalid order.' );
		}
		check_admin_referer( 'fmke_manual_mpesa_code_' . $order_id );

		if ( 'mpesa_manual' === $order->get_payment_method() && $order->has_status( 'on-hold' ) && ! empty( $_POST['fmke_manual_mpesa_code'] ) ) {
			$gateway = new self();
			$code    = sanitize_text_field( wp_unslash( $_POST['fmke_manual_mpesa_code'] ) );
			$gateway->save_customer_code_public( $order, $code );
		}

		wp_safe_redirect( $order->get_view_order_url() );
		exit;
	}

	public function save_customer_code_public( $order, $code ) {
		$this->save_customer_code( $order, $code, 'after checkout' );
	}

	/* ---------------- MANUAL ADMIN CONFIRMATION (fallback, always available) ---------------- */

	public static function confirm_manual_payment( $order ) {
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			return; // Already confirmed, nothing to do.
		}
		$code = $order->get_meta( '_fmke_manual_mpesa_code' );
		$order->payment_complete( $code );
		$order->add_order_note( $code
			? "Manual M-Pesa Paybill payment confirmed by staff after verifying code {$code} on the M-Pesa statement."
			: 'Manual M-Pesa Paybill payment confirmed by staff after verifying the M-Pesa statement (no code was on file).' );
	}

	public static function render_admin_order_meta( $order ) {
		if ( ! $order || 'mpesa_manual' !== $order->get_payment_method() ) {
			return;
		}
		$code = $order->get_meta( '_fmke_manual_mpesa_code' );
		echo '<p><strong>M-Pesa confirmation code:</strong> ' . ( $code ? esc_html( $code ) : '<em>not submitted yet</em>' ) . '</p>';
	}

	/* ---------------- AUTOMATIC CONFIRMATION: DARAJA C2B ---------------- */

	/**
	 * Returns the correct Daraja host for the selected C2B environment.
	 * Same pattern as WC_Gateway_Mpesa_STK::api_base_url() - the only place
	 * that knows sandbox vs production, so cutover is a settings change.
	 */
	private function api_base_url() {
		return ( 'live' === $this->c2b_environment )
			? 'https://api.safaricom.co.ke'
			: 'https://sandbox.safaricom.co.ke';
	}

	private function get_access_token() {
		$response = wp_remote_get( $this->api_base_url() . '/oauth/v1/generate?grant_type=client_credentials', array(
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

	/**
	 * Secret token appended to both the Confirmation and Validation URLs.
	 * Daraja doesn't sign C2B callbacks either, so - same as the STK
	 * gateway's callback secret - this is what stops a stranger from
	 * guessing the URL and POSTing a fake "payment received" for an order.
	 */
	private function get_callback_secret() {
		$secret = get_option( 'fmke_mpesa_manual_callback_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 32, false, false );
			update_option( 'fmke_mpesa_manual_callback_secret', $secret );
		}
		return $secret;
	}

	private function c2b_url( $type ) {
		return add_query_arg(
			array( 'fmke_token' => $this->get_callback_secret(), 'fmke_c2b' => $type ),
			WC()->api_request_url( 'WC_Gateway_Mpesa_Manual' )
		);
	}

	/**
	 * Tells Daraja where to send Confirmation (payment already happened -
	 * we just record it) and Validation (Daraja asks first, before
	 * crediting - only actually invoked if your shortcode has external
	 * validation enabled with Safaricom; harmless to register either way,
	 * we just always accept). Called automatically from
	 * process_admin_options() whenever automatic confirmation is on and
	 * configured, so there's no URL to copy/paste by hand.
	 */
	public function register_c2b_urls() {
		$token = $this->get_access_token();
		if ( ! $token ) {
			if ( class_exists( 'WC_Admin_Settings' ) ) {
				WC_Admin_Settings::add_error( 'M-Pesa Paybill (Manual): could not reach Daraja to register the automatic-confirmation URLs - check your Consumer Key/Secret. Manual confirmation still works in the meantime.' );
			}
			return false;
		}

		$response = wp_remote_post( $this->api_base_url() . '/mpesa/c2b/v1/registerurl', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'ShortCode'       => $this->business_number,
				'ResponseType'    => 'Completed',
				'ConfirmationURL' => $this->c2b_url( 'confirmation' ),
				'ValidationURL'   => $this->c2b_url( 'validation' ),
			) ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'WC_Admin_Settings' ) ) {
				WC_Admin_Settings::add_error( 'M-Pesa Paybill (Manual): registering automatic-confirmation URLs with Daraja failed: ' . $response->get_error_message() );
			}
			return false;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $result['ResponseCode'] ) && '0' === (string) $result['ResponseCode'] ) {
			return true;
		}

		if ( class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error( 'M-Pesa Paybill (Manual): Daraja rejected the URL registration: ' . ( $result['errorMessage'] ?? wp_json_encode( $result ) ) . '. Manual confirmation still works in the meantime.' );
		}
		return false;
	}

	/**
	 * Entry point for both the Confirmation and Validation URLs:
	 * https://yourdomain.com/wc-api/wc_gateway_mpesa_manual?fmke_c2b=confirmation|validation
	 */
	public function handle_c2b_request() {
		$token = isset( $_GET['fmke_token'] ) ? sanitize_text_field( wp_unslash( $_GET['fmke_token'] ) ) : '';
		if ( ! hash_equals( $this->get_callback_secret(), $token ) ) {
			status_header( 403 );
			exit;
		}

		$raw  = file_get_contents( 'php://input' );
		$data = json_decode( $raw, true );

		if ( 'validation' === ( $_GET['fmke_c2b'] ?? '' ) ) {
			// We're not doing pre-credit rejection (e.g. blocking underpayments
			// before the money moves) - just accept, and let
			// handle_c2b_confirmation()'s amount check flag anything odd
			// afterwards for manual review instead.
			status_header( 200 );
			echo wp_json_encode( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ) );
			exit;
		}

		$this->handle_c2b_confirmation( $data ?: array() );
	}

	/**
	 * Matches an incoming C2B Confirmation to an order by the Account
	 * Number (BillRefNumber) the customer typed, and auto-completes it if
	 * the amount matches. Anything that can't be matched cleanly (no such
	 * order, amount mismatch, order not in a state expecting payment) is
	 * left for manual review instead of guessed at - see
	 * log_unmatched_payment().
	 *
	 * Confirmation must always be acknowledged with ResultCode 0: unlike
	 * the STK callback, the money has already moved by the time this
	 * fires, so there's nothing to "reject" here, only record.
	 */
	private function handle_c2b_confirmation( $data ) {
		$trans_id     = $data['TransID'] ?? '';
		$amount       = isset( $data['TransAmount'] ) ? (float) $data['TransAmount'] : 0;
		$bill_ref     = $data['BillRefNumber'] ?? '';
		$msisdn       = $data['MSISDN'] ?? '';
		$order_number = preg_replace( '/\D/', '', $bill_ref ); // Customers sometimes type "Order 1234", "#1234", etc.

		$order = $order_number ? wc_get_order( (int) $order_number ) : false;

		if ( ! $order || 'mpesa_manual' !== $order->get_payment_method() ) {
			$this->log_unmatched_payment( $trans_id, $amount, $bill_ref, $msisdn );
			$this->ack_c2b();
		}

		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			// Idempotency: Safaricom won't normally repeat a Confirmation, but
			// don't risk double-processing if it ever does.
			$this->ack_c2b();
		}

		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			// Cancelled/failed/refunded order, but a payment came in against
			// its number anyway (e.g. paid late after giving up, or a stale
			// account number reused). Don't silently revive it - flag it.
			$order->add_order_note( "M-Pesa Paybill payment of KES {$amount} received (TransID: {$trans_id}) but this order is '{$order->get_status()}', not awaiting payment. NOT auto-confirmed - please review and contact the customer if needed." );
			$this->log_unmatched_payment( $trans_id, $amount, $bill_ref, $msisdn, $order->get_id() );
			$this->ack_c2b();
		}

		$expected = (float) $order->get_total();
		if ( $amount + 0.01 < $expected ) {
			// Underpaid - don't auto-complete an order that wasn't fully paid for.
			$order->add_order_note( "M-Pesa Paybill payment received but UNDERPAID: KES {$amount} received vs KES {$expected} expected (TransID: {$trans_id}). NOT auto-confirmed - please review before confirming manually." );
			$this->log_unmatched_payment( $trans_id, $amount, $bill_ref, $msisdn, $order->get_id() );
			$this->ack_c2b();
		}

		$order->payment_complete( $trans_id );
		$order->add_order_note( "M-Pesa Paybill payment automatically confirmed via Daraja C2B. TransID: {$trans_id}, Amount: KES {$amount}, Paid from: {$msisdn}." );
		$this->ack_c2b();
	}

	private function ack_c2b() {
		status_header( 200 );
		echo wp_json_encode( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ) );
		exit;
	}

	/**
	 * Keeps a short rolling log (last 50) of C2B payments that came in but
	 * couldn't be auto-matched/confirmed, so the money isn't just lost
	 * track of. Reviewable at WooCommerce > M-Pesa Unmatched Payments.
	 */
	private function log_unmatched_payment( $trans_id, $amount, $bill_ref, $msisdn, $order_id = 0 ) {
		$log   = get_option( 'fmke_mpesa_c2b_unmatched', array() );
		$log[] = array(
			'time'     => current_time( 'mysql' ),
			'trans_id' => $trans_id,
			'amount'   => $amount,
			'bill_ref' => $bill_ref,
			'msisdn'   => $msisdn,
			'order_id' => $order_id,
		);
		$log = array_slice( $log, -50 );
		update_option( 'fmke_mpesa_c2b_unmatched', $log, false );
	}

	/**
	 * Renders the "WooCommerce > M-Pesa Unmatched Payments" admin page
	 * (registered from the main plugin file).
	 */
	public static function render_unmatched_payments_page() {
		if ( isset( $_GET['fmke_dismiss'] ) && check_admin_referer( 'fmke_dismiss_unmatched' ) ) {
			$log = get_option( 'fmke_mpesa_c2b_unmatched', array() );
			unset( $log[ absint( $_GET['fmke_dismiss'] ) ] );
			update_option( 'fmke_mpesa_c2b_unmatched', array_values( $log ), false );
		}

		$log = array_reverse( get_option( 'fmke_mpesa_c2b_unmatched', array() ), true );

		echo '<div class="wrap"><h1>M-Pesa Unmatched Payments</h1>';
		echo '<p>Paybill payments Safaricom confirmed but that could not be automatically applied to an order - typically a mistyped Account Number, an underpayment, or a payment against an order that was already cancelled/failed. Match these to the right order by hand, then dismiss.</p>';

		if ( empty( $log ) ) {
			echo '<p>Nothing here right now.</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Time</th><th>TransID</th><th>Amount (KES)</th><th>Account Number entered</th><th>Phone</th><th>Possible order</th><th></th></tr></thead><tbody>';
		foreach ( $log as $i => $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['time'] ) . '</td>';
			echo '<td>' . esc_html( $row['trans_id'] ) . '</td>';
			echo '<td>' . esc_html( $row['amount'] ) . '</td>';
			echo '<td>' . esc_html( $row['bill_ref'] ) . '</td>';
			echo '<td>' . esc_html( $row['msisdn'] ) . '</td>';
			echo '<td>' . ( $row['order_id'] ? '<a href="' . esc_url( admin_url( 'post.php?post=' . $row['order_id'] . '&action=edit' ) ) . '">#' . esc_html( $row['order_id'] ) . '</a>' : '<em>none matched</em>' ) . '</td>';
			$dismiss_url = wp_nonce_url( add_query_arg( 'fmke_dismiss', $i ), 'fmke_dismiss_unmatched' );
			echo '<td><a href="' . esc_url( $dismiss_url ) . '">Dismiss</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}
