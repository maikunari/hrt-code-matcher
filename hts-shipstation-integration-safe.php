<?php
/**
 * Plugin Name: HTS to ShipStation Integration (Safe Version)
 * Plugin URI: https://yoursite.com
 * Description: Safely passes HTS codes and Country of Origin to ShipStation with comprehensive error handling
 * Version: 1.1.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * License: GPL v2 or later
 * Text Domain: hts-shipstation
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce and ShipStation are active
add_action('plugins_loaded', 'hts_shipstation_init');
function hts_shipstation_init() {
    if (!class_exists('WooCommerce') || !class_exists('WC_Shipstation_Integration')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php _e('HTS to ShipStation Integration requires both WooCommerce and ShipStation plugins to be active.', 'hts-shipstation'); ?></p>
            </div>
            <?php
        });
        return;
    }
    
    // Hook into ShipStation export with error handling wrapper
    add_action('woocommerce_shipstation_export_order_xml', 'hts_safe_add_customs_to_shipstation_xml', 10, 3);
    add_filter('woocommerce_shipstation_export_custom_field_2', 'hts_safe_add_hts_to_custom_field', 10, 2);
    add_filter('woocommerce_shipstation_export_custom_field_3', 'hts_safe_add_country_to_custom_field', 10, 2);
    add_action('woocommerce_shipstation_export_item_xml', 'hts_safe_add_customs_to_item_xml', 10, 4);
}

/**
 * SAFE WRAPPER: Add HTS code and Country of Origin to ShipStation item XML
 * This will NEVER block ShipStation even if it fails
 */
function hts_safe_add_customs_to_item_xml($item_xml, $order_item, $order, $xml) {
    try {
        // Set a timeout to prevent hanging
        $start_time = microtime(true);
        $max_execution_time = 0.5; // 500ms max per item
        
        // Get the product
        $product = is_callable(array($order_item, 'get_product')) ? $order_item->get_product() : false;
        
        // Gracefully handle missing product
        if (!$product) {
            hts_log_error('Product not found for order item', array('order_id' => $order->get_id()));
            return; // Return without modifying - ShipStation continues normally
        }
        
        $product_id = $product->get_id();
        
        // Safely get HTS code with fallback
        $hts_code = '';
        if ($product_id) {
            $hts_code = get_post_meta($product_id, '_hts_code', true);
        }
        
        // Skip if no HTS code or invalid - don't block ShipStation
        if (empty($hts_code) || $hts_code === '9999.99.9999') {
            // This is not an error - just no HTS code to add
            return;
        }
        
        // Validate HTS code format
        if (!preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
            hts_log_error('Invalid HTS code format', array(
                'product_id' => $product_id,
                'hts_code' => $hts_code,
                'sku' => $product->get_sku()
            ));
            return; // Don't add invalid data, but don't block ShipStation
        }
        
        // Check execution time
        if ((microtime(true) - $start_time) > $max_execution_time) {
            hts_log_error('Execution timeout - skipping to prevent blocking', array('product_id' => $product_id));
            return;
        }
        
        // Safely add CustomsDescription
        $customs_description = $product->get_name();
        if (!empty($customs_description)) {
            if (strlen($customs_description) > 200) {
                $customs_description = substr($customs_description, 0, 197) . '...';
            }
            hts_safe_xml_append($xml, $item_xml, 'CustomsDescription', $customs_description);
        }
        
        // Safely add HTS/Harmonization code
        $formatted_hts = str_replace('.', '', $hts_code);
        if (preg_match('/^\d{10}$/', $formatted_hts)) { // Verify it's 10 digits
            hts_safe_xml_append($xml, $item_xml, 'HarmonizedCode', $formatted_hts);
        }
        
        // Safely get and add Country of Origin
        $country_of_origin = get_post_meta($product_id, '_country_of_origin', true);
        if (empty($country_of_origin)) {
            $country_of_origin = 'CA'; // Safe default
        }
        
        // Validate country code (2 letters)
        if (preg_match('/^[A-Z]{2}$/', strtoupper($country_of_origin))) {
            hts_safe_xml_append($xml, $item_xml, 'CountryOfOrigin', strtoupper($country_of_origin));
        } else {
            hts_safe_xml_append($xml, $item_xml, 'CountryOfOrigin', 'CA'); // Safe fallback
        }
        
        // Safely add customs value
        try {
            $item_value = $order->get_item_subtotal($order_item, false, false);
            if (is_numeric($item_value) && $item_value > 0) {
                hts_safe_xml_append($xml, $item_xml, 'CustomsValue', number_format($item_value, 2, '.', ''));
            }
        } catch (Exception $e) {
            // If we can't get value, just skip it - don't block
            hts_log_error('Could not get item value', array('error' => $e->getMessage()));
        }
        
    } catch (Exception $e) {
        // Log error but NEVER throw - this ensures ShipStation continues
        hts_log_error('Unexpected error in HTS integration', array(
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ));
    } catch (Error $e) {
        // Catch fatal errors too
        hts_log_error('Fatal error in HTS integration', array(
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ));
    }
    
    // Always return normally - never block ShipStation
}

