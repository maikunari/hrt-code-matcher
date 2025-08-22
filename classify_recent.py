#!/usr/bin/env python3
"""
Quick classifier for RECENT products only (checks first 3 pages)
Optimized for when you know new products are on the first few pages
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
    
    print("Checking first 3 pages for new products (newest first)...")
    
    # Fetch ONLY from first 3 pages with the new max_pages parameter
    unprocessed_products = matcher.fetch_all_products(
        skip_processed=True,
        max_pages=3  # Only check first 3 pages
    )
    
    if not unprocessed_products:
        print("\n✓ No new products found in recent pages!")
        print("All recent products already have HTS codes.")
        return
    
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
    
    # Summary
    summary = matcher.get_match_summary()
    print(f"\n✓ Classification complete!")
    print(f"  Auto-approved: {summary['approved']}")
    print(f"  Needs review: {summary['pending'] + summary['needs_manual']}")
    
    # Push to WooCommerce?
    if summary['approved'] > 0:
        push = input(f"\nPush {summary['approved']} approved codes to WooCommerce? (y/n): ")
        if push.lower() == 'y':
            count = matcher.bulk_update_approved(dry_run=False)
            print(f"✓ Updated {count} products")

if __name__ == "__main__":
    main()