<?php
/**
 * Quick diagnostic to check Dutify taxonomy status for a specific product
 * Usage: Add to theme functions.php or run as admin
 */

add_action('admin_init', function() {
    if (!isset($_GET['check_dutify_status'])) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    
    echo '<pre>';
    echo "=== Dutify Taxonomy Status Check ===\n\n";
    
    // Check which Dutify taxonomies exist
    echo "1. Registered Dutify Taxonomies:\n";
    $dutify_taxonomies = ['pa_dutify_hs_code', 'pa_dutify_country_origin', 'pa_dutify_hs_code_country', 'pa_dutify_class_id'];
    foreach ($dutify_taxonomies as $tax) {
        $exists = taxonomy_exists($tax);
        echo "   " . $tax . ": " . ($exists ? "✅ EXISTS" : "❌ MISSING") . "\n";
        
        if ($exists) {
            $terms = get_terms(array('taxonomy' => $tax, 'hide_empty' => false));
            if (!is_wp_error($terms)) {
                echo "      Total terms: " . count($terms) . "\n";
                if (count($terms) > 0 && count($terms) <= 5) {
                    foreach ($terms as $term) {
                        echo "      - " . $term->name . " (ID: " . $term->term_id . ")\n";
                    }
                }
            }
        }
    }
    
    // Check specific product if provided
    if ($product_id > 0) {
        echo "\n2. Product #" . $product_id . " Status:\n";
        
        // Get HTS Manager data
        $hts_code = get_post_meta($product_id, '_hts_code', true);
        $country = get_post_meta($product_id, '_country_of_origin', true);
        
        echo "   HTS Manager Data:\n";
        echo "   - HTS Code: " . ($hts_code ?: 'NOT SET') . "\n";
        echo "   - Country: " . ($country ?: 'NOT SET') . "\n";
        
        // Get Dutify data
        echo "\n   Dutify Taxonomy Data:\n";
        foreach ($dutify_taxonomies as $tax) {
            if (taxonomy_exists($tax)) {
                $terms = wp_get_object_terms($product_id, $tax, array('fields' => 'names'));
                if (!is_wp_error($terms) && !empty($terms)) {
                    echo "   - " . $tax . ": " . implode(', ', $terms) . "\n";
                } else {
                    echo "   - " . $tax . ": NOT SET\n";
                }
            }
        }
        
        // Check if it's a variation
        $post_type = get_post_type($product_id);
        if ($post_type === 'product_variation') {
            $parent_id = wp_get_post_parent_id($product_id);
            echo "\n   Note: This is a variation (parent ID: " . $parent_id . ")\n";
            echo "   Dutify reads from parent product.\n";
            
            echo "\n   Parent Product Dutify Data:\n";
            foreach ($dutify_taxonomies as $tax) {
                if (taxonomy_exists($tax)) {
                    $terms = wp_get_object_terms($parent_id, $tax, array('fields' => 'names'));
                    if (!is_wp_error($terms) && !empty($terms)) {
                        echo "   - " . $tax . ": " . implode(', ', $terms) . "\n";
                    } else {
                        echo "   - " . $tax . ": NOT SET\n";
                    }
                }
            }
        }
    }
    
    echo "\n3. WooCommerce Attributes:\n";
    $attributes = wc_get_attribute_taxonomies();
    foreach ($attributes as $attr) {
        if (strpos($attr->attribute_name, 'dutify') !== false) {
            echo "   - " . $attr->attribute_name . " (ID: " . $attr->attribute_id . ")\n";
        }
    }
    
    echo '</pre>';
    die();
});

// Add admin notice
add_action('admin_notices', function() {
    if (current_user_can('manage_options')) {
        $url = add_query_arg('check_dutify_status', '1', admin_url());
        if (isset($_GET['post'])) {
            $url = add_query_arg('product_id', $_GET['post'], $url);
        }
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>Dutify Debug:</strong> ';
        echo '<a href="' . esc_url($url) . '" target="_blank">Check Dutify Taxonomy Status</a>';
        if (isset($_GET['post'])) {
            echo ' (for this product)';
        }
        echo '</p>';
        echo '</div>';
    }
});