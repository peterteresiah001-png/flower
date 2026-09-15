<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small "service highlight" badges (e.g. Same-Day Delivery, M-Pesa Accepted,
 * Verified Vendor, Fresh Today) shown on product cards in the shop/category
 * grid - but only when they actually apply to that product.
 *
 * Trigger method: WooCommerce product tags or categories. A shop owner or
 * vendor just adds one of the recognised tag slugs (see self::BADGES below)
 * to a product, the same way they'd add any other tag - no new admin
 * screens or custom fields required.
 *
 * Exception: the "M-Pesa Accepted" badge is store-wide rather than
 * tag-driven, because M-Pesa is a checkout payment method, not a
 * per-product attribute - it's "applicable" whenever the M-Pesa STK
 * gateway (includes/class-wc-gateway-mpesa-stk.php) is enabled in
 * WooCommerce > Settings > Payments, and applies to every purchasable
 * product at that point. A product can still opt out with the
 * 'no-mpesa' tag (e.g. a rental item invoiced separately).
 *
 * "Fresh Today" additionally requires the product to actually be in
 * stock, so a stale tag left on a sold-out product doesn't keep showing.
 */
class FMKE_Service_Badges {

	/**
	 * Badge definitions. 'match_slugs' are checked against both the
	 * product's tags and its categories (slug form), so a shop can use
	 * whichever taxonomy fits their catalogue.
	 */
	const BADGES = array(
		'same-day' => array(
			'label'       => 'Same-Day Delivery',
			'icon'        => '⚡',
			'match_slugs' => array( 'same-day-delivery', 'express-delivery', 'express' ),
		),
		'mpesa' => array(
			'label' => 'M-Pesa Accepted',
			'icon'  => '📱',
			// No match_slugs - handled separately via mpesa_is_applicable().
		),
		'verified-vendor' => array(
			'label'       => 'Verified Vendor',
			'icon'        => '✓',
			'match_slugs' => array( 'verified-vendor', 'trusted-vendor' ),
		),
		'fresh-today' => array(
			'label'       => 'Fresh Today',
			'icon'        => '🌸',
			'match_slugs' => array( 'fresh-today', 'fresh' ),
			'requires_stock' => true,
		),
	);

	public function __construct() {
		// Priority 9 = just before WooCommerce's own thumbnail (priority
		// 10 on the same hook), so badges sit in the DOM before the
		// product image and can be pinned over its top-left corner with CSS.
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'render_badges' ), 9 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Echoes the badge markup for the current loop product, or nothing
	 * if no badges apply - so it never prints an empty wrapper.
	 */
	public function render_badges() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$badges = $this->get_applicable_badges( $product );
		if ( empty( $badges ) ) {
			return;
		}

		echo '<div class="fmke-badges">';
		foreach ( $badges as $slug => $badge ) {
			printf(
				'<span class="fmke-badge fmke-badge-%1$s"><span class="fmke-badge-icon" aria-hidden="true">%2$s</span>%3$s</span>',
				esc_attr( $slug ),
				esc_html( $badge['icon'] ),
				esc_html( $badge['label'] )
			);
		}
		echo '</div>';
	}

	/**
	 * Works out which badges apply to a given product right now.
	 *
	 * @param WC_Product $product
	 * @return array Subset of self::BADGES that applies, keyed the same way.
	 */
	private function get_applicable_badges( $product ) {
		$terms = array_merge(
			wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'slugs' ) ),
			wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) )
		);

		$found = array();

		foreach ( self::BADGES as $slug => $badge ) {

			if ( 'mpesa' === $slug ) {
				if ( $this->mpesa_is_applicable( $terms ) ) {
					$found[ $slug ] = $badge;
				}
				continue;
			}

			if ( ! empty( $badge['requires_stock'] ) && ! $product->is_in_stock() ) {
				continue;
			}

			if ( array_intersect( $badge['match_slugs'], $terms ) ) {
				$found[ $slug ] = $badge;
			}
		}

		return $found;
	}

	/**
	 * M-Pesa is "applicable" when the STK gateway is switched on in
	 * WooCommerce > Settings > Payments, unless this specific product
	 * opts out with a 'no-mpesa' tag/category.
	 */
	private function mpesa_is_applicable( $terms ) {
		if ( in_array( 'no-mpesa', $terms, true ) ) {
			return false;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return false;
		}
		$available = WC()->payment_gateways->get_available_payment_gateways();
		return isset( $available['mpesa_stk'] );
	}

	public function enqueue() {
		if ( ! is_shop() && ! is_product_category() && ! is_product_tag() && ! is_product_taxonomy() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
ul.products li.product {
	position: relative;
}
.fmke-badges {
	position: absolute;
	top: 8px;
	left: 8px;
	right: 8px;
	z-index: 2;
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	pointer-events: none;
}
.fmke-badge {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	padding: 3px 8px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 600;
	line-height: 1.4;
	color: #fff;
	background: rgba(31, 61, 44, 0.9);
	box-shadow: 0 1px 3px rgba(0,0,0,0.2);
	white-space: nowrap;
}
.fmke-badge-icon {
	font-size: 11px;
	line-height: 1;
}
.fmke-badge-same-day     { background: rgba(122, 32, 72, 0.92); }
.fmke-badge-mpesa        { background: rgba(0, 121, 63, 0.92); }
.fmke-badge-verified-vendor { background: rgba(31, 61, 44, 0.92); }
.fmke-badge-fresh-today  { background: rgba(176, 138, 62, 0.92); }

@media (max-width: 480px) {
	.fmke-badge {
		font-size: 10px;
		padding: 2px 6px;
	}
}
CSS;
	}
}

new FMKE_Service_Badges();
