<?php
/**
 * Test script to verify HTS to Dutify sync
 * Run: php test-dutify-sync.php [product_id]
 */

require_once('/path/to/wordpress/wp-load.php'); // Update this path!

$product_id = isset($argv[1]) ? intval($argv[1]) : 0;

if (!$product_id) {
    echo "Usage: php test-dutify-sync.php [product_id]\n";
    exit(1);
}

$product = wc_get_product($product_id);
if (!$product) {
    echo "Product not found\n";
    exit(1);
}

echo "=== Product: " . $product->get_name() . " (ID: $product_id) ===\n\n";

// Check HTS Manager data
$hts_code = get_post_meta($product_id, '_hts_code', true);
$country = get_post_meta($product_id, '_country_of_origin', true);
echo "HTS Manager Data:\n";
echo "  HTS Code: " . ($hts_code ?: 'Not set') . "\n";
echo "  Country: " . ($country ?: 'Not set') . "\n\n";

// Check Dutify attributes
echo "Dutify Attributes:\n";
$dutify_attrs = [
    'pa_dutify_hs_code' => 'HS Code',
    'pa_dutify_country_origin' => 'Country',
    'pa_dutify_hs_code_country' => 'HS Country',
    'pa_dutify_class_id' => 'Class ID'
];

foreach ($dutify_attrs as $taxonomy => $label) {
    $terms = wc_get_product_terms($product_id, $taxonomy, array('fields' => 'names'));
    echo "  $label: " . ($terms ? implode(', ', $terms) : 'Not set') . "\n";
}

// Test sync
if ($hts_code && function_exists('hts_sync_to_dutify')) {
    echo "\n=== Testing Sync ===\n";
    $result = hts_sync_to_dutify($product_id);
    echo "Sync result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
    
    // Re-check after sync
    echo "\nAfter sync:\n";
    $terms = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
    echo "  Dutify HS Code: " . ($terms ? implode(', ', $terms) : 'Still not set') . "\n";
}