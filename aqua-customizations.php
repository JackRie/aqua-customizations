<?php 
/**
 * Plugin Name: Aqua Customizations
 * Description: Plugin that extends WordPress for Aqua's Integration needs.
 * Version: 1.0
 * Author: Jackie
 * Author URI: https://github.com/JackRie/aqua-customizations
 * Text Domain: aqua
 */

if( ! defined('ABSPATH') ) exit; //Exit if accessed directly

define( 'AQUA_DIR', dirname(__FILE__).'/' );

require_once AQUA_DIR . './includes/classes/class-wps-extend-plugin.php';

require_once AQUA_DIR . './includes/classes/class-wps-extend-plugin.php';

// Extend WooCommerce Warranty
new WPS_Extend_Plugin( 'woocommerce-warranty/woocommerce-warranty.php', __FILE__, '1.9', 'aqua' );

// Add the below line to class-warranty-coupons.php to line 63 in woocommerce-warranty plugin
// apply_filters('after_warranty_create_coupon', $coupon_id, $order_id, $warranty_id);

class AquaCustomizations {

    function __construct() {
        add_action( 'init', array($this, 'add_custom_order_status') );
        add_filter('wc_order_statuses', array($this, 'add_custom_status_to_order_statuses') );
        add_action( 'init', array($this, 'warranty_add_custom_statuses'), 30 );
        add_action( 'admin_menu', array( $this, 'add_custom_settings_page_to_warranties' ), 20 );
        add_action('rest_api_init', array($this, 'returns_rest_route'));
        add_action('wc_warranty_status_updated', array($this, 'aqua_get_return_info'), 10, 3);
        add_filter( 'after_warranty_create_coupon', array($this, 'add_custom_data_to_coupon_data'), 10, 3 );
        add_action( 'woocommerce_coupon_options', array($this, 'add_coupon_text_field'), 10 );
    }

    /**
     * Adding Custom Order Status To WooCommerce Orders For Partial Shipments
     */
    function add_custom_order_status() {
        register_post_status('wc-partially-shipped', array(
            'label'                     => 'Partially Shipped',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Partially Shipped <span class="count">(%s)</span>', 'Partially Shipped <span class="count">(%s)</span>' )
        ) );
    }

    /**
     * Adding Custom Order Status To Order Statuses Array
     */
    function add_custom_status_to_order_statuses($order_statuses) {

        $new_order_statuses = array();
    
        foreach($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;
            if('wc-completed' === $key) {
                $new_order_statuses['wc-partially-shipped'] = 'Partially Shipped';
            }
        }
        return $new_order_statuses;
    }

    /************************************************************
     * Begin WooCommerce Warranty (Returns) Integration
     ************************************************************/

    // Create Custom Status For Sending Return Data To Syteline (Jitterbit)
    function warranty_add_custom_statuses() {
        if (! function_exists('warranty_get_statuses')) return;

        // Statuses to add
        $statuses = array('Send Return To Syteline', 'Request Customer Shipment', 'Send Customer Return Label');

        foreach ( $statuses as $status ) {
            $term = get_term_by( 'name', $status, 'shop_warranty_status' );

            if (! $term ) {
                wp_insert_term( $status, 'shop_warranty_status' );
            } 
            
        }
    }

    // Add Custom Settings Page As Submenu Under Warranties Plugin
    function add_custom_settings_page_to_warranties() {
        add_submenu_page( 'warranties', __( 'Custom Settings', 'wc_warranty' ), __( 'Custom Settings', 'wc_warranty' ), 'manage_warranties', 'warranties-custom', array($this, 'warranty_custom_settings'), 6 );
        add_settings_section( 'aqua-api-section', "API Settings", null, 'warranties-custom' );
        add_settings_field('aqua-api-call', 'Jitterbit API URL', array($this, 'textInputHtml'), 'warranties-custom', 'aqua-api-section', array('name' => 'aqua-api-call'));
        register_setting('aqua', 'aqua-api-call', array('sanitize_callback' => 'sanitize_text_field', 'default' => NULL));
    }

    // Callback for Text Input HTML
    function textInputHtml($args) { ?>
        <input type="text" name="<?php echo $args['name']?>" value="<?php echo esc_attr(get_option($args['name']));?>">
    <?php
    }

    // Custom Setting Page HTML
    function warranty_custom_settings() { ?>
        <div class="wrap">
            <h2><?php _e("Aqua's Custom Settings") ?></h2>
            <h3>Use the fields below to set up creditials for Jitterbit API Call.</h3>
            <form action="options.php" method="POST">
                <?php 
                settings_fields('aqua');
                do_settings_sections('warranties-custom'); 
                submit_button();
                ?>
            </form>
        </div>
    <?php
    }

    // API Call for Our Send Return To Jitterbit Status
    function aqua_send_return_to_jitterbit($body) {
        $url = get_option('aqua-api-call');
        $request = new WP_Http();
        $response = $request->post($url, array(
            'headers' => array(
                'Cookie' => '_ga=GA1.2.1569990160.1634151587; XSRF-TOKEN=eyJpdiI6IlFLMmM3VUVDdVZPbktJZG9uckY1Wnc9PSIsInZhbHVlIjoiUGY5M2g2R1Q4NTBERldrMldJUUJNSmFuMTZhVXpcL1daY0UyYjZKXC9yYzQwVkZobVJRSVpvXC9NQklyZmpjOU5xOCIsIm1hYyI6IjdhOWYzMTk3OGU3NWY3ZDU4YzAxODhkZDg3MzliZWNlNGI3M2M0MWMwZDdiZjc0YjkxNmU3ZTRhMjIwOGQwNzQifQ%3D%3D; laravel_session=IhOtmOFF1w41TPoBsxXBnByC24wVgMCEcja9djv9; io=Bj_aV_IqJ3dZm7-uEmVw; _gid=GA1.2.1770087368.1639495630; _gat=1',
            ),
            'body'    => $body
        ));
    }

