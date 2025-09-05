import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

/**
 * Adds a "currency" filter to the WooCommerce Analytics.
 *
 * @param array filters
 * @return array
 * @since 4.8.2.200310
 */
function add_currency_filter(filters) {
	let settings = wcSettings.aelia_cs_woocommerce_admin_integration || {};

	if(settings === {}) {
		return filters;
	}

	return [
		...filters,
		{
			label: __('Currency', settings.text_domain),
			staticParams: [],
			param: settings.arg_report_currency,
			showFilters: () => true,
			defaultValue: settings.default_report_currency,
			filters: settings.report_currency_options,
		},
	];
}

addFilter('woocommerce_admin_revenue_report_filters', 'Aelia/WC/CurrencySwitcher', add_currency_filter);
addFilter('woocommerce_admin_orders_report_filters', 'Aelia/WC/CurrencySwitcher', add_currency_filter);
addFilter('woocommerce_admin_products_report_filters', 'Aelia/WC/CurrencySwitcher', add_currency_filter);
addFilter('woocommerce_admin_categories_report_filters', 'Aelia/WC/CurrencySwitcher', add_currency_filter);
addFilter('woocommerce_admin_taxes_report_filters', 'Aelia/WC/CurrencySwitcher', add_currency_filter);
// Add currency filter to coupons report
// @since 4.8.11.200524
addFilter('woocommerce_admin_coupons_report_filters', 'Aelia/WC/CurrencySwitcher', add_currency_filter);
// Add currency filter to Dashboard report
// @since 4.8.14.200805
addFilter('woocommerce_admin_dashboard_filters', 'Aelia/WC/CurrencySwitcher', add_currency_filter);
// Add currency filter for customers
// @since 4.9.0.200917
addFilter('woocommerce_admin_customers_report_filters', 'Aelia/WC/CurrencySwitcher', add_currency_filter);
// Add currency filter for variations
// @since 4.9.4.201118
addFilter('woocommerce_admin_variations_report_filters', 'Aelia/WC/CurrencySwitcher', add_currency_filter);

/**
 * Returns the currency settings that will be used to format the data
 * displayed by the Analytics.
 *
 * @param object config
 * @param object query
 * @since 4.8.7.200417
 * @link https://woocommerce.wordpress.com/2020/02/20/extending-wc-admin-reports/
 */
function update_report_currency(config, query) {
	let settings = wcSettings.aelia_cs_woocommerce_admin_integration || {};

	if(settings === {}) {
		return config;
	}

	// Fetch a list of the available currencies, with their respective settings
	let currencies = settings.currencies;

	// Fetch the selected currency. The "query" variable contains the arguments
	// passed with the request
	let selected_currency = query['report_currency'] || '';

	// If the selected currency matches one of the currencies in the list, return
	// the formatting settings for that currency
	if(selected_currency && currencies[selected_currency]) {
		config = currencies[selected_currency];
	}
	return config;
}

addFilter('woocommerce_admin_report_currency', 'Aelia/WC/CurrencySwitcher', update_report_currency);

/**
 * Add "currency" to the list of persisted queries so that the parameter remains
 * when navigating between different reports.
 *
 * @param array
 * @return array
 * @since 4.8.11.200524
 */
function persist_query_args(params) {
	params.push('report_currency');
	return params;
}

addFilter('woocommerce_admin_persisted_queries', 'Aelia/WC/CurrencySwitcher', persist_query_args);