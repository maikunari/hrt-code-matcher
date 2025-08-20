<?php
/**
 * Plugin Name: HTS Code Display for WooCommerce
 * Plugin URI: https://yoursite.com
 * Description: Securely displays and manages HTS codes in WooCommerce with ShipStation integration support
 * Version: 2.0.0
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

// Define plugin constants
define('HTS_DISPLAY_VERSION', '2.0.0');
define('HTS_DISPLAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HTS_DISPLAY_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, 'hts_display_activate');
function hts_display_activate() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires WooCommerce to be installed and active.', 'hts-display'));
    }
    
    // Set default options if needed
    add_option('hts_display_version', HTS_DISPLAY_VERSION);
}

// ===========================
// 1. ADD TAB TO PRODUCT DATA
// ===========================

add_filter('woocommerce_product_data_tabs', 'add_hts_product_data_tab');
function add_hts_product_data_tab($tabs) {
    // Check user capabilities
    if (!current_user_can('edit_products')) {
        return $tabs;
    }
    
    $tabs['hts_codes'] = array(
        'label'    => __('HTS Codes', 'hts-display'),
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
    
    // Security check
    if (!current_user_can('edit_product', $post->ID)) {
        return;
    }
    
    $hts_code = get_post_meta($post->ID, '_hts_code', true);
    $hts_confidence = get_post_meta($post->ID, '_hts_confidence', true);
    $hts_updated = get_post_meta($post->ID, '_hts_updated', true);
    
    // Add nonce field for security
    wp_nonce_field('hts_product_data_nonce_action', 'hts_product_data_nonce');
    
    ?>
    <div id="hts_code_product_data" class="panel woocommerce_options_panel">
        <div class="options_group">
            
            <?php if ($hts_code): ?>
                <div style="padding: 10px; margin: 10px; background: #f0f8ff; border-left: 4px solid #0073aa;">
                    <strong>✓ <?php esc_html_e('HTS Code Assigned by AI', 'hts-display'); ?></strong><br>
                    <?php 
                    echo esc_html__('Last updated: ', 'hts-display');
                    echo $hts_updated ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($hts_updated))) : esc_html__('Unknown', 'hts-display'); 
                    ?>
                </div>
            <?php else: ?>
                <div style="padding: 10px; margin: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
                    <strong>⚠ <?php esc_html_e('No HTS Code Assigned', 'hts-display'); ?></strong><br>
                    <?php esc_html_e('Run the HTS Matcher to classify this product', 'hts-display'); ?>
                </div>
            <?php endif; ?>
            
            <?php
            // HTS Code field with pattern validation
            woocommerce_wp_text_input(array(
                'id'          => '_hts_code',
                'label'       => __('HTS Code', 'hts-display'),
                'placeholder' => '####.##.####',
                'desc_tip'    => true,
                'description' => __('10-digit Harmonized Tariff Schedule code for US customs (format: ####.##.####)', 'hts-display'),
                'value'       => $hts_code,
                'custom_attributes' => array(
                    'pattern' => '[0-9]{4}\.[0-9]{2}\.[0-9]{4}',
                    'title'   => __('Format: ####.##.#### (e.g., 6109.10.0012)', 'hts-display'),
                ),
            ));
            
            // Confidence field (read-only)
            woocommerce_wp_text_input(array(
                'id'          => '_hts_confidence_display',
                'label'       => __('AI Confidence', 'hts-display'),
                'desc_tip'    => true,
                'description' => __('How confident the AI was in this classification', 'hts-display'),
                'value'       => $hts_confidence,
                'custom_attributes' => array('readonly' => 'readonly'),
                'style'       => 'background-color: #f5f5f5;'
            ));
            
            // Country of Origin - using WooCommerce countries
            $countries = WC()->countries->get_countries();
            $current_value = get_post_meta($post->ID, '_country_of_origin', true);
            // Default to Canada if no value is set
            if (empty($current_value)) {
                $current_value = 'CA';
            }
            
            woocommerce_wp_select(array(
                'id'          => '_country_of_origin',
                'label'       => __('Country of Origin', 'hts-display'),
                'options'     => array('' => __('Select country', 'hts-display')) + $countries,
                'desc_tip'    => true,
                'description' => __('Where this product is manufactured (defaults to Canada)', 'hts-display'),
                'value'       => $current_value,
            ));
            ?>
            
            <?php if ($hts_code && hts_validate_code_format($hts_code)): ?>
                <p class="form-field">
                    <label><?php esc_html_e('Quick References:', 'hts-display'); ?></label>
                    <a href="<?php echo esc_url('https://hts.usitc.gov/?query=' . urlencode($hts_code)); ?>" 
                       target="_blank" 
                       rel="noopener noreferrer" 
                       class="button button-small">
                        <?php esc_html_e('Look up HTS Code ↗', 'hts-display'); ?>
                    </a>
                    <a href="<?php echo esc_url('https://www.dutycalculator.com/hs-code-duty-calculator/'); ?>" 
                       target="_blank" 
                       rel="noopener noreferrer" 
                       class="button button-small">
                        <?php esc_html_e('Calculate Duties ↗', 'hts-display'); ?>
                    </a>
                </p>
            <?php endif; ?>
            
        </div>
    </div>
    <?php
}

// ===========================
// 3. SAVE THE FIELDS (SECURE)
// ===========================

add_action('woocommerce_process_product_meta', 'save_hts_product_data_fields');
function save_hts_product_data_fields($post_id) {
    // Verify nonce
    if (!isset($_POST['hts_product_data_nonce']) || !wp_verify_nonce($_POST['hts_product_data_nonce'], 'hts_product_data_nonce_action')) {
        return;
    }
    
    // Check user capabilities
    if (!current_user_can('edit_product', $post_id)) {
        return;
    }
    
    // Check autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Save HTS Code with validation
    if (isset($_POST['_hts_code'])) {
        $hts_code = sanitize_text_field($_POST['_hts_code']);
        
        // Validate HTS code format
        if (empty($hts_code) || hts_validate_code_format($hts_code)) {
            update_post_meta($post_id, '_hts_code', $hts_code);
            
            // Update timestamp if code changed
            $old_code = get_post_meta($post_id, '_hts_code', true);
            if ($old_code !== $hts_code && !empty($hts_code)) {
                update_post_meta($post_id, '_hts_updated', current_time('mysql'));
                // Clear confidence if manually edited
                if (!isset($_POST['_hts_confidence'])) {
                    update_post_meta($post_id, '_hts_confidence', 'Manual Entry');
                }
            }
        }
    }
    
    // Save Country of Origin
    if (isset($_POST['_country_of_origin'])) {
        $country = sanitize_text_field($_POST['_country_of_origin']);
        // Validate against WooCommerce countries
        $valid_countries = WC()->countries->get_countries();
        if (empty($country) || array_key_exists($country, $valid_countries)) {
            update_post_meta($post_id, '_country_of_origin', $country);
        }
    }
}

/**
 * Validate HTS code format
 */
