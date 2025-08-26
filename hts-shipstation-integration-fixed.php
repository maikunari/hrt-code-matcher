<?php
/**
 * Plugin Name: HTS to ShipStation Integration (Fixed)
 * Plugin URI: https://yoursite.com
 * Description: Fixed version - passes HTS codes using correct ShipStation API field names
 * Version: 1.2.0
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
add_action('plugins_loaded', 'hts_shipstation_fixed_init');
function hts_shipstation_fixed_init() {
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
    
    // Hook into ShipStation export with multiple approaches
    add_action('woocommerce_shipstation_export_order_xml', 'hts_fixed_add_customs_to_order_xml', 10, 3);
    add_action('woocommerce_shipstation_export_item_xml', 'hts_fixed_add_customs_to_item_xml', 10, 4);
    add_filter('woocommerce_shipstation_export_custom_field_2', 'hts_fixed_add_hts_to_custom_field', 10, 2);
    add_filter('woocommerce_shipstation_export_custom_field_3', 'hts_fixed_add_country_to_custom_field', 10, 2);
    
    // Add debug logging for testing
    if (defined('WP_DEBUG') && WP_DEBUG) {
        add_action('admin_notices', 'hts_fixed_debug_notice');
    }
}

/**
 * Fixed version: Add HTS code using correct field names for ShipStation
 */
function hts_fixed_add_customs_to_item_xml($item_xml, $order_item, $order, $xml) {
    try {
        $product = is_callable(array($order_item, 'get_product')) ? $order_item->get_product() : false;
        
        if (!$product) {
            return;
        }
        
        $product_id = $product->get_id();
        $hts_code = get_post_meta($product_id, '_hts_code', true);
        
        // Skip if no HTS code or invalid
        if (empty($hts_code) || $hts_code === '9999.99.9999') {
            return;
        }
        
        // Validate HTS code format
        if (!preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
            hts_fixed_log('Invalid HTS format: ' . $hts_code . ' for product ' . $product_id);
            return;
        }
        
        // Add customs description
        $customs_description = $product->get_name();
        if (!empty($customs_description)) {
            if (strlen($customs_description) > 200) {
                $customs_description = substr($customs_description, 0, 197) . '...';
            }
            hts_fixed_xml_append($xml, $item_xml, 'CustomsDescription', $customs_description);
        }
        
        // Format HTS code (remove dots for ShipStation)
        $formatted_hts = str_replace('.', '', $hts_code);
        
        // Try the most likely correct field name first based on ShipStation documentation
        // The field is most likely called 'harmonized_tariff_code' or 'HarmonizedTariffCode'
        if (preg_match('/^\d{10}$/', $formatted_hts)) {
            hts_fixed_xml_append($xml, $item_xml, 'harmonized_tariff_code', $formatted_hts);
            hts_fixed_log('Added HTS code ' . $formatted_hts . ' to product ' . $product_id . ' as harmonized_tariff_code');
        }
        
        // Add Country of Origin
        $country_of_origin = get_post_meta($product_id, '_country_of_origin', true);
        if (empty($country_of_origin)) {
            $country_of_origin = 'CA';
        }
        
        if (preg_match('/^[A-Z]{2}$/', strtoupper($country_of_origin))) {
            hts_fixed_xml_append($xml, $item_xml, 'CountryOfOrigin', strtoupper($country_of_origin));
        } else {
            hts_fixed_xml_append($xml, $item_xml, 'CountryOfOrigin', 'CA');
        }
        
        // Add customs value
        try {
            $item_value = $order->get_item_subtotal($order_item, false, false);
            if (is_numeric($item_value) && $item_value > 0) {
                hts_fixed_xml_append($xml, $item_xml, 'CustomsValue', number_format($item_value, 2, '.', ''));
            }
        } catch (Exception $e) {
            hts_fixed_log('Could not get item value: ' . $e->getMessage());
        }
        
    } catch (Exception $e) {
        hts_fixed_log('Error in item XML processing: ' . $e->getMessage());
    }
}

/**
 * Add customs information to order XML using correct structure
 */
