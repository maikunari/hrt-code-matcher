<?php
/**
 * Plugin Name: HTS to ShipStation Integration
 * Plugin URI: https://yoursite.com
 * Description: Automatically passes HTS codes and Country of Origin to ShipStation for customs declarations
 * Version: 1.0.0
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
    
    // Hook into ShipStation export
    add_action('woocommerce_shipstation_export_order_xml', 'hts_add_customs_to_shipstation_xml', 10, 3);
    add_filter('woocommerce_shipstation_export_custom_field_2', 'hts_add_hts_to_custom_field', 10, 2);
    add_filter('woocommerce_shipstation_export_custom_field_3', 'hts_add_country_to_custom_field', 10, 2);
    
    // Alternative: Add customs data to order items
    add_action('woocommerce_shipstation_export_item_xml', 'hts_add_customs_to_item_xml', 10, 4);
}

/**
 * Add HTS code and Country of Origin to ShipStation item XML
 * This is the primary method - adds customs data directly to each item
 */
function hts_add_customs_to_item_xml($item_xml, $order_item, $order, $xml) {
    // Get the product
    $product = is_callable(array($order_item, 'get_product')) ? $order_item->get_product() : false;
    
    if (!$product) {
        return;
    }
    
    $product_id = $product->get_id();
    
    // Get HTS code and country of origin from product meta
    $hts_code = get_post_meta($product_id, '_hts_code', true);
    $country_of_origin = get_post_meta($product_id, '_country_of_origin', true);
    
    // Add CustomsDescription (product name)
    $customs_description = $product->get_name();
    if (strlen($customs_description) > 200) {
        $customs_description = substr($customs_description, 0, 197) . '...';
    }
    hts_xml_append($xml, $item_xml, 'CustomsDescription', $customs_description);
    
    // Add HTS/Harmonization code
    if ($hts_code && $hts_code !== '9999.99.9999') {
        // Remove dots for ShipStation (they expect format like 6109100012)
        $formatted_hts = str_replace('.', '', $hts_code);
        hts_xml_append($xml, $item_xml, 'HarmonizedCode', $formatted_hts);
    }
    
    // Add Country of Origin
    if ($country_of_origin) {
        hts_xml_append($xml, $item_xml, 'CountryOfOrigin', $country_of_origin);
    } else {
        // Default to Canada if not set
        hts_xml_append($xml, $item_xml, 'CountryOfOrigin', 'CA');
    }
    
    // Add customs value (item value)
    $item_value = $order->get_item_subtotal($order_item, false, false);
    if ($item_value) {
        hts_xml_append($xml, $item_xml, 'CustomsValue', number_format($item_value, 2, '.', ''));
    }
}

/**
 * Alternative method: Add customs information to the order level XML
 * This adds a CustomsItems section to the order
 */
function hts_add_customs_to_shipstation_xml($order_xml, $order, $xml) {
    $customs_items_xml = $xml->createElement('CustomsItems');
    $has_customs_items = false;
    
    // Process each order item
    foreach ($order->get_items() as $item_id => $item) {
        $product = is_callable(array($item, 'get_product')) ? $item->get_product() : false;
        
        if (!$product || !$product->needs_shipping()) {
            continue;
        }
        
        $product_id = $product->get_id();
        
        // Get HTS code and country of origin
        $hts_code = get_post_meta($product_id, '_hts_code', true);
        $country_of_origin = get_post_meta($product_id, '_country_of_origin', true);
        
        // Skip if no HTS code
        if (!$hts_code || $hts_code === '9999.99.9999') {
            continue;
        }
        
        $has_customs_items = true;
        
        // Create customs item element
        $customs_item_xml = $xml->createElement('CustomsItem');
        
        // Add SKU
        hts_xml_append($xml, $customs_item_xml, 'SKU', $product->get_sku());
        
        // Add description
        $description = $product->get_name();
        if (strlen($description) > 200) {
            $description = substr($description, 0, 197) . '...';
        }
        hts_xml_append($xml, $customs_item_xml, 'Description', $description);
        
        // Add quantity
        $quantity = $item->get_quantity() - abs($order->get_qty_refunded_for_item($item_id));
        hts_xml_append($xml, $customs_item_xml, 'Quantity', $quantity);
        
        // Add value
        $item_value = $order->get_item_subtotal($item, false, false);
        hts_xml_append($xml, $customs_item_xml, 'Value', number_format($item_value, 2, '.', ''));
        
        // Add HTS code (formatted without dots)
        $formatted_hts = str_replace('.', '', $hts_code);
        hts_xml_append($xml, $customs_item_xml, 'HarmonizedCode', $formatted_hts);
        
        // Add country of origin (default to CA if not set)
        $country = $country_of_origin ?: 'CA';
        hts_xml_append($xml, $customs_item_xml, 'CountryOfOrigin', $country);
        
        $customs_items_xml->appendChild($customs_item_xml);
    }
    
    // Only append if we have customs items
    if ($has_customs_items) {
        $order_xml->appendChild($customs_items_xml);
    }
}

