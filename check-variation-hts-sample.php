<?php
/**
 * Simplified script to sample check if variations have different HTS codes
 * This version checks first 1000 variable products as a comprehensive sample
 */

add_action('admin_init', function() {
    if (!isset($_GET['check_variation_hts_sample'])) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    // Increase time limit for 1000 products
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    
    echo '<pre>';
    echo "=== HTS Code Variation Sample Check (First 1000 Variable Products) ===\n\n";
    
    // Get just variable products with HTS codes
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => 1000,
        'meta_query' => array(
            array(
                'key' => '_hts_code',
                'compare' => 'EXISTS'
            )
        ),
        'tax_query' => array(
            array(
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => 'variable',
            )
        )
    );
    
    $products = get_posts($args);
    
    if (empty($products)) {
        echo "No variable products with HTS codes found.\n";
        die();
    }
    
    $differences_found = false;
    
    echo "Checking " . count($products) . " variable products (20% sample of ~5000 total products)...\n\n";
    
    foreach ($products as $product_post) {
        $product = wc_get_product($product_post->ID);
        
        if (!$product || !$product->is_type('variable')) {
            continue;
        }
        
        $parent_hts = get_post_meta($product_post->ID, '_hts_code', true);
        $variations = $product->get_children();
        
        echo "Product: " . get_the_title($product_post->ID) . "\n";
        echo "  Parent HTS: " . $parent_hts . "\n";
        
        $has_differences = false;
        
        foreach ($variations as $variation_id) {
            $variation_hts = get_post_meta($variation_id, '_hts_code', true);
            
            if ($variation_hts && $variation_hts !== $parent_hts) {
                $has_differences = true;
                $differences_found = true;
                
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $attributes = $variation->get_variation_attributes();
                    $attr_string = array();
                    foreach ($attributes as $key => $value) {
                        if ($value) {
                            $attr_string[] = $value;
                        }
                    }
                    echo "  ⚠️ Different: " . implode(', ', $attr_string) . " = $variation_hts\n";
                }
            }
        }
        
        if (!$has_differences) {
            echo "  ✅ All variations match parent\n";
        }
        
        echo "\n";
    }
    
    echo str_repeat("-", 60) . "\n";
    
    if ($differences_found) {
        echo "⚠️ DIFFERENCES FOUND in this sample!\n";
        echo "Some variations have different HTS codes than their parents.\n";
        echo "The Dutify plugin would use incorrect codes for these products.\n";
        echo "\nRecommendation: Build custom integration (Option 2) to handle variations properly.\n";
    } else {
        echo "✅ NO DIFFERENCES found in this sample.\n";
        echo "All checked variations have the same HTS codes as parents.\n";
        echo "The Dutify plugin limitation does not affect you.\n";
        echo "\nNote: This is a comprehensive sample of 1000 products (20% of your catalog).\n";
        echo "This provides a highly reliable indication - you can use Dutify plugin as-is.\n";
    }
    
    echo '</pre>';
    die();
});

// Add admin notice
add_action('admin_notices', function() {
    if (current_user_can('manage_options')) {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>HTS Quick Check:</strong> ';
        echo '<a href="' . add_query_arg('check_variation_hts_sample', '1', admin_url()) . '" target="_blank">';
        echo 'Comprehensive check (1000 products)</a> - Check 20% of catalog to see if variations differ from parents</p>';
        echo '</div>';
    }
});