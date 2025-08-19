# ShipStation HTS Code Integration

This integration automatically passes HTS codes and Country of Origin from WooCommerce products to ShipStation for customs declarations.

## What It Does

1. **Automatically populates ShipStation customs forms** with:
   - HTS/Harmonization codes (from your product data)
   - Country of Origin (defaults to Canada if not set)
   - Customs descriptions
   - Customs values

2. **No manual entry required** - When you create a shipment in ShipStation, the customs information is already filled in.

## Installation

### Prerequisites
1. WooCommerce must be installed and active
2. ShipStation plugin must be installed and active
3. HTS codes must be assigned to products (using our HTS matcher or manually)

### Step 1: Install the HTS Display Plugin
Upload and activate `hts-display-secure.php` to your WordPress plugins directory. This adds HTS code fields to your products.

### Step 2: Install the ShipStation Integration
Upload and activate `hts-shipstation-integration.php` to your WordPress plugins directory.

### Step 3: Verify Installation
1. Go to WooCommerce → Orders
2. You should see a notice: "HTS to ShipStation Integration: Active ✓"

## How It Works

### Data Flow:
```
WooCommerce Product 
    ↓
[HTS Code: 6109.10.0012]
[Country: CA]
    ↓
Order Created
    ↓
ShipStation Sync
    ↓
Customs Form Auto-Filled
```

### Field Mapping:

| WooCommerce Field | ShipStation Field | Format |
|------------------|-------------------|---------|
| `_hts_code` | Harmonization | 6109100012 (dots removed) |
| `_country_of_origin` | Country | CA (2-letter code) |
| Product Name | CustomsDescription | Text (max 200 chars) |
| Item Price | CustomsValue | Decimal |

## Testing

### Test in ShipStation:
1. Create a test order in WooCommerce
2. Wait for ShipStation to sync (or manually sync)
3. Open the order in ShipStation
4. Click "Create Label"
5. Check the Customs form - it should show:
   - Harmonization field populated with HTS code
   - Country field showing origin country
   - Description and value filled in

### Debug Mode:
To enable debug logging, add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at: `/wp-content/debug.log`

## Troubleshooting

### HTS Codes Not Appearing in ShipStation:

1. **Check product has HTS code:**
   - Edit product in WooCommerce
   - Go to "HTS Codes" tab
   - Verify code is present and valid

2. **Check format:**
   - HTS codes must be in format: ####.##.####
   - Invalid codes (9999.99.9999) are skipped

3. **Verify ShipStation sync:**
   - Go to WooCommerce → Settings → Shipping → ShipStation
   - Click "Test Connection"
   - Check last sync time

### Country Not Showing:

- If no country is set, defaults to "CA" (Canada)
- To change default, edit line 87 in `hts-shipstation-integration.php`

### Custom Fields Alternative:

If the primary method doesn't work, the plugin also populates:
- Custom Field 2: HTS codes (format: "SKU:HTS, SKU:HTS")
- Custom Field 3: Countries of origin

You can map these in ShipStation settings if needed.

## API Integration Details

The plugin hooks into ShipStation's XML export at two points:

1. **`woocommerce_shipstation_export_item_xml`** - Primary method
   - Adds customs data directly to each line item
   - Most reliable method

2. **`woocommerce_shipstation_export_order_xml`** - Backup method
   - Adds a CustomsItems section to the order
   - Used by some ShipStation configurations

## Bulk Operations

### Update All Products with Default Country:
If you want all products to default to Canada, run this SQL:
```sql
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT ID, '_country_of_origin', 'CA'
FROM wp_posts 
WHERE post_type = 'product' 
AND ID NOT IN (
    SELECT post_id FROM wp_postmeta 
    WHERE meta_key = '_country_of_origin'
);
```

### Export HTS Codes for Review:
```sql
SELECT 
    p.ID,
    p.post_title as product_name,
    pm1.meta_value as sku,
    pm2.meta_value as hts_code,
    pm3.meta_value as country_of_origin
FROM wp_posts p
LEFT JOIN wp_postmeta pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_sku'
LEFT JOIN wp_postmeta pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_hts_code'
LEFT JOIN wp_postmeta pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_country_of_origin'
WHERE p.post_type = 'product'
ORDER BY p.post_title;
```

## Support

For issues or questions:
1. Check WooCommerce logs: WooCommerce → Status → Logs
2. Check ShipStation sync status
3. Verify both plugins are active
4. Enable debug mode for detailed logging