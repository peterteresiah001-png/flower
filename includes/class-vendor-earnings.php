<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Earnings" page on the Dokan vendor dashboard.
 *
 * Dokan Lite shows a vendor their sales chart but no breakdown of what
 * the marketplace actually kept - which is the first question a vendor
 * asks on an 80/20 split. This adds a dedicated Earnings page with:
 *
 *   - Four summary cards for the selected period: gross sales, platform
 *     commission (20%), net earnings (the vendor's 80%), and order count.
 *   - A per-order table: order number, date, status, gross, commission,
 *     net - so the vendor can see exactly how any one order was split.
 *
 * Numbers come from Dokan's own `dokan_orders` sync table (the same one
 * its dashboard and admin reports read), so they always agree with what
 * Dokan itself reports - the commission is read back per order rather
 * than re-applying 20% in code, which means it stays correct for orders
 * placed before the split was changed, and for any vendor on a custom
 * commission.
 *
 * Cancelled, refunded and failed orders are excluded, since nothing was
 * earned on them. Everything else (processing, on-hold, completed) is
 * counted, with the status shown per row so a vendor can tell settled
 * money from money still in flight.
 *
 * Vendors only ever see their own rows: every query is constrained to
 * seller_id = the logged-in user, and the page itself is behind Dokan's
 * own dashboard permission check.
 */
class FMKE_Vendor_Earnings {

	/** Dashboard URL segment, e.g. /dashboard/earnings/ */
	const QUERY_VAR = 'earnings';

