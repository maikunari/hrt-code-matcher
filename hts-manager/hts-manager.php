<?php

/**
 * Plugin Name: HTS Manager for WooCommerce FF 
 * Description: Complete HTS code management - display, auto-classify, and ShipStation integration
 * Version: 3.0.3
 * Author: Mike Sewell
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define pro version flag - set to true for pro version
if (!defined('HTS_MANAGER_PRO')) {
    define('HTS_MANAGER_PRO', false);
}

// Initialize the plugin
add_action('plugins_loaded', 'hts_manager_init');
function hts_manager_init()
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Register all hooks
    hts_register_hooks();
}

/**
 * Register all plugin hooks
 */
function hts_register_hooks()
{
    // PART 1: PRODUCT DATA TAB & DISPLAY
    add_filter('woocommerce_product_data_tabs', 'hts_add_product_data_tab');
    add_action('woocommerce_product_data_panels', 'hts_add_product_data_fields');
    add_action('woocommerce_process_product_meta', 'hts_save_product_data_fields');

    // PART 2: AJAX HANDLER
    add_action('wp_ajax_hts_generate_single_code', 'hts_ajax_generate_single_code');

    // PART 3: AUTO-CLASSIFICATION
    add_action('transition_post_status', 'hts_auto_classify_on_publish', 10, 3);
    add_action('save_post_product', 'hts_auto_classify_on_save', 10, 3);
    add_action('hts_classify_product_cron', 'hts_run_scheduled_classification');

    // PART 4: ADMIN
    add_action('admin_menu', 'hts_manager_menu');

    // PART 5: BULK ACTIONS
    add_filter('bulk_actions-edit-product', 'hts_add_bulk_classify');
    add_filter('handle_bulk_actions-edit-product', 'hts_handle_bulk_classify', 10, 3);
    add_action('admin_notices', 'hts_bulk_classify_notice');

    // PART 6: DASHBOARD WIDGET
    add_action('wp_dashboard_setup', 'hts_add_dashboard_widget');

    // PART 7: FRONTEND DISPLAY
    add_action('woocommerce_product_meta_end', 'hts_display_on_product_page');

    // PART 8: ADMIN NOTICES
    add_action('admin_notices', 'hts_product_save_notices');

    // PART 9: SHIPSTATION INTEGRATION
    add_action('plugins_loaded', 'hts_init_shipstation_integration');

    // PART 10: DUTIFY INTEGRATION (hooks registered at end of file with the functions)
}

/**
 * Check if this is the pro version
 */
function hts_is_pro()
{
    return defined('HTS_MANAGER_PRO') && HTS_MANAGER_PRO === true;
}

/**
 * Get classification limit based on version
 * @return int -1 for unlimited (pro), positive number for limit (free)
 */
function hts_get_classification_limit()
{
    return hts_is_pro() ? -1 : 25;
}

// ===============================================
// PART 1: PRODUCT DATA TAB & DISPLAY
// ===============================================

// Add HTS tab to product data metabox
function hts_add_product_data_tab($tabs)
{
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
function hts_add_product_data_fields()
{
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
                'description' => __(
                    'Enter the 10-digit Harmonized Tariff Schedule code for this product.',
                    'hts-manager'
                ),
                'value'       => $hts_code,
            ));
            ?>
            
            <p class="form-field">
                <label><?php _e('Generate HTS Code', 'hts-manager'); ?></label>
                <button type="button" class="button button-primary" id="hts_generate_code" 
                    <?php echo !empty($hts_code) ? 'disabled' : ''; ?>>
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                    <?php _e('Auto-Generate with AI', 'hts-manager'); ?>
                </button>
                <?php if (!empty($hts_code)) : ?>
                    <a href="#" id="hts_regenerate_link" style="margin-left: 10px; text-decoration: none;">
                        <?php _e('Regenerate', 'hts-manager'); ?>
                    </a>
                <?php endif; ?>
                <span id="hts_generate_spinner" class="spinner" 
                    style="display: none; float: none; margin-left: 10px;"></span>
                <span id="hts_generate_message" style="display: none; margin-left: 10px;"></span>
            </p>
            
            <?php if ($hts_confidence) : ?>
            <p class="form-field">
                <label><?php _e('Confidence', 'hts-manager'); ?></label>
                <span style="margin-left: 10px;">
                    <?php
                    $confidence_percent = round($hts_confidence * 100);
                    $confidence_color = $confidence_percent >= 85
                        ? 'green'
                        : ($confidence_percent >= 60 ? 'orange' : 'red');
                    ?>
                    <span style="color: <?php echo $confidence_color; ?>; font-weight: bold;">
                        <?php echo $confidence_percent; ?>%
                    </span>
                    <?php if ($hts_updated) : ?>
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
                'description' => __(
                    'Select the country where this product was manufactured or produced.',
                    'hts-manager'
                ),
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
                <?php _e(
                    'HTS codes are used for customs declarations and duty calculations when shipping internationally.',
                    'hts-manager'
                ); ?><br>
                <?php _e(
                    'These codes are automatically included in ShipStation exports for customs forms.',
                    'hts-manager'
                ); ?>
            </p>
        </div>
        
        <?php
        // Dutify sync status display - INSIDE the HTS panel
        if (class_exists('WOO_Dutify')) {
            $product_id = $post->ID;

            // Get Dutify attribute values
            $dutify_hs = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
            $dutify_country = wc_get_product_terms($product_id, 'pa_dutify_country_origin', array('fields' => 'names'));
            $dutify_hs_country = wc_get_product_terms($product_id, 'pa_dutify_hs_code_country', array('fields' => 'names'));

            $dutify_hs_value = $dutify_hs ? array_shift($dutify_hs) : null;
            $dutify_country_value = $dutify_country ? array_shift($dutify_country) : null;
            $dutify_hs_country_value = $dutify_hs_country ? array_shift($dutify_hs_country) : null;

            // Determine sync status
            $expected_hs = preg_replace('/[^0-9]/', '', $hts_code);
            $expected_country = strtoupper(substr($country_of_origin ?: 'CA', 0, 2));

            $hs_synced = ($dutify_hs_value === $expected_hs);
            $country_synced = ($dutify_country_value === $expected_country);
            $all_synced = $hs_synced && $country_synced && $dutify_hs_country_value;
            ?>
            
            <div class="options_group" style="background: #f8f9fa;">
                <h4 style="margin: 10px;">🔄 Dutify Sync Status</h4>
                
                <?php if (!empty($hts_code)) : ?>
                    <table style="width: 90%; margin: 0 10px;">
                        <tr>
                            <td><strong>HTS Code:</strong></td>
                            <td><?php echo $hs_synced
                                ? '<span style="color: green;">✅ ' . esc_html($dutify_hs_value) . '</span>'
                                : '<span style="color: red;">❌ Not synced</span>'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Country:</strong></td>
                            <td><?php echo $country_synced
                                ? '<span style="color: green;">✅ ' . esc_html($dutify_country_value) . '</span>'
                                : '<span style="color: red;">❌ Not synced</span>'; ?></td>
                        </tr>
                    </table>
                    
                    <?php if ($all_synced) : ?>
                        <p style="margin: 10px; color: green;">
                            <strong>✅ Ready for Dutify checkout!</strong>
                        </p>
                    <?php else : ?>
                        <p style="margin: 10px; color: #d63638;">
                            <strong>⚠️ Not synced to Dutify</strong>
                        </p>
                    <?php endif; ?>
                    
                    <!-- Test button for debugging -->
                    <p style="margin: 10px;">
                        <button type="button" class="button" id="dutify-sync-btn">Test Sync Now</button>
                        <span id="sync-result" style="margin-left: 10px;"></span>
                    </p>
                <?php else : ?>
                    <p style="margin: 10px; color: #666;">
                        No HTS code set yet.
                    </p>
                <?php endif; ?>
            </div>
            <?php
        }
        ?>
        
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
                        message.html(
                            '<span style="color: green;">✓ Generated: ' + 
                            response.data.hts_code + 
                            ' (' + Math.round(response.data.confidence * 100) + '% confidence)</span>'
                        );
                        message.addClass('success').show();
                        
                        // Keep button disabled since we now have a code
                        button.prop('disabled', true);
                        
                        // Show or create regenerate link
                        if (!regenerateLink.length) {
                            button.after(
                                ' <a href="#" id="hts_regenerate_link" ' +
                                'style="margin-left: 10px; text-decoration: none;">Regenerate</a>'
                            );
                            bindRegenerateHandler();
                        } else {
                            regenerateLink.show();
                        }
                        
                        // Add or update confidence display
                        var confidenceColor = response.data.confidence >= 0.85 
                            ? 'green' 
                            : (response.data.confidence >= 0.60 ? 'orange' : 'red');
                        var existingConfidence = $('.hts-confidence-display');
                        
                        if (existingConfidence.length) {
                            existingConfidence.find('span span')
                                .css('color', confidenceColor)
                                .text(Math.round(response.data.confidence * 100) + '%');
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
                    
                    message.html(
                        '<span style="color: red;">✗ Error: ' + error + '</span>'
                    );
                    message.addClass('error').show();
                }
            });
        }
        
        // Bind regenerate handler
        function bindRegenerateHandler() {
            $('#hts_regenerate_link').off('click').on('click', function(e) {
                e.preventDefault();
                var confirmMsg = 'Are you sure you want to regenerate the HTS code? ' +
                    'This will overwrite the existing code.';
                if (confirm(confirmMsg)) {
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
                $('#hts_generate_code').after(
                    ' <a href="#" id="hts_regenerate_link" ' +
                    'style="margin-left: 10px; text-decoration: none;">Regenerate</a>'
                );
                bindRegenerateHandler();
            } else if (!hasCode && $('#hts_regenerate_link').length) {
                $('#hts_regenerate_link').remove();
            }
        });
    });
    
    // Dutify sync button handler
    jQuery(document).ready(function($) {
        $('#dutify-sync-btn').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text('Syncing...');
            $('#sync-result').text('');
            
            $.post(ajaxurl, {
                action: 'test_dutify_sync',
                product_id: <?php echo $post->ID; ?>,
                _wpnonce: '<?php echo wp_create_nonce('test_dutify_sync'); ?>'
            }, function(response) {
                console.log('Sync Response:', response);
                button.prop('disabled', false).text('Test Sync Now');
                
                if (response.sync_result === true) {
                    $('#sync-result').html('<span style="color: green;">✅ Sync successful! Reloading...</span>');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    $('#sync-result').html('<span style="color: red;">❌ Sync failed - check console</span>');
                }
            }).fail(function(xhr, status, error) {
                console.error('AJAX Error:', error);
                button.prop('disabled', false).text('Test Sync Now');
                $('#sync-result').html('<span style="color: red;">❌ Error: ' + error + '</span>');
            });
        });
    });
    </script>
    <?php
}

