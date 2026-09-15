<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A horizontally-scrollable row of small "shortcut" cards at the very
 * top of the shop and category pages, for quickly jumping to a
 * sibling category without scrolling to (or on mobile, opening) the
 * left-hand sidebar added by class-shop-sidebar.php.
 *
 * Context-aware:
 *   - On the main shop page: shows the top-level categories, plus a
 *     leading "All" card that's marked active (since nothing is
 *     filtered yet).
 *   - On a category page: shows that category's siblings (i.e. its
 *     parent's children - or the top-level categories if it's already
 *     top-level), plus a leading "All" card linking back to the shop,
 *     with the current category's own card marked active.
 *
 * Deliberately separate from class-top-categories.php: that's a
 * homepage discovery grid ordered by product count; this is on-page,
 * always-in-context navigation ordered the same way the category tree
 * itself is (menu_order, matching how WooCommerce sorts categories
 * everywhere else), so the set of cards doesn't jump around as a
 * shopper moves between categories.
 */
class FMKE_Category_Shortcuts {

	public function __construct() {
		// Priority 5: before WooCommerce's own result-count (20) and
		// ordering (30) callbacks on the same hook, so the shortcuts sit
		// right at the top of the content area, above the sort/count bar.
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	private function is_shop_context() {
		return is_shop() || is_product_category();
	}

	public function render() {
		if ( ! $this->is_shop_context() ) {
			return;
		}

		$current_term_id = 0;
		$parent_id       = 0;

		if ( is_product_category() ) {
			$current = get_queried_object();
			if ( $current instanceof WP_Term ) {
				$current_term_id = $current->term_id;
				$parent_id       = $current->parent;
			}
		}

		$siblings = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'parent'     => $parent_id,
			'orderby'    => 'menu_order',
			'order'      => 'ASC',
			'exclude'    => array( get_option( 'default_product_cat', 0 ) ), // Leave out "Uncategorised".
		) );

		if ( empty( $siblings ) || is_wp_error( $siblings ) ) {
			return;
		}

		$shop_url = get_permalink( wc_get_page_id( 'shop' ) );

		echo '<div class="fmke-cat-shortcuts">';

		printf(
			'<a class="fmke-cat-shortcut%1$s" href="%2$s"><span class="fmke-cat-shortcut-icon" aria-hidden="true">%3$s</span><span class="fmke-cat-shortcut-label">%4$s</span></a>',
			0 === $current_term_id ? ' fmke-cat-shortcut-active' : '',
			esc_url( $shop_url ),
			'✿',
			esc_html__( 'All', 'fmke' )
		);

		foreach ( $siblings as $term ) {
			$image = $this->get_category_image( $term->term_id );

			printf(
				'<a class="fmke-cat-shortcut%1$s" href="%2$s">',
				$term->term_id === $current_term_id ? ' fmke-cat-shortcut-active' : '',
				esc_url( get_term_link( $term ) )
			);

			if ( $image ) {
				printf(
					'<span class="fmke-cat-shortcut-icon fmke-cat-shortcut-icon-image" style="background-image:url(\'%1$s\');" aria-hidden="true"></span>',
					esc_url( $image )
				);
			} else {
				printf(
					'<span class="fmke-cat-shortcut-icon" aria-hidden="true">%1$s</span>',
					esc_html( mb_substr( $term->name, 0, 1 ) )
				);
			}

			printf(
				'<span class="fmke-cat-shortcut-label">%1$s</span></a>',
				esc_html( $term->name )
			);
		}

		echo '</div>';
	}

	private function get_category_image( $term_id ) {
		$thumbnail_id = get_term_meta( $term_id, 'thumbnail_id', true );
		if ( ! $thumbnail_id ) {
			return '';
		}
		$image = wp_get_attachment_image_url( $thumbnail_id, 'thumbnail' );
		return $image ? $image : '';
	}

	public function enqueue() {
		if ( ! $this->is_shop_context() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-cat-shortcuts {
	display: flex;
	gap: 10px;
	margin: 0 0 20px;
	padding: 4px 2px 10px;
	overflow-x: auto;
	-webkit-overflow-scrolling: touch;
	scrollbar-width: thin;
	font-family: 'General Sans', sans-serif;
}
.fmke-cat-shortcuts::-webkit-scrollbar {
	height: 5px;
}
.fmke-cat-shortcuts::-webkit-scrollbar-thumb {
	background: #e6e0d6;
	border-radius: 999px;
}
.fmke-cat-shortcut {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 14px 8px 8px;
	border: 1px solid #ECE6D9;
	border-radius: 999px;
	text-decoration: none;
	color: #1F3D2C;
	background: #fff;
	white-space: nowrap;
	transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
}
.fmke-cat-shortcut:hover {
	border-color: #7A2048;
	color: #7A2048;
}
.fmke-cat-shortcut-active {
	background: #FBF7F0;
	border-color: #7A2048;
	color: #7A2048;
	font-weight: 600;
}
.fmke-cat-shortcut-icon {
	flex: 0 0 auto;
	width: 30px;
	height: 30px;
	border-radius: 50%;
	background: #FBF7F0;
	display: flex;
	align-items: center;
	justify-content: center;
	font-family: 'Fraunces', Georgia, serif;
	font-size: 14px;
	font-weight: 600;
	color: #7A2048;
}
.fmke-cat-shortcut-icon-image {
	background-size: cover;
	background-position: center;
}
.fmke-cat-shortcut-label {
	font-size: 13px;
}

@media (max-width: 480px) {
	.fmke-cat-shortcut {
		padding: 6px 12px 6px 6px;
	}
	.fmke-cat-shortcut-icon {
		width: 26px;
		height: 26px;
		font-size: 12px;
	}
	.fmke-cat-shortcut-label {
		font-size: 12px;
	}
}
CSS;
	}
}

new FMKE_Category_Shortcuts();
