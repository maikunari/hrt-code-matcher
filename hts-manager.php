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
                <button type="button" class="button button-primary" id="hts_generate_code">
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                    <?php _e('Auto-Generate with AI', 'hts-manager'); ?>
                </button>
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
        $('#hts_generate_code').on('click', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var spinner = $('#hts_generate_spinner');
            var message = $('#hts_generate_message');
            var product_id = <?php echo $post->ID; ?>;
            
            // Show spinner, disable button
            button.prop('disabled', true);
            spinner.css('display', 'inline-block').addClass('is-active');
            message.hide().removeClass('success error');
            
            // AJAX call
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'hts_generate_single_code',
                    product_id: product_id,
                    nonce: '<?php echo wp_create_nonce('hts_generate_nonce'); ?>'
                },
                success: function(response) {
                    spinner.removeClass('is-active').hide();
                    button.prop('disabled', false);
                    
                    if (response.success) {
                        // Update the HTS code field
                        $('#_hts_code').val(response.data.hts_code);
                        
                        // Show success message
                        message.html('<span style="color: green;">✓ Generated: ' + response.data.hts_code + ' (' + Math.round(response.data.confidence * 100) + '% confidence)</span>');
                        message.addClass('success').show();
                        
                        // Add confidence display if it doesn't exist
                        if (!$('.hts-confidence-display').length && response.data.confidence) {
                            var confidenceHtml = '<p class="form-field hts-confidence-display">' +
                                '<label>Confidence</label>' +
                                '<span style="margin-left: 10px;">' +
                                '<span style="color: ' + (response.data.confidence >= 0.85 ? 'green' : (response.data.confidence >= 0.60 ? 'orange' : 'red')) + '; font-weight: bold;">' +
                                Math.round(response.data.confidence * 100) + '%' +
                                '</span></span></p>';
                            $(confidenceHtml).insertAfter('#hts_generate_message').parent().parent();
                        }
                    } else {
                        message.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        message.addClass('error').show();
                    }
                },
                error: function(xhr, status, error) {
                    spinner.removeClass('is-active').hide();
                    button.prop('disabled', false);
                    message.html('<span style="color: red;">✗ Error: ' + error + '</span>');
                    message.addClass('error').show();
                }
            });
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
// PART 4: AUTO-CLASSIFICATION ON PUBLISH
// ===============================================

add_action('transition_post_status', 'hts_auto_classify_on_publish', 10, 3);
function hts_auto_classify_on_publish($new_status, $old_status, $post) {
    // Check if enabled
    if (get_option('hts_auto_classify_enabled', '1') !== '1') {
        return;
    }
    
    // Only process products
    if ($post->post_type !== 'product' || $new_status !== 'publish') {
        return;
    }
    
    // Check if already has HTS code
    $existing_hts = get_post_meta($post->ID, '_hts_code', true);
    if (!empty($existing_hts) && $existing_hts !== '9999.99.9999') {
        return;
    }
    
    // Schedule classification
    wp_schedule_single_event(time() + 5, 'hts_classify_product_cron', array($post->ID));
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
        
        <h2>Python Scripts</h2>
        <p>For bulk operations, use the Python scripts in your project directory:</p>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><code>python classify_recent_fixed.py</code> - Classify new products (checks first 3 pages)</li>
            <li><code>python push_recent_only.py</code> - Push recently classified products</li>
            <li><code>python main.py</code> - Full menu with all options</li>
        </ul>
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