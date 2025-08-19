# WooCommerce HTS Code Matcher

An intelligent system that automatically assigns Harmonized Tariff Schedule (HTS) codes to your WooCommerce products using Claude AI. Essential for US customs compliance with the new de minimis regulations effective August 29, 2025.

## 🎯 Purpose

With the suspension of the $800 de minimis exemption for international shipments to the US, all products now require accurate HTS codes for customs declarations. This tool automates the classification process for your entire WooCommerce catalog.

## ✨ Features

- **AI-Powered Classification**: Uses Claude 3.5 Sonnet to intelligently analyze and classify products
- **Category Filtering**: Process specific product categories while skipping services, gift cards, etc.
- **Confidence Scoring**: Auto-approves high-confidence matches, flags others for review
- **Bulk Processing**: Handle thousands of products efficiently
- **Export/Import**: Review classifications in Excel before updating your store
- **Local Database**: All classifications saved locally for review and reprocessing
- **Cost Estimation**: Calculate API costs before processing

## 📋 Prerequisites

- Python 3.7+
- WooCommerce store with REST API enabled
- WordPress Application Password or WooCommerce API keys
- Anthropic API key for Claude AI

## 🚀 Quick Start

### 1. Clone or Download the Project

```bash
git clone <repository-url>
cd hts-code-matcher
```

### 2. Install Dependencies

```bash
pip install -r requirements.txt
```

Or with virtual environment (recommended):

```bash
python3 -m venv venv
source venv/bin/activate  # On Windows: venv\Scripts\activate
pip install -r requirements.txt
```

### 3. Configure Credentials

Create a `.env` file in the project directory:

```env
# WooCommerce Settings
SITE_URL=https://yoursite.com
WOO_CONSUMER_KEY=your_wordpress_username
WOO_CONSUMER_SECRET=your_application_password_with_spaces

# Anthropic API
ANTHROPIC_API_KEY=sk-ant-your-key-here

# Processing Settings
BATCH_SIZE=10
AUTO_APPROVE_THRESHOLD=0.85
RATE_LIMIT_DELAY=1.0

# Database Settings
DATABASE_PATH=hts_codes.db
EXPORT_PATH=./exports/
```

### 4. Get Your API Keys

#### WordPress Application Password:
1. Go to WordPress Admin → Users → Your Profile
2. Scroll to "Application Passwords"
3. Name: "HTS Matcher"
4. Click "Add New Application Password"
5. Copy the password (with spaces)

#### Anthropic API Key:
1. Visit https://console.anthropic.com/settings/keys
2. Create new API key
3. Add credits to your account ($20-30 recommended)

### 5. Run the Application

```bash
python main.py
```

## 📖 Usage Guide

### Main Menu Options

When you run the application, you'll see:

```
=== Main Menu ===
📁 Category Filter: All categories

1. Select categories to process
2. Test with first 10 products (filtered)
3. Process first 100 products (filtered)
4. Process ALL products in selected categories
5. View summary and statistics
6. Review pending matches
7. Export results to CSV
8. Push approved matches to WooCommerce (dry run)
9. Push approved matches to WooCommerce (LIVE)
10. Estimate processing costs
0. Exit
```

### Step-by-Step Workflow

#### Step 1: Select Categories (Recommended)

Choose option `1` to filter which categories to process:

```
=== Category Selection ===
[ ]   15: Fireplaces (234 products)
[ ]   20: Accessories (567 products)
[ ]   25: Gift Cards (12 products)  ← Skip these
[ ]   30: Services (8 products)     ← Skip these

Options:
  123      - Toggle single category
  +123     - Add category and all children
  -123     - Remove category and children
  search   - Search by name
  done     - Finish selection
```

**Tip**: Exclude non-physical products like gift cards, warranties, and services.

#### Step 2: Test with 10 Products

Choose option `2` to test the system:
- Fetches 10 products from selected categories
- Processes them through Claude AI
- Shows HTS codes and confidence levels
- Costs approximately $0.04

#### Step 3: Process 100 Products

Choose option `3` for a larger test:
- Good for verifying accuracy across product variety
- Takes about 10-15 minutes
- Costs approximately $0.40

#### Step 4: Review Results

Choose option `5` to see statistics:

```
=== Classification Summary ===
Total products processed: 100
Auto-approved (>85% confidence): 92
Pending review (60-85% confidence): 8
Needs manual review (<60% confidence): 0
Average confidence: 87.3%
Unique HTS codes used: 24
```

#### Step 5: Export for Review

