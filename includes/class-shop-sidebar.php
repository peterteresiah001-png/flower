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

		echo '</aside>';
	}

	public function enqueue() {
		if ( ! $this->is_shop_context() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
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
