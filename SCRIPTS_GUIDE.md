# HTS Code Matcher - Scripts Guide

## Quick Reference

| Script | Purpose | When to Use |
|--------|---------|-------------|
| `main.py` | Full classification with menu | Initial setup or manual operations |
| `classify_recent_fixed.py` | Classify & push NEW products only | Daily/regular new product processing |
| `push_recent_only.py` | Push already-classified products | When you classified but didn't push |
| `push_todays_codes.py` | Push last 24h classifications | Alternative to push_recent_only |
| `classify_new_products.py` | Classify ALL unprocessed products | Bulk operations |

## Detailed Usage

### 1. Daily Workflow - New Products Added
```bash
# Best option - checks first 3 pages for new products
python classify_recent_fixed.py

# This script:
# - Only checks first 3 pages (newest products)
# - Only classifies unprocessed products
# - Only pushes the newly classified ones
# - Won't re-push existing classifications
```

### 2. You Classified But Forgot to Push
```bash
# Push products from last 2 hours
python push_recent_only.py 2

# Push products from last 24 hours (default)
python push_recent_only.py

# Push products from last 48 hours
python push_recent_only.py 48
```

### 3. Manual Operations & Troubleshooting
```bash
# Full menu with all options
python main.py

# Menu options:
# 1. Classify products without HTS codes
# 2. Review pending matches
# 3. Push approved codes to WooCommerce
# 4. Export results to CSV
# 7. Show match summary
# 9. Test single product
# 11. Exit
```

### 4. Bulk Operations
```bash
# Classify ALL products without HTS codes (not just recent)
python classify_new_products.py

# This is slower as it checks ALL pages
# Use classify_recent_fixed.py for daily operations
```

## Common Scenarios

### Scenario: Just Added 10 New Products
```bash
python classify_recent_fixed.py
# Finds 10 new products, classifies them, asks to push
```

### Scenario: Classified Earlier, Need to Push Now
```bash
python push_recent_only.py
# Shows products classified today, pushes them
```

### Scenario: Not Sure What's Been Classified
```bash
python main.py
# Choose option 7 (Show match summary)
# See total classified, approved, pending
```

### Scenario: Want to Test One Product First
```bash
python main.py
# Choose option 9 (Test single product)
# Enter product ID to test
```

## Important Notes

1. **Rate Limiting**: If you see HTTP 529 errors, the scripts automatically retry. You can increase delay in config.py:
   ```python
   RATE_LIMIT_DELAY = 2.0  # Increase from 1.0 to 2.0 seconds
   ```

2. **Country of Origin**: Currently defaults to Canada (CA) for all products

3. **Auto-Approval**: Products with >85% confidence are auto-approved. Others need manual review.

4. **Database**: All classifications are stored in `hts_codes.db` SQLite database

5. **ShipStation**: The HTS codes are automatically included when ShipStation syncs with WooCommerce

## Error Recovery

### If Classification Fails Midway
- Just run the script again - it skips already-processed products
- Use `classify_recent_fixed.py` to continue where you left off

### If Push Fails
- Use `push_recent_only.py` to retry pushing
- Check WooCommerce API credentials if consistently failing

### If Wrong Codes Were Assigned
- Use `main.py` → Option 2 to review and update
- Codes can be manually edited in WooCommerce admin

## Cost Estimates

- Each product classification: ~$0.003
- 100 products ≈ $0.30
- 1000 products ≈ $3.00

## Quick Commands Cheat Sheet

```bash
# Daily new products
python classify_recent_fixed.py

# Push recent classifications  
python push_recent_only.py

# Check status
python main.py
# Then press 7

# Test one product
python main.py  
# Then press 9

# Export to CSV
python main.py
# Then press 4
```