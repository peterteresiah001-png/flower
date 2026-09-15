<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Featured Vendors" section - a simple row of vendor cards (store logo,
 * name, and product count) linking through to each Dokan store page, so
 * shoppers can discover who's actually selling on the marketplace.
 *
 * Same rendering pattern as class-top-categories.php:
 *   1. Automatically, on the front page, just below Top Categories
 *      (same 'storefront_before_content' hook, lower priority so it
 *      lands after it).
 *   2. Via the [fmke_featured_vendors] shortcode, for use anywhere else.
 *      Accepts a "count" attribute, e.g. [fmke_featured_vendors count="4"].
 *
 * Vendors are pulled from Dokan (active sellers, most products first).
 * If Dokan isn't active, this section simply doesn't render anything.
 *
 * Each card also shows a simple star rating (average + review count),
 * pulled from Dokan's own seller rating data - the same rating shown on
 * the vendor's store page. Vendors with no reviews yet just show the
 * product count, no empty/zero-star row.
 */
class FMKE_Featured_Vendors {

	/** Default number of vendor cards to show. */
	const DEFAULT_COUNT = 4;

	public function __construct() {
		add_shortcode( 'fmke_featured_vendors', array( $this, 'shortcode' ) );
		// Priority 20: after FMKE_Top_Categories (15), so this lands below it.
		add_action( 'storefront_before_content', array( $this, 'auto_render' ), 20 );
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
		), $atts, 'fmke_featured_vendors' );

		return $this->render_grid( (int) $atts['count'] );
	}

	/**
	 * Builds the HTML, or an empty string if Dokan isn't active or there
	 * are no qualifying vendors - so this never prints a broken/empty
	 * heading.
	 *
	 * @param int $count Max number of vendor cards.
	 */
	private function render_grid( $count ) {
		if ( ! function_exists( 'dokan_get_sellers' ) ) {
			return '';
		}

		$count = $count > 0 ? $count : self::DEFAULT_COUNT;

		$sellers = dokan_get_sellers( array(
			'number' => $count,
			'orderby' => 'meta_value_num',
			'meta_key' => '_dokan_product_count', // phpcs:ignore -- Dokan-maintained meta key.
			'order' => 'DESC',
		) );

		$sellers = is_array( $sellers ) && isset( $sellers['users'] ) ? $sellers['users'] : array();

		if ( empty( $sellers ) ) {
			return '';
		}

		$cards = array();

		foreach ( $sellers as $seller ) {
			$store_info = function_exists( 'dokan_get_store_info' ) ? dokan_get_store_info( $seller->ID ) : array();
			$store_name = ! empty( $store_info['store_name'] ) ? $store_info['store_name'] : $seller->display_name;

			$rating = $this->get_store_rating( $seller->ID );
			$city   = $this->get_store_city( $store_info );

			$cards[] = array(
				'name'          => $store_name,
				'link'          => function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( $seller->ID ) : '#',
				'image'         => $this->get_store_image( $seller->ID, $store_info ),
				'count'         => (int) get_user_meta( $seller->ID, '_dokan_product_count', true ),
				'rating'        => $rating['rating'],
				'review_count'  => $rating['count'],
				'city'          => $city,
			);
		}

		if ( empty( $cards ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="fmke-featured-vendors">
			<h2 class="fmke-featured-vendors-title">Featured Vendors</h2>
			<div class="fmke-featured-vendors-grid">
				<?php foreach ( $cards as $card ) : ?>
					<a class="fmke-vendor-card" href="<?php echo esc_url( $card['link'] ); ?>">
						<span class="fmke-vendor-image"
							<?php if ( $card['image'] ) : ?>
								style="background-image:url('<?php echo esc_url( $card['image'] ); ?>');"
							<?php endif; ?>
						>
							<?php if ( ! $card['image'] ) : ?>
								<span class="fmke-vendor-initial" aria-hidden="true"><?php echo esc_html( mb_substr( $card['name'], 0, 1 ) ); ?></span>
							<?php endif; ?>
						</span>
						<span class="fmke-vendor-name"><?php echo esc_html( $card['name'] ); ?></span>
						<?php if ( $card['city'] ) : ?>
							<span class="fmke-vendor-city">📍 <?php echo esc_html( $card['city'] ); ?></span>
						<?php endif; ?>
						<?php if ( $card['review_count'] > 0 ) : ?>
							<span class="fmke-vendor-rating">
								<span class="fmke-vendor-stars" aria-hidden="true"><?php echo esc_html( $this->render_stars( $card['rating'] ) ); ?></span>
								<span class="fmke-vendor-rating-value">
									<?php echo esc_html( number_format_i18n( $card['rating'], 1 ) ); ?>
									(<?php echo esc_html( $card['review_count'] ); ?>)
								</span>
							</span>
						<?php endif; ?>
						<?php if ( $card['count'] > 0 ) : ?>
							<span class="fmke-vendor-count">
								<?php echo esc_html( sprintf( _n( '%d product', '%d products', $card['count'], 'fmke' ), $card['count'] ) ); ?>
							</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Prefers the store banner, falls back to the store gravatar, then to
	 * an empty string so the template can show a plain letter-tile.
	 */
	private function get_store_image( $seller_id, $store_info ) {
		if ( ! empty( $store_info['banner'] ) && function_exists( 'dokan_get_image_url' ) ) {
			$banner_url = dokan_get_image_url( $store_info['banner'] );
			if ( $banner_url ) {
				return $banner_url;
			}
		}
		if ( ! empty( $store_info['gravatar'] ) ) {
			$gravatar_url = function_exists( 'dokan_get_image_url' ) ? dokan_get_image_url( $store_info['gravatar'] ) : '';
			if ( $gravatar_url ) {
				return $gravatar_url;
			}
		}
		return get_avatar_url( $seller_id, array( 'size' => 96 ) );
	}

	/**
	 * Pulls the vendor's city out of Dokan's store address data, if the
	 * vendor has filled it in. Returns '' otherwise, so the badge just
	 * doesn't render rather than showing an empty pin.
	 */
	private function get_store_city( $store_info ) {
		if ( ! empty( $store_info['address']['city'] ) ) {
			return $store_info['address']['city'];
		}
		return '';
	}

	/**
	 * Reads a seller's average rating + review count from Dokan.
	 * Returns zeros (rendered as no rating row) if Dokan's rating
	 * function isn't available or the vendor has no reviews yet.
	 */
	private function get_store_rating( $seller_id ) {
		$rating = array( 'rating' => 0, 'count' => 0 );

		if ( function_exists( 'dokan_get_seller_rating' ) ) {
			$data = dokan_get_seller_rating( $seller_id );
			if ( ! empty( $data['rating'] ) ) {
				$rating['rating'] = (float) $data['rating'];
			}
			if ( ! empty( $data['count'] ) ) {
				$rating['count'] = (int) $data['count'];
			}
		}

		return $rating;
	}

	/**
	 * Turns a 0-5 average into a plain filled/empty star string, e.g.
	 * "★★★★☆" for a 4.3 average (rounded to the nearest whole star).
	 */
	private function render_stars( $average ) {
		$filled = (int) round( $average );
		$filled = max( 0, min( 5, $filled ) );
		return str_repeat( '★', $filled ) . str_repeat( '☆', 5 - $filled );
	}

	public function enqueue() {
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-featured-vendors {
	margin: 32px 0;
	font-family: 'General Sans', sans-serif;
}
.fmke-featured-vendors-title {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 24px;
	font-weight: 600;
	color: #1F3D2C;
	margin: 0 0 18px;
}
.fmke-featured-vendors-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 20px;
}
.fmke-vendor-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	text-decoration: none;
	color: #1F3D2C;
	background: #FBF7F0;
	border-radius: 12px;
	padding: 20px 12px;
	transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.fmke-vendor-card:hover {
	transform: translateY(-3px);
	box-shadow: 0 6px 16px rgba(0,0,0,0.15);
}
.fmke-vendor-image {
	width: 72px;
	height: 72px;
	border-radius: 50%;
	background-color: #ffffff;
	background-size: cover;
	background-position: center;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.fmke-vendor-initial {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 26px;
	font-weight: 600;
	color: #7A2048;
}
.fmke-vendor-name {
	margin-top: 12px;
	font-size: 14px;
	font-weight: 600;
}
.fmke-vendor-city {
	margin-top: 3px;
	font-size: 11px;
	color: #1F3D2C;
	background: #EDE6D8;
	border-radius: 10px;
	padding: 2px 8px;
	display: inline-block;
}
.fmke-vendor-card:hover .fmke-vendor-name {
	color: #7A2048;
}
.fmke-vendor-count {
	margin-top: 2px;
	font-size: 12px;
	color: #767676;
}
.fmke-vendor-rating {
	margin-top: 4px;
	display: flex;
	align-items: center;
	gap: 5px;
}
.fmke-vendor-stars {
	font-size: 13px;
	color: #E8A33D;
	letter-spacing: 1px;
}
.fmke-vendor-rating-value {
	font-size: 12px;
	color: #767676;
}

@media (max-width: 480px) {
	.fmke-featured-vendors-grid {
		grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
		gap: 14px;
	}
	.fmke-vendor-image {
		width: 60px;
		height: 60px;
	}
}
CSS;
	}
}

new FMKE_Featured_Vendors();
