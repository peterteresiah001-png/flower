<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account dropdown in the header, next to the cart.
 *
 * Guests see Login / Register.
 * Logged-in customers see My Account, Orders, Logout.
 * Logged-in vendors additionally see a Vendor Dashboard link.
 *
 * Hooks into Storefront's 'storefront_header' action, so it doesn't
 * require editing any theme template files.
 */
class FMKE_Account_Dropdown {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'storefront_header', array( $this, 'render' ), 65 );
	}

	public function enqueue() {
		wp_add_inline_script( 'jquery', $this->get_inline_js(), 'after' );
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	public function render() {
		$myaccount_url = function_exists( 'wc_get_page_permalink' )
			? wc_get_page_permalink( 'myaccount' )
			: home_url( '/my-account/' );

		echo '<div class="fmke-account-dropdown">';
		echo '<button type="button" class="fmke-account-toggle" aria-expanded="false">';
		echo '<span class="fmke-account-icon" aria-hidden="true">&#9679;</span>';

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			echo '<span class="fmke-account-label">' . esc_html( $user->display_name ) . '</span>';
		} else {
			echo '<span class="fmke-account-label">Account</span>';
		}

		echo '</button>';
		echo '<div class="fmke-account-panel">';

		if ( is_user_logged_in() ) {
			echo '<a href="' . esc_url( $myaccount_url ) . '">My Account</a>';
			echo '<a href="' . esc_url( $myaccount_url . 'orders/' ) . '">My Orders</a>';

			if ( function_exists( 'dokan_is_user_seller' ) && dokan_is_user_seller( get_current_user_id() ) ) {
				$dashboard_url = function_exists( 'dokan_get_navigation_url' )
					? dokan_get_navigation_url()
					: home_url( '/dashboard/' );
				echo '<a href="' . esc_url( $dashboard_url ) . '">Vendor Dashboard</a>';
			}

			echo '<a href="' . esc_url( wp_logout_url( home_url() ) ) . '">Log Out</a>';
		} else {
			echo '<a href="' . esc_url( $myaccount_url ) . '">Log In</a>';
			echo '<a href="' . esc_url( $myaccount_url ) . '">Register</a>';
		}

		echo '</div>';
		echo '</div>';
	}

	private function get_inline_js() {
		return <<<JS
jQuery(function ($) {
	$('.fmke-account-toggle').on('click', function (e) {
		e.stopPropagation();
		var \$btn = $(this);
		var expanded = \$btn.attr('aria-expanded') === 'true';
		$('.fmke-account-toggle').attr('aria-expanded', 'false');
		$('.fmke-account-panel').removeClass('is-open');
		if (!expanded) {
			\$btn.attr('aria-expanded', 'true');
			\$btn.next('.fmke-account-panel').addClass('is-open');
		}
	});
	$(document).on('click', function () {
		$('.fmke-account-toggle').attr('aria-expanded', 'false');
		$('.fmke-account-panel').removeClass('is-open');
	});
});
JS;
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-account-dropdown {
	position: relative;
	display: inline-block;
	font-family: 'General Sans', sans-serif;
}
.fmke-account-toggle {
	display: flex;
	align-items: center;
	gap: 6px;
	background: none;
	border: none;
	cursor: pointer;
	padding: 8px 10px;
	color: #1F3D2C;
	font-size: 14px;
	font-weight: 500;
}
.fmke-account-icon {
	font-size: 10px;
	color: #C9A227;
}
.fmke-account-panel {
	display: none;
	position: absolute;
	top: 100%;
	right: 0;
	min-width: 170px;
	background: #fff;
	border: 1px solid #E8B4BC;
	border-radius: 10px;
	box-shadow: 0 8px 24px rgba(31,61,44,0.12);
	margin-top: 6px;
	overflow: hidden;
	z-index: 200;
}
.fmke-account-panel.is-open {
	display: block;
}
.fmke-account-panel a {
	display: block;
	padding: 10px 14px;
	text-decoration: none;
	color: #1F3D2C;
	font-size: 14px;
	border-bottom: 1px solid #f2f2f2;
}
.fmke-account-panel a:last-child {
	border-bottom: none;
}
.fmke-account-panel a:hover {
	background: #FBF7F0;
	color: #7A2048;
}
@media (max-width: 768px) {
	.fmke-account-label { display: none; }
}
CSS;
	}
}

new FMKE_Account_Dropdown();
