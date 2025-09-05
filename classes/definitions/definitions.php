<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

/**
 * Implements a base class to store and handle the messages returned by the
 * plugin. This class is used to extend the basic functionalities provided by
 * standard WP_Error class.
 */
class Definitions {
	// @var string The menu slug for plugin's settings page.
	const MENU_SLUG = 'aelia_cs_options_page';
	// @var string The plugin slug
	const PLUGIN_SLUG = 'woocommerce-aelia-currencyswitcher';
	// @var string The plugin text domain
	const TEXT_DOMAIN = 'woocommerce-aelia-currencyswitcher';

	// Get/Post Arguments
	const ARG_CURRENCY = 'aelia_cs_currency';
	const ARG_PRICE_FILTER_CURRENCY = 'price_filter_currency';
	const ARG_FORCE_ALL_UPDATES = 'aelia_cs_fau';
	const ARG_DEBUG_GEOLOCATION_DETECTION = 'aelia_cs_dgd';

	// Billing and shipping country fields used during the "checkout_review" ajax call
	// @since 4.10.0.210312
	const ARG_CHECKOUT_REVIEW_BILLING_COUNTRY = 'country';
	const ARG_CHECKOUT_REVIEW_SHIPPING_COUNTRY = 's_country';

	// Billing and shipping country fields used during the "checkout" ajax call
	// @since 4.10.0.210312
	const ARG_CHECKOUT_BILLING_COUNTRY = 'billing_country';
	const ARG_CHECKOUT_SHIPPING_COUNTRY = 'shipping_country';

	const ARG_CUSTOMER_COUNTRY = 'aelia_customer_country';
	const ARG_REPORT_CURRENCY = 'report_currency';

	// The currency used during an admin operation
	// @since 4.5.5.171114
	const ARG_ADMIN_CURRENCY = 'admin_currency';

	/**
	 * Obsolete key. Used to store customer's country.
	 * @var string
	 * @deprecated since 4.0.0.150311. Replaced by ARG_CUSTOMER_COUNTRY.
	 */
	const ARG_BILLING_COUNTRY = 'aelia_billing_country';

	// Defaults
	const DEF_REPORT_CURRENCY = 'base';

	/**
	 * The default batch size to process database updates.
	 *
	 * @since 4.16.0.230623
	 */
	const DATABASE_UPDATER_DEFAULT_BATCH_SIZE = 100;

	// Session constants
	const SESSION_CUSTOMER_COUNTRY = 'aelia_customer_country';
	/**
	 * Obsolete key. Used to store customer's country.
	 * @var string
	 * @deprecated since 4.0.0.150311. Replaced by SESSION_CUSTOMER_COUNTRY.
	 */
	const SESSION_BILLING_COUNTRY = 'aelia_billing_country';

	// Slugs
	const SLUG_OPTIONS_PAGE = 'aelia_cs_options_page';

	// Error codes
	const OK = 0;
	const RES_OK = 0;
	const ERR_FILE_NOT_FOUND = 1100;
	const ERR_INVALID_CURRENCY = 1101;
	const ERR_MISCONFIGURED_CURRENCIES = 1102;
	const ERR_INVALID_SOURCE_CURRENCY = 1103;
	const ERR_INVALID_DESTINATION_CURRENCY = 1104;
	const ERR_INVALID_TEMPLATE = 1105;
	const ERR_INVALID_WIDGET_CLASS = 1106;
	const ERR_MANUAL_CURRENCY_SELECTION_DISABLED = 1107;

	const WARN_DYNAMIC_PRICING_INTEGRATION = 2001;
	const NOTICE_INTEGRATION_ADDONS = 2002;
	const WARN_YAHOO_FINANCE_DISCONTINUED = 2003;

	// @since 4.14.1.220803
	const NOTICE_UPDATES_PHP_82 = 2104;

	// Database updater
	const NOTICE_DATABASE_UPDATES_RUNNING = 2200;
	const NOTICE_DATABASE_UPDATE_EXCHANGE_RATES_ERROR = 2201;
	const NOTICE_DATABASE_UPDATE_SETTINGS_ERROR = 2202;

	// REST API
	const ERR_REST_HTTP_ERROR = 3001;
	const ERR_REST_INVALID_API_RESPONSE = 3002;

	// REST API
	// @since 5.0.4.230626
	const REST_NAMESPACE = 'aelia/cs';

	// Session/User Keys
	const USER_CURRENCY = 'aelia_cs_selected_currency';
	const RECALCULATE_CART_TOTALS = 'aelia_cs_recalculate_cart_totals';

	// Transients
	const TRANSIENT_SALES_CURRENCIES = 'aelia_cs_sales_currencies';

	// Custom meta
	// @since 4.8.7.200417
	const META_BASE_CURRENCY_EXCHANGE_RATE = '_base_currency_exchange_rate';

	// @since 5.0.0.230203
	const URL_MANUAL_ORDER_CURRENCY = 'https://aelia.freshdesk.com/a/solutions/articles/3000119328';
	// @since 5.0.5.230703
	const URL_DATABASE_UPDATES_INFORMATION = 'https://aelia.freshdesk.com/a/solutions/articles/3000121828';
}
