<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} 
/**
 * Get form posted data from WooCommerce: in some cases, it's serialized in a "post_data" key.
 *
 * @return array
 */
function get_wc_posted_data() {
	$form_data = $_POST;

	if ( isset( $_POST['post_data'] ) ) {
		parse_str( $_POST['post_data'], $form_data );
	}

	return $form_data;
}
/**
 * Get last df_cedula used by user
 *
 * @return array
 */
function get_last_df_cedula() { 
    // For logged in users only
    if ( is_user_logged_in() ){

        $user_id = get_current_user_id(); // The current user ID

        // Get the WC_Customer instance Object for the current user
        $customer = new WC_Customer( $user_id );

        // Get the last WC_Order Object instance from current customer
        $last_order = $customer->get_last_order();
        if($last_order==null || !is_array($last_order->get_meta_data())) return '';
		
        foreach ($last_order->get_meta_data() as $key => $value)
          if (isset($value->get_data()['key'])&&$value->get_data()['key']=='df_cedula')
            $df_cedulaArray[]=$value->get_data()['value'];
        $df_cedula=$df_cedulaArray[sizeof($df_cedulaArray??[])-1];
        return isset($df_cedula)?$df_cedula:'';
    }
	return '';
} 
/**
 * Display our custom extra fields: a checkbox and a select dropdown.
 *
 * @return void
 */
function display_custom_shipping_methods() {
	?> 
		<p class="form-row"> 
			<label>
				<?php esc_html_e( 'Identificación:', 'datafast' ); ?>
			</label>
			<span class="woocommerce-input-wrapper"> 
				<input class="df-custom-field" type="text" maxlength="10" name="df_cedula" value="<?php echo get_last_df_cedula() ?>" id="df_cedula">
			</span> 	
		</p> 

	<script>
		// When one of our custom field value changes, tell WC to update the checkout data (AJAX request to the back-end).
		jQuery(document).ready(function($) {
			$('form.checkout').on('change', '.df-custom-field', function() {
				$('body').trigger('update_checkout');
			});
		});
	</script>
	<?php
}
add_action( 'woocommerce_checkout_shipping', __NAMESPACE__ . '\\display_custom_shipping_methods', 10 );
 
/**
 * Validate checkout fields before processing the WooCommerce "order"
 *
 * @return void
 */
function validate_all_checkout_fields() {
	$errors = [];

	if ( isset( $_POST['df_cedula'] ) ) {
		if (strlen($_POST['df_cedula'])==0)
			$errors[] = __( '<strong>Ingresa una Identificación.</strong>', 'datafast' );
        if (strlen($_POST['df_cedula'])!=10)
            $errors[] = __( '<strong>Ingresa una Identificación valida.</strong>', 'datafast' );
		
	}else
        $error[]= __( '<strong>No se a capturado el campo Identificación.</strong>', 'datafast' );

	/**
	 * If we have errors, 
	 */
	if ( ! empty( $errors ) ) {
		foreach ( $errors as $error ) {
			wc_add_notice( $error, 'error' );
		}
	}
}
add_action( 'woocommerce_checkout_process', __NAMESPACE__ . '\\validate_all_checkout_fields' );
 
/**
 * For tutorial sake, enable the order process without payment.
 *
 * @param boolean $needed
 * @return boolean
 */
function woocommerce_order_needs_payment( $needed ) {
	return true;
}
add_filter( 'woocommerce_cart_needs_payment', __NAMESPACE__ . '\\woocommerce_order_needs_payment' );

/**
 * Allow our custom checkout data (emergency checkbox & level) to be included in order data array.
 *
 * @param array $data
 * @return array
 */
function add_custom_checkout_data_to_order_data_array( $data ) {
	$custom_keys = [
		'df_cedula',
	];

	foreach ( $custom_keys as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			$data[ $key ] = sanitize_text_field( $_POST[ $key ] );
		}
	}

	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', __NAMESPACE__ . '\\add_custom_checkout_data_to_order_data_array', 10, 2 );

/**
 * Save our custom checkout data on the order metadata.
 *
 * @param integer $order_id
 * @param array $data Posted data.
 * @return void
 */
function save_custom_checkout_data_in_order_metadata( $order_id, $data ) {
	$custom_keys = [
		'df_cedula',
	];
	$order = wc_get_order( $order_id );

	foreach ( $custom_keys as $key ) {
		if ( isset( $data[ $key ] ) ) {
			$order->update_meta_data( $key, $data[ $key ] );
            $_POST[$key]=$data[ $key ];
		}
	}

	$order->save();
}
add_action( 'woocommerce_checkout_update_order_meta', __NAMESPACE__ . '\\save_custom_checkout_data_in_order_metadata', 10, 2 );

