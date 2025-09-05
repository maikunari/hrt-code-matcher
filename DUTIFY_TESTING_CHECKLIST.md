# Dutify Integration Testing Checklist

## Pre-Testing Setup ✅

### 1. Plugin Installation
- [ ] Copy both plugins to WordPress plugins directory:
  ```bash
  cp -r /Users/michaelsewell/Projects/hts-code-matcher/hts-manager /path/to/wordpress/wp-content/plugins/
  cp -r /Users/michaelsewell/Projects/hts-code-matcher/woo-dutify /path/to/wordpress/wp-content/plugins/
  ```

### 2. Activate Plugins (in this order)
- [ ] WooCommerce (already active)
- [ ] Dutify for WooCommerce
- [ ] HTS Manager for WooCommerce

### 3. Configure API Keys
- [ ] **HTS Manager**: WooCommerce → HTS Manager → Add Anthropic API key
- [ ] **Dutify**: WooCommerce → Settings → Dutify tab → Add Dutify API key

### 4. Verify Attributes Created
- [ ] Go to Products → Attributes
- [ ] Confirm these 4 attributes exist:
  - Dutify Classification ID
  - Dutify Country of Origin
  - Dutify HS Code  
  - Dutify HS Code Country

---

## Integration Tests 🧪

### Test 1: Manual HTS Entry with Country
1. [ ] Edit any product
2. [ ] Go to **HTS Codes** tab (NOT Attributes tab)
3. [ ] Enter HTS code: `8476.90.9000`
4. [ ] Select Country: `Canada`
5. [ ] Click "Update" to save product
6. [ ] Check Attributes tab - should show:
   - Dutify HS Code: `8476909000` (no periods)
   - Dutify Country of Origin: `CA`
   - Dutify HS Code Country: `US`

### Test 2: Auto-Generate with AI
1. [ ] Edit a product without HTS code
2. [ ] Go to HTS Codes tab
3. [ ] Click "Auto-Generate with AI" button
4. [ ] Wait for generation
5. [ ] Save product
6. [ ] Verify Dutify attributes are populated

### Test 3: Country Change Sync
1. [ ] Edit product with existing HTS code
2. [ ] Change Country of Origin to "United States"
3. [ ] Save product
4. [ ] Check Attributes tab - Dutify Country should now be `US`

### Test 4: Bulk Classification
1. [ ] Go to Products list
2. [ ] Select 2-3 products without HTS codes
3. [ ] Bulk Actions → Generate HTS Codes
4. [ ] Wait for completion
5. [ ] Check those products - Dutify attributes should be set

### Test 5: Python Import Sync
1. [ ] Run classification script:
   ```bash
   cd /Users/michaelsewell/Projects/hts-code-matcher
   python classify_recent_fixed.py
   python push_recent_only.py
   ```
2. [ ] Check a classified product in WordPress
3. [ ] Verify Dutify attributes are populated

---

## Checkout Testing 💳

### Test 6: Duty Calculation at Checkout
1. [ ] Add product with HTS code to cart
2. [ ] Go to checkout
3. [ ] Enter shipping address:
   - **Country**: United States (or any country different from store)
   - **State**: New York
   - **Zip**: 10001
4. [ ] **Expected**: Dutify should show:
   - Duty amount
   - Tax amount
   - Total landed cost
5. [ ] If no duties show, check:
   - Browser console for JavaScript errors
   - WooCommerce → Status → Logs for Dutify errors

### Test 7: Domestic Shipping (No Duties)
1. [ ] Change shipping to Canadian address (same as store country)
2. [ ] **Expected**: No duties shown (domestic shipment)

---

## Validation Checks ✔️

### Data Integrity
- [ ] HTS codes stored WITH periods in `_hts_code` meta
- [ ] Dutify attributes stored WITHOUT periods
- [ ] Country codes are 2-letter uppercase (CA, US, etc.)
- [ ] No duplicate attribute terms created

### User Experience  
- [ ] Store employees ONLY use HTS Codes tab
- [ ] Never need to touch Attributes tab
- [ ] All syncing happens automatically
- [ ] No manual intervention required

### Error Handling
- [ ] Invalid HTS codes (like 9999.99.9999) don't sync
- [ ] Missing country defaults to CA
- [ ] Plugin handles missing Dutify gracefully

---

## Troubleshooting 🔧

### If Duties Don't Show at Checkout:
1. Check Dutify API key is valid
2. Verify product has all 3 Dutify attributes set
3. Check WooCommerce logs for API errors
4. Ensure shipping address is international
5. Test with a simple product (not variable/grouped)

### If Attributes Not Syncing:
1. Manually trigger sync by re-saving product
2. Check PHP error log for sync errors
3. Verify both plugins are active
4. Run verification script:
   ```bash
   php /Users/michaelsewell/Projects/hts-code-matcher/verify-dutify-ready.php
   ```

### Quick Database Check:
```sql
-- Check if product has Dutify attributes
SELECT p.ID, p.post_title, 
       pm.meta_value as hts_code,
       GROUP_CONCAT(t.name) as dutify_attrs
FROM wp_posts p
LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id 
  AND pm.meta_key = '_hts_code'
LEFT JOIN wp_term_relationships tr ON p.ID = tr.object_id
LEFT JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
LEFT JOIN wp_terms t ON tt.term_id = t.term_id
WHERE p.ID = [PRODUCT_ID]
  AND tt.taxonomy LIKE 'pa_dutify%'
GROUP BY p.ID;
```

---

## Sign-off 📝

- **Tester**: _______________
- **Date**: _______________
- **All Tests Passed**: [ ] Yes [ ] No
- **Ready for Production**: [ ] Yes [ ] No

### Notes:
_________________________________
_________________________________