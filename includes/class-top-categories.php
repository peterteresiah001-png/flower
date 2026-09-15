<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Top Categories" browsing grid - a row of circular category tiles
 * (image + name + live product count) ordered by product count, so
 * shoppers can jump straight into whichever part of the catalogue is
 * best stocked right now.
 *
 * This is deliberately a different shape to class-homepage-banners.php:
 * the three banners are fixed hero placements for the marketplace's
 * three top-level pillars (Flowers / Gifts / Event Rentals), while this
 * grid is data-driven and surfaces whatever categories actually have
 * products in them - including subcategories a vendor creates later
 * (e.g. "Roses", "Birthday Gifts", "Wedding Decor"). To avoid showing
 * the same three pillar categories twice in a row on the homepage, the
 * automatic homepage placement excludes them; the shortcode does not,
 * since it may be the only thing on the page it's placed on.
 *
 * Renders in two ways, same pattern as the promo banners:
 *   1. Automatically, on the front page, just below the promo banners
 *      (same 'storefront_before_content' hook, lower priority so it
 *      lands after them).
 *   2. Via the [fmke_top_categories] shortcode, for anyone who wants
 *      it elsewhere - e.g. lower on the shop page. Accepts a "count"
 *      attribute, e.g. [fmke_top_categories count="6"].
 *
 * Uses each category's WooCommerce "Thumbnail" image if set, otherwise
 * a plain letter-tile fallback so nothing ever renders broken.
 */
class FMKE_Top_Categories {

	/** Default number of category tiles to show. */
	const DEFAULT_COUNT = 8;

	/**
	 * The three pillar categories already hero-featured by the promo
	 * banners - excluded from the automatic homepage placement only
	 * (see class comment above).
	 */
	const HERO_CATEGORIES = array( 'Flowers', 'Gifts', 'Event Rentals' );

	public function __construct() {
		add_shortcode( 'fmke_top_categories', array( $this, 'shortcode' ) );
		// Priority 15: after FMKE_Homepage_Banners' default-priority (10)
		// hook on the same action, so this grid lands directly below it.
		add_action( 'storefront_before_content', array( $this, 'auto_render' ), 15 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function auto_render() {
		if ( ! is_front_page() ) {
			return;
		}
		echo $this->render_grid( self::DEFAULT_COUNT, true );
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'count' => self::DEFAULT_COUNT,
		), $atts, 'fmke_top_categories' );

		return $this->render_grid( (int) $atts['count'], false );
	}

	/**
	 * Builds the HTML, or an empty string if there are no qualifying
	 * categories - so this never prints a broken/empty heading.
	 *
	 * @param int  $count       Max number of category tiles.
	 * @param bool $exclude_hero Whether to leave out the 3 pillar categories.
	 */
	private function render_grid( $count, $exclude_hero ) {
		$count = $count > 0 ? $count : self::DEFAULT_COUNT;

		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'exclude'    => array( get_option( 'default_product_cat', 0 ) ), // Leave out "Uncategorised".
			'number'     => $exclude_hero ? ( $count + count( self::HERO_CATEGORIES ) ) : $count,
		);

		$terms = get_terms( $args );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$tiles = array();

		foreach ( $terms as $term ) {
			if ( $exclude_hero && in_array( $term->name, self::HERO_CATEGORIES, true ) ) {
				continue;
			}

			$tiles[] = array(
				'name'  => $term->name,
				'link'  => get_term_link( $term ),
				'count' => $term->count,
				'image' => $this->get_category_image( $term->term_id ),
			);

			if ( count( $tiles ) >= $count ) {
				break;
			}
		}

		if ( empty( $tiles ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="fmke-top-categories">
			<h2 class="fmke-top-categories-title">Top Categories</h2>
			<div class="fmke-top-categories-grid">
				<?php foreach ( $tiles as $tile ) : ?>
					<a class="fmke-top-category" href="<?php echo esc_url( $tile['link'] ); ?>">
						<span class="fmke-top-category-image"
							<?php if ( $tile['image'] ) : ?>
								style="background-image:url('<?php echo esc_url( $tile['image'] ); ?>');"
							<?php endif; ?>
						>
							<?php if ( ! $tile['image'] ) : ?>
								<span class="fmke-top-category-initial" aria-hidden="true"><?php echo esc_html( mb_substr( $tile['name'], 0, 1 ) ); ?></span>
							<?php endif; ?>
						</span>
						<span class="fmke-top-category-name"><?php echo esc_html( $tile['name'] ); ?></span>
						<span class="fmke-top-category-count">
							<?php echo esc_html( sprintf( _n( '%d product', '%d products', $tile['count'], 'fmke' ), $tile['count'] ) ); ?>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function get_category_image( $term_id ) {
		$thumbnail_id = get_term_meta( $term_id, 'thumbnail_id', true );
		if ( ! $thumbnail_id ) {
			return '';
		}
		$image = wp_get_attachment_image_url( $thumbnail_id, 'medium' );
		return $image ? $image : '';
	}

	public function enqueue() {
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-top-categories {
	margin: 32px 0;
	font-family: 'General Sans', sans-serif;
}
.fmke-top-categories-title {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 24px;
	font-weight: 600;
	color: #1F3D2C;
	margin: 0 0 18px;
}
.fmke-top-categories-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
	gap: 20px;
}
.fmke-top-category {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	text-decoration: none;
	color: #1F3D2C;
}
.fmke-top-category-image {
	width: 96px;
	height: 96px;
	border-radius: 50%;
	background-color: #FBF7F0;
	background-size: cover;
	background-position: center;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 1px 3px rgba(0,0,0,0.15);
	transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.fmke-top-category:hover .fmke-top-category-image {
	transform: translateY(-3px);
	box-shadow: 0 6px 16px rgba(0,0,0,0.18);
}
.fmke-top-category-initial {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 34px;
	font-weight: 600;
	color: #7A2048;
}
.fmke-top-category-name {
	margin-top: 10px;
	font-size: 14px;
	font-weight: 600;
}
.fmke-top-category:hover .fmke-top-category-name {
	color: #7A2048;
}
.fmke-top-category-count {
	margin-top: 2px;
	font-size: 12px;
	color: #767676;
}

@media (max-width: 480px) {
	.fmke-top-categories-grid {
		grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
		gap: 14px;
	}
	.fmke-top-category-image {
		width: 72px;
		height: 72px;
	}
	.fmke-top-category-initial {
		font-size: 26px;
	}
}
CSS;
	}
}

new FMKE_Top_Categories();
