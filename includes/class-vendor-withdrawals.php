<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor withdrawal requests.
 *
 * Dokan Lite's own withdraw screen assumes PayPal/Skrill/bank payouts an
 * admin processes by hand outside WordPress. Vendors here get paid by
 * M-Pesa, so this adds a parallel, marketplace-owned withdrawal flow:
 *
 *   - A "Withdrawals" page on the vendor dashboard (/dashboard/withdrawals/)
 *     showing the vendor's available balance and a form to request a
 *     payout via M-Pesa or bank transfer, plus their request history.
 *   - A "Withdrawal Requests" screen under WP Admin > WooCommerce for the
 *     marketplace admin to approve, reject, or mark requests paid once
 *     the M-Pesa/bank transfer has actually been sent.
 *
 * Available balance = net earnings on *completed* orders (read from
 * Dokan's own `dokan_orders` sync table, same source as the Earnings
 * page) minus everything already requested that hasn't been rejected
 * (pending + approved + paid). Orders still processing/on-hold aren't
 * counted - they're not settled yet, so they aren't withdrawable yet
 * either. Requests are stored in a dedicated `fmke_withdrawals` table
 * (created in the main plugin file) rather than Dokan's withdraw table,
 * since it needs to hold M-Pesa numbers / bank details Dokan's schema
 * doesn't have fields for.
 */
class FMKE_Vendor_Withdrawals {

	/** Dashboard URL segment, e.g. /dashboard/withdrawals/ */
	const QUERY_VAR = 'withdrawals';

	/** Custom table (without $wpdb prefix). */
	const TABLE = 'fmke_withdrawals';

	/** Smallest amount a vendor may request, in store currency (KES). */
	const MIN_WITHDRAWAL = 500;

	/** Order status that counts as "settled" money a vendor can draw on. */
	const SETTLED_STATUS = 'wc-completed';

	/** Request statuses and their vendor-facing labels. */
	const STATUSES = array(
		'pending'  => 'Pending review',
		'approved' => 'Approved - payment on the way',
		'paid'     => 'Paid',
		'rejected' => 'Rejected',
	);

	/** Statuses that still count as "spoken for" against the balance. */
	const COMMITTED_STATUSES = array( 'pending', 'approved', 'paid' );

	/** Payout methods and their labels. */
	const METHODS = array(
		'mpesa' => 'M-Pesa',
		'bank'  => 'Bank transfer',
	);

