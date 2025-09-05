<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

/**
 * Adds support for the WooCommerce Admin Analytics reports.
 *
 * @since 4.8.2.200310
 */
class WC_Aelia_CS_WooCommerce_Admin_Integration {
	protected static $id = 'woocommerce-admin-integration';

	protected static $text_domain = Definitions::TEXT_DOMAIN;

	/**
	 * The currency used to display the reports.
	 *
	 * @var string
	 */
	protected $report_currency;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// @since 4.10.0.210312
		add_action('woocommerce_init', array($this, 'set_hooks'));
	}

	/**
	 * Is the WooCommerce Admin actively included in the WooCommerce core?
	 * Based on presence of a basic WC Admin function.
	 *
	 * @return boolean
	 */
	protected static function is_wc_admin_active() {
		return function_exists('wc_admin_url');
	}

	/**
	 * Returns the name of the table where the order meta is stored.
	 * The method takes into account the HPOS feature, if enabled.
	 *
	 * @return string
	 * @since 5.0.0.230203
	 */
	protected function get_order_meta_table(): string {
		global $wpdb;

		return aelia_is_hpos_feature_enabled() ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
	}

	/**
	 * Returns the name of the column from the order meta table containing
	 * the order ID. The method takes into account the HPOS feature, if enabled.
	 *
	 * @return string
	 * @since 5.0.0.230203
	 */
	protected function get_order_id_column(): string {
		return aelia_is_hpos_feature_enabled() ? 'order_id' : 'post_id';
	}

	/**
	 * Set the hooks required by the class.
	 */
	public function set_hooks() {
		// If the WC Admin is not active, no need to do anything
		if(!self::is_wc_admin_active()) {
			return;
		}

		add_filter('admin_init', array($this, 'admin_init'), 50);
		add_filter('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'), 50);

		$this->set_orders_analytics_hooks();
		$this->set_revenue_analytics_hooks();
		$this->set_products_analytics_hooks();
		$this->set_categories_analytics_hooks();
		$this->set_taxes_analytics_hooks();
		// Add currency filter for coupons
		// @since 4.8.11.200524
		$this->set_coupons_analytics_hooks();

		// Add currency filter for customers
		// @since 4.9.0.200917
		$this->set_customers_analytics_hooks();

		// Add currency filter for variations
		// @since 4.9.4.201118
		$this->set_variations_analytics_hooks();
	}

	/**
	 * Sets the hooks used to alter the Analytics reports.
	 *
	 * @param int $report_id
	 */
	protected function set_report_hooks($report_id) {
		add_filter('woocommerce_analytics_' . $report_id . '_query_args', array($this, 'set_currency_filter_argument'));
		add_filter('woocommerce_analytics_' . $report_id . '_stats_query_args', array($this, 'set_currency_filter_argument'));

		if($this->get_report_currency() !== Definitions::DEF_REPORT_CURRENCY) {
			add_filter('woocommerce_analytics_clauses_join_' . $report_id . '_subquery', array($this, 'add_currency_filter_join'), 999);
			add_filter('woocommerce_analytics_clauses_join_' . $report_id . '_stats_total', array($this, 'add_currency_filter_join'), 999);
			add_filter('woocommerce_analytics_clauses_join_' . $report_id . '_stats_interval', array($this, 'add_currency_filter_join'), 999);
		}
		elseif(aelia_wc_version_is('>=', '4.6')) {
			// Generate a report with a total in base currency
			// This feature is supported in the Analytics included in WooCommerce 4.6 and later (requires WooCommerce Admin 1.6)
			// @link https://github.com/woocommerce/woocommerce-admin/pull/4984#issuecomment-682218866

			// Adds the join clauses to fetch the exchange rate saved with each order
			add_filter('woocommerce_analytics_clauses_join_' . $report_id . '_subquery', array($this, 'add_base_currency_join'), 999);
			add_filter('woocommerce_analytics_clauses_join_' . $report_id . '_stats_total', array($this, 'add_base_currency_join'), 999);
			add_filter('woocommerce_analytics_clauses_join_' . $report_id . '_stats_interval', array($this, 'add_base_currency_join'), 999);

			// Alter the SELECT clauses of the reports, to replace the data with its base currency equivalent
			add_filter('woocommerce_admin_report_columns', array($this, 'woocommerce_admin_report_columns_totals_in_base_currency'), 10, 3);
		}
	}

	/**
	 * Alters the columns for the WC Admin reports, to return totals in base currency.
	 *
	 * @param array $columns
	 * @param string $context
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 */
	public function woocommerce_admin_report_columns_totals_in_base_currency($columns, $context, $table_name) {
		$method_name = "get_{$context}_report_columns_base_currency";
		if(method_exists($this, $method_name)) {
			$columns = $this->$method_name($columns, $table_name);
		}

		return $columns;
	}

	/**
	 * Categories Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Categories\DataStore
	 */
	protected function get_categories_report_columns_base_currency($columns, $table_name) {
		$columns['net_revenue'] = "SUM(product_net_revenue * ORDERS_BCER.meta_value) AS net_revenue";

		return $columns;
	}

	/**
	 * Coupons Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Coupons\DataStore
	 */
	protected function get_coupons_report_columns_base_currency($columns, $table_name) {
		$columns['amount'] = "SUM(discount_amount * ORDERS_BCER.meta_value) AS amount";

		return $columns;
	}

	/**
	 * Coupons Stats Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Coupons\Stats\DataStore
	 */
	protected function get_coupons_stats_report_columns_base_currency($columns, $table_name) {
		$columns['amount'] = "SUM(discount_amount * ORDERS_BCER.meta_value) AS amount";

		return $columns;
	}

	/**
	 * Customers Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore
	 */
	protected function get_customers_report_columns_base_currency($columns, $table_name) {
		$orders_count = 'SUM( CASE WHEN parent_id = 0 THEN 1 ELSE 0 END )';
		$total_spend = 'SUM(total_sales * ORDERS_BCER.meta_value)';

		$columns['total_spend'] = "{$total_spend} as total_spend";
		$columns['avg_order_value'] = "CASE WHEN {$orders_count} = 0 THEN NULL ELSE {$total_spend} / {$orders_count} END AS avg_order_value";

		return $columns;
	}

	/**
	 * Customers Stats Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Customers\Stats\DataStore
	 */
	protected function get_customers_stats_report_columns_base_currency($columns, $table_name) {
		// We shouldn't need to change the columns for the Customers Stats report.
		// That reports SHOULD rely on the columns for the "Customers" report, whic are already
		// altered by a dedicated method.
		// @see WC_Aelia_CS_WooCommerce_Admin_Integration::get_customers_report_columns_base_currency()

		return $columns;
	}

	/**
	 * Orders Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Orders\DataStore
	 */
	protected function get_orders_report_columns_base_currency($columns, $table_name) {
		$columns['net_total'] = "({$table_name}.net_total * ORDERS_BCER.meta_value)";
		$columns['total_sales'] = "({$table_name}.total_sales * ORDERS_BCER.meta_value)";

		return $columns;
	}

	/**
	 * Orders Stats Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore
	 */
	protected function get_orders_stats_report_columns_base_currency($columns, $table_name) {
		$refunds = "ABS( SUM( CASE WHEN {$table_name}.net_total < 0 THEN ({$table_name}.net_total * ORDERS_BCER.meta_value) ELSE 0 END ) )";
		$gross_sales =
		"( SUM({$table_name}.total_sales * ORDERS_BCER.meta_value)" .
		' + COALESCE( SUM(discount_amount * ORDERS_BCER.meta_value), 0 )' . // SUM() all nulls gives null.
		" - SUM({$table_name}.tax_total * ORDERS_BCER.meta_value)" .
		" - SUM({$table_name}.shipping_total * ORDERS_BCER.meta_value)" .
		" + {$refunds}" .
		' ) as gross_sales';

		return array_merge($columns, array(
			'gross_sales' => $gross_sales,
			'total_sales' => "SUM({$table_name}.total_sales* ORDERS_BCER.meta_value) AS total_sales",
			'coupons' => 'COALESCE( SUM(discount_amount * ORDERS_BCER.meta_value), 0 ) AS coupons', // SUM() all nulls gives null.
			'refunds' => "{$refunds} AS refunds",
			'taxes' => "SUM({$table_name}.tax_total * ORDERS_BCER.meta_value) AS taxes",
			'shipping' => "SUM({$table_name}.shipping_total * ORDERS_BCER.meta_value) AS shipping",
			'net_revenue' => "SUM({$table_name}.net_total * ORDERS_BCER.meta_value) AS net_revenue",
			'avg_order_value' => "SUM( {$table_name}.net_total * ORDERS_BCER.meta_value ) / SUM( CASE WHEN {$table_name}.parent_id = 0 THEN 1 ELSE 0 END ) AS avg_order_value",
		));
	}

	/**
	 * Products Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Products\DataStore
	 */
	protected function get_products_report_columns_base_currency($columns, $table_name) {
		$columns['net_revenue'] = "SUM(product_net_revenue * ORDERS_BCER.meta_value) AS net_revenue";

		return $columns;
	}

	/**
	 * Products Stats Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Products\Stats\DataStore
	 */
	protected function get_products_stats_report_columns_base_currency($columns, $table_name) {
		$columns['net_revenue'] = "SUM(product_net_revenue * ORDERS_BCER.meta_value) AS net_revenue";

		return $columns;
	}

	/**
	 * Taxes Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Taxes\DataStore
	 */
	protected function get_taxes_report_columns_base_currency($columns, $table_name) {
		$columns['total_tax'] = "SUM(total_tax * ORDERS_BCER.meta_value) as total_tax";
		$columns['order_tax'] = "SUM(order_tax * ORDERS_BCER.meta_value) as order_tax";
		$columns['shipping_tax'] = "SUM(shipping_tax * ORDERS_BCER.meta_value) as shipping_tax";

		return $columns;
	}

	/**
	 * Taxes Stats Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.0.200917
	 * @see Automattic\WooCommerce\Admin\API\Reports\Taxes\Stats\DataStore
	 */
	protected function get_taxes_stats_report_columns_base_currency($columns, $table_name) {
		$columns['total_tax'] = "SUM(total_tax * ORDERS_BCER.meta_value) as total_tax";
		$columns['order_tax'] = "SUM(order_tax * ORDERS_BCER.meta_value) as order_tax";
		$columns['shipping_tax'] = "SUM(shipping_tax * ORDERS_BCER.meta_value) as shipping_tax";

		return $columns;
	}

	/**
	 * Variations Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.4.201118
	 * @see Automattic\WooCommerce\Admin\API\Reports\Variations\DataStore
	 */
	protected function get_variations_report_columns_base_currency($columns, $table_name) {
		$columns['net_revenue'] = "SUM(product_net_revenue * ORDERS_BCER.meta_value) AS net_revenue";

		return $columns;
	}

	/**
	 * Variations Stats Report. Returns to calculate totals in base currency.
	 *
	 * @param array $columns
	 * @param string $table_name
	 * @return array
	 * @since 4.9.4.201118
	 * @see Automattic\WooCommerce\Admin\API\Reports\Variations\Stats\DataStore
	 */
	protected function get_variations_stats_report_columns_base_currency($columns, $table_name) {
		$columns['net_revenue'] = "SUM(product_net_revenue * ORDERS_BCER.meta_value) AS net_revenue";

		return $columns;
	}

	/**
	 * Sets the filters to extend the orders analytics.
	 */
	protected function set_orders_analytics_hooks() {
		$this->set_report_hooks('orders');
	}

	/**
	 * Sets the filters to extend the revenue analytics.
	 */
	protected function set_revenue_analytics_hooks() {
		$this->set_report_hooks('revenue');
	}

	/**
	 * Sets the filters to extend the products analytics.
	 */
	protected function set_products_analytics_hooks() {
		$this->set_report_hooks('products');
	}

	/**
	 * Sets the filters to extend the categories analytics.
	 */
	protected function set_categories_analytics_hooks() {
		$this->set_report_hooks('categories');
	}

	/**
	 * Sets the filters to extend the taxes analytics.
	 */
	protected function set_taxes_analytics_hooks() {
		$this->set_report_hooks('taxes');
	}

	/**
	 * Sets the filters to extend the coupons analytics.
	 *
	 * @since 4.8.11.200524
	 */
	protected function set_coupons_analytics_hooks() {
		$this->set_report_hooks('coupons');
	}

	/**
	 * Sets the filters to extend the customers analytics.
	 *
	 * @since 4.9.0.200917
	 */
	protected function set_customers_analytics_hooks() {
		$this->set_report_hooks('customers');
	}

	/**
	 * Sets the filters to extend the variations analytics.
	 *
	 * @since 4.9.4.201118
	 */
	protected function set_variations_analytics_hooks() {
		$this->set_report_hooks('variations');
	}

	/**
	 * Adds a JOIN to the analytics query, to allow filtering the entries by currency.
	 *
	 * @param array $clauses
	 * @return array
	 */
	public function add_currency_filter_join($clauses) {
		global $wpdb;

		// Check if the HPOS feature is enabled. The order currency is located in a different
		// place, in that case
		// @since 5.0.0.230203
		if(aelia_is_hpos_feature_enabled()) {
			$orders_table = $wpdb->prefix . 'wc_orders';

			// If the HPOS feature is enabled, link to the new orders table
			// to filter orders based on the currency in which they were placed
			$clauses[] = $wpdb->prepare("
				JOIN {$orders_table} ORDERS_CURRENCY ON
						(ORDERS_CURRENCY.id = {$wpdb->prefix}wc_order_stats.order_id) AND
						(ORDERS_CURRENCY.currency = %s)
				", $this->get_report_currency());
		}
		else {
			// Legacy query, to use when the HPOS feature is disabled
			$clauses[] = $wpdb->prepare("
				JOIN {$wpdb->postmeta} ORDERS_CURRENCY ON
					(ORDERS_CURRENCY.post_id = {$wpdb->prefix}wc_order_stats.order_id) AND
					(ORDERS_CURRENCY.meta_key = '_order_currency') AND
					(ORDERS_CURRENCY.meta_value = %s)
			", $this->get_report_currency());
		}


		return $clauses;
	}

	/**
	 * Adds a JOIN to the analytics query, to fetch the exchange rate to calculate totals in base currency.
	 *
	 * @param array $clauses
	 * @return array
	 * @since 4.9.0.200917
	 */
	public function add_base_currency_join($clauses) {
		global $wpdb;

		// @since 5.0.0.230203
		$order_meta_table = $this->get_order_meta_table();
		$order_id_column = $this->get_order_id_column();

		// ORDERS_BCER stays for ORDERS_BASE_CURRENCY_EXCHANGE_RATE
		$clauses[] = $wpdb->prepare("JOIN {$order_meta_table} ORDERS_BCER ON
				(ORDERS_BCER.{$order_id_column} = {$wpdb->prefix}wc_order_stats.order_id) AND
				(ORDERS_BCER.meta_key = %s)", Definitions::META_BASE_CURRENCY_EXCHANGE_RATE);

		return $clauses;
	}

	/**
	 * Alters the arguments used by the WC Admin to cache the queries. This is done so that a different query is executed
	 * whenever the currency changes.
	 *
	 * @param array $args
	 * @return array
	 */
	public function set_currency_filter_argument($args) {
		$args[Definitions::ARG_REPORT_CURRENCY] = $this->get_report_currency();

		return $args;
	}

	/**
	 * Returns the currency to be used to run the reports.
	 *
	 * @return string
	 */
	protected function get_report_currency() {
		if(empty($this->report_currency)) {
			if(!empty($_REQUEST[Definitions::ARG_REPORT_CURRENCY]) && in_array($_REQUEST[Definitions::ARG_REPORT_CURRENCY], array_keys(WC_Aelia_Reporting_Manager::get_currencies_from_sales()))) {
				$this->report_currency = wc_clean($_REQUEST[Definitions::ARG_REPORT_CURRENCY]);
			}
			else {
				// Set the default report "base", to produce grand totals in shop's base currency
				// @since 4.9.0.200917
				$this->report_currency = self::get_default_report_currency();
			}
		}
		return $this->report_currency;
	}

	/**
	 * Indicates if we are on a WC Admin Analytics page.
	 *
	 * @return bool
	 */
	protected function is_wc_admin_page() {
		return isset($_GET['page']) && ($_GET['page'] === 'wc-admin');
	}

	/**
	 * Indicates if the current setup can be supported by the integration.
	 *
	 * @return boolean
	 * @since 4.8.3.200311
	 */
	protected function is_supported_wc_admin_version() {
		// If the Blocks\Package class doesn't exist, or doesn't contain the
		// necessary methods, we can stop here, as the integration can't run
		if(!class_exists('\Automattic\WooCommerce\Blocks\Package') ||
			 !method_exists('\Automattic\WooCommerce\Blocks\Package', 'container')) {
			return false;
		}

		return true;
	}

	/**
	 * Returns the currency to use by default in the Analytics reports.
	 * - WC 4.5 and earlier: shop's base currency.
	 * - WC 4.6 and later: "base", which means "generate reports with all data and totals in base currency".
	 *
	 * @return string
	 * @since 4.9.0.200917
	 */
	protected static function get_default_report_currency() {
		return aelia_wc_version_is('>=', '4.6') ? Definitions::DEF_REPORT_CURRENCY : get_option('woocommerce_currency');
	}

	/**
	 * Registers the settings for the currency filter field on the Analytics dashboard.
	 */
	protected function register_currency_filter_field_settings() {
		// If current setup is not supported, stop here
		// @since 4.8.3.200311
		if(!$this->is_supported_wc_admin_version()) {
			return;
		}

		$report_currency_options = array();
		// Adds the list of currencies
		foreach(WC_Aelia_Reporting_Manager::get_currencies_from_sales() as $currency_code => $currency_label) {
			$report_currency_options[] = array(
				'value' => $currency_code,
				'label' => $currency_label,
			);
		}

		// Sort the currencies by name
		usort($report_currency_options, function($a, $b) {
			return strcmp($a['label'], $b['label']);
		});


		if(aelia_wc_version_is('>=', '4.6')) {
			// Add a default option to generate reports with grand totals in base currency
			// at the top of the list
			//
			// This feature is supported in the Analytics included in WooCommerce 4.6 and later (requires WooCommerce Admin 1.6)
			// @link https://github.com/woocommerce/woocommerce-admin/pull/4984#issuecomment-682218866
			//
			// @since 4.9.0.200917
			$report_currency_options = array_merge(
				array(
					array(
						'value' => Definitions::DEF_REPORT_CURRENCY,
						'label' => __('All data, totals in base currency', self::$text_domain),
					)
			), $report_currency_options);
		}

		// Pass the settings to the JS frontend
		$data_registry = \Automattic\WooCommerce\Blocks\Package::container()->get(
			\Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry::class
		);

		$data_registry->add('aelia_cs_woocommerce_admin_integration', array(
			'arg_report_currency' => Definitions::ARG_REPORT_CURRENCY,
			// TODO Set the default currency to "all totals in base currency"
			'default_report_currency' => self::get_default_report_currency(),
			'text_domain' => self::$text_domain,
			'report_currency_options' => $report_currency_options,
			// Pass the list of available currencies and their formats
			// @since 4.8.7.200417
			'currencies' => $this->get_currencies(),
		));
	}

	/**
	 * Returns a list of entries containing formatting information, such as
	 * currency symbol and format, for the currencies used to prepare the
	 * analytics reports.
	 *
	 * @return array
	 * @since 4.8.7.200417
	 * @link https://woocommerce.wordpress.com/2020/02/20/extending-wc-admin-reports/
	 */
	protected function get_currencies() {
		// Get the settings manager
		$cs_settings = WC_Aelia_CurrencySwitcher::settings();

		$currencies = array();
		foreach(array_keys(WC_Aelia_Reporting_Manager::get_currencies_from_sales()) as $currency) {
			// Store the settings for each currency
			$currencies[$currency] = array(
				'code' => $currency,
				'symbol' => html_entity_decode(get_woocommerce_currency_symbol($currency)),
				'symbolPosition' => $cs_settings->get_currency_symbol_position($currency),
				'thousandSeparator' => $cs_settings->get_currency_thousand_separator($currency),
				'decimalSeparator' => $cs_settings->get_currency_decimal_separator($currency),
				'precision' => $cs_settings->get_currency_decimals($currency),
				'priceFormat' => html_entity_decode(self::get_woocommerce_price_format($currency)),
			);
		}
		return $currencies;
	}

	/**
	 * Get the price format for the given currency.
	 *
	 * NOTES
	 * This method is an almost verbatim re-implementation of core function get_woocommerce_price_format().
	 * The main difference is that this method allows to specify a currency explicitly, making it easier
	 * to fetch the format for such currency.
	 *
	 * @return string
	 * @see get_woocommerce_price_format()
	 * @since 4.8.9.200506
	 */
	protected static function get_woocommerce_price_format($currency) {
		$currency_pos = WC_Aelia_CurrencySwitcher::settings()->get_currency_symbol_position($currency);
		$format = '%1$s%2$s';

		switch($currency_pos) {
			case 'left':
				$format = '%1$s%2$s';
				break;
			case 'right':
				$format = '%2$s%1$s';
				break;
			case 'left_space':
				$format = '%1$s&nbsp;%2$s';
				break;
			case 'right_space':
				$format = '%2$s&nbsp;%1$s';
				break;
			default:
				$format = '%1$s%2$s';
		}

		return apply_filters('woocommerce_price_format', $format, $currency_pos);
	}

	/**
	 * Loads the scripts that extend the WC Admin dashboard.
	 */
	protected function enqueue_currency_filter_admin_scripts() {
		// The asset file just generates an array with the dependencies and the version
		// of the script
		$asset_file = include(__DIR__ . '/build/index.asset.php');

		// Enqueue the script to add a currency filter to the WC Admin reports
		$script_id = WC_Aelia_CurrencySwitcher::$plugin_slug . '-' . self::$id;
		wp_register_script(
			$script_id,
			WC_Aelia_CurrencySwitcher::instance()->url('plugin') . '/lib/classes/integration/woocommerce_admin/build/index.js',
			$asset_file['dependencies'],
			$asset_file['version']
		);
		wp_enqueue_script($script_id);
	}

	/**
	 * Performs initialisation operations on the Analytics dashboard.
	 *
	 * @return void
	 */
	public function admin_init() {
		if($this->is_wc_admin_page()) {
			$this->register_currency_filter_field_settings();
		}
	}

	/**
	 * Adds the JS needed to extend the UI of the WC Admin analytics.
	 */
	public function admin_enqueue_scripts() {
		if($this->is_wc_admin_page()) {
			$this->enqueue_currency_filter_admin_scripts();
		}
	}
}
