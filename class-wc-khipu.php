<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
Plugin Name: WooCommerce khipu
Plugin URI: https://khipu.com
Description: khipu Payment gateway for woocommerce
Version: 1.0
Author: khipu
Author URI: https://khipu.com
 */
 
add_action('plugins_loaded', 'woocommerce_khipu_init', 0);
function woocommerce_khipu_init(){ 
 

require_once "lib-khipu/src/Khipu.php";
 
class WC_Gateway_khipu extends WC_Payment_Gateway {

	var $notify_url;

    /**
     * Constructor for the gateway.
     *
     * @access public
     * @return void
     */
	public function __construct() {
	global $woocommerce;

        $this->id           = 'khipu';
        $this->icon         = plugins_url( 'images/buttons/50x25.png' , __FILE__ );
        $this->has_fields   = false;
        $this->liveurl      = 'https://khipu.com/api/1.1/createPaymentPage';
        $this->method_title = __( 'khipu', 'woocommerce' );
        $this->notify_url   = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_khipu', home_url( '/' ) ) );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title 			= $this->get_option( 'title' );
		$this->description 		= $this->get_option( 'description' );
		$this->receiver_id		= $this->get_option( 'receiver_id' );
		$this->secret 			= $this->get_option( 'secret' );


		// Actions
		add_action( 'valid-khipu-ipn-request', array( $this, 'successful_request' ) );
		add_action( 'woocommerce_receipt_khipu', array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Payment listener/API hook
		add_action( 'woocommerce_api_wc_gateway_khipu', array( $this, 'check_ipn_response' ) );

		if ( !$this->is_valid_for_use() ) $this->enabled = false;
    }


    /**
     * Check if this gateway is enabled and available in the user's country
     *
     * @access public
     * @return bool
     */
    function is_valid_for_use() {
        if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_khipu_supported_currencies', array( 'CLP' ) ) ) ) return false;

        return true;
    }

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {

		?>
		<h3><?php _e( 'khipu', 'woocommerce' ); ?></h3>
		<p><?php _e( 'khipu redirige al cliente a la página de pago', 'woocommerce' ); ?></p>

    	<?php if ( $this->is_valid_for_use() ) : ?>

			<table class="form-table">
			<?php
    			// Generate the HTML For the settings form.
    			$this->generate_settings_html();
			?>
			</table><!--/.form-table-->

		<?php else : ?>
            <div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: <?php _e( 'khipu does not support your store currency.', 'woocommerce' ); ?></p></div>
		<?php
			endif;
	}


    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access public
     * @return void
     */
    function init_form_fields() {

    	$this->form_fields = array(
			'enabled' => array(
							'title' => __( 'Enable/Disable', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'Enable khipu', 'woocommerce' ),
							'default' => 'yes'
						),
			'title' => array(
							'title' => __( 'Title', 'woocommerce' ),
							'type' => 'text',
							'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
							'default' => __( 'khipu', 'woocommerce' ),
							'desc_tip'      => true,
						),
			'description' => array(
							'title' => __( 'Description', 'woocommerce' ),
							'type' => 'textarea',
							'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
							'default' => __( 'Paga usando tu cuenta bancaria con khipu.', 'woocommerce' )
						),
			'receiver_id' => array(
							'title' => __( 'Id de cobrador', 'woocommerce' ),
							'type' 			=> 'text',
							'description' => __( 'Ingrese su Id de cobrador. Se obtiene en https://khipu.com/merchant/profile', 'woocommerce' ),
							'default' => '',
							'desc_tip'      => true
						),
			'secret' => array(
							'title' => __( 'Llave', 'woocommerce' ),
							'type' 			=> 'text',
							'description' => __( 'Ingrese su llave secreta. Se obtiene en https://khipu.com/merchant/profile', 'woocommerce' ),
							'default' => '',
							'desc_tip'      => true
						)
			);

    }




    function generate_khipu_form( $order_id ) {
		global $woocommerce;

		$order = new WC_Order( $order_id );
		
		$Khipu = new Khipu();
        $Khipu->authenticate($this->receiver_id, $this->secret);
		$khipu_service = $Khipu->loadService('CreatePaymentPage');

		$item_names = array();

		if ( sizeof( $order->get_items() ) > 0 )
			foreach ( $order->get_items() as $item )
				if ( $item['qty'] )
					$item_names[] = $item['name'] . ' x ' . $item['qty'];

		$khipu_service->setParameter('subject', 'Orden ' . $order->get_order_number(). ' - ' . get_bloginfo('name'));
		$khipu_service->setParameter('body', implode( ', ', $item_names ));
		$khipu_service->setParameter('amount', number_format( $order->get_total(), 0, ',', '' ));
		$khipu_service->setParameter('transaction_id', ltrim( $order->get_order_number(), '#' ));
		$khipu_service->setParameter('custom', serialize( array( $order_id, $order->order_key ) ));
		$khipu_service->setParameter('payer_email', $order->billing_email);
		$khipu_service->setParameter('notify_url', $this->notify_url);
		$khipu_service->setParameter('return_url', $this->get_return_url( $order ) );
			
		return $khipu_service->renderForm(plugins_url( 'images/buttons/200x50.png' , __FILE__ ));
	}


    /**
     * Process the payment and return the result
     *
     * @access public
     * @param int $order_id
     * @return array
     */
	function process_payment( $order_id ) {

		$order = new WC_Order( $order_id );
			return array(
				'result' 	=> 'success',
				'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay' ))))
			);

	}


    /**
     * Output for the order received page.
     *
     * @access public
     * @return void
     */
	function receipt_page( $order ) {

		echo '<p>'.__( 'Gracias por su orden. Por favor presione el siguiente botón para pagar con khipu.', 'woocommerce' ).'</p>';

		echo $this->generate_khipu_form( $order );

	}

	/**
	 * Check PayPal IPN validity
	 **/
	function check_ipn_request_is_valid() {
		global $woocommerce;
                $Khipu = new Khipu();
		$_POST = array_map('stripslashes', $_POST);
        	$Khipu->authenticate($this->receiver_id, $this->secret);
                $khipu_service = $Khipu->loadService('VerifyPaymentNotification');
		$khipu_service->setDataFromPost();
		if($_POST['receiver_id'] != $this->receiver_id)
			return false;
		
		$verify = $khipu_service->verify();
		return $verify['response'] == 'VERIFIED';
    }


	/**
	 * Check for PayPal IPN Response
	 *
	 * @access public
	 * @return void
	 */
	function check_ipn_response() {
		@ob_clean();

    	if ( ! empty( $_POST ) && $this->check_ipn_request_is_valid() ) {

    		header( 'HTTP/1.1 200 OK' );

        	do_action( "valid-khipu-ipn-request", $_POST );

		} else {

			wp_die( "khipu notification validation failed" );

   		}

	}


	/**
	 * Successful Payment!
	 *
	 * @access public
	 * @param array $posted
	 * @return void
	 */
	function successful_request( $posted ) {
		global $woocommerce;

		$posted = stripslashes_deep( $posted );

		// Custom holds post ID
	    if ( ! empty( $posted['transaction_id'] ) && ! empty( $posted['custom'] ) ) {

		$order = $this->get_khipu_order( $posted );

	            	if ( $order->status == 'completed' ) {
	            		 exit;
	            	}

	            	// Check valid txn_type
	                $order->add_order_note( __( 'Pago con khipu verificado', 'woocommerce' ) );
	                $order->payment_complete();

	    }

	}


	/**
	 * get_khipu_order function.
	 *
	 * @access public
	 * @param mixed $posted
	 * @return void
	 */
	function get_khipu_order( $posted ) {
		$custom = maybe_unserialize( $posted['custom'] );

    	// Backwards comp for IPN requests
    	if ( is_numeric( $custom ) ) {
	    	$order_id = (int) $custom;
	    	$order_key = $posted['transaction_id'];
    	} elseif( is_string( $custom ) ) {
	    	$order_id = (int) str_replace( $this->invoice_prefix, '', $custom );
	    	$order_key = $custom;
    	} else {
    		list( $order_id, $order_key ) = $custom;
		}

		$order = new WC_Order( $order_id );

		if ( ! isset( $order->id ) ) {
			// We have an invalid $order_id, probably because invoice_prefix has changed
			$order_id 	= woocommerce_get_order_id_by_order_key( $order_key );
			$order 		= new WC_Order( $order_id );
		}

		// Validate key
		if ( $order->order_key !== $order_key ) {
        	if ( $this->debug=='yes' )
        		$this->log->add( 'paypal', 'Error: Order Key does not match invoice.' );
        	exit;
        }

        return $order;
	}

}

   /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_khipu_gateway($methods) {
        $methods[] = 'WC_Gateway_khipu';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_khipu_gateway' );

function woocommerce_khipu_add_clp_currency($currencies) {
    $currencies["CLP"] = 'Pesos Chilenos';
    return $currencies;
}

function woocommerce_khipu_add_clp_currency_symbol($currency_symbol, $currency) {
    switch ($currency) {
        case 'CLP': $currency_symbol = '$';
            break;
    }
    return $currency_symbol;
}

	add_filter('woocommerce_currencies', 'woocommerce_khipu_add_clp_currency', 10, 1);
	add_filter('woocommerce_currency_symbol', 'woocommerce_khipu_add_clp_currency_symbol', 10, 2);



}
