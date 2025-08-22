<?php
/**
 * Plugin Name: HTS Auto-Classifier for New Products
 * Description: Automatically classifies new products with HTS codes when published
 * Version: 1.0.0
 * Author: Mike Sewell
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Hook into product publish/update
add_action('transition_post_status', 'hts_auto_classify_on_publish', 10, 3);
function hts_auto_classify_on_publish($new_status, $old_status, $post) {
    // Only process products
    if ($post->post_type !== 'product') {
        return;
    }
    
    // Only process when publishing a new product or updating from draft to publish
    if ($new_status !== 'publish') {
        return;
    }
    
    // Check if product already has HTS code
    $existing_hts = get_post_meta($post->ID, '_hts_code', true);
    if (!empty($existing_hts) && $existing_hts !== '9999.99.9999') {
        return; // Already classified
    }
    
    // Schedule classification (don't block the publish process)
    wp_schedule_single_event(time() + 5, 'hts_classify_product', array($post->ID));
}

// Handle the scheduled classification
add_action('hts_classify_product', 'hts_run_classification');
function hts_run_classification($product_id) {
    // Get product data
    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }
    
    // Get configuration from WordPress options
    $anthropic_api_key = get_option('hts_anthropic_api_key');
    if (empty($anthropic_api_key)) {
        error_log('HTS Auto-Classifier: No API key configured');
        return;
    }
    
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
        error_log('HTS Auto-Classifier: API call failed - ' . $response->get_error_message());
        return;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['content'][0]['text'])) {
        $response_text = $data['content'][0]['text'];
        
        // Extract JSON from response
        if (preg_match('/\{.*\}/s', $response_text, $matches)) {
            $result = json_decode($matches[0], true);
            
            if (isset($result['hts_code']) && preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $result['hts_code'])) {
                // Save HTS code
                update_post_meta($product_id, '_hts_code', $result['hts_code']);
                update_post_meta($product_id, '_hts_confidence', $result['confidence']);
                update_post_meta($product_id, '_hts_updated', current_time('mysql'));
                update_post_meta($product_id, '_country_of_origin', 'CA'); // Default to Canada
                
                // Log success
                error_log("HTS Auto-Classifier: Product {$product_id} classified as {$result['hts_code']} (confidence: {$result['confidence']})");
                
                // Send admin notification if confidence is low
                if ($result['confidence'] < 0.60) {
                    hts_notify_admin_low_confidence($product_id, $result);
                }
            }
        }
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
    
    $api_key = get_option('hts_anthropic_api_key', '');
    $enabled = get_option('hts_auto_classify_enabled', '1');
    $threshold = get_option('hts_confidence_threshold', 0.60);
    ?>
    <div class="wrap">
        <h1>HTS Auto-Classifier Settings</h1>
        
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
        
        <h2>How It Works</h2>
        <ol>
            <li>When a new product is published, it's automatically queued for classification</li>
            <li>The plugin calls Claude API to determine the HTS code</li>
            <li>The HTS code is saved to the product</li>
            <li>If confidence is low, an email notification is sent</li>
        </ol>
        
        <h2>Manual Classification</h2>
        <p>To manually trigger classification for a product:</p>
        <pre>wp_schedule_single_event(time(), 'hts_classify_product', array($product_id));</pre>
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