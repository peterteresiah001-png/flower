<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails people when an order's status *changes* - separate from
 * FMKE_Order_Notifications (class-order-notifications.php), which only
 * covers the moment an order is first placed. This one covers everything
 * that happens after: payment confirming, a vendor marking something
 * completed, or an order getting cancelled/refunded.
 *
 * Three audiences, each only for the statuses that actually matter to
 * them:
 *
 *   - Customer: told whenever their order reaches processing, on-hold,
 *     completed, cancelled, refunded, or failed - the moments a shopper
 *     actually cares about.
 *   - Vendor: told when *their* sub-order is confirmed paid (pending/
 *     on-hold -> processing - the actual "go prepare this" signal, since
 *     M-Pesa STK payments confirm asynchronously after the order was
 *     already created) or when it's completed/cancelled/refunded/failed.
 *     Skipped if the vendor themselves is the one who just made the
 *     change from their own dashboard - no need to tell someone about
 *     their own click.
 *   - Admin: only alerted for cancelled/refunded/failed - the cases that
 *     may need the marketplace to actually step in - not routine
 *     processing/completed updates the vendor and customer already got.
 *
 * On a Dokan store, an order with items from multiple vendors becomes one
 * parent order plus one real WC_Order per vendor. This only acts on the
 * per-vendor sub-orders (or a plain single-vendor order, which has no
 * sub-orders at all) and skips the parent order itself once it has been
 * split, so a multi-vendor purchase doesn't produce one confusing email
 * for the "whole" order on top of the per-vendor ones.
 */
class FMKE_Order_Status_Notifications {

	/** Statuses (without the wc- prefix) worth telling the customer about. */
	const CUSTOMER_STATUSES = array( 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );

	/** Statuses worth telling the vendor about. */
	const VENDOR_STATUSES = array( 'processing', 'completed', 'cancelled', 'refunded', 'failed' );

	/** Statuses that need the marketplace admin's attention. */
	const ADMIN_ALERT_STATUSES = array( 'cancelled', 'refunded', 'failed' );

	const CUSTOMER_PHRASES = array(
		'processing' => 'is confirmed and now being prepared',
		'on-hold'    => 'is on hold - we will update you shortly',
		'completed'  => 'has been completed',
		'cancelled'  => 'has been cancelled',
		'refunded'   => 'has been refunded',
		'failed'     => 'could not be processed',
	);

	const VENDOR_PHRASES = array(
		'processing' => 'Payment is confirmed - please prepare this order for dispatch.',
		'completed'  => 'This order has been marked completed.',
		'cancelled'  => 'This order has been cancelled.',
		'refunded'   => 'This order has been refunded.',
		'failed'     => 'Payment failed for this order.',
	);