/**
 * Add HTS codes to custom field 2 (backup method)
 */
function hts_add_hts_to_custom_field($value, $order) {
    $hts_codes = array();
    
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        
        $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
        if ($hts_code && $hts_code !== '9999.99.9999') {
            $sku = $product->get_sku();
            $hts_codes[] = $sku . ':' . $hts_code;
        }
    }
    
    if (!empty($hts_codes)) {
        return implode(', ', $hts_codes);
    }
    
    return $value;
}

/**
 * Add country of origin to custom field 3 (backup method)
 */
function hts_add_country_to_custom_field($value, $order) {
    $countries = array();
    
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        
        $country = get_post_meta($product->get_id(), '_country_of_origin', true);
        if (!$country) {
            $country = 'CA'; // Default to Canada
        }
        
        if (!in_array($country, $countries)) {
            $countries[] = $country;
        }
    }
    
    if (!empty($countries)) {
        return implode(', ', $countries);
    }
    
    return $value;
}

/**
 * Helper function to append XML elements
 */
function hts_xml_append($xml, $parent, $name, $value, $cdata = true) {
    $element = $xml->createElement($name);
    if ($cdata && $value) {
        $element->appendChild($xml->createCDATASection($value));
    } elseif ($value) {
        $element->appendChild($xml->createTextNode($value));
    }
    $parent->appendChild($element);
}

/**
 * Add admin notice to show integration status
 */
add_action('admin_notices', 'hts_shipstation_admin_notice');
function hts_shipstation_admin_notice() {
    $screen = get_current_screen();
    
    // Only show on WooCommerce orders page
    if ($screen->id !== 'edit-shop_order' && $screen->id !== 'woocommerce_page_wc-orders') {
        return;
    }
    
    // Check if both plugins are active
    if (class_exists('WC_Shipstation_Integration')) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong>HTS to ShipStation Integration:</strong> 
                Active ✓ - HTS codes and country of origin will be automatically sent to ShipStation.
            </p>
        </div>
        <?php
    }
}

/**
 * Add settings link on plugins page
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'hts_shipstation_settings_link');
function hts_shipstation_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=shipping&section=shipstation">' . __('ShipStation Settings', 'hts-shipstation') . '</a>';
    $products_link = '<a href="edit.php?post_type=product">' . __('View Products', 'hts-shipstation') . '</a>';
    
    array_unshift($links, $settings_link, $products_link);
    return $links;
}

/**
 * Log customs data for debugging (optional - can be enabled for testing)
 */
function hts_log_customs_data($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[HTS-ShipStation] ' . $message);
    }
}

/**
 * Test function to verify HTS codes are being read correctly
 */
add_action('woocommerce_shipstation_exported_order', 'hts_log_exported_order', 10, 1);
function hts_log_exported_order($order_id) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        
        $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
        $country = get_post_meta($product->get_id(), '_country_of_origin', true);
        
        hts_log_customs_data(sprintf(
            'Order #%d - Product: %s (SKU: %s) - HTS: %s - Country: %s',
            $order_id,
            $product->get_name(),
            $product->get_sku(),
            $hts_code ?: 'not set',
            $country ?: 'CA (default)'
        ));
    }
}