Choose option `7` to export to CSV:
- Opens in Excel for easy review
- Shows all products with their assigned HTS codes
- Includes confidence scores and reasoning
- Make manual corrections if needed

#### Step 6: Update WooCommerce

Choose option `8` for a dry run (see what would be updated)
Choose option `9` to actually update your products

### Category Selection Commands

| Command | Action | Example |
|---------|--------|---------|
| `123` | Toggle single category | Selects/deselects category 123 |
| `+123` | Add category and children | Selects entire branch |
| `-123` | Remove category and children | Deselects entire branch |
| `all` | Select all categories | Selects everything |
| `none` | Clear selection | Deselects everything |
| `search` | Search by name | Find "fireplace" categories |
| `done` | Finish selection | Save and continue |

### Understanding Confidence Levels

- **85-100%**: Auto-approved, high confidence
- **60-84%**: Pending review, good but verify
- **Below 60%**: Needs manual classification

## 💰 Cost Estimation

### API Pricing (Approximate)

- **Per product**: $0.004
- **100 products**: $0.40
- **1,000 products**: $4.00
- **5,000 products**: $20.00

### Time Estimates

- **Processing rate**: ~1 product per 1.5 seconds
- **100 products**: ~15 minutes
- **1,000 products**: ~2.5 hours
- **5,000 products**: ~12.5 hours

## 📊 Data Storage

### Local Database

All classifications are stored in `hts_codes.db` (SQLite database):
- Product matches with HTS codes
- Confidence scores and reasoning
- Processing history and metrics
- Review status and notes

### CSV Exports

Exports are saved with timestamp: `hts_matches_YYYYMMDD_HHMMSS.csv`

Columns include:
- Product ID, SKU, Name
- Categories
- HTS Code and Description
- Confidence Score
- Reasoning
- Alternative Codes
- Status

## 🛠️ Troubleshooting

### Connection Issues

**401 Unauthorized Error:**
- Verify WordPress username and application password
- Ensure REST API is enabled in WooCommerce
- Check that user has admin privileges

**SSL Certificate Error (Local Development):**
- The script auto-detects `.local` domains
- SSL verification is automatically disabled for local development

**404 Not Found:**
- Check WordPress permalinks (Settings → Permalinks)
- Should not be set to "Plain"
- Try "Post name" structure

### Classification Issues

**Low Confidence Scores:**
- Add more detailed product descriptions
- Include material composition in product attributes
- Specify product purpose/use clearly

**Wrong Classifications:**
- Export to CSV and manually correct
- Update product descriptions for clarity
- Consider adding product attributes

## 🔧 Advanced Configuration

### Adjust Processing Settings

In `.env` file:

```env
BATCH_SIZE=10              # Products per batch (10-20 recommended)
AUTO_APPROVE_THRESHOLD=0.85  # Auto-approve if confidence >= 85%
RATE_LIMIT_DELAY=1.0       # Seconds between API calls
```

### Category Presets

Save frequently used category selections in `selected_categories.json` for quick reuse.

## 📝 Best Practices

1. **Start Small**: Test with 10-100 products first
2. **Review Categories**: Exclude non-physical products
3. **Check Classifications**: Review the CSV export before pushing to WooCommerce
4. **Keep Descriptions Updated**: Better product descriptions = better classifications
5. **Save Your Work**: Export CSVs regularly for backup
6. **Monitor Costs**: Use option 10 to estimate costs before large batches

## ⚠️ Important Notes

### Compliance

- HTS codes are legally required for US customs
- Incorrect codes can result in penalties
- This tool provides suggestions - verify critical classifications
- Consider consulting a customs broker for high-value items

### Data Privacy

- All processing happens between your store and Claude AI
- No data is stored on third-party servers
- Keep your API keys secure
- Add `.env` and `config.py` to `.gitignore`

## 🆘 Support

### Common Issues

**"No products found"**
- Check category selection
- Verify WooCommerce has published products
- Ensure API credentials are correct

**"Classification failed"**
- Check Anthropic API key
- Verify API credits available
- Review product has sufficient information

**"Update failed"**
- Verify WordPress user has edit permissions
- Check product isn't locked/protected
- Ensure WooCommerce REST API is writable

## 📄 License

This project is provided as-is for commercial use with your WooCommerce store.

## 🙏 Credits

- Built with Claude 3.5 Sonnet by Anthropic
- Designed for WooCommerce/WordPress
- Created to address US de minimis regulation changes

---

**Remember**: Always verify classifications for high-value or restricted items. This tool provides intelligent suggestions but should not replace professional customs advice for complex products.