	public function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle' ), 10, 4 );
	}

	public function handle( $order_id, $old_status, $new_status, $order = null ) {
		if ( $old_status === $new_status ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// Parent order of an already-split multi-vendor purchase - the
		// individual vendor sub-orders each fire this same hook on their
		// own, so acting here too would duplicate every notification.
		if ( 'yes' === $order->get_meta( 'has_sub_order' ) ) {
			return;
		}

		$seller_id = $this->resolve_seller_id( $order );

		$this->maybe_notify_customer( $order, $new_status, $seller_id );
		$this->maybe_notify_vendor( $order, $new_status, $seller_id );
		$this->maybe_notify_admin( $order, $old_status, $new_status, $seller_id );
	}

	// -----------------------------------------------------------------
	// Vendor resolution
	// -----------------------------------------------------------------

	/**
	 * @return int Seller/vendor user ID, or 0 if this isn't a Dokan
	 *             vendor sub-order (e.g. a non-marketplace product, or
	 *             Dokan isn't active).
	 */
	private function resolve_seller_id( $order ) {
		$order_id = $order->get_id();

		if ( function_exists( 'dokan_get_seller_id_by_order' ) ) {
			$seller_id = (int) dokan_get_seller_id_by_order( $order_id );
			if ( $seller_id ) {
				return $seller_id;
			}
		}

		$meta_vendor_id = (int) $order->get_meta( '_dokan_vendor_id' );
		if ( $meta_vendor_id ) {
			return $meta_vendor_id;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dokan_orders';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$seller_id = $wpdb->get_var( $wpdb->prepare( "SELECT seller_id FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			if ( $seller_id ) {
				return (int) $seller_id;
			}
		}

		return 0;
	}

	// -----------------------------------------------------------------
	// Shared formatting
	// -----------------------------------------------------------------

	private function format_items( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$lines[] = '  - ' . $item->get_quantity() . ' x ' . $item->get_name();
		}
		return implode( "\n", $lines );
	}

	// -----------------------------------------------------------------
	// Customer
	// -----------------------------------------------------------------

	private function maybe_notify_customer( $order, $new_status, $seller_id ) {
		if ( ! isset( self::CUSTOMER_PHRASES[ $new_status ] ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email ) {
			return;
		}

		$vendor_suffix = '';
		if ( $seller_id ) {
			$vendor = get_userdata( $seller_id );
			if ( $vendor ) {
				$vendor_suffix = ' from ' . $vendor->display_name;
			}
		}

		$message  = 'Hi ' . $order->get_billing_first_name() . ",\n\n";
		$message .= 'Your order #' . $order->get_id() . $vendor_suffix . ' ' . self::CUSTOMER_PHRASES[ $new_status ] . ".\n\n";
		$message .= "Items:\n" . $this->format_items( $order ) . "\n\n";
		$message .= 'Order total: ' . wp_strip_all_tags( $order->get_formatted_order_total() ) . "\n\n";
		$message .= 'View your order: ' . $order->get_view_order_url();

		wp_mail(
			$email,
			sprintf( 'Order #%1$s update: %2$s', $order->get_id(), wc_get_order_status_name( 'wc-' . $new_status ) ),
			$message
		);
	}

	// -----------------------------------------------------------------
	// Vendor
	// -----------------------------------------------------------------

	private function maybe_notify_vendor( $order, $new_status, $seller_id ) {
		if ( ! $seller_id || ! isset( self::VENDOR_PHRASES[ $new_status ] ) ) {
			return;
		}

		// Don't tell a vendor about a status they just set themselves
		// from their own dashboard.
		if ( get_current_user_id() === $seller_id ) {
			return;
		}

		$vendor = get_userdata( $seller_id );
		if ( ! $vendor || ! $vendor->user_email ) {
			return;
		}

		$message  = 'Order #' . $order->get_id() . " status update\n\n";
		$message .= self::VENDOR_PHRASES[ $new_status ] . "\n\n";
		$message .= 'Customer: ' . $order->get_formatted_billing_full_name() . "\n";
		$message .= 'Phone: ' . $order->get_billing_phone() . "\n\n";
		$message .= "Items:\n" . $this->format_items( $order ) . "\n\n";
		$message .= 'Order total: ' . wp_strip_all_tags( $order->get_formatted_order_total() ) . "\n\n";

		if ( function_exists( 'dokan_get_navigation_url' ) ) {
			$message .= 'View this order: ' . dokan_get_navigation_url( 'orders' ) . '?order_id=' . $order->get_id();
		}

		wp_mail(
			$vendor->user_email,
			sprintf( 'Order #%1$s: %2$s', $order->get_id(), wc_get_order_status_name( 'wc-' . $new_status ) ),
			$message
		);
	}

	// -----------------------------------------------------------------
	// Admin
	// -----------------------------------------------------------------

	private function maybe_notify_admin( $order, $old_status, $new_status, $seller_id ) {
		if ( ! in_array( $new_status, self::ADMIN_ALERT_STATUSES, true ) ) {
			return;
		}

		$vendor_line = '';
		if ( $seller_id ) {
			$vendor = get_userdata( $seller_id );
			$vendor_line = 'Vendor: ' . ( $vendor ? $vendor->display_name : "#{$seller_id}" ) . "\n";
		}

		$message  = 'Order #' . $order->get_id() . ' changed from ' . wc_get_order_status_name( 'wc-' . $old_status )
			. ' to ' . wc_get_order_status_name( 'wc-' . $new_status ) . ".\n\n";
		$message .= $vendor_line;
		$message .= 'Customer: ' . $order->get_formatted_billing_full_name() . "\n";
		$message .= 'Order total: ' . wp_strip_all_tags( $order->get_formatted_order_total() ) . "\n\n";
		$message .= 'View order: ' . admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );

		wp_mail(
			get_option( 'admin_email' ),
			sprintf( 'Order #%1$s marked %2$s - review needed', $order->get_id(), wc_get_order_status_name( 'wc-' . $new_status ) ),
			$message
		);
	}
}

new FMKE_Order_Status_Notifications();
