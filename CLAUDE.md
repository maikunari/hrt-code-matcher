# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WooCommerce HTS (Harmonized Tariff Schedule) Code Matcher that uses Claude AI to intelligently assign HTS codes to products from a WooCommerce store. The system consists of both Python scripts for AI classification and a WordPress plugin for integration.

## Architecture

### Python Classification System

The core Python system (`main.py`) follows a single-file architecture with clear class separation:

- **HTSMatcher**: Claude AI integration for product-to-HTS code matching
- **WooCommerceHTSMatcher**: Main orchestrator handling WooCommerce API, database operations, and batch processing
- **CategoryManager**: Handles WooCommerce category filtering and selection
- **WooConfig**: Configuration dataclass for API credentials

The system stores results in SQLite (`hts_codes.db`) with two tables:
- `product_matches`: Stores HTS code classifications with confidence scores
- `processing_log`: Tracks API usage and performance metrics

### WordPress Plugin Component

The `hts-manager/` directory contains a WordPress plugin that:
- Provides a WordPress admin interface for HTS code management
- Integrates with WooCommerce product editing interface
- Adds ShipStation integration for customs forms
- Offers dashboard widgets for monitoring classification status
- Supports bulk operations and auto-classification on product publish

## Commands

### Setup and Installation
```bash
# Create virtual environment
python3 -m venv venv

# Activate virtual environment
source venv/bin/activate  # On macOS/Linux
# or
venv\Scripts\activate  # On Windows

# Install dependencies
pip install -r requirements.txt
```

### Primary Scripts (Daily Operations)

```bash
# Main interactive menu - full functionality
python main.py

# Quick daily classification of new products only (recommended)
python classify_recent_fixed.py

# Push recent classifications to WooCommerce (after classifying)
python push_recent_only.py [hours]  # Default: 24 hours

# Push today's classifications specifically
python push_todays_codes.py

# Classify ALL unprocessed products (bulk operation)
python classify_new_products.py
```

### Script Usage Patterns

The codebase includes specialized scripts for different workflows:
- **`classify_recent_fixed.py`**: Daily workflow - only processes first 3 pages for new products
- **`push_recent_only.py`**: Push already-classified products from last N hours
- **`push_todays_codes.py`**: Alternative pushing script for last 24 hours
- **`classify_new_products.py`**: Bulk processing of all unclassified products (slower)

### Configuration

Create either `config.py` or `.env` file with:
- `SITE_URL`: WooCommerce site URL
- `WOO_CONSUMER_KEY`: WooCommerce API consumer key
- `WOO_CONSUMER_SECRET`: WooCommerce API consumer secret
- `ANTHROPIC_API_KEY`: Claude API key
- `BATCH_SIZE`: Number of products per batch (default: 10)
- `AUTO_APPROVE_THRESHOLD`: Confidence threshold for auto-approval (default: 0.85)
- `DATABASE_PATH`: SQLite database path (default: 'hts_codes.db')
- `RATE_LIMIT_DELAY`: Delay between API calls in seconds (default: 1.0)

## Key Implementation Details

### Python Classification System
- Uses Claude 3.5 Sonnet model for HTS classification via Anthropic API
- Implements confidence-based approval workflow (auto-approve >85%, pending 60-85%, manual <60%)
- Rate limiting built-in to respect API limits (configurable delay)
- Batch processing support for handling large product catalogs
- Category filtering system to exclude services, gift cards, etc.
- Exports results to CSV for manual review and backup
- Can push approved classifications back to WooCommerce as product metadata
- Automatic retry logic for API failures and HTTP 529 errors

### WordPress Plugin Features
- Dashboard widget showing classification status with color coding
- Product edit interface integration with "HTS Codes" tab
- Auto-classification on product publish/update (configurable)
- Bulk actions for mass classification from Products list
- ShipStation integration for customs form export
- Secure API key storage and nonce verification
- Country of origin metadata support (defaults to Canada)