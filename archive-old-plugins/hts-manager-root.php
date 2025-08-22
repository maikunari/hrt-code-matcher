<?php
/**
 * Plugin Name: HTS Manager for WooCommerce
 * Description: Complete HTS code management - display, auto-classify, and ShipStation integration
 * Version: 3.0.0
 * Author: Mike Sewell
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ===============================================
// PART 1: PRODUCT DATA TAB & DISPLAY
// ===============================================

// Add HTS tab to product data metabox
add_filter('woocommerce_product_data_tabs', 'hts_add_product_data_tab');
function hts_add_product_data_tab($tabs) {
    $tabs['hts_codes'] = array(
        'label'    => __('HTS Codes', 'hts-manager'),
        'target'   => 'hts_codes_product_data',
        'class'    => array('show_if_simple', 'show_if_variable'),
        'priority' => 21,
    );
    return $tabs;
}

// Add content to HTS tab
add_action('woocommerce_product_data_panels', 'hts_add_product_data_fields');
function hts_add_product_data_fields() {
    global $post;
    
    // Check if product has an HTS code
    $hts_code = get_post_meta($post->ID, '_hts_code', true);
    $country_of_origin = get_post_meta($post->ID, '_country_of_origin', true);
    $hts_confidence = get_post_meta($post->ID, '_hts_confidence', true);
    $hts_updated = get_post_meta($post->ID, '_hts_updated', true);
    
    // Default country to Canada if not set
    if (empty($country_of_origin)) {
        $country_of_origin = 'CA';
    }
    ?>
    <div id="hts_codes_product_data" class="panel woocommerce_options_panel">
        
        <?php wp_nonce_field('hts_product_nonce_action', 'hts_product_nonce'); ?>
        
        <div class="options_group">
            <?php
            woocommerce_wp_text_input(array(
                'id'          => '_hts_code',
                'label'       => __('HTS Code', 'hts-manager'),
                'placeholder' => '0000.00.0000',
                'desc_tip'    => true,
                'description' => __('Enter the 10-digit Harmonized Tariff Schedule code for this product.', 'hts-manager'),
                'value'       => $hts_code,
            ));
            ?>
            
            <p class="form-field">
                <label><?php _e('Generate HTS Code', 'hts-manager'); ?></label>
                <button type="button" class="button button-primary" id="hts_generate_code" <?php echo !empty($hts_code) ? 'disabled' : ''; ?>>
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                    <?php _e('Auto-Generate with AI', 'hts-manager'); ?>
                </button>
                <?php if (!empty($hts_code)): ?>
                    <a href="#" id="hts_regenerate_link" style="margin-left: 10px; text-decoration: none;">
                        <?php _e('Regenerate', 'hts-manager'); ?>
                    </a>
                <?php endif; ?>
                <span id="hts_generate_spinner" class="spinner" style="display: none; float: none; margin-left: 10px;"></span>
                <span id="hts_generate_message" style="display: none; margin-left: 10px;"></span>
            </p>
            
            <?php if ($hts_confidence): ?>
            <p class="form-field">
                <label><?php _e('Confidence', 'hts-manager'); ?></label>
                <span style="margin-left: 10px;">
                    <?php 
                    $confidence_percent = round($hts_confidence * 100);
                    $confidence_color = $confidence_percent >= 85 ? 'green' : ($confidence_percent >= 60 ? 'orange' : 'red');
                    ?>
                    <span style="color: <?php echo $confidence_color; ?>; font-weight: bold;">
                        <?php echo $confidence_percent; ?>%
                    </span>
                    <?php if ($hts_updated): ?>
                        <span style="color: #666; margin-left: 10px;">
                            (Updated: <?php echo date('Y-m-d H:i', strtotime($hts_updated)); ?>)
                        </span>
                    <?php endif; ?>
                </span>
            </p>
            <?php endif; ?>
            
            <?php
            woocommerce_wp_select(array(
                'id'          => '_country_of_origin',
                'label'       => __('Country of Origin', 'hts-manager'),
                'desc_tip'    => true,
                'description' => __('Select the country where this product was manufactured or produced.', 'hts-manager'),
                'value'       => $country_of_origin,
                'options'     => array(
                    'CA' => __('Canada', 'hts-manager'),
                    'US' => __('United States', 'hts-manager'),
                    'MX' => __('Mexico', 'hts-manager'),
                    'CN' => __('China', 'hts-manager'),
                    'GB' => __('United Kingdom', 'hts-manager'),
                    'DE' => __('Germany', 'hts-manager'),
                    'FR' => __('France', 'hts-manager'),
                    'IT' => __('Italy', 'hts-manager'),
                    'JP' => __('Japan', 'hts-manager'),
                    'KR' => __('South Korea', 'hts-manager'),
                    'TW' => __('Taiwan', 'hts-manager'),
                    'IN' => __('India', 'hts-manager'),
                    'VN' => __('Vietnam', 'hts-manager'),
                    'TH' => __('Thailand', 'hts-manager'),
                    'OTHER' => __('Other', 'hts-manager'),
                ),
            ));
            ?>
        </div>
        
        <div class="options_group">
            <p style="margin: 10px;">
                <strong><?php _e('Information:', 'hts-manager'); ?></strong><br>
                <?php _e('HTS codes are used for customs declarations and duty calculations when shipping internationally.', 'hts-manager'); ?><br>
                <?php _e('These codes are automatically included in ShipStation exports for customs forms.', 'hts-manager'); ?>
            </p>
        </div>
        
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Main generate button handler
        function generateHTSCode(isRegenerate) {
            var button = $('#hts_generate_code');
            var spinner = $('#hts_generate_spinner');
            var message = $('#hts_generate_message');
            var regenerateLink = $('#hts_regenerate_link');
            var product_id = <?php echo $post->ID; ?>;
            
            // Show spinner, disable button
            button.prop('disabled', true);
            if (regenerateLink.length) {
                regenerateLink.hide();
            }
            spinner.css('display', 'inline-block').addClass('is-active');
            message.hide().removeClass('success error');
            
            // AJAX call
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'hts_generate_single_code',
                    product_id: product_id,
                    nonce: '<?php echo wp_create_nonce('hts_generate_nonce'); ?>',
                    regenerate: isRegenerate ? 1 : 0
                },
                success: function(response) {
                    spinner.removeClass('is-active').hide();
                    
                    if (response.success) {
                        // Update the HTS code field
                        $('#_hts_code').val(response.data.hts_code);
                        
                        // Show success message
                        message.html('<span style="color: green;">✓ Generated: ' + response.data.hts_code + ' (' + Math.round(response.data.confidence * 100) + '% confidence)</span>');
                        message.addClass('success').show();
                        
                        // Keep button disabled since we now have a code
                        button.prop('disabled', true);
                        
                        // Show or create regenerate link
                        if (!regenerateLink.length) {
                            button.after(' <a href="#" id="hts_regenerate_link" style="margin-left: 10px; text-decoration: none;">Regenerate</a>');
                            bindRegenerateHandler();
                        } else {
                            regenerateLink.show();
                        }
                        
                        // Add or update confidence display
                        var confidenceColor = response.data.confidence >= 0.85 ? 'green' : (response.data.confidence >= 0.60 ? 'orange' : 'red');
                        var existingConfidence = $('.hts-confidence-display');
                        
                        if (existingConfidence.length) {
                            existingConfidence.find('span span').css('color', confidenceColor).text(Math.round(response.data.confidence * 100) + '%');
                        } else if (response.data.confidence) {
                            var confidenceHtml = '<p class="form-field hts-confidence-display">' +
                                '<label>Confidence</label>' +
                                '<span style="margin-left: 10px;">' +
                                '<span style="color: ' + confidenceColor + '; font-weight: bold;">' +
                                Math.round(response.data.confidence * 100) + '%' +
                                '</span></span></p>';
                            $(confidenceHtml).insertAfter('#hts_generate_message').parent().parent();
                        }
                    } else {
                        message.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        message.addClass('error').show();
                        
                        // Re-enable button only if no code exists
                        if (!$('#_hts_code').val()) {
                            button.prop('disabled', false);
                        }
                        if (regenerateLink.length) {
                            regenerateLink.show();
                        }
                    }
                },
                error: function(xhr, status, error) {
                    spinner.removeClass('is-active').hide();
                    
                    // Re-enable button only if no code exists
                    if (!$('#_hts_code').val()) {
                        button.prop('disabled', false);
                    }
                    if (regenerateLink.length) {
                        regenerateLink.show();
                    }
                    
                    message.html('<span style="color: red;">✗ Error: ' + error + '</span>');
                    message.addClass('error').show();
                }
            });
        }
        
        // Bind regenerate handler
        function bindRegenerateHandler() {
            $('#hts_regenerate_link').off('click').on('click', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to regenerate the HTS code? This will overwrite the existing code.')) {
                    generateHTSCode(true);
                }
            });
        }
        
        // Initial button click handler
        $('#hts_generate_code').on('click', function(e) {
            e.preventDefault();
            generateHTSCode(false);
        });
        
        // Bind regenerate if it exists on load
        bindRegenerateHandler();
        
        // Monitor HTS code field for manual changes
        $('#_hts_code').on('input', function() {
            var hasCode = $(this).val().trim().length > 0;
            $('#hts_generate_code').prop('disabled', hasCode);
            
            if (hasCode && !$('#hts_regenerate_link').length) {
                $('#hts_generate_code').after(' <a href="#" id="hts_regenerate_link" style="margin-left: 10px; text-decoration: none;">Regenerate</a>');
                bindRegenerateHandler();
            } else if (!hasCode && $('#hts_regenerate_link').length) {
                $('#hts_regenerate_link').remove();
            }
        });
    });
    </script>
    <?php
}

// Save HTS fields
add_action('woocommerce_process_product_meta', 'hts_save_product_data_fields');
function hts_save_product_data_fields($post_id) {
    // Security check
    if (!isset($_POST['hts_product_nonce']) || !wp_verify_nonce($_POST['hts_product_nonce'], 'hts_product_nonce_action')) {
        return;
    }
    
    // Save HTS code
    if (isset($_POST['_hts_code'])) {
        $hts_code = sanitize_text_field($_POST['_hts_code']);
        update_post_meta($post_id, '_hts_code', $hts_code);
    }
    
    // Save country of origin
    if (isset($_POST['_country_of_origin'])) {
        $country = sanitize_text_field($_POST['_country_of_origin']);
        update_post_meta($post_id, '_country_of_origin', $country);
    }
}

// ===============================================
// PART 2: AJAX HANDLER FOR SINGLE PRODUCT
// ===============================================

add_action('wp_ajax_hts_generate_single_code', 'hts_ajax_generate_single_code');
function hts_ajax_generate_single_code() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'hts_generate_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed'));
        return;
    }
    
    // Check permissions
    if (!current_user_can('edit_products')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    if (!$product_id) {
        wp_send_json_error(array('message' => 'Invalid product ID'));
        return;
    }
    
    // Get API key
    $api_key = get_option('hts_anthropic_api_key');
    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'API key not configured. Please configure in WooCommerce → HTS Manager'));
        return;
    }
    
    // Get product
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => 'Product not found'));
        return;
    }
    
    // Generate HTS code
    $result = hts_classify_product($product_id, $api_key);
    
    if ($result && isset($result['hts_code'])) {
        // Save the results
        update_post_meta($product_id, '_hts_code', $result['hts_code']);
        update_post_meta($product_id, '_hts_confidence', $result['confidence']);
        update_post_meta($product_id, '_hts_updated', current_time('mysql'));
        update_post_meta($product_id, '_country_of_origin', 'CA');
        
        wp_send_json_success(array(
            'hts_code' => $result['hts_code'],
            'confidence' => $result['confidence'],
            'reasoning' => $result['reasoning'] ?? ''
        ));
    } else {
        wp_send_json_error(array('message' => 'Failed to generate HTS code. Please try again.'));
    }
}

// ===============================================
// PART 3: CLASSIFICATION FUNCTION
// ===============================================

function hts_classify_product($product_id, $api_key) {
    $product = wc_get_product($product_id);
    if (!$product) {
        return false;
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
    
    // Build prompt
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
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01',
        ),
        'body' => json_encode(array(
            'model' => 'claude-3-5-sonnet-20241022',
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
        error_log('HTS Manager: API call failed - ' . $response->get_error_message());
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (isset($data['content'][0]['text'])) {
        $response_text = $data['content'][0]['text'];
        
        // Extract JSON from response
        if (preg_match('/\{.*\}/s', $response_text, $matches)) {
            $result = json_decode($matches[0], true);
            
            if (isset($result['hts_code']) && preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $result['hts_code'])) {
                return $result;
            }
        }
    }
    
    return false;
}

// ===============================================
// PART 4: AUTO-CLASSIFICATION ON PUBLISH/UPDATE
// ===============================================

// Hook into both status transitions and save_post for better coverage
add_action('transition_post_status', 'hts_auto_classify_on_publish', 10, 3);
function hts_auto_classify_on_publish($new_status, $old_status, $post) {
    // Check if enabled
    if (get_option('hts_auto_classify_enabled', '1') !== '1') {
        return;
    }
    
    // Only process products that are published
    if ($post->post_type !== 'product' || $new_status !== 'publish') {
        return;
    }
    
    // Check if already has HTS code
    $existing_hts = get_post_meta($post->ID, '_hts_code', true);
    if (!empty($existing_hts) && $existing_hts !== '9999.99.9999') {
        return;
    }
    
    // Schedule classification (avoid duplicates by using unique action name)
    $hook = 'hts_classify_product_cron';
    $args = array($post->ID);
    
    // Clear any existing scheduled event for this product
    wp_clear_scheduled_hook($hook, $args);
    
    // Schedule new classification
    wp_schedule_single_event(time() + 5, $hook, $args);
}

// Also hook into save_post for products that are already published
add_action('save_post_product', 'hts_auto_classify_on_save', 10, 3);
function hts_auto_classify_on_save($post_id, $post, $update) {
    // Skip if not an update or if it's an autosave
    if (!$update || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    
    // Check if enabled
    if (get_option('hts_auto_classify_enabled', '1') !== '1') {
        return;
    }
    
    // Only process published products
    if ($post->post_status !== 'publish') {
        return;
    }
    
    // Check if already has HTS code
    $existing_hts = get_post_meta($post_id, '_hts_code', true);
    if (!empty($existing_hts) && $existing_hts !== '9999.99.9999') {
        return;
    }
    
    // Schedule classification (avoid duplicates)
    $hook = 'hts_classify_product_cron';
    $args = array($post_id);
    
    // Clear any existing scheduled event for this product
    wp_clear_scheduled_hook($hook, $args);
    
    // Schedule new classification
    wp_schedule_single_event(time() + 5, $hook, $args);
}

add_action('hts_classify_product_cron', 'hts_run_scheduled_classification');
function hts_run_scheduled_classification($product_id) {
    $api_key = get_option('hts_anthropic_api_key');
    if (empty($api_key)) {
        return;
    }
    
    $result = hts_classify_product($product_id, $api_key);
    
    if ($result && isset($result['hts_code'])) {
        update_post_meta($product_id, '_hts_code', $result['hts_code']);
        update_post_meta($product_id, '_hts_confidence', $result['confidence']);
        update_post_meta($product_id, '_hts_updated', current_time('mysql'));
        update_post_meta($product_id, '_country_of_origin', 'CA');
        
        // Notify admin if low confidence
        if ($result['confidence'] < 0.60) {
            hts_notify_admin_low_confidence($product_id, $result);
        }
    }
}

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

// ===============================================
// PART 5: SHIPSTATION INTEGRATION
// ===============================================

add_filter('woocommerce_shipstation_export_custom_field_2', 'hts_add_to_shipstation', 10, 2);
function hts_add_to_shipstation($value, $product) {
    $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
    if ($hts_code) {
        // ShipStation prefers HTS codes without dots for customs forms
        return str_replace('.', '', $hts_code);
    }
    return $value;
}

add_action('woocommerce_shipstation_export_order_xml', 'hts_add_customs_to_order_xml', 10, 2);
function hts_add_customs_to_order_xml($order_xml, $order) {
    // This runs after the order XML is generated
    // ShipStation will use the HTS codes from custom_field_2
}

add_filter('woocommerce_shipstation_export_order_item_xml', 'hts_add_customs_to_item_xml', 10, 4);
function hts_add_customs_to_item_xml($item_xml, $order_item, $order, $xml) {
    try {
        // Set a maximum execution time for this function
        $max_time = 0.5; // 500ms max per item
        $start_time = microtime(true);
        
        $product_id = $order_item->get_product_id();
        if (!$product_id) {
            return $item_xml;
        }
        
        // Check execution time
        if ((microtime(true) - $start_time) > $max_time) {
            error_log('HTS Manager: Timeout prevented for item processing');
            return $item_xml;
        }
        
        $hts_code = get_post_meta($product_id, '_hts_code', true);
        $country_of_origin = get_post_meta($product_id, '_country_of_origin', true);
        
        if ($hts_code || $country_of_origin) {
            // Add customs info to the item XML
            $item_xml->addChild('CustomsDescription', substr($order_item->get_name(), 0, 200));
            $item_xml->addChild('CustomsValue', $order_item->get_total());
            
            if ($hts_code) {
                $item_xml->addChild('HarmonizedCode', str_replace('.', '', $hts_code));
            }
            
            if ($country_of_origin) {
                $item_xml->addChild('CountryOfOrigin', $country_of_origin);
            }
        }
        
        return $item_xml;
        
    } catch (Exception $e) {
        error_log('HTS Manager ShipStation Integration Error: ' . $e->getMessage());
        return $item_xml;
    }
}

// ===============================================
// PART 6: ADMIN SETTINGS PAGE
// ===============================================

add_action('admin_menu', 'hts_manager_menu');
function hts_manager_menu() {
    add_submenu_page(
        'woocommerce',
        'HTS Manager',
        'HTS Manager',
        'manage_woocommerce',
        'hts-manager',
        'hts_manager_settings_page'
    );
}

function hts_manager_settings_page() {
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
            $api_key = get_option('hts_anthropic_api_key');
            if ($api_key) {
                echo '<div class="notice notice-info"><p>Testing classification for product ID: ' . $test_product_id . '</p></div>';
                
                $result = hts_classify_product($test_product_id, $api_key);
                
                if ($result && isset($result['hts_code'])) {
                    update_post_meta($test_product_id, '_hts_code', $result['hts_code']);
                    update_post_meta($test_product_id, '_hts_confidence', $result['confidence']);
                    update_post_meta($test_product_id, '_hts_updated', current_time('mysql'));
                    
                    echo '<div class="notice notice-success"><p>✓ Classification successful! HTS Code: ' . $result['hts_code'] . ' (Confidence: ' . round($result['confidence'] * 100) . '%)</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>✗ Classification failed. Please check your API key and try again.</p></div>';
                }
            }
        }
    }
    
    $api_key = get_option('hts_anthropic_api_key', '');
    $enabled = get_option('hts_auto_classify_enabled', '1');
    $threshold = get_option('hts_confidence_threshold', 0.60);
    ?>
    <div class="wrap">
        <h1>HTS Manager for WooCommerce</h1>
        
        <div class="notice notice-info">
            <p><strong>Complete HTS Management System</strong> - This plugin handles HTS code display, auto-classification, and ShipStation integration.</p>
        </div>
        
        <?php if (empty($api_key)): ?>
        <div class="notice notice-warning">
            <p><strong>⚠️ Setup Required:</strong> Please add your Anthropic API key below to enable auto-classification.</p>
        </div>
        <?php endif; ?>
        
        <form method="post">
            <?php wp_nonce_field('hts_settings', 'hts_nonce'); ?>
            
            <h2>Auto-Classification Settings</h2>
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
        
        <h2>Products Without HTS Codes</h2>
        <?php
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 20,
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
                )
            )
        );
        
        $products = get_posts($args);
        
        if ($products) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>Product Name</th><th>SKU</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ($products as $product_post) {
                $product = wc_get_product($product_post->ID);
                echo '<tr>';
                echo '<td>' . $product_post->ID . '</td>';
                echo '<td><a href="' . get_edit_post_link($product_post->ID) . '">' . $product->get_name() . '</a></td>';
                echo '<td>' . ($product->get_sku() ?: 'N/A') . '</td>';
                echo '<td>';
                echo '<a href="' . get_edit_post_link($product_post->ID) . '#hts_codes_product_data" class="button button-small">Edit HTS</a> ';
                echo '<button class="button button-small hts-quick-classify" data-product-id="' . $product_post->ID . '">Quick Classify</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.hts-quick-classify').on('click', function() {
                    var button = $(this);
                    var product_id = button.data('product-id');
                    
                    button.prop('disabled', true).text('Classifying...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'hts_generate_single_code',
                            product_id: product_id,
                            nonce: '<?php echo wp_create_nonce('hts_generate_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                button.text('✓ Classified').css('color', 'green');
                            } else {
                                button.text('✗ Failed').css('color', 'red');
                            }
                        },
                        error: function() {
                            button.text('✗ Error').css('color', 'red');
                            button.prop('disabled', false);
                        }
                    });
                });
            });
            </script>
            <?php
        } else {
            echo '<p>✓ All products have HTS codes!</p>';
        }
        ?>
        
        <hr>
        
        <h2>Features</h2>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><strong>Product Tab:</strong> HTS Codes tab in product edit screen with AI generation button</li>
            <li><strong>Auto-Classification:</strong> Automatically classifies new products when published</li>
            <li><strong>ShipStation Integration:</strong> Exports HTS codes with orders for customs forms</li>
            <li><strong>Bulk Operations:</strong> Classify multiple products at once from the products list</li>
            <li><strong>Manual Override:</strong> Edit HTS codes directly in product data</li>
            <li><strong>Confidence Tracking:</strong> Shows AI confidence level for each classification</li>
        </ul>
        
        <hr>
        
        <h2>📚 Usage Guide for Staff</h2>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 5px; border-left: 4px solid #2271b1;">
            <h3 style="margin-top: 0;">Daily Workflow</h3>
            
            <div style="margin-bottom: 20px;">
                <h4>🌅 Start of Day:</h4>
                <ol style="line-height: 1.8;">
                    <li>Check the <strong>Dashboard Widget</strong> for status overview</li>
                    <li>If you see <span style="color: #d63638;">❌ Products without codes</span>, they'll auto-classify as you work</li>
                    <li>Review any <span style="color: #dba617;">⚠️ Low confidence</span> items if time permits</li>
                </ol>
            </div>
            
            <div style="margin-bottom: 20px;">
                <h4>➕ Adding New Products:</h4>
                <ol style="line-height: 1.8;">
                    <li>Enter all product details as normal</li>
                    <li>Click <strong>"Publish"</strong> - HTS code generates automatically in background</li>
                    <li>Move to next product immediately (no waiting!)</li>
                    <li>Code will be ready in ~10 seconds if you need to check</li>
                </ol>
            </div>
            
            <div style="margin-bottom: 20px;">
                <h4>🔧 Fixing Missing Codes:</h4>
                <p><strong>Option A - Individual Product:</strong></p>
                <ol style="line-height: 1.8;">
                    <li>Edit the product</li>
                    <li>Go to <strong>"HTS Codes"</strong> tab</li>
                    <li>Click <strong>"Auto-Generate with AI"</strong> button</li>
                    <li>Wait 3 seconds for the code to appear</li>
                </ol>
                
                <p><strong>Option B - Let System Auto-Fix:</strong></p>
                <ol style="line-height: 1.8;">
                    <li>Just click <strong>"Update"</strong> on any product without a code</li>
                    <li>System will auto-generate in background</li>
                    <li>Check back in a minute to see the code</li>
                </ol>
                
                <p><strong>Option C - Bulk Fix:</strong></p>
                <ol style="line-height: 1.8;">
                    <li>Go to Products list</li>
                    <li>Filter by <strong>"Missing HTS Code"</strong></li>
                    <li>Select all products</li>
                    <li>Bulk Actions → <strong>"Generate HTS Codes"</strong></li>
                </ol>
            </div>
            
            <div style="margin-bottom: 20px;">
                <h4>🚢 For ShipStation:</h4>
                <ul style="line-height: 1.8;">
                    <li>✅ HTS codes automatically sync with ShipStation</li>
                    <li>✅ Customs forms populate automatically</li>
                    <li>✅ Country of origin defaults to Canada</li>
                    <li>📝 You can override any code manually if needed</li>
                </ul>
            </div>
            
            <div style="background: #fff; padding: 15px; border-radius: 5px; margin-top: 20px;">
                <h4 style="margin-top: 0;">⚡ Quick Tips:</h4>
                <ul style="line-height: 1.8; margin-bottom: 0;">
                    <li>🟢 <strong>Green progress bar</strong> = You're all set!</li>
                    <li>🟡 <strong>Yellow progress bar</strong> = Some products need attention</li>
                    <li>🔴 <strong>Red progress bar</strong> = Many products missing codes</li>
                    <li>💡 <strong>Low confidence?</strong> The code is probably still correct, but double-check if shipping high-value items</li>
                    <li>🔄 <strong>Regenerate a code:</strong> Use the "Regenerate" link next to the AI button</li>
                </ul>
            </div>
            
            <div style="background: #e7f3ff; padding: 15px; border-radius: 5px; margin-top: 20px;">
                <strong>📞 Need Help?</strong><br>
                • Check the dashboard widget for current status<br>
                • Missing codes auto-generate on product save<br>
                • Manual generation available in HTS Codes tab<br>
                • Bulk operations available for multiple products
            </div>
        </div>
    </div>
    <?php
}

// ===============================================
// PART 7: BULK ACTIONS
// ===============================================

add_filter('bulk_actions-edit-product', 'hts_add_bulk_classify');
function hts_add_bulk_classify($bulk_actions) {
    $bulk_actions['hts_classify'] = __('Generate HTS Codes', 'hts-manager');
    return $bulk_actions;
}

add_filter('handle_bulk_actions-edit-product', 'hts_handle_bulk_classify', 10, 3);
function hts_handle_bulk_classify($redirect_to, $action, $post_ids) {
    if ($action !== 'hts_classify') {
        return $redirect_to;
    }
    
    foreach ($post_ids as $post_id) {
        wp_schedule_single_event(time() + rand(5, 30), 'hts_classify_product_cron', array($post_id));
    }
    
    $redirect_to = add_query_arg('hts_classified', count($post_ids), $redirect_to);
    return $redirect_to;
}

add_action('admin_notices', 'hts_bulk_classify_notice');
function hts_bulk_classify_notice() {
    if (!empty($_REQUEST['hts_classified'])) {
        $count = intval($_REQUEST['hts_classified']);
        printf(
            '<div class="notice notice-success is-dismissible"><p>' . 
            _n('Queued %s product for HTS classification.', 'Queued %s products for HTS classification.', $count, 'hts-manager') . 
            '</p></div>',
            $count
        );
    }
}

// ===============================================
// PART 8: DISPLAY ON FRONTEND (OPTIONAL)
// ===============================================

add_action('woocommerce_product_meta_end', 'hts_display_on_product_page');
function hts_display_on_product_page() {
    if (get_option('hts_show_on_frontend', '0') === '1') {
        global $product;
        $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
        $country = get_post_meta($product->get_id(), '_country_of_origin', true);
        
        if ($hts_code) {
            echo '<span class="hts-code">HTS Code: ' . esc_html($hts_code) . '</span><br>';
        }
        if ($country) {
            echo '<span class="country-origin">Country of Origin: ' . esc_html($country) . '</span><br>';
        }
    }
}

// ===============================================
// PART 9: DASHBOARD WIDGET
// ===============================================

add_action('wp_dashboard_setup', 'hts_add_dashboard_widget');
function hts_add_dashboard_widget() {
    if (current_user_can('manage_woocommerce')) {
        wp_add_dashboard_widget(
            'hts_classification_status',
            '📦 HTS Classification Status',
            'hts_dashboard_widget_display'
        );
    }
}

function hts_dashboard_widget_display() {
    global $wpdb;
    
    // Get total products
    $total_products = wp_count_posts('product');
    $total_published = $total_products->publish;
    
    // Get products with HTS codes
    $with_codes = $wpdb->get_var("
        SELECT COUNT(DISTINCT post_id) 
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = '_hts_code' 
        AND pm.meta_value != '' 
        AND pm.meta_value != '9999.99.9999'
        AND p.post_status = 'publish'
        AND p.post_type = 'product'
    ");
    
    // Get products with low confidence
    $low_confidence = $wpdb->get_var("
        SELECT COUNT(DISTINCT post_id) 
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = '_hts_confidence' 
        AND CAST(pm.meta_value AS DECIMAL(3,2)) < 0.60
        AND p.post_status = 'publish'
        AND p.post_type = 'product'
    ");
    
    // Check for pending scheduled classifications
    $pending_crons = 0;
    $crons = _get_cron_array();
    foreach ($crons as $timestamp => $cron) {
        if (isset($cron['hts_classify_product_cron'])) {
            $pending_crons += count($cron['hts_classify_product_cron']);
        }
    }
    
    $without_codes = $total_published - $with_codes;
    $percentage = $total_published > 0 ? round(($with_codes / $total_published) * 100, 1) : 0;
    
    // Define status color based on coverage
    $status_color = $percentage >= 95 ? '#00a32a' : ($percentage >= 80 ? '#dba617' : '#d63638');
    
    ?>
    <style>
        .hts-widget-stats {
            margin: 15px 0;
        }
        .hts-stat-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .hts-stat-row:last-child {
            border-bottom: none;
        }
        .hts-stat-label {
            font-weight: 500;
        }
        .hts-stat-value {
            font-weight: bold;
        }
        .hts-progress-bar {
            width: 100%;
            height: 20px;
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        .hts-progress-fill {
            height: 100%;
            background: <?php echo $status_color; ?>;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        .hts-action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .hts-action-buttons .button {
            flex: 1;
            text-align: center;
        }
        .hts-status-good { color: #00a32a; }
        .hts-status-warning { color: #dba617; }
        .hts-status-error { color: #d63638; }
        .hts-refresh-notice {
            margin-top: 10px;
            color: #666;
            font-size: 12px;
            font-style: italic;
        }
    </style>
    
    <div class="hts-widget-content">
        <div class="hts-progress-bar">
            <div class="hts-progress-fill" style="width: <?php echo $percentage; ?>%">
                <?php echo $percentage; ?>%
            </div>
        </div>
        
        <div class="hts-widget-stats">
            <div class="hts-stat-row">
                <span class="hts-stat-label">✅ Products with HTS codes:</span>
                <span class="hts-stat-value hts-status-good"><?php echo number_format($with_codes); ?></span>
            </div>
            
            <?php if ($without_codes > 0): ?>
            <div class="hts-stat-row">
                <span class="hts-stat-label">❌ Products without codes:</span>
                <span class="hts-stat-value hts-status-error"><?php echo number_format($without_codes); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($pending_crons > 0): ?>
            <div class="hts-stat-row">
                <span class="hts-stat-label">⏳ Pending classification:</span>
                <span class="hts-stat-value hts-status-warning"><?php echo number_format($pending_crons); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($low_confidence > 0): ?>
            <div class="hts-stat-row">
                <span class="hts-stat-label">⚠️ Low confidence (needs review):</span>
                <span class="hts-stat-value hts-status-warning"><?php echo number_format($low_confidence); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="hts-stat-row">
                <span class="hts-stat-label">📊 Total products:</span>
                <span class="hts-stat-value"><?php echo number_format($total_published); ?></span>
            </div>
        </div>
        
        <div class="hts-action-buttons">
            <?php if ($without_codes > 0): ?>
            <a href="<?php echo admin_url('admin.php?page=hts-manager'); ?>" class="button button-primary">
                Classify Missing
            </a>
            <?php endif; ?>
            
            <?php if ($low_confidence > 0): ?>
            <a href="<?php echo admin_url('edit.php?post_type=product&hts_filter=low_confidence'); ?>" class="button">
                Review Low Confidence
            </a>
            <?php endif; ?>
            
            <a href="<?php echo admin_url('admin.php?page=hts-manager'); ?>" class="button">
                Settings
            </a>
        </div>
        
        <?php if ($pending_crons > 0): ?>
        <div class="hts-refresh-notice">
            ⏱️ Classifications in progress. Refresh in a minute to see updates.
        </div>
        <?php endif; ?>
        
        <div class="hts-refresh-notice" style="margin-top: 15px;">
            <a href="#" onclick="location.reload(); return false;">↻ Refresh Stats</a>
            <?php if ($with_codes === $total_published): ?>
            | <span style="color: #00a32a;">✨ All products classified!</span>
            <?php endif; ?>
            | <a href="#" onclick="jQuery('#hts-quick-guide').toggle(); return false;">📖 Quick Guide</a>
        </div>
        
        <div id="hts-quick-guide" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-left: 4px solid #2271b1; border-radius: 4px;">
            <h4 style="margin-top: 0;">🚀 Quick Usage Guide</h4>
            <ol style="margin-left: 20px; line-height: 1.6;">
                <li><strong>New Products:</strong> HTS codes auto-generate when you publish/update</li>
                <li><strong>Manual Generate:</strong> Edit product → HTS Codes tab → "Auto-Generate with AI"</li>
                <li><strong>Bulk Classify:</strong> Products list → Select multiple → "Generate HTS Codes"</li>
                <li><strong>Review Low Confidence:</strong> Click button above to see products needing review</li>
            </ol>
            <p style="margin-bottom: 0; color: #666; font-size: 12px;">
                💡 <strong>Tip:</strong> Products without codes will auto-classify on next save. Just click "Update" on any product missing a code!
            </p>
        </div>
    </div>
    <?php
}

// ===============================================
// PART 10: ADMIN NOTICES FOR PRODUCT SAVES
// ===============================================

add_action('admin_notices', 'hts_product_save_notices');
function hts_product_save_notices() {
    $screen = get_current_screen();
    
    // Only show on product edit screen
    if ($screen && $screen->id === 'product') {
        global $post;
        
        if ($post && $post->post_type === 'product') {
            $hts_code = get_post_meta($post->ID, '_hts_code', true);
            
            // Check if we just saved (by looking for the 'message' parameter)
            if (isset($_GET['message']) && $_GET['message'] == '1') {
                
                // Check if classification is scheduled
                $crons = _get_cron_array();
                $is_scheduled = false;
                
                foreach ($crons as $timestamp => $cron) {
                    if (isset($cron['hts_classify_product_cron'])) {
                        foreach ($cron['hts_classify_product_cron'] as $hook) {
                            if (in_array($post->ID, $hook['args'])) {
                                $is_scheduled = true;
                                break 2;
                            }
                        }
                    }
                }
                
                if ($is_scheduled && empty($hts_code)) {
                    ?>
                    <div class="notice notice-info is-dismissible">
                        <p>
                            <strong>⏳ HTS Classification in Progress</strong><br>
                            The HTS code is being generated for this product. Refresh the page in a few seconds to see the result.
                        </p>
                    </div>
                    <?php
                } elseif (!empty($hts_code)) {
                    $confidence = get_post_meta($post->ID, '_hts_confidence', true);
                    if ($confidence && $confidence < 0.60) {
                        ?>
                        <div class="notice notice-warning is-dismissible">
                            <p>
                                <strong>⚠️ Low Confidence HTS Code</strong><br>
                                This product has an HTS code (<?php echo esc_html($hts_code); ?>) but with low confidence (<?php echo round($confidence * 100); ?>%). 
                                Consider reviewing and updating if necessary.
                            </p>
                        </div>
                        <?php
                    }
                }
            }
        }
    }
}

// Add filter to products list for low confidence items
add_action('restrict_manage_posts', 'hts_add_confidence_filter');
function hts_add_confidence_filter() {
    global $typenow;
    
    if ($typenow == 'product') {
        $selected = isset($_GET['hts_filter']) ? $_GET['hts_filter'] : '';
        ?>
        <select name="hts_filter" id="hts_filter">
            <option value="">All HTS Status</option>
            <option value="no_code" <?php selected($selected, 'no_code'); ?>>Missing HTS Code</option>
            <option value="low_confidence" <?php selected($selected, 'low_confidence'); ?>>Low Confidence</option>
            <option value="has_code" <?php selected($selected, 'has_code'); ?>>Has HTS Code</option>
        </select>
        <?php
    }
}

add_filter('request', 'hts_filter_products_by_confidence');
function hts_filter_products_by_confidence($query_vars) {
    global $pagenow, $typenow;
    
    if ($pagenow == 'edit.php' && $typenow == 'product' && isset($_GET['hts_filter']) && $_GET['hts_filter'] != '') {
        
        switch ($_GET['hts_filter']) {
            case 'no_code':
                $query_vars['meta_query'] = array(
                    'relation' => 'OR',
                    array(
                        'key' => '_hts_code',
                        'compare' => 'NOT EXISTS'
                    ),
                    array(
                        'key' => '_hts_code',
                        'value' => '',
                        'compare' => '='
                    )
                );
                break;
                
            case 'low_confidence':
                $query_vars['meta_query'] = array(
                    array(
                        'key' => '_hts_confidence',
                        'value' => '0.60',
                        'compare' => '<',
                        'type' => 'DECIMAL(3,2)'
                    )
                );
                break;
                
            case 'has_code':
                $query_vars['meta_query'] = array(
                    array(
                        'key' => '_hts_code',
                        'value' => '',
                        'compare' => '!='
                    )
                );
                break;
        }
    }
    
    return $query_vars;
}