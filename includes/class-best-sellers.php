<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Best Sellers" section - a small grid of the marketplace's
 * best-selling products, using WooCommerce's own "popularity" ordering
 * (total sales count) and its native product-card template, so cards
 * look identical to the regular shop grid and automatically pick up
 * price, sale badges, add-to-cart, etc.
 *
 * Renders in two ways, same pattern as the other homepage sections:
 *   1. Automatically, on the front page, just below Featured Vendors
 *      (same 'storefront_before_content' hook, lower priority so it
 *      lands after it).
 *   2. Via the [fmke_best_sellers] shortcode, for use anywhere else.
 *      Accepts a "count" attribute, e.g. [fmke_best_sellers count="4"].
 *
 * Products with zero sales are left out entirely, so a brand-new store
 * with no orders yet just doesn't show this section rather than
 * displaying an arbitrary/misleading "best sellers" row.
 */
class FMKE_Best_Sellers {

	/** Default number of products to show. */
	const DEFAULT_COUNT = 8;

	public function __construct() {
		add_shortcode( 'fmke_best_sellers', array( $this, 'shortcode' ) );
		// Priority 25: after FMKE_Featured_Vendors (20), so this lands below it.
		add_action( 'storefront_before_content', array( $this, 'auto_render' ), 25 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function auto_render() {
		if ( ! is_front_page() ) {
			return;
		}
		echo $this->render_grid( self::DEFAULT_COUNT );
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'count' => self::DEFAULT_COUNT,
		), $atts, 'fmke_best_sellers' );

		return $this->render_grid( (int) $atts['count'] );
	}

	/**
	 * Builds the HTML, or an empty string if there are no products with
	 * any sales yet - so this never prints a broken/empty heading.
	 *
	 * @param int $count Max number of products to show.
	 */
	private function render_grid( $count ) {
		$count = $count > 0 ? $count : self::DEFAULT_COUNT;

		$query = new WC_Product_Query( array(
			'status'   => 'publish',
			'limit'    => $count,
			'orderby'  => 'popularity', // WooCommerce's own total_sales ordering.
			'order'    => 'DESC',
			'meta_query' => array( // phpcs:ignore -- excludes products with no sales yet.
				array(
					'key'     => 'total_sales',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
		) );

		$products = $query->get_products();

		if ( empty( $products ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="fmke-best-sellers">
			<h2 class="fmke-best-sellers-title">Best Sellers</h2>
			<ul class="products columns-4">
				<?php
				foreach ( $products as $product ) {
					$post_object = get_post( $product->get_id() );
					setup_postdata( $GLOBALS['post'] =& $post_object ); // phpcs:ignore -- standard WC loop pattern.
					wc_get_template_part( 'content', 'product' );
				}
				wp_reset_postdata();
				?>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	public function enqueue() {
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-best-sellers {
	margin: 32px 0;
}
.fmke-best-sellers-title {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 24px;
	font-weight: 600;
	color: #1F3D2C;
	margin: 0 0 16px;
}
CSS;
	}
}

new FMKE_Best_Sellers();
