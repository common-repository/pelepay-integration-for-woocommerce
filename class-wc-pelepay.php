<?php
/*
Plugin Name: Woocommerce PelePay Payment
Plugin URI: 
Description: Extends WooCommerce with PelePay payment gateway.
Version: 1.1
Author: EOI
Author URI: http://www.eoi.co.il
*/

//Pele pay currently supported credit/debit cards
define('PELEPAY_CARDTYPES', 'MC,MasterCard,VISA,VISA Credit,DELTA,VISA Debit,UKE,VISA Electron,MAESTRO,Maestro (Switch),AMEX,American Express,DC,Diner\'s Club,JCB,JCB Card,LASER,Laser');
//list of currencies this can easily be modified by adding/removing items    
define('PELEPAY_CURRENCY', 'USD,US Dollar (USD),EURO,Euro,GBP,GB Pound (GBP)');

define('VPS_PROTOCOL', 2.23);

add_action('plugins_loaded', 'pelepay_init', 0);
 
function pelepay_init() 
{
    /** failed shortcode */
    include_once('shortcodes/shortcode-failed.php');
	add_shortcode('pelepay_failed', 'get_pelepay_failed');
	if (class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Pelepay extends WC_Payment_Gateway{
		/**
		 * @var string
		 */
		var $plugin_url;
		
		/**
		 * @var string
		 */
		var $plugin_path;
		
		/**
		 * @var string
		 */
		var $template_url;

        var $failure_url; 
		var $versiongreatorthen2;
        function __construct()
        {
            global $ds_woocommerce;
            $this->pelepay_params   = array();
            $this->id               = 'pelepay';
            $this->method_title     = __('PelePay', 'woocommerce');
			// Variables
		    $this->template_url		= apply_filters( 'pelepay_template_url', 'wc-pelepay/');
			$this->plugin_url       = $this->plugin_url();
			$this->plugin_path      = $this->plugin_path();
			
			// load form fields
            $this->init_form_fields();

            // initialise settings
            $this->init_settings();

            $this->icon             = $this->settings['pelepay_button_url'];
            $this->has_fields       = true;
            
            $this->testmode         = 'no';
            // gateway urls            
            $this->test_url         = $this->settings['gatewayurl'];
            $this->live_url         = $this->settings['gatewayurl'];
                        
            // group & tidy available card types
            $cards = array();
                        
            // variables            
            $this->title            = @$this->settings['title'];
            $this->business_method  = @$this->settings['pelepay_business_method'];
            $this->cancel_url       = @$this->settings['pelepay_cancel_url'];
	        $this->success_url      = @$this->settings['pelepay_success_url'];
	        $this->failure_url      = @$this->settings['pelepay_failure_url'];			
            $this->debug            = @$this->settings['debug'];    
            $this->debugemail       = @$this->settings['debugemail'];
            if ( version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
			  // Pre 2.0
				$this->versiongreatorthen2 = false;
			} else {
			  // 2.0
				$this->versiongreatorthen2 = true;
			}        
            // actions
			add_action('init',array(&$this,'check_pelepay_response'));
		    add_action('valid-pelepay-request', array(&$this, 'successful_request') );
			add_action('valid-pelepay-failure', array(&$this, 'failure_request') );

		    add_action('woocommerce_receipt_pelepay', array(&$this, 'receipt_page'));
			
			if(!$this->versiongreatorthen2)
            add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
			else
			add_action('woocommerce_update_options_payment_gateways_'.$this->id , array(&$this, 'process_admin_options'));
            add_action('woocommerce_thankyou_pelepay', array(&$this, 'thankyou_page'));
			
    		// Failure page
   			$this->pelepay_create_page( esc_sql( _x('pelepay-order-failed', 'page_slug', 'woocommerce') ), 'woocommerce_pelepay_failure_page_id', __('Order Failed', 'woocommerce'), '[pelepay_failed]', woocommerce_get_page_id('checkout') );

        } // end __construct

        
		/** Helper functions ******************************************************/

		/**
		 * Get the plugin url.
		 *
		 * @access public
		 * @return string
		 */
		function plugin_url() {
			if ( $this->plugin_url ) return $this->plugin_url;
			return $this->plugin_url = plugins_url( basename( plugin_dir_path(__FILE__) ), basename( __FILE__ ) );
		}
	
	
		/**
		 * Get the plugin path.
		 *
		 * @access public
		 * @return string
		 */
		function plugin_path() {
			if ( $this->plugin_path ) return $this->plugin_path;
	
			return $this->plugin_path = untrailingslashit( plugin_dir_path( __FILE__ ) );
		}
	
		/**
		 * Create a page
		 *
		 * @access public
		 * @param mixed $slug Slug for the new page
		 * @param mixed $option Option name to store the page's ID
		 * @param string $page_title (default: '') Title for the new page
		 * @param string $page_content (default: '') Content for the new page
		 * @param int $post_parent (default: 0) Parent for the new page
		 * @return void
		 */
		function pelepay_create_page( $slug, $option, $page_title = '', $page_content = '', $post_parent = 0 ) {
			global $wpdb;
		
			$option_value = get_option( $option ); // print_r($option_value); die;
		
			if ( $option_value > 0 && get_post( $option_value ) )
				return;
 
			$page_found = $wpdb->get_var("SELECT ID FROM " . $wpdb->posts . " WHERE post_name = '$slug' LIMIT 1;"); 
			if ( $page_found ) :
				if ( ! $option_value || ($option_value != $page_found))
					update_option( $option, $page_found );
				return;
			endif;
		
			$page_data = array(
				'post_status' 		=> 'publish',
				'post_type' 		=> 'post',
				'post_author' 		=> 1,
				'post_name' 		=> $slug,
				'post_title' 		=> $page_title,
				'post_content' 		=> $page_content,
				'post_parent' 		=> $post_parent,
				'comment_status' 	=> 'closed'
			);
			$page_id = wp_insert_post( $page_data );
		
			update_option( $option, $page_id );
        }


        /**
         * Admin Panel Options 
         **/       
        public function admin_options()
        {
            ?>
            <h3><?php _e('PelePay', 'woocommerce'); ?></h3>
            <p><?php _e('PelePay standard works by sending the user to PelePay to enter their payment information.', 'woocommerce'); ?></p>
            <table class="form-table">
            <?php           
                // generate the settings form.
                $this->generate_settings_html();
            ?>
            </table><!--/.form-table-->
            <?php
        } // end admin_options()        

        /**
         * Initialise Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            
            // mode options
            $mode_options = array('test' => 'Test', 'live' => 'Live');            
            
            // transaction options
            $tx_options = array();
            
            // add available currencies
            $currency_options=array();
            $available_currencies = explode(',', PELEPAY_CURRENCY);
            for ($i=0; $i < count($available_currencies); $i+=2){
                $currency_options[$available_currencies[$i]] = $available_currencies[$i+1];
            }
			//  array to generate admin form
            $this->form_fields = array(
                'enabled' => array(
                                'title' => __( 'Enable/Disable', 'woocommerce' ), 
                                'type' => 'checkbox', 
                                'label' => __( 'Enable PelePay', 'woocommerce' ), 
                                'default' => 'yes'
                            ), 
                'title' => array(
                                'title' => __( 'Title', 'woocommerce' ), 
                                'type' => 'text', 
                                'description' => __( 'This is the title displayed to the user during checkout.', 'woocommerce' ), 
                                'default' => __( 'PelePay', 'woocommerce' )
                            ),
				'gatewayurl' => array(
                                'title' => __( 'Gateway URL', 'woocommerce' ), 
                                'type' => 'text', 
                                'description' => __( 'This is the PelePay Gateway Url used during checkout.', 'woocommerce' ), 
                                'default' => __( 'https://www.pelepay.co.il/pay/custompaypage.aspx' )
                            ),
				'paymentnumber' => array(
                                'title' => __( 'Payment Number (1-12)', 'woocommerce' ), 
                                'type' => 'text', 
                                'description' => __( 'Maximum number of payments to be given to the customer to pay him. You can define up to 12 payments.
.', 'woocommerce' ), 
                                'default' => __( '1' )
                            ),						 
                'pelepay_business_method' => array(
                                'title' => __( 'Business Name', 'woocommerce' ), 
                                'type' => 'text', 
                                'description' => __( 'Email address of the account holder used to identify the account beneficiary on pelepay', 'woocommerce' ), 
                                'default' =>  ''
                            ),
                'pelepay_cancel_url' => array(
                                'title' => __( 'Cancel URL', 'woocommerce' ), 
                                'type' => 'text', 
                                'description' => __( 'Please enter landing url for when customers click "cancel" during payment', 'woocommerce' ), 
                                'default' => ''
                            ),
                'pelepay_success_url' => array(
                                'title' => __( 'Success URL', 'woocommerce' ), 
                                'type' => 'text', 
                                'description' => __( 'Please enter landing URL for approved transactions', 'woocommerce' ), 
                                'default' => ''
                            ),
                'pelepay_failure_url' => array(
                                'title' => __( 'Failure URL', 'woocommerce' ), 
                                'type' => 'text', 
                                'description' => __( 'Please enter landing URL for declined transactions', 'woocommerce' ), 
                                'default' => $failure_url
                            ),
				'pelepay_button_url' => array(
                                'title' => __( 'PelePay Button URL', 'woocommerce' ), 
                                'type' => 'text', 
                                'description' => __( 'Please enter image URL for the payment button', 'woocommerce' ), 
                                'default' =>  __( 'https://www.pelepay.co.il/images/banners/respect_pp_8C.gif' )
                            )			
                );
            
            /*$this->form_fields['debug'] = array(
                                'title' => __( 'Debug', 'woocommerce' ), 
                                'type' => 'checkbox', 
                                'label' => __( 'Enable logging ', 'woocommerce' ), 
                                'default' => 'no'
                            );
            $this->form_fields['debugemail'] = array(
                                'title' => __( 'Debug Email Address', 'woocommerce' ), 
                                'type' => 'text', 
                                'description' => __( 'Email address to catch debug information.  Only available in <b>Simulator</b> &amp; <b>Test</b> modes.', 'woocommerce' ), 
                                'default' =>  get_option('admin_email')
                            );*/
        } // end init_form_fields()
        
        /**
         * Payment fields for PelePay.
         **/
        function payment_fields() 
        {
		  ?>
		  <p><?php echo __('You will be redirected to Pele-Pay website to enter your credit card information, when you place an order.')?></p>
		  <?php            
        }// payment_fields
        
		
	/**
	 * Get PelePay Args for passing to Pelepay
	 *
	 * @access public
	 * @param mixed $order
	 * @return array
	 */
	function get_pelepay_args( $order ) {
		global $woocommerce;
		$order_id = $order->id;
        
		if ($this->debug=='yes') $this->log->add( 'pelepay', 'Generating payment form for order #' . $order_id);

		$order->billing_phone = str_replace( array( '(', '-', ' ', ')', '.' ), '', $order->billing_phone );
		
		$price                = number_format($order->order_total,2,'.','');
		$address              = $order->billing_address_1;
		if($address == '')
        $address              = $order->billing_address_2;
		if($this->cancel_url != '')
		$pelepay_cancel_url   = add_query_arg( 'utm_nooverride', '1',$this->cancel_url);
		else
		$pelepay_cancel_url   = $order->get_cancel_order_url();
		if($this->success_url != '')
		$success_url          = add_query_arg( 'utm_nooverride', '1',$this->success_url);
		else
		$success_url          = add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) );
		$pelepay_failure_page = apply_filters('woocommerce_pelepay_failure_page_id', get_option('woocommerce_pelepay_failure_page_id'));
        if($this->failure_url != '')
		$failure_url          = add_query_arg( 'utm_nooverride', '1',$this->failure_url);
		else {  
		$failure_url          =	add_query_arg( 'utm_nooverride', '1',add_query_arg( 'key', $order->order_key, add_query_arg( 'order', $order->id, get_permalink($pelepay_failure_page))));
        }
		
		// PelePay Args
		$pelepay_args = 
			array(
				'business'		        => trim($this->settings['pelepay_business_method']),
    			'orderid'				=> $order_id,
	    		'amount'				=> $price,
				'description'  			=>	__('Your purchase at'),
				'Max_payments'			=> $this->settings['paymentnumber'],
				'success_return'		=> $success_url,
				'cancel_return'			=> $pelepay_cancel_url,
  				'fail_return'			=> $failure_url, 

				// Billing Address info
				'firstname'				=> $order->billing_first_name,
				'lastname'				=> $order->billing_last_name,
				'address'				=> $address,
				'postcode'				=> $order->billing_postcode,
				'country'				=> $order->billing_country,
				'email'					=> $order->billing_email,
				'phone'                 => $order->billing_phone,
				'order'                 => $order->id,
				'key'                   => $order->order_key 
			);

		// Shipping
		if ( $this->send_shipping=='yes' ) {
		    $ship_address               = $order->shipping_address_1;
		    if($ship_address == '')
            $ship_address               = $order->shipping_address_2;
			$order->shipping_phone      = str_replace( array( '(', '-', ' ', ')', '.' ), '', $order->shipping_phone );
			// If we are sending shipping, send shipping address instead of billing
			$pelepay_args['firstname']	= $order->shipping_first_name;
			$pelepay_args['lastname']	= $order->shipping_last_name;
			$pelepay_args['address']	= $ship_address;
			$pelepay_args['country']	= $order->shipping_country;
			$pelepay_args['postcode']	= $order->shipping_postcode;
			$pelepay_args['email']		= $order->shipping_email;
			$pelepay_args['phone']		= $order->shipping_phone;
		} 

		$pelepay_args = apply_filters( 'woocommerce_pelepay_args', $pelepay_args );
		return $pelepay_args;
	}

		
	/**
	 * Generate the pelepay button link
     *
     * @access public
     * @param mixed $order_id
     * @return string
     */
    function generate_pelepay_form( $order_id ) {
		global $woocommerce;

		$order = new WC_Order( $order_id );

		if ( $this->testmode == 'yes' ):
			$pelepay_adr = $this->test_url . '?';
		else :
			$pelepay_adr = $this->live_url;// . '?';
		endif;

		$pelepay_args = $this->get_pelepay_args( $order );
        

		$pelepay_args_array = array();

		foreach ($pelepay_args as $key => $value) {
			$pelepay_args_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
		}
            
		$woocommerce->add_inline_js('
			jQuery("body").block({
					message: "<img src=\"' . esc_url( apply_filters( 'woocommerce_ajax_loader_url', $woocommerce->plugin_url() . '/assets/images/ajax-loader.gif' ) ) . '\" alt=\"Redirecting&hellip;\" style=\"float:left; margin-right: 10px;\" />'.__('Thank you for your order. We are now redirecting you to pelepay to make payment.', 'woocommerce').'",
					overlayCSS:
					{
						background: "#fff",
						opacity: 0.6
					},
					css: {
				        padding:        20,
				        textAlign:      "center",
				        color:          "#555",
				        border:         "3px solid #aaa",
				        backgroundColor:"#fff",
				        cursor:         "wait",
				        lineHeight:		"32px"
				    }
				});
			jQuery("#submit_pelepay_payment_form").click();
		');

		return '<form action="'.esc_url( $pelepay_adr ).'" method="post" id="pelepay_payment_form" target="_top">
				' . implode('', $pelepay_args_array) . '
				<input type="submit" class="button-alt" id="submit_pelepay_payment_form" value="'.__('Pay via pelepay', 'woocommerce').'" /> <a class="button cancel" href="'.esc_url( $order->get_cancel_order_url() ).'">'.__('Cancel order &amp; restore cart', 'woocommerce').'</a>
			</form>';

	}
	 
        /**
        * process payment
        * 
        * @param int $order_id
        */
        function process_payment( $order_id ) 
        {
		    global $woocommerce;
			// woocommerce order instance
			if($this->versiongreatorthen2)
		        $order = new WC_Order( $order_id );
		    else
				$order = new woocommerce_order( $order_id );
			
			$pelepay_args = $this->get_pelepay_args( $order );
			$pelepay_args = http_build_query( $pelepay_args, '', '&' );
			
			if ( $this->testmode == 'yes' ):
			$pelepay_adr = $this->test_url . '?';
			else :
			$pelepay_adr = $this->live_url . '?';
			endif;
			//add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
			// Post back to get a response                
		    return array(
				'result' 	=> 'success',
				'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
			);
			
	    } // end process_payment
        
	
	
	/**
     * Output for the order received page.
     *
     * @access public
     * @return void
     */
	function receipt_page( $order ) {

		echo '<p>'.__('Thank you for your order, please click the button below to pay with PelePay.', 'woocommerce').'</p>';

		echo $this->generate_pelepay_form( $order );

	}

	
		/**
		 * Check for PelePay Response
		 *
		 * @access public
		 * @return void
		 */
		function check_pelepay_response() {
			global $woocommerce;
			@ob_clean();
			$_REQUEST = stripslashes_deep($_REQUEST);
			// Remove The Error Shown If Customer Again tries To Pay 
			if($_REQUEST['pay_for_order'] == 'true' && !empty($_REQUEST['order']))
			{
			  $this->response['result'] = '';
			  $woocommerce->clear_messages();
			}
			if($_REQUEST['Response'] == '000')
			do_action("valid-pelepay-request",$_REQUEST);
			if($_REQUEST['Response'] != '000')
			do_action("valid-pelepay-failure",$_REQUEST);
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

			if(empty($posted['index']))
			{
			  $pos = strpos($posted['utm_nooverride'],'index');
			  if ($pos !== false) {
			      $posted['index'] = trim(str_replace('1?index=','',$posted['utm_nooverride']));
               }
			}
			// Custom holds post ID
			if ( !empty($posted['index']) && !empty($posted['Response']) ) {
	
				$order_id = (int)$posted['orderid'];
				
				$order = new WC_Order( $order_id );
	
				 // Store PelePay Details
				if ( ! empty( $posted['email'] ) )
					update_post_meta( $order_id, 'Payer PelePay address', $posted['email'] );
				if ( ! empty( $posted['index'] ) )
					update_post_meta( $order_id, 'Transaction ID', $posted['index'] );
				if ( ! empty( $posted['firstname'] ) )
					update_post_meta( $order_id, 'Payer first name', $posted['firstname'] );
				if ( ! empty( $posted['lastname'] ) )
					update_post_meta( $order_id, 'Payer last name', $posted['lastname'] );
				 // Order failed
			    $order->update_status('processing', sprintf(__('Payment %s via PELEPAY.', 'woocommerce'), 'processing' ) );	
								// Payment completed
				$order->add_order_note( __('PelePay payment completed', 'woocommerce') );
				$order->payment_complete();

				if ($this->debug=='yes') $this->log->add( 'pelepay', 'Payment complete.' );
			}
		}
	    
		/**
		 * Failure Payment!
		 *
		 * @access public
		 * @param array $posted
		 * @return void
		 */
		function failure_request( $posted )
		{
		  global $woocommerce;
		  if(!empty($posted['Response'])) {
			  $order_id = (int)$posted['orderid'];
					
			  $order = new WC_Order( $order_id );
			  // Order failed
			  $order->update_status('failed', sprintf(__('Payment %s via PELEPAY.', 'woocommerce'), 'failed' ) );
			  /*START - THE ERROR CODES TO BE DISPLAYED*/
			  switch(trim($posted['Response'])) {
						case '003': 
							$msg = __('&#1492;&#1514;&#1511;&#1513;&#1512; &#1500;&#1495;&#1489;&#1512;&#1514; &#1492;&#1488;&#1513;&#1512;&#1488;&#1497;');
							break;
						case '004': 
							$msg = __('&#1505;&#1497;&#1512;&#1493;&#1489; &#1513;&#1500; &#1495;&#1489;&#1512;&#1514; &#1492;&#1488;&#1513;&#1512;&#1488;&#1497;');
							break;
						case '033': 
							$msg = __('&#1492;&#1499;&#1512;&#1496;&#1497;&#1505; &#1488;&#1497;&#1504;&#1493; &#1514;&#1511;&#1497;&#1503;');
							break;
						case '001': 
							$msg = __('&#1499;&#1512;&#1496;&#1497;&#1505; &#1488;&#1513;&#1512;&#1488;&#1497; &#1495;&#1505;&#1493;&#1501;');
							break;
						case '002': 
							$msg = __('&#1499;&#1512;&#1496;&#1497;&#1505; &#1488;&#1513;&#1512;&#1488;&#1497; &#1490;&#1504;&#1493;&#1489;');
							break;
						case '039': 
							$msg = __('&#1505;&#1508;&#1512;&#1514; &#1492;&#1489;&#1497;&#1511;&#1493;&#1512;&#1514; &#1513;&#1500; &#1492;&#1499;&#1512;&#1496;&#1497;&#1505; &#1488;&#1497;&#1504;&#1492; &#1514;&#1511;&#1497;&#1504;&#1492;');
							break;
						case '101': 
							$msg = __('&#1500;&#1488; &#1502;&#1499;&#1489;&#1491;&#1497;&#1501; &#1491;&#1497;&#1497;&#1504;&#1512;&#1505;');
							break;
						case '061': 
							$msg = __('&#1500;&#1488; &#1492;&#1493;&#1494;&#1503; &#1502;&#1505;&#1508;&#1512; &#1499;&#1512;&#1496;&#1497;&#1505; &#1488;&#1513;&#1512;&#1488;&#1497;');
							break;
						case '157': 
							$msg = __('&#1499;&#1512;&#1496;&#1497;&#1505; &#1488;&#1513;&#1512;&#1488;&#1497; &#1514;&#1497;&#1497;&#1512;');
							break;
						case '133': 
							$msg = __('&#1499;&#1512;&#1496;&#1497;&#1505; &#1488;&#1513;&#1512;&#1488;&#1497; &#1514;&#1497;&#1497;&#1512;');
							break;
						case '036': 
							$msg = __('&#1508;&#1490; &#1514;&#1493;&#1511;&#1507; &#1492;&#1499;&#1512;&#1496;&#1497;&#1505;');
							break;							
					}
			  /*END - THE ERROR CODES TO BE DISPLAYED */
			  $pelepay_response_msgcode = $posted['Response'];
			  $this->response['result'] = '';
			  $woocommerce->clear_messages();
			  $woocommerce->add_error("The Error Response From PelePay is : ".$pelepay_response_msgcode." - ".$msg);
			  $this->response['result'] = "The Error Response From PelePay is : ".$pelepay_response_msgcode." - ".$msg;
		  }
		} 	
		
        /**
        * Thank you page
        * 
        */
        function thankyou_page() 
        {
            if ($this->description) echo wpautop(wptexturize($this->description));
        } // end thankyou_page
           
        /**
         * Send debug email
         * 
         * @param string $msg
         **/
        function send_debug_email( $msg)
        {
            if($this->debug=='yes' AND $this->mode!=live AND !empty($this->debugemail)){
                // send debugemail
                wp_mail( $this->debugemail, __('PelePay Debug', 'woocommerce'), $msg );
            }
            
            
            
        } // end send_debug_email
        
        /**
        * add pelepay parameters for later processing
        * 
        * @param string $param
        * @param mixed $value
        */
        public function add_param($param, $value) {
            $this->params[$param] = $value;   
        } // end add_param
    }
	}
	
	if (class_exists( 'WC_Pelepay' ) ) {
		/**
		 * Init pelepay class
		 */
		if ( empty( $GLOBALS['wc_pelepay'] ) ) 
		$GLOBALS['wc_pelepay'] = new WC_Pelepay();
    }
} // end pelepay_init

function add_pelepay( $methods ) 
{
    $methods[] = 'WC_Pelepay'; 
    return $methods;
} // end add_pelepay
add_filter('woocommerce_payment_gateways', 'add_pelepay' );
?>