<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

/**
 * Implements features to integrate with the WooCommerce price filter widget.
 *
 * @since 4.9.11.210114
 */
class Price_Filters_Integration {
	use \Aelia\WC\Traits\Singleton;

	/**
	 * The default step used by the WooCommerce price filter widget.
	 *
	 * @var int
	 */
	const DEF_PRICE_FILTER_STEP = 10;

	/**
	 * Returns shop's base currency.
	 *
	 * @return string
	 */
	protected static function get_base_currency() {
		return WC_Aelia_CurrencySwitcher::instance()->base_currency();
	}

	/**
	 * Returns the selected currency.
	 *
	 * @return string
	 */
	protected static function get_selected_currency() {
		return WC_Aelia_CurrencySwitcher::instance()->get_selected_currency();
	}

	/**
	 * Initialisation function. Implemented for consistency with other integration classes.
	 *
	 * @return Aelia\WC\CurrencySwitcher\Price_Filters_Integration
	 */
	public static function init() {
		return static::instance();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->set_hooks();
	}

	/**
	 * Sets the actions and filters used by the class.
	 */
	protected function set_hooks() {
		// Alter the product meta query, to ensure that the price filters work correctly
		if(aelia_wc_version_is('>=', '3.6')) {
			// @since WC 3.6.0
			add_filter('posts_clauses', array($this, 'price_filter_post_clauses'), 150, 2);
		}
		else {
			// @deprecated WC 3.6.0
			add_filter('woocommerce_product_query_meta_query', array($this, 'woocommerce_product_query_meta_query'), 99, 2);
		}

		// Convert the min and max catalogue prices available to the price filter widget
		add_filter('woocommerce_price_filter_widget_min_amount', array($this, 'woocommerce_price_filter_widget_min_amount'));
		add_filter('woocommerce_price_filter_widget_max_amount', array($this, 'woocommerce_price_filter_widget_max_amount'));

		add_filter('widget_display_callback', array($this, 'update_price_filters_range'), 99, 3);
	}

	/**
	 * Alters the product meta query, converting the min/max values of the price filter
	 * to base currency. This conversion is needed because the price filtering query
	 * compares the value of the "_price" meta with the min and max value. Such meta is
	 * in shop's base currency.
	 *
	 * @param array $meta_query
	 * @param WC_Query $wc_query
	 * @return array
	 * @deprecated WC 3.6
	 */
	public function woocommerce_product_query_meta_query($meta_query, $wc_query) {
		// Alter the meta query if the min or the max price filter have been specified
		if(isset($meta_query['price_filter']) && (isset($_GET['max_price']) || isset($_GET['min_price']))) {
			// The first value is the minimum price
			$min = array_shift($meta_query['price_filter']['value']);
			if(!is_numeric($min)) {
				$min = 0;
			}

			// The second value is the maximum price
			$max = array_shift($meta_query['price_filter']['value']);
			if(!is_numeric($max)) {
				$max = 9999999999;
			}

			// Get the price filter currency
			$price_filter_currency = $_GET[Definitions::ARG_PRICE_FILTER_CURRENCY] ?? self::get_base_currency();
			$base_currency = self::get_base_currency();

			// Convert the min and max prices from the filter currency to the base currency,
			// so that they can be used by the WC_Query object to filter the products
			$meta_query['price_filter']['value'] = array(
				apply_filters('wc_aelia_cs_convert', $min, $price_filter_currency, $base_currency),
				apply_filters('wc_aelia_cs_convert', $max, $price_filter_currency, $base_currency),
			);
		}

		return $meta_query;
	}

