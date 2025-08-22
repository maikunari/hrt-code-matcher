#!/usr/bin/env python3
"""
Quick classifier for RECENT products only (checks first 3 pages)
Fixed version that only pushes NEW classifications
"""

from main import WooCommerceHTSMatcher, WooConfig
import logging

# Import configuration
try:
    from config import (
        SITE_URL, 
        WOO_CONSUMER_KEY, 
        WOO_CONSUMER_SECRET, 
        ANTHROPIC_API_KEY,
        DATABASE_PATH,
        AUTO_APPROVE_THRESHOLD
    )
except ImportError:
    from dotenv import load_dotenv
    import os
    load_dotenv()
    
    SITE_URL = os.getenv('SITE_URL')
    WOO_CONSUMER_KEY = os.getenv('WOO_CONSUMER_KEY')
    WOO_CONSUMER_SECRET = os.getenv('WOO_CONSUMER_SECRET')
    ANTHROPIC_API_KEY = os.getenv('ANTHROPIC_API_KEY')
    DATABASE_PATH = os.getenv('DATABASE_PATH', 'hts_codes.db')
    AUTO_APPROVE_THRESHOLD = float(os.getenv('AUTO_APPROVE_THRESHOLD', 0.85))

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

def main():
    print("=== Quick Classify - Recent Products Only ===\n")
    
    # Create configuration
    config = WooConfig(
        url=SITE_URL,
        consumer_key=WOO_CONSUMER_KEY,
        consumer_secret=WOO_CONSUMER_SECRET,
        anthropic_api_key=ANTHROPIC_API_KEY
    )
    
    # Initialize matcher
    matcher = WooCommerceHTSMatcher(config, hts_db_path=DATABASE_PATH)
    
    # Get BEFORE stats
    before_summary = matcher.get_match_summary()
    print(f"Current database: {before_summary['total']} products already classified")
    
    print("\nChecking first 3 pages for new products (newest first)...")
    
    # Fetch ONLY from first 3 pages with the new max_pages parameter
    unprocessed_products = matcher.fetch_all_products(
        skip_processed=True,
        max_pages=3  # Only check first 3 pages
    )
    
    if not unprocessed_products:
        print("\n✓ No new products found in recent pages!")
        print("All recent products already have HTS codes.")
        return
    
    # Track the product IDs we're about to process
    new_product_ids = [p['id'] for p in unprocessed_products]
    
    print(f"\n✓ Found {len(unprocessed_products)} new products to classify")
    
    # Show the products
    print("\nProducts to classify:")
    for i, product in enumerate(unprocessed_products, 1):
        print(f"  {i}. {product['name'][:60]}...")
        if product.get('sku'):
            print(f"      SKU: {product['sku']}")
    
    # Quick estimate
    if len(unprocessed_products) > 0:
        est_cost = len(unprocessed_products) * 0.003  # Rough estimate
        est_time = len(unprocessed_products) * 1.5 / 60  # Minutes
        print(f"\nEstimated: ~${est_cost:.2f} / ~{est_time:.1f} minutes")
    
    # Confirm
    confirm = input(f"\nProcess these {len(unprocessed_products)} products? (y/n): ")
    if confirm.lower() != 'y':
        print("Cancelled.")
        return
    
    # Process
    print("\nProcessing...")
    results = matcher.process_products(unprocessed_products)
    
    # Get AFTER stats
    after_summary = matcher.get_match_summary()
    
    # Calculate what's NEW
    newly_classified = after_summary['total'] - before_summary['total']
    newly_approved = after_summary['approved'] - before_summary['approved']
    newly_pending = (after_summary['pending'] + after_summary['needs_manual']) - (before_summary['pending'] + before_summary['needs_manual'])
    
    print(f"\n✓ Classification complete!")
    print(f"  NEW products classified: {newly_classified}")
    print(f"  NEW auto-approved: {newly_approved}")
    print(f"  NEW needing review: {newly_pending}")
    
    # Push ONLY the new products to WooCommerce
    if newly_approved > 0:
        print(f"\nReady to push {newly_approved} NEWLY approved codes to WooCommerce")
        print("(This will only update the products just classified, not all 4885)")
        push = input(f"\nPush these {newly_approved} NEW codes to WooCommerce? (y/n): ")
        
        if push.lower() == 'y':
            # Update only the newly processed products
            success_count = 0
            for product_id in new_product_ids:
                cursor = matcher.hts_db.cursor()
                cursor.execute("""
                    SELECT hts_code, confidence 
                    FROM product_matches 
                    WHERE product_id = ? AND status = 'approved'
                """, (product_id,))
                result = cursor.fetchone()
                
                if result:
                    hts_code, confidence = result
                    if matcher.update_product_hts(product_id, hts_code, confidence):
                        success_count += 1
                        print(f"  ✓ Updated product {product_id}")
            
            print(f"\n✓ Successfully pushed {success_count} new products to WooCommerce")
    else:
        print("\nNo new products were auto-approved (may need manual review)")

if __name__ == "__main__":
    main()