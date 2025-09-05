# Dutify Integration with HTS Manager

## Overview
This document describes the automatic synchronization between HTS Manager and the Dutify plugin for WooCommerce.

## How It Works

### Data Mapping
The integration automatically syncs HTS codes from HTS Manager to Dutify's product attributes:

| HTS Manager (Post Meta) | Dutify (Product Attributes) | Notes |
|------------------------|----------------------------|-------|
| `_hts_code` | `pa_dutify_hs_code` | Dots removed (e.g., 1234.56.7890 → 1234567890) |
| `_country_of_origin` | `pa_dutify_country_origin` | 2-letter country code (e.g., CA) |
| N/A | `pa_dutify_hs_code_country` | Defaults to "US" for North American trade |

### Automatic Sync Triggers

The sync happens automatically whenever:

1. **Product Classification via HTS Manager UI**
   - When clicking "Generate HTS Code" button
   - When manually entering HTS code and saving

2. **Auto-Classification on Product Publish**
   - New products published without HTS codes
   - Existing products updated (if auto-classify is enabled)

3. **Python Script Imports**
   - When running `push_recent_only.py`
   - When running `push_todays_codes.py`
   - When using `main.py` to push codes to WooCommerce

4. **WooCommerce API Updates**
   - REST API updates that include `_hts_code` meta
   - Legacy API updates with HTS code meta

### Sync Function Location

The main sync function `hts_sync_to_dutify()` is located at the end of:
`/hts-manager/hts-manager.php` (lines 1695-1806)

### Requirements

1. **Dutify Plugin Must Be Active**
   - The sync only runs if the Dutify plugin is installed and activated
   - Checks for `class_exists('WOO_Dutify')`

2. **Valid HTS Code Required**
   - Code must not be empty
   - Code must not be the placeholder value `9999.99.9999`

3. **Product Attributes Must Exist**
   - Dutify creates these on activation
   - The sync checks for their existence before updating

## Testing the Integration

### Manual Test
1. Edit any product in WooCommerce
2. Go to the "HTS Codes" tab
3. Enter an HTS code (e.g., `1234.56.7890`)
4. Save the product
5. Check the product's attributes - you should see:
   - Dutify HS Code: `1234567890`
   - Dutify Country of Origin: `CA` (or your set country)
   - Dutify HS Code Country: `US`

### Automatic Classification Test
1. Create a new product
2. Add name, description, and publish
3. Wait ~5-10 seconds for auto-classification
4. Refresh the product edit page
5. Check both HTS Codes tab and Attributes - both should be populated

### Python Script Test
```bash
# Classify recent products
python classify_recent_fixed.py

# Push to WooCommerce (this triggers sync)
python push_recent_only.py
```

## Troubleshooting

### Sync Not Working?

1. **Check Dutify is Active**
   ```php
   // In WordPress admin, go to Plugins
   // Ensure "WooCommerce Dutify" is activated
   ```

2. **Check Product Attributes Exist**
   - Go to Products → Attributes in WordPress admin
   - Look for:
     - Dutify Classification ID
     - Dutify Country of Origin
     - Dutify HS Code
     - Dutify HS Code Country

3. **Check Error Logs**
   ```bash
   # Check WordPress debug log
   tail -f wp-content/debug.log
   ```

4. **Force Re-sync All Products**
   - Use the bulk operations in HTS Manager
   - Or run a custom script to trigger `hts_sync_to_dutify()` for all products

### Manual Sync for Single Product

If you need to manually trigger sync for a specific product:

```php
// In WordPress admin, go to Tools → Site Health → Info → Tools
// Or use WP-CLI:
wp eval "hts_sync_to_dutify(123);" # Replace 123 with product ID
```

## Benefits

1. **Automatic Duty Calculation**: Dutify can now calculate duties using the HTS codes
2. **No Manual Entry**: Eliminates duplicate data entry between systems
3. **Consistent Data**: Ensures HTS codes are synchronized across both plugins
4. **Seamless Workflow**: Works with existing classification workflows

## Notes

- The sync is one-way: HTS Manager → Dutify
- Changes made directly in Dutify attributes won't sync back to HTS Manager
- The country of origin defaults to "CA" (Canada) but can be changed in HTS Manager
- HS Code Country defaults to "US" for North American trade agreements