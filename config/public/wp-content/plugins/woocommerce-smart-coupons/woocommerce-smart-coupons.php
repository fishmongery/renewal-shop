<?php
/*
Plugin Name: WooCommerce Smart Coupons
Plugin URI: http://woothemes.com/woocommerce
Description: <strong>WooCommerce Smart Coupons</strong> lets customers buy gift certificates, store credits or coupons easily. They can use purchased credits themselves or gift to someone else.
Version: 1.4.2
Author: Store Apps
Author URI: http://www.storeapps.org/
Copyright (c) 2012 Store Apps All rights reserved.
*/

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) )
    require_once( 'woo-includes/woo-functions.php' );

/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), '05c45f2aa466106a466de4402fff9dde', '18729' );

//
register_activation_hook ( __FILE__, 'smart_coupon_activate' );

// Function to have by default auto generation for smart coupon on activation of plugin.
function smart_coupon_activate() {
    global $wpdb, $blog_id;

    if (is_multisite()) {
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}", 0);
    } else {
        $blog_ids = array($blog_id);
    }

    if ( !get_option( 'smart_coupon_email_subject' ) ) {
        add_option( 'smart_coupon_email_subject' );
    }

    foreach ($blog_ids as $blog_id) {

        if (( file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php') ) && ( is_plugin_active('woocommerce/woocommerce.php') )) {

            $wpdb_obj = clone $wpdb;
            $wpdb->blogid = $blog_id;
            $wpdb->set_prefix($wpdb->base_prefix);

            $query = "SELECT postmeta.post_id FROM {$wpdb->prefix}postmeta as postmeta WHERE postmeta.meta_key = 'discount_type' AND postmeta.meta_value LIKE 'smart_coupon' AND postmeta.post_id IN
                    (SELECT p.post_id FROM {$wpdb->prefix}postmeta AS p WHERE p.meta_key = 'customer_email' AND p.meta_value LIKE 'a:0:{}') ";

            $results = $wpdb->get_col($query);

            foreach ($results as $result) {
                update_post_meta($result, 'auto_generate_coupon', 'yes');
            }
            // To disable apply_before_tax option for Gift Certificates / Store Credit.
            $post_id_tax_query = "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key LIKE 'discount_type' AND meta_value LIKE 'smart_coupon'";

            $tax_post_ids = $wpdb->get_col($post_id_tax_query);

            foreach ( $tax_post_ids as $tax_post_id ) {
                update_post_meta($tax_post_id, 'apply_before_tax', 'no');
            }

            $wpdb = clone $wpdb_obj;
        }
    }
}

