<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor (store-level) reviews.
 *
 * WooCommerce/Dokan reviews are per-*product*. There's nothing that lets
 * a customer rate the actual buying experience with a vendor - shipping
 * speed, packaging, communication - which matters more on a marketplace
 * than any single flower order does. This adds that as its own layer:
 *
 *   - A review block on each vendor's Dokan store page: average rating,
 *     the review list, and a form for eligible customers.
 *   - A "Reviews" page on the vendor dashboard (/dashboard/reviews/) so a
 *     vendor can read what's been said about their store and post one
 *     public reply per review.
 *   - A "Vendor Reviews" screen under WP Admin > WooCommerce so the
 *     marketplace admin can hide (or permanently delete) anything abusive
 *     or fake.
 *
 * Eligibility: only a logged-in customer with a *completed* order from
 * that specific vendor can leave a review (checked against Dokan's own
 * `dokan_orders` sync table, the same source the Earnings/Withdrawals
 * pages read), and only one review per customer per vendor - submitting
 * again updates their existing review rather than adding a second one.
 *
 * Reviews are visible immediately (no pending queue to babysit on a new
 * marketplace with few reviews), but the admin can hide any review after
 * the fact; hidden reviews drop out of both the store page and the
 * average rating straight away.
 */
class FMKE_Vendor_Reviews {

	/** Custom table (without $wpdb prefix). */
	const TABLE = 'fmke_vendor_reviews';

	/** Dashboard URL segment, e.g. /dashboard/reviews/ */
	const QUERY_VAR = 'reviews';

	/** Order status that counts as proof of a genuine purchase. */
	const QUALIFYING_STATUS = 'wc-completed';

	public function __construct() {
		// Store page: rating summary, review list, review form.
		add_action( 'dokan_store_profile_frame_after', array( $this, 'render_store_reviews' ), 20, 2 );
		add_action( 'admin_post_fmke_submit_vendor_review', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_fmke_submit_vendor_review', array( $this, 'handle_submit_nopriv' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		// Vendor dashboard: read reviews, post a reply.
		add_filter( 'dokan_query_var_filter', array( $this, 'register_query_var' ) );
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'register_nav_item' ) );
		add_action( 'dokan_load_custom_template', array( $this, 'maybe_render_dashboard_page' ) );
		add_action( 'admin_post_fmke_reply_to_review', array( $this, 'handle_reply' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );

		// Table safety net for sites updating the plugin without
		// re-running the activation hook.
		add_action( 'admin_init', array( $this, 'maybe_create_table' ) );

		// Admin moderation screen.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_fmke_moderate_vendor_review', array( $this, 'handle_moderate' ) );
	}

	public function maybe_create_table() {
		if ( '1' === get_option( 'fmke_vendor_reviews_table_created' ) ) {
			return;
		}
		if ( function_exists( 'fmke_create_vendor_reviews_table' ) ) {
			fmke_create_vendor_reviews_table();
		}
		update_option( 'fmke_vendor_reviews_table_created', '1' );
	}