// Save HTS fields
add_action('woocommerce_process_product_meta', 'hts_save_product_data_fields');
function hts_save_product_data_fields($post_id)
{
    // Security check
    if (!isset($_POST['hts_product_nonce']) || !wp_verify_nonce($_POST['hts_product_nonce'], 'hts_product_nonce_action')) {
        return;
    }

    // Save HTS code
    if (isset($_POST['_hts_code'])) {
        $hts_code = sanitize_text_field($_POST['_hts_code']);
        update_post_meta($post_id, '_hts_code', $hts_code);

        // Sync to Dutify if code was changed
        if (!empty($hts_code) && $hts_code !== '9999.99.9999') {
            hts_sync_to_dutify($post_id);
        }
    }

    // Save country of origin
    if (isset($_POST['_country_of_origin'])) {
        $country = sanitize_text_field($_POST['_country_of_origin']);
        $old_country = get_post_meta($post_id, '_country_of_origin', true);
        update_post_meta($post_id, '_country_of_origin', $country);

        // Sync to Dutify if country changed or if HTS code exists
        if ($country !== $old_country && !empty($hts_code)) {
            hts_sync_to_dutify($post_id);
        }
    }
}

// ===============================================
// PART 2: AJAX HANDLER FOR SINGLE PRODUCT
// ===============================================

