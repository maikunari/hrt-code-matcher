<?php
/**
 * Quick check for Dutify taxonomies
 * Add this to your theme's functions.php temporarily
 */

add_action('init', function() {
    if (!isset($_GET['check_dutify'])) {
        return;
    }
    
    echo '<pre>';
    echo "=== Dutify Taxonomy Check ===\n\n";
    
    // Check if Dutify class exists
    echo "1. Plugin Status:\n";
    if (class_exists('WOO_Dutify')) {
        echo "   ✅ WOO_Dutify class exists\n";
    } else {
        echo "   ❌ WOO_Dutify class NOT found\n";
    }
    
    // Check all taxonomies
    echo "\n2. All Registered Taxonomies:\n";
    $all_taxonomies = get_taxonomies();
    foreach ($all_taxonomies as $tax) {
        if (strpos($tax, 'dutify') !== false || strpos($tax, 'pa_dutify') !== false) {
            echo "   - $tax\n";
        }
    }
    
    // Check specific Dutify taxonomies
    echo "\n3. Dutify Taxonomies:\n";
    $dutify_taxonomies = [
        'pa_dutify_hs_code',
        'pa_dutify_country_origin', 
        'pa_dutify_hs_code_country',
        'pa_dutify_class_id'
    ];
    
    foreach ($dutify_taxonomies as $tax) {
        if (taxonomy_exists($tax)) {
            echo "   ✅ $tax EXISTS\n";
            $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                echo "      Terms count: " . count($terms) . "\n";
                if (count($terms) > 0 && count($terms) < 10) {
                    foreach ($terms as $term) {
                        echo "      - " . $term->name . "\n";
                    }
                }
            }
        } else {
            echo "   ❌ $tax NOT FOUND\n";
        }
    }
    
    // Check WooCommerce attributes
    echo "\n4. WooCommerce Attributes:\n";
    if (function_exists('wc_get_attribute_taxonomies')) {
        $attributes = wc_get_attribute_taxonomies();
        foreach ($attributes as $attr) {
            if (strpos($attr->attribute_name, 'dutify') !== false) {
                echo "   - " . $attr->attribute_name . " (ID: " . $attr->attribute_id . ", Taxonomy: pa_" . $attr->attribute_name . ")\n";
            }
        }
        
        if (empty($attributes)) {
            echo "   No attributes found at all\n";
        }
    } else {
        echo "   WooCommerce functions not available\n";
    }
    
    // Check when init hooks run
    echo "\n5. Current Action Hook:\n";
    echo "   " . current_action() . "\n";
    
    echo "\n6. Priority Check:\n";
    echo "   This is running at init priority 10\n";
    echo "   Dutify plugin init runs at priority 0\n";
    echo "   Taxonomies should be registered by now\n";
    
    echo '</pre>';
    
    // Try to manually trigger Dutify init if needed
    if (class_exists('WOO_Dutify') && function_exists('WOO_DUTIFY')) {
        $dutify = WOO_DUTIFY();
        if (method_exists($dutify, 'init')) {
            echo '<pre>';
            echo "\n7. Manually triggering Dutify init()...\n";
            $dutify->init();
            echo "   Done. Re-check taxonomies:\n";
            
            foreach ($dutify_taxonomies as $tax) {
                if (taxonomy_exists($tax)) {
                    echo "   ✅ $tax NOW EXISTS\n";
                } else {
                    echo "   ❌ $tax STILL NOT FOUND\n";
                }
            }
            echo '</pre>';
        }
    }
    
    die();
}, 10);

// Add link to admin
add_action('admin_notices', function() {
    if (current_user_can('manage_options')) {
        $check_url = add_query_arg('check_dutify', '1', home_url());
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p>Dutify Debug: <a href="' . esc_url($check_url) . '" target="_blank">Check Dutify Taxonomies</a></p>';
        echo '</div>';
    }
});