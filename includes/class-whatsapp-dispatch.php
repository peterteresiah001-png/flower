<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Dispatch via WhatsApp" box to the order edit screen (both WP Admin
 * and Dokan's vendor dashboard order view, since Dokan re-uses core WC order
 * data). No API needed - it just builds a wa.me deep link pre-filled with
 * the order details so the vendor/admin can tap it and manually forward the
 * job to a rider's WhatsApp. This keeps dispatch fully manual, as requested.
 */
class FMKE_Whatsapp_Dispatch {

	public function __construct() {
		// WP Admin order screen (classic).
		add_action( 'add_meta_boxes', array( $this, 'add_admin_meta_box' ) );

		// Save the rider phone number entered against the order.
		add_action( 'save_post_shop_order', array( $this, 'save_rider_phone' ) );

		// Dokan vendor dashboard order details page.
		add_action( 'dokan_order_details_after_shipping_address', array( $this, 'render_dokan_dispatch_box' ) );
		add_action( 'dokan_order_details_updated', array( $this, 'save_rider_phone_dokan' ) );

		// Store settings: default rider number.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_admin_meta_box() {
		add_meta_box(
			'fmke_whatsapp_dispatch',
			'Dispatch via WhatsApp',
			array( $this, 'render_admin_meta_box' ),
			'shop_order',
			'side',
			'high'
		);
	}

	/**
	 * Guarded with class_exists rather than called directly, so this file
	 * keeps working on its own if the store-pickup add-on is ever removed.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function is_pickup_order( $order ) {
		return class_exists( 'FMKE_Store_Pickup' ) && FMKE_Store_Pickup::order_is_pickup( $order );
	}

	private function build_message( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_quantity() . ' x ' . $item->get_name();
		}

		$address = $order->get_formatted_shipping_address();
		$address = $address ? wp_strip_all_tags( $address ) : $order->get_billing_address_1();

		$message  = "New Order #" . $order->get_id() . " for dispatch\n";
		$message .= "Customer: " . $order->get_formatted_billing_full_name() . "\n";
		$message .= "Phone: " . $order->get_billing_phone() . "\n";
		$message .= "Items: " . implode( ', ', $items ) . "\n";
		$message .= "Deliver to: " . $address . "\n";
		$message .= "Total: " . strip_tags( $order->get_formatted_order_total() ) . "\n";

		if ( $order->get_customer_note() ) {
			$message .= "Note: " . $order->get_customer_note() . "\n";
		}

		return $message;
	}

	private function build_wa_link( $rider_phone, $message ) {
		$rider_phone = preg_replace( '/[^0-9]/', '', $rider_phone );
		return 'https://wa.me/' . $rider_phone . '?text=' . rawurlencode( $message );
	}

	public function render_admin_meta_box( $post ) {
		$order = wc_get_order( $post->ID );
		if ( ! $order ) {
			return;
		}

		// Store Pickup orders have no delivery leg - the customer is walking
		// in to collect. Offering a rider hand-off here would be an easy way
		// to accidentally dispatch a bouquet to a customer who is already on
		// their way to the shop for it. See class-store-pickup.php.
		if ( $this->is_pickup_order( $order ) ) {
			echo '<p><strong>Store Pickup order.</strong> The customer is collecting this from the store in person, so there is nothing to dispatch.</p>';
			return;
		}

		$rider_phone = $order->get_meta( '_fmke_rider_phone' );
		$default_rider = get_option( 'fmke_default_rider_phone', '' );
		$phone_value = $rider_phone ?: $default_rider;

		wp_nonce_field( 'fmke_save_rider_phone', 'fmke_rider_nonce' );

		echo '<p><label>Rider WhatsApp Number (2547XXXXXXXX)</label><br />';
		echo '<input type="text" name="fmke_rider_phone" value="' . esc_attr( $phone_value ) . '" style="width:100%" /></p>';
		echo '<p><em>Save the order first, then click below to open WhatsApp with the order details pre-filled.</em></p>';

		if ( $phone_value ) {
			$link = $this->build_wa_link( $phone_value, $this->build_message( $order ) );
			echo '<p><a href="' . esc_url( $link ) . '" target="_blank">Open WhatsApp to dispatch this order</a></p>';
		}
	}

	public function save_rider_phone( $post_id ) {
		if ( ! isset( $_POST['fmke_rider_nonce'] ) || ! wp_verify_nonce( $_POST['fmke_rider_nonce'], 'fmke_save_rider_phone' ) ) {
			return;
		}
		if ( isset( $_POST['fmke_rider_phone'] ) ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				$order->update_meta_data( '_fmke_rider_phone', sanitize_text_field( $_POST['fmke_rider_phone'] ) );
				$order->save();
			}
		}
	}

	/**
	 * Dokan vendor dashboard equivalent.
	 */
	public function render_dokan_dispatch_box( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $this->is_pickup_order( $order ) ) {
			echo '<div class="dokan-order-history-panel">';
			echo '<h3>Store Pickup</h3>';
			echo '<p>This customer is collecting in person - no rider needed. Mark the order complete once they have picked it up.</p>';
			echo '</div>';
			return;
		}

		$rider_phone = $order->get_meta( '_fmke_rider_phone' );
		$default_rider = get_option( 'fmke_default_rider_phone', '' );
		$phone_value = $rider_phone ?: $default_rider;

		echo '<div class="dokan-order-history-panel">';
		echo '<h3>Dispatch via WhatsApp</h3>';
		echo '<form method="post">';
		wp_nonce_field( 'fmke_dokan_rider_phone' );
		echo '<label>Rider WhatsApp Number</label><br />';
		echo '<input type="text" name="fmke_rider_phone" value="' . esc_attr( $phone_value ) . '" /> ';
		echo '<button type="submit" name="fmke_update_rider" value="1">Save Number</button>';
		echo '</form>';

		if ( $phone_value ) {
			$link = $this->build_wa_link( $phone_value, $this->build_message( $order ) );
			echo '<p><a href="' . esc_url( $link ) . '" target="_blank">Open WhatsApp to dispatch this order</a></p>';
		}
		echo '</div>';
	}

	public function save_rider_phone_dokan( $order_id ) {
		if ( ! isset( $_POST['fmke_update_rider'], $_POST['fmke_rider_phone'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'fmke_dokan_rider_phone' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_fmke_rider_phone', sanitize_text_field( $_POST['fmke_rider_phone'] ) );
			$order->save();
		}
	}

	/**
	 * Simple settings page: default rider WhatsApp number used when an
	 * order doesn't have one set yet.
	 */
	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			'WhatsApp Dispatch',
			'WhatsApp Dispatch',
			'manage_woocommerce',
			'fmke-whatsapp-dispatch',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'fmke_whatsapp_settings', 'fmke_default_rider_phone' );
	}

	public function render_settings_page() {
		echo '<div class="wrap"><h1>WhatsApp Dispatch Settings</h1><form method="post" action="options.php">';
		settings_fields( 'fmke_whatsapp_settings' );
		do_settings_sections( 'fmke_whatsapp_settings' );
		echo '<p><label>Default Rider WhatsApp Number</label><br />';
		echo '<input type="text" name="fmke_default_rider_phone" value="' . esc_attr( get_option( 'fmke_default_rider_phone', '' ) ) . '" placeholder="2547XXXXXXXX" /></p>';
		submit_button();
		echo '</form></div>';
	}
}