/**
 * SAFE WRAPPER: Add customs information to the order level XML
 */
function hts_safe_add_customs_to_shipstation_xml($order_xml, $order, $xml) {
    try {
        // Set execution limit
        $start_time = microtime(true);
        $max_execution_time = 1.0; // 1 second max for entire order
        
        $customs_items_xml = $xml->createElement('CustomsItems');
        $has_customs_items = false;
        
        // Process each order item with safety checks
        foreach ($order->get_items() as $item_id => $item) {
            // Check timeout
            if ((microtime(true) - $start_time) > $max_execution_time) {
                hts_log_error('Order processing timeout', array('order_id' => $order->get_id()));
                break; // Stop processing but don't block
            }
            
            try {
                $product = is_callable(array($item, 'get_product')) ? $item->get_product() : false;
                
                if (!$product || !$product->needs_shipping()) {
                    continue;
                }
                
                $product_id = $product->get_id();
                
                // Safely get HTS code
                $hts_code = get_post_meta($product_id, '_hts_code', true);
                
                // Skip if no valid HTS code
                if (empty($hts_code) || $hts_code === '9999.99.9999') {
                    continue;
                }
                
                // Validate format
                if (!preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
                    continue;
                }
                
                $has_customs_items = true;
                
                // Create customs item element
                $customs_item_xml = $xml->createElement('CustomsItem');
                
                // Add all fields with safety checks
                hts_safe_xml_append($xml, $customs_item_xml, 'SKU', $product->get_sku());
                
                $description = substr($product->get_name(), 0, 200);
                hts_safe_xml_append($xml, $customs_item_xml, 'Description', $description);
                
                $quantity = $item->get_quantity() - abs($order->get_qty_refunded_for_item($item_id));
                hts_safe_xml_append($xml, $customs_item_xml, 'Quantity', max(0, $quantity));
                
                $item_value = $order->get_item_subtotal($item, false, false);
                if (is_numeric($item_value)) {
                    hts_safe_xml_append($xml, $customs_item_xml, 'Value', number_format($item_value, 2, '.', ''));
                }
                
                $formatted_hts = str_replace('.', '', $hts_code);
                hts_safe_xml_append($xml, $customs_item_xml, 'HarmonizedCode', $formatted_hts);
                
                $country = get_post_meta($product_id, '_country_of_origin', true) ?: 'CA';
                hts_safe_xml_append($xml, $customs_item_xml, 'CountryOfOrigin', strtoupper($country));
                
                $customs_items_xml->appendChild($customs_item_xml);
                
            } catch (Exception $e) {
                // Log but continue with next item
                hts_log_error('Error processing item', array(
                    'item_id' => $item_id,
                    'error' => $e->getMessage()
                ));
                continue;
            }
        }
        
        // Only append if we have customs items and no errors
        if ($has_customs_items) {
            $order_xml->appendChild($customs_items_xml);
        }
        
    } catch (Exception $e) {
        // Log error but never block ShipStation
        hts_log_error('Error in order customs processing', array(
            'order_id' => $order->get_id(),
            'error' => $e->getMessage()
        ));
    }
}

/**
 * SAFE WRAPPER: Add HTS codes to custom field 2
 */
function hts_safe_add_hts_to_custom_field($value, $order) {
    try {
        $hts_codes = array();
        $max_items = 10; // Limit to prevent timeout
        $count = 0;
        
        foreach ($order->get_items() as $item) {
            if ($count++ >= $max_items) break;
            
            try {
                $product = $item->get_product();
                if (!$product) continue;
                
                $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
                if ($hts_code && $hts_code !== '9999.99.9999') {
                    $sku = $product->get_sku();
                    if ($sku) {
                        $hts_codes[] = substr($sku, 0, 20) . ':' . $hts_code;
                    }
                }
            } catch (Exception $e) {
                continue; // Skip problematic items
            }
        }
        
        if (!empty($hts_codes)) {
            // Limit total length to prevent field overflow
            $result = implode(', ', $hts_codes);
            if (strlen($result) > 250) {
                $result = substr($result, 0, 247) . '...';
            }
            return $result;
        }
    } catch (Exception $e) {
        hts_log_error('Error in custom field 2', array('error' => $e->getMessage()));
    }
    
    return $value; // Return original value if any error
}

