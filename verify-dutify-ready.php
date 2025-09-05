<?php
/**
 * Verify products are ready for Dutify API
 * Run: php verify-dutify-ready.php
 */

// Update this path to your WordPress installation
require_once('/path/to/wordpress/wp-load.php');

echo "=== Checking Products Ready for Dutify ===\n\n";

// Get 5 products with HTS codes
$args = array(
    'post_type' => 'product',
    'posts_per_page' => 5,
    'meta_query' => array(
        array(
            'key' => '_hts_code',
            'compare' => 'EXISTS'
        )
    )
);

$products = get_posts($args);
$ready_count = 0;
$not_ready_count = 0;

foreach ($products as $post) {
    $product_id = $post->ID;
    $product = wc_get_product($product_id);
    
    echo "Product: " . $product->get_name() . " (ID: $product_id)\n";
    
    // Get HTS Manager data
    $hts_code = get_post_meta($product_id, '_hts_code', true);
    $country = get_post_meta($product_id, '_country_of_origin', true);
    
    // Get Dutify attributes
    $dutify_hs = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
    $dutify_country = wc_get_product_terms($product_id, 'pa_dutify_country_origin', array('fields' => 'names'));
    
    $dutify_hs_value = $dutify_hs ? array_shift($dutify_hs) : null;
    $dutify_country_value = $dutify_country ? array_shift($dutify_country) : null;
    
    echo "  HTS Manager: $hts_code | Country: " . ($country ?: 'CA default') . "\n";
    echo "  Dutify HS: " . ($dutify_hs_value ?: 'NOT SET') . " | Country: " . ($dutify_country_value ?: 'NOT SET') . "\n";
    
    // Check if ready for Dutify
    if ($dutify_hs_value && strlen($dutify_hs_value) == 10) {
        echo "  ✅ READY for Dutify API\n";
        $ready_count++;
    } else {
        echo "  ❌ NOT READY - Missing or invalid Dutify attributes\n";
        $not_ready_count++;
        
        // Try to sync
        if (function_exists('hts_sync_to_dutify')) {
            echo "  🔄 Attempting sync...\n";
            $result = hts_sync_to_dutify($product_id);
            if ($result) {
                echo "  ✅ Sync successful!\n";
            } else {
                echo "  ❌ Sync failed\n";
            }
        }
    }
    echo "\n";
}

echo "=== Summary ===\n";
echo "Ready for Dutify: $ready_count products\n";
echo "Need sync: $not_ready_count products\n";

// Check if Dutify plugin is active
if (class_exists('WOO_Dutify')) {
    echo "✅ Dutify plugin is active\n";
} else {
    echo "❌ Dutify plugin is NOT active\n";
}

// Check for API key
$api_key = get_option('woo_dutify_api_token');
if ($api_key) {
    echo "✅ Dutify API key is configured (" . strlen($api_key) . " chars)\n";
} else {
    echo "❌ Dutify API key is NOT configured\n";
    echo "   Set it at: WooCommerce → Settings → Dutify\n";
}