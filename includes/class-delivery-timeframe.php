<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-product "Delivery Timeframe" field - lets a vendor say how fast a
 * specific product actually ships (a bouquet made to order same-day reads
 * very differently from an event-rental tent that needs lead time), and
 * shows that promise on the product page so shoppers know what to expect
 * before they buy.
 *
 * Lives in the Shipping tab, both in the classic WP Admin product editor
 * and in Dokan's vendor-dashboard product form, since either can be used
 * to manage a product on this marketplace. Two fields:
 *   - a fixed timeframe choice (kept as a small set so it stays
 *     meaningful/filterable, unlike a free-text guess)
 *   - an optional short note for specifics, e.g. an order cut-off time.
 */
class FMKE_Delivery_Timeframe {

	const META_TIMEFRAME = '_fmke_delivery_timeframe';
	const META_NOTE       = '_fmke_delivery_note';

	/**
	 * Value => display label. The blank first option keeps "not set"
	 * distinct from any real choice, so an unconfigured product doesn't
	 * silently claim "Same-Day Delivery" once a label is chosen.
	 */
	const OPTIONS = array(
		''          => '— Not set —',
		'same-day'  => 'Same-Day Delivery',
		'next-day'  => 'Next-Day Delivery',
		'2-3-days'  => '2-3 Days',
		'3-5-days'  => '3-5 Days',
		'made-to-order' => 'Made to Order (contact vendor)',
	);

