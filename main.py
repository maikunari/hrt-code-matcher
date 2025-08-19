#!/usr/bin/env python3
"""
WooCommerce HTS Code Matcher Service with Claude AI and Category Selection
Connects to your WooCommerce store and uses Claude to intelligently assign HTS codes
"""

import requests
import json
import sqlite3
import pandas as pd
from typing import List, Dict, Optional, Set
import time
from datetime import datetime
import re
import os
from dataclasses import dataclass
from requests.auth import HTTPBasicAuth
import logging
from anthropic import Anthropic

# Disable SSL warnings for local development with .local domains
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# Import configuration
try:
    # Try config.py first
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
    print("✓ Loaded configuration from config.py")
except ImportError:
    # Fall back to .env file
    from dotenv import load_dotenv
    load_dotenv()
    
    SITE_URL = os.getenv('SITE_URL')
    WOO_CONSUMER_KEY = os.getenv('WOO_CONSUMER_KEY')
    WOO_CONSUMER_SECRET = os.getenv('WOO_CONSUMER_SECRET')
    ANTHROPIC_API_KEY = os.getenv('ANTHROPIC_API_KEY')
    BATCH_SIZE = int(os.getenv('BATCH_SIZE', 10))
    AUTO_APPROVE_THRESHOLD = float(os.getenv('AUTO_APPROVE_THRESHOLD', 0.85))
    DATABASE_PATH = os.getenv('DATABASE_PATH', 'hts_codes.db')
    RATE_LIMIT_DELAY = float(os.getenv('RATE_LIMIT_DELAY', 1.0))
    print("✓ Loaded configuration from .env file")

# Set up logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

@dataclass
class WooConfig:
    """WooCommerce API Configuration"""
    url: str
    consumer_key: str
    consumer_secret: str
    anthropic_api_key: str

