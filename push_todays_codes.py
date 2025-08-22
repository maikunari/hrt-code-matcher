#!/usr/bin/env python3
"""
Push only TODAY's classified products to WooCommerce
"""

from main import WooCommerceHTSMatcher, WooConfig
from datetime import datetime, timedelta
import sqlite3

# Import configuration
try:
    from config import (
        SITE_URL, 
        WOO_CONSUMER_KEY, 
        WOO_CONSUMER_SECRET, 
        ANTHROPIC_API_KEY,
        DATABASE_PATH
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

def main():
    print("=== Push Today's Classifications Only ===\n")
    
    # Connect to database
    db = sqlite3.connect(DATABASE_PATH)
    cursor = db.cursor()
    
    # Get products classified in the last 24 hours
    yesterday = datetime.now() - timedelta(days=1)
    
    cursor.execute("""
        SELECT product_id, name, hts_code, confidence, matched_at
        FROM product_matches 
        WHERE status = 'approved' 
        AND matched_at > ?
        ORDER BY matched_at DESC
    """, (yesterday.isoformat(),))
    
    recent_products = cursor.fetchall()
    
    if not recent_products:
        print("No products were classified in the last 24 hours.")
        return
    
    print(f"Found {len(recent_products)} products classified in the last 24 hours:\n")
    
    for product_id, name, hts_code, confidence, matched_at in recent_products:
        time_str = datetime.fromisoformat(matched_at).strftime("%H:%M")
        print(f"  • {name[:50]}...")
        print(f"    HTS: {hts_code} | Confidence: {confidence:.0%} | Time: {time_str}")
    
    # Confirm push
    confirm = input(f"\nPush these {len(recent_products)} recent codes to WooCommerce? (y/n): ")
    if confirm.lower() != 'y':
        print("Cancelled.")
        return
    
    # Initialize WooCommerce connection
    config = WooConfig(
        url=SITE_URL,
        consumer_key=WOO_CONSUMER_KEY,
        consumer_secret=WOO_CONSUMER_SECRET,
        anthropic_api_key=ANTHROPIC_API_KEY
    )
    
    matcher = WooCommerceHTSMatcher(config, hts_db_path=DATABASE_PATH)
    
    # Push each product
    success_count = 0
    for product_id, name, hts_code, confidence, _ in recent_products:
        if matcher.update_product_hts(product_id, hts_code, confidence):
            success_count += 1
            print(f"  ✓ Updated {name[:40]}...")
        else:
            print(f"  ✗ Failed to update product {product_id}")
    
    print(f"\n✓ Successfully pushed {success_count} of {len(recent_products)} products")

if __name__ == "__main__":
    main()