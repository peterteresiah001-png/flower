<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marketplace footer: branding blurb, quick links to the three default
 * shop categories, customer-care links (My Account, wishlist), a Help
 * Center column with just Call/WhatsApp links, a Supported Cities column
 * pulled from active vendors' own store addresses, a "we accept" row of
 * the enabled payment methods, and a copyright line.
 *
 * Renders in two ways so it works regardless of how the site is built:
 *   1. Automatically, via Storefront's own 'storefront_footer' hook, at
 *      priority 5 - i.e. above Storefront's own footer widgets/credit
 *      line (which run at priority 10/20), since the site is on
 *      Storefront (see class-homepage-banners.php).
 *   2. Via the [fmke_site_footer] shortcode, for anyone who wants it
 *      placed manually (e.g. a page builder footer template) or is on
 *      a different theme that never fires the Storefront hook.
 *
 * The payment badges reuse the same "is this gateway actually enabled
 * right now" checks as class-service-badges.php, so the footer never
 * advertises a payment method that isn't switched on.
 */
class FMKE_Site_Footer {

	public function __construct() {
		add_shortcode( 'fmke_site_footer', array( $this, 'shortcode' ) );
		add_action( 'storefront_footer', array( $this, 'auto_render' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// Bust the cached city list as soon as a vendor updates their
		// store address, rather than making shoppers wait out the hour.
		add_action( 'dokan_store_profile_saved', array( $this, 'clear_cities_cache' ) );
	}

	public function clear_cities_cache() {
		delete_transient( 'fmke_supported_cities' );
	}

	/**
	 * Auto-inserts the footer when the active theme fires Storefront's
	 * footer hook. Themes that don't fire it simply never call this -
	 * the shortcode still works as a manual fallback.
	 */
	public function auto_render() {
		echo $this->render();
	}

	public function shortcode() {
		return $this->render();
	}

	private function render() {
		ob_start();
		?>
		<div class="fmke-site-footer">
			<div class="fmke-footer-columns">

				<div class="fmke-footer-col fmke-footer-about">
					<p class="fmke-footer-brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
					<p class="fmke-footer-tagline">Flowers, gifts &amp; event rentals from vendors near you - ordered online, paid for with M-Pesa, and on their way the same day.</p>
				</div>

				<div class="fmke-footer-col">
					<p class="fmke-footer-heading">Shop</p>
					<ul class="fmke-footer-links">
						<?php echo $this->category_links(); ?>
					</ul>
				</div>

				<div class="fmke-footer-col">
					<p class="fmke-footer-heading">Customer Care</p>
					<ul class="fmke-footer-links">
						<?php echo $this->customer_care_links(); ?>
					</ul>
				</div>

				<div class="fmke-footer-col">
					<p class="fmke-footer-heading">Help Center</p>
					<ul class="fmke-footer-links">
						<?php echo $this->help_center_links(); ?>
					</ul>
				</div>

				<div class="fmke-footer-col">
					<p class="fmke-footer-heading">Supported Cities</p>
					<?php echo $this->supported_cities(); ?>
				</div>

				<div class="fmke-footer-col">
					<p class="fmke-footer-heading">We Accept</p>
					<?php echo $this->payment_badges(); ?>
				</div>

			</div>

			<div class="fmke-footer-bottom">
				<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>. All rights reserved.</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Links to the three default marketplace categories created on
	 * activation (see flower-marketplace-ke.php), skipping any that
	 * have since been renamed/deleted rather than printing a broken link.
	 */
	private function category_links() {
		$categories = array( 'Flowers', 'Gifts', 'Event Rentals' );
		$out        = '';

		foreach ( $categories as $name ) {
			$term = get_term_by( 'name', $name, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$out .= sprintf(
				'<li><a href="%1$s">%2$s</a></li>',
				esc_url( get_term_link( $term ) ),
				esc_html( $name )
			);
		}

		if ( '' === $out && function_exists( 'wc_get_page_permalink' ) ) {
			$out = sprintf(
				'<li><a href="%1$s">All Products</a></li>',
				esc_url( wc_get_page_permalink( 'shop' ) )
			);
		}

		return $out;
	}

	/**
	 * My Account and the wishlist page this plugin creates on activation
	 * (see fmke_create_wishlist_page()).
	 */
	private function customer_care_links() {
		$links = array();

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$links[] = array( wc_get_page_permalink( 'myaccount' ), 'My Account' );
		}

		$wishlist_page_id = get_option( 'fmke_wishlist_page_id' );
		if ( $wishlist_page_id && get_post( $wishlist_page_id ) ) {
			$links[] = array( get_permalink( $wishlist_page_id ), 'My Wishlist' );
		}

		$out = '';
		foreach ( $links as $link ) {
			$out .= sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $link[0] ), esc_html( $link[1] ) );
		}
		return $out;
	}

	/**
	 * Just two ways to reach a human: call, or WhatsApp - both using the
	 * same default rider number set under WooCommerce > WhatsApp
	 * Dispatch, since that's the only contact number the plugin already
	 * asks a store owner to configure (rather than inventing a second
	 * settings field for the same thing). Prints nothing if that number
	 * was never set, so the column doesn't show dead links.
	 */
	private function help_center_links() {
		$phone = get_option( 'fmke_default_rider_phone', '' );
		if ( ! $phone ) {
			return '';
		}

		$digits = preg_replace( '/[^0-9]/', '', $phone );

		$links = array(
			array( 'tel:+' . $digits, 'Call Us' ),
			array( 'https://wa.me/' . $digits, 'WhatsApp Us' ),
		);

		$out = '';
		foreach ( $links as $link ) {
			$out .= sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $link[0] ), esc_html( $link[1] ) );
		}
		return $out;
	}

	/**
	 * The distinct cities the marketplace actually delivers in, derived
	 * from active vendors' own Dokan store addresses - the same field
	 * class-featured-vendors.php reads for its "📍 city" badge - rather
	 * than a hardcoded list that could go stale as vendors join or leave.
	 * Cached for an hour (transient) since it means scanning every
	 * seller, and this footer renders on every page.
	 */
	private function supported_cities() {
		if ( ! function_exists( 'dokan_get_sellers' ) ) {
			return '';
		}

		$cities = get_transient( 'fmke_supported_cities' );

		if ( false === $cities ) {
			$sellers = dokan_get_sellers( array( 'number' => 200 ) );
			$sellers = is_array( $sellers ) && isset( $sellers['users'] ) ? $sellers['users'] : array();

			$cities = array();
			foreach ( $sellers as $seller ) {
				$store_info = function_exists( 'dokan_get_store_info' ) ? dokan_get_store_info( $seller->ID ) : array();
				if ( ! empty( $store_info['address']['city'] ) ) {
					$cities[] = $store_info['address']['city'];
				}
			}

			$cities = array_unique( $cities );
			sort( $cities );

			set_transient( 'fmke_supported_cities', $cities, HOUR_IN_SECONDS );
		}

		if ( empty( $cities ) ) {
			return '';
		}

		$out = '<div class="fmke-footer-cities">';
		foreach ( $cities as $city ) {
			$out .= '<span class="fmke-footer-city-badge">📍 ' . esc_html( $city ) . '</span>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * Only shows a payment method's badge when that gateway is actually
	 * enabled in WooCommerce > Settings > Payments right now, same
	 * "check availability, don't assume" approach as
	 * FMKE_Service_Badges::mpesa_is_applicable().
	 */
	private function payment_badges() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return '';
		}
		$available = WC()->payment_gateways->get_available_payment_gateways();
		$badges    = array();

		if ( isset( $available['mpesa_stk'] ) ) {
			$badges[] = 'M-Pesa';
		}
		if ( isset( $available['fmke_hosted_checkout'] ) ) {
			$badges[] = 'Card / Bank';
		}

		if ( empty( $badges ) ) {
			return '';
		}

		$out = '<div class="fmke-footer-payments">';
		foreach ( $badges as $badge ) {
			$out .= '<span class="fmke-footer-payment-badge">' . esc_html( $badge ) . '</span>';
		}
		$out .= '</div>';

		return $out;
	}

	public function enqueue() {
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-site-footer {
	background: #1F3D2C;
	color: #EAE6DD;
	padding: 40px 24px 20px;
	margin-top: 32px;
}
.fmke-footer-columns {
	display: flex;
	flex-wrap: wrap;
	gap: 32px;
	max-width: 1200px;
	margin: 0 auto;
}
.fmke-footer-col {
	flex: 1 1 200px;
	min-width: 160px;
}
.fmke-footer-brand {
	font-size: 18px;
	font-weight: 700;
	margin: 0 0 8px;
	color: #fff;
}
.fmke-footer-tagline {
	font-size: 13px;
	line-height: 1.6;
	color: #C9C2B3;
	margin: 0;
}
.fmke-footer-heading {
	font-size: 13px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: #fff;
	margin: 0 0 12px;
}
.fmke-footer-links {
	list-style: none;
	margin: 0;
	padding: 0;
}
.fmke-footer-links li {
	margin-bottom: 8px;
}
.fmke-footer-links a {
	color: #C9C2B3;
	text-decoration: none;
	font-size: 13px;
}
.fmke-footer-links a:hover {
	color: #fff;
	text-decoration: underline;
}
.fmke-footer-payments {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
.fmke-footer-payment-badge {
	display: inline-block;
	padding: 4px 10px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 600;
	color: #1F3D2C;
	background: #EAE6DD;
}
.fmke-footer-cities {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
.fmke-footer-city-badge {
	display: inline-block;
	padding: 4px 10px;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 600;
	color: #EAE6DD;
	background: rgba(255,255,255,0.12);
}
.fmke-footer-bottom {
	max-width: 1200px;
	margin: 28px auto 0;
	padding-top: 16px;
	border-top: 1px solid rgba(255,255,255,0.15);
	text-align: center;
}
.fmke-footer-bottom p {
	font-size: 12px;
	color: #C9C2B3;
	margin: 0;
}
CSS;
	}
}

new FMKE_Site_Footer();
