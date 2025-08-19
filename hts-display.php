<?php
/**
 * Plugin Name: HTS Code Display for WooCommerce
 * Plugin URI: https://yoursite.com
 * Description: Displays HTS codes in product admin pages, order details, and products list. Integrates with HTS Code Matcher.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * License: GPL v2 or later
 * Text Domain: hts-display
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ===========================
// 1. ADD TAB TO PRODUCT DATA
// ===========================

add_filter('woocommerce_product_data_tabs', 'add_hts_product_data_tab');
function add_hts_product_data_tab($tabs) {
    $tabs['hts_codes'] = array(
        'label'    => __('HTS Codes', 'woocommerce'),
        'priority' => 21,
        'target'   => 'hts_code_product_data',
        'class'    => array('show_if_simple', 'show_if_variable', 'show_if_grouped', 'show_if_external'),
    );
    return $tabs;
}

// ===========================
// 2. ADD FIELDS TO THE TAB
// ===========================

add_action('woocommerce_product_data_panels', 'add_hts_product_data_fields');
function add_hts_product_data_fields() {
    global $post;
    
    $hts_code = get_post_meta($post->ID, '_hts_code', true);
    $hts_confidence = get_post_meta($post->ID, '_hts_confidence', true);
    $hts_updated = get_post_meta($post->ID, '_hts_updated', true);
    
    ?>
    <div id="hts_code_product_data" class="panel woocommerce_options_panel">
        <div class="options_group">
            
            <?php if ($hts_code): ?>
                <div style="padding: 10px; margin: 10px; background: #f0f8ff; border-left: 4px solid #0073aa;">
                    <strong>✓ HTS Code Assigned by AI</strong><br>
                    Last updated: <?php echo $hts_updated ? date('F j, Y g:i a', strtotime($hts_updated)) : 'Unknown'; ?>
                </div>
            <?php else: ?>
                <div style="padding: 10px; margin: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
                    <strong>⚠ No HTS Code Assigned</strong><br>
                    Run the HTS Matcher to classify this product
                </div>
            <?php endif; ?>
            
            <?php
            // HTS Code field
            woocommerce_wp_text_input(array(
                'id'          => '_hts_code',
                'label'       => __('HTS Code', 'woocommerce'),
                'placeholder' => '####.##.####',
                'desc_tip'    => true,
                'description' => __('10-digit Harmonized Tariff Schedule code for US customs', 'woocommerce'),
                'value'       => $hts_code,
            ));
            
            // Confidence field (read-only)
            woocommerce_wp_text_input(array(
                'id'          => '_hts_confidence',
                'label'       => __('AI Confidence', 'woocommerce'),
                'desc_tip'    => true,
                'description' => __('How confident the AI was in this classification', 'woocommerce'),
                'value'       => $hts_confidence,
                'custom_attributes' => array('readonly' => 'readonly'),
                'style'       => 'background-color: #f5f5f5;'
            ));
            
            // Country of Origin (optional - you can add this)
            woocommerce_wp_select(array(
                'id'          => '_country_of_origin',
                'label'       => __('Country of Origin', 'woocommerce'),
                'options'     => array(
                    ''   => __('Select country', 'woocommerce'),
                    'CA' => 'Canada',
                    'CN' => 'China',
                    'MX' => 'Mexico',
                    'US' => 'United States',
                    'VN' => 'Vietnam',
                    'TW' => 'Taiwan',
                    'KR' => 'South Korea',
                    'JP' => 'Japan',
                    'DE' => 'Germany',
                    'IT' => 'Italy',
                    // Add more as needed
                ),
                'desc_tip'    => true,
                'description' => __('Where this product is manufactured', 'woocommerce'),
                'value'       => get_post_meta($post->ID, '_country_of_origin', true),
            ));
            ?>
            
            <?php if ($hts_code): ?>
                <p class="form-field">
                    <label>Quick References:</label>
                    <a href="https://hts.usitc.gov/?query=<?php echo urlencode($hts_code); ?>" 
                       target="_blank" class="button button-small">
                        Look up HTS Code ↗
                    </a>
                    <a href="https://www.dutycalculator.com/hs-code-duty-calculator/" 
                       target="_blank" class="button button-small">
                        Calculate Duties ↗
                    </a>
                </p>
            <?php endif; ?>
            
        </div>
    </div>
    <?php
}

// ===========================
// 3. SAVE THE FIELDS
// ===========================

add_action('woocommerce_process_product_meta', 'save_hts_product_data_fields');
function save_hts_product_data_fields($post_id) {
    $hts_code = $_POST['_hts_code'];
    if (!empty($hts_code)) {
        update_post_meta($post_id, '_hts_code', esc_attr($hts_code));
    }
    
    $country_of_origin = $_POST['_country_of_origin'];
    if (!empty($country_of_origin)) {
        update_post_meta($post_id, '_country_of_origin', esc_attr($country_of_origin));
    }
}

// ===========================
// 4. ADD COLUMN TO PRODUCTS LIST
// ===========================

// Add column header
add_filter('manage_edit-product_columns', 'add_hts_column');
function add_hts_column($columns) {
    $columns['hts_code'] = __('HTS Code', 'woocommerce');
    return $columns;
}

// Add column content
add_action('manage_product_posts_custom_column', 'show_hts_column', 10, 2);
function show_hts_column($column, $post_id) {
    if ($column == 'hts_code') {
        $hts_code = get_post_meta($post_id, '_hts_code', true);
        $confidence = get_post_meta($post_id, '_hts_confidence', true);
        
        if ($hts_code) {
            echo '<strong>' . esc_html($hts_code) . '</strong>';
            if ($confidence) {
                echo '<br><small style="color: #666;">(' . esc_html($confidence) . ')</small>';
            }
        } else {
            echo '<span style="color: #999;">—</span>';
        }
    }
}

// Make column sortable
add_filter('manage_edit-product_sortable_columns', 'make_hts_column_sortable');
function make_hts_column_sortable($columns) {
    $columns['hts_code'] = 'hts_code';
    return $columns;
}

// ===========================
// 5. SHOW IN ORDER DETAILS
// ===========================

add_action('woocommerce_order_item_meta_end', 'display_hts_in_order', 10, 4);
function display_hts_in_order($item_id, $item, $order, $plain_text) {
    $product = $item->get_product();
    if ($product) {
        $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
        if ($hts_code) {
            echo '<br><small><strong>HTS Code:</strong> ' . esc_html($hts_code) . '</small>';
        }
    }
}

// ===========================
// 6. ADD TO REST API (for integrations)
// ===========================

add_action('rest_api_init', 'register_hts_rest_fields');
function register_hts_rest_fields() {
    register_rest_field('product', 'hts_code', array(
        'get_callback'    => function($product) {
            return get_post_meta($product['id'], '_hts_code', true);
        },
        'update_callback' => null,
        'schema'          => null,
    ));
    
    register_rest_field('product', 'hts_confidence', array(
        'get_callback'    => function($product) {
            return get_post_meta($product['id'], '_hts_confidence', true);
        },
        'update_callback' => null,
        'schema'          => null,
    ));
}

// ===========================
// 7. BULK ACTIONS (Optional)
// ===========================

add_filter('bulk_actions-edit-product', 'add_hts_bulk_actions');
function add_hts_bulk_actions($bulk_actions) {
    $bulk_actions['export_hts'] = __('Export HTS Codes', 'woocommerce');
    $bulk_actions['clear_hts'] = __('Clear HTS Codes', 'woocommerce');
    return $bulk_actions;
}

// ===========================
// 8. ADMIN NOTICE FOR PRODUCTS WITHOUT HTS
// ===========================

add_action('admin_notices', 'hts_admin_notice');
function hts_admin_notice() {
    $screen = get_current_screen();
    if ($screen->id == 'edit-product') {
        global $wpdb;
        
        // Count products without HTS codes
        $total_products = wp_count_posts('product')->publish;
        $products_with_hts = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_hts_code' 
            AND meta_value != ''
        ");
        
        $products_without = $total_products - $products_with_hts;
        
        if ($products_without > 0) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong>HTS Codes:</strong> 
                    <?php echo $products_with_hts; ?> products have HTS codes assigned. 
                    <?php echo $products_without; ?> products still need classification.
                    <a href="#" class="button button-small" style="margin-left: 10px;">Run HTS Matcher</a>
                </p>
            </div>
            <?php
        }
    }
}