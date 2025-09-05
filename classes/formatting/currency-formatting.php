<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\Traits\Singleton;

/**
 * Handles the formatting of prices, in each currency.
 *
 * @since 4.11.0.210517
 */
class Currency_Formatting {
	use Singleton;

	/**
	 * Trims the trailing zeroes from a formatted floating point value.
	 *
	 * @param string value The falue to format.
	 * @param string decimal_separator The decimal separator.
	 * @return string
	 */
	protected function trim_zeroes($value, $decimal_separator) {
		$trim_regex = '/' . preg_quote($decimal_separator, '/') . '0++$/';
		return preg_replace($trim_regex, '', $value);
	}

	public function __construct()	{
		// Set the hooks used to format the currency prices when WooCommerce is loaded. Waiting until
		// WooCommerce is loaded can help preventing conflicts with other plugins
		// @since 4.11.0.210517
		add_action('woocommerce_init', array($this, 'set_currency_formatting_hooks'), 5);
	}

	/**
	 * Formats a raw price using WooCommerce settings.
	 *
	 * @param float raw_price The price to format.
	 * @param string currency The currency code. If empty, currently selected
	 * currency is taken.
	 * @return string
	 */
	public function format_price($raw_price, $currency = null) {
		// Prices may be left empty. In such case, there's no need to format them
		if(!is_numeric($raw_price)) {
			return $raw_price;
		}

		if(empty($currency)) {
			$currency = WC_Aelia_CurrencySwitcher::instance()->get_selected_currency();
		}

		$price_decimals = WC_Aelia_CurrencySwitcher::settings()->price_decimals($currency);
		$decimal_separator = WC_Aelia_CurrencySwitcher::settings()->get_currency_decimal_separator($currency);
		$price = number_format($raw_price,
													 $price_decimals,
													 $decimal_separator,
													 WC_Aelia_CurrencySwitcher::settings()->get_currency_thousand_separator($currency));

		if(($price_decimals > 0) && (get_option('woocommerce_price_trim_zeros') == 'yes')) {
			$price = $this->trim_zeroes($price, $decimal_separator);
		}

		$currency_symbol = get_woocommerce_currency_symbol($currency);

		return '<span class="amount">' . sprintf(get_woocommerce_price_format(), $currency_symbol, $price) . '</span>';
	}

	/**
	 * Enables or disables the hooks used to format the currency prices.
	 *
	 * @param bool enable Indicates if the hooks should be enabled or disabled.
	 * @since 4.11.0.210517
	 */
	public function set_currency_formatting_hooks($enable = true) {
		// When this method is used as an action, it receives the first argument as an
		// empty string. However, in that case, we must consider it as if it were "true",
		// as it's the default action to take when this method is not explicitly called
		// with "false"
		// @since 4.12.0.210628
		if($enable !== false) {
			// Display prices with the amount of decimals configured for the active currency
			add_filter('pre_option_woocommerce_price_num_decimals', array($this, 'pre_option_woocommerce_price_num_decimals'), 10, 1);
			add_filter('pre_option_woocommerce_currency_pos', array($this, 'pre_option_woocommerce_currency_pos'), 10, 1);
			add_filter('pre_option_woocommerce_price_thousand_sep', array($this, 'pre_option_woocommerce_price_thousand_sep'), 10, 1);
			add_filter('pre_option_woocommerce_price_decimal_sep', array($this, 'pre_option_woocommerce_price_decimal_sep'), 10, 1);
		}
		else {
			// Display prices with the amount of decimals configured for the active currency
			remove_filter('pre_option_woocommerce_price_num_decimals', array($this, 'pre_option_woocommerce_price_num_decimals'), 10, 1);
			remove_filter('pre_option_woocommerce_currency_pos', array($this, 'pre_option_woocommerce_currency_pos'), 10, 1);
			remove_filter('pre_option_woocommerce_price_thousand_sep', array($this, 'pre_option_woocommerce_price_thousand_sep'), 10, 1);
			remove_filter('pre_option_woocommerce_price_decimal_sep', array($this, 'pre_option_woocommerce_price_decimal_sep'), 10, 1);
		}
	}

	/**
	 * Overrides the number of decimals used to format prices.
	 *
	 * @param int decimals The number of decimals passed by WooCommerce.
	 * @return int
	 */
	public function pre_option_woocommerce_price_num_decimals($decimals) {
		return WC_Aelia_CurrencySwitcher::settings()->price_decimals(get_woocommerce_currency());
	}

	/**
	 * Sets the currency symbol position for the active currency.
	 *
	 * @param string position The default position used by WooCommerce.
	 * @return string The position to use for the active currency.
	 */
	public function pre_option_woocommerce_currency_pos($position) {
		return WC_Aelia_CurrencySwitcher::settings()->get_currency_symbol_position(get_woocommerce_currency());
	}

	/**
	 * Sets the thousdand separator for the active currency.
	 *
	 * @param string position The default position used by WooCommerce.
	 * @return string The thousand separator.
	 */
	public function pre_option_woocommerce_price_thousand_sep($thousand_separator) {
		return WC_Aelia_CurrencySwitcher::settings()->get_currency_thousand_separator(get_woocommerce_currency());
	}

	/**
	 * Sets the thousdand separator for the active currency.
	 *
	 * @param string position The default position used by WooCommerce.
	 * @return string The decimal separator.
	 */
	public function pre_option_woocommerce_price_decimal_sep($decimal_separator) {
		return WC_Aelia_CurrencySwitcher::settings()->get_currency_decimal_separator(get_woocommerce_currency());
	}
}