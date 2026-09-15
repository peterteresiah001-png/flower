<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live product search suggestions for the header search bar.
 *
 * Works with Storefront's built-in product search field
 * (.woocommerce-product-search .search-field) without needing to edit
 * any theme files - it just listens for input on that field and injects
 * a results dropdown underneath it.
 */
class FMKE_Live_Search {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_fmke_live_search', array( $this, 'handle_search' ) );
		add_action( 'wp_ajax_nopriv_fmke_live_search', array( $this, 'handle_search' ) );
	}

	public function enqueue() {
		// jQuery ships with WordPress core, so no extra file to upload.
		wp_enqueue_script( 'jquery' );

		wp_add_inline_script( 'jquery', $this->get_inline_js() );
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	/**
	 * AJAX handler: returns up to 6 matching published products as JSON.
	 */
	public function handle_search() {
		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		if ( strlen( $term ) < 2 ) {
			wp_send_json( array() );
		}

		$query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => $term,
			'posts_per_page' => 6,
			'no_found_rows'  => true,
		) );

		$results = array();

		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}
			$results[] = array(
				'title'     => get_the_title( $post ),
				'url'       => get_permalink( $post ),
				'price'     => wp_strip_all_tags( $product->get_price_html() ),
				'thumbnail' => get_the_post_thumbnail_url( $post->ID, 'thumbnail' ) ?: wc_placeholder_img_src( 'thumbnail' ),
			);
		}

		wp_reset_postdata();
		wp_send_json( $results );
	}

	private function get_inline_js() {
		$ajax_url = admin_url( 'admin-ajax.php' );
		return <<<JS
jQuery(function ($) {
	var \$input = $('.woocommerce-product-search .search-field');
	if (!\$input.length) return;

	var \$wrap = \$input.closest('form');
	\$wrap.css('position', 'relative');

	var \$results = $('<div class="fmke-live-search-results"></div>').hide();
	\$wrap.append(\$results);

	var timer = null;

	\$input.on('input', function () {
		var term = $(this).val();
		clearTimeout(timer);

		if (term.length < 2) {
			\$results.hide().empty();
			return;
		}

		timer = setTimeout(function () {
			$.getJSON('{$ajax_url}', { action: 'fmke_live_search', term: term }, function (items) {
				\$results.empty();

				if (!items.length) {
					\$results.append('<div class="fmke-live-search-empty">No products found</div>');
				} else {
					items.forEach(function (item) {
						var \$row = $('<a class="fmke-live-search-item"></a>')
							.attr('href', item.url);
						\$row.append($('<img>').attr('src', item.thumbnail));
						var \$text = $('<div class="fmke-live-search-text"></div>');
						\$text.append($('<span class="fmke-live-search-title"></span>').text(item.title));
						\$text.append($('<span class="fmke-live-search-price"></span>').html(item.price));
						\$row.append(\$text);
						\$results.append(\$row);
					});
				}
				\$results.show();
			});
		}, 300);
	});

	$(document).on('click', function (e) {
		if (!$(e.target).closest(\$wrap).length) {
			\$results.hide();
		}
	});
});
JS;
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-live-search-results {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	background: #fff;
	border: 1px solid #E8B4BC;
	border-radius: 12px;
	margin-top: 6px;
	box-shadow: 0 8px 24px rgba(31,61,44,0.12);
	z-index: 200;
	overflow: hidden;
}
.fmke-live-search-item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 12px;
	text-decoration: none;
	color: #1F3D2C;
	border-bottom: 1px solid #f2f2f2;
}
.fmke-live-search-item:last-child { border-bottom: none; }
.fmke-live-search-item:hover { background: #FBF7F0; }
.fmke-live-search-item img {
	width: 40px;
	height: 40px;
	object-fit: cover;
	border-radius: 6px;
	flex-shrink: 0;
}
.fmke-live-search-text {
	display: flex;
	flex-direction: column;
	font-family: 'General Sans', sans-serif;
}
.fmke-live-search-title { font-size: 13px; font-weight: 500; }
.fmke-live-search-price { font-size: 12px; color: #7A2048; }
.fmke-live-search-empty {
	padding: 10px 12px;
	font-size: 13px;
	color: #999;
	font-family: 'General Sans', sans-serif;
}
CSS;
	}
}

new FMKE_Live_Search();