	public function __construct() {
		// Vendor dashboard page.
		add_filter( 'dokan_query_var_filter', array( $this, 'register_query_var' ) );
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'register_nav_item' ) );
		add_action( 'dokan_load_custom_template', array( $this, 'maybe_render_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
		add_action( 'admin_post_fmke_request_withdrawal', array( $this, 'handle_request' ) );

		// Table safety net: covers sites updating the plugin without ever
		// re-running the activation hook (fmke_create_withdrawals_table()
		// already runs on activation - this just catches the update case).
		add_action( 'admin_init', array( $this, 'maybe_create_table' ) );

		// Admin review screen.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_fmke_process_withdrawal', array( $this, 'handle_admin_process' ) );
	}

	public function maybe_create_table() {
		if ( '1' === get_option( 'fmke_withdrawals_table_created' ) ) {
			return;
		}
		if ( function_exists( 'fmke_create_withdrawals_table' ) ) {
			fmke_create_withdrawals_table();
		}
		update_option( 'fmke_withdrawals_table_created', '1' );
	}

	public function maybe_flush_rewrites() {
		if ( '1' === get_option( 'fmke_withdrawals_rewrites' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'fmke_withdrawals_rewrites', '1' );
	}

	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Slots in right after the Earnings page, since "how much can I
	 * actually take out" is the natural next question.
	 */
	public function register_nav_item( $urls ) {
		$urls[ self::QUERY_VAR ] = array(
			'title' => 'Withdrawals',
			'icon'  => '<i class="fa fa-university"></i>',
			'url'   => dokan_get_navigation_url( self::QUERY_VAR ),
			'pos'   => 57, // Dokan: Orders 50, FMKE Earnings 55, Coupons 60.
		);
		return $urls;
	}

	public function maybe_render_page( $query_vars ) {
		if ( ! isset( $query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		$this->render_page();
	}

	private function is_withdrawals_page() {
		return function_exists( 'dokan_is_seller_dashboard' )
			&& dokan_is_seller_dashboard()
			&& '' !== get_query_var( self::QUERY_VAR, '' );
	}

	// -----------------------------------------------------------------
	// Data
	// -----------------------------------------------------------------

	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	private function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Net earnings on this vendor's *completed* orders only - the same
	 * `dokan_orders` sync table the Earnings page reads, but restricted
	 * to a status that's actually settled rather than still in flight.
	 */
	private function get_settled_net_earnings( $vendor_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dokan_orders';

		if ( ! $this->table_exists( $table ) ) {
			return 0.0;
		}

		$sql = "SELECT COALESCE(SUM(net_amount), 0) FROM {$table} WHERE seller_id = %d AND order_status = %s";
		return (float) $wpdb->get_var( $wpdb->prepare( $sql, $vendor_id, self::SETTLED_STATUS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Everything already requested that hasn't been rejected - pending
	 * and approved requests reserve the funds, paid ones have already
	 * taken them, so all three come off the available balance.
	 */
	private function get_committed_amount( $vendor_id ) {
		global $wpdb;
		$table = $this->table_name();

		if ( ! $this->table_exists( $table ) ) {
			return 0.0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( self::COMMITTED_STATUSES ), '%s' ) );
		$sql          = "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE vendor_id = %d AND status IN ( {$placeholders} )";
		$params       = array_merge( array( $vendor_id ), self::COMMITTED_STATUSES );

		return (float) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	private function get_available_balance( $vendor_id ) {
		$balance = $this->get_settled_net_earnings( $vendor_id ) - $this->get_committed_amount( $vendor_id );
		return max( 0.0, $balance );
	}

	private function get_vendor_history( $vendor_id, $limit = 25 ) {
		global $wpdb;
		$table = $this->table_name();

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$sql = "SELECT * FROM {$table} WHERE vendor_id = %d ORDER BY requested_at DESC LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $vendor_id, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	// -----------------------------------------------------------------
	// Vendor: submitting a request
	// -----------------------------------------------------------------

	public function handle_request() {
		if ( ! is_user_logged_in() || ! current_user_can( 'dokandar' ) ) {
			wp_die( 'You need a vendor account to request a withdrawal.' );
		}
		check_admin_referer( 'fmke_request_withdrawal' );

		$vendor_id = get_current_user_id();
		$redirect  = dokan_get_navigation_url( self::QUERY_VAR );

		$amount = isset( $_POST['fmke_amount'] ) ? (float) $_POST['fmke_amount'] : 0.0;
		$method = isset( $_POST['fmke_method'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_method'] ) ) : '';

		if ( ! isset( self::METHODS[ $method ] ) ) {
			$this->redirect_with( $redirect, 'error', 'method' );
		}

		if ( 'mpesa' === $method ) {
			$phone_raw = isset( $_POST['fmke_mpesa_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_mpesa_phone'] ) ) : '';
			$phone     = $this->normalize_mpesa_phone( $phone_raw );
			if ( ! $phone ) {
				$this->redirect_with( $redirect, 'error', 'phone_format' );
			}
			$details = array( 'phone' => $phone );
		} else {
			$bank_name  = isset( $_POST['fmke_bank_name'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_bank_name'] ) ) : '';
			$acc_name   = isset( $_POST['fmke_bank_account_name'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_bank_account_name'] ) ) : '';
			$acc_number = isset( $_POST['fmke_bank_account_number'] ) ? sanitize_text_field( wp_unslash( $_POST['fmke_bank_account_number'] ) ) : '';
			$details    = $this->validate_bank_details( $bank_name, $acc_name, $acc_number );
			if ( ! $details ) {
				$this->redirect_with( $redirect, 'error', 'bank_format' );
			}
		}

		$note = isset( $_POST['fmke_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fmke_note'] ) ) : '';

		if ( $amount < self::MIN_WITHDRAWAL ) {
			$this->redirect_with( $redirect, 'error', 'minimum' );
		}

		// The balance check and the insert have to happen as one atomic
		// step. Without this lock, two near-simultaneous requests (a
		// double-click, a page refresh replaying the form, two open
		// tabs) can both read the same "available balance" before either
		// row exists, both pass the check, and together overdraw the
		// vendor's real balance. A MySQL named lock scoped to this vendor
		// serializes any concurrent submissions from them - a genuinely
		// different vendor isn't blocked at all.
		if ( ! $this->acquire_vendor_lock( $vendor_id ) ) {
			$this->redirect_with( $redirect, 'error', 'busy' );
		}

		// Recompute server-side rather than trusting a hidden field, so a
		// vendor can't request more than they actually have by editing
		// the form - and now guaranteed race-free by the lock above.
		$available = $this->get_available_balance( $vendor_id );
		if ( $amount > $available ) {
			$this->release_vendor_lock( $vendor_id );
			$this->redirect_with( $redirect, 'error', 'balance' );
		}

		global $wpdb;
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table_name(),
			array(
				'vendor_id'       => $vendor_id,
				'amount'          => $amount,
				'method'          => $method,
				'payment_details' => wp_json_encode( $details ),
				'status'          => 'pending',
				'vendor_note'     => $note,
				'requested_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->release_vendor_lock( $vendor_id );

		if ( ! $inserted ) {
			$this->redirect_with( $redirect, 'error', 'db' );
		}

		// Prefill next time so the vendor isn't retyping the same M-Pesa
		// number or bank details on every request.
		update_user_meta( $vendor_id, 'fmke_withdrawal_last_method', $method );
		update_user_meta( $vendor_id, 'fmke_withdrawal_last_details', $details );

		$this->notify_admin_new_request( $vendor_id, $amount, $method );

		$this->redirect_with( $redirect, 'success', 'requested' );
	}

	/**
	 * Serializes withdrawal requests from the SAME vendor so the balance
	 * check in handle_request() can't race with itself (see the comment
	 * there). Uses a MySQL session-level named lock rather than table
	 * locking or a DB transaction, since it works the same regardless of
	 * table storage engine and needs no schema change. Different vendors
	 * never contend with each other - the lock name is per-vendor.
	 * MySQL automatically releases the lock if the request dies (fatal
	 * error, timeout) without calling release_vendor_lock(), since the
	 * lock is tied to that one database connection.
	 */
	private function acquire_vendor_lock( $vendor_id ) {
		global $wpdb;
		$lock_name = 'fmke_withdrawal_' . (int) $vendor_id;
		// Wait up to 5 seconds for a same-vendor request already in
		// flight to finish, rather than failing immediately.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	private function release_vendor_lock( $vendor_id ) {
		global $wpdb;
		$lock_name = 'fmke_withdrawal_' . (int) $vendor_id;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Accepts 07XXXXXXXX / 01XXXXXXXX (local) or 2547XXXXXXXX /
	 * 2541XXXXXXXX (international, no leading +) and normalizes to the
	 * 2547.../2541... form Daraja/manual disbursement expects. Returns
	 * false for anything else, so a typo'd or garbage number gets caught
	 * here instead of only being noticed by an admin about to send real
	 * money to it.
	 */
	private function normalize_mpesa_phone( $phone ) {
		$digits = preg_replace( '/[^0-9]/', '', (string) $phone );

		if ( preg_match( '/^0[17]\d{8}$/', $digits ) ) {
			$digits = '254' . substr( $digits, 1 );
		}

		if ( preg_match( '/^254[17]\d{8}$/', $digits ) ) {
			return $digits;
		}

		return false;
	}

	/**
	 * Light sanity checks on bank payout details - not a full bank-
	 * account validator (Kenyan banks vary), just enough to catch blank/
	 * junk input before it reaches an admin who'll wire real money
	 * against it. Returns the cleaned-up details array, or false.
	 */
	private function validate_bank_details( $bank_name, $acc_name, $acc_number ) {
		$bank_name  = trim( $bank_name );
		$acc_name   = trim( $acc_name );
		$acc_digits = preg_replace( '/[^0-9]/', '', $acc_number );

		if ( mb_strlen( $bank_name ) < 2 || mb_strlen( $bank_name ) > 100 ) {
			return false;
		}
		if ( mb_strlen( $acc_name ) < 2 || mb_strlen( $acc_name ) > 100 ) {
			return false;
		}
		// Kenyan bank account numbers are numeric and typically 6-20 digits.
		if ( strlen( $acc_digits ) < 6 || strlen( $acc_digits ) > 20 ) {
			return false;
		}

		return array(
			'bank_name'      => $bank_name,
			'account_name'   => $acc_name,
			'account_number' => $acc_digits,
		);
	}

	private function redirect_with( $base_url, $key, $value ) {
		wp_safe_redirect( add_query_arg( $key, $value, $base_url ) );
		exit;
	}

	private function notify_admin_new_request( $vendor_id, $amount, $method ) {
		$vendor = get_userdata( $vendor_id );
		$name   = $vendor ? $vendor->display_name : "Vendor #{$vendor_id}";

		wp_mail(
			get_option( 'admin_email' ),
			'New withdrawal request - ' . $name,
			sprintf(
				"%1\$s has requested a withdrawal of %2\$s via %3\$s.\n\nReview it at: %4\$s",
				$name,
				wp_strip_all_tags( wc_price( $amount ) ),
				self::METHODS[ $method ],
				admin_url( 'admin.php?page=fmke-withdrawals' )
			)
		);
	}

	// -----------------------------------------------------------------
	// Vendor: page display
	// -----------------------------------------------------------------

	private function render_page() {
		if ( ! current_user_can( 'dokandar' ) ) {
			echo '<div class="dokan-alert dokan-alert-danger">You need a vendor account to request a withdrawal.</div>';
			return;
		}

		$vendor_id = get_current_user_id();
		$table     = $this->table_name();

		if ( ! $this->table_exists( $table ) ) {
			?>
			<div class="dokan-dashboard-wrap">
				<div class="dokan-dashboard-content fmke-withdrawals">
					<div class="dokan-alert dokan-alert-warning">
						Withdrawals aren't set up yet on this site. Please ask the
						marketplace admin to deactivate and reactivate the
						Flower Marketplace KE plugin once.
					</div>
				</div>
			</div>
			<?php
			return;
		}

		$available = $this->get_available_balance( $vendor_id );
		$history   = $this->get_vendor_history( $vendor_id );
		$last_method  = get_user_meta( $vendor_id, 'fmke_withdrawal_last_method', true );
		$last_details = get_user_meta( $vendor_id, 'fmke_withdrawal_last_details', true );
		if ( ! is_array( $last_details ) ) {
			$last_details = array();
		}
		?>
		<div class="dokan-dashboard-wrap">
			<?php do_action( 'dokan_dashboard_content_before' ); ?>
			<div class="dokan-dashboard-content fmke-withdrawals">

				<article class="fmke-withdrawals-area">
					<header class="dokan-dashboard-header">
						<h1 class="entry-title">Withdrawals</h1>
					</header>

					<?php $this->render_notice(); ?>

					<div class="fmke-withdrawals-balance">
						<span class="fmke-withdrawals-balance-label">Available to withdraw</span>
						<span class="fmke-withdrawals-balance-value"><?php echo wp_kses_post( wc_price( $available ) ); ?></span>
						<span class="fmke-withdrawals-balance-note">
							Based on completed orders, minus anything already requested.
							Minimum withdrawal is <?php echo wp_kses_post( wc_price( self::MIN_WITHDRAWAL ) ); ?>.
						</span>
					</div>

					<?php $this->render_request_form( $available, $last_method, $last_details ); ?>

					<h2 class="fmke-withdrawals-subheading">Your requests</h2>
					<?php $this->render_history_table( $history ); ?>

				</article>
			</div>
			<?php do_action( 'dokan_dashboard_content_after' ); ?>
		</div>
		<?php
	}

	private function render_notice() {
		$success = isset( $_GET['success'] ) ? sanitize_text_field( wp_unslash( $_GET['success'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only, no state change.
		$error   = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'requested' === $success ) {
			echo '<div class="dokan-alert dokan-alert-success">Your withdrawal request has been submitted and is awaiting review.</div>';
			return;
		}

		$messages = array(
			'method'       => 'Please choose a payout method.',
			'details'      => 'Please fill in the payment details for your chosen method.',
			'phone_format' => 'Please enter a valid Safaricom/Airtel M-Pesa number, e.g. 07XXXXXXXX or 2547XXXXXXXX.',
			'bank_format'  => 'Please double-check your bank details - bank name and account name need at least 2 characters, and the account number should be 6-20 digits.',
			'minimum'      => sprintf( 'The minimum withdrawal amount is %s.', wp_strip_all_tags( wc_price( self::MIN_WITHDRAWAL ) ) ),
			'balance'      => 'That amount is more than your available balance.',
			'busy'         => "We're still processing your last request - please wait a few seconds and try again.",
			'db'           => 'Something went wrong saving your request. Please try again.',
		);

		if ( isset( $messages[ $error ] ) ) {
			echo '<div class="dokan-alert dokan-alert-danger">' . esc_html( $messages[ $error ] ) . '</div>';
		}
	}

	private function render_request_form( $available, $last_method, $last_details ) {
		$disabled     = $available < self::MIN_WITHDRAWAL;
		$mpesa_active = ( 'bank' !== $last_method );
		?>
		<div class="fmke-withdrawals-form-wrap">
			<?php if ( $disabled ) : ?>
				<p class="fmke-withdrawals-empty">
					You don't have enough available balance to request a withdrawal yet
					(minimum <?php echo wp_kses_post( wc_price( self::MIN_WITHDRAWAL ) ); ?>).
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fmke-withdrawals-form">
					<input type="hidden" name="action" value="fmke_request_withdrawal" />
					<?php wp_nonce_field( 'fmke_request_withdrawal' ); ?>

					<label class="fmke-field">
						<span>Amount (KES)</span>
						<input type="number" step="0.01" min="<?php echo esc_attr( self::MIN_WITHDRAWAL ); ?>" max="<?php echo esc_attr( $available ); ?>" name="fmke_amount" required />
					</label>

					<fieldset class="fmke-field fmke-method-choice">
						<legend>Payout method</legend>
						<label>
							<input type="radio" name="fmke_method" value="mpesa" class="fmke-method-radio" data-target="fmke-method-mpesa" <?php checked( $mpesa_active ); ?> />
							M-Pesa
						</label>
						<label>
							<input type="radio" name="fmke_method" value="bank" class="fmke-method-radio" data-target="fmke-method-bank" <?php checked( ! $mpesa_active ); ?> />
							Bank transfer
						</label>
					</fieldset>

					<div id="fmke-method-mpesa" class="fmke-method-fields" <?php echo $mpesa_active ? '' : 'style="display:none;"'; ?>>
						<label class="fmke-field">
							<span>M-Pesa number</span>
							<input type="text" name="fmke_mpesa_phone" placeholder="2547XXXXXXXX" value="<?php echo esc_attr( $last_details['phone'] ?? '' ); ?>" />
						</label>
					</div>

					<div id="fmke-method-bank" class="fmke-method-fields" <?php echo $mpesa_active ? 'style="display:none;"' : ''; ?>>
						<label class="fmke-field">
							<span>Bank name</span>
							<input type="text" name="fmke_bank_name" value="<?php echo esc_attr( $last_details['bank_name'] ?? '' ); ?>" />
						</label>
						<label class="fmke-field">
							<span>Account name</span>
							<input type="text" name="fmke_bank_account_name" value="<?php echo esc_attr( $last_details['account_name'] ?? '' ); ?>" />
						</label>
						<label class="fmke-field">
							<span>Account number</span>
							<input type="text" name="fmke_bank_account_number" value="<?php echo esc_attr( $last_details['account_number'] ?? '' ); ?>" />
						</label>
					</div>

					<label class="fmke-field">
						<span>Note (optional)</span>
						<textarea name="fmke_note" rows="2"></textarea>
					</label>

					<button type="submit" class="dokan-btn dokan-btn-theme">Request withdrawal</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_history_table( $history ) {
		if ( empty( $history ) ) {
			echo '<p class="fmke-withdrawals-empty">No withdrawal requests yet.</p>';
			return;
		}
		?>
		<table class="dokan-table fmke-withdrawals-table">
			<thead>
				<tr>
					<th>Date</th>
					<th class="fmke-num">Amount</th>
					<th>Method</th>
					<th>Status</th>
					<th>Note</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $history as $row ) : ?>
				<?php
				$status_label = self::STATUSES[ $row->status ] ?? ucfirst( $row->status );
				$note         = 'rejected' === $row->status && ! empty( $row->admin_note ) ? $row->admin_note : '';
				?>
				<tr>
					<td><?php echo esc_html( date_i18n( wc_date_format(), strtotime( $row->requested_at ) ) ); ?></td>
					<td class="fmke-num"><?php echo wp_kses_post( wc_price( (float) $row->amount ) ); ?></td>
					<td><?php echo esc_html( self::METHODS[ $row->method ] ?? $row->method ); ?></td>
					<td><span class="fmke-withdrawals-status fmke-withdrawals-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
					<td><?php echo esc_html( $note ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -----------------------------------------------------------------
	// Admin: review screen
	// -----------------------------------------------------------------

	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Withdrawal Requests',
			'Withdrawal Requests',
			'manage_woocommerce',
			'fmke-withdrawals',
			array( $this, 'render_admin_page' )
		);
	}

	private function get_admin_requests( $status_filter ) {
		global $wpdb;
		$table = $this->table_name();

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		if ( 'all' === $status_filter ) {
			$sql = "SELECT * FROM {$table} ORDER BY requested_at DESC LIMIT 200";
			return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$sql = "SELECT * FROM {$table} WHERE status = %s ORDER BY requested_at DESC LIMIT 200";
		return $wpdb->get_results( $wpdb->prepare( $sql, $status_filter ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to view this page.' );
		}

		$current_status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$valid_filters   = array_merge( array( 'all' ), array_keys( self::STATUSES ) );
		if ( ! in_array( $current_status, $valid_filters, true ) ) {
			$current_status = 'pending';
		}

		$requests = $this->get_admin_requests( $current_status );
		?>
		<div class="wrap">
			<h1>Withdrawal Requests</h1>

			<?php if ( isset( $_GET['fmke_processed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>Request updated.</p></div>
			<?php endif; ?>

			<ul class="subsubsub">
				<?php foreach ( $valid_filters as $filter ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'fmke-withdrawals', 'status' => $filter ), admin_url( 'admin.php' ) ) ); ?>"
							<?php echo $filter === $current_status ? 'class="current"' : ''; ?>>
							<?php echo esc_html( 'all' === $filter ? 'All' : self::STATUSES[ $filter ] ); ?>
						</a> |
					</li>
				<?php endforeach; ?>
			</ul>
			<br class="clear" />

			<?php if ( empty( $requests ) ) : ?>
				<p>No withdrawal requests in this view.</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Date</th>
							<th>Vendor</th>
							<th>Amount</th>
							<th>Method</th>
							<th>Payment details</th>
							<th>Vendor note</th>
							<th>Status</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $requests as $row ) : ?>
						<?php $this->render_admin_row( $row ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_admin_row( $row ) {
		$vendor       = get_userdata( $row->vendor_id );
		$vendor_name  = $vendor ? $vendor->display_name . ' (' . $vendor->user_email . ')' : "Vendor #{$row->vendor_id}";
		$details      = json_decode( $row->payment_details, true );
		$details_text = '';
		if ( is_array( $details ) ) {
			$parts = array();
			foreach ( $details as $key => $value ) {
				$parts[] = ucfirst( str_replace( '_', ' ', $key ) ) . ': ' . $value;
			}
			$details_text = implode( ', ', $parts );
		}
		?>
		<tr>
			<td><?php echo esc_html( date_i18n( 'Y-m-d H:i', strtotime( $row->requested_at ) ) ); ?></td>
			<td><?php echo esc_html( $vendor_name ); ?></td>
			<td><?php echo wp_kses_post( wc_price( (float) $row->amount ) ); ?></td>
			<td><?php echo esc_html( self::METHODS[ $row->method ] ?? $row->method ); ?></td>
			<td><?php echo esc_html( $details_text ); ?></td>
			<td><?php echo esc_html( $row->vendor_note ); ?></td>
			<td><?php echo esc_html( self::STATUSES[ $row->status ] ?? $row->status ); ?></td>
			<td><?php $this->render_admin_actions( $row ); ?></td>
		</tr>
		<?php
	}

	private function render_admin_actions( $row ) {
		$next_actions = array();
		if ( 'pending' === $row->status ) {
			$next_actions = array(
				'approve' => 'Approve',
				'reject'  => 'Reject',
			);
		} elseif ( 'approved' === $row->status ) {
			$next_actions = array(
				'paid'   => 'Mark paid',
				'reject' => 'Reject',
			);
		}

		if ( empty( $next_actions ) ) {
			echo '&mdash;';
			return;
		}

		foreach ( $next_actions as $decision => $label ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fmke-admin-inline-form">
				<input type="hidden" name="action" value="fmke_process_withdrawal" />
				<input type="hidden" name="request_id" value="<?php echo esc_attr( $row->id ); ?>" />
				<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>" />
				<input type="hidden" name="status_filter" value="<?php echo isset( $_GET['status'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['status'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />
				<?php wp_nonce_field( 'fmke_process_withdrawal_' . $row->id, 'fmke_process_nonce' ); ?>
				<?php if ( 'reject' === $decision ) : ?>
					<input type="text" name="admin_note" placeholder="Reason (optional)" class="regular-text" />
				<?php endif; ?>
				<button type="submit" class="button<?php echo 'reject' === $decision ? '' : ' button-primary'; ?>"><?php echo esc_html( $label ); ?></button>
			</form>
			<?php
		}
	}

	public function handle_admin_process() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to do that.' );
		}

		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		check_admin_referer( 'fmke_process_withdrawal_' . $request_id, 'fmke_process_nonce' );

		$decision      = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( $_POST['decision'] ) ) : '';
		$admin_note    = isset( $_POST['admin_note'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_note'] ) ) : '';
		$status_filter = isset( $_POST['status_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['status_filter'] ) ) : '';

		$map = array(
			'approve' => 'approved',
			'reject'  => 'rejected',
			'paid'    => 'paid',
		);

		if ( ! isset( $map[ $decision ] ) ) {
			wp_die( 'Unknown action.' );
		}

		global $wpdb;
		$table = $this->table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $request_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		if ( $row ) {
			// Only allow the transitions the buttons actually offer, so a
			// replayed/edited request can't skip straight to "paid".
			$allowed = array(
				'pending'  => array( 'approved', 'rejected' ),
				'approved' => array( 'paid', 'rejected' ),
			);
			if ( isset( $allowed[ $row->status ] ) && in_array( $map[ $decision ], $allowed[ $row->status ], true ) ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'status'       => $map[ $decision ],
						'admin_note'   => $admin_note,
						'processed_at' => current_time( 'mysql' ),
						'processed_by' => get_current_user_id(),
					),
					array( 'id' => $request_id ),
					array( '%s', '%s', '%s', '%d' ),
					array( '%d' )
				);
				$this->notify_vendor_status_change( $row->vendor_id, (float) $row->amount, $map[ $decision ], $admin_note );
			}
		}

		$redirect = add_query_arg(
			array(
				'page'           => 'fmke-withdrawals',
				'status'         => $status_filter ? $status_filter : 'pending',
				'fmke_processed' => '1',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	private function notify_vendor_status_change( $vendor_id, $amount, $new_status, $admin_note ) {
		$vendor = get_userdata( $vendor_id );
		if ( ! $vendor ) {
			return;
		}

		$label   = self::STATUSES[ $new_status ] ?? $new_status;
		$message = sprintf(
			"Your withdrawal request for %1\$s is now: %2\$s.",
			wp_strip_all_tags( wc_price( $amount ) ),
			$label
		);
		if ( $admin_note ) {
			$message .= "\n\nNote from the marketplace: " . $admin_note;
		}

		wp_mail( $vendor->user_email, 'Withdrawal request update', $message );
	}

	// -----------------------------------------------------------------
	// Assets
	// -----------------------------------------------------------------

	public function enqueue() {
		if ( ! $this->is_withdrawals_page() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $this->get_inline_js() );
	}

	/**
	 * Tiny vanilla-JS toggle between the M-Pesa and bank detail fields -
	 * no build step, matches the rest of the plugin's "no custom CSS
	 * pipeline" approach.
	 */
	private function get_inline_js() {
		return <<<JS
document.addEventListener('DOMContentLoaded', function () {
	var radios = document.querySelectorAll('.fmke-method-radio');
	radios.forEach(function (radio) {
		radio.addEventListener('change', function () {
			document.querySelectorAll('.fmke-method-fields').forEach(function (el) {
				el.style.display = 'none';
			});
			var target = document.getElementById(radio.getAttribute('data-target'));
			if (target) {
				target.style.display = '';
			}
		});
	});
});
JS;
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-withdrawals-balance {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 18px 20px;
	border: 1px solid #E4DFD3;
	border-radius: 8px;
	background: #F7F5F0;
	margin: 0 0 20px;
	max-width: 340px;
}
.fmke-withdrawals-balance-label {
	font-size: 12px;
	font-weight: 600;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: #5A5648;
}
.fmke-withdrawals-balance-value {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 28px;
	color: #7A2048;
}
.fmke-withdrawals-balance-note {
	font-size: 12px;
	color: #5A5648;
}
.fmke-withdrawals-form-wrap {
	max-width: 420px;
	margin: 0 0 30px;
}
.fmke-field {
	display: block;
	margin: 0 0 14px;
}
.fmke-field span {
	display: block;
	font-size: 13px;
	font-weight: 600;
	color: #1F3D2C;
	margin: 0 0 4px;
}
.fmke-field input[type="text"],
.fmke-field input[type="number"],
.fmke-field textarea {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid #E4DFD3;
	border-radius: 6px;
}
.fmke-method-choice {
	border: none;
	padding: 0;
	margin: 0 0 14px;
}
.fmke-method-choice legend {
	font-size: 13px;
	font-weight: 600;
	color: #1F3D2C;
	margin: 0 0 6px;
	padding: 0;
}
.fmke-method-choice label {
	display: inline-block;
	margin-right: 16px;
	font-weight: 400;
}
.fmke-withdrawals-subheading {
	font-family: 'Fraunces', Georgia, serif;
	font-size: 18px;
	color: #1F3D2C;
	margin: 0 0 12px;
}
.fmke-withdrawals-table { width: 100%; }
.fmke-withdrawals-table .fmke-num { text-align: right; }
.fmke-withdrawals-status {
	display: inline-block;
	padding: 2px 9px;
	border-radius: 999px;
	border: 1px solid #E4DFD3;
	background: #fff;
	font-size: 12px;
	white-space: nowrap;
}
.fmke-withdrawals-status-pending { color: #5A5648; }
.fmke-withdrawals-status-approved { color: #1F3D2C; border-color: #1F3D2C; }
.fmke-withdrawals-status-paid { color: #fff; background: #1F3D2C; border-color: #1F3D2C; }
.fmke-withdrawals-status-rejected { color: #7A2048; border-color: #7A2048; }
.fmke-withdrawals-empty {
	font-size: 13px;
	color: #5A5648;
}

@media (max-width: 600px) {
	.fmke-withdrawals-balance-value { font-size: 22px; }
}
CSS;
	}
}

new FMKE_Vendor_Withdrawals();