class CategoryManager:
    """Manage WooCommerce categories for selective processing"""
    
    def __init__(self, config):
        self.config = config
        self.api_url = f"{config.url}/wp-json/wc/v3"
        
        # Determine auth method
        if config.consumer_key.startswith('ck_'):
            self.auth = HTTPBasicAuth(config.consumer_key, config.consumer_secret)
        else:
            self.auth = HTTPBasicAuth(config.consumer_key, config.consumer_secret.replace(' ', ''))
        
        # Check if local development
        self.verify_ssl = not ('.local' in config.url or 'localhost' in config.url)
        
        self.categories = []
        self.selected_category_ids = set()
    
    def fetch_all_categories(self) -> List[Dict]:
        """Fetch all product categories from WooCommerce"""
        categories = []
        page = 1
        per_page = 100
        
        print("Fetching product categories...")
        
        while True:
            response = requests.get(
                f"{self.api_url}/products/categories",
                auth=self.auth,
                verify=self.verify_ssl,
                params={
                    'page': page,
                    'per_page': per_page,
                    'orderby': 'name',
                    'order': 'asc'
                }
            )
            
            if response.status_code != 200:
                print(f"Error fetching categories: {response.status_code}")
                break
            
            batch = response.json()
            if not batch:
                break
                
            categories.extend(batch)
            
            # Check if there are more pages
            total_pages = int(response.headers.get('X-WP-TotalPages', 1))
            if page >= total_pages:
                break
                
            page += 1
        
        self.categories = categories
        print(f"Found {len(categories)} categories")
        return categories
    
    def display_category_tree(self, parent_id=0, indent=0):
        """Display categories in a tree structure"""
        for cat in self.categories:
            if cat.get('parent', 0) == parent_id:
                checkbox = "[✓]" if cat['id'] in self.selected_category_ids else "[ ]"
                print(f"{'  ' * indent}{checkbox} {cat['id']:4d}: {cat['name']} ({cat.get('count', 0)} products)")
                # Recursively display children
                self.display_category_tree(cat['id'], indent + 1)
    
    def get_category_with_children(self, category_id: int) -> Set[int]:
        """Get a category and all its child category IDs"""
        ids = {category_id}
        for cat in self.categories:
            if cat.get('parent', 0) == category_id:
                ids.update(self.get_category_with_children(cat['id']))
        return ids
    
    def select_categories_interactive(self) -> Set[int]:
        """Interactive category selection"""
        if not self.categories:
            self.fetch_all_categories()
        
        while True:
            print("\n=== Category Selection ===")
            print("Current selection:")
            self.display_category_tree()
            
            print("\n" + "="*50)
            selected_count = sum(1 for cat in self.categories if cat['id'] in self.selected_category_ids)
            selected_products = sum(cat.get('count', 0) for cat in self.categories if cat['id'] in self.selected_category_ids)
            print(f"Selected: {selected_count} categories, ~{selected_products} products")
            
            print("\nOptions:")
            print("  [ID]     - Toggle single category")
            print("  +[ID]    - Add category and all children")
            print("  -[ID]    - Remove category and all children")
            print("  all      - Select all categories")
            print("  none     - Clear selection")
            print("  invert   - Invert selection")
            print("  search   - Search categories by name")
            print("  done     - Finish selection")
            
            choice = input("\nEnter choice: ").strip().lower()
            
            if choice == 'done':
                break
            elif choice == 'all':
                self.selected_category_ids = {cat['id'] for cat in self.categories}
                print("✓ All categories selected")
            elif choice == 'none':
                self.selected_category_ids.clear()
                print("✓ Selection cleared")
            elif choice == 'invert':
                all_ids = {cat['id'] for cat in self.categories}
                self.selected_category_ids = all_ids - self.selected_category_ids
                print("✓ Selection inverted")
            elif choice == 'search':
                search_term = input("Search for: ").lower()
                matches = [cat for cat in self.categories if search_term in cat['name'].lower()]
                if matches:
                    print("\nSearch results:")
                    for cat in matches:
                        checkbox = "[✓]" if cat['id'] in self.selected_category_ids else "[ ]"
                        print(f"{checkbox} {cat['id']:4d}: {cat['name']} ({cat.get('count', 0)} products)")
                else:
                    print("No matches found")
            elif choice.startswith('+'):
                try:
                    cat_id = int(choice[1:])
                    ids_to_add = self.get_category_with_children(cat_id)
                    self.selected_category_ids.update(ids_to_add)
                    print(f"✓ Added category {cat_id} and children ({len(ids_to_add)} categories)")
                except ValueError:
                    print("Invalid ID")
            elif choice.startswith('-'):
                try:
                    cat_id = int(choice[1:])
                    ids_to_remove = self.get_category_with_children(cat_id)
                    self.selected_category_ids.difference_update(ids_to_remove)
                    print(f"✓ Removed category {cat_id} and children ({len(ids_to_remove)} categories)")
                except ValueError:
                    print("Invalid ID")
            else:
                try:
                    cat_id = int(choice)
                    if cat_id in self.selected_category_ids:
                        self.selected_category_ids.remove(cat_id)
                        print(f"✓ Deselected category {cat_id}")
                    else:
                        self.selected_category_ids.add(cat_id)
                        print(f"✓ Selected category {cat_id}")
                except ValueError:
                    print("Invalid choice")
        
        return self.selected_category_ids
    
    def fetch_products_by_categories(self, category_ids: Set[int], limit: int = None) -> List[Dict]:
        """Fetch products from specific categories"""
        all_products = []
        products_seen = set()
        
        for cat_id in category_ids:
            cat_name = next((c['name'] for c in self.categories if c['id'] == cat_id), f"Category {cat_id}")
            print(f"\nFetching products from: {cat_name}")
            
            page = 1
            while True:
                response = requests.get(
                    f"{self.api_url}/products",
                    auth=self.auth,
                    verify=self.verify_ssl,
                    params={
                        'category': cat_id,
                        'page': page,
                        'per_page': 100,
                        'status': 'publish'
                    }
                )
                
                if response.status_code != 200:
                    print(f"Error fetching products: {response.status_code}")
                    break
                
                products = response.json()
                if not products:
                    break
                
                # Avoid duplicates (products can be in multiple categories)
                for product in products:
                    if product['id'] not in products_seen:
                        products_seen.add(product['id'])
                        all_products.append(product)
                        
                        if limit and len(all_products) >= limit:
                            print(f"Reached limit of {limit} products")
                            return all_products[:limit]
                
                # Check for more pages
                total_pages = int(response.headers.get('X-WP-TotalPages', 1))
                if page >= total_pages:
                    break
                    
                page += 1
                print(f"  Page {page}/{total_pages}...")
        
        print(f"\nTotal unique products found: {len(all_products)}")
        return all_products
    
    def save_category_selection(self, filename='selected_categories.json'):
        """Save selected categories to file for reuse"""
        data = {
            'category_ids': list(self.selected_category_ids),
            'category_names': {
                cat_id: next((c['name'] for c in self.categories if c['id'] == cat_id), '')
                for cat_id in self.selected_category_ids
            }
        }
        with open(filename, 'w') as f:
            json.dump(data, f, indent=2)
        print(f"✓ Saved selection to {filename}")
    
    def load_category_selection(self, filename='selected_categories.json'):
        """Load previously saved category selection"""
        try:
            with open(filename, 'r') as f:
                data = json.load(f)
            self.selected_category_ids = set(data['category_ids'])
            print(f"✓ Loaded {len(self.selected_category_ids)} categories from {filename}")
            return True
        except FileNotFoundError:
            print(f"No saved selection found at {filename}")
            return False