	/** Order statuses that never count as earnings. */
	const EXCLUDED_STATUSES = array( 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-pending' );

	/** Selectable periods: key => array( label, days ). 0 days = all time. */
	const RANGES = array(
		'7'   => array( 'label' => 'Last 7 days', 'days' => 7 ),
		'30'  => array( 'label' => 'Last 30 days', 'days' => 30 ),
		'90'  => array( 'label' => 'Last 90 days', 'days' => 90 ),
		'all' => array( 'label' => 'All time', 'days' => 0 ),
	);

	const DEFAULT_RANGE = '30';

	/** Max rows in the per-order table. */
	const ROW_LIMIT = 50;

	public function __construct() {
		add_filter( 'dokan_query_var_filter', array( $this, 'register_query_var' ) );
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'register_nav_item' ) );
		add_action( 'dokan_load_custom_template', array( $this, 'maybe_render_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// Dokan builds the /dashboard/<var>/ rewrite rules from the
		// query vars filtered above, so adding one needs a flush. Done
		// once, flagged by version, rather than on every request.
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
	}

	public function maybe_flush_rewrites() {
		if ( '1' === get_option( 'fmke_earnings_rewrites' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'fmke_earnings_rewrites', '1' );
	}

	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Slots in just after Dokan's own "Orders" item, since earnings are
	 * the natural next question after looking at an order list.
	 */
	public function register_nav_item( $urls ) {
		$urls[ self::QUERY_VAR ] = array(
			'title' => 'Earnings',
			'icon'  => '<i class="fa fa-money"></i>',
			'url'   => dokan_get_navigation_url( self::QUERY_VAR ),
			'pos'   => 55, // Dokan: Orders is 50, Coupons 60.
		);
		return $urls;
	}

	/**
	 * Dokan fires this for any query var it doesn't recognise, so bail
	 * unless it's ours.
	 */
	public function maybe_render_page( $query_vars ) {
		if ( ! isset( $query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		$this->render_page();
	}

	private function is_earnings_page() {
		return function_exists( 'dokan_is_seller_dashboard' )
			&& dokan_is_seller_dashboard()
			&& '' !== get_query_var( self::QUERY_VAR, '' );
	}

	// -----------------------------------------------------------------
	// Data
	// -----------------------------------------------------------------

	/**
	 * @return string One of the RANGES keys.
	 */
	private function get_current_range() {
		$range = isset( $_GET['fmke_range'] ) ? sanitize_text_field( wp_unslash( $_GET['fmke_range'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change.
		return isset( self::RANGES[ $range ] ) ? $range : self::DEFAULT_RANGE;
	}

	/**
	 * Does Dokan's order sync table exist? On a fresh install it's
	 * created by Dokan itself; if it's missing there's nothing sensible
	 * to report and we say so rather than printing zeroes.
	 */
	private function table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . 'dokan_orders';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Fetches this vendor's orders for the period, newest first.
	 *
	 * Joins `posts` for the order date - `dokan_orders` doesn't store
	 * one. HPOS note: on stores with High-Performance Order Storage
	 * enabled the posts row still exists for synced orders, but if you
	 * ever switch to HPOS-only (no posts table sync), swap the join to
	 * {$wpdb->prefix}wc_orders.date_created_gmt.
	 *
	 * @param int $days 0 for all time.
	 * @return array Row objects: order_id, order_total, net_amount, order_status, order_date.
	 */
	private function get_orders( $days ) {
		global $wpdb;

		$seller_id = get_current_user_id();
		$table     = $wpdb->prefix . 'dokan_orders';

		$excluded     = self::EXCLUDED_STATUSES;
		$placeholders = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );

		$sql = "SELECT do.order_id, do.order_total, do.net_amount, do.order_status, p.post_date
			FROM {$table} AS do
			INNER JOIN {$wpdb->posts} AS p ON p.ID = do.order_id
			WHERE do.seller_id = %d
			AND do.order_status NOT IN ( {$placeholders} )";

		$params = array_merge( array( $seller_id ), $excluded );

		if ( $days > 0 ) {
			$sql     .= ' AND p.post_date >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );
		}

		$sql .= ' ORDER BY p.post_date DESC';

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $orders Rows from get_orders().
	 * @return array gross, commission, net, count
	 */
	private function summarise( $orders ) {
		$gross = 0.0;
		$net   = 0.0;

		foreach ( $orders as $order ) {
			$gross += (float) $order->order_total;
			$net   += (float) $order->net_amount;
		}

		return array(
			'gross'      => $gross,
			'commission' => $gross - $net,
			'net'        => $net,
			'count'      => count( $orders ),
		);
	}

	// -----------------------------------------------------------------
	// Display
	// -----------------------------------------------------------------

	private function render_page() {
		// Dokan's dashboard template already gates this, but the page
		// prints one vendor's money - so check again here rather than
		// inheriting someone else's guard.
		if ( ! current_user_can( 'dokandar' ) ) {
			echo '<div class="dokan-alert dokan-alert-danger">You need a vendor account to view earnings.</div>';
			return;
		}

		$range   = $this->get_current_range();
		$days    = self::RANGES[ $range ]['days'];
		$orders  = $this->table_exists() ? $this->get_orders( $days ) : null;
		$summary = is_array( $orders ) ? $this->summarise( $orders ) : null;
		?>
		<div class="dokan-dashboard-wrap">
			<?php
			do_action( 'dokan_dashboard_content_before' );
			?>
			<div class="dokan-dashboard-content fmke-earnings">

				<article class="dokan-earnings-area">
					<header class="dokan-dashboard-header">
						<h1 class="entry-title">Earnings</h1>
					</header>

					<?php if ( null === $orders ) : ?>

						<div class="dokan-alert dokan-alert-warning">
							Earnings data isn't available yet - Dokan's order table hasn't been
							created on this site. It appears once Dokan has processed its first
							order; if this persists, deactivate and reactivate Dokan Lite.
						</div>

					<?php else : ?>

						<?php $this->render_range_filter( $range ); ?>
						<?php $this->render_summary_cards( $summary ); ?>
						<?php $this->render_orders_table( $orders ); ?>

						<p class="fmke-earnings-footnote">
							Net earnings are what's payable to you after the marketplace
							commission. Cancelled, refunded and failed orders are excluded.
							Orders still in <em>processing</em> or <em>on hold</em> are counted
							here but aren't settled yet.
						</p>

					<?php endif; ?>

				</article>
			</div>

			<?php do_action( 'dokan_dashboard_content_after' ); ?>
		</div>
		<?php
	}

	private function render_range_filter( $current ) {
		echo '<div class="fmke-earnings-ranges">';
		foreach ( self::RANGES as $key => $range ) {
			$url   = add_query_arg( 'fmke_range', $key, dokan_get_navigation_url( self::QUERY_VAR ) );
			$class = $key === $current ? 'fmke-earnings-range is-active' : 'fmke-earnings-range';
			printf(
				'<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				$key === $current ? ' aria-current="page"' : '',
				esc_html( $range['label'] )
			);
		}
		echo '</div>';
	}

	private function render_summary_cards( $summary ) {
		$cards = array(
			array(
				'label' => 'Gross sales',
				'value' => wc_price( $summary['gross'] ),
				'note'  => 'Total value of your orders',
				'tone'  => 'neutral',
			),
			array(
				'label' => 'Marketplace commission',
				'value' => wc_price( $summary['commission'] ),
				'note'  => 'Kept by the marketplace',
				'tone'  => 'muted',
			),
			array(
				'label' => 'Net earnings',
				'value' => wc_price( $summary['net'] ),
				'note'  => 'Payable to you',
				'tone'  => 'accent',
			),
			array(
				'label' => 'Orders',
				'value' => number_format_i18n( $summary['count'] ),
				'note'  => 'Orders in this period',
				'tone'  => 'neutral',
			),
		);

		echo '<div class="fmke-earnings-cards">';
		foreach ( $cards as $card ) {
			printf(
				'<div class="fmke-earnings-card fmke-earnings-card-%1$s">
					<span class="fmke-earnings-card-label">%2$s</span>
					<span class="fmke-earnings-card-value">%3$s</span>
					<span class="fmke-earnings-card-note">%4$s</span>
				</div>',
				esc_attr( $card['tone'] ),
				esc_html( $card['label'] ),
				wp_kses_post( $card['value'] ),
				esc_html( $card['note'] )
			);
		}
		echo '</div>';
	}

	private function render_orders_table( $orders ) {
		if ( empty( $orders ) ) {
			echo '<p class="fmke-earnings-empty">No orders in this period yet.</p>';
			return;
		}

		$rows = array_slice( $orders, 0, self::ROW_LIMIT );
		?>
		<table class="dokan-table fmke-earnings-table">
			<thead>
				<tr>
					<th>Order</th>
					<th>Date</th>
					<th>Status</th>
					<th class="fmke-num">Gross</th>
					<th class="fmke-num">Commission</th>
					<th class="fmke-num">Net</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$gross      = (float) $row->order_total;
				$net        = (float) $row->net_amount;
				$commission = $gross - $net;
				$status     = wc_get_order_status_name( $row->order_status );
				$url        = dokan_get_navigation_url( 'orders' ) . '?order_id=' . absint( $row->order_id );
				?>
				<tr>
					<td><a href="<?php echo esc_url( $url ); ?>">#<?php echo esc_html( $row->order_id ); ?></a></td>
					<td><?php echo esc_html( date_i18n( wc_date_format(), strtotime( $row->post_date ) ) ); ?></td>
					<td><span class="fmke-earnings-status"><?php echo esc_html( $status ); ?></span></td>
					<td class="fmke-num"><?php echo wp_kses_post( wc_price( $gross ) ); ?></td>
					<td class="fmke-num fmke-earnings-commission"><?php echo wp_kses_post( wc_price( $commission ) ); ?></td>
					<td class="fmke-num fmke-earnings-net"><?php echo wp_kses_post( wc_price( $net ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( count( $orders ) > self::ROW_LIMIT ) {
			printf(
				'<p class="fmke-earnings-footnote">Showing the %1$s most recent orders of %2$s in this period. The totals above cover all of them.</p>',
				esc_html( number_format_i18n( self::ROW_LIMIT ) ),
				esc_html( number_format_i18n( count( $orders ) ) )
			);
		}
	}

	public function enqueue() {
		if ( ! $this->is_earnings_page() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-earnings-ranges {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0 0 20px;
}
.fmke-earnings-range {
	padding: 6px 14px;
	border-radius: 999px;
	border: 1px solid #E4DFD3;
	background: #fff;
	color: #1F3D2C;
	font-size: 13px;
	font-weight: 600;
	text-decoration: none;
}
.fmke-earnings-range.is-active {
	background: #1F3D2C;
	border-color: #1F3D2C;
	color: #fff;
}
.fmke-earnings-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 14px;
	margin: 0 0 26px;
}
.fmke-earnings-card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 16px;
	border: 1px solid #E4DFD3;
	border-radius: 8px;
	background: #F7F5F0;
}
.fmke-earnings-card-label {
	font-size: 12px;
	font-weight: 600;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: #5A5648;
}
.fmke-earnings-card-value {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 24px;
	line-height: 1.2;
	color: #1F3D2C;
}
.fmke-earnings-card-accent .fmke-earnings-card-value { color: #7A2048; }
.fmke-earnings-card-muted .fmke-earnings-card-value { color: #5A5648; }
.fmke-earnings-card-note {
	font-size: 12px;
	color: #5A5648;
}
.fmke-earnings-table { width: 100%; }
.fmke-earnings-table .fmke-num { text-align: right; }
.fmke-earnings-commission { color: #5A5648; }
.fmke-earnings-net { font-weight: 600; color: #7A2048; }
.fmke-earnings-status {
	display: inline-block;
	padding: 2px 9px;
	border-radius: 999px;
	border: 1px solid #E4DFD3;
	background: #fff;
	font-size: 12px;
	white-space: nowrap;
}
.fmke-earnings-empty,
.fmke-earnings-footnote {
	font-size: 13px;
	color: #5A5648;
	margin: 14px 0 0;
}

@media (max-width: 600px) {
	.fmke-earnings-table th:nth-child(2),
	.fmke-earnings-table td:nth-child(2) { display: none; }
	.fmke-earnings-card-value { font-size: 20px; }
}
CSS;
	}
}

new FMKE_Vendor_Earnings();
