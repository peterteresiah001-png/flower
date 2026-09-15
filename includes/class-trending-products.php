<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Trending Products" section - unlike Best Sellers (all-time total
 * sales), this looks only at orders placed in the last
 * DEFAULT_WINDOW_DAYS days, so it surfaces what's hot *right now*
 * rather than old favourites that sold well once, long ago.
 *
 * Renders in two ways, same pattern as the other homepage sections:
 *   1. Automatically, on the front page, just below Best Sellers
 *      (same 'storefront_before_content' hook, lower priority so it
 *      lands after it).
 *   2. Via the [fmke_trending_products] shortcode, for use anywhere
 *      else. Accepts "count" and "days" attributes, e.g.
 *      [fmke_trending_products count="4" days="7"].
 *
 * Uses WooCommerce's own product-card template so cards look identical
 * to the regular shop grid. If there's no recent order activity (e.g. a
 * brand-new store), this section simply doesn't render anything.
 */
class FMKE_Trending_Products {

	/** Default number of products to show. */
	const DEFAULT_COUNT = 8;

	/** Default size of the "recent" window, in days. */
	const DEFAULT_WINDOW_DAYS = 14;

	public function __construct() {
		add_shortcode( 'fmke_trending_products', array( $this, 'shortcode' ) );
		// Priority 30: after FMKE_Best_Sellers (25), so this lands below it.
		add_action( 'storefront_before_content', array( $this, 'auto_render' ), 30 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function auto_render() {
		if ( ! is_front_page() ) {
			return;
		}
		echo $this->render_grid( self::DEFAULT_COUNT, self::DEFAULT_WINDOW_DAYS );
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'count' => self::DEFAULT_COUNT,
			'days'  => self::DEFAULT_WINDOW_DAYS,
		), $atts, 'fmke_trending_products' );

		return $this->render_grid( (int) $atts['count'], (int) $atts['days'] );
	}

	/**
	 * Builds the HTML, or an empty string if nothing has sold within
	 * the window - so this never prints a broken/empty heading.
	 *
	 * @param int $count Max number of products to show.
	 * @param int $days  Size of the "recent" window, in days.
	 */
	private function render_grid( $count, $days ) {
		$count = $count > 0 ? $count : self::DEFAULT_COUNT;
		$days  = $days > 0 ? $days : self::DEFAULT_WINDOW_DAYS;

		$product_ids = $this->get_trending_product_ids( $count, $days );

		if ( empty( $product_ids ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="fmke-trending-products">
			<h2 class="fmke-trending-products-title">📈 Trending Now</h2>
			<ul class="products columns-4">
				<?php
				foreach ( $product_ids as $product_id ) {
					$post_object = get_post( $product_id );
					if ( ! $post_object ) {
						continue;
					}
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

	/**
	 * Tallies quantities sold per product across orders placed within
	 * the last $days days, and returns the top $count product IDs by
	 * that recent quantity, highest first.
	 */
	private function get_trending_product_ids( $count, $days ) {
		$orders = wc_get_orders( array(
			'status'       => array( 'wc-processing', 'wc-completed' ),
			'date_created' => '>' . ( time() - ( $days * DAY_IN_SECONDS ) ),
			'limit'        => -1,
			'return'       => 'objects',
		) );

		if ( empty( $orders ) ) {
			return array();
		}

		$tally = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$product_id = $item->get_product_id();
				if ( ! $product_id ) {
					continue;
				}
				if ( ! isset( $tally[ $product_id ] ) ) {
					$tally[ $product_id ] = 0;
				}
				$tally[ $product_id ] += $item->get_quantity();
			}
		}

		if ( empty( $tally ) ) {
			return array();
		}

		arsort( $tally ); // Highest recent quantity first.

		$product_ids = array_keys( $tally );

		// Only keep products that are still published.
		$product_ids = array_filter( $product_ids, function ( $id ) {
			return 'publish' === get_post_status( $id );
		} );

		return array_slice( $product_ids, 0, $count );
	}

	public function enqueue() {
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-trending-products {
	margin: 32px 0;
}
.fmke-trending-products-title {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 24px;
	font-weight: 600;
	color: #1F3D2C;
	margin: 0 0 16px;
}
CSS;
	}
}

new FMKE_Trending_Products();
