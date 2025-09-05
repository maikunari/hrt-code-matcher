#!/usr/bin/env php
<?php
/**
 * Quick CLI tool to check Dutify sync status
 * Usage: php check-dutify-sync.php [product_id]
 * Or: php check-dutify-sync.php all (to check all products with HTS codes)
 */

// IMPORTANT: Update this path to your WordPress installation
$wp_load_path = '/path/to/wordpress/wp-load.php';

if (!file_exists($wp_load_path)) {
    echo "ERROR: Update the wp-load.php path in this script first!\n";
    echo "Edit line 10 of this file with your WordPress path.\n";
    exit(1);
}

require_once($wp_load_path);

// Color codes for terminal output
$green = "\033[0;32m";
$red = "\033[0;31m";
$yellow = "\033[1;33m";
$reset = "\033[0m";

if ($argc < 2) {
    echo "Usage: php check-dutify-sync.php [product_id]\n";
    echo "   Or: php check-dutify-sync.php all\n";
    exit(1);
}

$arg = $argv[1];

if ($arg === 'all') {
    // Check all products with HTS codes
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_hts_code',
                'compare' => 'EXISTS'
            )
        )
    );
    
    $products = get_posts($args);
    $total = count($products);
    $synced = 0;
    $not_synced = 0;
    
    echo "Checking $total products with HTS codes...\n\n";
    
    foreach ($products as $post) {
        $product_id = $post->ID;
        $product = wc_get_product($product_id);
        
        // Get HTS code
        $hts_code = get_post_meta($product_id, '_hts_code', true);
        $expected_hs = preg_replace('/[^0-9]/', '', $hts_code);
        
        // Get Dutify value
        $dutify_hs = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
        $dutify_hs_value = $dutify_hs ? array_shift($dutify_hs) : null;
        
        if ($dutify_hs_value === $expected_hs) {
            $synced++;
            echo "{$green}✅{$reset} " . $product->get_name() . " (ID: $product_id)\n";
        } else {
            $not_synced++;
            echo "{$red}❌{$reset} " . $product->get_name() . " (ID: $product_id) - HTS: $hts_code\n";
        }
    }
    
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "Summary:\n";
    echo "{$green}✅ Synced: $synced{$reset}\n";
    echo "{$red}❌ Not synced: $not_synced{$reset}\n";
    
    if ($not_synced > 0) {
        echo "\n{$yellow}To sync all products, run:{$reset}\n";
        echo "php sync-all-to-dutify.php\n";
    }
    
} else {
    // Check single product
    $product_id = intval($arg);
    $product = wc_get_product($product_id);
    
    if (!$product) {
        echo "{$red}ERROR: Product ID $product_id not found{$reset}\n";
        exit(1);
    }
    
    echo str_repeat('=', 50) . "\n";
    echo "Product: " . $product->get_name() . " (ID: $product_id)\n";
    echo str_repeat('=', 50) . "\n\n";
    
    // Get HTS Manager data
    $hts_code = get_post_meta($product_id, '_hts_code', true);
    $country = get_post_meta($product_id, '_country_of_origin', true);
    
    echo "HTS Manager Data:\n";
    echo "  HTS Code: " . ($hts_code ?: 'Not set') . "\n";
    echo "  Country: " . ($country ?: 'Not set (defaults to CA)') . "\n\n";
    
    // Get Dutify attributes
    $dutify_hs = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
    $dutify_country = wc_get_product_terms($product_id, 'pa_dutify_country_origin', array('fields' => 'names'));
    $dutify_hs_country = wc_get_product_terms($product_id, 'pa_dutify_hs_code_country', array('fields' => 'names'));
    
    $dutify_hs_value = $dutify_hs ? array_shift($dutify_hs) : null;
    $dutify_country_value = $dutify_country ? array_shift($dutify_country) : null;
    $dutify_hs_country_value = $dutify_hs_country ? array_shift($dutify_hs_country) : null;
    
    echo "Dutify Attributes:\n";
    echo "  HS Code: " . ($dutify_hs_value ?: 'Not set') . "\n";
    echo "  Country: " . ($dutify_country_value ?: 'Not set') . "\n";
    echo "  HS Country: " . ($dutify_hs_country_value ?: 'Not set') . "\n\n";
    
    // Check sync status
    $expected_hs = preg_replace('/[^0-9]/', '', $hts_code);
    $expected_country = strtoupper(substr($country ?: 'CA', 0, 2));
    
    echo "Sync Status:\n";
    
    if ($dutify_hs_value === $expected_hs) {
        echo "  HTS Code: {$green}✅ SYNCED{$reset}\n";
    } else {
        echo "  HTS Code: {$red}❌ NOT SYNCED{$reset}\n";
        echo "    Expected: $expected_hs\n";
        echo "    Actual: " . ($dutify_hs_value ?: 'null') . "\n";
    }
    
    if ($dutify_country_value === $expected_country) {
        echo "  Country: {$green}✅ SYNCED{$reset}\n";
    } else {
        echo "  Country: {$red}❌ NOT SYNCED{$reset}\n";
        echo "    Expected: $expected_country\n";
        echo "    Actual: " . ($dutify_country_value ?: 'null') . "\n";
    }
    
    if ($dutify_hs_country_value) {
        echo "  HS Country: {$green}✅ SET{$reset} ($dutify_hs_country_value)\n";
    } else {
        echo "  HS Country: {$red}❌ NOT SET{$reset}\n";
    }
    
    echo "\n";
    
    // Overall status
    if ($dutify_hs_value === $expected_hs && 
        $dutify_country_value === $expected_country && 
        $dutify_hs_country_value) {
        echo "{$green}✅ READY FOR DUTIFY CHECKOUT!{$reset}\n";
    } else {
        echo "{$yellow}⚠️ NOT FULLY SYNCED - Save product in WordPress to trigger sync{$reset}\n";
    }
}

echo "\n";