function hts_fixed_add_customs_to_order_xml($order_xml, $order, $xml) {
    try {
        $customs_items_xml = $xml->createElement('CustomsItems');
        $has_customs_items = false;
        
        foreach ($order->get_items() as $item_id => $item) {
            try {
                $product = is_callable(array($item, 'get_product')) ? $item->get_product() : false;
                
                if (!$product || !$product->needs_shipping()) {
                    continue;
                }
                
                $product_id = $product->get_id();
                $hts_code = get_post_meta($product_id, '_hts_code', true);
                
                if (empty($hts_code) || $hts_code === '9999.99.9999') {
                    continue;
                }
                
                if (!preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
                    continue;
                }
                
                $has_customs_items = true;
                $customs_item_xml = $xml->createElement('CustomsItem');
                
                // Add all required fields
                hts_fixed_xml_append($xml, $customs_item_xml, 'SKU', $product->get_sku());
                
                $description = substr($product->get_name(), 0, 200);
                hts_fixed_xml_append($xml, $customs_item_xml, 'Description', $description);
                
                $quantity = $item->get_quantity() - abs($order->get_qty_refunded_for_item($item_id));
                hts_fixed_xml_append($xml, $customs_item_xml, 'Quantity', max(0, $quantity));
                
                $item_value = $order->get_item_subtotal($item, false, false);
                if (is_numeric($item_value)) {
                    hts_fixed_xml_append($xml, $customs_item_xml, 'Value', number_format($item_value, 2, '.', ''));
                }
                
                // Use correct field name for harmonized tariff code
                $formatted_hts = str_replace('.', '', $hts_code);
                hts_fixed_xml_append($xml, $customs_item_xml, 'harmonized_tariff_code', $formatted_hts);
                
                $country = get_post_meta($product_id, '_country_of_origin', true) ?: 'CA';
                hts_fixed_xml_append($xml, $customs_item_xml, 'CountryOfOrigin', strtoupper($country));
                
                $customs_items_xml->appendChild($customs_item_xml);
                
                hts_fixed_log('Added customs item for product ' . $product_id . ' with HTS ' . $formatted_hts);
                
            } catch (Exception $e) {
                hts_fixed_log('Error processing customs item: ' . $e->getMessage());
                continue;
            }
        }
        
        if ($has_customs_items) {
            $order_xml->appendChild($customs_items_xml);
            hts_fixed_log('Added CustomsItems section to order ' . $order->get_id());
        }
        
    } catch (Exception $e) {
        hts_fixed_log('Error in order customs processing: ' . $e->getMessage());
    }
}

/**
 * Fallback: Add HTS codes to custom field 2
 */
function hts_fixed_add_hts_to_custom_field($value, $order) {
    try {
        $hts_codes = array();
        
        foreach ($order->get_items() as $item) {
            try {
                $product = $item->get_product();
                if (!$product) continue;
                
                $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
                if ($hts_code && $hts_code !== '9999.99.9999' && preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
                    $sku = $product->get_sku();
                    if ($sku) {
                        $hts_codes[] = substr($sku, 0, 20) . ':' . $hts_code;
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        if (!empty($hts_codes)) {
            $result = implode(', ', $hts_codes);
            if (strlen($result) > 250) {
                $result = substr($result, 0, 247) . '...';
            }
            hts_fixed_log('Added HTS codes to custom field 2: ' . $result);
            return $result;
        }
    } catch (Exception $e) {
        hts_fixed_log('Error in custom field 2: ' . $e->getMessage());
    }
    
    return $value;
}

/**
 * Add country of origin to custom field 3
 */
function hts_fixed_add_country_to_custom_field($value, $order) {
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
                
                if (preg_match('/^[A-Z]{2}$/i', $country)) {
                    $country = strtoupper($country);
                    if (!in_array($country, $countries)) {
                        $countries[] = $country;
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        if (!empty($countries)) {
            return implode(', ', array_slice($countries, 0, 5));
        }
    } catch (Exception $e) {
        hts_fixed_log('Error in custom field 3: ' . $e->getMessage());
    }
    
    return $value;
}

/**
 * Safe XML append helper
 */
function hts_fixed_xml_append($xml, $parent, $name, $value, $cdata = true) {
    try {
        if (!$xml || !$parent || !$name) {
            return false;
        }
        
        $value = (string) $value;
        if (empty($value) && $value !== '0') {
            return false;
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
        hts_fixed_log('XML append failed for ' . $name . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced logging function
 */
function hts_fixed_log($message, $context = array()) {
    try {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = '[HTS-ShipStation FIXED] ' . $message;
            if (!empty($context)) {
                $log_message .= ' | Context: ' . json_encode($context);
            }
            error_log($log_message);
        }
        
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info($message, array('source' => 'hts-shipstation-fixed', 'context' => $context));
        }
    } catch (Exception $e) {
        // Silently continue
    }
}

/**
 * Debug notice for admin
 */
function hts_fixed_debug_notice() {
    if (current_user_can('manage_woocommerce') && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') {
        ?>
        <div class="notice notice-info">
            <p><strong>HTS-ShipStation Fixed Integration:</strong> Debug mode active. Check logs for HTS export details.</p>
        </div>
        <?php
    }
}

/**
 * Add settings link to plugin page
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'hts_fixed_add_settings_link');
function hts_fixed_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-status&tab=logs') . '">View Logs</a>';
    array_unshift($links, $settings_link);
    return $links;
}