#!/usr/bin/env python3
"""
Classify only NEW products that don't have HTS codes yet
Perfect for products added after your initial classification run
"""

from main import WooCommerceHTSMatcher, WooConfig, HTSMatcher
import logging

# Import configuration
try:
    from config import (
        SITE_URL, 
        WOO_CONSUMER_KEY, 
        WOO_CONSUMER_SECRET, 
        ANTHROPIC_API_KEY,
        BATCH_SIZE,
        AUTO_APPROVE_THRESHOLD,
        DATABASE_PATH,
        RATE_LIMIT_DELAY
    )
except ImportError:
    from dotenv import load_dotenv
    import os
    load_dotenv()
    
    SITE_URL = os.getenv('SITE_URL')
    WOO_CONSUMER_KEY = os.getenv('WOO_CONSUMER_KEY')
    WOO_CONSUMER_SECRET = os.getenv('WOO_CONSUMER_SECRET')
    ANTHROPIC_API_KEY = os.getenv('ANTHROPIC_API_KEY')
    BATCH_SIZE = int(os.getenv('BATCH_SIZE', 10))
    AUTO_APPROVE_THRESHOLD = float(os.getenv('AUTO_APPROVE_THRESHOLD', 0.85))
    DATABASE_PATH = os.getenv('DATABASE_PATH', 'hts_codes.db')
    RATE_LIMIT_DELAY = float(os.getenv('RATE_LIMIT_DELAY', 1.0))

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

def main():
    print("=== Classify NEW Products Only ===\n")
    
    # Create configuration
    config = WooConfig(
        url=SITE_URL,
        consumer_key=WOO_CONSUMER_KEY,
        consumer_secret=WOO_CONSUMER_SECRET,
        anthropic_api_key=ANTHROPIC_API_KEY
    )
    
    # Initialize matcher
    matcher = WooCommerceHTSMatcher(config, hts_db_path=DATABASE_PATH)
    
    # Get current status
    summary = matcher.get_match_summary()
    print(f"Current Status:")
    print(f"  - {summary['total']} products already classified")
    print(f"  - {summary['approved']} auto-approved")
    
    # Fetch ONLY unprocessed products
    print("\nFetching products without HTS codes...")
    unprocessed_products = matcher.get_products_without_hts()
    
    if not unprocessed_products:
        print("\n✓ All products already have HTS codes!")
        print("No new products to classify.")
        return
    
    print(f"\nFound {len(unprocessed_products)} NEW products without HTS codes")
    
    # Show first few products
    print("\nProducts to be classified:")
    for i, product in enumerate(unprocessed_products[:5], 1):
        print(f"  {i}. {product['name'][:50]}... (SKU: {product.get('sku', 'N/A')})")
    if len(unprocessed_products) > 5:
        print(f"  ... and {len(unprocessed_products) - 5} more")
    
    # Cost estimate
    cost_est = matcher.get_processing_cost_estimate(len(unprocessed_products))
    print(f"\nEstimated cost: ${cost_est['estimated_cost_usd']}")
    print(f"Estimated time: {cost_est['estimated_time_minutes']} minutes")
    
    # Confirm
    confirm = input("\nClassify these NEW products? (y/n): ")
    if confirm.lower() != 'y':
        print("Cancelled.")
        return
    
    # Process the new products
    print("\nProcessing new products...")
    results = matcher.process_products(unprocessed_products)
    
    # Show results
    new_summary = matcher.get_match_summary()
    newly_classified = new_summary['total'] - summary['total']
    
    print(f"\n✓ Complete!")
    print(f"  - {newly_classified} new products classified")
    print(f"  - {new_summary['approved'] - summary['approved']} auto-approved")
    print(f"  - {(new_summary['pending'] + new_summary['needs_manual']) - (summary['pending'] + summary['needs_manual'])} need review")
    
    # Ask if user wants to push to WooCommerce
    push = input("\nPush approved codes to WooCommerce? (y/n): ")
    if push.lower() == 'y':
        print("\nPushing to WooCommerce...")
        count = matcher.bulk_update_approved(dry_run=False)
        print(f"✓ Updated {count} products in WooCommerce")
    
    # Ask about exporting
    export = input("\nExport results to CSV? (y/n): ")
    if export.lower() == 'y':
        filename = matcher.export_results()
        print(f"✓ Exported to {filename}")

if __name__ == "__main__":
    main()