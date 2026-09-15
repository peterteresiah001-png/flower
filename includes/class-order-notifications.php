<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email notifications when a new order comes in.
 *
 *   - Admin gets ONE email per checkout, covering the whole order with a
 *     per-vendor breakdown (so a multi-vendor cart doesn't produce five
 *     separate admin emails for one customer purchase).
 *   - Each vendor gets their OWN email, containing only their sub-order's
 *     items and total - never the rest of the customer's cart - sent off
 *     Dokan's own per-vendor split hook so it always matches exactly what
 *     that vendor will see on their dashboard/Earnings page.
 *
 * This is independent of (and may run alongside) Dokan Lite's own built-in
 * "New order" vendor email if that's still enabled under WooCommerce >
 * Settings > Emails - disable that one there if getting two vendor emails
 * per order isn't wanted. This plugin's version exists because Dokan's
 * default doesn't include delivery address/customer phone in one place
 * the way this marketplace's dispatch workflow needs.
 */
class FMKE_Order_Notifications {

	public function __construct() {
		// Fires once per checkout, after Dokan has already split the
		// cart into per-vendor sub-orders (Dokan hooks this same action
		// at a much lower priority number, so a very late priority here
		// guarantees the split - and dokan_orders sync rows - exist by
		// the time this runs).
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'notify_admin' ), 999999 );

		// Fires once per vendor sub-order, right after Dokan creates it.
		add_action( 'dokan_checkout_update_order_meta', array( $this, 'notify_vendor' ), 20, 2 );
	}

	// -----------------------------------------------------------------
	// Shared helpers
	// -----------------------------------------------------------------

	/**
	 * Vendor (seller) user ID that owns a product - Dokan stores this as
	 * the product's post_author, same relationship used elsewhere in this
	 * plugin (see FMKE_Vendor_Reviews).
	 */
	private function get_product_vendor_id( $product_id ) {
		return (int) get_post_field( 'post_author', $product_id );
	}

	private function format_items( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$lines[] = '  - ' . $item->get_quantity() . ' x ' . $item->get_name()
				. ' (' . wp_strip_all_tags( wc_price( $order->get_line_total( $item, false, false ) ) ) . ')';
		}
		return implode( "\n", $lines );
	}

	private function format_customer_block( $order ) {
		$address = $order->get_formatted_shipping_address();
		$address = $address ? wp_strip_all_tags( $address ) : $order->get_formatted_billing_address();

		$lines = array(
			'Customer: ' . $order->get_formatted_billing_full_name(),
			'Phone: ' . $order->get_billing_phone(),
			'Deliver to: ' . $address,
		);

		if ( $order->get_customer_note() ) {
			$lines[] = 'Note: ' . $order->get_customer_note();
		}

		return implode( "\n", $lines );
	}

	/**
	 * A vendor's net (post-commission) amount for one sub-order.
	 *
	 * Reads Dokan's own `dokan_orders` sync table first - the same
	 * source the Earnings/Withdrawals pages use, so the figure always
	 * agrees with what's shown there. Falls back to computing it from
	 * the site's default commission percentage only if that row isn't
	 * there yet (e.g. a very fast async Dokan setup), so the email
	 * never has to skip the figure entirely.
	 */
	private function get_vendor_net_amount( $sub_order_id, $order_total ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dokan_orders';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists ) {
			$net = $wpdb->get_var( $wpdb->prepare( "SELECT net_amount FROM {$table} WHERE order_id = %d", $sub_order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			if ( null !== $net ) {
				return (float) $net;
			}
		}

		$admin_percentage = (float) get_option( 'admin_percentage', 20 );
		return round( $order_total * ( 1 - ( $admin_percentage / 100 ) ), 2 );
	}

	// -----------------------------------------------------------------
	// Admin: one summary email per checkout
	// -----------------------------------------------------------------

	public function notify_admin( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// This fires on the parent order; if Dokan's split has already
		// re-pointed sub-orders as separate posts, get_items() on the
		// parent still returns the full original line list, which is
		// exactly what a whole-order admin summary needs.
		$vendor_groups = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$vendor_id = $this->get_product_vendor_id( $product->get_id() );
			if ( ! isset( $vendor_groups[ $vendor_id ] ) ) {
				$vendor_groups[ $vendor_id ] = array();
			}
			$vendor_groups[ $vendor_id ][] = '  - ' . $item->get_quantity() . ' x ' . $item->get_name()
				. ' (' . wp_strip_all_tags( wc_price( $order->get_line_total( $item, false, false ) ) ) . ')';
		}

		$breakdown = array();
		foreach ( $vendor_groups as $vendor_id => $lines ) {
			$vendor       = $vendor_id ? get_userdata( $vendor_id ) : false;
			$vendor_label = $vendor ? $vendor->display_name : 'Unknown vendor';
			$breakdown[]  = $vendor_label . ":\n" . implode( "\n", $lines );
		}

		$message  = "New order #" . $order->get_id() . "\n\n";
		$message .= $this->format_customer_block( $order ) . "\n\n";
		$message .= "Payment method: " . $order->get_payment_method_title() . "\n";
		$message .= "Order total: " . wp_strip_all_tags( $order->get_formatted_order_total() ) . "\n\n";
		$message .= "Items by vendor:\n" . implode( "\n\n", $breakdown ) . "\n\n";
		$message .= 'View order: ' . admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );

		wp_mail(
			get_option( 'admin_email' ),
			sprintf( 'New order #%1$s - %2$s', $order->get_id(), wp_strip_all_tags( $order->get_formatted_order_total() ) ),
			$message
		);
	}

	// -----------------------------------------------------------------
	// Vendor: one email per sub-order
	// -----------------------------------------------------------------

	/**
	 * @param int $sub_order_id The vendor-specific order Dokan just created.
	 * @param int $seller_id    That vendor's user ID.
	 */
	public function notify_vendor( $sub_order_id, $seller_id ) {
		$order = wc_get_order( $sub_order_id );
		if ( ! $order ) {
			return;
		}

		$vendor = get_userdata( $seller_id );
		if ( ! $vendor || ! $vendor->user_email ) {
			return;
		}

		$net = $this->get_vendor_net_amount( $sub_order_id, (float) $order->get_total() );

		$message  = "You have a new order, " . $vendor->display_name . "!\n\n";
		$message .= "Order #" . $order->get_id() . "\n\n";
		$message .= $this->format_customer_block( $order ) . "\n\n";
		$message .= "Items:\n" . $this->format_items( $order ) . "\n\n";
		$message .= "Order total: " . wp_strip_all_tags( $order->get_formatted_order_total() ) . "\n";
		$message .= "Your earnings (after commission): " . wp_strip_all_tags( wc_price( $net ) ) . "\n\n";
		$message .= 'View and dispatch this order: ' . dokan_get_navigation_url( 'orders' ) . '?order_id=' . $order->get_id();

		wp_mail(
			$vendor->user_email,
			sprintf( 'New order #%1$s on your store', $order->get_id() ),
			$message
		);
	}
}

new FMKE_Order_Notifications();