add_action('wp_ajax_hts_generate_single_code', 'hts_ajax_generate_single_code');
function hts_ajax_generate_single_code()
{
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

        // Sync to Dutify if active
        hts_sync_to_dutify($product_id);

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

function hts_classify_product($product_id, $api_key)
{
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
function hts_auto_classify_on_publish($new_status, $old_status, $post)
{
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
function hts_auto_classify_on_save($post_id, $post, $update)
{
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
function hts_run_scheduled_classification($product_id)
{
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

        // Sync to Dutify if active
        hts_sync_to_dutify($product_id);

        // Notify admin if low confidence
        if ($result['confidence'] < 0.60) {
            hts_notify_admin_low_confidence($product_id, $result);
        }
    }
}

function hts_notify_admin_low_confidence($product_id, $result)
{
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
// PART 5: SHIPSTATION INTEGRATION (FIXED)
// ===============================================

// Initialize ShipStation integration when both plugins are active
add_action('plugins_loaded', 'hts_init_shipstation_integration');
function hts_init_shipstation_integration()
{
    if (class_exists('WooCommerce') && class_exists('WC_Shipstation_Integration')) {
        // Hook into ShipStation export - add customs data to orders
        add_filter('woocommerce_shipstation_export_order_xml', 'hts_add_customs_to_shipstation_order_xml', 10, 3);

        // Use custom fields as fallback method
        add_filter('woocommerce_shipstation_export_custom_field_2', 'hts_set_custom_field_2_key');
        add_filter('woocommerce_shipstation_export_custom_field_2_value', 'hts_add_hts_to_custom_field_value', 10, 2);
        add_filter('woocommerce_shipstation_export_custom_field_3', 'hts_set_custom_field_3_key');
        add_filter('woocommerce_shipstation_export_custom_field_3_value', 'hts_add_country_to_custom_field_value', 10, 2);
    }
}

// Set the custom field 2 to map to HTS codes
function hts_set_custom_field_2_key($meta_key)
{
    return '_hts_codes_summary';
}

// Set the custom field 3 to map to country of origin
function hts_set_custom_field_3_key($meta_key)
{
    return '_country_summary';
}

function hts_add_customs_to_shipstation_order_xml($order_xml, $order, $xml)
{
    try {
        // Store HTS codes summary in order meta for custom field fallback
        hts_store_customs_summary_in_order($order);

        // Add CustomsItems section using correct ShipStation XML structure
        $customs_items_xml = $xml->createElement('CustomsItems');
        $has_customs_items = false;

        foreach ($order->get_items() as $item_id => $item) {
            try {
                $product = is_callable(array($item, 'get_product')) ? $item->get_product() : false;

                if (!$product || !$product->needs_shipping()) {
                    continue;
                }

                $product_id = $product->get_id();
                $hts_code = get_post_meta($product_id, '_hts_code', true);

                if (empty($hts_code) || $hts_code === '9999.99.9999') {
                    continue;
                }

                if (!preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
                    continue;
                }

                $has_customs_items = true;
                $customs_item_xml = $xml->createElement('CustomsItem');

                // Add required fields exactly as ShipStation expects
                hts_safe_xml_append($xml, $customs_item_xml, 'Description', substr($product->get_name(), 0, 200), true);
                hts_safe_xml_append($xml, $customs_item_xml, 'SKU', $product->get_sku(), false);

                $quantity = $item->get_quantity() - abs($order->get_qty_refunded_for_item($item_id));
                hts_safe_xml_append($xml, $customs_item_xml, 'Quantity', max(0, $quantity), false);

                $item_value = $order->get_item_subtotal($item, false, false);
                if (is_numeric($item_value)) {
                    hts_safe_xml_append(
                        $xml,
                        $customs_item_xml,
                        'ItemValue',
                        number_format($item_value, 2, '.', ''),
                        false
                    );
                }

                // Format HTS code according to ShipStation API docs
                // API expects harmonized_tariff_code field with format like "3926.10" (keeping dots)
                hts_safe_xml_append($xml, $customs_item_xml, 'harmonized_tariff_code', $hts_code, false);

                $country = get_post_meta($product_id, '_country_of_origin', true) ?: 'CA';
                hts_safe_xml_append($xml, $customs_item_xml, 'CountryOfOrigin', strtoupper($country), false);

                $customs_items_xml->appendChild($customs_item_xml);

                hts_log_info(
                    'Added customs item: ' . $product->get_name() .
                    ' (HTS: ' . $hts_code . ', Country: ' . strtoupper($country) . ')'
                );
            } catch (Exception $e) {
                hts_log_error('Error processing customs item: ' . $e->getMessage());
                continue;
            }
        }

        if ($has_customs_items) {
            $order_xml->appendChild($customs_items_xml);
            hts_log_info('Added CustomsItems section to order ' . $order->get_id());
        }
    } catch (Exception $e) {
        hts_log_error('Error in order customs processing: ' . $e->getMessage());
    }

    return $order_xml;
}

/**
 * Store customs summary in order meta for custom field fallback
 */
function hts_store_customs_summary_in_order($order)
{
    $hts_codes = array();
    $countries = array();

    foreach ($order->get_items() as $item) {
        try {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $hts_code = get_post_meta($product->get_id(), '_hts_code', true);
            if ($hts_code && $hts_code !== '9999.99.9999' && preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
                $sku = $product->get_sku();
                if ($sku) {
                    $hts_codes[] = substr($sku, 0, 20) . ':' . $hts_code;
                }
            }

            $country = get_post_meta($product->get_id(), '_country_of_origin', true) ?: 'CA';
            if (!in_array($country, $countries)) {
                $countries[] = strtoupper($country);
            }
        } catch (Exception $e) {
            continue;
        }
    }

    // Store summaries in order meta
    if (!empty($hts_codes)) {
        $order->update_meta_data('_hts_codes_summary', implode(', ', $hts_codes));
    }
    if (!empty($countries)) {
        $order->update_meta_data('_country_summary', implode(', ', $countries));
    }
    $order->save_meta_data();
}

/**
 * Custom field 2 value - return HTS codes summary from order meta
 */
function hts_add_hts_to_custom_field_value($value, $order_id)
{
    try {
        $order = wc_get_order($order_id);
        if (!$order) {
            return $value;
        }

        $hts_summary = $order->get_meta('_hts_codes_summary', true);
        if (!empty($hts_summary)) {
            hts_log_info('Returning HTS codes for custom field 2: ' . $hts_summary);
            return $hts_summary;
        }
    } catch (Exception $e) {
        hts_log_error('Error in custom field 2 value: ' . $e->getMessage());
    }

    return $value;
}

/**
 * Custom field 3 value - return country summary from order meta
 */
function hts_add_country_to_custom_field_value($value, $order_id)
{
    try {
        $order = wc_get_order($order_id);
        if (!$order) {
            return $value;
        }

        $country_summary = $order->get_meta('_country_summary', true);
        if (!empty($country_summary)) {
            hts_log_info('Returning countries for custom field 3: ' . $country_summary);
            return $country_summary;
        }
    } catch (Exception $e) {
        hts_log_error('Error in custom field 3 value: ' . $e->getMessage());
    }

    return $value;
}

/**
 * Safe XML append helper - won't throw exceptions
 */
function hts_safe_xml_append($xml, $parent, $name, $value, $cdata = true)
{
    try {
        if (!$xml || !$parent || !$name) {
            return false;
        }

        $value = (string) $value;
        if (empty($value) && $value !== '0') {
            return false;
        }

        // Clean value of any invalid XML characters
        $value = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $value);

        $element = $xml->createElement($name);
        if ($cdata && $value) {
            $element->appendChild($xml->createCDATASection($value));
        } elseif ($value) {
            $element->appendChild($xml->createTextNode($value));
        }
        $parent->appendChild($element);
        return true;
    } catch (Exception $e) {
        hts_log_error('XML append failed for ' . $name . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced logging functions for debugging ShipStation integration
 */
function hts_log_error($message, $context = array())
{
    try {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = '[HTS Manager ERROR] ' . $message;
            if (!empty($context)) {
                $log_message .= ' | Context: ' . json_encode($context);
            }
            error_log($log_message);
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->error($message, array('source' => 'hts-manager', 'context' => $context));
        }
    } catch (Exception $e) {
        // Silently continue
    }
}

function hts_log_info($message, $context = array())
{
    try {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = '[HTS Manager INFO] ' . $message;
            if (!empty($context)) {
                $log_message .= ' | Context: ' . json_encode($context);
            }
            error_log($log_message);
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info($message, array('source' => 'hts-manager', 'context' => $context));
        }
    } catch (Exception $e) {
        // Silently continue
    }
}

// ===============================================
// PART 6: ADMIN SETTINGS PAGE
// ===============================================

add_action('admin_menu', 'hts_manager_menu');
function hts_manager_menu()
{
    add_submenu_page(
        'woocommerce',
        'HTS Manager',
        'HTS Manager',
        'manage_woocommerce',
        'hts-manager',
        'hts_manager_settings_page'
    );
}

function hts_manager_settings_page()
{
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
                echo '<div class="notice notice-info"><p>' .
                    'Testing classification for product ID: ' . $test_product_id .
                    '</p></div>';

                $result = hts_classify_product($test_product_id, $api_key);

                if ($result && isset($result['hts_code'])) {
                    update_post_meta($test_product_id, '_hts_code', $result['hts_code']);
                    update_post_meta($test_product_id, '_hts_confidence', $result['confidence']);
                    update_post_meta($test_product_id, '_hts_updated', current_time('mysql'));

                    echo '<div class="notice notice-success"><p>' .
                        '✓ Classification successful! HTS Code: ' . $result['hts_code'] .
                        ' (Confidence: ' . round($result['confidence'] * 100) . '%)' .
                        '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' .
                        '✗ Classification failed. Please check your API key and try again.' .
                        '</p></div>';
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
            <p><strong>Complete HTS Management System</strong> - 
                This plugin handles HTS code display, auto-classification, and ShipStation integration.</p>
        </div>
        
        
        <?php if (empty($api_key)) : ?>
        <div class="notice notice-warning">
            <p><strong>⚠️ Setup Required:</strong> 
                Please add your Anthropic API key below to enable auto-classification.</p>
        </div>
        <?php endif; ?>
        
        <?php
        // Dutify Bulk Sync Section
        if (class_exists('WOO_Dutify') && taxonomy_exists('pa_dutify_hs_code')) {
            // Count products with HTS codes
            $products_with_codes = get_posts(array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => '_hts_code',
                        'compare' => 'EXISTS'
                    ),
                    array(
                        'key' => '_hts_code',
                        'value' => '9999.99.9999',
                        'compare' => '!='
                    )
                )
            ));
            $total_products = count($products_with_codes);
            ?>
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>🔄 Dutify Integration Sync</h2>
                <p>Sync HTS codes from HTS Manager to Dutify attributes for duty calculations at checkout.</p>
                <p><strong>Products with HTS codes:</strong> <?php echo $total_products; ?></p>
                
                <?php if ($total_products > 0) : ?>
                <div style="margin-top: 20px;">
                    <button type="button" class="button button-primary button-hero" id="bulk-sync-dutify">
                        Sync All Products to Dutify
                    </button>
                    <span id="sync-status" style="margin-left: 20px;"></span>
                </div>
                
                <div id="sync-progress-wrapper" style="display: none; margin-top: 20px;">
                    <div style="background: #f0f0f0; height: 30px; border-radius: 5px; overflow: hidden;">
                        <div id="sync-progress-bar" style="background: #007cba; height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center;">
                            <span id="sync-progress-text" style="color: white; font-weight: bold;">0%</span>
                        </div>
                    </div>
                    <div id="sync-details" style="margin-top: 10px;">
                        <span id="sync-current">0</span> / <span id="sync-total"><?php echo $total_products; ?></span> products synced
                        <span id="sync-errors" style="color: red; margin-left: 20px;"></span>
                    </div>
                </div>
                
                <div id="sync-log" style="margin-top: 20px; max-height: 200px; overflow-y: auto; background: #f8f9fa; padding: 10px; border: 1px solid #ddd; display: none;">
                    <strong>Sync Log:</strong><br>
                </div>
                
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var syncInProgress = false;
                    var currentBatch = 0;
                    var batchSize = 5; // Smaller batches to prevent timeout
                    var totalProducts = <?php echo json_encode($products_with_codes); ?>;
                    var totalBatches = Math.ceil(totalProducts.length / batchSize);
                    var successCount = 0;
                    var errorCount = 0;
                    
                    $('#bulk-sync-dutify').on('click', function() {
                        if (syncInProgress) {
                            return;
                        }
                        
                        if (!confirm('This will sync ' + totalProducts.length + ' products to Dutify. Continue?')) {
                            return;
                        }
                        
                        syncInProgress = true;
                        currentBatch = 0;
                        successCount = 0;
                        errorCount = 0;
                        
                        $(this).prop('disabled', true).text('Syncing...');
                        $('#sync-progress-wrapper').show();
                        $('#sync-log').show().html('<strong>Sync Log:</strong><br>');
                        $('#sync-status').html('<span style="color: orange;">⏳ Sync in progress...</span>');
                        
                        processBatch();
                    });
                    
                    function processBatch() {
                        if (currentBatch >= totalBatches) {
                            syncComplete();
                            return;
                        }
                        
                        var start = currentBatch * batchSize;
                        var end = Math.min(start + batchSize, totalProducts.length);
                        var batchProducts = totalProducts.slice(start, end);
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'bulk_sync_dutify',
                                products: batchProducts,
                                batch: currentBatch + 1,
                                total_batches: totalBatches,
                                _wpnonce: '<?php echo wp_create_nonce('bulk_sync_dutify'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    successCount += response.data.synced;
                                    errorCount += response.data.errors;
                                    
                                    // Update progress
                                    var progress = Math.round(((currentBatch + 1) / totalBatches) * 100);
                                    $('#sync-progress-bar').css('width', progress + '%');
                                    $('#sync-progress-text').text(progress + '%');
                                    $('#sync-current').text(successCount + errorCount);
                                    
                                    // Update log
                                    if (response.data.messages) {
                                        response.data.messages.forEach(function(msg) {
                                            $('#sync-log').append(msg + '<br>');
                                        });
                                        $('#sync-log').scrollTop($('#sync-log')[0].scrollHeight);
                                    }
                                    
                                    if (errorCount > 0) {
                                        $('#sync-errors').text('(' + errorCount + ' errors)');
                                    }
                                    
                                    currentBatch++;
                                    setTimeout(processBatch, 500); // Small delay between batches
                                } else {
                                    syncError('Batch ' + (currentBatch + 1) + ' failed: ' + response.data.message);
                                }
                            },
                            error: function(xhr, status, error) {
                                syncError('Network error: ' + error);
                            }
                        });
                    }
                    
                    function syncComplete() {
                        syncInProgress = false;
                        $('#bulk-sync-dutify').prop('disabled', false).text('Sync Complete');
                        $('#sync-status').html('<span style="color: green;">✅ Sync complete! ' + successCount + ' synced, ' + errorCount + ' errors</span>');
                        $('#sync-log').append('<br><strong>✅ Sync completed!</strong><br>');
                        
                        setTimeout(function() {
                            $('#bulk-sync-dutify').text('Sync All Products to Dutify');
                        }, 5000);
                    }
                    
                    function syncError(message) {
                        syncInProgress = false;
                        $('#bulk-sync-dutify').prop('disabled', false).text('Sync Failed - Try Again');
                        $('#sync-status').html('<span style="color: red;">❌ ' + message + '</span>');
                        $('#sync-log').append('<br><span style="color: red;">ERROR: ' + message + '</span><br>');
                    }
                });
                </script>
                <?php else : ?>
                <p style="color: #666;">No products with HTS codes found. Classify products first.</p>
                <?php endif; ?>
            </div>
            <?php
        }
        ?>
        
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
                        <input type="password" name="api_key" 
                            value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                        <p class="description">Your Claude API key from Anthropic</p>
                        <?php if (!empty($api_key)) : ?>
                        <p class="description" style="color: green;">
                            ✓ API key is configured (<?php echo strlen($api_key); ?> characters)
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Low Confidence Threshold</th>
                    <td>
                        <input type="number" name="confidence_threshold" 
                            value="<?php echo esc_attr($threshold); ?>" 
                            min="0" max="1" step="0.05">
                        <p class="description">
                            Send email notification if confidence is below this threshold (0.60 = 60%)
                        </p>
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
        // Get current page
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;

        // First, get total count
        $count_args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
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

        $all_products_without_codes = get_posts($count_args);
        $total_without_codes = count($all_products_without_codes);
        $total_pages = ceil($total_without_codes / $per_page);

        // Now get paginated results
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $per_page,
            'paged' => $current_page,
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

        if ($total_without_codes > 0) {
            // Show summary and bulk action
            echo '<div style="margin-bottom: 20px; padding: 15px; background: #f0f0f1; ' .
                'border-left: 4px solid #2271b1;">';
            echo '<p style="font-size: 16px; margin: 0 0 10px 0;">' .
                '<strong>Found ' . $total_without_codes . ' products without HTS codes</strong></p>';

            if ($total_without_codes > 20) {
                echo '<p style="margin: 10px 0;">Showing ' .
                    (($current_page - 1) * $per_page + 1) . '-' .
                    min($current_page * $per_page, $total_without_codes) .
                    ' of ' . $total_without_codes . ' products</p>';
            }

            // Bulk classify button
            echo '<div style="margin-top: 15px;">';
            echo '<button class="button button-primary button-large" id="hts-classify-all-missing" data-product-ids="' . esc_attr(implode(',', $all_products_without_codes)) . '">';
            echo '🚀 Classify All ' . $total_without_codes . ' Products';
            echo '</button>';
            echo '<span id="hts-bulk-progress" style="display: none; margin-left: 15px;"></span>';
            echo '<div id="hts-bulk-status" style="margin-top: 10px; display: none;"></div>';
            echo '</div>';

            echo '</div>';

            // Products table
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
                echo '<button class="button button-small hts-quick-classify" data-product-id="' . $product_post->ID . '">Quick Classify</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            // Pagination
            if ($total_pages > 1) {
                echo '<div style="margin-top: 20px; text-align: right;">';
                $base_url = admin_url('admin.php?page=hts-manager');

                echo '<div class="tablenav-pages">';
                echo '<span class="displaying-num">' . $total_without_codes . ' items</span>';
                echo '<span class="pagination-links">';

                // First page
                if ($current_page > 1) {
                    echo '<a class="first-page button" href="' . $base_url . '&paged=1">«</a> ';
                    echo '<a class="prev-page button" href="' . $base_url . '&paged=' . ($current_page - 1) . '">‹</a> ';
                } else {
                    echo '<span class="tablenav-pages-navspan button disabled">«</span> ';
                    echo '<span class="tablenav-pages-navspan button disabled">‹</span> ';
                }

                echo '<span class="paging-input">';
                echo '<span class="tablenav-paging-text">' . $current_page . ' of <span class="total-pages">' . $total_pages . '</span></span>';
                echo '</span>';

                // Next/Last page
                if ($current_page < $total_pages) {
                    echo ' <a class="next-page button" href="' . $base_url . '&paged=' . ($current_page + 1) . '">›</a>';
                    echo ' <a class="last-page button" href="' . $base_url . '&paged=' . $total_pages . '">»</a>';
                } else {
                    echo ' <span class="tablenav-pages-navspan button disabled">›</span>';
                    echo ' <span class="tablenav-pages-navspan button disabled">»</span>';
                }

                echo '</span>';
                echo '</div>';
                echo '</div>';
            }

            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Individual quick classify
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
                
                // Bulk classify all missing
                $('#hts-classify-all-missing').on('click', function() {
                    var button = $(this);
                    var productIds = button.data('product-ids').toString().split(',');
                    var totalProducts = productIds.length;
                    var processed = 0;
                    var succeeded = 0;
                    var failed = 0;
                    
                    if (!confirm('This will classify ' + totalProducts + ' products. This may take several minutes and cost approximately $' + (totalProducts * 0.003).toFixed(2) + ' in API fees.\n\nProceed?')) {
                        return;
                    }
                    
                    button.prop('disabled', true).text('Processing...');
                    $('#hts-bulk-progress').show().html('<span class="spinner is-active" style="float: none;"></span> Processing...');
                    $('#hts-bulk-status').show().html('<div style="padding: 10px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">Starting classification...</div>');
                    
                    // Process in batches to avoid overwhelming the server
                    var batchSize = 5;
                    var batches = [];
                    
                    for (var i = 0; i < productIds.length; i += batchSize) {
                        batches.push(productIds.slice(i, i + batchSize));
                    }
                    
                    function processBatch(batchIndex) {
                        if (batchIndex >= batches.length) {
                            // All done
                            $('#hts-bulk-progress').html('✓ Complete!');
                            $('#hts-bulk-status').html(
                                '<div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">' +
                                '<strong>Classification Complete!</strong><br>' +
                                '✓ Succeeded: ' + succeeded + '<br>' +
                                '✗ Failed: ' + failed + '<br>' +
                                'Total Processed: ' + processed + ' of ' + totalProducts +
                                '<br><br><a href="' + window.location.href + '" class="button">Refresh Page</a>' +
                                '</div>'
                            );
                            button.text('Classification Complete').prop('disabled', false);
                            return;
                        }
                        
                        var batch = batches[batchIndex];
                        var batchPromises = [];
                        
                        batch.forEach(function(productId) {
                            var promise = $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'hts_generate_single_code',
                                    product_id: productId,
                                    nonce: '<?php echo wp_create_nonce('hts_generate_nonce'); ?>'
                                },
                                success: function() {
                                    succeeded++;
                                    processed++;
                                },
                                error: function() {
                                    failed++;
                                    processed++;
                                }
                            });
                            batchPromises.push(promise);
                        });
                        
                        // Wait for batch to complete
                        $.when.apply($, batchPromises).always(function() {
                            // Update progress
                            var percentComplete = Math.round((processed / totalProducts) * 100);
                            $('#hts-bulk-progress').html(
                                '<div style="display: inline-block; width: 200px; background: #f0f0f0; ' +
                                'border-radius: 10px; overflow: hidden; margin-right: 10px;">' +
                                '<div style="background: #2271b1; height: 20px; width: ' + percentComplete + '%; transition: width 0.3s;"></div>' +
                                '</div>' +
                                processed + ' / ' + totalProducts + ' (' + percentComplete + '%)'
                            );
                            
                            $('#hts-bulk-status').html(
                                '<div style="padding: 10px; background: #fff; ' +
                                'border: 1px solid #c3c4c7; border-radius: 4px;">' +
                                'Processing batch ' + (batchIndex + 1) + ' of ' + batches.length + '<br>' +
                                '✓ Succeeded: ' + succeeded + ' | ✗ Failed: ' + failed +
                                '</div>'
                            );
                            
                            // Process next batch with a small delay to avoid rate limiting
                            setTimeout(function() {
                                processBatch(batchIndex + 1);
                            }, 1000); // 1 second delay between batches
                        });
                    }
                    
                    // Start processing
                    processBatch(0);
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
                    <li>If you see <span style="color: #d63638;">❌ Products without codes</span>, 
                        they'll auto-classify as you work</li>
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
                    <li>💡 <strong>Low confidence?</strong> The code is probably still correct, 
                        but double-check if shipping high-value items</li>
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
function hts_add_bulk_classify($bulk_actions)
{
    $bulk_actions['hts_classify'] = __('Generate HTS Codes', 'hts-manager');
    return $bulk_actions;
}

