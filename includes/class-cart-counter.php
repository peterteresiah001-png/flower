<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the header cart count update live, without a full page reload,
 * from anywhere a product gets added - including single product pages,
 * which WooCommerce doesn't AJAX-ify by default (only shop/archive
 * listings get that out of the box).
 *
 * Reuses WooCommerce's own existing 'add_to_cart' AJAX action and its
 * native fragments/added_to_cart system, rather than building a separate
 * custom endpoint - this keeps it compatible with anything else on the
 * site that already listens for WooCommerce's own cart-update events.
 */
class FMKE_Cart_Counter {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		if ( ! function_exists( 'is_product' ) ) {
			return;
		}
		// wc_add_to_cart_params is localized by WooCommerce core on pages
		// where it enqueues its own add-to-cart script - relying on that
		// being present rather than re-declaring our own AJAX URL logic.
		wp_add_inline_script( 'wc-add-to-cart', $this->get_inline_js(), 'after' );
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_js() {
		return <<<JS
jQuery(function ($) {
	if (typeof wc_add_to_cart_params === 'undefined') {
		return;
	}

	// Only intervene on the single-product "form.cart" - WooCommerce's
	// own script already handles AJAX for shop/archive loop buttons.
	$('form.cart').on('submit', function (e) {
		var \$form = $(this);

		// Let variable/external products fall through to normal behaviour;
		// only intercept straightforward simple-product submissions.
		if (\$form.find('input.variation_id').length) {
			return;
		}

		e.preventDefault();

		var \$button = \$form.find('button[type="submit"]');
		\$button.addClass('fmke-adding').prop('disabled', true);

		var data = \$form.serialize() + '&action=add_to_cart';
		var ajaxUrl = wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'add_to_cart');

		$.post(ajaxUrl, data)
			.done(function (response) {
				if (!response || response.error) {
					\$form.off('submit').trigger('submit');
					return;
				}
				$(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, \$button]);

				var \$note = $('<span class="fmke-added-notice">Added to cart</span>');
				\$button.after(\$note);
				setTimeout(function () {
					\$note.fadeOut(300, function () { $(this).remove(); });
				}, 1500);
			})
			.always(function () {
				\$button.removeClass('fmke-adding').prop('disabled', false);
			});
	});

	// Give the header badge a brief pulse whenever the cart updates,
	// whether that came from this page or a shop-loop AJAX add.
	$(document.body).on('added_to_cart', function () {
		$('.site-header-cart .cart-contents-count, .site-header-cart .count')
			.addClass('fmke-cart-pulse')
			.each(function () {
				var \$el = $(this);
				setTimeout(function () { \$el.removeClass('fmke-cart-pulse'); }, 500);
			});
	});
});
JS;
	}

	private function get_inline_css() {
		return <<<CSS
.site-header-cart .cart-contents-count,
.site-header-cart .count {
	background: #7A2048;
	color: #fff;
	border-radius: 50%;
	min-width: 18px;
	height: 18px;
	line-height: 18px;
	text-align: center;
	font-size: 11px;
	display: inline-block;
	transition: transform 0.2s ease;
}
.fmke-cart-pulse {
	transform: scale(1.35);
}
.fmke-adding {
	opacity: 0.6;
	pointer-events: none;
}
.fmke-added-notice {
	display: inline-block;
	margin-left: 10px;
	color: #1F3D2C;
	background: #E8B4BC;
	font-family: 'General Sans', sans-serif;
	font-size: 13px;
	padding: 4px 10px;
	border-radius: 12px;
}
CSS;
	}
}

new FMKE_Cart_Counter();