    // Helper Function for Formatting Products on Return For JSON
    function aqua_get_return_items($warranty_id) {
        global $wpdb;
        // Get Items from Database for Warranty Products
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT *
            FROM {$wpdb->prefix}wc_warranty_products
            WHERE request_id = %d",
            $warranty_id
        ), ARRAY_A );
        // Initiate Empty Array to Hold Product Data
        $products = array();
        // Loop over Items from Database
        foreach($items as $key => $item) {
            // Setup Variables for Product Array
            $product_id = $item['product_id'];
            $product    = wc_get_product( $product_id );
            $name       = $product->get_name();
            $sku        = $product->get_sku();
            $syteline   = $product->get_meta('_syteline_item_number');
            $quantity   = $item['quantity'];
            // Add Product To Product Array
            $products[$key] = array(
                'name' 			  => $name,
                'sku'             => $sku,
                "syteline_number" => $syteline,
                "quantity" 		  => $quantity
            );

        }
        // Return Products After Loop
        return $products;
    }

    // Hook Onto Warranty Status Update and Setup Return Data if Status is Send Return to Syteline
    // Pass Formatted Data Into Function to Send API Call to Jitterbit
    function aqua_get_return_info($warranty_id, $new_status, $prev_status) {
        if($new_status == 'send-return-to-syteline') {
            // Get Order Info
            $order_id   = get_post_meta( $warranty_id, '_order_id', true );
            $order      = wc_get_order( $order_id );
            $order_data = $order->get_data();
            // Setup Variables to Use In Response Body
            $rma_number   = get_post_meta( $warranty_id, '_code', true );
            $reason       = get_post_meta( $warranty_id, '_field_1199171998', true );
            $order_number = $order_data['number'];
            // Setup Return Array For Response Body
            $return = array(
                'rma_id'       => $warranty_id,
                'rma_date'     => current_time( 'mysql' ),
                'rma_number'   => $rma_number,
                'reason'       => $reason,
                'order_number' => $order_number,
                'products'     => $this->aqua_get_return_items($warranty_id)
            );
            // JSON Encode Return Data for API Call
            $body = json_encode( $return );
    
            // error_log(print_r($return, true));
    
            // Pass Body to API Call Function
            $this->aqua_send_return_to_jitterbit( $body );
        }
        
    }

    // Create Rest Route for Jitterbit to Call extends WooCommerce's REST by adding wc prefix to url
    function returns_rest_route() {
        register_rest_route('wc-aqua-returns/v1', '/update-return', array(
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => array($this, 'create_return_update'),
            'permission_callback' => array($this, 'create_return_update_permission')
        ));
    }

    // Check Consumer & Secret Key sent in API call
    function create_return_update_permission( WP_REST_Request $request ){
        if ( is_user_logged_in() ) {
            return true;
        }
        return false;
    }
    
    //Leverage warranty_update_request function (in WooCommerce Warranty Requests plugin) to update status and tracking number
    function create_return_update( WP_REST_Request $request ) {
        $request_id = $request['id'];
        $data = $request['data'];
        // error_log(print_r($data, true));
        
        warranty_update_request( $request_id, $data );
        $this->custom_email( $request_id, $data );
    }

    // Send email with tracking when API call is sent
    function custom_email( $request_id, $data ) {
        global $wpdb, $woocommerce;
    
        $emails      = get_option( 'warranty_emails', array() );
        $request     = get_post($request_id);

        $mailer      = $woocommerce->mailer();
        $order       = wc_get_order( get_post_meta( $request_id, '_order_id', true ) );
        $admin_email = get_option('admin_email');

        $subject     = "Your Return Has Shipped";
        $message     = "Your return has shipped. Here is your tracking number " . $data['return_tracking_code'];

        if ( $order ) {
            $customer_email = WC_Warranty_Compatibility::get_order_prop( $order, 'billing_email' );
        } else {
            $customer_email = sanitize_email( get_post_meta( $request_id, '_email', true ) );
        }
         
        $message = $mailer->wrap_message( $subject, $message );
        $mailer->send( $customer_email, $subject, $message );
    }

    // Add Post Meta Inputs On Coupons
    function add_coupon_text_field() {
        woocommerce_wp_text_input( array(
            'id'                => 'original_order_id',
            'label'             => __( 'Original Order Id (If Coupon From Return)', 'woocommerce' ),
            'placeholder'       => '',
            'description'       => __( 'Original Order Id (If Coupon From Return)', 'woocommerce' ),
            'desc_tip'    => true,
        ) );
        woocommerce_wp_text_input( array(
            'id'                => 'rma_number',
            'label'             => __( 'RMA Number (If Coupon From Return', 'woocommerce' ),
            'placeholder'       => '',
            'description'       => __( 'RMA Number (If Coupon From Return', 'woocommerce' ),
            'desc_tip'    => true,
        ) );
    }
    // Get Warranty, Order, and Coupon IDs from coupon creation in woocommerce warranty plugin
    function add_custom_data_to_coupon_data($coupon_id, $order_id, $warranty_id) {

        $order = wc_get_order( $order_id );
        $order_data = $order->get_data();
        $rma_number   = get_post_meta( $warranty_id, '_code', true );

        update_post_meta( $coupon_id, 'original_order_id', $order_data['number'] );
        update_post_meta( $coupon_id, 'rma_number', $rma_number );
    }

}

$aquaCustomizations = new AquaCustomizations();