<?php
/**
 * One-time fix to set default country for products with HTS codes but no country
 */

add_action('admin_init', function() {
    if (!isset($_GET['fix_missing_countries'])) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    echo '<pre>';
    echo "=== Fixing Missing Countries for HTS Products ===\n\n";
    
    // Find all products with HTS codes
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
    $fixed = 0;
    $already_set = 0;
    
    echo "Found " . count($products) . " products with HTS codes.\n\n";
    
    foreach ($products as $product_post) {
        $product_id = $product_post->ID;
        $hts_code = get_post_meta($product_id, '_hts_code', true);
        $country = get_post_meta($product_id, '_country_of_origin', true);
        
        if (empty($country)) {
            // Set default to Canada
            update_post_meta($product_id, '_country_of_origin', 'CA');
            echo "✅ Fixed: " . get_the_title($product_id) . " (ID: $product_id) - Set country to CA\n";
            $fixed++;
            
            // Also trigger Dutify sync
            if (function_exists('hts_sync_to_dutify')) {
                hts_sync_to_dutify($product_id);
            }
        } else {
            $already_set++;
        }
    }
    
    echo "\n=== Summary ===\n";
    echo "Products fixed: $fixed\n";
    echo "Products already had country: $already_set\n";
    echo "Total products processed: " . count($products) . "\n";
    
    echo "\nDone! All products with HTS codes now have a country of origin.\n";
    echo '</pre>';
    die();
});

// Add admin notice
add_action('admin_notices', function() {
    if (current_user_can('manage_options') && isset($_GET['page']) && $_GET['page'] === 'hts-manager') {
        global $wpdb;
        
        // Check if there are products with HTS codes but no country
        $missing = $wpdb->get_var("
            SELECT COUNT(DISTINCT p1.post_id) 
            FROM {$wpdb->postmeta} p1
            LEFT JOIN {$wpdb->postmeta} p2 
                ON p1.post_id = p2.post_id 
                AND p2.meta_key = '_country_of_origin'
            WHERE p1.meta_key = '_hts_code' 
                AND p1.meta_value != ''
                AND p1.meta_value != '9999.99.9999'
                AND (p2.meta_value IS NULL OR p2.meta_value = '')
        ");
        
        if ($missing > 0) {
            $fix_url = add_query_arg('fix_missing_countries', '1', admin_url());
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>HTS Manager:</strong> Found ' . $missing . ' products with HTS codes but no country set. ';
            echo '<a href="' . esc_url($fix_url) . '" onclick="return confirm(\'This will set Canada as the default country for all products with HTS codes but no country. Continue?\')">Fix Now</a></p>';
            echo '</div>';
        }
    }
});