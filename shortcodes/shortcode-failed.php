<?php
/**
 * Thankyou Shortcode
 *
 * The thankyou page displays after successful checkout and can be hooked into by payment gateways.
 *
 * @author 		WooThemes
 * @category 	Shortcodes
 * @package 	WooCommerce/Shortcodes/Thankyou
 * @version     1.6.4
 */

/**
 * Get the thankyou shortcode content.
 *
 * @access public
 * @param array $atts
 * @return string
 */
function get_pelepay_failed( $atts ) {
	global $woocommerce;
	return $woocommerce->shortcode_wrapper('pelepay_failed', $atts);
}


/**
 * Outputs the thankyou page
 *
 * @access public
 * @param mixed $atts
 * @return void
 */
function pelepay_failed( $atts ) {
	global $woocommerce;
    global $wc_pelepay;
	
	$woocommerce->nocache();

	$woocommerce->show_messages();

	$order = false;

	// Pay for order after checkout step
	if (isset($_GET['order'])) $order_id = $_GET['order']; else $order_id = 0;
	if (isset($_GET['key'])) $order_key = $_GET['key']; else $order_key = '';

	// Empty awaiting payment session
	unset($_SESSION['order_awaiting_payment']);

	if ($order_id > 0) :
		$order = new WC_Order( $order_id );
		if ($order->order_key != $order_key) :
			unset($order);
		endif;
	endif;
	//echo "<PRE>"; print_r($wc_pelepay->plugin_path); die;
	$default_path = $wc_pelepay->plugin_path.'/templates/';
///include( '../../../templates/checkout/failed.php' );
    woocommerce_get_template('checkout/failed.php', array( 'order' => $order ) , $wc_pelepay->plugin_path,$default_path);
}