	/**
	 * Custom query used to filter products by price.
	 *
	 * @param array $args
	 * @param WP_Query $wp_query
	 * @return array
	 */
	public function price_filter_post_clauses($args, $wp_query) {
		if(!$wp_query->is_main_query() || (!isset($_GET['max_price']) && !isset($_GET['min_price']))) {
			return $args;
		}

		// If a price filter currency has not been specified, assume that the range is in the active currency
		$price_filter_currency = $_GET[Definitions::ARG_PRICE_FILTER_CURRENCY] ?? self::get_selected_currency();

		// The standard filters look into the "_price" meta, which is is shop's base currency. Due to that,
		// the min and max filter values have to be converted to that currency
		$target_currency = self::get_base_currency();

		if($price_filter_currency !== $target_currency) {
			global $wpdb;

			// Fetch current min and max price. They will be used to find and replace the filter clauses
			// from the SQL query
			$current_min_price = floatval(wp_unslash($_GET['min_price'] ?? 0));
			$current_max_price = floatval(wp_unslash($_GET['max_price'] ?? PHP_INT_MAX));

			// Converts the price range to shop's base currency, so that it can be used by WooCommerce's filtering query,
			// which reads prices in shop's base currency straight from the database
			$target_min_price = floor(apply_filters('wc_aelia_cs_convert', $current_min_price, $price_filter_currency, $target_currency));
			$target_max_price = ceil(apply_filters('wc_aelia_cs_convert', $current_max_price, $price_filter_currency, $target_currency));

			// Search and replace the filter queries. This is a horrible system to replace the values, and it's seriously open to
			// issues, but it's the only reliable way to replace the values just for the price filter. Replacing the values in
			// the $_GET array will no longer work, and it will interfere with the rendering of the price filter widget
			$args['where'] = str_replace($wpdb->prepare('wc_product_meta_lookup.min_price >= %f', $current_min_price),
																	 $wpdb->prepare('wc_product_meta_lookup.min_price >= %f', $target_min_price), $args['where']);
			$args['where'] = str_replace($wpdb->prepare('wc_product_meta_lookup.max_price <= %f', $current_max_price),
																	 $wpdb->prepare('wc_product_meta_lookup.max_price <= %f', $target_max_price), $args['where']);
		}

		return $args;
	}

	/**
	 * Returns the step used by the WooCommerce price filter widget.
	 *
	 * @return int
	 * @since 4.9.11.210114
	 */
	protected static function get_filter_widget_step() {
		return max(apply_filters('woocommerce_price_filter_widget_step', self::DEF_PRICE_FILTER_STEP), 1);
	}

	/**
	 * Converts the minimum catalogue prices available to the price filter widget (i.e. the lowest minimum
	 * price across all products). WooCommerce calculates such value in shop's base currency, and it has to
	 * be converted to the active currency before it can be used in the price filter widget.
	 *
	 * @param float $amount
	 * @return float
	 */
	public function woocommerce_price_filter_widget_min_amount($amount) {
		$filter_step = self::get_filter_widget_step();

		return floor(apply_filters('wc_aelia_cs_convert', $amount, self::get_base_currency(), self::get_selected_currency()) / $filter_step) * $filter_step;
	}

	/**
	 * Converts the minimum catalogue prices available to the price filter widget (i.e. the highest minimum
	 * price across all products). WooCommerce calculates such value in shop's base currency, and it has to
	 * be converted to the active currency before it can be used in the price filter widget.
	 *
	 * @param float $amount
	 * @return float
	 */
	public function woocommerce_price_filter_widget_max_amount($amount) {
		$filter_step = self::get_filter_widget_step();

		return ceil(apply_filters('wc_aelia_cs_convert', $amount, self::get_base_currency(), self::get_selected_currency()) / $filter_step) * $filter_step;
	}

	/**
	 * Updates the min and max prices selected in the price filter widget, before they are used to display the widget, or
	 * the layered navigation widget. This method doesn't alter the arguments passed to it, it's only used as a convenient
	 * event to perform the conversion.
	 *
	 * @param array $widget_args
	 * @param WP_Widget $widget
	 * @param array $default_args
	 * @return array
	 * @since 4.9.11.210114
	 */
	public function update_price_filters_range($widget_args, $widget, $default_args) {
		if(apply_filters('wc_aelia_cs_convert_price_filter_prices', ($widget instanceof \WC_Widget_Layered_Nav_Filters) || $widget instanceof \WC_Widget_Price_Filter)) {
			// Fetch the currency used to filter product prices
			$price_filter_currency = $_GET[Definitions::ARG_PRICE_FILTER_CURRENCY] ?? self::get_base_currency();

			// If the price filter currency is different from the active one, convert the min and max values
			if($price_filter_currency !== self::get_selected_currency()) {
				$filter_step = self::get_filter_widget_step();

				if(isset($_GET['max_price'])) {
					// Convert the max selected price, taking into account the steps
					$_GET['max_price'] = ceil(apply_filters('wc_aelia_cs_convert', $_GET['max_price'], $price_filter_currency, self::get_selected_currency()) / $filter_step) * $filter_step;
				}

				if(isset($_GET['min_price'])) {
					// Convert the min selected price, taking into account the steps
					$_GET['min_price'] = floor(apply_filters('wc_aelia_cs_convert', $_GET['min_price'], $price_filter_currency, self::get_selected_currency()) / $filter_step) * $filter_step;
				}

				// Update the currency, so that the min and max values won't be converted from the original currency again
				$_GET[Definitions::ARG_PRICE_FILTER_CURRENCY] = self::get_selected_currency();
			}
		}

		return $widget_args;
	}
}