if ( is_woocommerce_active() ) {

    
        // For PHP version lower than 5.3.0
        if (!function_exists('str_getcsv')) {
            function str_getcsv($input, $delimiter = ",", $enclosure = '"', $escape = "\\") {
                $fiveMBs = 5 * 1024 * 1024;
                $fp = fopen("php://temp/maxmemory:$fiveMBs", 'r+');
                fputs($fp, $input);
                rewind($fp);

                $data = fgetcsv($fp, 0, $delimiter, $enclosure); //  $escape only got added in 5.3.0

                fclose($fp);
                return $data;
            }
        }

        if ( ! class_exists( 'WC_Smart_Coupons' ) ) {

        class WC_Smart_Coupons {

            var $credit_settings;
            
            public function __construct() {
                                
                // Action to display coupons field on product edit page
                add_action( 'woocommerce_product_options_general_product_data', array(&$this, 'woocommerce_product_options_coupons') );
                add_action( 'woocommerce_process_product_meta_simple', array(&$this, 'woocommerce_process_product_meta_coupons') );
                add_action( 'woocommerce_process_product_meta_variable', array(&$this, 'woocommerce_process_product_meta_coupons') );
                add_action( 'wp_ajax_woocommerce_json_search_coupons', array(&$this, 'woocommerce_json_search_coupons') );

                // Actions on order status change
                add_action( 'woocommerce_order_status_completed', array(&$this, 'sa_add_coupons'), 19 );
                add_action( 'woocommerce_order_status_completed', array(&$this, 'coupons_used'), 19 );
                add_action( 'woocommerce_order_status_processing', array(&$this, 'sa_add_coupons'), 19 );
                                add_action( 'woocommerce_order_status_processing', array(&$this, 'coupons_used'), 19 );
                add_action( 'woocommerce_order_status_refunded', array(&$this, 'sa_remove_coupons'), 19 );
                add_action( 'woocommerce_order_status_cancelled', array(&$this, 'sa_remove_coupons'), 19 );
                                add_action( 'woocommerce_order_status_on-hold', array(&$this, 'update_smart_coupon_balance'), 19 );
                add_action( 'update_smart_coupon_balance', array(&$this, 'update_smart_coupon_balance') );

                // Default settings for Store Credit / Gift Certificate
                add_option('woocommerce_delete_smart_coupon_after_usage', 'yes');
                add_option('woocommerce_smart_coupon_apply_before_tax', 'no');
                add_option('woocommerce_smart_coupon_individual_use', 'no');
                add_option('woocommerce_smart_coupon_show_my_account', 'yes');

                // Gift Certificate Settings to be displayed under WooCommerce->Settings
                $this->credit_settings = array(
                    array(
                        'name'              => __( 'Store Credit / Gift Certificate', 'wc_smart_coupons' ),
                        'type'              => 'title',
                        'desc'              => __('The following options are specific to Gift / Credit.', 'wc_smart_coupons'),
                        'id'                => 'smart_coupon_options'
                    ),
                    array(
                        'name'              => __('Default Gift / Credit options', 'wc_smart_coupons'),
                        'desc'              => __('Show Credit on My Account page.', 'wc_smart_coupons'),
                        'id'                => 'woocommerce_smart_coupon_show_my_account',
                        'type'              => 'checkbox',
                        'default'           => 'yes',
                        'checkboxgroup'     => 'start'
                    ),
                    array(
                        'desc'              => __('Delete Gift / Credit, when credit is used up.', 'wc_smart_coupons'),
                        'id'                => 'woocommerce_delete_smart_coupon_after_usage',
                        'type'              => 'checkbox',
                        'default'           => 'yes',
                        'checkboxgroup'     => ''
                    ),
                    array(
                        'desc'              => __('Individual use', 'wc_smart_coupons'),
                        'id'                => 'woocommerce_smart_coupon_individual_use',
                        'type'              => 'checkbox',
                        'default'           => 'no',
                        'checkboxgroup'     => ''
                    ),
                    array(                     
                        'name'              => __( "E-mail subject", 'wc_smart_coupons' ),
                        'desc'              => __( "This text will be used as subject for e-mails to be sent to customers. In case of empty value following message will be displayed <br/><b>Congratulations! You've received a coupon</b>", 'wc_smart_coupons' ),
                        'id'                => 'smart_coupon_email_subject',
                        'type'              => 'textarea',
                        'desc_tip'          =>  true,
                        'css'               => 'min-width:300px;'
                     ),
                     array(                     
                        'name'              => __( "Product Page Text", 'wc_smart_coupons' ),
                        'desc'              => __( "Text to display associated coupon details on the Product shop page. In case of empty value following message will be displayed <br/><b>By purchasing this product, you will get the following coupon(s):</b> ", 'wc_smart_coupons' ),
                        'id'                => 'smart_coupon_product_page_text',
                        'type'              => 'text',
                        'desc_tip'          =>  true,
                        'css'               => 'min-width:300px;'
                     ),  
                     array(
                        'name'              => __( "Cart/Checkout Page Text", 'wc_smart_coupons' ),
                        'desc'              => __( "Text to display as title of 'Available Coupons List' on Cart and Checkout page. In case of empty value following message will be displayed <br/><b>Available Coupons (Click on the coupon to use it)</b> ", 'wc_smart_coupons' ),
                        'id'                => 'smart_coupon_cart_page_text',
                        'type'              => 'text',
                        'desc_tip'          =>  true,
                        'css'               => 'min-width:300px;'
                     ),
                     array(                    
                        'name'              => __( "My Account Page Text", 'wc_smart_coupons' ),
                        'desc'              => __( "Text to display as title of available coupons on My Account page. In case of empty value following message will be displayed <br/><b>Store Credit Available</b>", 'wc_smart_coupons' ),
                        'id'                => 'smart_coupon_myaccount_page_text',
                        'type'              => 'text',
                        'desc_tip'          =>  true,
                        'css'               => 'min-width:300px;'
                    ),
                    array(                    
                        'name'              => __( "Purchase Credit Text", 'wc_smart_coupons' ),
                        'desc'              => __( "Text for purchasing 'Store Credit of any amount' product. In case of empty value following message will be displayed <br/><b>Purchase Credit worth</b>", 'wc_smart_coupons' ),
                        'id'                => 'smart_coupon_store_gift_page_text',
                        'type'              => 'text',
                        'desc_tip'          =>  true,
                        'css'               => 'min-width:300px;'
                    ),
                    array(
                        'name'              => __( "Receiver's details Form Title", 'wc_smart_coupons' ),
                        'desc'              => __( "Text to display as title of Receiver's details form. In case of empty value following message will be displayed <br/><b>Store Credit / Gift Certificate receiver&#146;s details</b>", 'wc_smart_coupons' ),
                        'id'                => 'smart_coupon_gift_certificate_form_page_text',
                        'type'              => 'text',
                        'desc_tip'          =>  true,
                        'css'               => 'min-width:300px;'
                    ),
                    array(
                        'name'              => __( "Additional information about form", 'wc_smart_coupons' ),
                        'desc'              => __( "Text to display as additional information below 'Receiver's detail Form Title'. In case of empty value following message will be displayed <br/><b>(To send this coupon as a gift to someone, please enter their details, otherwise leave this blank and the coupon will be sent to you.)</b>", 'wc_smart_coupons' ),
                        'id'                => 'smart_coupon_gift_certificate_form_details_text',
                        'type'              => 'text',
                        'css'               => 'min-width:300px;',
                        'desc_tip'          =>  true
                        
                    ),
                    array(
                        'type'              => 'sectionend',
                        'id'                => 'smart_coupon_options'
                    )
                );

                // Filters for handling coupon types & checking its validity
                add_filter( 'woocommerce_coupon_discount_types', array(&$this, 'add_smart_coupon_discount_type') );
                add_filter( 'woocommerce_coupon_is_valid', array(&$this, 'is_smart_coupon_valid'), 10, 2 );

                // Actions for handling processing of Gift Certificate
                add_action( 'woocommerce_new_order', array(&$this, 'smart_coupons_contribution') );
                add_action( 'woocommerce_calculate_totals', array(&$this, 'apply_smart_coupon_to_cart') );
                add_action( 'woocommerce_before_my_account', array(&$this, 'show_smart_coupon_balance') );
                add_action( 'woocommerce_email_after_order_table', array(&$this, 'show_store_credit_balance'), 10, 5 );

                // Actions for Gift Certificate settings
                add_action( 'woocommerce_settings_digital_download_options_after', array(&$this, 'smart_coupon_admin_settings'));
                add_action( 'woocommerce_update_options_general', array(&$this, 'save_smart_coupon_admin_settings'));

                // Actions to show gift certificate & receiver's details form
                add_action( 'woocommerce_after_add_to_cart_button', array( &$this, 'show_attached_gift_certificates' ) );
                add_action( 'woocommerce_checkout_before_customer_details', array( &$this, 'gift_certificate_receiver_detail_form' ) );
                add_action( 'woocommerce_before_checkout_process', array( &$this, 'verify_gift_certificate_receiver_details' ) );
                add_action( 'woocommerce_new_order', array( &$this, 'add_gift_certificate_receiver_details_in_order' ) );

                // Action to show available coupons
                add_action( 'woocommerce_after_cart_table', array( &$this, 'show_available_coupons_after_cart_table' ) );
                add_action( 'woocommerce_before_checkout_form', array( &$this, 'show_available_coupons_before_checkout_form' ), 11 );

                                // Action to show duplicate icon for coupons
                                add_filter( 'post_row_actions', array( &$this,'woocommerce_duplicate_coupon_link_row'), 1, 2 );

                                // Action to create duplicate coupon
                                add_action( 'admin_action_duplicate_coupon', array( &$this,'woocommerce_duplicate_coupon_action') );

                                // Action to search coupon based on email ids in customer email postmeta key
                                add_action( 'parse_request', array( &$this,'woocommerce_admin_coupon_search' ) );
                                add_filter( 'get_search_query', array( &$this,'woocommerce_admin_coupon_search_label' ) );

                                // Action for importing coupon csv file
                                add_action( 'admin_menu', array(&$this, 'woocommerce_coupon_admin_menu') );
                                add_action( 'admin_init', array(&$this, 'woocommerce_coupon_admin_init') );
                                // To show import message on coupon page
                                add_action( 'admin_notices', array(&$this, 'woocommerce_show_import_message') );

                                // Action for settings on coupon page
                                add_action( 'woocommerce_coupon_options', array(&$this, 'woocommerce_smart_coupon_options') );
                                add_action( 'save_post', array(&$this, 'woocommerce_process_smart_coupon_meta'), 10, 2 );

                                add_action( 'woocommerce_single_product_summary', array(&$this, 'call_for_credit_form') );
                                add_filter( 'woocommerce_is_purchasable', array(&$this, 'make_product_purchasable'), 10, 2 );
                                add_action( 'woocommerce_before_calculate_totals', array(&$this, 'override_price_before_calculate_totals') );
                                
                                add_action( 'woocommerce_after_shop_loop_item', array(&$this, 'remove_add_to_cart_button_from_shop_page') );

                                if ( !function_exists( 'is_plugin_active' ) ) {
                                    if ( ! defined('ABSPATH') ) {
                                        include_once ('../../../wp-load.php');
            }
                                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                                }
                                if( is_plugin_active( 'woocommerce-gateway-paypal-express/woocommerce-gateway-paypal-express.php' ) ) {
                                    add_action( 'woocommerce_ppe_checkout_order_review', array( &$this, 'gift_certificate_receiver_detail_form' ) );
                                    add_action( 'woocommerce_thankyou', array( &$this, 'add_gift_certificate_receiver_details_in_order' ) );
                                }
                                //
                                add_action( 'restrict_manage_posts', array(&$this, 'woocommerce_restrict_manage_smart_coupons'), 20 );
                                add_action( 'admin_init', array(&$this,'woocommerce_export_coupons') );
                                
                                // Action for updating coupon's email id with the updation of customer profile
                                add_action( 'personal_options_update', array( &$this, 'my_profile_update' ) );
                                add_action( 'edit_user_profile_update', array( &$this, 'my_profile_update' ) );

                                add_action( 'woocommerce_checkout_order_processed', array( &$this, 'save_called_credit_details_in_order' ), 10, 2 );
                                add_action( 'woocommerce_add_order_item_meta', array( &$this, 'save_called_credit_details_in_order_item_meta' ), 10, 2 );
                                add_filter( 'woocommerce_add_cart_item_data', array( &$this, 'call_for_credit_cart_item_data' ), 10, 3 );
                                add_filter( 'woocommerce_add_to_cart_validation', array( &$this, 'sc_woocommerce_add_to_cart_validation' ), 10, 6 );
                                add_action( 'woocommerce_add_to_cart', array( &$this, 'save_called_credit_in_session' ), 10, 6 );
                                
                // Generate Smart Coupon's hook
                add_filter( 'generate_smart_coupon_action', array( &$this, 'generate_smart_coupon_action' ), 1, 9 );
                add_action( 'wp_ajax_smart_coupons_json_search', array(&$this, 'smart_coupons_json_search') );
                add_action( 'wp_ajax_sc_get_coupon_object', array(&$this, 'sc_get_coupon_object') );

                add_action( 'init', array( &$this, 'smart_coupon_shortcode_button_init' ) );
                add_action( 'init', array( &$this, 'register_smart_coupon_shortcode' ) );
                add_action( 'init', array( &$this, 'register_plugin_styles' ) );
                add_action( 'after_wp_tiny_mce', array( &$this, 'smart_coupons_after_wp_tiny_mce' ) );
                                add_action( 'init',    array( &$this, 'init' ) );
                                ob_start();
            }

                        //
            function sc_woocommerce_add_to_cart_validation( $validation, $product_id, $quantity, $variation_id = '', $variations = '', $cart_item_data = array() ) {
                            global $woocommerce;
                            
                            $cart_item_data['credit_amount'] = $_POST['credit_called'][$product_id];

                            $cart_id = $woocommerce->cart->generate_cart_id( $product_id, $variation_id, $variations, $cart_item_data );

                            if ( function_exists( 'get_product' ) ) {
                                if ( isset( $woocommerce->session->credit_called[$cart_id] ) && empty( $woocommerce->session->credit_called[$cart_id] ) ) {
                                    return false;
                                }
                            } else {
                                if ( isset( $_SESSION['credit_called'][$cart_id] ) && empty( $_SESSION['credit_called'][$cart_id] ) ) {
                                    return false;
                                }
                            }
                            
                            return $validation;
                        }

                        //
                        function init() {
                     
                            load_plugin_textdomain( 'wc_smart_coupons', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
                    
                        }

                        //
                        function save_called_credit_details_in_order( $order_id, $posted ) {
                            global $woocommerce;

                            $order = new WC_Order( $order_id );
                            $order_items = $order->get_items();
                            
                            $sc_called_credit = array();
                            $update = false;
                            foreach ( $order_items as $item_id => $order_item ) {
                                if ( isset( $order_item['sc_called_credit'] ) && !empty( $order_item['sc_called_credit'] ) ) {
                                    $sc_called_credit[$item_id] = $order_item['sc_called_credit'];
                                    woocommerce_delete_order_item_meta( $item_id, 'sc_called_credit' );
                                    $update = true;
                                }
                            }
                            if ( $update ) {
                                update_post_meta( $order_id, 'sc_called_credit_details', $sc_called_credit );
                            }

                            if( function_exists( 'get_product' ) ) {
                                if ( isset( $woocommerce->session->credit_called ) ) unset( $woocommerce->session->credit_called );
                            } else {                         
                                if ( isset( $_SESSION['credit_called'] ) ) unset( $_SESSION['credit_called'] );
                            }

                        }

                        function save_called_credit_details_in_order_item_meta( $item_id, $values ) {
                            global $woocommerce;

                $coupon_titles = get_post_meta( $values['product_id'], '_coupon_title', true );

                if ( $this->is_coupon_amount_pick_from_product_price( $coupon_titles ) && isset( $values['data']->price ) && $values['data']->price > 0 ) {
                                woocommerce_add_order_item_meta( $item_id, 'sc_called_credit', $values['data']->price );
                            }
                        }

                        function save_called_credit_in_session( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
                            if ( !empty( $variation_id ) && $variation_id > 0 ) return;
                            if ( !isset( $cart_item_data['credit_amount'] ) || empty( $cart_item_data['credit_amount'] ) ) return;

                            global $woocommerce;

                            if( function_exists( 'get_product' ) ){
                                $_product = get_product( $product_id ) ;
                            } else {
                                $_product = new WC_Product( $product_id ) ;
                            }
                            
                            $coupons = get_post_meta( $product_id, '_coupon_title', true );

                            if ( !empty( $coupons ) && $this->is_coupon_amount_pick_from_product_price( $coupons ) && !( $_product->get_price() > 0 ) ) {
                                if ( function_exists( 'get_product' ) ) {
                                    if ( !isset( $woocommerce->session->credit_called ) ) {
                                        $woocommerce->session->credit_called = array();
                                    }
                                    $woocommerce->session->credit_called += array( $cart_item_key => $cart_item_data['credit_amount'] );
                                } else {
                                    if ( !isset( $_SESSION['credit_called'] ) ) {
                                        $_SESSION['credit_called'] = array();
                                    }
                                    $_SESSION['credit_called'] += array( $cart_item_key => $cart_item_data['credit_amount'] );
                                }
                            }
                      
                        }

                        function call_for_credit_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
                            if ( !empty( $variation_id ) && $variation_id > 0 ) return $cart_item_data;

                            if( function_exists( 'get_product' ) ){
                                $_product = get_product( $product_id ) ;
                            } else {
                                $_product = new WC_Product( $product_id ) ;
                            }
                            
                            $coupons = get_post_meta( $product_id, '_coupon_title', true );

                            if ( !empty( $coupons ) && $this->is_coupon_amount_pick_from_product_price( $coupons ) && !( $_product->get_price() > 0 ) ) {
                                $cart_item_data['credit_amount'] = $_REQUEST['credit_called'][$_REQUEST['add-to-cart']];
                                return $cart_item_data;
                            }

                            return $cart_item_data;
                        }

            //
            function register_plugin_styles() {
                wp_register_style( 'smart-coupon', plugins_url( 'woocommerce-smart-coupons/assets/css/smart-coupon.css' ) );
                wp_enqueue_style( 'smart-coupon' );
            }

            //
            function smart_coupons_after_wp_tiny_mce( $mce_settings ) {
                $plugins = explode( ',', $mce_settings['content']['plugins'] );
                if ( in_array('-sc_shortcode_button', $plugins, true) ) {
                    $this->sc_attributes_dialog();
                }
            }

            //
            function register_smart_coupon_shortcode() {
                add_shortcode( 'smart_coupons', array( &$this, 'execute_smart_coupons_shortcode' ) );
            }

            //
            function execute_smart_coupons_shortcode( $atts ) {
                ob_start();
                global $current_user, $wpdb;
                
                extract( shortcode_atts( array(
                    'coupon_code'           => '',
                    'discount_type'         => 'smart_coupon',
                    'coupon_amount'         => '',
                    'usage_limit'           => '',
                    'expiry_days'           => '',
                    'minimum_total'         => '',
                    'free_shipping'         => 'no',
                    'individual_use'        => 'no',
                    'apply_before_tax'      => 'no',
                    'exclude_sale_items'    => 'no',
                    'auto_generate'         => 'no',
                    'coupon_prefix'         => '',
                    'coupon_suffix'         => '',
                    'customer_email'        => '',
                    'disable_email'         => 'no'
                ), $atts ) );
            
                if ( empty( $customer_email ) ) {
                    
                    if ( !($current_user instanceof WP_User) ) {
                        $current_user   = wp_get_current_user();
                        $customer_email = ( isset($current_user->user_email) ) ? $current_user->user_email : '';
                    } else {
                        $customer_email = $current_user->data->user_email;
                    }

                }
               
                $coupon_exists = $wpdb->get_var("SELECT ID
                                                    FROM {$wpdb->prefix}posts AS posts 
                                                        LEFT JOIN {$wpdb->prefix}postmeta AS postmeta 
                                                        ON ( postmeta.post_id = posts.ID )
                                                    WHERE posts.post_title LIKE '$coupon_code'
                                                        AND posts.post_type LIKE 'shop_coupon'
                                                        AND posts.post_status LIKE 'publish'
                                                        AND postmeta.meta_key LIKE 'customer_email'
                                                        AND postmeta.meta_value LIKE '%$customer_email%'");
                $expiry_date = "";

                if ( $coupon_exists == null ) {

                    if ( !empty( $coupon_code ) ) {
                        $coupon = new WC_Coupon( $coupon_code );
                      
                        if ( !empty( $coupon->discount_type ) ) {
                            $is_auto_generate = get_post_meta( $coupon->id, 'auto_generate_coupon', true );
                     
                            if ( !empty( $is_auto_generate ) && $is_auto_generate == 'yes' ) {

                                $generated_coupon_details = apply_filters( 'generate_smart_coupon_action', $customer_email, $coupon->amount, '', $coupon );
                                $new_generated_coupon_code = $generated_coupon_details[$customer_email][0]['code'];
                              
                            } else {
                                $is_disable_email_restriction = get_post_meta( $coupon->id, 'sc_disable_email_restriction', true );
                                if ( empty( $is_disable_email_restriction ) || $is_disable_email_restriction == 'no' ) {
                                    $existing_customer_emails = get_post_meta( $coupon->id, 'customer_email', true );
                                    $existing_customer_emails[] = $customer_email;
                                    update_post_meta( $coupon->id, 'customer_email', $existing_customer_emails );
                                }
                                $new_generated_coupon_code = $coupon_code;

                            }
                        }
                    }

                    if ( ( !empty( $coupon_code ) && empty( $coupon->discount_type ) ) || ( empty( $coupon_code ) ) ) {

                        if ( empty( $coupon_code ) ) {
                            $coupon_code = $this->generate_unique_code( $customer_email );
                            $coupon_code = $coupon_prefix . $coupon_code . $coupon_suffix;
                        }

                        $coupon_args = array(
                            'post_title'    => $coupon_code,
                            'post_content'  => '',
                            'post_status'   => 'publish',
                            'post_author'   => 1,
                            'post_type'     => 'shop_coupon'
                        );

                        $new_coupon_id = wp_insert_post( $coupon_args );                        
                        if ( !empty( $expiry_days ) ) {
                            $expiry_date = date( 'Y-m-d', strtotime( "+$expiry_days days" ) );
                        }
                        
                        // Add meta for coupons
                        update_post_meta( $new_coupon_id, 'discount_type', $discount_type );
                        update_post_meta( $new_coupon_id, 'coupon_amount', $coupon_amount );
                        update_post_meta( $new_coupon_id, 'individual_use', $individual_use );
                        update_post_meta( $new_coupon_id, 'minimum_amount', $minimum_total );
                        update_post_meta( $new_coupon_id, 'product_ids', array() );
                        update_post_meta( $new_coupon_id, 'exclude_product_ids', array() );
                        update_post_meta( $new_coupon_id, 'usage_limit', $usage_limit );
                        update_post_meta( $new_coupon_id, 'expiry_date', $expiry_date );
                        update_post_meta( $new_coupon_id, 'customer_email', array( $customer_email ) );
                        update_post_meta( $new_coupon_id, 'apply_before_tax', $apply_before_tax  );
                        update_post_meta( $new_coupon_id, 'free_shipping', $free_shipping );
                        update_post_meta( $new_coupon_id, 'product_categories', array()  );
                        update_post_meta( $new_coupon_id, 'exclude_product_categories', array() );
                        update_post_meta( $new_coupon_id, 'sc_disable_email_restriction', $disable_email );

                        $new_generated_coupon_code = $coupon_code;

                    }

                } else {

                    $expiry_date = get_post_meta( $coupon_exists, 'expiry_date', true );
                    $new_generated_coupon_code = $coupon_code;

                }

                switch( $discount_type ) {
                    case 'smart_coupon':
                        $coupon_type = __( 'Store Credit', 'wc_smart_coupons' );
                        $coupon_amount = woocommerce_price( $coupon->amount );
                        break;

                    case 'fixed_cart':
                        $coupon_type = __( 'Cart Discount', 'wc_smart_coupons' );
                        $coupon_amount = woocommerce_price( $coupon->amount );
                        break;

                    case 'fixed_product':
                        $coupon_type = __( 'Product Discount', 'wc_smart_coupons' );
                        $coupon_amount = woocommerce_price( $coupon->amount );
                        break;

                    case 'percent_product':
                        $coupon_type = __( 'Product Discount', 'wc_smart_coupons' );
                        $coupon_amount = $coupon->amount . '%';
                        break;

                    case 'percent':
                        $coupon_type = __( 'Cart Discount', 'wc_smart_coupons' );
                        $coupon_amount = $coupon->amount . '%';
                        break;

                }
                //$coupon_data = get_coupon_meta_data( $coupon );
                $discount_text = $coupon_amount . ' '. $coupon_type;
                $discount_text = wp_strip_all_tags( $discount_text );

                echo '<div class="coupon-container '.$atts["coupon_style"].'" style="cursor:inherit">
                            <div class="coupon-content '.$atts["coupon_style"].'">
                                <div class="discount-info">'. $discount_text.'</div>
                                <div class="code">'. $new_generated_coupon_code .'</div>';

                if( $expiry_date != "" ) {
                    echo ' <div class="coupon-expire">' . __( 'Expires on ', 'wc_smart_coupons' ) . $expiry_date .'</div>';
                } else {
                    echo ' <div class="coupon-expire">' . __( 'Never Expires ', 'wc_smart_coupons' ) . '</div>';
                }
                
                echo '</div>
                    </div>';
                
                return ob_get_clean();
            }

            function get_coupon_meta_data( $coupon )
            {
                switch( $coupon->discount_type ) {
                    case 'smart_coupon':
                        $coupon_data['coupon_type'] = __( 'Store Credit', 'wc_smart_coupons' );
                        $coupon_data['coupon_amount'] = woocommerce_price( $coupon->amount );
                        break;

                    case 'fixed_cart':
                        $coupon_data['coupon_type'] = __( 'Cart Discount', 'wc_smart_coupons' );
                        $coupon_data['coupon_amount'] = woocommerce_price( $coupon->amount );
                        break;

                    case 'fixed_product':
                        $coupon_data['coupon_type'] = __( 'Product Discount', 'wc_smart_coupons' );
                        $coupon_data['coupon_amount'] = woocommerce_price( $coupon->amount );
                        break;

                    case 'percent_product':
                        $coupon_data['coupon_type'] = __( 'Product Discount', 'wc_smart_coupons' );
                        $coupon_data['coupon_amount'] = $coupon->amount . '%';
                        break;

                    case 'percent':
                        $coupon_data['coupon_type'] = __( 'Cart Discount', 'wc_smart_coupons' );
                        $coupon_data['coupon_amount'] = $coupon->amount . '%';
                        break;

                }
                return $coupon_data;
            }

            //
            function smart_coupon_shortcode_button_init() {

                if ( ! current_user_can('edit_posts') && ! current_user_can('edit_pages') && get_user_option('rich_editing') == 'true') {
                    return;
                }

                add_filter( 'mce_external_plugins', array( &$this, 'smart_coupon_register_tinymce_plugin' ) );
                add_filter( 'mce_buttons', array( &$this, 'smart_coupon_add_tinymce_button' ) );

            }

            //
            function smart_coupon_register_tinymce_plugin( $plugin_array ) {
                $plugin_array['sc_shortcode_button'] = plugins_url( 'assets/js/sc_shortcode.js', __FILE__ );
                return $plugin_array;
            }

            //
            function smart_coupon_add_tinymce_button( $buttons ) {
                $buttons[] = 'sc_shortcode_button';
                return $buttons;
            }

            //
            function smart_coupons_json_search( $x = '', $post_types = array( 'shop_coupon' ) ) {
                global $woocommerce, $wpdb;

                check_ajax_referer( 'search-coupons', 'security' );

                $term = (string) urldecode(stripslashes(strip_tags($_GET['term'])));

                if (empty($term)) die();

                $auto_generated_post_ids = $wpdb->get_col("SELECT post_id 
                                                            FROM {$wpdb->prefix}postmeta 
                                                            WHERE meta_key LIKE 'auto_generate_coupon' 
                                                                AND meta_value LIKE 'yes'
                                                        ");

                $blank_email_post_ids = $wpdb->get_col("SELECT post_id 
                                                        FROM {$wpdb->prefix}postmeta 
                                                        WHERE meta_key LIKE 'customer_email' 
                                                            AND meta_value NOT LIKE '%@%.%' 
                                                            AND post_id IN ( " . implode( ',', $auto_generated_post_ids ) . " )
                                                        ");
                
                foreach ( $blank_email_post_ids as $key => $post_id ) {

                    $expiry_date = get_post_meta( $post_id, 'expiry_date', true );
                    if ( !empty( $expiry_date ) && current_time( 'timestamp' ) > strtotime( $expiry_date ) ) {
                        unset( $blank_email_post_ids[$key] );
                    }
                    
                }
           
                $posts = $wpdb->get_results("SELECT * 
                                            FROM {$wpdb->prefix}posts 
                                            WHERE post_type LIKE 'shop_coupon' 
                                                AND post_title LIKE '$term%' 
                                                AND post_status = 'publish' 
                                                AND ID IN ( " . implode( ',', $blank_email_post_ids ) . " )");

                $found_products = array();

                $all_discount_types = $woocommerce->get_coupon_discount_types();

                if ($posts) foreach ($posts as $post) {

                    $discount_type = get_post_meta($post->ID, 'discount_type', true);
                    if ( !empty( $all_discount_types[$discount_type] ) ) {

                        $coupon = new WC_Coupon( get_the_title( $post->ID ) );
                        switch ( $coupon->discount_type ) {

                            case 'smart_coupon':
                                $coupon_type = 'Store Credit';
                                $coupon_amount = woocommerce_price( $coupon->amount );
                                break;

                            case 'fixed_cart':
                                $coupon_type = 'Cart Discount';
                                $coupon_amount = woocommerce_price( $coupon->amount );
                                break;

                            case 'fixed_product':
                                $coupon_type = 'Product Discount';
                                $coupon_amount = woocommerce_price( $coupon->amount );
                                break;

                            case 'percent_product':
                                $coupon_type = 'Product Discount';
                                $coupon_amount = $coupon->amount . '%';
                                break;

                            case 'percent':
                                $coupon_type = 'Cart Discount';
                                $coupon_amount = $coupon->amount . '%';
                                break;

                        }

                        $discount_type = ' ( ' . $coupon_amount . ' '. $coupon_type . ' )';
                        $discount_type = wp_strip_all_tags( $discount_type );
                        $found_products[get_the_title( $post->ID )] = get_the_title( $post->ID ) .' '. $discount_type;
                    }

                }

                echo json_encode( $found_products );

                die();
            }

            //
            function sc_get_coupon_object() {
                global $woocommerce, $wpdb;

                check_ajax_referer( 'get-coupon', 'security' );

                $coupon_code = (string) urldecode(stripslashes(strip_tags($_GET['coupon_code'])));

                if (empty($coupon_code)) die();

                $coupon = new WC_Coupon( $coupon_code );

                echo json_encode( $coupon );
                exit();
            }

            //
            public static function sc_attributes_dialog() {

                global $woocommerce;
                wp_enqueue_style( 'coupon-style' );

                ?>
                <div style="display:none;">
                    <form id="sc_coupons_attributes" tabindex="-1">
                    <?php wp_nonce_field( 'internal_coupon_shortcode', '_ajax_coupon_shortcode_nonce', false ); ?>

                    <script type="text/javascript">
                        jQuery('input#search-coupon-field').keyup(function(){
                            var searchString = jQuery(this).val().trim();
                            var inputCharacterCount = searchString.length;
                            if ( inputCharacterCount == 0 ) {
                                jQuery('div#search-results ul').empty();
                                jQuery('div#search-panel').hide();
                                emptyAllFormElement();
                                showAllFormElement();
                            }
                            if ( inputCharacterCount < 2 ) return true;
                            
                            jQuery.ajax({
                                url: '<?php echo admin_url( "admin-ajax.php" ); ?>',
                                method: 'GET',
                                afterTypeDelay: 100,
                                data: {
                                    action        : 'smart_coupons_json_search',
                                    security      : '<?php echo wp_create_nonce("search-coupons"); ?>',
                                    term          : searchString
                                },
                                dataType: 'json',
                                success: function( response ) {
                                    if ( response ) {
                                        jQuery('div#search-panel').show();
                                        jQuery('div#search-results ul').empty();
                                    }
                                    var coupon = [];
                                    jQuery.each(response, function (i, val) {
                                       
                                        jQuery('div#search-results ul').append('<li><label><span>'+i+'</span>'+val.substr(val.indexOf('(')-1)+'</label></li>');
                                    });
                                }
                            });

                        });

                        var enableDisableFormElement = [];
                        enableDisableFormElement.push('coupon-code-field');

                        var hideShowFormElement = [];
                        hideShowFormElement.push('coupon-customer-email-field');
                        
                        function emptyAllFormElement() {
                            jQuery('div#coupon-option input[type=text]').val('');
                            jQuery('div#coupon-option input[type=number]').val('');
                            jQuery('div#coupon-option input[type=radio]').removeAttr('checked');
                        }

                        function showAllFormElement() {
                            for( var i = 0; i < hideShowFormElement.length; i++ ) {
                                jQuery('#'+hideShowFormElement[i]).parent().parent().show();
                            }
                            for( var j = 0; j < enableDisableFormElement.length; j++ ) {
                                jQuery('#'+enableDisableFormElement[j]).removeAttr('readonly');
                            }
                            jQuery('#discount-type-field').removeAttr('disabled');
                        }

                        function hideAllFormElement() {
                            for( var i = 0; i < hideShowFormElement.length; i++ ) {
                                jQuery('#'+hideShowFormElement[i]).parent().parent().hide();
                            }
                            for( var j = 0; j < enableDisableFormElement.length; j++ ) {
                                jQuery('#'+enableDisableFormElement[j]).attr('readonly','readonly');
                            }
                            jQuery('#discount-type-field').attr('disabled', 'disabled');
                        }

                        jQuery('div#search-results ul li label').live('click', function(){
                            var couponCode = jQuery(this).children('span').text();
                            var couponDesc = jQuery(this).first().contents().eq(1).text();
                            jQuery.ajax({
                                url: '<?php echo admin_url( "admin-ajax.php" ); ?>',
                                method: 'GET',
                                data: {
                                    action        : 'sc_get_coupon_object',
                                    security      : '<?php echo wp_create_nonce("get-coupon"); ?>',
                                    coupon_code   : couponCode
                                },
                                dataType: 'json',
                                success: function( response ) {
                                    jQuery('input#coupon-code-field').val(response.code);
                                    jQuery('div#coupon-code-field').text(response.code);
                                    jQuery('div#coupon-description').text(couponDesc.substring(3, couponDesc.length-2));

                                    var emails = response.customer_email;
                                    jQuery('input#coupon-customer-email-field').val(response.customer_email.join(','));
                                  
                                    if ( response != undefined ) {
                                        hideAllFormElement();
                                    } else {
                                        showAllFormElement();
                                    }
                                    
                                }
                            });

                        });

                        jQuery('input#sc_shortcode_submit').click(function() {

                            var couponShortcode = '[smart_coupons ';
                            var couponCode      = jQuery('#coupon-code-field').val();
                            var customerEmail   = jQuery('#coupon-customer-email-field').val();
                            var coupon_border   = jQuery('select#coupon-border').find('option:selected').val();
                            var coupon_color    = jQuery('select#coupon-color').find('option:selected').val();
                            var coupon_size     = jQuery('select#coupon-size').find('option:selected').val();
                            var coupon_style    = coupon_border+' '+coupon_color+' '+coupon_size;

                            if ( couponCode != undefined && couponCode != '' ) {
                                couponShortcode += 'coupon_code="'+couponCode.trim()+'" ';
                            }
                            if ( customerEmail != undefined && customerEmail != '' ) {
                                couponShortcode += 'customer_email="'+customerEmail.trim()+'" ';
                            }
                            if ( coupon_style != undefined && coupon_style != '' ) {
                                couponShortcode += 'coupon_style="'+coupon_style+'" ';    
                            }
                            
                            couponShortcode += ']';
                            tinyMCE.execCommand("mceInsertContent", false, couponShortcode);
                            jQuery('a.ui-dialog-titlebar-close').trigger('click');

                        });

                        jQuery('div#sc_shortcode_cancel a').click(function(){
                            emptyAllFormElement();
                            jQuery('a.ui-dialog-titlebar-close').trigger('click');
                        });
                            

                        //Shortcode Styles
                        jQuery('select').live('change', function() {
                            var coupon_border   = jQuery('select#coupon-border').find('option:selected').val();
                            var coupon_color    = jQuery('select#coupon-color').find('option:selected').val();
                            var coupon_size     = jQuery('select#coupon-size').find('option:selected').val();
                            
                            jQuery('div.coupon-container').removeClass().addClass('coupon-container preview');
                            jQuery('div.coupon-container').addClass(coupon_color+' '+coupon_size);

                            jQuery('div.coupon-content').removeClass().addClass('coupon-content');
                            jQuery('div.coupon-content').addClass(coupon_border+' '+coupon_size+' '+coupon_color);


                        });

                    </script>
                  
                    <div id="coupon-selector">
                        <div id="coupon-option">
                            <div>
                                <label><span><?php _e( 'Coupon code', 'wc_smart_coupons' ); ?></span><input id="coupon-code-field" type="text" name="coupon_code" /></label>
                            </div>
                         
                            <div>
                                <label><span><?php _e( 'Customer email', 'wc_smart_coupons' ); ?></span><input id="coupon-customer-email-field" type="text" name="coupon_customer_email" /></label>
                            </div>
                            <div>
                                <div>
                                    <label><span><?php _e( 'Coupon color', 'wc_smart_coupons' ); ?></span>
                                        <select id="coupon-color" name="coupon-color">
                                            <option value="green" selected="selected"><?php _e( 'Light Green', 'wc_smart_coupons' ) ?></option>
                                            <option value="blue"><?php _e( 'Light Blue', 'wc_smart_coupons' ) ?></option>
                                            <option value="red"><?php _e( 'Light Red', 'wc_smart_coupons' ) ?></option>
                                            <option value="yellow"><?php _e( 'Light Yellow', 'wc_smart_coupons' ) ?></option>
                                        </select>
                                    </label>
                                </div>
                               <div>
                                    <label><span><?php _e( 'Coupon border', 'wc_smart_coupons' ); ?></span>
                                        <select id="coupon-border" name="coupon-border">
                                            <option value="dashed" selected="selected">- - - - - -</option>
                                            <option value="dotted">-----------</option>
                                            <option value="solid">––––––</option>
                                            <option value="groove">––––––</option>
                                            <option value="none">       </option>
                                        </select>
                                    </label>
                                </div>
                                <div>
                                    <label><span><?php _e( 'Coupon size', 'wc_smart_coupons' ); ?></span>
                                        <select id="coupon-size" name="coupon-size">
                                            <option value="small"><?php _e( 'Small', 'wc_smart_coupons' ) ?></option>
                                            <option value="medium" selected="selected"><?php _e( 'Medium', 'wc_smart_coupons' ) ?></option>
                                            <option value="large"><?php _e( 'Large', 'wc_smart_coupons' ) ?></option>
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <div class="coupon-container">
                                <div class="coupon-content">
                                    <div class="discount-info"><?php _e( 'XX Product Discount', 'wc_smart_coupons' ) ?></div>
                                    <div class="code"><?php _e( 'coupon code', 'wc_smart_coupons' ) ?></div>
                                    <div class="coupon-expire"><?php _e( 'Expire on xx date', 'wc_smart_coupons' ) ?></div>

                                </div>
                            </div>

                            
                            <hr />
                            <h5><?php _e( 'Or link to existing Coupon', 'wc_smart_coupons' ); ?></h5>
                            <div>
                                <label><span><?php _e( 'Search coupon', 'wc_smart_coupons' ); ?></span><input id="search-coupon-field" type="text" name="search_coupon_code" /></label>
                            </div>
                            <div id="search-panel" style="display:none">
                                <div id="search-results" class="query-results">
                                    <ul></ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="submitbox">
                        <div id="sc_shortcode_update">
                            <input type="button" value="<?php esc_attr_e( 'Add coupon shortcode', 'wc_smart_coupons' ); ?>" class="button-primary" id="sc_shortcode_submit" name="sc_shortcode_submit">
                        </div>
                        <div id="sc_shortcode_cancel">
                            <a class="submitdelete deletion" href="#"><?php _e( 'Cancel', 'wc_smart_coupons' ); ?></a>
                        </div>
                    </div>
                    </form>
                </div>
                <?php
            }


                        //Update coupon's email id with the updation of customer profile
                        function my_profile_update( $user_id ) {

                            global $wpdb;

                            if ( current_user_can( 'edit_user', $user_id ) ) {

                                $current_user = get_userdata( $user_id );

                                $old_customers_email_id = $current_user->data->user_email;
                            
                                if( isset( $_POST['email'] ) && $_POST['email'] != $old_customers_email_id ) {

                                    $query = "SELECT post_id
                                                FROM $wpdb->postmeta
                                                WHERE meta_key = 'customer_email'
                                                AND meta_value LIKE  '%$old_customers_email_id%'
                                                AND post_id IN ( SELECT ID
                                                                    FROM $wpdb->posts 
                                                                    WHERE post_type =  'shop_coupon')";
                                    $result = $wpdb->get_col( $query ); 

                                    if( ! empty( $result ) ) {

                                        foreach ( $result as $post_id ) {

                                            $coupon_meta = get_post_meta( $post_id, 'customer_email', true );
                                            
                                            foreach ( $coupon_meta as $key => $email_id ) {
                                                
                                                if( $email_id == $old_customers_email_id ) {

                                                    $coupon_meta[$key] = $_POST['email'];
                                                }
                                            }

                                            update_post_meta( $post_id, 'customer_email', $coupon_meta );

                                        } //end foreach
                                    }                                      
                                }
                            }
                        }

                        //
                        function remove_add_to_cart_button_from_shop_page() {
                            global $product, $woocommerce;
                            
                            $coupons = get_post_meta( $product->id, '_coupon_title', true );

                            if ( !empty( $coupons ) && $this->is_coupon_amount_pick_from_product_price( $coupons ) && !( $product->get_price() > 0 ) ) {

                                $woocommerce->add_inline_js("

                                    jQuery(function(){
                                        jQuery('a[data-product_id=". $product->id ."]').remove();
                                    });

                                    ");                           
                            }
                        }
                        
                        //
                        function override_price_before_calculate_totals( $cart_object ) {
                            global $woocommerce;
                            
                            foreach ( $cart_object->cart_contents as $key => $value ) {

                                $coupons = get_post_meta( $value['data']->id, '_coupon_title', true );

                                if ( !empty( $coupons ) && $this->is_coupon_amount_pick_from_product_price( $coupons ) && !( $value['data']->price > 0 ) ) {
                                  
                                    // NEWLY ADDED CODE TO MAKE COMPATIBLE.
                                    if( function_exists( 'get_product' ) ) {
                                        $price = ( isset( $woocommerce->session->credit_called[$key] ) ) ? $woocommerce->session->credit_called[$key]: '';
                                    } else {                         
                                        $price = ( isset( $_SESSION['credit_called'][$key] ) ) ? $_SESSION['credit_called'][$key]: '';
                                    }

                                    if ( $price <= 0 ) {
                                        $woocommerce->cart->set_quantity( $key, 0 );    // Remove product from cart if price is not found either in session or in product
                                        continue;
                                    }

                                    $cart_object->cart_contents[$key]['data']->price = $price;
                            
                                }

                            }

                        }

                        //
                        function make_product_purchasable( $purchasable, $product ) {

                            $coupons = get_post_meta( $product->id, '_coupon_title', true );

                            if ( !empty( $coupons ) && $product instanceof WC_Product && $product->get_price() === '' && $this->is_coupon_amount_pick_from_product_price( $coupons ) && !( $product->get_price() > 0 ) ) {
                                return true;
                            }

                            return $purchasable;
                        }

                        //
                        function is_coupon_amount_pick_from_product_price( $coupons ) {
                            global $woocommerce;

                            foreach ( $coupons as $coupon_code ) {
                                $coupon = new WC_Coupon( $coupon_code );
                                if ( $coupon->discount_type == 'smart_coupon' && get_post_meta( $coupon->id, 'is_pick_price_of_product', true ) == 'yes' ) {
                                    return true;
                                }
                            }
                            return false;
                        }

                        //
                        function call_for_credit_form() {
                            global $product, $woocommerce;

                            if ( $product instanceof WC_Product_Variation ) return;

                            $coupons = get_post_meta( $product->id, '_coupon_title', true );

                            if ( !function_exists( 'is_plugin_active' ) ) {
                                if ( ! defined('ABSPATH') ) {
                                    include_once ('../../../wp-load.php');
                                }
                                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                            }

                            // MADE CHANGES IN THE CONDITION TO SHOW INPUT FIELDFOR PRICE ONLY FOR COUPON AS A PRODUCT
                            if ( !empty( $coupons ) && $this->is_coupon_amount_pick_from_product_price( $coupons ) && ( !( $product->get_price() != '' || ( is_plugin_active( 'woocommerce-name-your-price/woocommerce-name-your-price.php' ) && ( get_post_meta( $product->id, '_nyp', true ) == 'yes' ) ) ) ) ) {

                                $woocommerce->add_inline_js("

                                    jQuery(function(){
                                        var validateCreditCalled = function(){
                                            var enteredCreditAmount = jQuery('input#credit_called').val();
                                            if ( enteredCreditAmount < 0.01 ) {
                                                jQuery('p#error_message').text('Invalid amount');
                                                jQuery('input#credit_called').css('border-color', 'red');
                                                return false;
                                            } else {
                                    jQuery('form.cart').unbind('submit');
                                                jQuery('p#error_message').text('');
                                                jQuery('input#credit_called').css('border-color', '');
                                                return true;
                                            }
                                        };

                            jQuery('input#credit_called').bind('change keyup', function(){
                                            validateCreditCalled();
                                            jQuery('input#hidden_credit').remove();
                                            jQuery('div.quantity').append('<input type=\"hidden\" id=\"hidden_credit\" name=\"credit_called[". $product->id ."]\" value=\"'+jQuery('input#credit_called').val()+'\" />');
                                        });

                           
                            jQuery('button.single_add_to_cart_button').live('click', function(e) {
                                            if ( validateCreditCalled() == false ) {
                                                e.preventDefault();
                                            }
                                        });
                                        
                                    });

                                    ");
                    $smart_coupon_store_gift_page_text = get_option('smart_coupon_store_gift_page_text');
                    $smart_coupon_store_gift_page_text = ( !empty( $smart_coupon_store_gift_page_text ) ) ? $smart_coupon_store_gift_page_text.' ' : 'Purchase Credit worth';
                    
                                ?>                      
                                <br /><br />
                                <div id="call_for_credit">
                                    <?php
                                        $currency_symbol = get_woocommerce_currency_symbol();
                                    ?>
                                    <p style="float: left">
                                    <?php 
                                        echo __( stripslashes( $smart_coupon_store_gift_page_text ) , 'wc_smart_coupons') . '(' . $currency_symbol . ')'; 
                                        echo '</p><br /><br />';
                                        echo "<input id='credit_called' type='number' min='1' name='credit_called' value='' autocomplete='off' />";
                                    ?>
                                    <p id="error_message" style="color: red;"></p>
                                </div><br />
                                <?php

                            }
                        }

                        // Function to notifiy user about remaining balance in Store Credit in "Order Complete" email
                        function show_store_credit_balance( $order, $send_to_admin ) {
                                global $woocommerce;

                                if ( $send_to_admin ) return;
                                
                if ( ! class_exists( 'WC_Coupon' ) ) {
                                        require_once( WP_PLUGIN_DIR . '/woocommerce/classes/class-wc-coupon.php' );
                }

                                if ( sizeof( $order->get_used_coupons() ) > 0 ) {
                                        $store_credit_balance = '';
                                        foreach ( $order->get_used_coupons() as $code ) {
                                                if ( ! $code ) continue;
                                                $coupon = new WC_Coupon( $code );

                                                if ( $coupon->type == 'smart_coupon' && $coupon->amount > 0 ) {
                                                        $store_credit_balance .= '<li><strong>'. $coupon->code .'</strong> &mdash; '. woocommerce_price( $coupon->amount ) .'</li>';
                                                }
                                        }

                                        if ( !empty( $store_credit_balance ) ) {
                                                echo "<br /><h3>" . __( 'Store Credit / Gift Certificate Balance', 'wc_smart_coupons' ) . ": </h3>";
                                                echo "<ul>" . $store_credit_balance . "</ul><br />";
                                        }
                                }
                        }

            // Function to show available coupons after cart table
            function show_available_coupons_after_cart_table() {

                if ( $this->show_available_coupons() ) {

                    global $woocommerce;

                    $woocommerce->add_inline_js("

                            // Apply Coupon through Ajax
                        jQuery('div.apply_coupons_credits').click( function() {
            
                                var coupon_code = jQuery(this).attr('name');

                                jQuery.ajax({
                                    url:    '". esc_url( $woocommerce->cart->get_cart_url() ) ."',
                                    type:   'post',
                                    data:   {
                                        'coupon_code': coupon_code,
                                        'apply_coupon': jQuery('input[name=apply_coupon]').val(),
                                        '_n': jQuery('#_n').val(),
                                        '_wp_http_referer': jQuery('input[name=_wp_http_referer]').val()
                                    },
                                    dataType: 'html',
                                    success: function( response ) {
                                        jQuery('body').html( response );
                                                                            
                                        if( jQuery('div#coupons_list').find('div.coupon-container').length == 0 ) {
                                            jQuery('div#coupons_list h2').css('display' , 'none');
                                                                            }
                                    }
                                });
                                return false;

                            });
                        if( jQuery('div#coupons_list').find('div.coupon-container').length == 0 ) {
                            jQuery('div#coupons_list h2').css('display' , 'none');
                            }
                            ");

                }

            }

            // Function to show available coupons before checkout form
            function show_available_coupons_before_checkout_form() {

                global $woocommerce;

                if ( $this->show_available_coupons() ) {
                    $woocommerce->add_inline_js("

                        jQuery('#coupons_list').hide();

                        jQuery('a.showcoupon').click( function() {
                            jQuery('#coupons_list').slideToggle();
                            return false;
                        });

                        // Apply Coupon through Ajax
                        jQuery('div.apply_coupons_credits').click( function() {
                            var coupon_code = jQuery(this).attr('name');
                            jQuery.ajax({
                                 type:      'POST',
                                 url:       woocommerce_params.ajax_url,
                                 dataType:  'html',
                                 data: {
                                    action:             'woocommerce_apply_coupon',
                                    security:           woocommerce_params.apply_coupon_nonce,
                                    coupon_code:        coupon_code
                                 },
                                 success: function( code ) {
                                    jQuery('.woocommerce_error, .woocommerce_message').remove();
                                    jQuery('form.checkout_coupon').removeClass('processing').unblock();

                                    if ( code ) {
                                        jQuery('form.checkout_coupon').before( code );
                                        jQuery('form.checkout_coupon').slideUp();
                                        jQuery('#coupons_list').slideToggle();
                                        jQuery('div[name=\''+coupon_code+'\']').remove(); // if coupon contain any space


                                        jQuery('body').trigger('update_checkout');

                                        if( jQuery('div#coupons_list').find('div.coupon-container').length == 0 ) {
                                            jQuery('div#coupons_list h2').css('display' , 'none');
                                        }
                                    }
                                 }
                            });
                            return false;

                        });

                        if( jQuery('div#coupons_list').find('div.coupon-container').length == 0 ) {
                            jQuery('div#coupons_list h2').css('display' , 'none');
                        }

                        ");

                }

            }

            // Function to show available coupons
            function show_available_coupons() {
                                global $woocommerce;
								$smart_coupon_cart_page_text = get_option('smart_coupon_cart_page_text');
                                $smart_coupon_cart_page_text = ( !empty($smart_coupon_cart_page_text ) ) ? $smart_coupon_cart_page_text : 'Available Coupons (Click on the coupon to use it)';
                                
                if ( !is_user_logged_in() ) return false;

                $coupons = $this->get_customer_credit();

                if ( empty( $coupons ) ) return false;

                    ?>
                    <div id='coupons_list'><h2><?php _e( stripslashes( $smart_coupon_cart_page_text ), 'wc_smart_coupons' ) ?></h2>
                        <?php
                                                
                                                // NEWLY ADDED CODE TO MAKE COMPATIBLE.
                                                if( function_exists( 'get_product' ) ){
                                                    $coupons_applied = $woocommerce->cart->get_applied_coupons();
                                                } else {
                                                    $coupons_applied = $_SESSION['coupons'];
                                                }
                                                
                        foreach ( $coupons as $code ) {

                            if ( in_array( $code->post_title, $coupons_applied ) ) continue;

                            $coupon = new WC_Coupon( $code->post_title );
           
                            if ( empty( $coupon->discount_type ) ) continue;

                            $coupon_data = $this->get_coupon_meta_data( $coupon );

                               echo '<div class="coupon-container apply_coupons_credits blue medium" name="'.$coupon->code.'" style="cursor: pointer">
                                <div class="coupon-content blue dashed small" name="'.$coupon->code.'">
                                    <div class="discount-info">'.$coupon_data['coupon_amount']." ". $coupon_data['coupon_type'].'</div>
                                    <div class="code">'. $coupon->code .'</div>';

                                if( !empty( $coupon->coupon_custom_fields['expiry_date'][0] ) ) {

                                    echo '<div class="coupon-expire">'. __( 'Expire on ', 'wc_smart_coupons' ) . $coupon->coupon_custom_fields['expiry_date'][0] .'</div>';    

                                } else {

                                    echo '<div class="coupon-expire">'. __( 'Never Expires', 'wc_smart_coupons' ) . '</div>';    

                                }    
                                    
                                echo '</div>
                                </div>';

                        }
                        ?>
                    </div>
                    <?php

                    return true;

            }

            // Function to add gift certificate receiver's details in order itself
            function add_gift_certificate_receiver_details_in_order( $order_id ) {
                            
                if ( !isset( $_REQUEST['gift_receiver_email'] ) || count( $_REQUEST['gift_receiver_email'] ) <= 0 ) return;

                                if ( isset( $_REQUEST['gift_receiver_email']) || ( isset( $_REQUEST['billing_email'] ) && $_REQUEST['billing_email'] != $_REQUEST['gift_receiver_email'] ) ) {

                                        update_post_meta( $order_id, 'gift_receiver_email', $_REQUEST['gift_receiver_email'] );

                    if ( isset( $_REQUEST['gift_receiver_name'] ) && $_REQUEST['gift_receiver_name'] != '' ) {
                        update_post_meta( $order_id, 'gift_receiver_name', $_REQUEST['gift_receiver_name'] );
                    }

                    if ( isset( $_REQUEST['gift_receiver_message'] ) && $_REQUEST['gift_receiver_message'] != '' ) {
                        update_post_meta( $order_id, 'gift_receiver_message', $_REQUEST['gift_receiver_message'] );
                    }

                }
            }

            // Function to verify gift certificate form details
            function verify_gift_certificate_receiver_details() {
                global $woocommerce;

                                if ( !isset( $_POST['gift_receiver_email'] ) || count( $_POST['gift_receiver_email'] ) <= 0 ) return;

                                foreach ( $_POST['gift_receiver_email'] as $key => $emails ) {
                                    foreach ( $emails as $index => $email ) {
                                        if ( empty( $email ) ) {
                                            $_POST['gift_receiver_email'][$key][$index] = $_POST['billing_email'];
                                        } elseif ( !empty( $email ) && !is_email( $email ) ) {
                                            $woocommerce->add_error( __( 'Error: Gift Certificate Receiver&#146;s E-mail address is invalid.', 'wc_smart_coupons' ) );
                                            return;
                                        }
                                    }
                                }

            }

                        //
                        function add_text_field_for_email( $coupon = '', $product = '' ) {
                            global $woocommerce;

                            if ( empty( $coupon ) ) return;

                            for ( $i = 0; $i < $product['quantity']; $i++ ) {

                                $coupon_amount = ( $this->is_coupon_amount_pick_from_product_price( $coupon ) ) ? $product['data']->price: $coupon->amount;

                                // NEWLY ADDED CONDITION TO NOT TO SHOW TEXTFIELD IF COUPON AMOUNT IS "0"
                                if($coupon_amount != '' || $coupon_amount > 0) {
                                    ?>

                                    <tr>
                                        <td><input class="gift_receiver_email" type="text" name="gift_receiver_email[<?php echo $coupon->id; ?>][]" value="" /></td>
                                        <td><p class="coupon_amount_label"><?php echo $coupon_amount; ?></p></td>
                                    </tr>

                                    <?php
                                }

                            }

                        }
                        
            // Function to display form for entering details of the gift certificate's receiver
            function gift_certificate_receiver_detail_form() {
                global $woocommerce;

                                $form_started = false;

                foreach ( $woocommerce->cart->cart_contents as $product ) {

                    $coupon_titles = get_post_meta( $product['product_id'], '_coupon_title', true );

                                        // NEWLY ADDED CONDITION TO MAKE COMPATIBLE
                                        if( function_exists( 'get_product' ) ){
                                            $_product = get_product( $product['product_id'] ) ;
                                        } else {
                                            $_product = new WC_Product( $product['product_id'] ) ;
                                        }
                                        
                                        $price = $_product->get_price();
                                        
                    if ( $coupon_titles ) {

                                            foreach ( $coupon_titles as $coupon_title ) {

                                                    $coupon = new WC_Coupon( $coupon_title );

                                                    $pick_price_of_prod = get_post_meta( $coupon->id, 'is_pick_price_of_product', true ) ;
													$smart_coupon_gift_certificate_form_page_text  = get_option('smart_coupon_gift_certificate_form_page_text');
                                                    $smart_coupon_gift_certificate_form_page_text  = ( !empty( $smart_coupon_gift_certificate_form_page_text ) ) ? $smart_coupon_gift_certificate_form_page_text : 'Store Credit / Gift Certificate receiver&#146;s details';
                                                    $smart_coupon_gift_certificate_form_details_text  = get_option('smart_coupon_gift_certificate_form_details_text');
                                                    $smart_coupon_gift_certificate_form_details_text  = ( !empty( $smart_coupon_gift_certificate_form_details_text ) ) ? $smart_coupon_gift_certificate_form_details_text : '(To send this coupon as a gift to someone, please enter their details, otherwise leave this blank and the coupon will be sent to you.)';
                                                    
                                                    // MADE CHANGES IN THE CONDITION TO SHOW FORM
                                                    if ( $coupon->type == 'smart_coupon' || ( $pick_price_of_prod == 'yes' &&  $price == '' ) || ( $pick_price_of_prod == 'yes' &&  $price != '' && $coupon->amount > 0)  ) {

                                                            if ( !$form_started ) {

                                                                    ?>

                                                                    <div class="gift-certificate">
                                                                        <div class="gift-certificate-receiver-detail-form">
                                                                            <h3><?php _e( stripslashes( $smart_coupon_gift_certificate_form_page_text ) , 'wc_smart_coupons' ); ?></h3>
                                                                            <p><?php _e( stripslashes( $smart_coupon_gift_certificate_form_details_text ) , 'wc_smart_coupons' ); ?></p>
                                                                            <table id="gift-certificate-receiver-form">
                                                                                <thead >
                                                                                    <th><?php _e('E-mail IDs', 'wc_smart_coupons'); ?></th>
                                                                                    <th><?php _e('Coupon amount', 'wc_smart_coupons'); ?></th>
                                                                                </thead>

                                                                    <?php

                                                                    $form_started = true;

                                                                }

                                                                $this->add_text_field_for_email( $coupon, $product );

                                                    }

                                            }

                    }

                }

                                if ( $form_started ) {
                                    ?>
                                    <tr>
                                        <td colspan="2"><textarea placeholder="<?php _e('Message', 'wc_smart_coupons'); ?>..." id="gift_receiver_message" name="gift_receiver_message" cols="50" rows="5"></textarea></td>
                                    </tr>
                                    </table>
                                    </div></div>
                                    <?php
                                }

            }

            // Function to show gift certificates that are attached with the product
            function show_attached_gift_certificates() {
                global $post, $woocommerce, $wp_rewrite;

                $coupon_titles = get_post_meta( $post->ID, '_coupon_title', true );

                                //NEWLY ADDED TO EVENSHOW COUPON THAT HAS "is_pick_price_of_product" : TRUE
                                if( function_exists( 'get_product' ) ){
                                    $_product = get_product( $post->ID ) ;
                                } else {
                                    $_product = new WC_Product( $post->ID ) ;
                                }
                                
                                $price = $_product->get_price();

                if ( $coupon_titles && count( $coupon_titles ) > 0 && !empty( $price ) ) {

                    $all_discount_types = $woocommerce->get_coupon_discount_types();
					$smart_coupons_product_page_text = get_option('smart_coupon_product_page_text');
                    $smart_coupons_product_page_text = ( !empty( $smart_coupons_product_page_text ) ) ? $smart_coupons_product_page_text : 'By purchasing this product, you will get the following coupon(s):';

                    $list_started = true;

                    foreach ( $coupon_titles as $coupon_title ) {

                        $coupon = new WC_Coupon( $coupon_title );

                        if ( $list_started && !empty( $coupon->discount_type ) ) {
                            echo '<div class="clear"></div>';
                            echo '<div class="gift-certificates">';
                            echo '<br /><p>' . __( stripslashes( $smart_coupons_product_page_text ), 'wc_smart_coupons' ) . '';
                            echo '<ul>';
                            $list_started = false;
                        }

                        switch ( $coupon->discount_type ) {

                            case 'smart_coupon':
                                                            
                                                                //NEWLY ADDED TO EVENSHOW COUPON THAT HAS "is_pick_price_of_product" : TRUE
                                                                if( get_post_meta( $coupon->id, 'is_pick_price_of_product', true ) == 'yes' ){
                                                                    $amount = ($_product->price > 0) ? __( 'Store Credit of ', 'wc_smart_coupons' ) . $_product->price : "" ;
                                                                } else {
                                                                    $amount = __( 'Store Credit of ', 'wc_smart_coupons' ) . woocommerce_price( $coupon->amount );
                                                                }
                                
                                break;

                            case 'fixed_cart':
                                $amount = woocommerce_price( $coupon->amount ).__( ' discount on your entire purchase.', 'wc_smart_coupons' );
                                break;

                            case 'fixed_product':
                                $amount = woocommerce_price( $coupon->amount ).__( ' discount on this product.', 'wc_smart_coupons' );
                                break;

                            case 'percent_product':
                                $amount = $coupon->amount.'%'.__( ' discount on this product.', 'wc_smart_coupons' );
                                break;

                            case 'percent':
                                $amount = $coupon->amount.'%'.__( ' discount on your entire purchase.', 'wc_smart_coupons' );
                                break;
                        }
                        if(!empty($amount)) echo '<li>' . $amount . '</li>';
                    }
                    if ( !$list_started ) {
                    echo '</ul></p></div>';
                    }
                }
            }

            // Function for saving settings for Gift Certificate
            function save_smart_coupon_admin_settings() {
                woocommerce_update_options( $this->credit_settings );
            }

            // Function to display fields for configuring settings for Gift Certificate
            function smart_coupon_admin_settings() {
                woocommerce_admin_fields( $this->credit_settings );
            }

            // Function to display current balance associated with Gift Certificate
            function show_smart_coupon_balance() {
                $coupons = $this->get_customer_credit();
				$smart_coupon_myaccount_page_text  = get_option( 'smart_coupon_myaccount_page_text' );
                $smart_coupons_myaccount_page_text = ( !empty( $smart_coupon_myaccount_page_text ) ) ? $smart_coupon_myaccount_page_text: 'Available Store Credit / Coupons';

                if ( $coupons ) {
                    ?>
					<h2><?php _e( stripslashes ( $smart_coupons_myaccount_page_text ), 'wc_smart_coupons' ); ?></h2>
                        <?php
                        foreach ( $coupons as $code ) {

                            $coupon = new WC_Coupon( $code->post_title );

                            if ( empty( $coupon->discount_type ) ) continue;

                            $coupon_data = $this->get_coupon_meta_data( $coupon );

                               echo '<div class="coupon-container apply_coupons_credits blue medium" style="cursor: initial">
                                <div class="coupon-content blue dashed small">
                                    <div class="discount-info">'.$coupon_data['coupon_amount']." ". $coupon_data['coupon_type'].'</div>
                                    <div class="code">'. $coupon->code .'</div>';
                                if( !empty( $coupon->coupon_custom_fields['expiry_date'][0] ) ) {

                                    // $this->get_expiration_format();

                                    echo '<div class="coupon-expire">'. __( 'Expires on ', 'wc_smart_coupons' ) . $coupon->coupon_custom_fields['expiry_date'][0] .'</div>';    
                                } else {

                                    echo '<div class="coupon-expire">'. __( 'Never Expires ', 'wc_smart_coupons' ).'</div>';    
                                }    
                                    
                                echo '</div>
                                </div>';
                        }
                        ?>
                    <?php
                }

            }

            // Function to apply Gift Certificate's credit to cart
            function apply_smart_coupon_to_cart() {
                global $woocommerce;

                $woocommerce->cart->smart_coupon_credit_used = array();

                if ($woocommerce->cart->applied_coupons) {

                    foreach ($woocommerce->cart->applied_coupons as $code) {

                        $smart_coupon = new WC_Coupon( $code );

                        if ( $smart_coupon->is_valid() && $smart_coupon->type=='smart_coupon' ) {

                            $order_total = $woocommerce->cart->cart_contents_total + $woocommerce->cart->tax_total + $woocommerce->cart->shipping_tax_total + $woocommerce->cart->shipping_total;

                            if ( $woocommerce->cart->discount_total != 0 && ( $woocommerce->cart->discount_total + $smart_coupon->amount ) > $order_total ) {
                                $smart_coupon->amount = $order_total - $woocommerce->cart->discount_total;
                            } elseif( $smart_coupon->amount > $order_total ) {
                                $smart_coupon->amount = $order_total;
                            }

                            $woocommerce->cart->discount_total      = $woocommerce->cart->discount_total + $smart_coupon->amount;
                            $woocommerce->cart->smart_coupon_credit_used[$code]     = $smart_coupon->amount;
                        }
                    }
                }
            }

            // Function to save Smart Coupon's contribution in discount
            function smart_coupons_contribution( $order_id ) {
                global $woocommerce;

                if( $woocommerce->cart->applied_coupons ) {

                    foreach( $woocommerce->cart->applied_coupons as $code ) {

                        $smart_coupon = new WC_Coupon( $code );

                        if($smart_coupon->type == 'smart_coupon' ) {
                                                    
                                                        update_post_meta( $order_id, 'smart_coupons_contribution', $woocommerce->cart->smart_coupon_credit_used );

                        }

                    }

                }
            }

            // Function to update Store Credit / Gift Ceritficate balance
            function update_smart_coupon_balance( $order_id ) {

                                $order = new WC_Order( $order_id );
                                
                                $order_used_coupons = $order->get_used_coupons();
                            
                if( $order_used_coupons ) {

                                        $smart_coupons_contribution = get_post_meta( $order_id, 'smart_coupons_contribution', true );
                                        
                                        if ( ! isset( $smart_coupons_contribution ) || empty( $smart_coupons_contribution ) || ( is_array( $smart_coupons_contribution ) && count( $smart_coupons_contribution ) <= 0 ) ) return; 
                                    
                                        if ( !class_exists( 'WC_Coupon' ) ) {
                                                require_once( WP_PLUGIN_DIR . '/woocommerce/classes/class-wc-coupon.php' );
                                        }
                                        
                    foreach( $order_used_coupons as $code ) {

                                                if ( array_key_exists( $code, $smart_coupons_contribution ) ) {
                                                    
                                                    $smart_coupon = new WC_Coupon( $code );

                                                    if($smart_coupon->type == 'smart_coupon' ) {

                                                            $credit_remaining = max( 0, ( $smart_coupon->amount - $smart_coupons_contribution[$code] ) );

                                                            if ( $credit_remaining <= 0 && get_option( 'woocommerce_delete_smart_coupon_after_usage' ) == 'yes' ) {
                                                                    wp_delete_post( $smart_coupon->id );
                                                            } else {
                                                                    update_post_meta( $smart_coupon->id, 'coupon_amount', $credit_remaining );
                                                            }

                                                    }
                                                    
                                                }

                    }
                                        
                                        delete_post_meta( $order_id, 'smart_coupons_contribution' );

                }
            }

            // Function to return validity of Store Credit / Gift Certificate
            function is_smart_coupon_valid( $valid, $coupon ) {
                global $woocommerce;

                if ( $valid && $coupon->type == 'smart_coupon' && $coupon->amount <= 0 ) {
                    $woocommerce->add_error( __('There is no credit remaining on this coupon.', 'wc_smart_coupons') );
                    return false;
                }

                return $valid;
            }

            // Function to add new discount type 'smart_coupon'
            function add_smart_coupon_discount_type( $discount_types ) {
                $discount_types['smart_coupon'] = __('Store Credit / Gift Certificate', 'wc_smart_coupons');
                return $discount_types;
            }

            // Function to search coupons
            function woocommerce_json_search_coupons( $x = '', $post_types = array( 'shop_coupon' ) ) {
                global $woocommerce, $wpdb;

                check_ajax_referer( 'search-coupons', 'security' );

                $term = (string) urldecode(stripslashes(strip_tags($_GET['term'])));

                if (empty($term)) die();

                    $args = array(
                        'post_type'     => $post_types,
                        'post_status'       => 'publish',
                        'posts_per_page'    => -1,
                        's'             => $term,
                        'fields'            => 'all'
                    );

                                $posts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}posts WHERE post_type LIKE 'shop_coupon' AND post_title LIKE '$term%' AND post_status = 'publish'");

                $found_products = array();

                $all_discount_types = $woocommerce->get_coupon_discount_types();

                if ($posts) foreach ($posts as $post) {

                    $discount_type = get_post_meta($post->ID, 'discount_type', true);

                    if ( !empty( $all_discount_types[$discount_type] ) ) {
                                            $discount_type = ' (Type: ' . $all_discount_types[$discount_type] . ')';
                                            $found_products[get_the_title( $post->ID )] = get_the_title( $post->ID ) . $discount_type;
                                        }

                }

                echo json_encode( $found_products );

                die();
            }

            // Function to provide area for entering coupon code
            function woocommerce_product_options_coupons() {
                global $post, $woocommerce;

                ?>
                <p class="form-field" id="sc-field"><label for="_coupon_title"><?php _e('Coupons', 'wc_smart_coupons'); ?></label>

                <select id="_coupon_title" name="_coupon_title[]" class="ajax_chosen_select_coupons" multiple="multiple" data-placeholder="<?php _e('Search for a coupon...', 'wc_smart_coupons'); ?>">

                <?php
                        if ( ! class_exists( 'WC_Coupon' ) ) {
                            require_once( WP_PLUGIN_DIR . '/woocommerce/classes/class-wc-coupon.php' );
                        }

                        $all_discount_types = $woocommerce->get_coupon_discount_types();

                        $coupon_titles = get_post_meta( $post->ID, '_coupon_title', true );

                        if ($coupon_titles) {

                            foreach ($coupon_titles as $coupon_title) {

                                $coupon = new WC_Coupon( $coupon_title );

                                $discount_type = $coupon->discount_type;

                                if (isset($discount_type) && $discount_type) $discount_type = ' ( Type: ' . $all_discount_types[$discount_type] . ' )';

                                echo '<option value="'.$coupon_title.'" selected="selected">'. $coupon_title . $discount_type .'</option>';

                            }
                        }
                    ?>
                </select>

                    <script type="text/javascript">

                        // Ajax Chosen Coupon Selectors
                        jQuery("select.ajax_chosen_select_coupons").ajaxChosen({
                            method:     'GET',
                            url:        '<?php echo admin_url('admin-ajax.php'); ?>',
                            dataType:   'json',
                            afterTypeDelay: 100,
                            data:       {
                                action:         'woocommerce_json_search_coupons',
                                security:       '<?php echo wp_create_nonce("search-coupons"); ?>'
                            }
                        }, function (data) {

                            var terms = {};

                            jQuery.each(data, function (i, val) {
                                terms[i] = val;
                            });

                            return terms;
                        });

                        jQuery('select#product-type').live('change', function() {

                            var productType = jQuery(this).find('option:selected').val();

                            if ( productType == 'simple' || productType == 'variable' ) {
                                jQuery('p#sc-field').show();
                            } else {
                                jQuery('p#sc-field').hide();
                            }

                        });

                    </script>

                <img class="help_tip" data-tip='<?php _e('These coupon/s will be given to customers who buy this product. The coupon code will be automatically sent to their email address on purchase.', 'wc_smart_coupons'); ?>' src="<?php echo $woocommerce->plugin_url(); ?>/assets/images/tip.png" /></p>

                <?php

            }

            // Function to save coupon code to database
            function woocommerce_process_product_meta_coupons( $post_id ) {
                if (isset($_POST['_coupon_title'])) :
                    update_post_meta( $post_id, '_coupon_title', $_POST['_coupon_title'] );
                else :
                    update_post_meta( $post_id, '_coupon_title', array() );
                endif;
            }

            // Function to track whether coupon is used or not
            function coupons_used( $order_id ) {
                
                                // Update Smart Coupons balance when the order status is either 'processing' or 'completed'
                                do_action( 'update_smart_coupon_balance', $order_id );
                            
                                $order = new WC_Order( $order_id );

                $email = get_post_meta( $order_id, 'gift_receiver_email', true );
                                
                if ( $order->get_used_coupons() ) {
                    $this->update_coupons( $order->get_used_coupons(), $email, '', 'remove' );
                }
            }

            // Function to update details related to coupons
            function update_coupons( $coupon_titles = array(), $email, $product_ids = '', $operation, $order_item = null, $gift_certificate_receiver = false, $gift_certificate_receiver_name = '', $message_from_sender = '', $gift_certificate_sender_name = '', $gift_certificate_sender_email = '', $order_id = '' ) {

                            global $smart_coupon_codes;

                                $prices_include_tax = (get_option('woocommerce_prices_include_tax')=='yes') ? true : false;

                if ( !empty( $coupon_titles ) ) {

                    if ( ! class_exists( 'WC_Coupon' ) ) {
                        require_once( WP_PLUGIN_DIR . '/woocommerce/classes/class-wc-coupon.php' );
                    }

                    if ( isset( $order_item['qty'] ) && $order_item['qty'] > 1 ) {
                        $qty = $order_item['qty'];
                    } else {
                        $qty = 1;
                    }

                    foreach ( $coupon_titles as $coupon_title ) {

                        $coupon = new WC_Coupon( $coupon_title );

                                                $auto_generation_of_code = get_post_meta( $coupon->id, 'auto_generate_coupon', true);

                        if ( ( $auto_generation_of_code == 'yes' || $coupon->discount_type == 'smart_coupon' ) && $operation == 'add' ) {

                                                        if ( get_post_meta( $coupon->id, 'is_pick_price_of_product', true ) == 'yes' && $coupon->discount_type == 'smart_coupon' ) {
                                                            $products_price = ( !$prices_include_tax ) ? $order_item['line_total'] : $order_item['line_total'] + $order_item['line_tax'];
                                                            $amount = $products_price / $qty;
                                                        } else {
                                                            if ( $coupon->discount_type == 'fixed_cart' || $coupon->discount_type == 'fixed_product' ) {
                                                                $amount = $coupon->amount * $qty;
                                                            } else {
                                                                $amount = $coupon->amount;
                                                            }
                                                        }

                                                        $email_id = ( $auto_generation_of_code == 'yes' && $coupon->discount_type != 'smart_coupon' && !empty( $gift_certificate_sender_email ) ) ? $gift_certificate_sender_email : $email;

                                                        if( $amount > 0 ) {
                                                            $coupon_title =  $this->generate_smart_coupon( $email_id, $amount, $order_id, $coupon, $coupon->discount_type, $gift_certificate_receiver_name, $message_from_sender, $gift_certificate_sender_name, $gift_certificate_sender_email );
                                                        }

                        } else {

                            $coupon_receiver_email = ( $gift_certificate_sender_email != '' ) ? $gift_certificate_sender_email : $email;

                            $sc_disable_email_restriction = get_post_meta( $coupon->id, 'sc_disable_email_restriction', true );

                            if ( ( $sc_disable_email_restriction == 'no' || empty( $sc_disable_email_restriction ) ) ) {
                                $old_customers_email_ids = (array) maybe_unserialize( get_post_meta( $coupon->id, 'customer_email', true ) );
                            }

                            if ( $operation == 'add' && $auto_generation_of_code != 'yes' && $coupon->discount_type != 'smart_coupon') {

                                if ( $qty && $operation == 'add' && ! ( $coupon->discount_type == 'percent_product' || $coupon->discount_type == 'percent' ) ) {
                                    $amount = $coupon->amount * $qty;
                                } else {
                                    $amount = $coupon->amount;
                                }

                                                                if ( $qty > 0 && ( $sc_disable_email_restriction == 'no' || empty( $sc_disable_email_restriction ) ) ) {
                                                                    for ( $i = 0; $i < $qty; $i++ ) 
                                                                        $old_customers_email_ids[] = $coupon_receiver_email;
                                                                }

                                                                $coupon_details = array(
                                                                    $coupon_receiver_email  =>  array(
                                                                        'parent'    => $coupon->id,
                                                                        'code'      => $coupon_title,
                                                                        'amount'    => $amount
                                                                    )
                                                                );

                                $this->sa_email_coupon( $coupon_details, $coupon->discount_type );

                            } elseif ( $operation == 'remove' && $coupon->discount_type != 'smart_coupon' && ( $sc_disable_email_restriction == 'no' || empty( $sc_disable_email_restriction ) ) ) {

                                $key = array_search( $coupon_receiver_email, $old_customers_email_ids );

                                if ($key !== false) {
                                    unset( $old_customers_email_ids[$key] );
                                }

                            }

                            if ( ( $sc_disable_email_restriction == 'no' || empty( $sc_disable_email_restriction ) ) ) {
                                update_post_meta( $coupon->id, 'customer_email', $old_customers_email_ids );
                            }

                        }

                    }

                }

            }

                        //
                        function get_receivers_detail( $coupon_details = array(), $gift_certificate_sender_email = '' ) {

                            if ( count( $coupon_details ) <= 0 ) return 0;

                            global $woocommerce;

                            $receivers_email = array();

                            foreach ( $coupon_details as $coupon_id => $emails ) {
                                $discount_type = get_post_meta( $coupon_id, 'discount_type', true );
                                if ( $discount_type == 'smart_coupon' ) {
                                    $receivers_email = array_merge( $receivers_email, array_diff( $emails, array( $gift_certificate_sender_email ) ) );
                                }
                            }

                            return $receivers_email;
                        }

            // Function to process coupons based on change in order status
            function process_coupons( $order_id, $operation ) {
                            global $smart_coupon_codes;

                            $smart_coupon_codes = array();

                $receivers_emails = get_post_meta( $order_id, 'gift_receiver_email', true );
				$is_coupon_sent   = get_post_meta( $order_id, 'coupon_sent', true );

                if ( empty( $receivers_emails ) || $is_coupon_sent == 'yes' ) return;

                $sc_called_credit_details = get_post_meta( $order_id, 'sc_called_credit_details', true );
                
                $order = new WC_Order( $order_id );
                $order_items = (array) $order->get_items();

                if ( count( $order_items ) <= 0 ) {
                    return;
                }
                
                                foreach ( $receivers_emails as $coupon_id => $emails ) {
                                    foreach ( $emails as $key => $email ) {
                                        if ( empty( $email ) ) {
                                            $email = $order->billing_email;
                                            $receivers_emails[$coupon_id][$key] = $email;
                                        }
                                    }
                                }
                                
                                $email = $receivers_emails;

                                $gift_certificate_receiver = true;
                                $gift_certificate_sender_name = $order->billing_first_name . ' ' . $order->billing_last_name;
                                $gift_certificate_sender_email = $order->billing_email;
                                $gift_certificate_receiver_name = '';
                                $message_from_sender = get_post_meta( $order_id, 'gift_receiver_message', true );

                                $receivers_detail = array();

                if ( is_array( $sc_called_credit_details ) && count( $sc_called_credit_details ) > 0 ) {
                    
                    $email_to_credit = array();

                    foreach ( $order_items as $item_id => $item ) {
                        
                        $product = $order->get_product_from_item( $item );

                        $coupon_titles = get_post_meta( $product->id, '_coupon_title', true );

                        if ( $coupon_titles ) {
                            
                            foreach ( $coupon_titles as $coupon_title ) {
                                $coupon = new WC_Coupon( $coupon_title );
                                if ( !isset( $receivers_emails[$coupon->id] ) ) continue;
                                for ( $i = 0; $i < $item['qty']; $i++ ) {
                                    if ( isset( $receivers_emails[$coupon->id][0] ) ) {
                                        if ( !isset( $email_to_credit[$receivers_emails[$coupon->id][0]] ) ) {
                                            $email_to_credit[$receivers_emails[$coupon->id][0]] = array();
                                        }
                                        $email_to_credit[$receivers_emails[$coupon->id][0]][] = $coupon->id . ':' . $sc_called_credit_details[$item_id];
                                        unset( $receivers_emails[$coupon->id][0] );
                                        $receivers_emails[$coupon->id] = array_values( $receivers_emails[$coupon->id] );
                                    }
                                }

                            }
                            if ( $product->get_price() <= 0 ) {
                                $item['sc_called_credit'] = $sc_called_credit_details[$item_id];
                            }

                        }
                        unset( $order_items[$item_id] );
                    }
                    
                    if ( count( $email_to_credit ) > 0 ) {
                        foreach ( $email_to_credit as $email_id => $credits ) {
                            $email_to_credit[$email_id] = array_count_values( $credits );
                            foreach ( $email_to_credit[$email_id] as $coupon_credit => $qty ) {
                                $coupon_details = explode( ':', $coupon_credit );
                                $coupon_title = get_the_title( $coupon_details[0] );
                                $coupon = new WC_Coupon( $coupon_title );
                                $credit_amount = $coupon_details[1];
                                $this->generate_smart_coupon( $email_id, ( $credit_amount * $qty ), $order_id, $coupon, 'smart_coupon', $gift_certificate_receiver_name, $message_from_sender, $gift_certificate_sender_name, $gift_certificate_sender_email );
                                $smart_coupon_codes = array();
                            }
                        }
                        $receivers_detail = array_diff( array_keys( $email_to_credit ), array( $gift_certificate_sender_email ) );
                    }

                }

                
                if ( count( $order_items ) > 0 ) {
                                
                    foreach( $order_items as $item_id => $item ) {

                        $product = $order->get_product_from_item( $item );

                        $coupon_titles = get_post_meta( $product->id, '_coupon_title', true );

                        if ( $coupon_titles ) {

                            if ( $product->get_price() <= 0 ) {
                                $item['sc_called_credit'] = $sc_called_credit_details[$item_id];
                            }

                                                    $this->update_coupons( $coupon_titles, $email, '', $operation, $item, $gift_certificate_receiver, $gift_certificate_receiver_name, $message_from_sender, $gift_certificate_sender_name, $gift_certificate_sender_email, $order_id );

                                                    if ( $operation == 'add' ) {
                                $receivers_detail += $this->get_receivers_detail( $receivers_emails, $gift_certificate_sender_email );
                            }

                                            }
                    }
			}

                                update_post_meta($order_id, 'coupon_sent', 'yes');              // to know whether coupon has sent or not
                                
                                if ( count( $receivers_detail ) > 0 ) {
                    $this->acknowledge_gift_certificate_sender( $receivers_detail, $gift_certificate_receiver_name, $email, $gift_certificate_sender_email );
                }

                                unset( $smart_coupon_codes );
            }

            // Function to acknowledge sender of gift credit
            function acknowledge_gift_certificate_sender( $receivers_detail = array(), $gift_certificate_receiver_name = '', $email = '', $gift_certificate_sender_email = '' ) {

                                if ( count( $receivers_detail ) <= 0 ) return;

                // Start collecting content for e-mail
                ob_start();

                $subject = __( 'Gift Certificate sent successfully!', 'wc_smart_coupons' );

                do_action('woocommerce_email_header', $subject);

                echo sprintf(__('You have successfully sent %d %s to %s (%s)', 'wc_smart_coupons'), count( $receivers_detail ), _n( 'Gift Certificate', 'Gift Certificates', count( $receivers_detail ), 'wc_smart_coupons'), $gift_certificate_receiver_name, implode( ', ', array_unique( $receivers_detail ) ) );

                do_action('woocommerce_email_footer');

                // Get contents of the e-mail to be sent
                $message = ob_get_clean();
                woocommerce_mail( $gift_certificate_sender_email, $subject, $message );

            }

            // Function to add details to coupons
            function sa_add_coupons( $order_id ) {
                $this->process_coupons( $order_id, 'add' );
            }

            // Function to remove details from coupons
            function sa_remove_coupons( $order_id ) {
                $this->process_coupons( $order_id, 'remove' );
            }

            //Function to send e-mail containing coupon code to customer
            function sa_email_coupon( $coupon_title, $discount_type, $gift_certificate_receiver_name = '', $message_from_sender = '', $gift_certificate_sender_name = '', $gift_certificate_sender_email = '' ) {
                global $woocommerce;

                $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

                                $subject_string = __("Congratulations! You've received a coupon", 'wc_smart_coupons');

                                $url = ( get_option('permalink_structure') ) ? get_permalink( woocommerce_get_page_id('shop') ) : get_post_type_archive_link('product');

                if ( ( $discount_type == 'smart_coupon' ) && ( $gift_certificate_sender_name != '' || $gift_certificate_sender_email != '' ) ) {
                    $from = ( $gift_certificate_sender_name != '' ) ? $gift_certificate_sender_name . ' ( ' . $gift_certificate_sender_email . ' )' : substr( $gift_certificate_sender_email, 0, strpos( $gift_certificate_sender_email, '@' ) );
                    $subject_string .= ' ' . __( 'from', 'wc_smart_coupons' ) . ' ' . $from;
                }

                                $subject_string = ( get_option( 'smart_coupon_email_subject' ) && get_option( 'smart_coupon_email_subject' ) != '' ) ? __( get_option( 'smart_coupon_email_subject' ), 'wc_smart_coupons' ): $subject_string;

                                $subject = apply_filters( 'woocommerce_email_subject_gift_certificate', sprintf( '[%s] %s', $blogname, $subject_string ) );

                                foreach ( $coupon_title as $email => $coupon ) {

                                    $amount = $coupon['amount'];
                                    $coupon_code = $coupon['code'];

                                    switch ( $discount_type ) {

                                            case 'smart_coupon':
                                                    $email_heading  =  sprintf(__('You have received credit worth %s ', 'wc_smart_coupons'), woocommerce_price($amount) );
                                                    break;

                                            case 'fixed_cart':
                                                    $email_heading  =  sprintf(__('You have received a coupon worth %s (on entire purchase) ', 'wc_smart_coupons'), woocommerce_price($amount) );
                                                    break;

                                            case 'fixed_product':
                                                    $email_heading  =  sprintf(__('You have received a coupon worth %s (for a product) ', 'wc_smart_coupons'), woocommerce_price($amount) );
                                                    break;

                                            case 'percent_product':
                                                    $email_heading  =  sprintf(__('You have received a coupon worth %s%% (for a product) ', 'wc_smart_coupons'), $amount );
                                                    break;

                                            case 'percent':
                                                    $email_heading  =  sprintf(__('You have received a coupon worth %s%% (on entire purchase) ', 'wc_smart_coupons'), $amount );
                                                    break;

                                    }

                                    // Buffer
                                    ob_start();

                                    include(apply_filters('woocommerce_gift_certificates_email_template', 'templates/email.php'));

                                    if ( empty( $email ) ) {
                                        $email = $gift_certificate_sender_email;
                                    }   
                                    
                                    // Get contents of the e-mail to be sent
                                    $message = ob_get_clean();

                                    woocommerce_mail( $email, $subject, $message );

                                }

            }

                        //
                        function is_credit_sent( $email_id, $coupon ) {

                            global $smart_coupon_codes;

                            if ( isset( $smart_coupon_codes[$email_id] ) && count( $smart_coupon_codes[$email_id] ) > 0 ) {
                                foreach ( $smart_coupon_codes[$email_id] as $generated_coupon_details ) {
                                    if ( $generated_coupon_details['parent'] == $coupon->id ) return true;
                                }
                            }

                            return false;

                        }

                        //
                        function generate_unique_code( $email = '', $coupon = '' ) {
                                $unique_code = ( !empty( $email ) ) ? strtoupper( uniqid( substr( preg_replace('/[^a-z0-9]/i', '', sanitize_title( $email ) ), 0, 5 ) ) ) : strtoupper( uniqid() );

                                if ( !empty( $coupon ) && get_post_meta( $coupon->id, 'auto_generate_coupon', true) == 'yes' ) {
                                     $prefix = get_post_meta( $coupon->id, 'coupon_title_prefix', true);
                                     $suffix = get_post_meta( $coupon->id, 'coupon_title_suffix', true);
                                     $unique_code = $prefix . $unique_code . $suffix;
                                }

                                return $unique_code;
                        }

            // Function for generating Gift Certificate
            function generate_smart_coupon( $email, $amount, $order_id = '', $coupon = '', $discount_type = 'smart_coupon', $gift_certificate_receiver_name = '', $message_from_sender = '', $gift_certificate_sender_name = '', $gift_certificate_sender_email = '' ) {
                return apply_filters( 'generate_smart_coupon_action', $email, $amount, $order_id, $coupon, $discount_type, $gift_certificate_receiver_name, $message_from_sender, $gift_certificate_sender_name, $gift_certificate_sender_email );
            }

            // Function for generating Gift Certificate
            function generate_smart_coupon_action( $email, $amount, $order_id = '', $coupon = '', $discount_type = 'smart_coupon', $gift_certificate_receiver_name = '', $message_from_sender = '', $gift_certificate_sender_name = '', $gift_certificate_sender_email = '' ) {

                            if ( $email == '' ) return false;

                            global $smart_coupon_codes;

                            if ( !is_array( $email ) ) {
                                $emails = array( $email => 1 );
                            } else {
                                $emails = array_count_values( $email[$coupon->id] );
                            }

                            foreach ( $emails as $email_id => $qty ) {

                                if ( $this->is_credit_sent( $email_id, $coupon ) ) continue;

                                $smart_coupon_code = $this->generate_unique_code( $email_id, $coupon );

                                $smart_coupon_args = array(
                    'post_title'    => $smart_coupon_code,
                    'post_content'  => '',
                    'post_status'   => 'publish',
                    'post_author'   => 1,
                    'post_type'     => 'shop_coupon'
                );

                $smart_coupon_id = wp_insert_post( $smart_coupon_args );

                                $type                           = ( !empty( $coupon ) && !empty( $coupon->type ) ) ?  $coupon->type: 'smart_coupon';
                                $individual_use                 = ( !empty( $coupon ) ) ?  $coupon->individual_use: get_option('woocommerce_smart_coupon_individual_use');
                                $minimum_amount                 = ( !empty( $coupon ) ) ?  $coupon->minimum_amount: '';
                                $product_ids                    = ( !empty( $coupon ) ) ?  implode( ',', $coupon->product_ids ): '';
                                $exclude_product_ids            = ( !empty( $coupon ) ) ?  implode( ',', $coupon->exclude_product_ids ): '';
                                $usage_limit                    = ( !empty( $coupon ) ) ?  $coupon->usage_limit: '';
                                $expiry_date                    = ( !empty( $coupon ) && !empty( $coupon->expiry_date ) ) ?  date( 'Y-m-d', intval( $coupon->expiry_date ) ): '';
                                
								$sc_coupon_validity             = get_post_meta( $coupon->id, 'sc_coupon_validity', true );

                                if ( !empty( $sc_coupon_validity ) ) {
                                    $is_parent_coupon_expired = ( !empty( $expiry_date ) && ( strtotime( $expiry_date ) < time() ) ) ? true : false;
                                    if ( !$is_parent_coupon_expired ) {
                                        $validity_suffix = get_post_meta( $coupon->id, 'validity_suffix', true );
                                        $expiry_date = date( 'Y-m-d', strtotime( "+$sc_coupon_validity $validity_suffix" ) );
                                    }
                                }

                                $apply_before_tax               = ( !empty( $coupon ) ) ?  $coupon->apply_before_tax: 'no';
                                $free_shipping                  = ( !empty( $coupon ) ) ?  $coupon->free_shipping: 'no';
                                $product_categories             = ( !empty( $coupon ) ) ?  $coupon->product_categories: '';
                                $exclude_product_categories     = ( !empty( $coupon ) ) ?  $coupon->exclude_product_categories: '';

                // Add meta for Gift Certificate
                update_post_meta( $smart_coupon_id, 'discount_type', $type );
                update_post_meta( $smart_coupon_id, 'coupon_amount', ( $amount * $qty ) );
                update_post_meta( $smart_coupon_id, 'individual_use', $individual_use );
                update_post_meta( $smart_coupon_id, 'minimum_amount', $minimum_amount );
                update_post_meta( $smart_coupon_id, 'product_ids', $product_ids );
                update_post_meta( $smart_coupon_id, 'exclude_product_ids', $exclude_product_ids );
                update_post_meta( $smart_coupon_id, 'usage_limit', $usage_limit );
                update_post_meta( $smart_coupon_id, 'expiry_date', $expiry_date );
                update_post_meta( $smart_coupon_id, 'customer_email', array( $email_id ) );
                update_post_meta( $smart_coupon_id, 'apply_before_tax', $apply_before_tax  );
                update_post_meta( $smart_coupon_id, 'free_shipping', $free_shipping );
                                update_post_meta( $smart_coupon_id, 'product_categories', $product_categories  );
                                update_post_meta( $smart_coupon_id, 'exclude_product_categories', $exclude_product_categories );
                update_post_meta( $smart_coupon_id, 'generated_from_order_id', $order_id );

                                $generated_coupon_details = array(
                                    'parent'    => $coupon->id,
                                    'code'      => $smart_coupon_code,
                                    'amount'    => ( $amount * $qty )
                                );

                                $smart_coupon_codes[$email_id][] = $generated_coupon_details;

                                $this->sa_email_coupon( array( $email_id => $generated_coupon_details ), $discount_type, $gift_certificate_receiver_name, $message_from_sender, $gift_certificate_sender_name, $gift_certificate_sender_email );

                            }

                            return $smart_coupon_codes;

            }

            // Function to get current user's Credit amount
            function get_customer_credit() {

                if ( get_option( 'woocommerce_smart_coupon_show_my_account' ) == 'no' ) return;

                global $current_user;
                get_currentuserinfo();

                $args = array(
                    'post_type'         => 'shop_coupon',
                    'post_status'       => 'publish',
                    'posts_per_page'    => -1,
                    'meta_query'        => array(
                        array(
                            'key'       => 'customer_email',
                            'value'     => $current_user->user_email,
                            'compare'   => 'LIKE'
                        ),
                        array(
                            'key'       => 'coupon_amount',
                            'value'     => '0',
                            'compare'   => '>=',
                            'type'      => 'NUMERIC'
                        )
                    )
                );

                $coupons = get_posts( $args );

                return $coupons;
            }

                        //Funtion to add "duplicate" icon for coupons
                        function woocommerce_duplicate_coupon_link_row($actions, $post){

                                if ( function_exists( 'duplicate_post_plugin_activation' ) )
                                return $actions;

                                if ( ! current_user_can( 'manage_woocommerce' ) ) return $actions;

                                if ( $post->post_type != 'shop_coupon' )
                                return $actions;

                                $actions['duplicate'] = '<a href="' . wp_nonce_url( admin_url( 'admin.php?action=duplicate_coupon&amp;post=' . $post->ID ), 'woocommerce-duplicate-coupon_' . $post->ID ) . '" title="' . __("Make a duplicate from this coupon", 'wc_smart_coupons')
                                . '" rel="permalink">' .  __("Duplicate", 'wc_smart_coupons') . '</a>';

                                return $actions;
                        }

                        // function to insert post meta values for duplicate coupon
                        function woocommerce_duplicate_coupon_post_meta($id, $new_id){
                                global $wpdb;
                                $post_meta_infos = $wpdb->get_results("SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id=$id");

                                if (count($post_meta_infos)!=0) {
                                        $sql_query = "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) ";
                                        foreach ($post_meta_infos as $meta_info) {
                                                $meta_key = $meta_info->meta_key;
                                                $meta_value = addslashes($meta_info->meta_value);
                                                $sql_query_sel[]= "SELECT $new_id, '$meta_key', '$meta_value'";
                                        }
                                        $sql_query.= implode(" UNION ALL ", $sql_query_sel);
                                        $wpdb->query($sql_query);
                                }
                        }


                        // Function to duplicate post taxonomies for the duplicate coupon
                        function woocommerce_duplicate_coupon_post_taxonomies($id, $new_id, $post_type){
                                global $wpdb;
                                $taxonomies = get_object_taxonomies($post_type);
                                foreach ($taxonomies as $taxonomy) {
                                        $post_terms = wp_get_object_terms($id, $taxonomy);
                                        $post_terms_count = sizeof( $post_terms );
                                        for ($i=0; $i<$post_terms_count; $i++) {
                                                wp_set_object_terms($new_id, $post_terms[$i]->slug, $taxonomy, true);
                                        }
                                }
                        }

                        // Function to create duplicate coupon and copy all properties of the coupon to duplicate coupon
                        function woocommerce_create_duplicate_from_coupon( $post, $parent = 0, $post_status = '' ){
                                global $wpdb;

                                $new_post_author    = wp_get_current_user();
                                $new_post_date      = current_time('mysql');
                                $new_post_date_gmt  = get_gmt_from_date($new_post_date);

                                if ( $parent > 0 ) {
                                        $post_parent        = $parent;
                                        $post_status        = $post_status ? $post_status : 'publish';
                                        $suffix             = '';
                                } else {
                                        $post_parent        = $post->post_parent;
                                        $post_status        = $post_status ? $post_status : 'draft';
                                        $suffix             = __("(Copy)", 'wc_smart_coupons');
                                }

                                $new_post_type          = $post->post_type;
                                $post_content           = str_replace("'", "''", $post->post_content);
                                $post_content_filtered  = str_replace("'", "''", $post->post_content_filtered);
                                $post_excerpt           = str_replace("'", "''", $post->post_excerpt);
                                $post_title             = str_replace("'", "''", $post->post_title).$suffix;
                                $post_name              = str_replace("'", "''", $post->post_name);
                                $comment_status         = str_replace("'", "''", $post->comment_status);
                                $ping_status            = str_replace("'", "''", $post->ping_status);

                                // Insert the new template in the post table
                                $wpdb->query(
                                                "INSERT INTO $wpdb->posts
                                                (post_author, post_date, post_date_gmt, post_content, post_content_filtered, post_title, post_excerpt,  post_status, post_type, comment_status, ping_status, post_password, to_ping, pinged, post_modified, post_modified_gmt, post_parent, menu_order, post_mime_type)
                                                VALUES
                                                ('$new_post_author->ID', '$new_post_date', '$new_post_date_gmt', '$post_content', '$post_content_filtered', '$post_title', '$post_excerpt', '$post_status', '$new_post_type', '$comment_status', '$ping_status', '$post->post_password', '$post->to_ping', '$post->pinged', '$new_post_date', '$new_post_date_gmt', '$post_parent', '$post->menu_order', '$post->post_mime_type')");

                                $new_post_id = $wpdb->insert_id;

                                // Copy the taxonomies
                                $this->woocommerce_duplicate_coupon_post_taxonomies( $post->ID, $new_post_id, $post->post_type );

                                // Copy the meta information
                                $this->woocommerce_duplicate_coupon_post_meta( $post->ID, $new_post_id );

                                return $new_post_id;
                        }

                        // Functionto return post id of the duplicate coupon to be created
                        function woocommerce_get_coupon_to_duplicate( $id ){
                            global $wpdb;
                                $post = $wpdb->get_results("SELECT * FROM $wpdb->posts WHERE ID=$id");
                                if (isset($post->post_type) && $post->post_type == "revision"){
                                        $id = $post->post_parent;
                                        $post = $wpdb->get_results("SELECT * FROM $wpdb->posts WHERE ID=$id");
                                }
                                return $post[0];
                        }

                        // Function to validate condition and create duplicate coupon
                        function woocommerce_duplicate_coupon(){

                                if (! ( isset( $_GET['post']) || isset( $_POST['post'])  || ( isset($_REQUEST['action']) && 'duplicate_post_save_as_new_page' == $_REQUEST['action'] ) ) ) {
                                    wp_die(__('No coupon to duplicate has been supplied!', 'wc_smart_coupons'));
                                }

                                // Get the original page
                                $id = (isset($_GET['post']) ? $_GET['post'] : $_POST['post']);
                                check_admin_referer( 'woocommerce-duplicate-coupon_' . $id );
                                $post = $this->woocommerce_get_coupon_to_duplicate($id);

                                if (isset($post) && $post!=null) {
                                    $new_id = $this->woocommerce_create_duplicate_from_coupon($post);

                                    // If you have written a plugin which uses non-WP database tables to save
                                    // information about a page you can hook this action to dupe that data.
                                    do_action( 'woocommerce_duplicate_coupon', $new_id, $post );

                                    // Redirect to the edit screen for the new draft page
                                    wp_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
                                    exit;
                                } else {
                                    wp_die(__('Coupon creation failed, could not find original product:', 'wc_smart_coupons') . ' ' . $id);
                                }

                        }

                        // Function to call function to create duplicate coupon
                        function woocommerce_duplicate_coupon_action(){
                            $this->woocommerce_duplicate_coupon();
                        }


                        // Funtion to show search result based on email id included in customer email
                        function woocommerce_admin_coupon_search( $wp ){
                                global $pagenow, $wpdb;

                                if( 'edit.php' != $pagenow ) return;
                                if( !isset( $wp->query_vars['s'] ) ) return;
                                if ($wp->query_vars['post_type']!='shop_coupon') return;

                                $e = substr( $wp->query_vars['s'], 0, 6 );

                                if( 'Email:' == substr( $wp->query_vars['s'], 0, 6 ) ) {

                                    $email = trim( substr( $wp->query_vars['s'], 6 ) );

                                    if( !$email ) return;

                                    $post_ids = $wpdb->get_col( 'SELECT post_id FROM '.$wpdb->postmeta.' WHERE meta_key="customer_email" AND meta_value LIKE "%'.$email.'%"; ' );

                                    if( !$post_ids ) return;

                                    unset( $wp->query_vars['s'] );

                                    $wp->query_vars['post__in'] = $post_ids;

                                    $wp->query_vars['email'] = $email;
                                }

                        }

                        // Function to show label of the search result on email
                        function woocommerce_admin_coupon_search_label( $query ){
                                global $pagenow, $typenow, $wp;

                                if ( 'edit.php' != $pagenow ) return $query;
                                if ( $typenow!='shop_coupon' ) return $query;

                                $s = get_query_var( 's' );
                                if ($s) return $query;

                                $email = get_query_var( 'email' );

                                if( $email ) {

                                    $post_type = get_post_type_object($wp->query_vars['post_type']);
                                    return sprintf(__("[%s with email of %s]", 'wc_smart_coupons'), $post_type->labels->singular_name, $email);
                                }

                                return $query;
                        }

                        // funtion to register the coupon importer
                        function woocommerce_coupon_admin_init(){
                                global $wpdb;

                                if ( defined( 'WP_LOAD_IMPORTERS' ) ) {
                                        register_importer( 'woocommerce_coupon_csv', 'Import WooCommerce Coupons', __('Import <strong>coupons</strong> to your store using CSV file.', 'wc_smart_coupons'), array( &$this, 'coupon_importer')  );
                                }

                                if ( !empty( $_GET['action'] ) && ( $_GET['action'] == 'sent_gift_certificate' ) && !empty( $_GET['page'] ) && ( $_GET['page']=='woocommerce_coupon_csv_import' ) ) {
                                        $email = $_POST['smart_coupon_email'];
                                        $amount = $_POST['smart_coupon_amount'];
                                        $this->send_gift_certificate( $email, $amount );
                }
                        }

                        //
                        function woocommerce_show_import_message(){
                            global $pagenow,$typenow;
                            
                            if( ! isset($_GET['show_import_message'])) return;
                            
                            if( isset($_GET['show_import_message']) && $_GET['show_import_message'] == true ){
                                if( 'edit.php' == $pagenow && 'shop_coupon' == $typenow ){
                                    
                                    $imported = $_GET['imported'];
                                    $skipped = $_GET['skipped'];
                                    
                                    echo '<div id="message" class="updated fade"><p>
                                            '.sprintf(__('Import complete - imported <strong>%s</strong>, skipped <strong>%s</strong>', 'wc_smart_coupons'), $imported, $skipped  ).'
                                    </p></div>';
                                }
                            }
                        }

                        //
                        function send_gift_certificate( $email, $amount ){
                                global $woocommerce;

                                $emails = explode( ',', $email );

                                foreach ( $emails as $email ) {

                                    $email = trim( $email );

                                    if ( count( $emails ) == 1 && ( !$email || !is_email($email) ) ) {

                                        $location = admin_url('admin.php?page=woocommerce_coupon_csv_import&tab=send_certificate&email_error=yes');

                                    } elseif ( count( $emails ) == 1 && ( !$amount || !is_numeric($amount) ) ) {

                                        $location = admin_url('admin.php?page=woocommerce_coupon_csv_import&tab=send_certificate&amount_error=yes');

                                    } elseif ( is_email( $email ) && is_numeric( $amount ) ) {

                                        $coupon_title = $this->generate_smart_coupon( $email, $amount );

                                        $location = admin_url('admin.php?page=woocommerce_coupon_csv_import&tab=send_certificate&sent=yes');

                                    }
                                }

                                wp_safe_redirect($location);
                        }

                        // Function to add submenu page for Coupon CSV Import
                        function woocommerce_coupon_admin_menu(){
                                $page = add_submenu_page('woocommerce', __( 'Smart Coupon', 'wc_smart_coupons' ), __( 'Smart Coupon', 'wc_smart_coupons' ), 'manage_woocommerce', 'woocommerce_coupon_csv_import', array(&$this, 'admin_page') );
                        }

                        // funtion to show content on the Coupon CSV Importer page
                        function admin_page(){
                                global $woocommerce;

                                $tab = ( !empty($_GET['tab'] )  ? ( $_GET['tab'] == 'send_certificate'   ? 'send_certificate': 'import' ) : 'generate_bulk_coupons' )   ;

                                ?>

                <div class="wrap woocommerce">

                    <h2 class="nav-tab-wrapper woo-nav-tab-wrapper">
                                        <a href="<?php echo admin_url('admin.php?page=woocommerce_coupon_csv_import') ?>" class="nav-tab <?php echo ($tab == 'generate_bulk_coupons') ? 'nav-tab-active' : ''; ?>"><?php _e('Generate Coupons', 'wc_smart_coupons'); ?></a>
                        <a href="<?php echo admin_url('admin.php?page=woocommerce_coupon_csv_import&tab=import') ?>" class="nav-tab <?php echo ($tab == 'import') ? 'nav-tab-active' : ''; ?>"><?php _e('Import Coupons', 'wc_smart_coupons'); ?></a>
                                        <a href="<?php echo admin_url('admin.php?page=woocommerce_coupon_csv_import&tab=send_certificate') ?>" class="nav-tab <?php echo ($tab == 'send_certificate') ? 'nav-tab-active' : ''; ?>"><?php _e('Send Store Credit', 'wc_smart_coupons'); ?></a>
                                        
                    </h2>

                    <?php
                        switch ($tab) {
                            case "send_certificate" :
                                $this->admin_send_certificate();
                            break;
                                                        case "import" :
                                $this->admin_import_page();
                            break;
                            default :
                                                                $this->admin_generate_bulk_coupons_and_export();
                            break;
                        }
                    ?>

                </div>
                <?php

                        }

                        //
                        function admin_import_page() {
                                global $woocommerce;
                ?>
                <div class="tool-box">
                                    <h3 class="title"><?php _e('Bulk Upload / Import Coupons using CSV file', 'wc_smart_coupons'); ?></h3>
                                    <p class="description"><?php _e('Upload a CSV file & click \'Import\' . Importing requires <code>post_title</code> column.', 'wc_smart_coupons'); ?></p>
                                    <p class="submit"><a class="button" href="<?php echo admin_url('admin.php?import=woocommerce_coupon_csv'); ?>"><?php _e('Import Coupons', 'wc_smart_coupons'); ?></a> </p>
                                </div>
                                <?php
                        }

                        //
                        function admin_send_certificate() {
                                global $woocommerce;

                                if( !empty($_GET['sent']) && $_GET['sent']=='yes' ){
                                    echo '<div id="message" class="updated fade"><p><strong>' . __( 'Store Credit / Gift Certificate sent successfully.', 'wc_smart_coupons' ) . '</strong></p></div>';
                                }

                                ?>
                <div class="tool-box">

                    <h3 class="title"><?php _e('Send Store Credit / Gift Certificate', 'wc_smart_coupons'); ?></h3>
                    <p class="description"><?php _e('Click "Send" to send Store Credit / Gift Certificate. *All field are compulsary.', 'wc_smart_coupons'); ?></p>

                    <form action="<?php echo admin_url('admin.php?page=woocommerce_coupon_csv_import&action=sent_gift_certificate'); ?>" method="post">

                        <table class="form-table">
                            <tr>
                                <th>
                                    <label for="smart_coupon_email"><?php _e( 'Email ID', 'wc_smart_coupons' ); ?> *</label>
                                </th>
                                <td>
                                                                    <input type="text" name="smart_coupon_email" id="email" class="input-text" />
                                </td>
                                                                <td>
                                                                    <?php
                                                                        if( !empty($_GET['email_error']) && $_GET['email_error']=='yes' ){
                                                                          echo '<div id="message" class="error fade"><p><strong>' . __( 'Invalid email address.', 'wc_smart_coupons' ) . '</strong></p></div>';
                                                                        }
                                                                    ?>
                                                                    <span class="description"><?php _e( 'Use comma "," as separator for multiple e-mail ids', 'wc_smart_coupons' ); ?></span>
                                                                </td>
                            </tr>

                                                        <tr>
                                <th>
                                    <label for="smart_coupon_amount"><?php _e( 'Coupon Amount', 'wc_smart_coupons' ); ?> *</label>
                                </th>
                                <td>
                                    <input type="text" name="smart_coupon_amount" id="amount" placeholder="<?php _e('0.00', 'wc_smart_coupons'); ?>" class="input-text" />
                                </td>
                                                                <td>
                                                                    <?php
                                                                        if( !empty($_GET['amount_error']) && $_GET['amount_error']=='yes' ){
                                                                              echo '<div id="message" class="error fade"><p><strong>' . __( 'Invalid amount.', 'wc_smart_coupons' ) . '</strong></p></div>';
                                                                        }
                                                                    ?>
                                                                </td>
                            </tr>

                        </table>

                        <p class="submit"><input type="submit" class="button" value="<?php _e('Send', 'wc_smart_coupons'); ?>" /></p>

                    </form>
                </div>
                                <?php
                        }

                        //
                        function admin_generate_bulk_coupons_and_export(){
                            global $woocommerce, $woocommerce_smart_coupon;
                            
                            $upload_url = wp_upload_dir();
                            $upload_path = $upload_url['path'];

                            $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
                            // Register scripts
                            wp_register_script( 'woocommerce_admin', $woocommerce->plugin_url() . '/assets/js/admin/woocommerce_admin' . $suffix . '.js', array ('jquery', 'jquery-ui-widget', 'jquery-ui-core' ), '1.0' );
                            wp_register_script( 'woocommerce_writepanel', $woocommerce->plugin_url() . '/assets/js/admin/write-panels' . $suffix . '.js', array ('jquery' ) );
                            wp_register_script( 'ajax-chosen', $woocommerce->plugin_url() . '/assets/js/chosen/ajax-chosen.jquery' . $suffix . '.js', array ('jquery' ), '1.0' );
                            
                            wp_enqueue_script( 'woocommerce_admin' );
                            wp_enqueue_script( 'woocommerce_writepanel' );
                            wp_enqueue_script( 'ajax-chosen' );
                            
                            $woocommerce_witepanel_params = array ('ajax_url' => admin_url( 'admin-ajax.php' ), 'search_products_nonce' => wp_create_nonce( "search-products" ) );
                            
                            wp_localize_script( 'woocommerce_writepanel', 'woocommerce_writepanel_params', $woocommerce_witepanel_params );
                            
                            wp_enqueue_style( 'woocommerce_admin_styles', $woocommerce->plugin_url() . '/assets/css/admin.css' );
                            wp_enqueue_style( 'jquery-ui-style', (is_ssl()) ? 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/smoothness/jquery-ui.css' : 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/smoothness/jquery-ui.css' );
                            wp_enqueue_style( 'woocommerce_chosen_styles', $woocommerce->plugin_url() . '/assets/css/chosen.css' );
                                
                            // Adding style for help tip for WC 2.0
                            if (version_compare(WOOCOMMERCE_VERSION, "2.0.0") >= 0) {
                                $style = "width:16px;height=16px;" ; 
                            } else {
                                $style = '';
                            }

                            if( isset($_POST['generate_and_import'] ) && isset($_POST['sc_export_and_import'])) {  
                                
                                $this->export_coupon( $_POST, '', '' );
                            }                            
                            ?>
                                    
                                    <script type="text/javascript">
                                        jQuery(document).ready(function(){
                                            
                                            jQuery('input#sc_expiry_date').datepicker({
                                                dateFormat: 'yy-mm-dd'
                                            });

                                            jQuery('.message').hide();
                                            jQuery('input#generate_and_import').click(function(){
                                                                                                
                                                if( jQuery('input#no_of_coupons_to_generate').val() == "" ){
                                                    jQuery("div#message").removeClass("updated fade").addClass("error fade");
                                                    jQuery('div#message p').html( "<strong>Please enter a valid value for Number of Coupons to Generate </strong>");
                                                    return false;
                                                } else {
                                                    return true;
                                                }                                                  
                                            });
                                            
                                            jQuery("input#sc_export_and_import").change(function() {

                                               if(jQuery("input#sc_export_and_import").attr("checked") ) {
                                                   
                                                   jQuery('span#desc_for_file').text("") ;
                                                   jQuery('input#generate_and_import').val("Generate and Export .CSV file") ;
                                                   jQuery('form#generate_coupons').attr('action', '<?php echo admin_url('admin.php?page=woocommerce_coupon_csv_import'); ?>');
                                                   jQuery('p#woo_sc_is_email_imported_coupons_row').hide();
                                                   jQuery('input#woo_sc_is_email_imported_coupons').removeAttr('checked');

                                               } else {

                                                   jQuery('input#generate_and_import').val("Generate and Add to the Store") ;
                                                   jQuery('form#generate_coupons').attr('action', '<?php echo admin_url( 'admin.php?import=woocommerce_coupon_csv&step=2'); ?>');
                                                   jQuery('p#woo_sc_is_email_imported_coupons_row').show();
                                                   
                                               }
                                               
                                             });

                                        });   
                                    </script> 
                                    
                                    <div id="message"><p></p></div>
                                    <div class="tool-box">

                    <h3 class="title"><?php _e('Generate Coupons', 'wc_smart_coupons'); ?></h3>
                    <p class="description"><?php _e('You can bulk generate coupons using options below.', 'wc_smart_coupons'); ?></p>


                    <form id="generate_coupons" action="<?php echo admin_url( 'admin.php?import=woocommerce_coupon_csv&step=2'); ?>" method="post">
                                                <?php wp_nonce_field( 'import-woocommerce-coupon' ); ?>
                        <div class="panel woocommerce_options_panel">
                            <div class="option_group">
                                <p class="form-field">                                
                                    <label for="no_of_coupons_to_generate"><?php _e( 'Number of Coupons to Generate ', 'wc_smart_coupons' ); ?> *</label>
                                    <input type="number" name="no_of_coupons_to_generate" id="no_of_coupons_to_generate" placeholder="<?php _e('10', 'wc_smart_coupons'); ?>" class="short" min="1" />                                
                                </p>
                                                        
                                <p class="form-field">
                                    <label for="discount_type"><?php _e( 'Discount Type', 'wc_smart_coupons' ); ?> </label>
                                    <select id="discount_type" name="discount_type" class="select short">
                                        <?php
                                            foreach ( $woocommerce->get_coupon_discount_types() as $key => $value ) {

                                                echo '<option value="' . esc_attr( $key ) . '" >' . esc_html( $value ) . '</option>';

                                            }
                                        ?>
                                    </select>
                                </p>

                                <p class="form-field">
                                    <label for="smart_coupon_amount"><?php _e( 'Coupon Amount', 'wc_smart_coupons' ); ?> </label>
                                    <input type="number" name="smart_coupon_amount" id="amount" placeholder="<?php _e('0.00', 'wc_smart_coupons'); ?>" class="short" min="0" step="any" />
                                    <span class="description"><?php echo __( 'Value of the coupon.', 'wc_smart_coupons' ); ?></span>
                                </p>

                                <p class="form-field">
                                    <label><?php _e( 'Enable free shipping', 'wc_smart_coupons' ); ?></label>
                                    <input type="checkbox" name="sc_free_shipping" id="sc_free_shipping"  />
                                    <span class="description"><?php echo sprintf(__( 'Check this box if the coupon grants free shipping. The <a href="%s">free shipping method</a> must be enabled with the "must use coupon" setting checked.', 'wc_smart_coupons' ), admin_url('admin.php?page=woocommerce_settings&tab=shipping&section=WC_Shipping_Free_Shipping')); ?></span>
                                </p>  

                                <p class="form-field">
                                    <label for="sc_individual_use"><?php _e( 'Individual use', 'wc_smart_coupons' ); ?></label>
                                    <input type="checkbox" name="sc_individual_use" id="sc_individual_use"  />
                                    <span class="description"><?php echo __( 'Check this box if the coupon cannot be used in conjunction with other coupons.', 'wc_smart_coupons' ); ?></span>
                                </p>  
                                
                                <p class="form-field">
                                    <label for="sc_apply_before_tax"><?php _e( 'Apply before tax', 'wc_smart_coupons' ); ?></label>
                                    <input type="checkbox" name="sc_apply_before_tax" id="sc_apply_before_tax"  />
                                    <span class="description"><?php echo __( 'Check this box if the coupon should be applied before calculating cart tax.', 'wc_smart_coupons' ); ?></span>
                                </p>  
                                
                                <p class="form-field">
                                    <label for="sc_exclude_sale_items"><?php _e( 'Exclude sale items', 'wc_smart_coupons' ); ?></label>
                                    <input type="checkbox" name="sc_exclude_sale_items" id="sc_exclude_sale_items"  />
                                    <span class="description"><?php echo __( 'Check this box if the coupon should not apply to items on sale. Per-item coupons will only work if the item is not on sale. Per-cart coupons will only work if there are no sale items in the cart.', 'wc_smart_coupons' ); ?></span>
                                </p>

                                <p class="form-field">
                                    <label for="sc_minimum_amount"><?php _e( 'Minimum amount', 'wc_smart_coupons' ); ?></label>
                                    <input type="number" name="sc_minimum_amount" id="sc_minimum_amount"  />
                                    <span class="description"><?php echo __( 'This field allows you to set the minimum subtotal needed to use the coupon.', 'wc_smart_coupons' ); ?></span>
                                </p>
                            
                                <p class="form-field">
                                    <label><?php _e( 'Products', 'wc_smart_coupons' ) ?></label>
                                    
                                        <select id="product_ids" name="product_ids[]" class="ajax_chosen_select_products_and_variations" multiple="multiple" data-placeholder="<?php _e( 'Search for a product&hellip;', 'wc_smart_coupons' ); ?>">
                                            <?php
                                                $product_ids = get_post_meta( $post->ID, 'product_ids', true );
                                                if ( $product_ids ) {
                                                    $product_ids = array_map( 'absint', explode( ',', $product_ids ) );
                                                    foreach ( $product_ids as $product_id ) {

                                                        $product      = get_product( $product_id );
                                                        $product_name = woocommerce_get_formatted_product_name( $product );

                                                        echo '<option value="' . esc_attr( $product_id ) . '" selected="selected">' . wp_kses_post( $product_name ) . '</option>';
                                                    }
                                                }
                                            ?>
                                        </select> <img class="help_tip" data-tip='<?php _e( 'Products which need to be in the cart to use this coupon or, for "Product Discounts", which products are discounted.', 'wc_smart_coupons' ) ?>' src="<?php echo $woocommerce->plugin_url(); ?>/assets/images/help.png" height="16" width="16" /></p>
                                        
                                    
                                </p>
                                
                                <p class="form-field">
                                    <label><?php _e( 'Exclude products', 'wc_smart_coupons' ) ?></label>
                                    
                                        <select id="exclude_product_ids" name="exclude_product_ids[]" class="ajax_chosen_select_products_and_variations" multiple="multiple" data-placeholder="<?php _e( 'Search for a product…', 'wc_smart_coupons' ); ?>">
                                            <?php
                                                $product_ids = get_post_meta( $post->ID, 'exclude_product_ids', true );
                                                if ( $product_ids ) {
                                                    $product_ids = array_map( 'absint', explode( ',', $product_ids ) );
                                                    foreach ( $product_ids as $product_id ) {

                                                        $product      = get_product( $product_id );
                                                        $product_name = woocommerce_get_formatted_product_name( $product );

                                                        echo '<option value="' . esc_attr( $product_id ) . '" selected="selected">' . esc_html( $product_name ) . '</option>';
                                                    }
                                                }
                                            ?>
                                        </select> <img class="help_tip" data-tip='<?php _e( 'Products which must not be in the cart to use this coupon or, for "Product Discounts", which products are not discounted.', 'wc_smart_coupons' ) ?>' src="<?php echo $woocommerce->plugin_url(); ?>/assets/images/help.png" height="16" width="16" /></p>
                                    
                                </p>
                                
                                <p class="form-field">
                                    <label><?php _e( 'Product categories', 'wc_smart_coupons' ) ?></label>
                                    
                                        <select id="product_categories" name="product_categories[]" class="chosen_select" multiple="multiple" data-placeholder="<?php _e( 'Any category', 'wc_smart_coupons' ); ?>">
                                            <?php
                                                $category_ids = (array) get_post_meta( $post->ID, 'product_categories', true );

                                                $categories = get_terms( 'product_cat', 'orderby=name&hide_empty=0' );
                                                if ( $categories ) foreach ( $categories as $cat )
                                                    echo '<option value="' . esc_attr( $cat->term_id ) . '"' . selected( in_array( $cat->term_id, $category_ids ), true, false ) . '>' . esc_html( $cat->name ) . '</option>';
                                            ?>
                                        </select> <img class="help_tip" data-tip='<?php _e( 'A product must be in this category for the coupon to remain valid or, for "Product Discounts", products in these categories will be discounted.', 'wc_smart_coupons' ) ?>' src="<?php echo $woocommerce->plugin_url(); ?>/assets/images/help.png" height="16" width="16" /></p>
                                    
                                </p>
                                
                                <p class="form-field">
                                    <label for="exclude_product_categories"><?php _e( 'Exclude categories', 'wc_smart_coupons' ) ?></label>
                                    
                                        <select id="exclude_product_categories" name="exclude_product_categories[]" class="chosen_select" multiple="multiple" data-placeholder="<?php _e( 'No categories', 'wc_smart_coupons' ); ?>">
                                            <?php
                                                $category_ids = (array) get_post_meta( $post->ID, 'exclude_product_categories', true );

                                                $categories = get_terms( 'product_cat', 'orderby=name&hide_empty=0' );
                                                if ( $categories ) foreach ( $categories as $cat )
                                                    echo '<option value="' . esc_attr( $cat->term_id ) . '"' . selected( in_array( $cat->term_id, $category_ids ), true, false ) . '>' . esc_html( $cat->name ) . '</option>';
                                            ?>
                                        </select> <img class="help_tip" data-tip='<?php _e( 'Product must not be in this category for the coupon to remain valid or, for "Product Discounts", products in these categories will not be discounted.', 'wc_smart_coupons' ) ?>' src="<?php echo $woocommerce->plugin_url(); ?>/assets/images/help.png" height="16" width="16" /></p>
                                </p>
                                  
                        
                                <p class="form-field">
                                    <label for="sc_customer_emails"><?php _e( 'Email restrictions', 'wc_smart_coupons' ); ?> </label>
                                    <input type="text" name="sc_customer_emails" id="sc_customer_emails" class="input-text" />
                                    <img class="help_tip" data-tip='<?php _e( 'List of emails to check against the customer&#39;s billing email when an order is placed. Enter comma (,) separated e-mail ids. One coupon code will be assigned to one e-mail. Number of e-mails should be equal to number of coupons to be generated.', 'wc_smart_coupons' ) ?>' src="<?php echo $woocommerce->plugin_url(); ?>/assets/images/help.png" height="16" width="16" /></p>
                                </p>

                                <p class="form-field">
                                    <label for="sc_usage_limit"><?php _e( 'Usage limit', 'wc_smart_coupons' ); ?></label>
                                    <input type="number" name="sc_usage_limit" id="sc_usage_limit"  />
                                    <span class="description"><?php echo __( 'How many times this coupon can be used before it is void.', 'wc_smart_coupons' ); ?></span>
                                </p>   
                                   
                                <?php
                                    if ( !wp_script_is( 'jquery-ui-datepicker' ) ) {
                                        wp_enqueue_script('jquery-ui-datepicker');
                                        wp_enqueue_style('jquery-style', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.2/themes/smoothness/jquery-ui.css');
                                    }

                                ?>

                                <p class="form-field">
                                    <label for="sc_expiry_date"><?php _e( 'Expiry date', 'wc_smart_coupons' ); ?></label>
                                    <input type="text" name="sc_expiry_date" id="sc_expiry_date"  />
                                    <span class="description"><?php echo __( 'The date this coupon will expire, <code>YYYY-MM-DD</code>.', 'wc_smart_coupons' ); ?></span>
                                </p>

                                <p class="form-field">
                                    <label for="sc_prefix_for_code"><?php _e( 'Prefix for Coupon Code', 'wc_smart_coupons' ); ?> </label>
                                    <input type="text" name="sc_prefix_for_code" id="sc_prefix" class="input-text" />
                                </p>

                                <p class="form-field">
                                    <label for="sc_suffix_for_code"><?php _e( 'Suffix for Coupon Code', 'wc_smart_coupons' ); ?> </label>
                                    <input type="text" name="sc_suffix_for_code" id="sc_suffix" class="input-text" />
                                </p>

                                <p class="form-field">
                                    <label><?php echo '&nbsp;'; ?></label>
                                    <input type="checkbox" name="sc_export_and_import" id="sc_export_and_import"  /> 
                                    <span class="description"><?php _e( 'Generate only, do not add these coupons in WooCommerce. This will download a .CSV file which you can later import from ', 'wc_smart_coupons' ); 
                                                echo '<a href="admin.php?page=woocommerce_coupon_csv_import&tab=import">' . __( 'Import Coupons', 'wc_smart_coupons' ) . '</a>'; ?></span>
                                </p>

                                <p class="form-field" id="woo_sc_is_email_imported_coupons_row">
                                    <label><?php echo '&nbsp;'; ?></label>
                                    <input type="checkbox" name="woo_sc_is_email_imported_coupons" id="woo_sc_is_email_imported_coupons"  /> 
                                    <span class="description"><?php _e( 'E-mail imported coupon codes to respective customers/users.', 'wc_smart_coupons' ); ?></span>
                                </p>

                            </div>
                        </div>
                                                
                        <p class="submit"><input id="generate_and_import" name="generate_and_import" type="submit" class="button" value="<?php _e('Generate and Add to the Store', 'wc_smart_coupons'); ?>" /></p>

                    </form>
                </div>
                                <?php  
                                
                        }

                        //
                        function woocommerce_restrict_manage_smart_coupons(){
                                global $woocommerce, $typenow, $wp_query,$wp,$woocommerce_smart_coupon;

                                $array = $wp_query->query;
                                
                                $sc_query = new WP_Query($array);
                                
                                if ( $typenow != 'shop_coupon' )
                                    return;

                                if( version_compare( get_bloginfo( 'version' ), '3.5', '<' ) ) {
                                    $background_position_x = 0.9;
                                    $background_size = 1.4;
                                    $padding_left = 2.5;
                                } else {
                                    $background_position_x = 0.4;
                                    $background_size = 1.5;
                                    $padding_left = 2.2;
                                }
                                ?>                                   
                                    <style type="text/css">
                                        
                                            input#export_coupons{

                    background-image:url("<?php echo plugins_url( 'assets/images/import.png' , __FILE__ ) ; ?>");
                                                background-repeat:no-repeat;
                                                background-position: center;  
                                                background-position-x:<?php echo $background_position_x.'em' ?>;
                                                background-size:<?php echo $background_size.'em' ?>;
                                                padding-left:<?php echo $padding_left.'em' ?>;

                                        }
                                   </style>

                                    <div class="alignright" style="margin-top: 1px;" >
                                            <input type="submit" name="export_coupons" id="export_coupons" class="button apply" value="<?php _e('Export Coupons', 'wc_smart_coupons'); ?>" >
                                    </div>
                                    
                                    
                                <?php


                        }
                        
                        
                        //
                        function woocommerce_export_coupons(){
                                global $typenow, $wp_query,$wp,$post;
                               
                                if(isset($_GET['export_coupons'])){
                                     
                                    $args = array(  'post_status' => '',
                                                    'post_type' => '',
                                                    'm' => '',
                                                    'posts_per_page' => -1,
                                                    'fields' => 'ids',


                                        ); 

                                    if(isset($_GET['coupon_type']) && $_GET['coupon_type'] != ''){
                                        $args['meta_query'] = array(
                                                    array(
                                                            'key'   => 'discount_type',
                                                            'value'     => $_GET['coupon_type']
                                                    )
                                            );
                                    }

                                    foreach($args as $key => $value){
                                        if(array_key_exists($key, $_GET)){
                                            $args[$key] = $_GET[$key];
                                        } else {
                                            $args[$key] = $value;
                                        }
                                    }

                                    if($args['post_status'] == "all"){
                                        $args['post_status'] =  array("publish", "draft", "pending", "private","future");

                                    }

                                    $query = new WP_Query($args);
                                  
                                    $post_ids = $query->posts;
                              
                                    $this->export_coupon( '', $_GET, $post_ids );
                                }
                        }
                        
                        //
                        function generate_coupons_code( $post, $get, $post_ids ){
                            global $wpdb, $wp, $wp_query;
                            
                            $data = array();
                            if( !empty( $post ) && isset( $post['generate_and_import'] ) ) {

                                if ( isset( $post['sc_customer_emails'] ) && !empty( $post['sc_customer_emails'] ) ) {
                                    $emails = explode( ',', $post['sc_customer_emails'] );
                                    if ( is_array( $emails ) && count( $emails ) > 0 ) {
                                        $customer_emails = array();
                                        for ( $j = 1; $j <= $post['no_of_coupons_to_generate']; $j++ ) {
                                            $customer_emails[$j] = ( isset( $emails[$j-1] ) && is_email( $emails[$j-1] ) ) ? $emails[$j-1] : '';
                                        }
                                    }
                                }
                                
                                for( $i = 1; $i <= $post['no_of_coupons_to_generate']; $i++ ){

                                     // $code = $post['sc_prefix_for_code'] . strtoupper( uniqid() ) . $post['sc_suffix_for_code'];
                                     $unique_code = $this->generate_unique_code( $customer_emails[$i] );
                                     $code = $post['sc_prefix_for_code'] . $unique_code . $post['sc_suffix_for_code'];

                                     $data[$i]['post_title'] = $code;
                                      if( "fixed_cart" == $post['discount_type'] ){
                                          $data[$i]['discount_type'] = "Cart Discount";
                                      } elseif( "percent" == $post['discount_type'] ) {
                                          $data[$i]['discount_type'] = "Cart % Discount";
                                      } elseif( "fixed_product" == $post['discount_type'] ) {
                                          $data[$i]['discount_type'] = "Product Discount";
                                      } elseif( "percent_product" == $post['discount_type'] ) {
                                          $data[$i]['discount_type'] = "Product % Discount";
                                      } elseif( "smart_coupon" == $post['discount_type'] ) {
                                          $data[$i]['discount_type'] = "Store Credit / Gift Certificate";
                                      }

                                     $data[$i]['coupon_amount']                 = $post['smart_coupon_amount'];
                                     $data[$i]['free_shipping']                 = ( isset( $post['sc_free_shipping'] ) ) ? 'yes' : 'no';
                                     $data[$i]['individual_use']                = ( isset( $post['sc_individual_use'] ) ) ? 'yes' : 'no';
                                     $data[$i]['apply_before_tax']              = ( isset( $post['sc_apply_before_tax'] ) ) ? 'yes' : 'no';
                                     $data[$i]['exclude_sale_items']            = ( isset( $post['sc_exclude_sale_items'] ) ) ? 'yes' : 'no';
                                     $data[$i]['minimum_amount']                = ( isset( $post['sc_minimum_amount'] ) ) ? $post['sc_minimum_amount'] : '';
                                     $data[$i]['product_ids']                   = ( isset( $post['product_ids'] ) ) ? implode( '|', $post['product_ids'] ) : '';
                                     $data[$i]['exclude_product_ids']           = ( isset( $post['exclude_product_ids'] ) ) ? implode( '|', $post['exclude_product_ids'] ) : '';
                                     $data[$i]['product_categories']            = ( isset( $post['product_categories'] ) ) ? implode( '|', $post['product_categories'] ) : '';
                                     $data[$i]['exclude_product_categories']    = ( isset( $post['exclude_product_categories'] ) ) ? implode( '|', $post['exclude_product_categories'] ) : '';
                                     $data[$i]['customer_email']                = $customer_emails[$i];
                                     $data[$i]['usage_limit']                   = ( isset( $post['sc_usage_limit'] ) ) ? $post['sc_usage_limit'] : '';
                                     $data[$i]['expiry_date']                   = ( isset( $post['sc_expiry_date'] ) ) ? $post['sc_expiry_date']: '';
                                     $data[$i]['post_status']                   = "publish";
                                     
                                 }
                            }
                           
                           if( !empty( $get ) && isset( $get['export_coupons'] ) ) {
                               
                                $query_to_fetch_data = " SELECT p.ID, 
                                                              p.post_title,
                                                              p.post_excerpt,
                                                              p.post_status,
                                                              p.post_parent,
                                                              p.menu_order,
                                                              DATE_FORMAT(p.post_date,'%d-%m-%Y %h:%i') AS post_date,
                                                              GROUP_CONCAT(pm.meta_key order by pm.meta_id SEPARATOR '###') AS coupon_meta_key,
                                                              GROUP_CONCAT(pm.meta_value order by pm.meta_id SEPARATOR '###') AS coupon_meta_value
                                                              FROM {$wpdb->prefix}posts as p JOIN {$wpdb->prefix}postmeta as pm ON (p.ID = pm.post_id 
                                                              AND pm.meta_key IN ('discount_type','coupon_amount','individual_use','product_ids','exclude_product_ids','usage_limit','expiry_date','apply_before_tax','fres_shipping','product_categories','exclude_product_categories','minimum_amount','customer_email') )
                                                              WHERE p.ID IN (" . implode(',', $post_ids ) . ")
                                                              GROUP BY p.id  ORDER BY p.id

                                                        ";
                                                              
                                $results = $wpdb->get_results ( $query_to_fetch_data, ARRAY_A );
                             
                                foreach($results as $result){

                                    $coupon_meta_key = explode('###', $result['coupon_meta_key']);
                                    $coupon_meta_value =  explode('###', $result['coupon_meta_value']) ;

                                    unset($result['coupon_meta_key']);
                                    unset($result['coupon_meta_value']);

                                    $coupon_meta_key_value = array_combine($coupon_meta_key,$coupon_meta_value);

                                    $coupon_data = array_merge($result,$coupon_meta_key_value);

                                    foreach($coupon_data as $key => $value){

                                        $id = $coupon_data['ID'];
                                        if($key != "ID"){
                                            $data[$id][$key] = (is_serialized($value)) ? implode(',',maybe_unserialize($value) ) : $value;
                                            
                                        }
                                     }
                                 }
                            
                           }
                           
                           return $data;
                           
                        }
                        
                        //
                        function export_coupon_csv( $columns_header, $data ){
                            
                            $getfield = '';
                            
                            foreach ( $columns_header as $key => $value ) {
                                    $getfield .= $key . ',';
                            }

                            $fields = substr_replace($getfield, '', -1);
                            
                            $each_field = array_keys( $columns_header );
                            
                            $csv_file_name = get_bloginfo( 'name' ) . gmdate('d-M-Y_H_i_s') . ".csv";

                            foreach( (array) $data as $row ){
                                    for($i = 0; $i < count ( $columns_header ); $i++){
                                            if($i == 0) $fields .= "\n";
                                            
                                                if( array_key_exists($each_field[$i], $row) ){
                                                    $row_each_field = $row[$each_field[$i]];
                                                } else {
                                                    $row_each_field = '';
                                                }
                                            
                                            $array = str_replace(array("\n", "\n\r", "\r\n", "\r"), "\t", $row_each_field);
                                            
                                            $array = str_getcsv ( $array , ",", "\"" , "\\");
                                            
                                            $str = ( $array && is_array( $array ) ) ? implode( ', ', $array ) : '';
                                            $fields .= '"'. $str . '",'; 
                                    }           
                                    $fields = substr_replace($fields, '', -1); 
                            }
                            $upload_dir = wp_upload_dir();
                            
                            $file_data = array();
                            $file_data['wp_upload_dir'] = $upload_dir['path'] . '/';
                            $file_data['file_name'] = $csv_file_name;
                            $file_data['file_content'] = $fields;
                            
                            return $file_data;
                        }
                        
                        
                        //
                        function export_coupon( $post, $get, $post_ids ){
                            
                                $column_headers = array(
                                                            'post_title' => __('Coupon Code','wc_smart_coupons'),
                                                            'post_excerpt' => __('Post Excerpt','wc_smart_coupons'),
                                                            'post_status' => __('Post status','wc_smart_coupons'),
                                                            'post_parent' => __('post parent','wc_smart_coupons'),
                                                            'menu_order' => __('menu order','wc_smart_coupons'),
                                                            'post_date' => __('post_date', 'wc_smart_coupons'),
                                                            'discount_type' => __('Discount Type','wc_smart_coupons'),
                                                            'coupon_amount' => __('Coupon Amount','wc_smart_coupons'),
                                                            'individual_use' => __('Individual USe','wc_smart_coupons'),
                                                            'product_ids' => __('Product IDs','wc_smart_coupons'),
                                                            'exclude_product_ids' => __('Exclude product IDs','wc_smart_coupons'),
                                                            'usage_limit' => __('Usage Limit','wc_smart_coupons'),
                                                            'expiry_date' => __('Expiry date','wc_smart_coupons'),
                                                            'apply_before_tax' => __('Apply before tax','wc_smart_coupons'),
                                                            'free_shipping' => __('Free shipping','wc_smart_coupons'),
                                                            'product_categories' => __('Product categories','wc_smart_coupons'),
                                                            'exclude_product_categories' => __('Exclude Product categories','wc_smart_coupons'),
                                                            'minimum_amount' => __('Minimum Amount','wc_smart_coupons'),
                                                            'customer_email' => __('customer_email','wc_smart_coupons')

                                        );
                            
                                
                                if(!empty($post)){
                                    $data = $this->generate_coupons_code( $post, '', '' );
                                } else if(!empty($get)){
                                    $data = $this->generate_coupons_code( '', $get, $post_ids );
                                }
                                       
                                $file_data = $this->export_coupon_csv( $column_headers, $data );
                             
                                if( ( isset($post['generate_and_import']) && isset($post['sc_export_and_import']) ) || isset($get['export_coupons'])){
                                    
                                        ob_clean();
                                        header("Content-type: text/x-csv; charset=UTF-8"); 
                                        header("Content-Transfer-Encoding: binary");
                                        header("Content-Disposition: attachment; filename=".$file_data['file_name']); 
                                        header("Pragma: no-cache");
                                        header("Expires: 0");

                                        echo $file_data['file_content'];
                                        exit;
                                } else {
                                    
//                                      Create CSV file
                                        $csv_folder     = $file_data['wp_upload_dir'];
                                        $filename       = str_replace( array( '\'', '"', ',' , ';', '<', '>','/',':' ), '', $file_data['file_name'] );
                                        $CSVFileName    = $csv_folder.$filename;
                                        $fp = fopen($CSVFileName, 'w');
                                        file_put_contents($CSVFileName, $file_data['file_content']);
                                        fclose($fp);

                                        return $CSVFileName;
                                }
                                
                        }
                        
                        
                        // Funtion to perform importing of coupon from csv file
                        function coupon_importer(){

                                if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) return;

                                // Load Importer API
                                require_once ABSPATH . 'wp-admin/includes/import.php';

                                if ( ! class_exists( 'WP_Importer' ) ) {

                                        $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';

                                        if ( file_exists( $class_wp_importer ) ){
                                            require $class_wp_importer;

                                        }
                                }

                                // includes
                                require dirname(__FILE__) . '/classes/class-wc-csv-coupon-import.php' ;
                                require dirname(__FILE__) . '/classes/class-wc-coupon-parser.php' ;

                                $wc_csv_coupon_import = new WC_CSV_Coupon_Import();

                                $wc_csv_coupon_import->dispatch();

                        }

                        // function to display the coupon data meta box.
                        function woocommerce_smart_coupon_options(){
                                global $woocommerce, $post;

                                ?>
                                    <script type="text/javascript">
                                        jQuery(function(){
                                            var showHideApplyBeforeTax = function() {
                                                if ( jQuery('select#discount_type').val() == 'smart_coupon' ) {
                                                    jQuery('p.apply_before_tax_field').hide();
                                                    jQuery('div.pick_price_of_product').show();
                                                    jQuery('input#auto_generate_coupon').attr('checked', 'checked');
                                                    jQuery('div#for_prefix_sufix').show();
                                                    jQuery('input#sc_disable_email_restriction').removeAttr('checked');
                                                    jQuery('div#sc_disable_email_restriction_row').hide();
                                                } else {
                                                    jQuery('p.apply_before_tax_field').show();
                                                    jQuery('div.pick_price_of_product').hide();
                                                    jQuery('div#sc_disable_email_restriction_row').show();
                                                }
                                            };

                                            jQuery(document).ready(function(){
                                                showHideApplyBeforeTax();
                                                var showHidePrefixSuffix = function() {
                                                    if (jQuery("#auto_generate_coupon").is(":checked")){
                                                            //show the hidden div
                                                            jQuery("#for_prefix_sufix").show("fast");
                                                            jQuery("input#sc_disable_email_restriction").removeAttr("checked");
                                                            jQuery("div#sc_disable_email_restriction_row").hide();
                                                    } else {
                                                            //otherwise, hide it
                                                            jQuery("#for_prefix_sufix").hide("fast");
                                                            jQuery("div#sc_disable_email_restriction_row").show();
                                                    }
                                                }

                                                jQuery("#auto_generate_coupon").click(function(){
                                                    showHidePrefixSuffix();
                                                });
                                            });

                                            jQuery('select#discount_type').change(function(){
                                                showHideApplyBeforeTax();
                                            });
                                        });
                                    </script>
                                    <div id="coupon_options" class="panel woocommerce_options_panel">
                                        <div class="options_group pick_price_of_product">
                                            <?php woocommerce_wp_checkbox( array( 'id' => 'is_pick_price_of_product', 'label' => __('Pick Product\'s Price?', 'wc_smart_coupons'), 'description' => __('Check this box to allow overwriting coupon\'s amount with Product\'s Price.', 'wc_smart_coupons') ) ); ?>
                                        </div>
                                    </div>
                                    <div id="coupon_options" class="panel woocommerce_options_panel">

                                        <?php
                                            echo '<div class="options_group">';

                                            echo '<div id="sc_disable_email_restriction_row">';
                                            // for disabling e-mail restriction
                                            woocommerce_wp_checkbox( array( 'id' => 'sc_disable_email_restriction', 'label' => __( 'Disable Email restriction?', 'wc_smart_coupons' ), 'description' => __('When checked, no e-mail id will be added through Smart Coupons plugin.', 'wc_smart_coupons') ) );
                                            echo '</div>';

                                            // autogeneration of coupon for store credit/gift certificate
                                            woocommerce_wp_checkbox( array( 'id' => 'auto_generate_coupon', 'label' => __('Auto Generation of Coupon', 'wc_smart_coupons'), 'description' => __('Check this box if the coupon needs to be auto generated', 'wc_smart_coupons') ) );

                                            echo '<div id="for_prefix_sufix">';
                                            // text field for coupon prefix
                                            woocommerce_wp_text_input( array( 'id' => 'coupon_title_prefix', 'label' => __('Prefix for Coupon Title', 'wc_smart_coupons'), 'placeholder' => _x('Prefix', 'placeholder', 'wc_smart_coupons'), 'description' => __('Adding prefix to the coupon title', 'wc_smart_coupons') ) );

                                            // text field for coupon suffix
                                            woocommerce_wp_text_input( array( 'id' => 'coupon_title_suffix', 'label' => __('Suffix for Coupon Title', 'wc_smart_coupons'), 'placeholder' => _x('Suffix', 'placeholder', 'wc_smart_coupons'), 'description' => __('Adding suffix to the coupon title', 'wc_smart_coupons') ) );

                                            echo '</div>';
                                            
                                            ?>
                                                <p class="form-field sc_coupon_validity ">
                                                    <label for="sc_coupon_validity"><?php _e('Valid for', 'wc_smart_coupons'); ?></label>
                                                    <input type="number" class="short" name="sc_coupon_validity" id="sc_coupon_validity" value="<?php echo get_post_meta( $post->ID, 'sc_coupon_validity', true ); ?>" placeholder="0">
                                                    <select name="validity_suffix">
                                                        <option value="days" <?php echo ( ( get_post_meta( $post->ID, 'validity_suffix', true ) == 'days' ) ? 'selected="selected"' : '' ); ?>><?php _e( 'Days', 'wc_smart_coupons' ); ?></option>
                                                        <option value="weeks" <?php echo ( ( get_post_meta( $post->ID, 'validity_suffix', true ) == 'weeks' ) ? 'selected="selected"' : '' ); ?>><?php _e( 'Weeks', 'wc_smart_coupons' ); ?></option>
                                                        <option value="months" <?php echo ( ( get_post_meta( $post->ID, 'validity_suffix', true ) == 'months' ) ? 'selected="selected"' : '' ); ?>><?php _e( 'Months', 'wc_smart_coupons' ); ?></option>
                                                        <option value="years" <?php echo ( ( get_post_meta( $post->ID, 'validity_suffix', true ) == 'years' ) ? 'selected="selected"' : '' ); ?>><?php _e( 'Years', 'wc_smart_coupons' ); ?></option>
                                                    </select>
                                                </p>
                                            <?php

                                            echo '</div>';
                                       ?>

                                    </div>

                                <?php

                        }

                        // Function to save the coupon data meta box.
                        function woocommerce_process_smart_coupon_meta( $post_id, $post ){
                                if ( empty($post_id) || empty($post) || empty($_POST) ) return;
                                if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
                                if ( is_int( wp_is_post_revision( $post ) ) ) return;
                                if ( is_int( wp_is_post_autosave( $post ) ) ) return;
                                if ( empty($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' )) return;
                                if ( !current_user_can( 'edit_post', $post_id )) return;
                                if ( $post->post_type != 'shop_coupon' ) return;

                                if ( isset( $_POST['auto_generate_coupon'] ) ) {
                                    update_post_meta( $post_id, 'auto_generate_coupon', $_POST['auto_generate_coupon'] );
                                } else {
                                    if ( get_post_meta( $post_id, 'discount_type', true ) == 'smart_coupon' ) {
                                        update_post_meta( $post_id, 'auto_generate_coupon', 'yes' );
                                    } else {
                                        update_post_meta( $post_id, 'auto_generate_coupon', 'no' );
                                    }
                                }

                                if ( get_post_meta( $post_id, 'discount_type', true ) == 'smart_coupon' ) {
                                    update_post_meta( $post_id, 'apply_before_tax', 'no' );
                                }

                                if ( isset( $_POST['coupon_title_prefix'] ) ) {
                                    update_post_meta( $post_id, 'coupon_title_prefix', $_POST['coupon_title_prefix'] );
                                }

                                if ( isset( $_POST['coupon_title_suffix'] ) ) {
                                    update_post_meta( $post_id, 'coupon_title_suffix', $_POST['coupon_title_suffix'] );
                                }

                                if ( isset( $_POST['sc_coupon_validity'] ) ) {
                                    update_post_meta( $post_id, 'sc_coupon_validity', $_POST['sc_coupon_validity'] );
                                    update_post_meta( $post_id, 'validity_suffix', $_POST['validity_suffix'] );
                                }

                                if ( isset( $_POST['sc_disable_email_restriction'] ) ) {
                                    update_post_meta( $post_id, 'sc_disable_email_restriction', $_POST['sc_disable_email_restriction'] );
                                } else {
                                    update_post_meta( $post_id, 'sc_disable_email_restriction', 'no' );
                                }

                                if ( isset( $_POST['is_pick_price_of_product'] ) ) {
                                    update_post_meta( $post_id, 'is_pick_price_of_product', $_POST['is_pick_price_of_product'] );
                                } else {
                                    update_post_meta( $post_id, 'is_pick_price_of_product', 'no' );
                                }

                        }

        }// End of class WC_Smart_Coupons

        $GLOBALS['woocommerce_smart_coupon'] = new WC_Smart_Coupons();

    } // End class exists check

} // End woocommerce active check