def test_woocommerce_connection(config: WooConfig):
    """Test if WooCommerce API connection works"""
    
    api_url = f"{config.url}/wp-json/wc/v3"
    
    # Determine auth method
    if config.consumer_key.startswith('ck_'):
        auth = HTTPBasicAuth(config.consumer_key, config.consumer_secret)
        print("Using WooCommerce REST API authentication")
    else:
        # WordPress Application Password - remove spaces just in case
        auth = HTTPBasicAuth(config.consumer_key, config.consumer_secret.replace(' ', ''))
        print("Using WordPress Application Password authentication")
    
    # Check if using local development
    is_local = '.local' in config.url or 'localhost' in config.url
    verify_ssl = not is_local
    
    if is_local:
        print("Local development detected - SSL verification disabled")
    
    # Test the connection
    try:
        # Try to fetch store info
        response = requests.get(
            f"{api_url}/system_status", 
            auth=auth,
            verify=verify_ssl
        )
        
        if response.status_code == 200:
            print("✅ Successfully connected to WooCommerce!")
            
            # Try to fetch a few products
            response = requests.get(
                f"{api_url}/products", 
                auth=auth,
                verify=verify_ssl,
                params={'per_page': 1}
            )
            
            if response.status_code == 200:
                products = response.json()
                print(f"✅ Can access products! Found at least {len(products)} product(s)")
                return True
            else:
                print(f"❌ Can't access products: {response.status_code}")
                print(f"Error: {response.text}")
                return False
                
        elif response.status_code == 401:
            print("❌ Authentication failed! Check your credentials.")
            print("Make sure you have the correct:")
            print("  - Site URL (with https://)")
            print("  - API keys or username/password")
            return False
            
        elif response.status_code == 404:
            print("❌ WooCommerce REST API not found at this URL.")
            print("Check that:")
            print("  1. Your site URL is correct")
            print("  2. WooCommerce is installed and activated")
            print("  3. Permalinks are enabled (Settings → Permalinks)")
            return False
            
        else:
            print(f"❌ Connection failed: {response.status_code}")
            print(f"Error: {response.text}")
            return False
            
    except requests.exceptions.ConnectionError:
        print("❌ Could not connect to the site. Check the URL.")
        return False
    except Exception as e:
        print(f"❌ Error: {e}")
        return False

class HTSMatcher:
    """Claude-powered HTS code matcher"""
    
    def __init__(self, api_key: str):
        self.client = Anthropic(api_key=api_key)
        self.model = "claude-3-7-sonnet-20250219"  # Using Claude 3.7 Sonnet
        
    def match_product(self, product_info: Dict) -> Dict:
        """Use Claude to match a product to its HTS code"""
        
        # Build the prompt with product information
        prompt = f"""You are an expert in Harmonized Tariff Schedule (HTS) classification for US imports. 
Analyze this product and provide the most accurate 10-digit HTS code.

PRODUCT INFORMATION:
Name: {product_info.get('name', 'Unknown')}
SKU: {product_info.get('sku', 'N/A')}
Description: {product_info.get('description', 'No description')}
Categories: {', '.join(product_info.get('categories', []))}
Attributes: {product_info.get('attributes', 'None')}

IMPORTANT RULES:
1. Provide the full 10-digit HTS code (format: ####.##.####)
2. Consider the product's primary function and material composition
3. Use the most specific classification available
4. If uncertain between codes, choose the one with higher duty rate (conservative approach)

Respond in this exact JSON format:
{{
    "hts_code": "####.##.####",
    "hts_description": "Brief description from HTS schedule",
    "confidence": 0.0 to 1.0,
    "reasoning": "Brief explanation of classification logic",
    "material": "primary material if identified",
    "alternative_codes": ["####.##.####"] or null if very confident
}}

Focus on accuracy - this will be used for actual customs declarations."""

        try:
            response = self.client.messages.create(
                model=self.model,
                max_tokens=500,
                temperature=0.2,  # Low temperature for consistency
                messages=[{
                    "role": "user",
                    "content": prompt
                }]
            )
            
            # Extract JSON from Claude's response
            response_text = response.content[0].text
            
            # Try to parse JSON from response
            json_match = re.search(r'\{.*\}', response_text, re.DOTALL)
            if json_match:
                result = json.loads(json_match.group())
                
                # Validate the response
                if self.validate_hts_code(result.get('hts_code', '')):
                    return result
                else:
                    logger.warning(f"Invalid HTS code format from Claude: {result.get('hts_code')}")
                    return self.fallback_match(product_info)
            else:
                logger.warning("Could not parse JSON from Claude response")
                return self.fallback_match(product_info)
                
        except Exception as e:
            logger.error(f"Claude API error: {e}")
            return self.fallback_match(product_info)
    
    def validate_hts_code(self, code: str) -> bool:
        """Validate HTS code format"""
        # HTS codes should be ####.##.#### format
        pattern = r'^\d{4}\.\d{2}\.\d{4}$'
        return bool(re.match(pattern, code))
    
    def fallback_match(self, product_info: Dict) -> Dict:
        """Fallback if Claude fails"""
        return {
            "hts_code": "9999.99.9999",
            "hts_description": "Unclassified - needs manual review",
            "confidence": 0.0,
            "reasoning": "Automatic classification failed",
            "material": "unknown",
            "alternative_codes": None
        }

