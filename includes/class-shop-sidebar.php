<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a left-hand category sidebar on the shop and product category pages.
 *
 * Reuses WooCommerce's own WC_Widget_Product_Categories class (handles
 * counts, hierarchy, and "current category" highlighting correctly)
 * rather than re-querying terms manually. Positioned via CSS float swap,
 * so no theme template files need editing.
 */
class FMKE_Shop_Sidebar {

	public function __construct() {
		add_action( 'woocommerce_before_main_content', array( $this, 'render' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'print_accordion_script' ) );
	}

	private function is_shop_context() {
		return is_shop() || is_product_category() || is_product_tag();
	}

	public function render() {
		if ( ! $this->is_shop_context() ) {
			return;
		}

		echo '<aside class="fmke-shop-sidebar">';
		echo '<h3 class="fmke-shop-sidebar-title">Shop by Category</h3>';

		if ( class_exists( 'WC_Widget_Product_Categories' ) ) {
			the_widget( 'WC_Widget_Product_Categories', array(
				'title'              => '',
				'count'              => 1,
				'hierarchical'       => 1,
				'show_children_only' => 0,
				'dropdown'           => 0,
			) );
		}

		$this->render_price_filter();

		echo '</aside>';
	}

	/**
	 * Renders a min/max price filter. Plain number inputs (rather than
	 * WooCommerce's own WC_Widget_Price_Filter slider) so there's no
	 * jQuery UI slider dependency and it behaves well on mobile.
	 *
	 * Submits as a GET form to the current URL with min_price/max_price
	 * query args, which WooCommerce already reads and applies to the
	 * main shop/category query on its own (WC_Query::price_filter()),
	 * so no extra query-filtering code is needed here.
	 */
	private function render_price_filter() {
		$min_price = isset( $_GET['min_price'] ) ? wc_clean( wp_unslash( $_GET['min_price'] ) ) : '';
		$max_price = isset( $_GET['max_price'] ) ? wc_clean( wp_unslash( $_GET['max_price'] ) ) : '';
		$currency  = get_woocommerce_currency_symbol();

		echo '<div class="fmke-price-filter">';
		echo '<h3 class="fmke-shop-sidebar-title">Filter by Price</h3>';
		echo '<form class="fmke-price-filter-form" method="get">';

		// Preserve every existing query arg except the ones this form
		// controls, so category/search/sort context isn't lost and
		// pagination resets to page 1 on a new filter.
		foreach ( $_GET as $key => $value ) {
			if ( in_array( $key, array( 'min_price', 'max_price', 'paged' ), true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				continue;
			}
			printf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $key ),
				esc_attr( wp_unslash( $value ) )
			);
		}

		echo '<div class="fmke-price-filter-fields">';
		printf(
			'<label class="fmke-price-field"><span>%1$s</span><input type="number" min="0" step="1" name="min_price" placeholder="Min" value="%2$s" /></label>',
			esc_html( $currency ),
			esc_attr( $min_price )
		);
		printf(
			'<label class="fmke-price-field"><span>%1$s</span><input type="number" min="0" step="1" name="max_price" placeholder="Max" value="%2$s" /></label>',
			esc_html( $currency ),
			esc_attr( $max_price )
		);
		echo '</div>';

		echo '<button type="submit" class="fmke-price-filter-apply">Apply</button>';

		if ( '' !== $min_price || '' !== $max_price ) {
			$clear_url = remove_query_arg( array( 'min_price', 'max_price', 'paged' ) );
			printf( '<a class="fmke-price-filter-clear" href="%s">Clear price filter</a>', esc_url( $clear_url ) );
		}

