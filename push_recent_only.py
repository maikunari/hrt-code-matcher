#!/usr/bin/env python3
"""
Push only recently classified products to WooCommerce
Useful when you've already run classification but didn't push
"""

from main import WooCommerceHTSMatcher, WooConfig
from datetime import datetime, timedelta
import sqlite3
import sys

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
    print("=== Push Recently Classified Products ===\n")
    
    # Allow specifying hours via command line
    hours = 24
    if len(sys.argv) > 1:
        try:
            hours = int(sys.argv[1])
        except ValueError:
            print(f"Invalid hours argument: {sys.argv[1]}")
            print("Usage: python push_recent_only.py [hours]")
            print("Example: python push_recent_only.py 2  # Push products from last 2 hours")
            return
    
    # Connect to database
    db = sqlite3.connect(DATABASE_PATH)
    cursor = db.cursor()
    
    # Get products classified in the specified time period
    cutoff_time = datetime.now() - timedelta(hours=hours)
    
    # First, show what we'll be pushing
    cursor.execute("""
        SELECT product_id, name, hts_code, confidence, matched_at
        FROM product_matches 
        WHERE status = 'approved' 
        AND matched_at > ?
        ORDER BY matched_at DESC
    """, (cutoff_time.isoformat(),))
    
    recent_products = cursor.fetchall()
    
    if not recent_products:
        print(f"No products were classified in the last {hours} hours.")
        print("\nTip: You can specify a different time period:")
        print("  python push_recent_only.py 48  # Last 48 hours")
        print("  python push_recent_only.py 1   # Last hour")
        return
    
    print(f"Found {len(recent_products)} products classified in the last {hours} hours:\n")
    
    # Group by time for better display
    current_date = None
    for product_id, name, hts_code, confidence, matched_at in recent_products:
        match_datetime = datetime.fromisoformat(matched_at)
        match_date = match_datetime.date()
        
        # Show date header when it changes
        if match_date != current_date:
            current_date = match_date
            if match_date == datetime.now().date():
                date_str = "Today"
            elif match_date == (datetime.now() - timedelta(days=1)).date():
                date_str = "Yesterday"
            else:
                date_str = match_date.strftime("%B %d")
            print(f"\n{date_str}:")
        
        time_str = match_datetime.strftime("%H:%M")
        name_display = name[:50] + "..." if len(name) > 50 else name
        print(f"  • [{time_str}] {name_display}")
        print(f"              HTS: {hts_code} | Confidence: {confidence:.0%}")
    
    # Show summary
    print(f"\n{'='*60}")
    print(f"Total: {len(recent_products)} products ready to push")
    
    # Confirm push
    confirm = input(f"\nPush these {len(recent_products)} codes to WooCommerce? (y/n): ")
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
    print("\nPushing to WooCommerce...")
    success_count = 0
    failed_products = []
    
    for product_id, name, hts_code, confidence, _ in recent_products:
        try:
            if matcher.update_product_hts(product_id, hts_code, confidence):
                success_count += 1
                print(f"  ✓ {name[:40]}...")
            else:
                failed_products.append((product_id, name))
                print(f"  ✗ Failed: {name[:40]}...")
        except Exception as e:
            failed_products.append((product_id, name))
            print(f"  ✗ Error updating {name[:40]}: {str(e)}")
    
    # Final summary
    print(f"\n{'='*60}")
    print(f"✓ Successfully pushed {success_count} of {len(recent_products)} products")
    
    if failed_products:
        print(f"\n⚠ {len(failed_products)} products failed to update:")
        for pid, pname in failed_products:
            print(f"  - {pname[:50]}... (ID: {pid})")
        print("\nYou can try pushing these again later.")
    
    # Offer to show pushed products on website
    if success_count > 0:
        print(f"\n💡 View your products at: {SITE_URL}/wp-admin/edit.php?post_type=product")

if __name__ == "__main__":
    main()