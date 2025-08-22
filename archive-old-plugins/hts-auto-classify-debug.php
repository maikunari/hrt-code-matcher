<?php
/**
 * Plugin Name: HTS Auto-Classifier for New Products (Debug Version)
 * Description: Automatically classifies new products with HTS codes when published - WITH DEBUG LOGGING
 * Version: 1.0.1
 * Author: Mike Sewell
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Debug logging function
function hts_debug_log($message) {
    if (WP_DEBUG === true) {
        error_log('[HTS-AUTO] ' . $message);
    }
    // Also log to a custom file for easier tracking
    $log_file = WP_CONTENT_DIR . '/hts-auto-classify-debug.log';
    $timestamp = current_time('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// Hook into product publish/update
add_action('transition_post_status', 'hts_auto_classify_on_publish', 10, 3);
function hts_auto_classify_on_publish($new_status, $old_status, $post) {
    hts_debug_log("transition_post_status fired: old=$old_status, new=$new_status, type={$post->post_type}, id={$post->ID}");
    
    // Check if auto-classification is enabled
    $enabled = get_option('hts_auto_classify_enabled', '1');
    if ($enabled !== '1') {
        hts_debug_log("Auto-classification is disabled in settings");
        return;
    }
    
    // Only process products
    if ($post->post_type !== 'product') {
        return;
    }
    
    // Only process when publishing a new product or updating from draft to publish
    if ($new_status !== 'publish') {
        hts_debug_log("Not a publish event, skipping");
        return;
    }
    
    // Check if product already has HTS code
    $existing_hts = get_post_meta($post->ID, '_hts_code', true);
    hts_debug_log("Existing HTS code for product {$post->ID}: " . ($existing_hts ?: 'none'));
    
    if (!empty($existing_hts) && $existing_hts !== '9999.99.9999') {
        hts_debug_log("Product {$post->ID} already has HTS code: $existing_hts");
        return;
    }
    
    // Schedule classification (don't block the publish process)
    $scheduled = wp_schedule_single_event(time() + 5, 'hts_classify_product', array($post->ID));
    hts_debug_log("Scheduled classification for product {$post->ID}: " . ($scheduled ? 'success' : 'failed'));
}

// Handle the scheduled classification
add_action('hts_classify_product', 'hts_run_classification');
function hts_run_classification($product_id) {
    hts_debug_log("Starting classification for product ID: $product_id");
    
    // Get product data
    $product = wc_get_product($product_id);
    if (!$product) {
        hts_debug_log("Could not load product $product_id");
        return;
    }
    
    // Get configuration from WordPress options
    $anthropic_api_key = get_option('hts_anthropic_api_key');
    if (empty($anthropic_api_key)) {
        hts_debug_log("No API key configured!");
        return;
    }
    
    hts_debug_log("API key found (length: " . strlen($anthropic_api_key) . ")");
    
    // Prepare product data
    $product_data = array(
        'name' => $product->get_name(),
        'description' => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'sku' => $product->get_sku(),
        'categories' => wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names')),
        'tags' => wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names')),
        'price' => $product->get_price(),
        'weight' => $product->get_weight(),
    );
    
    hts_debug_log("Product data prepared for: " . $product_data['name']);
    
    // Build prompt for Claude
    $prompt = "You are an expert in Harmonized Tariff Schedule (HTS) classification for US imports. 
Analyze this product and provide the most accurate 10-digit HTS code.

PRODUCT INFORMATION:
Name: {$product_data['name']}
SKU: {$product_data['sku']}
Description: " . substr($product_data['description'], 0, 1000) . "
Categories: " . implode(', ', $product_data['categories']) . "

IMPORTANT RULES:
1. Provide the full 10-digit HTS code (format: ####.##.####)
2. Consider the product's primary function and material composition
3. Use the most specific classification available
4. If uncertain between codes, choose the one with higher duty rate (conservative approach)

Respond in this exact JSON format:
{
    \"hts_code\": \"####.##.####\",
    \"confidence\": 0.0 to 1.0,
    \"reasoning\": \"Brief explanation\"
}";
    
    hts_debug_log("Calling Claude API...");
    
    // Call Claude API
    $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'x-api-key' => $anthropic_api_key,
            'anthropic-version' => '2023-06-01',
        ),
        'body' => json_encode(array(
            'model' => 'claude-3-7-sonnet-20250219',
            'max_tokens' => 500,
            'temperature' => 0.2,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            )
        )),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        hts_debug_log("API call failed: " . $response->get_error_message());
        return;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    hts_debug_log("API response code: $response_code");
    
    $body = wp_remote_retrieve_body($response);
    hts_debug_log("API response (first 500 chars): " . substr($body, 0, 500));
    
    $data = json_decode($body, true);
    
    if (isset($data['content'][0]['text'])) {
        $response_text = $data['content'][0]['text'];
        hts_debug_log("Claude response text: " . substr($response_text, 0, 200));
        
        // Extract JSON from response
        if (preg_match('/\{.*\}/s', $response_text, $matches)) {
            $result = json_decode($matches[0], true);
            
            if (isset($result['hts_code']) && preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $result['hts_code'])) {
                // Save HTS code
                update_post_meta($product_id, '_hts_code', $result['hts_code']);
                update_post_meta($product_id, '_hts_confidence', $result['confidence']);
                update_post_meta($product_id, '_hts_updated', current_time('mysql'));
                update_post_meta($product_id, '_country_of_origin', 'CA'); // Default to Canada
                
                hts_debug_log("SUCCESS: Product {$product_id} classified as {$result['hts_code']} (confidence: {$result['confidence']})");
                
                // Send admin notification if confidence is low
                if ($result['confidence'] < 0.60) {
                    hts_notify_admin_low_confidence($product_id, $result);
                }
            } else {
                hts_debug_log("Invalid HTS code format in response: " . print_r($result, true));
            }
        } else {
            hts_debug_log("Could not extract JSON from response");
        }
    } else {
        hts_debug_log("Unexpected API response structure: " . print_r($data, true));
    }
}

// Admin notification for low confidence classifications
function hts_notify_admin_low_confidence($product_id, $result) {
    $product = wc_get_product($product_id);
    $admin_email = get_option('admin_email');
    
    $subject = 'HTS Classification Needs Review';
    $message = "A product was automatically classified with low confidence:\n\n";
    $message .= "Product: {$product->get_name()}\n";
    $message .= "SKU: {$product->get_sku()}\n";
    $message .= "HTS Code: {$result['hts_code']}\n";
    $message .= "Confidence: " . ($result['confidence'] * 100) . "%\n";
    $message .= "Reasoning: {$result['reasoning']}\n\n";
    $message .= "Please review: " . get_edit_post_link($product_id);
    
    wp_mail($admin_email, $subject, $message);
}

// Add settings page
add_action('admin_menu', 'hts_auto_classifier_menu');
function hts_auto_classifier_menu() {
    add_submenu_page(
        'woocommerce',
        'HTS Auto-Classifier Settings',
        'HTS Auto-Classifier',
        'manage_woocommerce',
        'hts-auto-classifier',
        'hts_auto_classifier_settings_page'
    );
}

function hts_auto_classifier_settings_page() {
    // Save settings
    if (isset($_POST['submit']) && wp_verify_nonce($_POST['hts_nonce'], 'hts_settings')) {
        update_option('hts_anthropic_api_key', sanitize_text_field($_POST['api_key']));
        update_option('hts_auto_classify_enabled', isset($_POST['enabled']) ? '1' : '0');
        update_option('hts_confidence_threshold', floatval($_POST['confidence_threshold']));
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    // Handle test classification
    if (isset($_POST['test_classify']) && wp_verify_nonce($_POST['hts_test_nonce'], 'hts_test')) {
        $test_product_id = intval($_POST['test_product_id']);
        if ($test_product_id > 0) {
            echo '<div class="notice notice-info"><p>Starting test classification for product ID: ' . $test_product_id . '</p></div>';
            
            // Run classification immediately (not scheduled)
            hts_run_classification($test_product_id);
            
            // Check if it worked
            $hts_code = get_post_meta($test_product_id, '_hts_code', true);
            if ($hts_code) {
                echo '<div class="notice notice-success"><p>✓ Classification successful! HTS Code: ' . $hts_code . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>✗ Classification failed. Check the debug log at: ' . WP_CONTENT_DIR . '/hts-auto-classify-debug.log</p></div>';
            }
        }
    }
    
    $api_key = get_option('hts_anthropic_api_key', '');
    $enabled = get_option('hts_auto_classify_enabled', '1');
    $threshold = get_option('hts_confidence_threshold', 0.60);
    
    // Check WordPress cron
    $next_cron = wp_next_scheduled('hts_classify_product');
    ?>
    <div class="wrap">
        <h1>HTS Auto-Classifier Settings</h1>
        
        <?php if (empty($api_key)): ?>
        <div class="notice notice-warning">
            <p><strong>⚠️ No API key configured!</strong> Please add your Anthropic API key below.</p>
        </div>
        <?php endif; ?>
        
        <form method="post">
            <?php wp_nonce_field('hts_settings', 'hts_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Enable Auto-Classification</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($enabled, '1'); ?>>
                            Automatically classify new products when published
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Anthropic API Key</th>
                    <td>
                        <input type="password" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                        <p class="description">Your Claude API key from Anthropic</p>
                        <?php if (!empty($api_key)): ?>
                        <p class="description" style="color: green;">✓ API key is configured (<?php echo strlen($api_key); ?> characters)</p>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Low Confidence Threshold</th>
                    <td>
                        <input type="number" name="confidence_threshold" value="<?php echo esc_attr($threshold); ?>" min="0" max="1" step="0.05">
                        <p class="description">Send email notification if confidence is below this threshold (0.60 = 60%)</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="Save Settings">
            </p>
        </form>
        
        <hr>
        
        <h2>Test Classification</h2>
        <form method="post">
            <?php wp_nonce_field('hts_test', 'hts_test_nonce'); ?>
            <p>Test the classifier on a specific product:</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Product ID</th>
                    <td>
                        <input type="number" name="test_product_id" placeholder="Enter product ID">
                        <input type="submit" name="test_classify" class="button" value="Test Classification">
                        <p class="description">Enter a product ID to test classification immediately</p>
                    </td>
                </tr>
            </table>
        </form>
        
        <hr>
        
        <h2>Debug Information</h2>
        <table class="widefat">
            <tr>
                <th>Setting</th>
                <th>Value</th>
            </tr>
            <tr>
                <td>Auto-Classification Enabled</td>
                <td><?php echo $enabled === '1' ? '✓ Yes' : '✗ No'; ?></td>
            </tr>
            <tr>
                <td>API Key Configured</td>
                <td><?php echo !empty($api_key) ? '✓ Yes' : '✗ No'; ?></td>
            </tr>
            <tr>
                <td>WordPress Cron</td>
                <td><?php echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? '⚠️ Disabled (may affect scheduling)' : '✓ Enabled'; ?></td>
            </tr>
            <tr>
                <td>Next Scheduled Classification</td>
                <td><?php echo $next_cron ? date('Y-m-d H:i:s', $next_cron) : 'None scheduled'; ?></td>
            </tr>
            <tr>
                <td>Debug Log Location</td>
                <td><?php echo WP_CONTENT_DIR . '/hts-auto-classify-debug.log'; ?></td>
            </tr>
        </table>
        
        <hr>
        
        <h2>Recent Products Without HTS Codes</h2>
        <?php
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 10,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_hts_code',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_hts_code',
                    'value' => '',
                    'compare' => '='
                ),
                array(
                    'key' => '_hts_code',
                    'value' => '9999.99.9999',
                    'compare' => '='
                )
            )
        );
        
        $products = get_posts($args);
        
        if ($products) {
            echo '<table class="widefat">';
            echo '<thead><tr><th>ID</th><th>Product Name</th><th>SKU</th><th>Status</th></tr></thead>';
            echo '<tbody>';
            foreach ($products as $product_post) {
                $product = wc_get_product($product_post->ID);
                echo '<tr>';
                echo '<td>' . $product_post->ID . '</td>';
                echo '<td><a href="' . get_edit_post_link($product_post->ID) . '">' . $product->get_name() . '</a></td>';
                echo '<td>' . ($product->get_sku() ?: 'N/A') . '</td>';
                echo '<td>' . $product_post->post_status . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>✓ All products have HTS codes!</p>';
        }
        ?>
        
        <hr>
        
        <h2>How It Works</h2>
        <ol>
            <li>When a new product is published, it's automatically queued for classification</li>
            <li>The plugin calls Claude API to determine the HTS code</li>
            <li>The HTS code is saved to the product</li>
            <li>If confidence is low, an email notification is sent</li>
        </ol>
    </div>
    <?php
}

// Add bulk action to classify selected products
add_filter('bulk_actions-edit-product', 'hts_add_bulk_classify');
function hts_add_bulk_classify($bulk_actions) {
    $bulk_actions['hts_classify'] = __('Classify HTS Codes', 'hts-display');
    return $bulk_actions;
}

add_filter('handle_bulk_actions-edit-product', 'hts_handle_bulk_classify', 10, 3);
function hts_handle_bulk_classify($redirect_to, $action, $post_ids) {
    if ($action !== 'hts_classify') {
        return $redirect_to;
    }
    
    foreach ($post_ids as $post_id) {
        // Schedule classification for each product
        wp_schedule_single_event(time() + rand(5, 30), 'hts_classify_product', array($post_id));
        hts_debug_log("Bulk action: Scheduled classification for product $post_id");
    }
    
    $redirect_to = add_query_arg('hts_classified', count($post_ids), $redirect_to);
    return $redirect_to;
}

// Show admin notice for bulk classification
add_action('admin_notices', 'hts_bulk_classify_notice');
function hts_bulk_classify_notice() {
    if (!empty($_REQUEST['hts_classified'])) {
        $count = intval($_REQUEST['hts_classified']);
        printf(
            '<div class="notice notice-success is-dismissible"><p>' . 
            _n('Queued %s product for HTS classification.', 'Queued %s products for HTS classification.', $count, 'hts-display') . 
            '</p></div>',
            $count
        );
    }
}