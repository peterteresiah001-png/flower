<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three homepage promo banners, one per default marketplace category
 * (Flowers, Gifts, Event Rentals - the same three terms this plugin
 * creates on activation in flower-marketplace-ke.php). Each banner links
 * straight to that category's shop archive.
 *
 * Renders in two ways so it works regardless of how the homepage is
 * built:
 *   1. Automatically, via Storefront's own 'storefront_before_content'
 *      hook, on the front page - since the site is on Storefront.
 *   2. Via the [fmke_promo_banners] shortcode, for anyone using a page
 *      builder or a "Your latest posts" homepage where that hook may
 *      not fire, or who wants the banners somewhere other than the top.
 *
 * If a category has a WooCommerce "Thumbnail" image set (Products >
 * Categories > edit), that image is used as the banner background.
 * Otherwise it falls back to a plain colour block so the banner never
 * renders broken/empty.
 */
class FMKE_Homepage_Banners {

	/**
	 * Category names + fallback colour, in the same order as the
	 * default categories created on activation.
	 */
	const BANNERS = array(
		array(
			'category' => 'Flowers',
			'tagline'  => 'Fresh bouquets, delivered same day',
			'color'    => '#7A2048',
		),
		array(
			'category' => 'Gifts',
			'tagline'  => 'Something extra to go with the flowers',
			'color'    => '#1F3D2C',
		),
		array(
			'category' => 'Event Rentals',
			'tagline'  => 'Décor and rentals for weddings & events',
			'color'    => '#B08A3E',
		),
	);

	public function __construct() {
		add_shortcode( 'fmke_promo_banners', array( $this, 'shortcode' ) );
		add_action( 'storefront_before_content', array( $this, 'auto_render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Auto-inserts the banners at the top of the content area on the
	 * front page when the active theme fires Storefront's content hook.
	 * Themes that don't fire it simply never call this - the shortcode
	 * still works as a manual fallback.
	 */
	public function auto_render() {
		if ( ! is_front_page() ) {
			return;
		}
		echo $this->render_banners();
	}

	public function shortcode() {
		return $this->render_banners();
	}

	/**
	 * Builds the HTML, or an empty string if none of the three default
	 * categories exist (e.g. a vendor deleted/renamed them) - so this
	 * never prints a broken block of dead links.
	 */
	private function render_banners() {
		$banners = array();

		foreach ( self::BANNERS as $banner ) {
			$term = get_term_by( 'name', $banner['category'], 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$banners[] = array(
				'name'    => $term->name,
				'link'    => get_term_link( $term ),
				'tagline' => $banner['tagline'],
				'color'   => $banner['color'],
				'image'   => $this->get_category_image( $term->term_id ),
			);
		}

		if ( empty( $banners ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="fmke-promo-banners">
			<?php foreach ( $banners as $banner ) : ?>
				<a class="fmke-promo-banner" href="<?php echo esc_url( $banner['link'] ); ?>"
					<?php if ( $banner['image'] ) : ?>
						style="background-image:linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.45)), url('<?php echo esc_url( $banner['image'] ); ?>');"
					<?php else : ?>
						style="background-color:<?php echo esc_attr( $banner['color'] ); ?>;"
					<?php endif; ?>
				>
					<span class="fmke-promo-banner-name"><?php echo esc_html( $banner['name'] ); ?></span>
					<span class="fmke-promo-banner-tagline"><?php echo esc_html( $banner['tagline'] ); ?></span>
					<span class="fmke-promo-banner-cta">Shop now &rarr;</span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function get_category_image( $term_id ) {
		$thumbnail_id = get_term_meta( $term_id, 'thumbnail_id', true );
		if ( ! $thumbnail_id ) {
			return '';
		}
		$image = wp_get_attachment_image_url( $thumbnail_id, 'large' );
		return $image ? $image : '';
	}

	public function enqueue() {
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-promo-banners {
	display: flex;
	gap: 16px;
	margin: 24px 0;
	font-family: 'General Sans', sans-serif;
}
.fmke-promo-banner {
	flex: 1 1 0;
	min-height: 220px;
	display: flex;
	flex-direction: column;
	justify-content: flex-end;
	padding: 20px;
	border-radius: 6px;
	background-size: cover;
	background-position: center;
	text-decoration: none;
	color: #fff;
	box-shadow: 0 1px 3px rgba(0,0,0,0.15);
	transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.fmke-promo-banner:hover {
	transform: translateY(-2px);
	box-shadow: 0 6px 16px rgba(0,0,0,0.18);
	color: #fff;
}
.fmke-promo-banner-name {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 22px;
	font-weight: 600;
	line-height: 1.2;
}
.fmke-promo-banner-tagline {
	font-size: 13px;
	opacity: 0.9;
	margin-top: 4px;
}
.fmke-promo-banner-cta {
	font-size: 13px;
	font-weight: 600;
	margin-top: 12px;
}

@media (max-width: 768px) {
	.fmke-promo-banners {
		flex-direction: column;
	}
	.fmke-promo-banner {
		min-height: 160px;
	}
}
CSS;
	}
}

new FMKE_Homepage_Banners();
