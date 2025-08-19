=== WooCommerce ShipStation Integration ===
Contributors: woocommerce, automattic, royho, akeda, mattyza, bor0, woothemes, dwainm, laurendavissmith001, Kloon
Tags: shipping, woocommerce, automattic
Requires at least: 6.6
Tested up to: 6.7
WC tested up to: 9.7
WC requires at least: 9.5
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 4.4.7
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Ship with confidence, save big on top carriers, and streamline the entire fulfillment process with the WooCommerce ShipStation Integration.

== Description ==

Ship with confidence, save big on top carriers, and streamline the entire fulfillment process with the WooCommerce ShipStation Integration.

= Features =
- __Save money;__ get up to 84% off with top carriers, including UPS, USPS, and DHL Express.
- __Save time;__ sync orders from all your sales channels in one place, and use automation to speed up your processes.
- __Delight customers;__ deliver an exceptional experience with tracking, custom emails and SMS, plus a branded returns portal.
- __Expand across borders;__ automatically generate customs forms, verify addresses, and get low rates on international shipments.

= Get started =
This extension requires a ShipStation monthly plan — [sign up for a free 30-day trial](https://www.shipstation.com/partners/woocommerce/?ref=partner-woocommerce&utm_campaign=partner-referrals&utm_source=woocommerce&utm_medium=partner).

= Save money =
Save __up to 84%__ with UPS, USPS, and DHL Express. You'll also get seriously discounted rates from leading carriers in the US, Canada, UK, Australia, New Zealand, France, and Germany.

= Save time =
Connect your store in seconds, automate workflows, sync tracking info, and get products to your customers __fast__. Sync orders from all your sales channels (including Amazon, Walmart, eBay, and Etsy) in one place.

Get back hours of your time by automating, tagging, splitting, and batching orders and labels. Score!

= Delight your customers =
Deliver an exceptional experience __every time__ with customizable emails, SMS, and branded tracking info to keep customers updated. Returns? No problem, thanks to your own branded returns portal — now that's seamless.

= Expand your business across borders =
Global fulfillment just became effortless. With ShipStation, you can automatically generate and send __customs declarations__ and __verify overseas addresses__ in no time. Shipping to Canada from the US? International parcels are fast and affordable, with low, flat rate Canada Delivered Duty Paid (DDP).

= Why ShipStation? =
ShipStation powers global shipping success for businesses of all sizes. It streamlines the online fulfillment process — from order import and batch label creation to customer communication — with advanced customization features.

== Frequently Asked Questions ==

= Where can I find documentation and a setup guide? =
You’ve come to the right place. [Our documentation](https://woocommerce.com/document/shipstation-for-woocommerce/) for WooCommerce ShipStation Integration includes detailed setup instructions, troubleshooting tips, and more.

= Where can I get support? =
To start, [review our troubleshooting tips](https://woocommerce.com/document/shipstation-for-woocommerce/#troubleshooting) for answers to common questions. Then, if you need further assistance, get in touch via the [official support forum](https://wordpress.org/support/plugin/woocommerce-shipstation-integration/).

= Do I need a ShipStation account? =
Yes; [sign up for a free 30-day trial](https://www.shipstation.com/partners/woocommerce/?ref=partner-woocommerce&utm_campaign=partner-referrals&utm_source=woocommerce&utm_medium=partner).

= Does this extension provide real-time shipping quotes at checkout? =
No. Merchants will need a _real-time shipping quote extension_ (such as USPS, FedEx, UPS, etc.) or an alternate method (e.g. [flat rate charges](https://woocommerce.com/document/flat-rate-shipping/).

= Does ShipStation send data when not in use (e.g. for free shipping)? =
Yes; conditional exporting is not currently available.

= Why are multiple line items in a WooCommerce order combined when they reach ShipStation? =
This commonly occurs when products and variations do not have a unique [stock-keeping unit (SKU)](https://woocommerce.com/document/managing-products/product-editor-settings/#what-is-sku) assigned to them. Allocate a unique SKU to each product — and each variation of that product — to ensure order line items show up correctly in ShipStation.

= My question is not listed; where can I find more answers? =
[Review our general FAQ](https://woocommerce.com/document/shipstation-for-woocommerce/#frequently-asked-questions) or [contact support](https://wordpress.org/support/plugin/woocommerce-shipstation-integration/).

== Changelog ==

= 4.4.7 - 2025-03-04 =
* Tweak - PHP 8.4 Compatibility.
* Tweak - WooCommerce 9.7 Compatibility.

= 4.4.6 - 2024-11-27 =
* Tweak - Reimplemented compatibility with WordPress 6.7 while maintaining unchanged execution priorities.

= 4.4.5 - 2024-10-28 =
* Tweak - WordPress 6.7 Compatibility.

= 4.4.4 - 2024-07-02 =
* Fix   - Security updates.
* Tweak - WooCommerce 9.0 and WordPress 6.6 Compatibility.

= 4.4.3 - 2024-05-27 =
* Tweak - Performance enhancement.

= 4.4.2 - 2024-04-09 =
* Fix - Cannot retrieve order number on from GET variable.

= 4.4.1 - 2024-03-25 =
* Tweak - WordPress 6.5 compatibility.

= 4.4.0 - 2024-03-19 =
* Fix - Applying WordPress coding standards.

= 4.3.9 - 2023-09-05 =
* Fix - Security updates.
* Tweaks - Developer dependencies update.
* Add - Developer QIT workflow.

= 4.3.8 - 2023-08-09 =
* Fix - Security updates.

= 4.3.7 - 2023-05-08 =
* Fix - Allow filtering the order exchange rate and currency code before exporting to ShipStation.

= 4.3.6 - 2023-04-20 =
* Fix - Compatibility for Sequential Order Numbers by WebToffee.
* Add - New query var for WC_Order_Query called `wt_order_number` to search order number.

= 4.3.5 - 2023-04-17 =
* Fix - Revert version 4.3.4's compatibility update for Sequential Order Numbers by WebToffee.

= 4.3.4 - 2023-04-12 =
* Fix   - Compatibility for Sequential Order Numbers by WebToffee.

= 4.3.3 - 2023-03-29 =
* Fix   - Fatal error when product image does not exist.

= 4.3.2 - 2022-11-29 =
* Fix   - Use product variation name when exporting a product variation.

= 4.3.1 - 2022-10-25 =
* Add   - Declared HPOS compatibility.

= 4.3.0 - 2022-10-13 =
* Add   - High-Performance Order Storage compatibility.

= 4.2.0 - 2022-09-07 =
* Add   - Filter for manipulating address export data.
* Fix   - Remove unnecessary files from plugin zip file.
* Tweak - Transition version numbering to WordPress versioning.
* Tweak - WC 6.7.0 and WP 6.0.1 compatibility.
* Fix - Remove 'translate : true' in package.json.

= 4.1.48 - 2021-11-03 =
* Fix - Critical Error when null value is passed to appendChild method.
* Fix - $logging_enabled compared against string instead of boolean.

= 4.1.47 - 2021-09-29 =
* Fix - Change API Export order search to be accurate down to the second, not just the date.

= 4.1.46 - 2021-09-10 =
* Fix   - Order is not changed to completed when the order has partial refund and is marked as shipped in ShipStation.

= 4.1.45 - 2021-08-24 =
* Fix    - Remove all usage of deprecated $HTTP_RAW_POST_DATA.

= 4.1.44 - 2021-08-12 =
* Fix    - Changing text domain to "woocommerce-shipstation-integration" to match with plugin slug.
* Fix    - Order product quantities do not sync to Shipstation when using a refund.
* Fix    - PHP notice error "wc_cog_order_total_cost" was called incorrectly.

= 4.1.43 - 2021-07-27 =
* Fix   - API returns status code 200 even when errors exist.
* Tweak - Add version compare for deprecated Order::get_product_from_item().

= 4.1.42 - 2021-04-20 =
* Fix - Use order currency code instead of store currency.

= 4.1.41 - 2021-03-02 =
* Add - Add currency code and weight units to orders XML.

= 4.1.40 - 2020-11-24 =
* Tweak - PHP 8 compatibility fixes.

= 4.1.39 - 2020-10-06 =
* Add   - Add woocommerce_shipstation_export_order_xml filter.
* Tweak - Update Readme.
* Tweak - WC 4.5 compatibility.
* Fix   - Updated shop_thumbnail to woocommerce_gallery_thumbnail for thumbnail export.

[See changelog for all versions](https://github.com/woocommerce/woocommerce-shipstation/raw/master/changelog.txt).