		echo '</form>';
		echo '</div>';
	}

	public function enqueue() {
		if ( ! $this->is_shop_context() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	/**
	 * Prints the vanilla-JS accordion behaviour for category items that
	 * have children. Inserted in the footer (rather than enqueued as a
	 * separate .js asset) to keep this a single self-contained file, same
	 * approach already used for the CSS above.
	 *
	 * Relies on WordPress' own category walker output: any <li> with
	 * descendants gets a "cat-parent" class, and the ancestors of the
	 * term currently being viewed get "current-cat-parent" - both are
	 * core WP behaviour, not something this plugin adds.
	 */
	public function print_accordion_script() {
		if ( ! $this->is_shop_context() ) {
			return;
		}
		?>
		<script>
		( function () {
			document.addEventListener( 'DOMContentLoaded', function () {
				var sidebar = document.querySelector( '.fmke-shop-sidebar' );
				if ( ! sidebar ) {
					return;
				}

				var parents = sidebar.querySelectorAll( '.widget_product_categories li.cat-parent' );

				parents.forEach( function ( li ) {
					var link = li.querySelector( ':scope > a' );
					if ( ! link ) {
						return;
					}

					var btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'fmke-cat-toggle';
					btn.setAttribute( 'aria-expanded', 'false' );
					btn.setAttribute( 'aria-label', 'Toggle subcategories' );
					btn.innerHTML = '&#9662;';
					link.insertAdjacentElement( 'afterend', btn );

					// Auto-expand the branch that leads to whatever
					// category page the visitor is currently on.
					if ( li.classList.contains( 'current-cat' ) || li.classList.contains( 'current-cat-parent' ) ) {
						li.classList.add( 'fmke-cat-open' );
						btn.setAttribute( 'aria-expanded', 'true' );
					}

					btn.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						var open = li.classList.toggle( 'fmke-cat-open' );
						btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}

	private function get_inline_css() {
		return <<<CSS
/* Reflow shop/category pages: sidebar on the left, content on the right */
body.woocommerce #primary,
body.tax-product_cat #primary,
body.post-type-archive-product #primary {
	float: right;
	width: 73%;
}
/* Hide the theme's own default sidebar on these pages - our own
   category sidebar replaces it, avoiding a duplicate second sidebar. */
body.woocommerce #secondary,
body.tax-product_cat #secondary,
body.post-type-archive-product #secondary {
	display: none;
}

.fmke-shop-sidebar {
	float: left;
	width: 23%;
	font-family: 'General Sans', sans-serif;
}
.fmke-shop-sidebar-title {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 18px;
	font-weight: 600;
	color: #1F3D2C;
	margin: 0 0 14px;
}
.fmke-shop-sidebar .widget_product_categories ul {
	list-style: none;
	margin: 0;
	padding: 0;
}
.fmke-shop-sidebar .widget_product_categories li {
	border-bottom: 1px solid #f2f2f2;
}
.fmke-shop-sidebar .widget_product_categories li a {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 9px 4px;
	color: #1F3D2C;
	text-decoration: none;
	font-size: 14px;
}
.fmke-shop-sidebar .widget_product_categories li a:hover {
	color: #7A2048;
}
.fmke-shop-sidebar .widget_product_categories li.current-cat > a {
	color: #7A2048;
	font-weight: 500;
}
.fmke-shop-sidebar .widget_product_categories .count {
	background: #FBF7F0;
	color: #7A2048;
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
	margin-left: 6px;
}
.fmke-shop-sidebar .widget_product_categories ul.children {
	padding-left: 14px;
	margin-top: 4px;
}

/* Accordion: category items with children (.cat-parent, added by core
   WP's category walker) become a flex row so the toggle arrow sits next
   to the link, with the children list wrapping onto its own full-width
   row that's hidden until opened. */
.fmke-shop-sidebar .widget_product_categories li.cat-parent {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
}
.fmke-shop-sidebar .widget_product_categories li.cat-parent > a {
	flex: 1 1 auto;
	min-width: 0;
}
.fmke-shop-sidebar .widget_product_categories li.cat-parent > ul.children {
	order: 3;
	flex: 1 0 100%;
	display: none;
}
.fmke-shop-sidebar .widget_product_categories li.cat-parent.fmke-cat-open > ul.children {
	display: block;
}
.fmke-shop-sidebar .widget_product_categories .fmke-cat-toggle {
	order: 2;
	flex: 0 0 auto;
	background: none;
	border: none;
	cursor: pointer;
	padding: 8px 4px 8px 8px;
	color: #7A2048;
	font-size: 12px;
	line-height: 1;
	transition: transform 0.2s ease;
}
.fmke-shop-sidebar .widget_product_categories li.cat-parent.fmke-cat-open > .fmke-cat-toggle {
	transform: rotate(180deg);
}

.fmke-price-filter {
	margin-top: 28px;
	padding-top: 20px;
	border-top: 1px solid #f2f2f2;
}
.fmke-price-filter-fields {
	display: flex;
	gap: 10px;
	margin-bottom: 12px;
}
.fmke-price-field {
	flex: 1 1 0;
	display: flex;
	align-items: center;
	border: 1px solid #e2ded4;
	border-radius: 6px;
	padding: 0 8px;
	background: #fff;
}
.fmke-price-field span {
	color: #7A2048;
	font-size: 13px;
	margin-right: 4px;
}
.fmke-price-field input[type="number"] {
	width: 100%;
	border: none;
	padding: 8px 0;
	font-size: 14px;
	font-family: 'General Sans', sans-serif;
	color: #1F3D2C;
	background: transparent;
}
.fmke-price-field input[type="number"]:focus {
	outline: none;
}
.fmke-price-filter-apply {
	width: 100%;
	background: #7A2048;
	color: #fff;
	border: none;
	border-radius: 6px;
	padding: 10px;
	font-size: 14px;
	font-weight: 500;
	font-family: 'General Sans', sans-serif;
	cursor: pointer;
	transition: background 0.2s ease;
}
.fmke-price-filter-apply:hover {
	background: #5e1636;
}
.fmke-price-filter-clear {
	display: block;
	text-align: center;
	margin-top: 10px;
	font-size: 13px;
	color: #7A2048;
	text-decoration: underline;
}

@media (max-width: 768px) {
	body.woocommerce #primary,
	body.tax-product_cat #primary,
	body.post-type-archive-product #primary {
		float: none;
		width: 100%;
	}
	.fmke-shop-sidebar {
		float: none;
		width: 100%;
		margin: 0 0 24px;
	}
}
CSS;
	}
}

new FMKE_Shop_Sidebar();
