# HTS Manager - Dutify Integration Testing Checklist

## Pre-Testing Setup

1. **Verify Installation**
   - [ ] HTS Manager plugin is activated
   - [ ] WooCommerce Dutify plugin is activated
   - [ ] API key is configured in HTS Manager settings
   
2. **Check Taxonomies**
   - [ ] Go to Products → Attributes
   - [ ] Verify these attributes exist:
     - Dutify HS Code (`pa_dutify_hs_code`)
     - Dutify Country of Origin (`pa_dutify_country_origin`) 
     - Dutify HS Code Country (`pa_dutify_hs_code_country`)

## Functional Tests

### Test 1: Manual HTS Code Entry
1. [ ] Edit an existing product
2. [ ] Go to HTS Codes tab
3. [ ] Enter HTS code: `1234.56.7890`
4. [ ] Enter Country: `CA`
5. [ ] Save product
6. [ ] Check Attributes tab - should show:
   - Dutify HS Code: `1234567890`
   - Dutify Country of Origin: `CA`
   - Dutify HS Code Country: `US`

### Test 2: Auto-Generation via UI
1. [ ] Edit a product without HTS code
2. [ ] Click "Generate HTS Code" button
3. [ ] Wait for generation
4. [ ] After success, check Attributes tab
5. [ ] Verify all three Dutify attributes are populated

### Test 3: Auto-Classification on Publish
1. [ ] Create new product with name and description
2. [ ] Publish without entering HTS code
3. [ ] Wait 10 seconds
4. [ ] Refresh page
5. [ ] Check both HTS Codes tab and Attributes tab
6. [ ] Verify sync happened automatically

### Test 4: Python Script Import
1. [ ] Run classification script:
   ```bash
   python classify_recent_fixed.py
   ```
2. [ ] Push to WooCommerce:
   ```bash
   python push_recent_only.py
   ```
3. [ ] Check a pushed product in WordPress admin
4. [ ] Verify Dutify attributes are populated

## Edge Case Tests

### Test 5: Invalid HTS Codes
1. [ ] Try to save with invalid code: `ABCD.EF.GHIJ`
2. [ ] Should not sync to Dutify
3. [ ] Try with placeholder: `9999.99.9999`
4. [ ] Should not sync to Dutify

### Test 6: Rate Limiting
1. [ ] Create a test script to update 35 products rapidly
2. [ ] Check error log for rate limit messages after 30th product
3. [ ] Wait 60 seconds
4. [ ] Try again - should work

### Test 7: Missing Country Code
1. [ ] Save product with HTS code but no country
2. [ ] Check Dutify Country of Origin attribute
3. [ ] Should default to `CA` or be empty

### Test 8: Special Characters
1. [ ] Try HTS code with spaces: `1234. 56. 7890`
2. [ ] Should clean to `1234567890`
3. [ ] Try country with special chars: `C@A!`
4. [ ] Should clean to `CA`

## Security Tests

### Test 9: SQL Injection Prevention
1. [ ] Try HTS code: `'; DROP TABLE wp_posts; --`
2. [ ] Should be sanitized and rejected
3. [ ] Check database - tables should be intact

### Test 10: XSS Prevention
1. [ ] Try HTS code: `<script>alert('XSS')</script>`
2. [ ] Should be sanitized
3. [ ] No JavaScript should execute

## Performance Tests

### Test 11: Bulk Operation
1. [ ] Select 10 products in Products list
2. [ ] Use bulk action to classify
3. [ ] Monitor for timeouts or errors
4. [ ] All should sync to Dutify

### Test 12: Large Product Test
1. [ ] Test with product having long description (5000+ words)
2. [ ] Classification should still work
3. [ ] Sync should complete normally

## Error Recovery Tests

### Test 13: Dutify Plugin Deactivated
1. [ ] Deactivate Dutify plugin
2. [ ] Try to save HTS code
3. [ ] Should save to HTS Manager but not crash
4. [ ] Reactivate Dutify
5. [ ] Edit and save product again
6. [ ] Should sync successfully

### Test 14: Network Error Simulation
1. [ ] Temporarily block api.anthropic.com
2. [ ] Try to generate HTS code
3. [ ] Should show error message, not crash
4. [ ] Unblock and retry
5. [ ] Should work normally

## Integration Tests

### Test 15: Dutify Checkout Flow
1. [ ] Add product with synced HTS code to cart
2. [ ] Go to checkout
3. [ ] Enter international shipping address
4. [ ] Verify Dutify calculates duties using the HTS code

### Test 16: ShipStation Export
1. [ ] Process an order with HTS-coded products
2. [ ] Export to ShipStation (if configured)
3. [ ] Verify HTS codes appear in customs forms

## Validation Checklist

### Code Quality Checks
- [ ] No PHP errors in debug.log
- [ ] No JavaScript console errors
- [ ] Page load time acceptable (<3 seconds)
- [ ] Memory usage normal (<256MB)

### Data Integrity
- [ ] HTS codes properly formatted in database
- [ ] No duplicate taxonomy terms created
- [ ] Product attributes correctly linked
- [ ] Meta data properly sanitized

### User Experience
- [ ] UI responsive and intuitive
- [ ] Error messages clear and helpful
- [ ] Success notifications visible
- [ ] No unexpected page reloads

## Post-Testing Cleanup

1. [ ] Clear any test products created
2. [ ] Reset rate limiting transients if needed:
   ```sql
   DELETE FROM wp_options WHERE option_name LIKE '%hts_dutify_sync_count%';
   ```
3. [ ] Review error logs for any issues
4. [ ] Document any bugs found

## Sign-off

- **Tester Name**: ________________
- **Date Tested**: ________________
- **WordPress Version**: ________________
- **WooCommerce Version**: ________________
- **PHP Version**: ________________
- **All Tests Passed**: [ ] Yes [ ] No

### Notes:
_____________________________________
_____________________________________
_____________________________________