class WooCommerceHTSMatcher:
    def __init__(self, config: WooConfig, hts_db_path: str = 'hts_codes.db'):
        self.config = config
        self.api_url = f"{config.url}/wp-json/wc/v3"
        
        # Check if using WordPress Application Password (no 'ck_' prefix)
        if config.consumer_key.startswith('ck_'):
            # WooCommerce REST API keys
            self.auth = HTTPBasicAuth(config.consumer_key, config.consumer_secret)
        else:
            # WordPress Application Password - remove spaces
            self.auth = HTTPBasicAuth(config.consumer_key, config.consumer_secret.replace(' ', ''))
        
        # Check if local development
        self.is_local = '.local' in config.url or 'localhost' in config.url
        self.verify_ssl = not self.is_local
        
        self.hts_db = sqlite3.connect(hts_db_path)
        self.claude_matcher = HTSMatcher(config.anthropic_api_key)
        self.create_tables()
        
    def create_tables(self):
        """Create local tracking database"""
        cursor = self.hts_db.cursor()
        
        # Enhanced product matching table
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS product_matches (
                product_id INTEGER PRIMARY KEY,
                sku TEXT,
                name TEXT,
                description TEXT,
                categories TEXT,
                hts_code TEXT,
                hts_description TEXT,
                confidence REAL,
                reasoning TEXT,
                material TEXT,
                alternative_codes TEXT,
                status TEXT,  -- 'pending', 'approved', 'rejected', 'manual'
                matched_at TIMESTAMP,
                updated_at TIMESTAMP,
                review_notes TEXT
            )
        ''')
        
        # Processing history for rate limiting
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS processing_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER,
                api_calls INTEGER,
                processing_time REAL,
                timestamp TIMESTAMP
            )
        ''')
        
        self.hts_db.commit()
    
    def fetch_all_products(self, limit: Optional[int] = None) -> List[Dict]:
        """Fetch all products from WooCommerce"""
        products = []
        page = 1
        per_page = 100
        
        while True:
            logger.info(f"Fetching products page {page}...")
            
            response = requests.get(
                f"{self.api_url}/products",
                auth=self.auth,
                verify=self.verify_ssl,  # Use SSL setting
                params={
                    'page': page,
                    'per_page': per_page,
                    'status': 'publish'
                }
            )
            
            if response.status_code != 200:
                logger.error(f"API Error: {response.status_code} - {response.text}")
                break
                
            batch = response.json()
            
            if not batch:
                break
                
            products.extend(batch)
            
            if limit and len(products) >= limit:
                products = products[:limit]
                break
                
            # Check if there are more pages
            total_pages = int(response.headers.get('X-WP-TotalPages', 1))
            if page >= total_pages:
                break
                
            page += 1
            time.sleep(0.5)  # Be nice to the API
        
        logger.info(f"Fetched {len(products)} total products")
        return products
    
    def extract_product_features(self, product: Dict) -> Dict:
        """Extract and clean product information for Claude"""
        
        # Clean HTML from descriptions
        description = self.clean_html(product.get('description', ''))
        short_desc = self.clean_html(product.get('short_description', ''))
        
        # Get categories and tags
        categories = [cat.get('name', '') for cat in product.get('categories', [])]
        tags = [tag.get('name', '') for tag in product.get('tags', [])]
        
        # Extract attributes
        attributes = []
        for attr in product.get('attributes', []):
            if attr.get('visible', True):
                name = attr.get('name', '')
                options = attr.get('options', [])
                for option in options:
                    attributes.append(f"{name}: {option}")
        
        return {
            'id': product.get('id'),
            'sku': product.get('sku', ''),
            'name': product.get('name', ''),
            'description': description[:1000],  # Limit length for API
            'short_description': short_desc[:500],
            'categories': categories,
            'tags': tags,
            'attributes': ', '.join(attributes),
            'price': product.get('price', ''),
            'weight': product.get('weight', ''),
            'dimensions': product.get('dimensions', {})
        }
    
    def clean_html(self, text: str) -> str:
        """Remove HTML tags from text"""
        clean = re.sub('<.*?>', '', text)
        clean = re.sub(r'\s+', ' ', clean)
        return clean.strip()
    
    def process_products(self, products: List[Dict], batch_size: int = None):
        """Process products in batches with Claude"""
        
        if batch_size is None:
            batch_size = BATCH_SIZE
            
        results = []
        total = len(products)
        
        for i in range(0, total, batch_size):
            batch = products[i:i+batch_size]
            batch_num = (i // batch_size) + 1
            total_batches = (total + batch_size - 1) // batch_size
            
            logger.info(f"\nProcessing batch {batch_num}/{total_batches}")
            
            for product in batch:
                start_time = time.time()
                
                # Extract features
                features = self.extract_product_features(product)
                
                logger.info(f"  Analyzing: {features['name'][:50]}...")
                
                # Get Claude's classification
                match = self.claude_matcher.match_product(features)
                
                # Determine auto-approval based on confidence
                if match['confidence'] >= AUTO_APPROVE_THRESHOLD:
                    status = 'approved'
                elif match['confidence'] >= 0.60:
                    status = 'pending'
                else:
                    status = 'manual'
                
                # Store result
                result = {
                    'product_id': product['id'],
                    'sku': features['sku'],
                    'name': features['name'],
                    'description': features['description'][:200],
                    'categories': ', '.join(features['categories']),
                    'hts_code': match['hts_code'],
                    'hts_description': match.get('hts_description', ''),
                    'confidence': match['confidence'],
                    'reasoning': match.get('reasoning', ''),
                    'material': match.get('material', ''),
                    'alternative_codes': json.dumps(match.get('alternative_codes', [])),
                    'status': status
                }
                
                results.append(result)
                self.save_match(result)
                
                # Log processing time
                processing_time = time.time() - start_time
                self.log_processing(product['id'], 1, processing_time)
                
                logger.info(f"    → HTS: {match['hts_code']} (confidence: {match['confidence']:.0%})")
                
                # Rate limiting for Claude API
                time.sleep(RATE_LIMIT_DELAY)
            
            # Pause between batches
            if batch_num < total_batches:
                logger.info(f"  Batch complete. Pausing before next batch...")
                time.sleep(5)
        
        return results
    
    def save_match(self, match: Dict):
        """Save match to local database"""
        cursor = self.hts_db.cursor()
        
        cursor.execute('''
            INSERT OR REPLACE INTO product_matches
            (product_id, sku, name, description, categories, hts_code, 
             hts_description, confidence, reasoning, material, alternative_codes,
             status, matched_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ''', (
            match['product_id'],
            match['sku'],
            match['name'],
            match['description'],
            match['categories'],
            match['hts_code'],
            match['hts_description'],
            match['confidence'],
            match['reasoning'],
            match['material'],
            match['alternative_codes'],
            match['status'],
            datetime.now(),
            datetime.now()
        ))
        
        self.hts_db.commit()
    
    def log_processing(self, product_id: int, api_calls: int, processing_time: float):
        """Log processing metrics"""
        cursor = self.hts_db.cursor()
        cursor.execute('''
            INSERT INTO processing_log (product_id, api_calls, processing_time, timestamp)
            VALUES (?, ?, ?, ?)
        ''', (product_id, api_calls, processing_time, datetime.now()))
        self.hts_db.commit()
    
    def get_pending_matches(self) -> pd.DataFrame:
        """Get all matches pending review"""
        query = '''
            SELECT product_id, sku, name, hts_code, hts_description, 
                   confidence, reasoning, alternative_codes, status
            FROM product_matches
            WHERE status IN ('pending', 'manual')
            ORDER BY confidence DESC
        '''
        
        return pd.read_sql_query(query, self.hts_db)
    
    def get_match_summary(self) -> Dict:
        """Get summary of matching results"""
        cursor = self.hts_db.cursor()
        
        cursor.execute('''
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'manual' THEN 1 END) as needs_manual,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
                AVG(CASE WHEN confidence > 0 THEN confidence END) as avg_confidence,
                COUNT(DISTINCT hts_code) as unique_codes
            FROM product_matches
        ''')
        
        result = cursor.fetchone()
        
        # Get processing stats
        cursor.execute('''
            SELECT 
                SUM(api_calls) as total_api_calls,
                AVG(processing_time) as avg_processing_time
            FROM processing_log
            WHERE timestamp > datetime('now', '-24 hours')
        ''')
        
        stats = cursor.fetchone()
        
        return {
            'total': result[0] or 0,
            'approved': result[1] or 0,
            'pending': result[2] or 0,
            'needs_manual': result[3] or 0,
            'rejected': result[4] or 0,
            'avg_confidence': result[5] or 0,
            'unique_codes': result[6] or 0,
            'api_calls_24h': stats[0] or 0,
            'avg_processing_time': stats[1] or 0
        }
    
    def update_product_hts(self, product_id: int, hts_code: str, confidence: float = None):
        """Update HTS code in WooCommerce"""
        
        # Prepare meta data
        meta_data = [
            {'key': '_hts_code', 'value': hts_code}
        ]
        
        if confidence is not None:
            meta_data.append({
                'key': '_hts_confidence', 
                'value': f"{confidence:.1%}"
            })
            
        # Add timestamp
        meta_data.append({
            'key': '_hts_updated',
            'value': datetime.now().isoformat()
        })
        
        # Update product
        data = {'meta_data': meta_data}
        
        response = requests.put(
            f"{self.api_url}/products/{product_id}",
            auth=self.auth,
            verify=self.verify_ssl,  # Use SSL setting
            json=data
        )
        
        if response.status_code == 200:
            logger.info(f"Updated product {product_id} with HTS {hts_code}")
            return True
        else:
            logger.error(f"Failed to update product {product_id}: {response.text}")
            return False
    
    def bulk_update_approved(self, dry_run: bool = True):
        """Update all approved matches to WooCommerce"""
        cursor = self.hts_db.cursor()
        cursor.execute('''
            SELECT product_id, sku, name, hts_code, confidence
            FROM product_matches
            WHERE status = 'approved'
        ''')
        
        updates = cursor.fetchall()
        
        if dry_run:
            print(f"\nDRY RUN - Would update {len(updates)} products:")
            for product_id, sku, name, hts_code, confidence in updates[:10]:
                print(f"  {sku}: {name[:30]}... → {hts_code} ({confidence:.0%})")
            if len(updates) > 10:
                print(f"  ... and {len(updates)-10} more")
            return 0
        
        success_count = 0
        for product_id, sku, name, hts_code, confidence in updates:
            if self.update_product_hts(product_id, hts_code, confidence):
                success_count += 1
                time.sleep(0.5)  # Rate limit
        
        logger.info(f"Successfully updated {success_count}/{len(updates)} products")
        return success_count
    
    def export_results(self, filename: str = None):
        """Export all matches to CSV for review"""
        if filename is None:
            filename = f"hts_matches_{datetime.now().strftime('%Y%m%d_%H%M%S')}.csv"
        
        query = '''
            SELECT 
                product_id, 
                sku, 
                name, 
                categories,
                hts_code, 
                hts_description,
                confidence,
                reasoning,
                material,
                alternative_codes,
                status
            FROM product_matches
            ORDER BY status, confidence DESC
        '''
        
        df = pd.read_sql_query(query, self.hts_db)
        
        # Parse alternative codes for readability
        df['alternative_codes'] = df['alternative_codes'].apply(
            lambda x: ', '.join(json.loads(x)) if x and x != 'null' else ''
        )
        
        df.to_csv(filename, index=False)
        logger.info(f"Exported {len(df)} matches to {filename}")
        return filename
    
    def get_processing_cost_estimate(self, num_products: int) -> Dict:
        """Estimate API costs for processing products"""
        # Rough estimates for Claude Sonnet pricing (check current rates)
        cost_per_1k_input_tokens = 0.003  # $3 per million
        cost_per_1k_output_tokens = 0.015  # $15 per million
        
        # Estimate tokens per product
        avg_input_tokens = 400  # Product info prompt
        avg_output_tokens = 150  # JSON response
        
        total_input_tokens = num_products * avg_input_tokens
        total_output_tokens = num_products * avg_output_tokens
        
        input_cost = (total_input_tokens / 1000) * cost_per_1k_input_tokens
        output_cost = (total_output_tokens / 1000) * cost_per_1k_output_tokens
        total_cost = input_cost + output_cost
        
        # Time estimate
        processing_time_seconds = num_products * (RATE_LIMIT_DELAY + 0.5)
        processing_time_minutes = processing_time_seconds / 60
        
        return {
            'num_products': num_products,
            'estimated_cost_usd': round(total_cost, 2),
            'estimated_time_minutes': round(processing_time_minutes, 1),
            'input_tokens': total_input_tokens,
            'output_tokens': total_output_tokens
        }


def main():
    """Main execution function with interactive menu"""
    
    print("=== WooCommerce HTS Matcher with Claude AI ===\n")
    
    # Check if configuration is loaded
    if not all([SITE_URL, WOO_CONSUMER_KEY, WOO_CONSUMER_SECRET, ANTHROPIC_API_KEY]):
        print("❌ Configuration not found!")
        print("Please create either config.py or .env file with your credentials.")
        print("See the documentation for details.")
        return
    
    # Create configuration object
    config = WooConfig(
        url=SITE_URL,
        consumer_key=WOO_CONSUMER_KEY,
        consumer_secret=WOO_CONSUMER_SECRET,
        anthropic_api_key=ANTHROPIC_API_KEY
    )
    
    # Test connection first
    print("Testing WooCommerce connection...")
    if not test_woocommerce_connection(config):
        print("\n⚠️  Connection test failed. Please check your credentials.")
        print("\nTroubleshooting:")
        print("1. Make sure WooCommerce REST API is enabled")
        print("2. Check your API keys or Application Password")
        print("3. Verify your site URL includes https://")
        print("4. Ensure permalinks are enabled (not plain)")
        
        cont = input("\nContinue anyway? (y/n): ")
        if cont.lower() != 'y':
            return
    
    # Initialize matcher and category manager
    matcher = WooCommerceHTSMatcher(config, hts_db_path=DATABASE_PATH)
    category_manager = CategoryManager(config)
    
    # Try to load saved category selection
    category_manager.load_category_selection()
    
    print(f"\nConnected to: {SITE_URL}")
    print(f"Database: {DATABASE_PATH}")
    print(f"Auto-approve threshold: {AUTO_APPROVE_THRESHOLD:.0%}")
    print(f"Batch size: {BATCH_SIZE} products")
    print(f"Rate limit delay: {RATE_LIMIT_DELAY} seconds")
    
    if matcher.is_local:
        print("🔧 Local development mode - SSL verification disabled")
    
    while True:
        print("\n=== Main Menu ===")
        
        if category_manager.selected_category_ids:
            cat_count = len(category_manager.selected_category_ids)
            product_count = sum(c.get('count', 0) for c in category_manager.categories if c['id'] in category_manager.selected_category_ids)
            print(f"📁 Category Filter: {cat_count} categories selected (~{product_count} products)")
        else:
            print("📁 Category Filter: All categories")
        
        print("\n1. Select categories to process")
        print("2. Test with first 10 products (filtered)")
        print("3. Process first 100 products (filtered)")
        print("4. Process ALL products in selected categories")
        print("5. View summary and statistics")
        print("6. Review pending matches")
        print("7. Export results to CSV")
        print("8. Push approved matches to WooCommerce (dry run)")
        print("9. Push approved matches to WooCommerce (LIVE)")
        print("10. Estimate processing costs")
        print("0. Exit")
        
        choice = input("\nSelect option: ").strip()
        
        if choice == '1':
            selected_categories = category_manager.select_categories_interactive()
            if selected_categories:
                save = input("\nSave this selection for future use? (y/n): ")
                if save.lower() == 'y':
                    category_manager.save_category_selection()
        
        elif choice == '2':
            print("\nFetching first 10 products...")
            if category_manager.selected_category_ids:
                products = category_manager.fetch_products_by_categories(
                    category_manager.selected_category_ids, 
                    limit=10
                )
            else:
                products = matcher.fetch_all_products(limit=10)
            
            if products:
                print(f"Found {len(products)} products. Starting analysis...")
                results = matcher.process_products(products)
                summary = matcher.get_match_summary()
                print(f"\n✓ Complete! Approved: {summary['approved']}, Needs review: {summary['pending'] + summary['needs_manual']}")
            
        elif choice == '3':
            print("\nFetching first 100 products...")
            if category_manager.selected_category_ids:
                products = category_manager.fetch_products_by_categories(
                    category_manager.selected_category_ids, 
                    limit=100
                )
            else:
                products = matcher.fetch_all_products(limit=100)
            
            if products:
                confirm = input(f"Process {len(products)} products? (y/n): ")
                if confirm.lower() == 'y':
                    results = matcher.process_products(products)
                    summary = matcher.get_match_summary()
                    print(f"\n✓ Complete! Approved: {summary['approved']}, Needs review: {summary['pending'] + summary['needs_manual']}")
            
        elif choice == '4':
            if not category_manager.selected_category_ids:
                print("\n⚠️  No categories selected!")
                print("Use option 1 to select categories first, or option 3 to process all products.")
                continue
            
            print("\n⚠️  WARNING: This will process ALL products in selected categories!")
            products = category_manager.fetch_products_by_categories(
                category_manager.selected_category_ids
            )
            
            if products:
                cost_est = matcher.get_processing_cost_estimate(len(products))
                print(f"\nFound {len(products)} products in selected categories")
                print(f"Estimated cost: ${cost_est['estimated_cost_usd']}")
                print(f"Estimated time: {cost_est['estimated_time_minutes']} minutes")
                confirm = input("Continue? (type 'YES' to confirm): ")
                if confirm == 'YES':
                    results = matcher.process_products(products)
                    summary = matcher.get_match_summary()
                    print(f"\n✓ Complete! Check summary for results.")
            
        elif choice == '5':
            summary = matcher.get_match_summary()
            print(f"\n=== Classification Summary ===")
            print(f"Total products processed: {summary['total']}")
            print(f"Auto-approved (>{AUTO_APPROVE_THRESHOLD:.0%} confidence): {summary['approved']}")
            print(f"Pending review (60-{AUTO_APPROVE_THRESHOLD:.0%} confidence): {summary['pending']}")
            print(f"Needs manual review (<60% confidence): {summary['needs_manual']}")
            print(f"Rejected: {summary['rejected']}")
            print(f"Average confidence: {summary['avg_confidence']:.1%}")
            print(f"Unique HTS codes used: {summary['unique_codes']}")
            print(f"API calls (last 24h): {summary['api_calls_24h']}")
            if summary['avg_processing_time'] > 0:
                print(f"Avg processing time: {summary['avg_processing_time']:.1f} seconds/product")
            
        elif choice == '6':
            pending = matcher.get_pending_matches()
            if not pending.empty:
                print(f"\n{len(pending)} products need review:")
                print("\nShowing first 20:")
                for idx, row in pending.head(20).iterrows():
                    print(f"\n{idx+1}. {row['name'][:50]}...")
                    print(f"   SKU: {row['sku']}")
                    print(f"   Suggested: {row['hts_code']} ({row['confidence']:.0%} confidence)")
                    print(f"   Reasoning: {row['reasoning'][:100]}...")
                    if row['alternative_codes'] and row['alternative_codes'] != '[]':
                        print(f"   Alternatives: {row['alternative_codes']}")
            else:
                print("\nNo pending matches to review!")
            
        elif choice == '7':
            filename = matcher.export_results()
            print(f"✓ Exported to {filename}")
            print("You can open this in Excel to review and make changes")
            
        elif choice == '8':
            print("\nDRY RUN - Checking what would be updated...")
            matcher.bulk_update_approved(dry_run=True)
            
        elif choice == '9':
            print("\n⚠️  LIVE UPDATE WARNING")
            print("This will update your WooCommerce products with the approved HTS codes.")
            confirm = input("Are you sure? (type 'YES' to confirm): ")
            if confirm == 'YES':
                count = matcher.bulk_update_approved(dry_run=False)
                print(f"✓ Updated {count} products")
            
        elif choice == '10':
            if category_manager.selected_category_ids:
                product_count = sum(c.get('count', 0) for c in category_manager.categories if c['id'] in category_manager.selected_category_ids)
                print(f"\nEstimating for {product_count} products in selected categories...")
                cost_est = matcher.get_processing_cost_estimate(product_count)
            else:
                num = input("Number of products to estimate: ")
                try:
                    cost_est = matcher.get_processing_cost_estimate(int(num))
                except ValueError:
                    print("Invalid number")
                    continue
            
            print(f"\n=== Cost Estimate for {cost_est['num_products']} products ===")
            print(f"Estimated API cost: ${cost_est['estimated_cost_usd']}")
            print(f"Processing time: ~{cost_est['estimated_time_minutes']} minutes")
            print(f"Input tokens: {cost_est['input_tokens']:,}")
            print(f"Output tokens: {cost_est['output_tokens']:,}")
            print("\nNote: Actual costs may vary based on product description length")
            
        elif choice == '0':
            print("\nGoodbye!")
            break
        
        else:
            print("Invalid option")


if __name__ == "__main__":
    main()