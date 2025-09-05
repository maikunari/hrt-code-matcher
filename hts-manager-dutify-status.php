<?php
/**
 * Add Dutify sync status indicator to HTS Manager tab
 * Add this code to hts-manager.php or include as separate file
 */

// Add sync status display to the HTS Codes tab
add_action('woocommerce_product_data_panels', 'hts_add_dutify_sync_status', 11);
function hts_add_dutify_sync_status() {
    global $post;
    $product_id = $post->ID;
    
    // Check if Dutify is active
    if (!class_exists('WOO_Dutify')) {
        return;
    }
    
    // Get current HTS data
    $hts_code = get_post_meta($product_id, '_hts_code', true);
    $country = get_post_meta($product_id, '_country_of_origin', true);
    
    // Get Dutify attribute values
    $dutify_hs = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
    $dutify_country = wc_get_product_terms($product_id, 'pa_dutify_country_origin', array('fields' => 'names'));
    $dutify_hs_country = wc_get_product_terms($product_id, 'pa_dutify_hs_code_country', array('fields' => 'names'));
    
    $dutify_hs_value = $dutify_hs ? array_shift($dutify_hs) : null;
    $dutify_country_value = $dutify_country ? array_shift($dutify_country) : null;
    $dutify_hs_country_value = $dutify_hs_country ? array_shift($dutify_hs_country) : null;
    
    // Determine sync status
    $expected_hs = preg_replace('/[^0-9]/', '', $hts_code);
    $expected_country = strtoupper(substr($country ?: 'CA', 0, 2));
    
    $hs_synced = ($dutify_hs_value === $expected_hs);
    $country_synced = ($dutify_country_value === $expected_country);
    $all_synced = $hs_synced && $country_synced && $dutify_hs_country_value;
    
    ?>
    <div id="dutify_sync_status" style="padding: 15px; background: #f8f9fa; border-top: 1px solid #ddd; margin-top: 20px;">
        <h4 style="margin-top: 0;">🔄 Dutify Integration Status</h4>
        
        <?php if (!empty($hts_code)) : ?>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 5px;"><strong>HTS Code Sync:</strong></td>
                    <td style="padding: 5px;">
                        <?php if ($hs_synced) : ?>
                            <span style="color: green;">✅ Synced</span>
                            <code style="margin-left: 10px;"><?php echo esc_html($dutify_hs_value); ?></code>
                        <?php else : ?>
                            <span style="color: red;">❌ Not synced</span>
                            <?php if ($dutify_hs_value) : ?>
                                <code style="margin-left: 10px;">Current: <?php echo esc_html($dutify_hs_value); ?></code>
                            <?php endif; ?>
                            <code style="margin-left: 10px;">Expected: <?php echo esc_html($expected_hs); ?></code>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <td style="padding: 5px;"><strong>Country Sync:</strong></td>
                    <td style="padding: 5px;">
                        <?php if ($country_synced) : ?>
                            <span style="color: green;">✅ Synced</span>
                            <code style="margin-left: 10px;"><?php echo esc_html($dutify_country_value); ?></code>
                        <?php else : ?>
                            <span style="color: red;">❌ Not synced</span>
                            <?php if ($dutify_country_value) : ?>
                                <code style="margin-left: 10px;">Current: <?php echo esc_html($dutify_country_value); ?></code>
                            <?php endif; ?>
                            <code style="margin-left: 10px;">Expected: <?php echo esc_html($expected_country); ?></code>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <td style="padding: 5px;"><strong>HS Country:</strong></td>
                    <td style="padding: 5px;">
                        <?php if ($dutify_hs_country_value) : ?>
                            <span style="color: green;">✅ Set</span>
                            <code style="margin-left: 10px;"><?php echo esc_html($dutify_hs_country_value); ?></code>
                        <?php else : ?>
                            <span style="color: red;">❌ Not set</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <?php if ($all_synced) : ?>
                <div style="margin-top: 10px; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">
                    <strong style="color: #155724;">✅ Ready for Dutify checkout calculations!</strong>
                </div>
            <?php else : ?>
                <div style="margin-top: 10px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">
                    <strong style="color: #721c24;">⚠️ Not fully synced to Dutify</strong>
                    <br><small>Save the product to trigger sync, or check error logs if problem persists.</small>
                    <br>
                    <button type="button" class="button" id="force_dutify_sync" style="margin-top: 5px;">
                        Force Sync Now
                    </button>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    $('#force_dutify_sync').on('click', function() {
                        var button = $(this);
                        button.prop('disabled', true).text('Syncing...');
                        
                        // Trigger save to force sync
                        $('#publish').click();
                    });
                });
                </script>
            <?php endif; ?>
            
        <?php else : ?>
            <p style="color: #666;">No HTS code set. Add an HTS code to enable Dutify integration.</p>
        <?php endif; ?>
        
        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
            <small style="color: #666;">
                💡 <strong>Tip:</strong> Dutify attributes are set programmatically. You don't need to manually select them in the Attributes tab.
            </small>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Move sync status to HTS panel
        $('#dutify_sync_status').appendTo('#hts_product_data');
    });
    </script>
    <?php
}

// Add admin column to show sync status in products list
add_filter('manage_product_posts_columns', 'hts_add_dutify_column');
function hts_add_dutify_column($columns) {
    if (class_exists('WOO_Dutify')) {
        $columns['dutify_sync'] = 'Dutify';
    }
    return $columns;
}

add_action('manage_product_posts_custom_column', 'hts_show_dutify_column', 10, 2);
function hts_show_dutify_column($column, $post_id) {
    if ($column === 'dutify_sync') {
        $hts_code = get_post_meta($post_id, '_hts_code', true);
        
        if (empty($hts_code)) {
            echo '<span style="color: #999;">—</span>';
            return;
        }
        
        // Check if synced
        $dutify_hs = wc_get_product_terms($post_id, 'pa_dutify_hs_code', array('fields' => 'names'));
        $dutify_hs_value = $dutify_hs ? array_shift($dutify_hs) : null;
        $expected_hs = preg_replace('/[^0-9]/', '', $hts_code);
        
        if ($dutify_hs_value === $expected_hs) {
            echo '<span style="color: green;" title="HTS: ' . esc_attr($dutify_hs_value) . '">✅</span>';
        } else {
            echo '<span style="color: red;" title="Not synced">❌</span>';
        }
    }
}