	public function maybe_flush_rewrites() {
		if ( '1' === get_option( 'fmke_vendor_reviews_rewrites' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'fmke_vendor_reviews_rewrites', '1' );
	}

	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Slots in after Withdrawals, since "what are people saying about my
	 * store" rounds out the vendor-facing money/reputation pages.
	 */
	public function register_nav_item( $urls ) {
		$urls[ self::QUERY_VAR ] = array(
			'title' => 'Reviews',
			'icon'  => '<i class="fa fa-star"></i>',
			'url'   => dokan_get_navigation_url( self::QUERY_VAR ),
			'pos'   => 58, // Dokan: Orders 50, FMKE Earnings 55, Withdrawals 57, Coupons 60.
		);
		return $urls;
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
	 * Does this customer have a completed order with this vendor? Reuses
	 * the same `dokan_orders` + `posts` join the Earnings page uses -
	 * the order post's author is the customer who placed it.
	 *
	 * @return int The qualifying order ID, or 0 if none.
	 */
	private function find_qualifying_order( $customer_id, $vendor_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dokan_orders';

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		$sql = "SELECT do.order_id FROM {$table} AS do
			INNER JOIN {$wpdb->posts} AS p ON p.ID = do.order_id
			WHERE do.seller_id = %d AND p.post_author = %d AND do.order_status = %s
			ORDER BY p.post_date DESC LIMIT 1";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $vendor_id, $customer_id, self::QUALIFYING_STATUS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	private function get_customer_review( $vendor_id, $customer_id ) {
		global $wpdb;
		$table = $this->table_name();

		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$sql = "SELECT * FROM {$table} WHERE vendor_id = %d AND customer_id = %d LIMIT 1";
		return $wpdb->get_row( $wpdb->prepare( $sql, $vendor_id, $customer_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * @return array( 'rating' => float 0-5, 'count' => int ) over visible reviews only.
	 */
	public function get_rating_summary( $vendor_id ) {
		global $wpdb;
		$table  = $this->table_name();
		$result = array( 'rating' => 0.0, 'count' => 0 );

		if ( ! $this->table_exists( $table ) ) {
			return $result;
		}

		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			"SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM {$table} WHERE vendor_id = %d AND is_hidden = 0",
			$vendor_id
		) );

		if ( $row && $row->total > 0 ) {
			$result['rating'] = round( (float) $row->avg_rating, 1 );
			$result['count']  = (int) $row->total;
		}

		return $result;
	}

	private function get_visible_reviews( $vendor_id, $limit = 20 ) {
		global $wpdb;
		$table = $this->table_name();

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$sql = "SELECT * FROM {$table} WHERE vendor_id = %d AND is_hidden = 0 ORDER BY created_at DESC LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $vendor_id, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	private function get_all_reviews_for_vendor( $vendor_id ) {
		global $wpdb;
		$table = $this->table_name();

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$sql = "SELECT * FROM {$table} WHERE vendor_id = %d ORDER BY created_at DESC";
		return $wpdb->get_results( $wpdb->prepare( $sql, $vendor_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	// -----------------------------------------------------------------
	// Store page: display + submission
	// -----------------------------------------------------------------

	private function is_store_page() {
		return function_exists( 'dokan_is_store_page' ) && dokan_is_store_page();
	}

	/**
	 * Hooked to 'dokan_store_profile_frame_after', which Dokan Lite fires
	 * right after the store header/banner and before the product grid.
	 *
	 * @param WP_User $store_user
	 * @param array   $store_info
	 */
	public function render_store_reviews( $store_user, $store_info ) {
		if ( ! $store_user instanceof WP_User ) {
			return;
		}

		$vendor_id = $store_user->ID;
		$summary   = $this->get_rating_summary( $vendor_id );
		$reviews   = $this->get_visible_reviews( $vendor_id );

		$current_user_id  = get_current_user_id();
		$existing_review   = $current_user_id ? $this->get_customer_review( $vendor_id, $current_user_id ) : null;
		$qualifying_order  = 0;
		if ( $current_user_id && $current_user_id !== $vendor_id ) {
			$qualifying_order = $this->find_qualifying_order( $current_user_id, $vendor_id );
		}
		?>
		<div class="fmke-vendor-reviews" id="fmke-vendor-reviews">
			<h2 class="fmke-vendor-reviews-heading">Vendor Reviews</h2>

			<div class="fmke-vendor-reviews-summary">
				<?php if ( $summary['count'] > 0 ) : ?>
					<span class="fmke-vendor-reviews-stars" aria-hidden="true"><?php echo esc_html( $this->render_stars( $summary['rating'] ) ); ?></span>
					<span class="fmke-vendor-reviews-avg"><?php echo esc_html( number_format_i18n( $summary['rating'], 1 ) ); ?> / 5</span>
					<span class="fmke-vendor-reviews-count">
						<?php echo esc_html( sprintf( _n( '(%d review)', '(%d reviews)', $summary['count'], 'fmke' ), $summary['count'] ) ); ?>
					</span>
				<?php else : ?>
					<span class="fmke-vendor-reviews-count">No reviews yet - be the first to leave one.</span>
				<?php endif; ?>
			</div>

			<?php $this->render_review_notice(); ?>

			<div class="fmke-vendor-reviews-list">
				<?php if ( empty( $reviews ) ) : ?>
					<p class="fmke-vendor-reviews-empty">This vendor hasn't been reviewed yet.</p>
				<?php else : ?>
					<?php foreach ( $reviews as $review ) : ?>
						<?php $this->render_review_item( $review ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<?php $this->render_review_form( $vendor_id, $current_user_id, $qualifying_order, $existing_review ); ?>
		</div>
		<?php
	}

	private function render_review_item( $review ) {
		$reviewer = get_userdata( $review->customer_id );
		$name     = $reviewer ? $reviewer->display_name : 'A verified customer';
		?>
		<div class="fmke-vendor-review">
			<div class="fmke-vendor-review-head">
				<span class="fmke-vendor-review-author"><?php echo esc_html( $name ); ?></span>
				<span class="fmke-vendor-review-stars" aria-hidden="true"><?php echo esc_html( $this->render_stars( (int) $review->rating ) ); ?></span>
				<?php if ( $review->order_id ) : ?>
					<span class="fmke-vendor-review-verified">Verified purchase</span>
				<?php endif; ?>
				<span class="fmke-vendor-review-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review->created_at ) ) ); ?></span>
			</div>
			<?php if ( $review->comment ) : ?>
				<p class="fmke-vendor-review-comment"><?php echo esc_html( $review->comment ); ?></p>
			<?php endif; ?>
			<?php if ( $review->vendor_reply ) : ?>
				<div class="fmke-vendor-review-reply">
					<strong>Vendor response:</strong>
					<p><?php echo esc_html( $review->vendor_reply ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_review_notice() {
		$success = isset( $_GET['fmke_review'] ) ? sanitize_text_field( wp_unslash( $_GET['fmke_review'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		$error   = isset( $_GET['fmke_review_error'] ) ? sanitize_text_field( wp_unslash( $_GET['fmke_review_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'posted' === $success ) {
			echo '<div class="dokan-alert dokan-alert-success">Thanks - your review has been posted.</div>';
			return;
		}

		$messages = array(
			'self'         => "You can't review your own store.",
			'not_eligible' => 'Only customers with a completed order from this vendor can leave a review.',
			'rating'       => 'Please choose a star rating between 1 and 5.',
			'db'           => 'Something went wrong saving your review. Please try again.',
		);

		if ( isset( $messages[ $error ] ) ) {
			echo '<div class="dokan-alert dokan-alert-danger">' . esc_html( $messages[ $error ] ) . '</div>';
		}
	}

	private function render_review_form( $vendor_id, $current_user_id, $qualifying_order, $existing_review ) {
		if ( ! $current_user_id ) {
			printf(
				'<p class="fmke-vendor-reviews-login"><a href="%1$s">Log in</a> to leave a review of this vendor.</p>',
				esc_url( wp_login_url( get_permalink() ) )
			);
			return;
		}

		if ( $current_user_id === $vendor_id ) {
			return; // A vendor viewing their own store doesn't get a review form.
		}

		if ( ! $qualifying_order && ! $existing_review ) {
			echo '<p class="fmke-vendor-reviews-empty">Only customers who have completed an order with this vendor can leave a review.</p>';
			return;
		}

		$rating  = $existing_review ? (int) $existing_review->rating : 0;
		$comment = $existing_review ? $existing_review->comment : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fmke-vendor-review-form">
			<input type="hidden" name="action" value="fmke_submit_vendor_review" />
			<input type="hidden" name="fmke_vendor_id" value="<?php echo esc_attr( $vendor_id ); ?>" />
			<?php wp_nonce_field( 'fmke_submit_vendor_review_' . $vendor_id ); ?>

			<h3 class="fmke-vendor-reviews-subheading">
				<?php echo $existing_review ? 'Update your review' : 'Leave a review'; ?>
			</h3>

			<label class="fmke-field">
				<span>Rating</span>
				<select name="fmke_rating" required>
					<option value="">Choose a rating&hellip;</option>
					<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
						<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $rating, $i ); ?>>
							<?php echo esc_html( $i . ' - ' . $this->render_stars( $i ) ); ?>
						</option>
					<?php endfor; ?>
				</select>
			</label>

			<label class="fmke-field">
				<span>Your review (optional)</span>
				<textarea name="fmke_comment" rows="3" maxlength="1000"><?php echo esc_textarea( $comment ); ?></textarea>
			</label>

			<button type="submit" class="dokan-btn dokan-btn-theme">
				<?php echo $existing_review ? 'Update review' : 'Submit review'; ?>
			</button>
		</form>
		<?php
	}

	public function handle_submit_nopriv() {
		wp_safe_redirect( wp_login_url( wp_get_referer() ) );
		exit;
	}

	public function handle_submit() {
		if ( ! is_user_logged_in() ) {
			$this->handle_submit_nopriv();
		}

		$vendor_id = isset( $_POST['fmke_vendor_id'] ) ? absint( $_POST['fmke_vendor_id'] ) : 0;
		check_admin_referer( 'fmke_submit_vendor_review_' . $vendor_id );

		// Deliberately not appending the #fmke-vendor-reviews anchor here -
		// add_query_arg() doesn't understand URL fragments, so a query
		// string tacked on after one would end up inside the fragment
		// and never reach $_GET when the browser follows the redirect.
		// The anchor is added once, last, in redirect_with() below.
		$redirect_base = function_exists( 'dokan_get_store_url' ) && $vendor_id
			? dokan_get_store_url( $vendor_id )
			: wp_get_referer();

		$customer_id = get_current_user_id();

		if ( ! $vendor_id || ! user_can( $vendor_id, 'dokandar' ) ) {
			$this->redirect_with( $redirect_base, 'fmke_review_error', 'not_eligible' );
		}

		if ( $customer_id === $vendor_id ) {
			$this->redirect_with( $redirect_base, 'fmke_review_error', 'self' );
		}

		$existing  = $this->get_customer_review( $vendor_id, $customer_id );
		$order_id  = $this->find_qualifying_order( $customer_id, $vendor_id );

		if ( ! $order_id && ! $existing ) {
			$this->redirect_with( $redirect_base, 'fmke_review_error', 'not_eligible' );
		}

		$rating = isset( $_POST['fmke_rating'] ) ? absint( $_POST['fmke_rating'] ) : 0;
		if ( $rating < 1 || $rating > 5 ) {
			$this->redirect_with( $redirect_base, 'fmke_review_error', 'rating' );
		}

		$comment = isset( $_POST['fmke_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fmke_comment'] ) ) : '';
		$now     = current_time( 'mysql' );

		global $wpdb;
		$table = $this->table_name();

		if ( $existing ) {
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'rating'     => $rating,
					'comment'    => $comment,
					'order_id'   => $order_id ? $order_id : $existing->order_id,
					'updated_at' => $now,
				),
				array( 'id' => $existing->id ),
				array( '%d', '%s', '%d', '%s' ),
				array( '%d' )
			);
			$ok = ( false !== $updated );
		} else {
			$ok = (bool) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'vendor_id'   => $vendor_id,
					'customer_id' => $customer_id,
					'order_id'    => $order_id,
					'rating'      => $rating,
					'comment'     => $comment,
					'is_hidden'   => 0,
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
			);
		}

		if ( ! $ok ) {
			$this->redirect_with( $redirect_base, 'fmke_review_error', 'db' );
		}

		$this->redirect_with( $redirect_base, 'fmke_review', 'posted' );
	}

	private function redirect_with( $base_url, $key, $value ) {
		$url = add_query_arg( $key, $value, $base_url ) . '#fmke-vendor-reviews';
		wp_safe_redirect( $url );
		exit;
	}

	// -----------------------------------------------------------------
	// Vendor dashboard: reading reviews + replying
	// -----------------------------------------------------------------

	public function maybe_render_dashboard_page( $query_vars ) {
		if ( ! isset( $query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		$this->render_dashboard_page();
	}

	private function is_dashboard_reviews_page() {
		return function_exists( 'dokan_is_seller_dashboard' )
			&& dokan_is_seller_dashboard()
			&& '' !== get_query_var( self::QUERY_VAR, '' );
	}

	private function render_dashboard_page() {
		if ( ! current_user_can( 'dokandar' ) ) {
			echo '<div class="dokan-alert dokan-alert-danger">You need a vendor account to view reviews.</div>';
			return;
		}

		$vendor_id = get_current_user_id();
		$summary   = $this->get_rating_summary( $vendor_id );
		$reviews   = $this->get_all_reviews_for_vendor( $vendor_id );
		?>
		<div class="dokan-dashboard-wrap">
			<?php do_action( 'dokan_dashboard_content_before' ); ?>
			<div class="dokan-dashboard-content fmke-dashboard-reviews">
				<article>
					<header class="dokan-dashboard-header">
						<h1 class="entry-title">Reviews</h1>
					</header>

					<?php if ( isset( $_GET['fmke_replied'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
						<div class="dokan-alert dokan-alert-success">Your reply has been posted.</div>
					<?php endif; ?>

					<div class="fmke-withdrawals-balance">
						<span class="fmke-withdrawals-balance-label">Average rating</span>
						<span class="fmke-withdrawals-balance-value">
							<?php echo $summary['count'] > 0 ? esc_html( number_format_i18n( $summary['rating'], 1 ) . ' / 5' ) : '&mdash;'; ?>
						</span>
						<span class="fmke-withdrawals-balance-note">
							<?php echo esc_html( sprintf( _n( '%d public review', '%d public reviews', $summary['count'], 'fmke' ), $summary['count'] ) ); ?>
						</span>
					</div>

					<?php if ( empty( $reviews ) ) : ?>
						<p class="fmke-withdrawals-empty">No one has reviewed your store yet.</p>
					<?php else : ?>
						<?php foreach ( $reviews as $review ) : ?>
							<?php $this->render_dashboard_review_row( $review ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</article>
			</div>
			<?php do_action( 'dokan_dashboard_content_after' ); ?>
		</div>
		<?php
	}

	private function render_dashboard_review_row( $review ) {
		$reviewer = get_userdata( $review->customer_id );
		$name     = $reviewer ? $reviewer->display_name : 'A verified customer';
		?>
		<div class="fmke-vendor-review fmke-dashboard-review">
			<div class="fmke-vendor-review-head">
				<span class="fmke-vendor-review-author"><?php echo esc_html( $name ); ?></span>
				<span class="fmke-vendor-review-stars" aria-hidden="true"><?php echo esc_html( $this->render_stars( (int) $review->rating ) ); ?></span>
				<span class="fmke-vendor-review-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review->created_at ) ) ); ?></span>
				<?php if ( $review->is_hidden ) : ?>
					<span class="fmke-withdrawals-status fmke-withdrawals-status-rejected">Hidden by admin</span>
				<?php endif; ?>
			</div>
			<?php if ( $review->comment ) : ?>
				<p class="fmke-vendor-review-comment"><?php echo esc_html( $review->comment ); ?></p>
			<?php endif; ?>

			<?php if ( $review->vendor_reply ) : ?>
				<div class="fmke-vendor-review-reply">
					<strong>Your reply:</strong>
					<p><?php echo esc_html( $review->vendor_reply ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fmke-vendor-reply-form">
				<input type="hidden" name="action" value="fmke_reply_to_review" />
				<input type="hidden" name="review_id" value="<?php echo esc_attr( $review->id ); ?>" />
				<?php wp_nonce_field( 'fmke_reply_to_review_' . $review->id ); ?>
				<label class="fmke-field">
					<span><?php echo $review->vendor_reply ? 'Edit your reply' : 'Reply publicly'; ?></span>
					<textarea name="fmke_reply" rows="2" maxlength="1000"><?php echo esc_textarea( $review->vendor_reply ); ?></textarea>
				</label>
				<button type="submit" class="dokan-btn dokan-btn-theme dokan-btn-sm">
					<?php echo $review->vendor_reply ? 'Update reply' : 'Post reply'; ?>
				</button>
			</form>
		</div>
		<?php
	}

	public function handle_reply() {
		if ( ! is_user_logged_in() || ! current_user_can( 'dokandar' ) ) {
			wp_die( 'You need a vendor account to reply to a review.' );
		}

		$review_id = isset( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
		check_admin_referer( 'fmke_reply_to_review_' . $review_id );

		$vendor_id = get_current_user_id();
		$redirect  = add_query_arg( 'fmke_replied', '1', dokan_get_navigation_url( self::QUERY_VAR ) );

		global $wpdb;
		$table = $this->table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $review_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		// A vendor may only reply to reviews left about their own store.
		if ( $row && (int) $row->vendor_id === $vendor_id ) {
			$reply = isset( $_POST['fmke_reply'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fmke_reply'] ) ) : '';
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'vendor_reply'      => $reply,
					'vendor_replied_at' => current_time( 'mysql' ),
				),
				array( 'id' => $review_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	// -----------------------------------------------------------------
	// Admin: moderation screen
	// -----------------------------------------------------------------

	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Vendor Reviews',
			'Vendor Reviews',
			'manage_woocommerce',
			'fmke-vendor-reviews',
			array( $this, 'render_admin_page' )
		);
	}

	private function get_admin_reviews( $filter ) {
		global $wpdb;
		$table = $this->table_name();

		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		if ( 'hidden' === $filter ) {
			return $wpdb->get_results( "SELECT * FROM {$table} WHERE is_hidden = 1 ORDER BY created_at DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( 'visible' === $filter ) {
			return $wpdb->get_results( "SELECT * FROM {$table} WHERE is_hidden = 0 ORDER BY created_at DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to view this page.' );
		}

		$filter = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $filter, array( 'all', 'visible', 'hidden' ), true ) ) {
			$filter = 'all';
		}

		$reviews = $this->get_admin_reviews( $filter );
		$labels  = array( 'all' => 'All', 'visible' => 'Visible', 'hidden' => 'Hidden' );
		?>
		<div class="wrap">
			<h1>Vendor Reviews</h1>

			<?php if ( isset( $_GET['fmke_moderated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>Review updated.</p></div>
			<?php endif; ?>

			<ul class="subsubsub">
				<?php foreach ( $labels as $key => $label ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'fmke-vendor-reviews', 'filter' => $key ), admin_url( 'admin.php' ) ) ); ?>"
							<?php echo $key === $filter ? 'class="current"' : ''; ?>>
							<?php echo esc_html( $label ); ?>
						</a> |
					</li>
				<?php endforeach; ?>
			</ul>
			<br class="clear" />

			<?php if ( empty( $reviews ) ) : ?>
				<p>No reviews in this view.</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Date</th>
							<th>Vendor</th>
							<th>Customer</th>
							<th>Rating</th>
							<th>Review</th>
							<th>Vendor reply</th>
							<th>Status</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $reviews as $review ) : ?>
						<?php $this->render_admin_row( $review, $filter ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_admin_row( $review, $filter ) {
		$vendor       = get_userdata( $review->vendor_id );
		$customer     = get_userdata( $review->customer_id );
		$vendor_name  = $vendor ? $vendor->display_name : "Vendor #{$review->vendor_id}";
		$customer_name = $customer ? $customer->display_name . ' (' . $customer->user_email . ')' : "Customer #{$review->customer_id}";
		?>
		<tr>
			<td><?php echo esc_html( date_i18n( 'Y-m-d H:i', strtotime( $review->created_at ) ) ); ?></td>
			<td><?php echo esc_html( $vendor_name ); ?></td>
			<td><?php echo esc_html( $customer_name ); ?></td>
			<td><?php echo esc_html( $review->rating ); ?>/5</td>
			<td><?php echo esc_html( $review->comment ); ?></td>
			<td><?php echo esc_html( $review->vendor_reply ); ?></td>
			<td><?php echo $review->is_hidden ? 'Hidden' : 'Visible'; ?></td>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fmke-admin-inline-form">
					<input type="hidden" name="action" value="fmke_moderate_vendor_review" />
					<input type="hidden" name="review_id" value="<?php echo esc_attr( $review->id ); ?>" />
					<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>" />
					<?php wp_nonce_field( 'fmke_moderate_vendor_review_' . $review->id, 'fmke_moderate_nonce' ); ?>
					<button type="submit" name="decision" value="<?php echo $review->is_hidden ? 'unhide' : 'hide'; ?>" class="button">
						<?php echo $review->is_hidden ? 'Unhide' : 'Hide'; ?>
					</button>
					<button type="submit" name="decision" value="delete" class="button" onclick="return confirm('Permanently delete this review?');">
						Delete
					</button>
				</form>
			</td>
		</tr>
		<?php
	}

	public function handle_moderate() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to do that.' );
		}

		$review_id = isset( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
		check_admin_referer( 'fmke_moderate_vendor_review_' . $review_id, 'fmke_moderate_nonce' );

		$decision = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( $_POST['decision'] ) ) : '';
		$filter   = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'all';

		global $wpdb;
		$table = $this->table_name();

		if ( 'hide' === $decision ) {
			$wpdb->update( $table, array( 'is_hidden' => 1 ), array( 'id' => $review_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( 'unhide' === $decision ) {
			$wpdb->update( $table, array( 'is_hidden' => 0 ), array( 'id' => $review_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( 'delete' === $decision ) {
			$wpdb->delete( $table, array( 'id' => $review_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$redirect = add_query_arg(
			array(
				'page'           => 'fmke-vendor-reviews',
				'filter'         => $filter,
				'fmke_moderated' => '1',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	// -----------------------------------------------------------------
	// Shared helpers
	// -----------------------------------------------------------------

	/**
	 * Turns a 0-5 average into a plain filled/empty star string, e.g.
	 * "★★★★☆" for a 4.3 average (rounded to the nearest whole star).
	 */
	private function render_stars( $average ) {
		$filled = (int) round( $average );
		$filled = max( 0, min( 5, $filled ) );
		return str_repeat( '★', $filled ) . str_repeat( '☆', 5 - $filled );
	}

	public function enqueue() {
		if ( ! $this->is_store_page() && ! $this->is_dashboard_reviews_page() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-vendor-reviews {
	margin: 24px 0 32px;
	padding: 20px;
	border: 1px solid #E4DFD3;
	border-radius: 8px;
	background: #fff;
}
.fmke-vendor-reviews-heading,
.fmke-vendor-reviews-subheading {
	font-family: 'Fraunces', Georgia, serif;
	color: #1F3D2C;
	margin: 0 0 12px;
}
.fmke-vendor-reviews-heading { font-size: 20px; }
.fmke-vendor-reviews-subheading { font-size: 16px; margin-top: 20px; }
.fmke-vendor-reviews-summary {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 16px;
	flex-wrap: wrap;
}
.fmke-vendor-reviews-stars,
.fmke-vendor-review-stars {
	color: #E8A33D;
	letter-spacing: 1px;
}
.fmke-vendor-reviews-avg {
	font-weight: 600;
	color: #1F3D2C;
}
.fmke-vendor-reviews-count {
	font-size: 13px;
	color: #5A5648;
}
.fmke-vendor-review {
	padding: 14px 0;
	border-top: 1px solid #EDE6D8;
}
.fmke-vendor-review:first-child { border-top: none; }
.fmke-vendor-review-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin: 0 0 6px;
	font-size: 13px;
}
.fmke-vendor-review-author { font-weight: 600; color: #1F3D2C; }
.fmke-vendor-review-verified {
	font-size: 11px;
	color: #1F3D2C;
	background: #EDE6D8;
	border-radius: 10px;
	padding: 1px 8px;
}
.fmke-vendor-review-date { color: #767676; margin-left: auto; }
.fmke-vendor-review-comment {
	margin: 0;
	font-size: 14px;
	color: #333;
}
.fmke-vendor-review-reply {
	margin: 8px 0 0 16px;
	padding: 8px 12px;
	background: #F7F5F0;
	border-radius: 6px;
	font-size: 13px;
}
.fmke-vendor-review-reply p { margin: 4px 0 0; }
.fmke-vendor-reviews-empty,
.fmke-vendor-reviews-login {
	font-size: 13px;
	color: #5A5648;
}
.fmke-vendor-review-form,
.fmke-vendor-reply-form {
	max-width: 420px;
	margin-top: 12px;
}
.fmke-vendor-reply-form { max-width: 100%; margin-top: 10px; }
.fmke-field {
	display: block;
	margin: 0 0 12px;
}
.fmke-field span {
	display: block;
	font-size: 13px;
	font-weight: 600;
	color: #1F3D2C;
	margin: 0 0 4px;
}
.fmke-field select,
.fmke-field textarea {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid #E4DFD3;
	border-radius: 6px;
}
.fmke-dashboard-review { border-top: 1px solid #E4DFD3; padding: 16px 0; }
CSS;
	}
}

// Stored on $GLOBALS (not just instantiated) so other classes - e.g.
// class-featured-vendors.php - can read get_rating_summary() without a
// second, divergent query against this table.
$GLOBALS['fmke_vendor_reviews'] = new FMKE_Vendor_Reviews();
