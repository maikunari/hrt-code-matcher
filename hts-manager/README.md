# HTS Manager for WooCommerce

A comprehensive WordPress plugin for managing Harmonized Tariff Schedule (HTS) codes in WooCommerce with AI-powered classification and ShipStation integration.

## 🚀 Features

- **AI-Powered Classification**: Automatically generates HTS codes using Claude AI
- **ShipStation Integration**: Seamlessly exports codes for customs forms  
- **Dashboard Widget**: Monitor classification status at a glance
- **Bulk Operations**: Classify multiple products simultaneously
- **Auto-Generation**: Codes generate automatically on product publish/update
- **Confidence Tracking**: View AI confidence levels for each classification
- **Manual Override**: Edit codes directly when needed

## 📦 Installation

1. Download the `hts-manager` folder
2. Upload to `/wp-content/plugins/` on your WordPress site
3. Activate through the WordPress Plugins menu
4. Configure your API key at WooCommerce → HTS Manager

## ⚙️ Configuration

### Required Setup
1. Get an Anthropic API key from [console.anthropic.com](https://console.anthropic.com/)
2. Enter the API key in WooCommerce → HTS Manager
3. Save settings

### Optional Settings
- **Auto-Classification**: Enable/disable automatic classification on product publish (default: enabled)
- **Confidence Threshold**: Set minimum confidence for auto-approval (default: 60%)
- **Country of Origin**: Default country for all products (default: Canada)

## 💡 Usage

### For Individual Products
1. Edit any product
2. Go to "HTS Codes" tab
3. Click "Auto-Generate with AI" or enter manually
4. Save product

### For Bulk Classification
1. Go to Products list
2. Select products without codes
3. Choose "Generate HTS Codes" from Bulk Actions
4. Apply

### Monitoring Status
- Check dashboard widget for overview
- Green = 95%+ products classified
- Yellow = 80-94% classified  
- Red = <80% classified

## 🚢 ShipStation Integration

The plugin automatically:
- Exports HTS codes with orders
- Formats codes for customs forms (removes dots)
- Includes country of origin
- Populates customs descriptions

No additional configuration needed - it just works!

## 💰 Costs

- API usage: ~$0.003 per product classification
- 1,000 products ≈ $3.00
- One-time classification for existing catalog
- Ongoing costs only for new products

## 🔧 Troubleshooting

### Codes Not Generating
1. Check API key is configured
2. Verify auto-classification is enabled
3. Check dashboard widget for pending items
4. Look for admin notices after saving

### ShipStation Not Showing Codes
1. Ensure products have HTS codes in WordPress
2. Force sync in ShipStation
3. Check ShipStation customs settings
4. Verify orders were placed after codes added

### Low Confidence Classifications
- Review products flagged with <60% confidence
- These are usually still correct but worth checking
- Use "Regenerate" option if needed

## 📝 Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+
- SSL certificate for API communications

## 🔒 Security

- All inputs sanitized and validated
- Nonce verification on all forms
- Capability checks for user permissions
- API key stored securely in WordPress options
- No customer data sent to AI service

## 📄 License

GPL v2 or later

## 👨‍💻 Developer

Created by Mike Sewell

## 🆘 Support

For issues or questions, please contact the developer.

---

**Note**: This plugin requires an Anthropic API key for AI features. Get one at [console.anthropic.com](https://console.anthropic.com/)