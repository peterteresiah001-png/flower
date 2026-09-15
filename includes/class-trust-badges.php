<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Pay safely" trust bar: the accepted payment methods (checked against
 * WooCommerce's actually-enabled gateways, same approach as
 * class-service-badges.php and class-site-footer.php) plus a row of
 * static security reassurances (SSL, secure checkout, buyer protection).
 *
 * Unlike the footer's "We Accept" list, this is meant to sit right where
 * a shopper is deciding whether to trust the site with money - the
 * checkout page (above the order form) and the single product page
 * (just under Add to Cart) - since that's where trust badges actually
 * move the needle on conversion.
 *
 * The security row (SSL, secure checkout, buyer protection) is printed
 * on checkout only, where the reassurance is doing the most work. The
 * product page gets the payment-method row alone, so the bar stays a
 * single compact line under Add to Cart.
 *
 * Prints nothing at all - on either page - when there is nothing left
 * to show, so it never implies you can pay when you can't and never
 * leaves an empty bordered box behind.
 */
class FMKE_Trust_Badges {

	/**
	 * Security badges are store-wide claims, not tied to any gateway,
	 * so - unlike the payment badges - they're always shown together.
	 */
	const SECURITY_BADGES = array(
		'ssl' => array(
			'label' => 'SSL Secured',
			'icon'  => '🔒',
		),
		'secure-checkout' => array(
			'label' => 'Secure Checkout',
			'icon'  => '🔐',
		),
		'buyer-protection' => array(
			'label' => 'Buyer Protection',
			'icon'  => '🛡️',
		),
	);

	public function __construct() {
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout' ), 5 );
		// 36, not 35: the wishlist heart also hooks this at 35, and
		// relying on include order to break the tie makes the layout
		// depend on the order of the require_once calls in the
		// bootstrap file. Explicit priority keeps the bar below it.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_product' ), 36 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function render_checkout() {
		if ( ! is_checkout() ) {
			return;
		}
		echo $this->render( true );
	}

	public function render_product() {
		if ( ! is_product() ) {
			return;
		}
		echo $this->render( false );
	}

	/**
	 * @param bool $with_security Whether to also print the security row.
	 *                            True on checkout (where the reassurance
	 *                            matters most), false on the product
	 *                            page so the bar stays one short line.
	 */
	private function render( $with_security = true ) {
		$payment_badges = $this->get_payment_badges();

		// No enabled gateway means nothing honest to show, so print
		// nothing at all: on checkout the bar would imply you can pay
		// when you can't, and on the product page (security row off)
		// it would leave an empty bordered box behind.
		if ( empty( $payment_badges ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="fmke-trust-badges">
			<?php if ( ! empty( $payment_badges ) ) : ?>
				<div class="fmke-trust-group fmke-trust-payments">
					<?php foreach ( $payment_badges as $slug => $badge ) : ?>
						<span class="fmke-trust-badge fmke-trust-badge-<?php echo esc_attr( $slug ); ?>">
							<span class="fmke-trust-badge-icon" aria-hidden="true"><?php echo esc_html( $badge['icon'] ); ?></span><?php echo esc_html( $badge['label'] ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $with_security ) : ?>
				<div class="fmke-trust-group fmke-trust-security">
					<?php foreach ( self::SECURITY_BADGES as $slug => $badge ) : ?>
						<span class="fmke-trust-badge fmke-trust-badge-<?php echo esc_attr( $slug ); ?>">
							<span class="fmke-trust-badge-icon" aria-hidden="true"><?php echo esc_html( $badge['icon'] ); ?></span><?php echo esc_html( $badge['label'] ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Same "only advertise what's actually switched on" rule as
	 * FMKE_Site_Footer::payment_badges() - checked independently here
	 * since this can render on pages that hook fires without the footer
	 * ever having run.
	 *
	 * @return array Keyed by slug, same shape as SECURITY_BADGES.
	 */
	private function get_payment_badges() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return array();
		}

		$available = WC()->payment_gateways->get_available_payment_gateways();
		$badges    = array();

		if ( isset( $available['mpesa_stk'] ) ) {
			$badges['mpesa'] = array(
				'label' => 'M-Pesa',
				'icon'  => '📱',
			);
		}
		if ( isset( $available['fmke_hosted_checkout'] ) ) {
			$badges['card'] = array(
				'label' => 'Visa / Mastercard / Bank',
				'icon'  => '💳',
			);
		}

		return $badges;
	}

	public function enqueue() {
		if ( ! is_checkout() && ! is_product() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-trust-badges {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 20px;
	margin: 0 0 20px;
	padding: 12px 16px;
	background: #F7F5F0;
	border: 1px solid #E4DFD3;
	border-radius: 8px;
}
.fmke-trust-group {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
.fmke-trust-payments + .fmke-trust-security {
	padding-left: 20px;
	border-left: 1px solid #E4DFD3;
}
.fmke-trust-badge {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 4px 10px;
	border-radius: 999px;
	font-size: 12px;
	font-weight: 600;
	line-height: 1.4;
	color: #1F3D2C;
	background: #fff;
	border: 1px solid #E4DFD3;
	white-space: nowrap;
}
.fmke-trust-badge-icon {
	font-size: 12px;
	line-height: 1;
}
.fmke-trust-badge-mpesa   { border-color: rgba(0, 121, 63, 0.4); }
.fmke-trust-badge-card    { border-color: rgba(31, 61, 44, 0.4); }
.fmke-trust-badge-ssl,
.fmke-trust-badge-secure-checkout,
.fmke-trust-badge-buyer-protection {
	color: #7A2048;
	border-color: rgba(122, 32, 72, 0.3);
}

@media (max-width: 480px) {
	.fmke-trust-badges {
		gap: 10px;
	}
	.fmke-trust-payments + .fmke-trust-security {
		padding-left: 0;
		border-left: none;
	}
	.fmke-trust-badge {
		font-size: 11px;
		padding: 3px 8px;
	}
}
CSS;
	}
}

new FMKE_Trust_Badges();
