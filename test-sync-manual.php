<?php
/**
 * Manual test for Dutify sync
 * Usage: php test-sync-manual.php [product_id]
 */

// Update this path!
require_once('/path/to/wordpress/wp-load.php');

if ($argc < 2) {
    echo "Usage: php test-sync-manual.php [product_id]\n";
    exit(1);
}

$product_id = intval($argv[1]);

echo "Testing Dutify sync for product ID: $product_id\n\n";

// Check plugin
echo "1. Checking if Dutify plugin is active...\n";
if (class_exists('WOO_Dutify')) {
    echo "   ✅ WOO_Dutify class exists\n";
} else {
    echo "   ❌ WOO_Dutify class not found\n";
    echo "   Is the plugin in wp-content/plugins/woo-dutify/?\n";
    echo "   Is it activated in WordPress admin?\n";
    exit(1);
}

// Check taxonomies
echo "\n2. Checking if Dutify taxonomies exist...\n";
$taxonomies = ['pa_dutify_hs_code', 'pa_dutify_country_origin', 'pa_dutify_hs_code_country'];
foreach ($taxonomies as $tax) {
    if (taxonomy_exists($tax)) {
        echo "   ✅ $tax exists\n";
    } else {
        echo "   ❌ $tax does not exist\n";
        echo "   The Dutify plugin may not have initialized properly\n";
    }
}

// Get product data
echo "\n3. Getting product data...\n";
$hts_code = get_post_meta($product_id, '_hts_code', true);
$country = get_post_meta($product_id, '_country_of_origin', true);
echo "   HTS Code: $hts_code\n";
echo "   Country: $country\n";

// Test sync function
echo "\n4. Testing sync function...\n";
if (function_exists('hts_sync_to_dutify')) {
    echo "   Function exists, calling it...\n";
    $result = hts_sync_to_dutify($product_id);
    echo "   Result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
} else {
    echo "   ❌ hts_sync_to_dutify function not found\n";
    echo "   Is the HTS Manager plugin active?\n";
    exit(1);
}

// Check results
echo "\n5. Checking if sync worked...\n";
$dutify_hs = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
$dutify_country = wc_get_product_terms($product_id, 'pa_dutify_country_origin', array('fields' => 'names'));

echo "   Dutify HS Code: " . ($dutify_hs ? implode(', ', $dutify_hs) : 'NOT SET') . "\n";
echo "   Dutify Country: " . ($dutify_country ? implode(', ', $dutify_country) : 'NOT SET') . "\n";

$expected_hs = preg_replace('/[^0-9]/', '', $hts_code);
if ($dutify_hs && in_array($expected_hs, $dutify_hs)) {
    echo "\n✅ SUCCESS: HTS code is synced to Dutify!\n";
} else {
    echo "\n❌ FAILED: HTS code did not sync\n";
    echo "Expected: $expected_hs\n";
    echo "Check the WordPress error log for details\n";
}