add_filter('handle_bulk_actions-edit-product', 'hts_handle_bulk_classify', 10, 3);
function hts_handle_bulk_classify($redirect_to, $action, $post_ids)
{
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
function hts_bulk_classify_notice()
{
    if (!empty($_REQUEST['hts_classified'])) {
        $count = intval($_REQUEST['hts_classified']);
        printf(
            '<div class="notice notice-success is-dismissible"><p>' .
            _n(
                'Queued %s product for HTS classification.',
                'Queued %s products for HTS classification.',
                $count,
                'hts-manager'
            ) .
            '</p></div>',
            $count
        );
    }
}

// ===============================================
// PART 8: DISPLAY ON FRONTEND (OPTIONAL)
// ===============================================

add_action('woocommerce_product_meta_end', 'hts_display_on_product_page');
function hts_display_on_product_page()
{
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
function hts_add_dashboard_widget()
{
    if (current_user_can('manage_woocommerce')) {
        wp_add_dashboard_widget(
            'hts_classification_status',
            '📦 HTS Classification Status',
            'hts_dashboard_widget_display'
        );
    }
}

function hts_dashboard_widget_display()
{
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
            
            <?php if ($without_codes > 0) : ?>
            <div class="hts-stat-row">
                <span class="hts-stat-label">❌ Products without codes:</span>
                <span class="hts-stat-value hts-status-error"><?php echo number_format($without_codes); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($pending_crons > 0) : ?>
            <div class="hts-stat-row">
                <span class="hts-stat-label">⏳ Pending classification:</span>
                <span class="hts-stat-value hts-status-warning"><?php echo number_format($pending_crons); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($low_confidence > 0) : ?>
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
            <?php if ($without_codes > 0) : ?>
            <a href="<?php echo admin_url('admin.php?page=hts-manager'); ?>" class="button button-primary">
                Classify Missing
            </a>
            <?php endif; ?>
            
            <?php if ($low_confidence > 0) : ?>
            <a href="<?php echo admin_url('admin.php?page=hts-manager'); ?>" class="button">
                Review Products
            </a>
            <?php endif; ?>
            
            <a href="<?php echo admin_url('admin.php?page=hts-manager'); ?>" class="button">
                Settings
            </a>
        </div>
        
        <?php if ($pending_crons > 0) : ?>
        <div class="hts-refresh-notice">
            ⏱️ Classifications in progress. Refresh in a minute to see updates.
        </div>
        <?php endif; ?>
        
        <div class="hts-refresh-notice" style="margin-top: 15px;">
            <a href="#" onclick="location.reload(); return false;">↻ Refresh Stats</a>
            <?php if ($with_codes === $total_published) : ?>
            | <span style="color: #00a32a;">✨ All products classified!</span>
            <?php endif; ?>
            | <a href="#" onclick="jQuery('#hts-quick-guide').toggle(); return false;">
                📖 Quick Guide</a>
        </div>
        
        <div id="hts-quick-guide" style="display: none; margin-top: 15px; padding: 15px; 
            background: #f8f9fa; border-left: 4px solid #2271b1; border-radius: 4px;">
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
function hts_product_save_notices()
{
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
                            The HTS code is being generated for this product. 
                            Refresh the page in a few seconds to see the result.
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

// ===============================================
// DUTIFY INTEGRATION - AUTOMATIC SYNC
// ===============================================

/**
 * Sync HTS code to Dutify plugin attributes
 * This function automatically syncs HTS codes to Dutify whenever they are saved
 */
function hts_sync_to_dutify($product_id)
{
    error_log('HTS Dutify Sync: Starting sync for product ID ' . $product_id);

    // Validate product ID
    $original_product_id = absint($product_id);
    if (!$original_product_id) {
        error_log('HTS Dutify Sync: Invalid product ID');
        return false;
    }

    // Check if Dutify plugin is active
    if (!class_exists('WOO_Dutify')) {
        error_log('HTS Dutify Sync: WOO_Dutify class not found - plugin may not be active');
        return false;
    }

    // Check if this is a variation and get parent ID if needed
    $post_type = get_post_type($original_product_id);
    $dutify_product_id = $original_product_id; // ID to sync to Dutify

    if ($post_type === 'product_variation') {
        // This is a variation, get the parent product ID for Dutify
        $parent_id = wp_get_post_parent_id($original_product_id);
        if ($parent_id) {
            error_log('HTS Dutify Sync: Product is a variation (ID: ' . $original_product_id . '), will sync to parent (ID: ' . $parent_id . ')');
            $dutify_product_id = $parent_id; // Dutify needs codes on parent
        }
    } elseif ($post_type !== 'product') {
        return false;
    }

    // Implement rate limiting using transients - but allow more for bulk operations
    $rate_limit_key = 'hts_dutify_sync_count';
    $sync_count = get_transient($rate_limit_key);
    
    // Check if this is a bulk operation (called via AJAX)
    $is_bulk = defined('DOING_AJAX') && DOING_AJAX;
    $rate_limit = $is_bulk ? 500 : 30; // Much higher limit for bulk operations

    if ($sync_count === false) {
        set_transient($rate_limit_key, 1, 60); // Reset every minute
    } elseif ($sync_count >= $rate_limit) {
        error_log('HTS Dutify Sync: Rate limit exceeded (' . $sync_count . '/' . $rate_limit . '), skipping sync for product ' . $original_product_id);
        return false;
    } else {
        set_transient($rate_limit_key, $sync_count + 1, 60);
    }

    // Get HTS Manager data from the ORIGINAL product ID (could be variation)
    $hts_code = sanitize_text_field(get_post_meta($original_product_id, '_hts_code', true));
    $country_of_origin = sanitize_text_field(get_post_meta($original_product_id, '_country_of_origin', true));
    
    // Default to Canada if no country is set (same as UI default)
    if (empty($country_of_origin)) {
        $country_of_origin = 'CA';
        // Also save it to the product meta for consistency
        update_post_meta($original_product_id, '_country_of_origin', 'CA');
        error_log('HTS Dutify Sync: No country set, using default: CA');
    }
    
    error_log('HTS Dutify Sync: Retrieved data - HTS: ' . $hts_code . ', Country: ' . $country_of_origin);
    error_log('HTS Dutify Sync: Taxonomy checks - pa_dutify_country_origin exists: ' . (taxonomy_exists('pa_dutify_country_origin') ? 'yes' : 'no'));

    if (empty($hts_code) || $hts_code === '9999.99.9999') {
        return false;
    }

    // Get the product (use Dutify product ID which might be parent)
    $product = wc_get_product($dutify_product_id);
    if (!$product) {
        return false;
    }

    $updated = false;

    // Sync HS Code attribute
    if (taxonomy_exists('pa_dutify_hs_code')) {
        error_log('HTS Dutify Sync: pa_dutify_hs_code taxonomy exists');

        // Remove dots and clean the HTS code (be more flexible with format)
        $clean_hs_code = preg_replace('/[^0-9]/', '', $hts_code);

        // Check if it's at least 6 digits (minimum for a valid HTS code)
        if (strlen($clean_hs_code) < 6) {
            error_log('HTS Dutify Sync: HTS code too short: ' . $hts_code);
            return false;
        }

        // Pad to 10 digits if needed (some codes might be 8 digits)
        if (strlen($clean_hs_code) < 10) {
            $clean_hs_code = str_pad($clean_hs_code, 10, '0', STR_PAD_RIGHT);
            error_log('HTS Dutify Sync: Padded HTS code to 10 digits: ' . $clean_hs_code);
        } elseif (strlen($clean_hs_code) > 10) {
            // Truncate if longer than 10
            $clean_hs_code = substr($clean_hs_code, 0, 10);
            error_log('HTS Dutify Sync: Truncated HTS code to 10 digits: ' . $clean_hs_code);
        }

        // Create or get the term - use the clean code directly as term name
        $term = term_exists($clean_hs_code, 'pa_dutify_hs_code');
        if (!$term) {
            error_log('HTS Dutify Sync: Creating new term: ' . $clean_hs_code);
            $term = wp_insert_term($clean_hs_code, 'pa_dutify_hs_code');
            if (is_wp_error($term)) {
                error_log('HTS Dutify Sync: Error creating term: ' . $term->get_error_message());
            }
        } else {
            error_log('HTS Dutify Sync: Term already exists: ' . $clean_hs_code);
        }

        if (!is_wp_error($term)) {
            // Get the term ID properly
            $term_id = is_array($term) ? $term['term_id'] : $term;
            if (!$term_id || $term_id < 1) {
                return false;
            }
            $result = wp_set_object_terms($dutify_product_id, intval($term_id), 'pa_dutify_hs_code');
            if (is_wp_error($result)) {
                error_log('HTS Dutify Sync: Error setting object terms: ' . $result->get_error_message());
            } else {
                error_log('HTS Dutify Sync: Successfully set term ' . $clean_hs_code . ' for product ' . $dutify_product_id);
            }

            // Also update the product attribute
            try {
                $attributes = $product->get_attributes();
                $hs_code_attribute = new WC_Product_Attribute();
                $hs_code_attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_dutify_hs_code'));
                $hs_code_attribute->set_name('pa_dutify_hs_code');
                $hs_code_attribute->set_options(array(intval($term_id)));
                $hs_code_attribute->set_visible(false);
                $hs_code_attribute->set_variation(false);

                $attributes['pa_dutify_hs_code'] = $hs_code_attribute;
                $product->set_attributes($attributes);
            } catch (Exception $e) {
                error_log('HTS Dutify Sync Error (HS Code): ' . $e->getMessage());
                return false;
            }
            $updated = true;
        }
    } else {
        error_log('HTS Dutify Sync: pa_dutify_hs_code taxonomy DOES NOT exist - Dutify may not be properly initialized');
    }

    // Sync Country of Origin attribute
    if (!taxonomy_exists('pa_dutify_country_origin')) {
        error_log('HTS Dutify Sync: WARNING - pa_dutify_country_origin taxonomy does not exist!');
        // Try to register it if Dutify function exists
        if (function_exists('wc_create_attribute')) {
            error_log('HTS Dutify Sync: Attempting to create missing country_origin attribute');
            $args = array(
                'slug'    => 'dutify_country_origin',
                'name'   => __('Dutify Country Origin', 'hts-manager'),
                'type'    => 'select',
                'orderby' => 'menu_order',
                'has_archives'  => false,
            );
            wc_create_attribute($args);
            register_taxonomy('pa_dutify_country_origin', 'product');
        }
    }
    
    if (taxonomy_exists('pa_dutify_country_origin') && !empty($country_of_origin)) {
        error_log('HTS Dutify Sync: Starting country sync with value: ' . $country_of_origin);
        
        // Country is already stored as a 2-letter code (CA, US, etc.)
        $country_code = strtoupper(trim($country_of_origin));
        error_log('HTS Dutify Sync: Using country code: ' . $country_code);

        // Validate it's a 2-letter code (or OTHER)
        if (!preg_match('/^[A-Z]{2}$/', $country_code) && $country_code !== 'OTHER') {
            error_log('HTS Dutify Sync: Invalid country code format (' . $country_code . '), using default CA');
            $country_code = 'CA'; // Default fallback
        }
        
        // Convert OTHER to a default code
        if ($country_code === 'OTHER') {
            $country_code = 'XX'; // Use XX for unknown countries
        }

        // Create or get the term
        $term = term_exists($country_code, 'pa_dutify_country_origin');
        if (!$term) {
            error_log('HTS Dutify Sync: Creating new country term: ' . $country_code);
            $term = wp_insert_term($country_code, 'pa_dutify_country_origin');
            if (is_wp_error($term)) {
                error_log('HTS Dutify Sync: Error creating country term: ' . $term->get_error_message());
            }
        } else {
            error_log('HTS Dutify Sync: Country term already exists: ' . $country_code);
        }

        if (!is_wp_error($term)) {
            $result = wp_set_object_terms($dutify_product_id, $country_code, 'pa_dutify_country_origin');
            if (is_wp_error($result)) {
                error_log('HTS Dutify Sync: Error setting country terms: ' . $result->get_error_message());
            } else {
                error_log('HTS Dutify Sync: Successfully set country term for product ' . $dutify_product_id);
                $updated = true; // Mark as updated when terms are set successfully
            }

            // Also update the product attribute
            try {
                $term_id = is_array($term) ? $term['term_id'] : $term;
                $attributes = $product->get_attributes();
                $country_attribute = new WC_Product_Attribute();
                $country_attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_dutify_country_origin'));
                $country_attribute->set_name('pa_dutify_country_origin');
                $country_attribute->set_options(array(intval($term_id)));
                $country_attribute->set_visible(false);
                $country_attribute->set_variation(false);

                $attributes['pa_dutify_country_origin'] = $country_attribute;
                $product->set_attributes($attributes);
                $updated = true;
            } catch (Exception $e) {
                error_log('HTS Dutify Sync Error (Country): ' . $e->getMessage());
            }
        }
    }

    // Sync HS Code Country (default to US for North American trade)
    if (taxonomy_exists('pa_dutify_hs_code_country')) {
        $hs_country = 'US'; // Default to US for HTS codes

        $term = term_exists($hs_country, 'pa_dutify_hs_code_country');
        if (!$term) {
            $term = wp_insert_term($hs_country, 'pa_dutify_hs_code_country');
        }

        if (!is_wp_error($term)) {
            wp_set_object_terms($dutify_product_id, $hs_country, 'pa_dutify_hs_code_country');

            try {
                $term_id = is_array($term) ? $term['term_id'] : $term;
                $attributes = $product->get_attributes();
                $hs_country_attribute = new WC_Product_Attribute();
                $hs_country_attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_dutify_hs_code_country'));
                $hs_country_attribute->set_name('pa_dutify_hs_code_country');
                $hs_country_attribute->set_options(array(intval($term_id)));
                $hs_country_attribute->set_visible(false);
                $hs_country_attribute->set_variation(false);

                $attributes['pa_dutify_hs_code_country'] = $hs_country_attribute;
                $product->set_attributes($attributes);
                $updated = true;
            } catch (Exception $e) {
                error_log('HTS Dutify Sync Error (HS Country): ' . $e->getMessage());
            }
        }
    }

    if ($updated) {
        // Use try-catch to handle potential save errors
        try {
            // Temporarily remove our own hooks to prevent infinite loops
            remove_action('woocommerce_process_product_meta', 'hts_save_product_data_fields');
            remove_action('transition_post_status', 'hts_auto_classify_on_publish', 10);
            remove_action('save_post_product', 'hts_auto_classify_on_save', 10);

            $product->save();

            // Re-add hooks
            add_action('woocommerce_process_product_meta', 'hts_save_product_data_fields');
            add_action('transition_post_status', 'hts_auto_classify_on_publish', 10, 3);
            add_action('save_post_product', 'hts_auto_classify_on_save', 10, 3);
        } catch (Exception $e) {
            error_log('HTS Dutify Sync Save Error: ' . $e->getMessage());
            return false;
        }
    }

    // If we got this far and processed the HTS code, consider it successful
    // even if no changes were needed (already synced)
    if (!empty($hts_code) && $hts_code !== '9999.99.9999') {
        return true; // Return true if we have a valid HTS code, regardless of whether updates were needed
    }
    
    return $updated;
}

// Hook into various save/import processes for Dutify sync
add_action('hts_code_imported', 'hts_sync_to_dutify');
add_action('woocommerce_api_edit_product', 'hts_check_api_update_for_sync', 10, 2);
add_action('woocommerce_rest_insert_product', 'hts_check_rest_api_sync', 10, 2);

// ===============================================
// BULK EDIT SUPPORT FOR HTS CODES
// ===============================================

// Add fields to bulk edit form
add_action('woocommerce_product_bulk_edit_end', 'hts_add_bulk_edit_fields');
function hts_add_bulk_edit_fields() {
    ?>
    <label>
        <span class="title"><?php _e('HTS Code', 'hts-manager'); ?></span>
        <span class="input-text-wrap">
            <input type="text" name="_hts_code" class="text" placeholder="<?php _e('— No change —', 'woocommerce'); ?>" value="">
        </span>
    </label>
    
    <label>
        <span class="title"><?php _e('Country of Origin', 'hts-manager'); ?></span>
        <span class="input-text-wrap">
            <select name="_country_of_origin" class="select">
                <option value=""><?php _e('— No change —', 'woocommerce'); ?></option>
                <option value="CA"><?php _e('Canada', 'hts-manager'); ?></option>
                <option value="US"><?php _e('United States', 'hts-manager'); ?></option>
                <option value="MX"><?php _e('Mexico', 'hts-manager'); ?></option>
                <option value="CN"><?php _e('China', 'hts-manager'); ?></option>
                <option value="GB"><?php _e('United Kingdom', 'hts-manager'); ?></option>
                <option value="DE"><?php _e('Germany', 'hts-manager'); ?></option>
                <option value="FR"><?php _e('France', 'hts-manager'); ?></option>
                <option value="IT"><?php _e('Italy', 'hts-manager'); ?></option>
                <option value="JP"><?php _e('Japan', 'hts-manager'); ?></option>
                <option value="KR"><?php _e('South Korea', 'hts-manager'); ?></option>
                <option value="TW"><?php _e('Taiwan', 'hts-manager'); ?></option>
                <option value="IN"><?php _e('India', 'hts-manager'); ?></option>
                <option value="VN"><?php _e('Vietnam', 'hts-manager'); ?></option>
                <option value="TH"><?php _e('Thailand', 'hts-manager'); ?></option>
                <option value="OTHER"><?php _e('Other', 'hts-manager'); ?></option>
            </select>
        </span>
    </label>
    <?php
}

// Save bulk edit data
add_action('woocommerce_product_bulk_edit_save', 'hts_save_bulk_edit_fields');
function hts_save_bulk_edit_fields($product) {
    // Security check - verify user can edit products
    if (!current_user_can('edit_products')) {
        return;
    }
    
    // Validate product object
    if (!$product || !is_a($product, 'WC_Product')) {
        return;
    }
    
    $product_id = $product->get_id();
    if (!$product_id) {
        return;
    }
    
    $updated = false;
    
    // Update HTS Code if provided
    if (isset($_REQUEST['_hts_code']) && $_REQUEST['_hts_code'] !== '') {
        $hts_code = sanitize_text_field($_REQUEST['_hts_code']);
        
        // Validate HTS code format (optional - remove if you want to allow any format)
        if (preg_match('/^\d{4}\.?\d{2}\.?\d{4}$/', str_replace(' ', '', $hts_code))) {
            update_post_meta($product_id, '_hts_code', $hts_code);
            update_post_meta($product_id, '_hts_updated', current_time('mysql'));
            $updated = true;
        }
    }
    
    // Update Country of Origin if provided
    if (isset($_REQUEST['_country_of_origin']) && $_REQUEST['_country_of_origin'] !== '') {
        $country = sanitize_text_field($_REQUEST['_country_of_origin']);
        
        // Validate country code (2 letters or 'OTHER')
        if (preg_match('/^[A-Z]{2}$/', $country) || $country === 'OTHER') {
            update_post_meta($product_id, '_country_of_origin', $country);
            $updated = true;
        }
    }
    
    // Sync to Dutify if any HTS data was updated
    if ($updated && class_exists('WOO_Dutify')) {
        try {
            hts_sync_to_dutify($product_id);
        } catch (Exception $e) {
            error_log('HTS Bulk Edit: Dutify sync failed for product ' . $product_id . ': ' . $e->getMessage());
        }
    }
}

// AJAX handler for testing Dutify sync
add_action('wp_ajax_test_dutify_sync', 'hts_ajax_test_dutify_sync');
function hts_ajax_test_dutify_sync()
{
    if (!check_ajax_referer('test_dutify_sync', '_wpnonce', false)) {
        wp_die('Security check failed');
    }

    $product_id = intval($_POST['product_id']);

    // Enable error reporting for this request
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $response = array(
        'product_id' => $product_id,
        'hts_code' => get_post_meta($product_id, '_hts_code', true),
        'country' => get_post_meta($product_id, '_country_of_origin', true),
        'dutify_class_exists' => class_exists('WOO_Dutify'),
        'taxonomy_exists' => taxonomy_exists('pa_dutify_hs_code'),
    );

    // Try to sync
    if (function_exists('hts_sync_to_dutify')) {
        $response['sync_result'] = hts_sync_to_dutify($product_id);
    } else {
        $response['sync_result'] = 'Function not found';
    }

    // Check result
    $dutify_hs = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
    $response['dutify_hs_after'] = $dutify_hs ? implode(', ', $dutify_hs) : 'NOT SET';

    wp_send_json($response);
}

// AJAX handler for bulk Dutify sync
add_action('wp_ajax_bulk_sync_dutify', 'hts_ajax_bulk_sync_dutify');
function hts_ajax_bulk_sync_dutify()
{
    if (!check_ajax_referer('bulk_sync_dutify', '_wpnonce', false)) {
        wp_send_json_error(array('message' => 'Security check failed'));
        return;
    }

    $products = isset($_POST['products']) ? array_map('intval', $_POST['products']) : array();
    $batch = intval($_POST['batch']);
    $total_batches = intval($_POST['total_batches']);

    if (empty($products)) {
        wp_send_json_error(array('message' => 'No products provided'));
        return;
    }

    $synced = 0;
    $errors = 0;
    $messages = array();

    // Disable error logging temporarily to avoid clutter
    $original_log_errors = ini_get('log_errors');
    ini_set('log_errors', 0);

    // Reset time limit for each batch to prevent timeout
    @set_time_limit(30);
    
    foreach ($products as $product_id) {
        // Clear any previous product from memory
        wp_cache_delete($product_id, 'posts');
        wp_cache_delete($product_id, 'post_meta');
        
        $product = wc_get_product($product_id);
        if (!$product) {
            $errors++;
            $messages[] = "❌ Product ID $product_id not found";
            continue;
        }

        $product_name = $product->get_name();
        $product_type = $product->get_type();

        // Skip variable products (parent products) - only sync simple products and variations
        if ($product_type === 'variable') {
            // Variable products don't have their own HTS codes - their variations do
            $messages[] = "⏭️ " . $product_name . " - Skipped (variable product parent)";
            continue;
        }

        $hts_code = get_post_meta($product_id, '_hts_code', true);
        if (empty($hts_code) || $hts_code === '9999.99.9999') {
            $errors++;
            $messages[] = "⚠️ " . $product_name . " - No valid HTS code";
            continue;
        }

        // Try to sync with better error capture
        try {
            // Debug: Log what we're trying to sync
            error_log('Bulk sync attempting: Product ' . $product_id . ' (' . $product_name . ') - Type: ' . $product_type);
            
            $result = hts_sync_to_dutify($product_id);
            if ($result === true) {
                $synced++;

                // For variations, Dutify data is on the parent product
                $check_product_id = $product_id;
                if ($product_type === 'variation') {
                    $parent_id = wp_get_post_parent_id($product_id);
                    if ($parent_id) {
                        $check_product_id = $parent_id;
                    }
                }

                // Verify sync actually worked
                $dutify_hs = wc_get_product_terms($check_product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
                $synced_code = $dutify_hs ? array_shift($dutify_hs) : null;

                if ($synced_code) {
                    $messages[] = "✅ " . $product_name . " - Synced (HTS: " . $synced_code . ")";
                } else {
                    $messages[] = "✅ " . $product_name . " - Sync completed";
                }
            } else {
                $errors++;

                // Try to get more specific error info
                $error_reason = "Unknown error";

                // Check various failure conditions
                if (!taxonomy_exists('pa_dutify_hs_code')) {
                    $error_reason = "Dutify taxonomy missing";
                } elseif ($product_type === 'variation') {
                    $error_reason = "Variation product - may need parent sync";
                } elseif (!preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
                    $error_reason = "Invalid HTS format: " . $hts_code;
                } else {
                    // Check if the product couldn't be loaded for Dutify
                    $test_product = wc_get_product($product_id);
                    if (!$test_product) {
                        $error_reason = "Product not found";
                    } else {
                        // Check if HTS code is too short when cleaned
                        $clean_hs = preg_replace('/[^0-9]/', '', $hts_code);
                        if (strlen($clean_hs) < 6) {
                            $error_reason = "HTS code too short: " . $hts_code;
                        } else {
                            $error_reason = "Sync returned false - check logs";
                        }
                    }
                }

                $messages[] = "❌ " . $product_name . " - Sync failed (" . $error_reason . ")";
            }
        } catch (Exception $e) {
            $errors++;
            $messages[] = "❌ " . $product_name . " - Error: " . $e->getMessage();
        }
    }

    // Restore error logging
    ini_set('log_errors', $original_log_errors);

    $messages[] = "Batch $batch of $total_batches completed: $synced synced, $errors errors";

    wp_send_json_success(array(
        'synced' => $synced,
        'errors' => $errors,
        'messages' => $messages,
        'batch' => $batch,
        'total_batches' => $total_batches
    ));
}

function hts_check_api_update_for_sync($id, $data)
{
    // Validate product ID
    $id = absint($id);
    if (!$id) {
        return;
    }

    if (isset($data['meta_data']) && is_array($data['meta_data'])) {
        foreach ($data['meta_data'] as $meta) {
            if (isset($meta['key']) && $meta['key'] === '_hts_code' && !empty($meta['value'])) {
                // Validate HTS code format before syncing
                $hts_code = sanitize_text_field($meta['value']);
                if (preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
                    hts_sync_to_dutify($id);
                }
                break;
            }
        }
    }
}

function hts_check_rest_api_sync($post, $request)
{
    // Validate post object and ID
    if (!is_object($post) || !isset($post->ID)) {
        return;
    }

    $product_id = absint($post->ID);
    if (!$product_id) {
        return;
    }

    $params = $request->get_params();

    if (isset($params['meta_data']) && is_array($params['meta_data'])) {
        foreach ($params['meta_data'] as $meta) {
            if (isset($meta['key']) && $meta['key'] === '_hts_code' && !empty($meta['value'])) {
                // Validate HTS code format before syncing
                $hts_code = sanitize_text_field($meta['value']);
                if (preg_match('/^\d{4}\.\d{2}\.\d{4}$/', $hts_code)) {
                    hts_sync_to_dutify($product_id);
                }
                break;
            }
        }
    }
}