	public function __construct() {
		// WP Admin classic product editor - Shipping tab.
		add_action( 'woocommerce_product_options_shipping', array( $this, 'render_admin_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_admin_fields' ) );

		// Dokan vendor dashboard product form (new + edit both save
		// through the same hook - see save_dokan_fields()).
		add_action( 'dokan_product_edit_after_shipping', array( $this, 'render_dokan_edit_fields' ), 10, 2 );
		add_action( 'dokan_new_product_form', array( $this, 'render_dokan_new_fields' ) );
		add_action( 'dokan_process_product_meta', array( $this, 'save_dokan_fields' ) );

		// Storefront: show the promise on the product page, right under
		// the price so it's seen before Add to Cart.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_frontend' ), 11 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * @param int $product_id
	 * @return string One of the OPTIONS keys, '' if unset/invalid.
	 */
	private function get_timeframe( $product_id ) {
		$value = get_post_meta( $product_id, self::META_TIMEFRAME, true );
		return isset( self::OPTIONS[ $value ] ) ? $value : '';
	}

	private function get_note( $product_id ) {
		return get_post_meta( $product_id, self::META_NOTE, true );
	}

	// -----------------------------------------------------------------
	// WP Admin
	// -----------------------------------------------------------------

	public function render_admin_fields() {
		global $post;

		echo '<div class="options_group">';

		woocommerce_wp_select( array(
			'id'      => self::META_TIMEFRAME,
			'label'   => 'Delivery Timeframe',
			'desc_tip' => true,
			'description' => 'How quickly this specific product ships once ordered.',
			'options' => self::OPTIONS,
			'value'   => $this->get_timeframe( $post->ID ),
		) );

		woocommerce_wp_text_input( array(
			'id'          => self::META_NOTE,
			'label'       => 'Delivery Note (optional)',
			'desc_tip'    => true,
			'description' => 'Shown next to the timeframe, e.g. "Order before 2 PM for same-day delivery".',
			'value'       => $this->get_note( $post->ID ),
		) );

		echo '</div>';
	}

	public function save_admin_fields( $post_id ) {
		if ( isset( $_POST[ self::META_TIMEFRAME ] ) ) {
			$value = sanitize_text_field( wp_unslash( $_POST[ self::META_TIMEFRAME ] ) );
			$value = isset( self::OPTIONS[ $value ] ) ? $value : '';
			update_post_meta( $post_id, self::META_TIMEFRAME, $value );
		}
		if ( isset( $_POST[ self::META_NOTE ] ) ) {
			update_post_meta( $post_id, self::META_NOTE, sanitize_text_field( wp_unslash( $_POST[ self::META_NOTE ] ) ) );
		}
	}

	// -----------------------------------------------------------------
	// Dokan vendor dashboard
	// -----------------------------------------------------------------

	private function fields_markup( $timeframe_value, $note_value ) {
		ob_start();
		?>
		<div class="dokan-form-group">
			<label for="<?php echo esc_attr( self::META_TIMEFRAME ); ?>" class="form-label">Delivery Timeframe</label>
			<select name="<?php echo esc_attr( self::META_TIMEFRAME ); ?>" id="<?php echo esc_attr( self::META_TIMEFRAME ); ?>" class="dokan-form-control">
				<?php foreach ( self::OPTIONS as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $timeframe_value, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="help-block">How quickly this product ships once ordered.</span>
		</div>
		<div class="dokan-form-group">
			<label for="<?php echo esc_attr( self::META_NOTE ); ?>" class="form-label">Delivery Note (optional)</label>
			<input type="text" name="<?php echo esc_attr( self::META_NOTE ); ?>" id="<?php echo esc_attr( self::META_NOTE ); ?>" class="dokan-form-control" value="<?php echo esc_attr( $note_value ); ?>" placeholder="e.g. Order before 2 PM for same-day delivery" />
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Edit form - hook passes ($post, $post_id) per Dokan's docs.
	 */
	public function render_dokan_edit_fields( $post, $post_id ) {
		echo $this->fields_markup( $this->get_timeframe( $post_id ), $this->get_note( $post_id ) );
	}

	/**
	 * New-product form has no product ID yet, so both fields start blank.
	 */
	public function render_dokan_new_fields() {
		echo $this->fields_markup( '', '' );
	}

	/**
	 * Fires on both create and update of a vendor's product, after Dokan
	 * has already verified the form nonce/capability - same trust level
	 * this plugin already gives dokan_store_profile_saved elsewhere.
	 */
	public function save_dokan_fields( $post_id ) {
		if ( isset( $_POST[ self::META_TIMEFRAME ] ) ) {
			$value = sanitize_text_field( wp_unslash( $_POST[ self::META_TIMEFRAME ] ) );
			$value = isset( self::OPTIONS[ $value ] ) ? $value : '';
			update_post_meta( $post_id, self::META_TIMEFRAME, $value );
		}
		if ( isset( $_POST[ self::META_NOTE ] ) ) {
			update_post_meta( $post_id, self::META_NOTE, sanitize_text_field( wp_unslash( $_POST[ self::META_NOTE ] ) ) );
		}
	}

	// -----------------------------------------------------------------
	// Storefront display
	// -----------------------------------------------------------------

	public function render_frontend() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$timeframe = $this->get_timeframe( $product->get_id() );
		if ( '' === $timeframe ) {
			return;
		}

		$note = $this->get_note( $product->get_id() );

		echo '<p class="fmke-delivery-timeframe">';
		echo '<span class="fmke-delivery-timeframe-icon" aria-hidden="true">🚚</span> ';
		echo esc_html( self::OPTIONS[ $timeframe ] );
		if ( $note ) {
			echo ' <span class="fmke-delivery-timeframe-note">&ndash; ' . esc_html( $note ) . '</span>';
		}
		echo '</p>';
	}

	public function enqueue() {
		if ( ! is_product() ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', $this->get_inline_css() );
	}

	private function get_inline_css() {
		return <<<CSS
.fmke-delivery-timeframe {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0 0 14px;
	font-size: 14px;
	font-weight: 600;
	color: #1F3D2C;
}
.fmke-delivery-timeframe-icon {
	font-size: 15px;
	line-height: 1;
}
.fmke-delivery-timeframe-note {
	font-weight: 400;
	color: #5A5648;
}
CSS;
	}
}

new FMKE_Delivery_Timeframe();
