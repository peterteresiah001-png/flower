<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flash Sales.
 *
 * Deliberately built on top of WooCommerce's own native sale-price
 * scheduling (Product > General tab > Sale price > "Schedule") rather
 * than a new custom field, so vendors/admins use a screen they already
 * know - no new meta boxes to maintain.
 *
 * A product counts as a "flash sale" when it's on sale AND its
 * scheduled sale end date/time is within FLASH_WINDOW_HOURS from now
 * (default 72h). A sale price with no end date, or one that ends
 * further out than that, is treated as a normal ongoing sale and is
 * left alone - this class only lights up for genuinely short, urgent
 * windows.
 *
 * What it adds:
 *   1. A "🔥 Flash Sale - ends in ..." countdown badge on shop/category
 *      product cards (top-right, so it doesn't collide with the
 *      service badges added in class-service-badges.php, which sit
 *      top-left).
 *   2. The same countdown banner above the title on the single product
 *      page.
 *   3. A [fmke_flash_sales] shortcode that lists every currently active
 *      flash-sale product in a small grid, for use on the homepage or
 *      anywhere else.
 */
class FMKE_Flash_Sales {

	/**
	 * How close to its scheduled end a sale has to be to count as
	 * "flash" rather than just an ordinary sale. Filterable.
	 */
	const DEFAULT_WINDOW_HOURS = 72;

	public function __construct() {
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'render_grid_badge' ), 9 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_banner' ), 4 );
		add_shortcode( 'fmke_flash_sales', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Returns the sale's end timestamp (as a WP-timezone Unix
	 * timestamp) if $product currently qualifies as a flash sale,
	 * or 0 if it doesn't.
	 */
	private function get_flash_sale_end( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
			return 0;
		}

		$end_raw = $product->get_date_on_sale_to();
		if ( ! $end_raw ) {
			return 0; // No scheduled end = ongoing sale, not a "flash" one.
		}

		$end_timestamp = $end_raw instanceof WC_DateTime ? $end_raw->getTimestamp() : strtotime( $end_raw );
		$now           = current_time( 'timestamp' );

		if ( $end_timestamp <= $now ) {
			return 0; // Already ended (WooCommerce clears the sale price around this too, but belt-and-braces).
		}

		$window_hours  = (int) apply_filters( 'fmke_flash_sale_window_hours', self::DEFAULT_WINDOW_HOURS );
		$window_end    = $now + ( $window_hours * HOUR_IN_SECONDS );

		if ( $end_timestamp > $window_end ) {
			return 0; // Ends too far in the future to count as "flash".
		}

		return $end_timestamp;
	}

	public function render_grid_badge() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}

		$end = $this->get_flash_sale_end( $product );
		if ( ! $end ) {
			return;
		}

		printf(
			'<div class="fmke-flash-badge" data-fmke-countdown="%1$d">🔥 Flash Sale &middot; <span class="fmke-flash-time"></span></div>',
			esc_attr( $end * 1000 ) // JS Date works in milliseconds.
		);
	}

	public function render_single_banner() {
		global $product;

		$end = $this->get_flash_sale_end( $product );
		if ( ! $end ) {
			return;
		}

		printf(
			'<div class="fmke-flash-banner" data-fmke-countdown="%1$d">🔥 Flash Sale &mdash; ends in <span class="fmke-flash-time"></span></div>',
			esc_attr( $end * 1000 )
		);
	}

	/**
	 * [fmke_flash_sales] - lists every product currently on a flash
	 * sale, using WooCommerce's own product card template so it looks
	 * identical to the shop grid (and still gets the badge above).
	 */
	public function shortcode() {
		$query = new WC_Product_Query( array(
			'status'  => 'publish',
			'limit'   => -1,
			'orderby' => 'date',
		) );
		$products = $query->get_products();

		$flash_products = array();
		foreach ( $products as $product ) {
			if ( $this->get_flash_sale_end( $product ) ) {
				$flash_products[] = $product;
			}
		}

		if ( empty( $flash_products ) ) {
			return '';
		}

		ob_start();
		echo '<div class="fmke-flash-sales-section">';
		echo '<h2 class="fmke-flash-sales-heading">🔥 Flash Sales</h2>';
		echo '<ul class="products columns-4">';
		foreach ( $flash_products as $product ) {
			$post_object = get_post( $product->get_id() );
			setup_postdata( $GLOBALS['post'] =& $post_object ); // phpcs:ignore -- standard WC loop pattern.
			wc_get_template_part( 'content', 'product' );
		}
		wp_reset_postdata();
		echo '</ul>';
		echo '</div>';
		return ob_get_clean();
	}

	public function enqueue() {
		// Cheap global check - countdown elements only render inside the
		// hooks above, so this just needs to run wherever those could fire.
		wp_register_script( 'fmke-flash-sales', false, array(), '1.0.0', true );
		wp_enqueue_script( 'fmke-flash-sales' );
		wp_add_inline_script( 'fmke-flash-sales', $this->get_inline_js() );
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_js() {
		return <<<JS
(function () {
	function format( ms ) {
		if ( ms <= 0 ) return 'Sale ended';
		var s = Math.floor( ms / 1000 );
		var d = Math.floor( s / 86400 ); s -= d * 86400;
		var h = Math.floor( s / 3600 );  s -= h * 3600;
		var m = Math.floor( s / 60 );    s -= m * 60;
		if ( d > 0 ) return d + 'd ' + h + 'h left';
		if ( h > 0 ) return h + 'h ' + m + 'm left';
		return m + 'm ' + s + 's left';
	}
	function tick() {
		document.querySelectorAll( '[data-fmke-countdown]' ).forEach( function ( el ) {
			var end = parseInt( el.getAttribute( 'data-fmke-countdown' ), 10 );
			var span = el.querySelector( '.fmke-flash-time' );
			if ( ! span ) return;
			var remaining = end - Date.now();
			span.textContent = format( remaining );
			if ( remaining <= 0 ) {
				el.classList.add( 'fmke-flash-ended' );
			}
		} );
	}
	document.addEventListener( 'DOMContentLoaded', function () {
		tick();
		setInterval( tick, 1000 );
	} );
})();
JS;
	}

	private function get_inline_css() {
		return <<<CSS
ul.products li.product {
	position: relative;
}
.fmke-flash-badge {
	position: absolute;
	top: 8px;
	right: 8px;
	z-index: 2;
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 3px 8px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 700;
	line-height: 1.4;
	color: #fff;
	background: rgba(200, 40, 40, 0.92);
	box-shadow: 0 1px 3px rgba(0,0,0,0.2);
	white-space: nowrap;
	pointer-events: none;
}
.fmke-flash-banner {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 10px;
	padding: 6px 14px;
	border-radius: 4px;
	font-size: 14px;
	font-weight: 700;
	color: #fff;
	background: rgba(200, 40, 40, 0.95);
}
.fmke-flash-badge.fmke-flash-ended,
.fmke-flash-banner.fmke-flash-ended {
	background: rgba(120, 120, 120, 0.85);
}
.fmke-flash-sales-section {
	margin: 32px 0;
}
.fmke-flash-sales-heading {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 24px;
	margin-bottom: 16px;
}
CSS;
	}
}

new FMKE_Flash_Sales();
