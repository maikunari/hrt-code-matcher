#!/usr/bin/env python3
"""
Upload HTS codes from existing database to a second WooCommerce site
Perfect for sites with identical product catalogs
"""

import requests
import sqlite3
import time
from requests.auth import HTTPBasicAuth
import logging

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# SECOND SITE CONFIGURATION - Update these!
SECOND_SITE_URL = 'https://your-second-site.com'
SECOND_WOO_CONSUMER_KEY = 'ck_your_second_site_key'
SECOND_WOO_CONSUMER_SECRET = 'cs_your_second_site_secret'

# Database path (uses existing database from first site)
DATABASE_PATH = 'hts_codes.db'

class SecondSiteUploader:
    def __init__(self, site_url, consumer_key, consumer_secret, db_path):
        self.site_url = site_url
        self.api_url = f"{site_url}/wp-json/wc/v3"
        self.auth = HTTPBasicAuth(consumer_key, consumer_secret)
        self.db = sqlite3.connect(db_path)
        
    def get_approved_matches(self):
        """Get all approved HTS codes from database"""
        cursor = self.db.cursor()
        cursor.execute("""
            SELECT product_id, hts_code, confidence 
            FROM product_matches 
            WHERE status = 'approved' 
            AND hts_code IS NOT NULL 
            AND hts_code != '9999.99.9999'
            ORDER BY product_id
        """)
        return cursor.fetchall()
    
    def update_product(self, product_id, hts_code, confidence=None):
        """Update a single product with HTS code"""
        meta_data = [
            {'key': '_hts_code', 'value': hts_code},
            {'key': '_country_of_origin', 'value': 'CA'}  # Default to Canada
        ]
        
        if confidence:
            meta_data.append({
                'key': '_hts_confidence', 
                'value': f"{confidence:.1%}" if isinstance(confidence, float) else str(confidence)
            })
        
        data = {'meta_data': meta_data}
        
        try:
            response = requests.put(
                f"{self.api_url}/products/{product_id}",
                auth=self.auth,
                json=data,
                timeout=10
            )
            
            if response.status_code == 200:
                logger.info(f"✓ Updated product {product_id} with HTS {hts_code}")
                return True
            else:
                logger.error(f"✗ Failed product {product_id}: {response.status_code}")
                return False
                
        except Exception as e:
            logger.error(f"✗ Error updating product {product_id}: {e}")
            return False
    
    def upload_all(self, dry_run=True):
        """Upload all approved HTS codes to second site"""
        matches = self.get_approved_matches()
        
        if not matches:
            print("No approved HTS codes found in database!")
            return
        
        print(f"\nFound {len(matches)} products with approved HTS codes")
        
        if dry_run:
            print("\n=== DRY RUN MODE ===")
            print("Would update the following products:")
            for product_id, hts_code, confidence in matches[:10]:
                print(f"  Product {product_id}: {hts_code} ({confidence:.0%} confidence)")
            if len(matches) > 10:
                print(f"  ... and {len(matches) - 10} more products")
            print("\nRun with dry_run=False to actually update the second site")
            return
        
        # LIVE MODE
        print("\n=== LIVE UPDATE MODE ===")
        confirm = input(f"Update {len(matches)} products on {self.site_url}? (type 'YES' to confirm): ")
        
        if confirm != 'YES':
            print("Cancelled.")
            return
        
        success_count = 0
        error_count = 0
        
        for i, (product_id, hts_code, confidence) in enumerate(matches, 1):
            print(f"[{i}/{len(matches)}] Updating product {product_id}...", end=' ')
            
            if self.update_product(product_id, hts_code, confidence):
                success_count += 1
                print("✓")
            else:
                error_count += 1
                print("✗")
            
            # Rate limiting
            time.sleep(0.5)
            
            # Progress update every 10 products
            if i % 10 == 0:
                print(f"Progress: {i}/{len(matches)} ({success_count} successful, {error_count} errors)")
        
        print(f"\n=== COMPLETE ===")
        print(f"Successfully updated: {success_count} products")
        print(f"Errors: {error_count} products")
        
        return success_count

def main():
    print("=== Upload HTS Codes to Second Site ===\n")
    
    # Check if config is set
    if SECOND_SITE_URL == 'https://your-second-site.com':
        print("ERROR: Please update the configuration in this file first!")
        print("Edit the following variables:")
        print("  - SECOND_SITE_URL")
        print("  - SECOND_WOO_CONSUMER_KEY")
        print("  - SECOND_WOO_CONSUMER_SECRET")
        return
    
    print(f"Source Database: {DATABASE_PATH}")
    print(f"Target Site: {SECOND_SITE_URL}")
    
    uploader = SecondSiteUploader(
        SECOND_SITE_URL,
        SECOND_WOO_CONSUMER_KEY,
        SECOND_WOO_CONSUMER_SECRET,
        DATABASE_PATH
    )
    
    # Test connection
    print("\nTesting connection to second site...")
    try:
        response = requests.get(
            f"{uploader.api_url}/products",
            auth=uploader.auth,
            params={'per_page': 1}
        )
        if response.status_code == 200:
            print("✓ Connection successful!")
        else:
            print(f"✗ Connection failed: {response.status_code}")
            return
    except Exception as e:
        print(f"✗ Connection error: {e}")
        return
    
    # Menu
    while True:
        print("\n=== Options ===")
        print("1. Dry run (preview what would be updated)")
        print("2. LIVE upload to second site")
        print("3. Show statistics")
        print("0. Exit")
        
        choice = input("\nSelect option: ").strip()
        
        if choice == '1':
            uploader.upload_all(dry_run=True)
        elif choice == '2':
            uploader.upload_all(dry_run=False)
        elif choice == '3':
            matches = uploader.get_approved_matches()
            print(f"\nDatabase contains {len(matches)} approved HTS codes ready to upload")
        elif choice == '0':
            break
        else:
            print("Invalid option")

if __name__ == "__main__":
    main()