function hts_validate_code_format($code) {
    // HTS codes should be ####.##.#### format
    return preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $code);
}

// ===========================
// 4. ADD COLUMN TO PRODUCTS LIST
// ===========================

// Add column header
add_filter('manage_edit-product_columns', 'add_hts_column');
function add_hts_column($columns) {
    // Check user capabilities
    if (!current_user_can('edit_products')) {
        return $columns;
    }
    
    $columns['hts_code'] = __('HTS Code', 'hts-display');
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
                $confidence_display = is_numeric($confidence) ? number_format((float)$confidence * 100, 0) . '%' : esc_html($confidence);
                echo '<br><small style="color: #666;">(' . $confidence_display . ')</small>';
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

// Handle sorting
add_action('pre_get_posts', 'hts_column_orderby');
function hts_column_orderby($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    if ($query->get('orderby') == 'hts_code') {
        $query->set('meta_key', '_hts_code');
        $query->set('orderby', 'meta_value');
    }
}

// ===========================
// 5. SHOW IN ORDER DETAILS
// ===========================

add_action('woocommerce_order_item_meta_end', 'display_hts_in_order', 10, 4);
function display_hts_in_order($item_id, $item, $order, $plain_text) {
    // Check if user can view orders
    if (!current_user_can('view_woocommerce_reports') && !current_user_can('manage_woocommerce')) {
        return;
    }
    
    $product = $item->get_product();
    if ($product) {
        $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
        $country_of_origin = get_post_meta($product->get_id(), '_country_of_origin', true);
        
        if ($hts_code) {
            if ($plain_text) {
                echo "\nHTS Code: " . $hts_code;
            } else {
                echo '<br><small><strong>' . esc_html__('HTS Code:', 'hts-display') . '</strong> ' . esc_html($hts_code) . '</small>';
            }
        }
        
        if ($country_of_origin) {
            $countries = WC()->countries->get_countries();
            $country_name = isset($countries[$country_of_origin]) ? $countries[$country_of_origin] : $country_of_origin;
            
            if ($plain_text) {
                echo "\nCountry of Origin: " . $country_name;
            } else {
                echo '<br><small><strong>' . esc_html__('Origin:', 'hts-display') . '</strong> ' . esc_html($country_name) . '</small>';
            }
        }
    }
}

// ===========================
// 6. ADD TO REST API (for integrations)
// ===========================

add_action('rest_api_init', 'register_hts_rest_fields');
function register_hts_rest_fields() {
    // HTS Code field
    register_rest_field('product', 'hts_code', array(
        'get_callback'    => function($product) {
            // Check permissions
            if (!current_user_can('read')) {
                return null;
            }
            return get_post_meta($product['id'], '_hts_code', true);
        },
        'update_callback' => function($value, $product) {
            // Check permissions
            if (!current_user_can('edit_product', $product->ID)) {
                return false;
            }
            
            // Validate format
            if (!empty($value) && !hts_validate_code_format($value)) {
                return new WP_Error('invalid_hts_format', __('Invalid HTS code format. Use ####.##.####', 'hts-display'));
            }
            
            update_post_meta($product->ID, '_hts_code', sanitize_text_field($value));
            update_post_meta($product->ID, '_hts_updated', current_time('mysql'));
            return true;
        },
        'schema' => array(
            'description' => __('HTS Code for customs', 'hts-display'),
            'type'        => 'string',
            'pattern'     => '^\d{4}\.\d{2}\.\d{4}$',
        ),
    ));
    
    // HTS Confidence field (read-only)
    register_rest_field('product', 'hts_confidence', array(
        'get_callback'    => function($product) {
            if (!current_user_can('read')) {
                return null;
            }
            return get_post_meta($product['id'], '_hts_confidence', true);
        },
        'update_callback' => null, // Read-only
        'schema' => array(
            'description' => __('AI confidence level', 'hts-display'),
            'type'        => 'string',
            'readonly'    => true,
        ),
    ));
    
    // Country of Origin field
    register_rest_field('product', 'country_of_origin', array(
        'get_callback'    => function($product) {
            if (!current_user_can('read')) {
                return null;
            }
            $country = get_post_meta($product['id'], '_country_of_origin', true);
            return $country ?: 'CA'; // Default to Canada
        },
        'update_callback' => function($value, $product) {
            if (!current_user_can('edit_product', $product->ID)) {
                return false;
            }
            
            // Validate against WooCommerce countries
            $valid_countries = WC()->countries->get_countries();
            if (!empty($value) && !array_key_exists($value, $valid_countries)) {
                return new WP_Error('invalid_country', __('Invalid country code', 'hts-display'));
            }
            
            update_post_meta($product->ID, '_country_of_origin', sanitize_text_field($value));
            return true;
        },
        'schema' => array(
            'description' => __('Country of Origin', 'hts-display'),
            'type'        => 'string',
        ),
    ));
}

// ===========================
// 7. BULK ACTIONS
// ===========================

add_filter('bulk_actions-edit-product', 'add_hts_bulk_actions');
function add_hts_bulk_actions($bulk_actions) {
    // Check capabilities
    if (!current_user_can('edit_products')) {
        return $bulk_actions;
    }
    
    $bulk_actions['export_hts'] = __('Export HTS Codes', 'hts-display');
    return $bulk_actions;
}

// Handle bulk actions
add_filter('handle_bulk_actions-edit-product', 'handle_hts_bulk_actions', 10, 3);
function handle_hts_bulk_actions($redirect_to, $action, $post_ids) {
    if (!current_user_can('edit_products')) {
        return $redirect_to;
    }
    
    if ($action === 'export_hts') {
        // Export logic here
        $redirect_to = add_query_arg('hts_exported', count($post_ids), $redirect_to);
    }
    
    return $redirect_to;
}

// Show admin notices for bulk actions
add_action('admin_notices', 'hts_bulk_action_notices');
function hts_bulk_action_notices() {
    if (!empty($_REQUEST['hts_exported'])) {
        $count = intval($_REQUEST['hts_exported']);
        printf(
            '<div class="notice notice-success is-dismissible"><p>' . 
            esc_html(_n('Exported HTS codes for %s product.', 'Exported HTS codes for %s products.', $count, 'hts-display')) . 
            '</p></div>',
            $count
        );
    }
}

// ===========================
// 8. ADMIN DASHBOARD WIDGET
// ===========================

add_action('wp_dashboard_setup', 'add_hts_dashboard_widget');
function add_hts_dashboard_widget() {
    if (current_user_can('manage_woocommerce')) {
        wp_add_dashboard_widget(
            'hts_status_widget',
            __('HTS Code Status', 'hts-display'),
            'hts_dashboard_widget_display'
        );
    }
}

function hts_dashboard_widget_display() {
    global $wpdb;
    
    // Get statistics using prepared statement
    $total_products = wp_count_posts('product')->publish;
    
    $products_with_hts = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = %s 
             AND meta_value != ''",
            '_hts_code'
        )
    );
    
    $products_without = $total_products - $products_with_hts;
    $percentage = $total_products > 0 ? round(($products_with_hts / $total_products) * 100, 1) : 0;
    
    ?>
    <div class="hts-dashboard-widget">
        <p>
            <strong><?php esc_html_e('Coverage:', 'hts-display'); ?></strong> 
            <?php echo esc_html($percentage); ?>%
        </p>
        <p>
            <?php 
            printf(
                esc_html__('%1$s of %2$s products have HTS codes', 'hts-display'),
                '<strong>' . esc_html($products_with_hts) . '</strong>',
                '<strong>' . esc_html($total_products) . '</strong>'
            );
            ?>
        </p>
        <?php if ($products_without > 0): ?>
            <p style="color: #d63638;">
                <?php 
                printf(
                    esc_html(_n('%s product needs classification', '%s products need classification', $products_without, 'hts-display')),
                    '<strong>' . esc_html($products_without) . '</strong>'
                );
                ?>
            </p>
        <?php else: ?>
            <p style="color: #00a32a;">
                <strong>✓ <?php esc_html_e('All products have HTS codes!', 'hts-display'); ?></strong>
            </p>
        <?php endif; ?>
        
        <p class="hts-actions">
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>" class="button">
                <?php esc_html_e('View Products', 'hts-display'); ?>
            </a>
        </p>
    </div>
    
    <style>
        .hts-dashboard-widget p {
            margin: 10px 0;
        }
        .hts-dashboard-widget .hts-actions {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
    </style>
    <?php
}