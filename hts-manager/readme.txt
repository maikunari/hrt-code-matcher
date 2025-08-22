=== HTS Manager for WooCommerce ===
Contributors: mikesewell
Tags: woocommerce, hts, customs, shipstation, harmonized-tariff-schedule
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 3.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete HTS code management system for WooCommerce with AI-powered classification and ShipStation integration.

== Description ==

HTS Manager automates the classification and management of Harmonized Tariff Schedule (HTS) codes for your WooCommerce products, ensuring smooth international shipping and customs compliance.

= Key Features =

* **AI-Powered Classification** - Automatically generates HTS codes using Claude AI
* **ShipStation Integration** - Seamlessly exports codes for customs forms
* **Dashboard Widget** - Monitor classification status at a glance
* **Bulk Operations** - Classify multiple products simultaneously
* **Smart Auto-Generation** - Codes generate automatically when products are published or updated
* **Confidence Tracking** - See AI confidence levels for each classification
* **Manual Override** - Edit codes directly when needed

= How It Works =

1. Install and activate the plugin
2. Add your Anthropic API key in WooCommerce → HTS Manager
3. HTS codes auto-generate when you publish/update products
4. Codes sync automatically with ShipStation for customs forms

= Perfect For =

* E-commerce stores shipping internationally
* Businesses needing customs compliance
* ShipStation users requiring automated customs forms
* Anyone dealing with cross-border commerce

== Installation ==

1. Upload the `hts-manager` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → HTS Manager to configure your API key
4. Enable auto-classification (on by default)
5. That's it! New products will auto-classify on publish

= Configuration =

**Required:**
* Anthropic API key (get one at https://console.anthropic.com/)

**Optional:**
* Set confidence threshold for notifications
* Enable/disable auto-classification
* Configure default country of origin

== Frequently Asked Questions ==

= Do I need an Anthropic API key? =

Yes, you'll need an API key from Anthropic to use the AI classification features. You can get one at https://console.anthropic.com/

= How much does classification cost? =

Each product classification costs approximately $0.003 (less than a penny) through the Anthropic API.

= Will this work with my existing products? =

Yes! The plugin can classify existing products. Just click "Update" on any product without an HTS code, or use the bulk actions feature.

= How does ShipStation integration work? =

The plugin automatically exports HTS codes with your WooCommerce orders. ShipStation picks these up during sync and populates customs forms automatically.

= Can I manually override the AI-generated codes? =

Absolutely! Every product has an HTS Codes tab where you can manually enter or edit codes.

= What happens if a product already has an HTS code? =

The auto-classifier skips products that already have codes. You can use the "Regenerate" option to get a new code if needed.

= How accurate are the AI-generated codes? =

The AI typically achieves 85-95% confidence on most products. Low-confidence classifications are flagged for manual review.

= Does this slow down product publishing? =

No! Classification happens in the background 5-10 seconds after publishing, so there's no delay in your workflow.

== Screenshots ==

1. Dashboard widget showing classification status
2. Product edit screen with HTS Codes tab
3. AI generation button with confidence display
4. Settings page with configuration options
5. Bulk classification in products list

== Changelog ==

= 3.1.0 =
* Enhanced security with proper nonce verification
* Added comprehensive data sanitization and escaping
* Improved error handling and user feedback
* Added staff usage guide
* Removed developer-focused features from UI

= 3.0.0 =
* Combined all HTS functionality into single plugin
* Added dashboard widget for status monitoring
* Implemented auto-generation on product save
* Added regenerate option for existing codes
* Improved ShipStation integration

= 2.0.0 =
* Added AI-powered classification
* Integrated with Claude API
* Added confidence tracking
* Implemented bulk operations

= 1.0.0 =
* Initial release
* Basic HTS code management
* ShipStation export functionality

== Upgrade Notice ==

= 3.1.0 =
Security enhancements and improved user experience. Recommended update for all users.

= 3.0.0 =
Major update combining all HTS features into one plugin. Backup before upgrading.

== Additional Information ==

= System Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* SSL certificate (for API communications)

= Support =

For support, feature requests, or bug reports, please contact the developer.

= Privacy =

This plugin sends product data to Anthropic's API for classification. No personal customer data is transmitted. Product information is used solely for HTS code generation.