<?php
/**
 * Debug specific products that are failing
 */

add_action('admin_init', function() {
    if (!isset($_GET['debug_product_sync'])) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    // Test with one of the failing products
    $test_products = array(
        'Stainless Steel Liner - Flexible',
        'Wood Stove Back Draft Heat Collar',
        'Single Wall Smoke Pipe Thermometer',
        'Wood Stove Heat Shield Protection'
    );
    
    echo '<pre>';
    echo "=== Debug Product Sync Issues ===\n\n";
    
    // Find these products
    foreach ($test_products as $search_title) {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 1,
            's' => $search_title,
            'post_status' => 'publish'
        );
        
        $products = get_posts($args);
        
        if (!empty($products)) {
            $product_id = $products[0]->ID;
            $product = wc_get_product($product_id);
            
            echo "Product: " . $product->get_name() . " (ID: $product_id)\n";
            echo "Type: " . $product->get_type() . "\n";
            
            // Get HTS data
            $hts_code = get_post_meta($product_id, '_hts_code', true);
            $country = get_post_meta($product_id, '_country_of_origin', true);
            
            echo "HTS Code: " . $hts_code . "\n";
            echo "Country: " . $country . "\n";
            
            // Check format
            $clean_hs = preg_replace('/[^0-9]/', '', $hts_code);
            echo "Clean HTS: " . $clean_hs . " (length: " . strlen($clean_hs) . ")\n";
            
            // Try sync
            echo "Attempting sync...\n";
            
            // Enable error reporting for this test
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
            ini_set('log_errors', 1);
            
            $result = hts_sync_to_dutify($product_id);
            
            echo "Sync result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
            
            // Check what got saved
            $dutify_hs = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
            $dutify_country = wc_get_product_terms($product_id, 'pa_dutify_country_origin', array('fields' => 'names'));
            
            echo "Dutify HS: " . ($dutify_hs ? implode(', ', $dutify_hs) : 'NOT SET') . "\n";
            echo "Dutify Country: " . ($dutify_country ? implode(', ', $dutify_country) : 'NOT SET') . "\n";
            
            echo "\n" . str_repeat("-", 50) . "\n\n";
        } else {
            echo "Product not found: $search_title\n\n";
        }
    }
    
    echo '</pre>';
    die();
});

// Add admin notice
add_action('admin_notices', function() {
    if (current_user_can('manage_options')) {
        $url = add_query_arg('debug_product_sync', '1', admin_url());
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>Debug:</strong> ';
        echo '<a href="' . esc_url($url) . '" target="_blank">Debug Failing Product Sync</a></p>';
        echo '</div>';
    }
});