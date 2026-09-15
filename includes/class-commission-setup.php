<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets Dokan's global commission to: Admin 20% / Vendor 80%.
 *
 * Dokan stores this under the 'dokan_selling_commission' option as of
 * Dokan Lite 3.x. If your Dokan version stores it differently, just
 * set it manually once at:
 * WP Admin > Dokan > Settings > Selling Options > Commission.
 * This function only sets a sane default on plugin activation and
 * will NOT overwrite a commission you've already configured.
 */
function fmke_set_default_commission() {

	$existing = get_option( 'dokan_selling_commission' );
	if ( ! empty( $existing ) ) {
		return; // Don't override an admin who already configured this.
	}

	$commission_settings = array(
		'commission_type'    => 'percentage', // percentage | flat | combine
		'admin_percentage'   => 20,           // Admin/platform keeps 20%
		'admin_flat'         => 0,
		'commission_source'  => 'admin', // Admin (platform) sets the commission.
	);

	update_option( 'dokan_selling_commission', $commission_settings );

	// Some Dokan versions also read a flat 'admin_commission_type' / 'admin_percentage'
	// pair directly. Set both for compatibility across versions.
	update_option( 'admin_commission_type', 'percentage' );
	update_option( 'admin_percentage', 20 );
}

/**
 * Admin notice reminder to double-check commission settings, since Dokan
 * option keys can vary slightly between versions/updates.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( get_option( 'fmke_commission_notice_dismissed' ) ) {
		return;
	}
	echo '<div class="notice notice-info is-dismissible"><p><strong>Flower Marketplace KE:</strong> Default commission set to Admin 20% / Vendor 80%. Please verify this at Dokan &gt; Settings &gt; Selling Options &gt; Commission to make sure it matches your installed Dokan version.</p></div>';
} );