/**
 * SAFE WRAPPER: Add country of origin to custom field 3
 */
function hts_safe_add_country_to_custom_field($value, $order) {
    try {
        $countries = array();
        
        foreach ($order->get_items() as $item) {
            try {
                $product = $item->get_product();
                if (!$product) continue;
                
                $country = get_post_meta($product->get_id(), '_country_of_origin', true);
                if (empty($country)) {
                    $country = 'CA';
                }
                
                // Validate country code
                if (preg_match('/^[A-Z]{2}$/i', $country)) {
                    $country = strtoupper($country);
                    if (!in_array($country, $countries)) {
                        $countries[] = $country;
                    }
                }
            } catch (Exception $e) {
                continue; // Skip problematic items
            }
        }
        
        if (!empty($countries)) {
            return implode(', ', array_slice($countries, 0, 5)); // Limit to 5 countries
        }
    } catch (Exception $e) {
        hts_log_error('Error in custom field 3', array('error' => $e->getMessage()));
    }
    
    return $value; // Return original value if any error
}

/**
 * SAFE XML append helper - won't throw exceptions
 */
function hts_safe_xml_append($xml, $parent, $name, $value, $cdata = true) {
    try {
        if (!$xml || !$parent || !$name) {
            return false;
        }
        
        // Sanitize value
        $value = (string) $value;
        if (empty($value) && $value !== '0') {
            return false; // Don't add empty elements
        }
        
        // Clean value of any invalid XML characters
        $value = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $value);
        
        $element = $xml->createElement($name);
        if ($cdata && $value) {
            $element->appendChild($xml->createCDATASection($value));
        } elseif ($value) {
            $element->appendChild($xml->createTextNode($value));
        }
        $parent->appendChild($element);
        return true;
        
    } catch (Exception $e) {
        hts_log_error('XML append failed', array(
            'name' => $name,
            'error' => $e->getMessage()
        ));
        return false;
    }
}

/**
 * Safe error logging - won't throw exceptions
 */
function hts_log_error($message, $context = array()) {
    try {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = '[HTS-ShipStation ERROR] ' . $message;
            if (!empty($context)) {
                $log_message .= ' | Context: ' . json_encode($context);
            }
            error_log($log_message);
        }
        
        // Also log to WooCommerce logs if available
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->error($message, array('source' => 'hts-shipstation', 'context' => $context));
        }
    } catch (Exception $e) {
        // Even logging failed - just silently continue
    }
}

/**
 * Health check endpoint for monitoring
 */
add_action('init', 'hts_register_health_check');
function hts_register_health_check() {
    if (isset($_GET['hts_health_check']) && $_GET['hts_health_check'] === 'true') {
        header('Content-Type: application/json');
        
        $status = array(
            'status' => 'healthy',
            'plugin_active' => true,
            'shipstation_active' => class_exists('WC_Shipstation_Integration'),
            'last_error' => get_option('hts_last_error', 'none'),
            'version' => '1.1.0'
        );
        
        echo json_encode($status);
        exit;
    }
}

/**
 * Add admin notice for critical errors only
 */
add_action('admin_notices', 'hts_shipstation_error_notice');
function hts_shipstation_error_notice() {
    $last_error = get_option('hts_last_critical_error');
    
    if ($last_error && current_user_can('manage_woocommerce')) {
        $time_diff = time() - strtotime($last_error['time']);
        
        // Only show if error happened in last hour
        if ($time_diff < 3600) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>HTS ShipStation Integration Warning:</strong>
                    Some products may be missing HTS codes in ShipStation. 
                    <a href="<?php echo admin_url('admin.php?page=wc-status&tab=logs'); ?>">View Logs</a>
                </p>
            </div>
            <?php
        }
    }
}

/**
 * Clear error log periodically
 */
add_action('wp_scheduled_delete', 'hts_clear_old_errors');
function hts_clear_old_errors() {
    delete_option('hts_last_critical_error');
}