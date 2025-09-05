<?php
/**
 * Script to check if variations have different HTS codes from their parents
 * Usage: Add to theme functions.php temporarily or run via WP-CLI
 */

// Add this as an admin action to run from browser
add_action('admin_init', function() {
    if (!isset($_GET['check_variation_hts'])) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    // Increase memory and time limits for this operation
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);
    
    echo '<pre>';
    echo "=== HTS Code Variation Analysis ===\n\n";
    
    $differences_found = false;
    $total_products = 0;
    $total_variations = 0;
    $products_with_differences = array();
    $variations_checked = 0;
    $variations_with_different_codes = 0;
    
    // Process in smaller batches
    $batch_size = 50;
    $offset = 0;
    
    while (true) {
        // Query products in batches
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'meta_query' => array(
                array(
                    'key' => '_hts_code',
                    'compare' => 'EXISTS'
                )
            )
        );
        
        $products = get_posts($args);
        
        if (empty($products)) {
            break;
        }
        
        $offset += $batch_size;
        
        foreach ($products as $product_post) {
            $product = wc_get_product($product_post->ID);
            
            if (!$product) {
                continue;
            }
            
            $total_products++;
            
            // Get parent HTS code
            $parent_hts = get_post_meta($product_post->ID, '_hts_code', true);
            
            if ($product->is_type('variable')) {
                $variations = $product->get_children();
                
                if (!empty($variations)) {
                    $parent_has_variations = true;
                    $all_same = true;
                    $variation_codes = array();
                    
                    foreach ($variations as $variation_id) {
                        $total_variations++;
                        $variations_checked++;
                        
                        // Get variation HTS code
                        $variation_hts = get_post_meta($variation_id, '_hts_code', true);
                        
                        if ($variation_hts && $variation_hts !== $parent_hts) {
                            $all_same = false;
                            $variations_with_different_codes++;
                            $variation_codes[$variation_id] = $variation_hts;
                        }
                    }
                    
                    if (!$all_same) {
                        $differences_found = true;
                        $products_with_differences[] = array(
                            'product_id' => $product_post->ID,
                            'product_title' => get_the_title($product_post->ID),
                            'parent_hts' => $parent_hts,
                            'variation_codes' => $variation_codes
                        );
                    }
                }
            }
        }
    }
    
    // Display results
    echo "Total products checked: $total_products\n";
    echo "Total variations checked: $variations_checked\n";
    echo "Variations with different HTS codes: $variations_with_different_codes\n\n";
    
    if ($differences_found) {
        echo "⚠️ DIFFERENCES FOUND!\n";
        echo "Products with variation HTS code differences: " . count($products_with_differences) . "\n\n";
        
        echo "Detailed list of products with differences:\n";
        echo str_repeat("-", 80) . "\n\n";
        
        foreach ($products_with_differences as $diff) {
            echo "Product: " . $diff['product_title'] . " (ID: " . $diff['product_id'] . ")\n";
            echo "Parent HTS: " . $diff['parent_hts'] . "\n";
            echo "Variations with different codes:\n";
            
            foreach ($diff['variation_codes'] as $var_id => $var_hts) {
                $variation = wc_get_product($var_id);
                if ($variation) {
                    $attributes = $variation->get_variation_attributes();
                    $attr_string = array();
                    foreach ($attributes as $key => $value) {
                        if ($value) {
                            $attr_string[] = $value;
                        }
                    }
                    echo "  - Variation " . implode(', ', $attr_string) . " (ID: $var_id): $var_hts\n";
                }
            }
            echo "\n";
        }
        
        echo str_repeat("-", 80) . "\n";
        echo "SUMMARY: Found " . count($products_with_differences) . " products where variations have different HTS codes than parents.\n";
        echo "This means Option 1 (using Dutify plugin as-is) would lose accuracy for these products.\n";
        
    } else {
        echo "✅ GOOD NEWS! No differences found.\n";
        echo "All variations have the same HTS codes as their parent products.\n";
        echo "This means the Dutify plugin limitation is not a problem for your store.\n";
    }
    
    // Also check for variations without any HTS code
    echo "\n=== Additional Check: Missing HTS Codes ===\n";
    
    $variations_without_codes = 0;
    $parents_without_codes = 0;
    
    $all_variations = get_posts(array(
        'post_type' => 'product_variation',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ));
    
    foreach ($all_variations as $variation_post) {
        $hts_code = get_post_meta($variation_post->ID, '_hts_code', true);
        if (empty($hts_code)) {
            $variations_without_codes++;
        }
    }
    
    $all_products = get_posts(array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ));
    
    foreach ($all_products as $product_post) {
        $hts_code = get_post_meta($product_post->ID, '_hts_code', true);
        if (empty($hts_code)) {
            $parents_without_codes++;
        }
    }
    
    echo "Products without HTS codes: $parents_without_codes\n";
    echo "Variations without HTS codes: $variations_without_codes\n";
    
    echo '</pre>';
    die();
});

// Add admin notice with link
add_action('admin_notices', function() {
    if (current_user_can('manage_options')) {
        $check_url = add_query_arg('check_variation_hts', '1', admin_url());
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>HTS Variation Check:</strong> ';
        echo '<a href="' . esc_url($check_url) . '" target="_blank">Check if variations have different HTS codes from parents</a>';
        echo ' (This will help determine if Dutify plugin limitation affects you)</p>';
        echo '</div>';
    }
});