# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WooCommerce HTS (Harmonized Tariff Schedule) Code Matcher that uses Claude AI to intelligently assign HTS codes to products from a WooCommerce store. The system fetches products via the WooCommerce API, analyzes them using Claude's AI model, and maintains a local SQLite database for tracking classifications.

## Architecture

The codebase follows a single-file architecture with clear class separation:

- **HTSMatcher**: Claude AI integration for product-to-HTS code matching
- **WooCommerceHTSMatcher**: Main orchestrator handling WooCommerce API, database operations, and batch processing
- **WooConfig**: Configuration dataclass for API credentials

The system stores results in SQLite with two tables:
- `product_matches`: Stores HTS code classifications with confidence scores
- `processing_log`: Tracks API usage and performance metrics

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

### Running the Application
```bash
# Run the main interactive menu
python main.py
```

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

- Uses Claude 3.5 Sonnet model for HTS classification
- Implements confidence-based approval workflow (auto-approve, pending, manual review)
- Rate limiting built-in to respect API limits
- Batch processing support for handling large product catalogs
- Exports results to CSV for manual review
- Can push approved classifications back to WooCommerce as product metadata