<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple wishlist feature: logged-in users can save products to a list
 * stored in their user meta, view it on a dedicated page, and remove
 * items from there. Guests are prompted to log in when they try to save.
 */
class FMKE_Wishlist {

	const META_KEY = 'fmke_wishlist_product_ids';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'storefront_header', array( $this, 'render_header_icon' ), 64 );
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_toggle_button' ), 15 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_toggle_button' ), 35 );
		add_action( 'wp_ajax_fmke_wishlist_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'wp_ajax_nopriv_fmke_wishlist_toggle', array( $this, 'handle_toggle_guest' ) );
		add_shortcode( 'fmke_wishlist', array( $this, 'render_wishlist_page' ) );
	}

	private function get_ids() {
		if ( ! is_user_logged_in() ) {
			return array();
		}
		$ids = get_user_meta( get_current_user_id(), self::META_KEY, true );
		return is_array( $ids ) ? $ids : array();
	}

	private function save_ids( $ids ) {
		update_user_meta( get_current_user_id(), self::META_KEY, array_values( array_unique( $ids ) ) );
	}

	public function render_header_icon() {
		$count = count( $this->get_ids() );
		$url   = get_option( 'fmke_wishlist_page_id' ) ? get_permalink( get_option( 'fmke_wishlist_page_id' ) ) : '#';

		echo '<a href="' . esc_url( $url ) . '" class="fmke-wishlist-header-icon" aria-label="Wishlist">';
		echo '<span class="fmke-wishlist-heart" aria-hidden="true">&#9825;</span>';
		echo '<span class="fmke-wishlist-count">' . intval( $count ) . '</span>';
		echo '</a>';
	}

	public function render_toggle_button() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$is_saved = in_array( $product->get_id(), $this->get_ids(), true );
		$class    = $is_saved ? 'fmke-wishlist-btn is-saved' : 'fmke-wishlist-btn';

		echo '<button type="button" class="' . esc_attr( $class ) . '" data-product-id="' . esc_attr( $product->get_id() ) . '" aria-label="Save to wishlist">';
		echo '<span aria-hidden="true">&#9825;</span>';
		echo '</button>';
	}

	public function handle_toggle() {
		check_ajax_referer( 'fmke_wishlist', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error();
		}

		$ids     = $this->get_ids();
		$is_now  = ! in_array( $product_id, $ids, true );

		if ( $is_now ) {
			$ids[] = $product_id;
		} else {
			$ids = array_diff( $ids, array( $product_id ) );
		}

		$this->save_ids( $ids );

		wp_send_json_success( array(
			'saved' => $is_now,
			'count' => count( $ids ),
		) );
	}

	public function handle_toggle_guest() {
		wp_send_json_error( array( 'message' => 'Please log in to save items to your wishlist.' ), 401 );
	}

	public function render_wishlist_page() {
		if ( ! is_user_logged_in() ) {
			$myaccount_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
			return '<p>Please <a href="' . esc_url( $myaccount_url ) . '">log in</a> to view your wishlist.</p>';
		}

		$ids = $this->get_ids();
		if ( empty( $ids ) ) {
			return '<p>Your wishlist is empty. Browse the shop and tap the heart icon on any product to save it here.</p>';
		}

		ob_start();
		echo '<div class="fmke-wishlist-grid">';
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			echo '<div class="fmke-wishlist-item">';
			echo '<a href="' . esc_url( get_permalink( $id ) ) . '">' . wp_kses_post( $product->get_image( 'medium' ) ) . '</a>';
			echo '<a href="' . esc_url( get_permalink( $id ) ) . '" class="fmke-wishlist-item-title">' . esc_html( $product->get_name() ) . '</a>';
			echo '<span class="fmke-wishlist-item-price">' . wp_kses_post( $product->get_price_html() ) . '</span>';
			echo '<button type="button" class="fmke-wishlist-btn is-saved" data-product-id="' . esc_attr( $id ) . '"><span aria-hidden="true">&#9825;</span> Remove</button>';
			echo '</div>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	public function enqueue() {
		wp_add_inline_script( 'jquery', $this->get_inline_js(), 'after' );
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_js() {
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'fmke_wishlist' );
		return <<<JS
jQuery(function ($) {
	$(document).on('click', '.fmke-wishlist-btn', function (e) {
		e.preventDefault();
		var \$btn = $(this);
		var productId = \$btn.data('product-id');

		$.post('{$ajax_url}', {
			action: 'fmke_wishlist_toggle',
			product_id: productId,
			nonce: '{$nonce}'
		}).done(function (res) {
			if (!res.success) {
				alert(res.data && res.data.message ? res.data.message : 'Please log in to save items.');
				return;
			}
			\$('.fmke-wishlist-count').text(res.data.count);

			if (\$btn.closest('.fmke-wishlist-grid').length) {
				\$btn.closest('.fmke-wishlist-item').fadeOut(200, function () { $(this).remove(); });
			} else {
				\$btn.toggleClass('is-saved', res.data.saved);
			}
		});
	});
});
JS;
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-wishlist-header-icon {
	position: relative;
	display: inline-flex;
	align-items: center;
	padding: 8px 10px;
	color: #1F3D2C;
	text-decoration: none;
	font-size: 20px;
}
.fmke-wishlist-count {
	position: absolute;
	top: 2px;
	right: 2px;
	background: #7A2048;
	color: #fff;
	font-size: 10px;
	min-width: 16px;
	height: 16px;
	line-height: 16px;
	text-align: center;
	border-radius: 50%;
	font-family: 'General Sans', sans-serif;
}
.fmke-wishlist-btn {
	background: #fff;
	border: 1px solid #E8B4BC;
	border-radius: 50%;
	width: 36px;
	height: 36px;
	cursor: pointer;
	color: #7A2048;
	font-size: 16px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	transition: background 0.15s ease;
}
.fmke-wishlist-btn.is-saved {
	background: #7A2048;
	color: #fff;
	border-color: #7A2048;
}
.fmke-wishlist-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 20px;
}
.fmke-wishlist-item {
	display: flex;
	flex-direction: column;
	gap: 6px;
	font-family: 'General Sans', sans-serif;
}
.fmke-wishlist-item-title {
	color: #1F3D2C;
	text-decoration: none;
	font-weight: 500;
}
.fmke-wishlist-item-price {
	color: #7A2048;
	font-size: 14px;
}
CSS;
	}
}

new FMKE_Wishlist();
