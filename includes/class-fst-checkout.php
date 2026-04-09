<?php
/**
 * WooCommerce checkout integration — SMS notification opt-in fields.
 *
 * Adds fields at checkout for customers to opt-in to shipment SMS
 * notifications via email-to-SMS carrier gateways.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Checkout {

	/**
	 * Email-to-SMS gateway domains keyed by carrier slug.
	 *
	 * @var array
	 */
	private $gateways = array(
		'att'     => '@txt.att.net',
		'verizon' => '@vtext.com',
		'tmobile' => '@tmomail.net',
		'sprint'  => '@messaging.sprintpcs.com',
	);

	/**
	 * Statuses available for SMS notifications.
	 *
	 * @var array
	 */
	private $available_statuses = array(
		'in_transit'       => 'In Transit',
		'out_for_delivery' => 'Out for Delivery',
		'delivered'        => 'Delivered',
	);

	public function __construct() {
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_sms_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_sms_fields' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_sms_fields' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_js' ) );
	}

	/**
	 * Render SMS opt-in fields on the checkout page.
	 *
	 * @param WC_Checkout $checkout
	 */
	public function render_sms_fields( $checkout ) {
		if ( ! get_option( 'fst_sms_notifications', false ) ) {
			return;
		}

		echo '<div id="fst-sms-fields">';
		echo '<h3>' . esc_html__( 'Shipment Text Notifications', 'fishotel-shiptracker' ) . '</h3>';
		echo '<p class="fst-sms-description">'
			. esc_html__( 'Want text updates about your order? We\'ll send you a quick text when your fish are on the way!', 'fishotel-shiptracker' )
			. '</p>';

		woocommerce_form_field( '_fst_sms_enabled', array(
			'type'  => 'checkbox',
			'class' => array( 'form-row-wide' ),
			'label' => __( 'Yes, send me text updates', 'fishotel-shiptracker' ),
		), $checkout->get_value( '_fst_sms_enabled' ) );

		echo '<div id="fst-sms-options" style="display:none;">';

		// Carrier dropdown.
		$carrier_options = array( '' => __( 'Select your carrier...', 'fishotel-shiptracker' ) );
		foreach ( $this->gateways as $key => $domain ) {
			$carrier_options[ $key ] = $this->get_carrier_label( $key );
		}

		woocommerce_form_field( '_fst_sms_carrier', array(
			'type'    => 'select',
			'class'   => array( 'form-row-wide' ),
			'label'   => __( 'Mobile Carrier', 'fishotel-shiptracker' ),
			'options' => $carrier_options,
		), $checkout->get_value( '_fst_sms_carrier' ) );

		// Status checkboxes.
		echo '<p class="form-row form-row-wide fst-sms-statuses-label">';
		echo '<label>' . esc_html__( 'Which updates do you want?', 'fishotel-shiptracker' ) . '</label>';
		echo '</p>';

		echo '<div class="form-row form-row-wide fst-sms-statuses">';
		foreach ( $this->available_statuses as $key => $label ) {
			$checked = ( 'out_for_delivery' === $key ) ? 'checked="checked"' : '';
			$desc    = $this->get_status_description( $key );
			printf(
				'<label class="fst-sms-status-option"><input type="checkbox" name="_fst_sms_statuses[]" value="%s" %s /> %s<span class="fst-sms-status-desc"> — %s</span></label><br>',
				esc_attr( $key ),
				$checked,
				esc_html( $label ),
				esc_html( $desc )
			);
		}
		echo '</div>';

		echo '</div>'; // #fst-sms-options
		echo '</div>'; // #fst-sms-fields
	}

	/**
	 * Validate SMS fields during checkout.
	 */
	public function validate_sms_fields() {
		if ( ! get_option( 'fst_sms_notifications', false ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce.
		if ( empty( $_POST['_fst_sms_enabled'] ) ) {
			return;
		}

		$carrier = isset( $_POST['_fst_sms_carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['_fst_sms_carrier'] ) ) : '';
		if ( empty( $carrier ) || ! isset( $this->gateways[ $carrier ] ) ) {
			wc_add_notice( __( 'Please select your mobile carrier for SMS notifications.', 'fishotel-shiptracker' ), 'error' );
		}

		$statuses = isset( $_POST['_fst_sms_statuses'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['_fst_sms_statuses'] ) ) : array();
		if ( empty( $statuses ) ) {
			wc_add_notice( __( 'Please select at least one shipment status for SMS notifications.', 'fishotel-shiptracker' ), 'error' );
		}

		$phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		if ( empty( $phone ) ) {
			wc_add_notice( __( 'A billing phone number is required for SMS notifications.', 'fishotel-shiptracker' ), 'error' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save SMS preferences to order meta.
	 *
	 * @param int $order_id
	 */
	public function save_sms_fields( $order_id ) {
		if ( ! get_option( 'fst_sms_notifications', false ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce.
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! empty( $_POST['_fst_sms_enabled'] ) ) {
			$carrier  = isset( $_POST['_fst_sms_carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['_fst_sms_carrier'] ) ) : '';
			$statuses = isset( $_POST['_fst_sms_statuses'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['_fst_sms_statuses'] ) ) : array();

			$order->update_meta_data( '_fst_sms_enabled', 'yes' );
			$order->update_meta_data( '_fst_sms_carrier', $carrier );
			$order->update_meta_data( '_fst_sms_statuses', $statuses );
		} else {
			$order->update_meta_data( '_fst_sms_enabled', 'no' );
		}

		$order->save();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Enqueue inline JS for the SMS toggle on the checkout page.
	 */
	public function enqueue_checkout_js() {
		if ( ! is_checkout() ) {
			return;
		}

		$js = "(function($){
			function fstToggleSms(){
				var checked=$('#_fst_sms_enabled').is(':checked');
				$('#fst-sms-options')[checked?'slideDown':'slideUp'](200);
			}
			$(document).on('change','#_fst_sms_enabled',fstToggleSms);
			$(document).ready(fstToggleSms);
		})(jQuery);";

		wp_add_inline_script( 'wc-checkout', $js );
	}

	/**
	 * Get a human-readable carrier label.
	 *
	 * @param string $key Carrier slug.
	 * @return string
	 */
	private function get_carrier_label( $key ) {
		$labels = array(
			'att'     => 'AT&T',
			'verizon' => 'Verizon',
			'tmobile' => 'T-Mobile',
			'sprint'  => 'Sprint',
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Get a short description for a status checkbox.
	 *
	 * @param string $key Status slug.
	 * @return string
	 */
	private function get_status_description( $key ) {
		$descriptions = array(
			'in_transit'       => __( 'when your package starts moving', 'fishotel-shiptracker' ),
			'out_for_delivery' => __( 'when it\'s on the truck (recommended!)', 'fishotel-shiptracker' ),
			'delivered'        => __( 'confirmation of delivery', 'fishotel-shiptracker' ),
		);
		return isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
	}
}
