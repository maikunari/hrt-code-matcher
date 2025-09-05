<?php
/**
 * Debug script to test Dutify sync functionality
 * Add this to your theme's functions.php temporarily or run as standalone
 */

// Add debug action to test sync on admin_init
add_action('admin_init', function() {
    if (!isset($_GET['test_dutify_sync'])) {
        return;
    }
    
    echo '<pre>';
    echo "=== Dutify Sync Debug ===\n\n";
    
    // Check if Dutify class exists
    echo "1. Dutify Plugin Check:\n";
    if (class_exists('WOO_Dutify')) {
        echo "   ✅ WOO_Dutify class exists\n";
    } else {
        echo "   ❌ WOO_Dutify class NOT found\n";
        echo "   Active plugins: \n";
        $active_plugins = get_option('active_plugins');
        foreach ($active_plugins as $plugin) {
            echo "      - $plugin\n";
        }
    }
    
    // Check if taxonomies exist
    echo "\n2. Taxonomy Check:\n";
    $taxonomies = ['pa_dutify_hs_code', 'pa_dutify_country_origin', 'pa_dutify_hs_code_country', 'pa_dutify_class_id'];
    foreach ($taxonomies as $tax) {
        if (taxonomy_exists($tax)) {
            echo "   ✅ $tax exists\n";
            $terms = get_terms(array('taxonomy' => $tax, 'hide_empty' => false));
            echo "      Terms: " . count($terms) . "\n";
        } else {
            echo "   ❌ $tax NOT found\n";
        }
    }
    
    // Check if sync function exists
    echo "\n3. Sync Function Check:\n";
    if (function_exists('hts_sync_to_dutify')) {
        echo "   ✅ hts_sync_to_dutify function exists\n";
        
        // Try to sync a test product
        if (isset($_GET['product_id'])) {
            $product_id = intval($_GET['product_id']);
            echo "\n4. Testing sync for product ID: $product_id\n";
            
            $hts_code = get_post_meta($product_id, '_hts_code', true);
            $country = get_post_meta($product_id, '_country_of_origin', true);
            
            echo "   HTS Code: $hts_code\n";
            echo "   Country: $country\n";
            
            echo "\n   Calling hts_sync_to_dutify($product_id)...\n";
            $result = hts_sync_to_dutify($product_id);
            
            echo "   Result: " . ($result ? 'TRUE' : 'FALSE') . "\n";
            
            // Check what was saved
            echo "\n5. After sync check:\n";
            $dutify_hs = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
            $dutify_country = wc_get_product_terms($product_id, 'pa_dutify_country_origin', array('fields' => 'names'));
            
            echo "   Dutify HS Code: " . ($dutify_hs ? implode(', ', $dutify_hs) : 'NOT SET') . "\n";
            echo "   Dutify Country: " . ($dutify_country ? implode(', ', $dutify_country) : 'NOT SET') . "\n";
        }
    } else {
        echo "   ❌ hts_sync_to_dutify function NOT found\n";
    }
    
    // Check WooCommerce attribute taxonomies
    echo "\n6. WooCommerce Attributes Check:\n";
    $attributes = wc_get_attribute_taxonomies();
    foreach ($attributes as $attr) {
        if (strpos($attr->attribute_name, 'dutify') !== false) {
            echo "   - " . $attr->attribute_name . " (ID: " . $attr->attribute_id . ")\n";
        }
    }
    
    echo '</pre>';
    die();
});

// Add admin notice with debug link
add_action('admin_notices', function() {
    if (current_user_can('manage_options') && isset($_GET['page']) && $_GET['page'] === 'hts-manager') {
        $debug_url = add_query_arg('test_dutify_sync', '1', admin_url());
        if (isset($_GET['post'])) {
            $debug_url = add_query_arg('product_id', $_GET['post'], $debug_url);
        }
        echo '<div class="notice notice-info">';
        echo '<p>Debug Dutify Sync: <a href="' . esc_url($debug_url) . '" target="_blank">Run Debug Test</a></p>';
        echo '</div>';
    }
});