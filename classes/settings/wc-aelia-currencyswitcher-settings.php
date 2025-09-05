<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use \Exception;
use \stdClass;

/**
 * Handles the settings for the Currency Switcher plugin and provides convenience
 * methods to read and write them.
 */
class Settings extends \Aelia\WC\Settings {
	/*** Settings Key ***/
	// @var string The key to identify plugin settings amongst WP options.
	const SETTINGS_KEY = 'wc_aelia_currency_switcher';

	/*** Settings fields ***/
	// @var string The name of "enabled currencies" field.
	const FIELD_ENABLED_CURRENCIES = 'enabled_currencies';
	// @var string The name of "exchange rates" field.
	const FIELD_EXCHANGE_RATES = 'exchange_rates';
	// @var string The name of "exchanges rates update enabled" field.
	const FIELD_EXCHANGE_RATES_UPDATE_ENABLE = 'exchange_rates_update_enable';
	// @var string The name of "exchanges rates update schedule" field.
	const FIELD_EXCHANGE_RATES_UPDATE_SCHEDULE = 'exchange_rates_update_schedule';
	// @var string The name of "last update of Exchange Rates" field
	const FIELD_EXCHANGE_RATES_LAST_UPDATE = 'exchange_rates_last_update';
	// @var string The name of "Exchange Rates Provider" field
	const FIELD_EXCHANGE_RATES_PROVIDER = 'exchange_rates_provider';
	// @var string The name of "Open Exchange Rates API Key" field
	const FIELD_OPENEXCHANGE_API_KEY = 'openexchange_api_key';
	// @var string The name of "IP Geolocation enabled" field
	const FIELD_IPGEOLOCATION_ENABLED = 'ipgeolocation_enabled';
	// @var string The default currency to use if the geolocation fails, or if the visitor comes from a country using an unsupported currency
	const FIELD_IPGEOLOCATION_DEFAULT_CURRENCY = 'ipgeolocation_default_currency';
	// @var string The name of "currency payment gateways" field.
	const FIELD_PAYMENT_GATEWAYS = 'payment_gateways';
	const FIELD_CURRENCY_VIA_URL_ENABLED = 'currency_via_url_enabled';
	// @var string The name of the "debug mode" field
	const FIELD_DEBUG_MODE_ENABLED = 'debug_mode';

	/**
	 * The "field" (actually a button) that admins will be able to use to trigger
	 * the import of order data.
	 *
	 * NOTES
	 * - This "field" is not going to be saved as a setting. It's only rendered on the
	 *   settings page.
	 * - This "field" doesn't actually have a type, because it's not an actual setting.
	 *   This is only the ID of the UI element on the settings page.
	 *
	 * @var string
	 * @since 5.0.4.230626
	 */
	const FIELD_IMPORT_SALES_DATA = 'import_sales_data';

	// @var string The name of "force currency base on country" field.
	const FIELD_FORCE_CURRENCY_BY_COUNTRY = 'force_currency_by_country';
	/**
	 * The name of "force currency selection by billing country" field.
	 * @var string
	 * @deprecated since 4.0.0.150311
	 */
	const FIELD_CURRENCY_BY_BILLING_COUNTRY_ENABLED = 'currency_by_billing_country_enabled';

	// @var A list of currency/countries mappings, used to override the currency linked to a country
	// @since 4.13.0.220104
	const FIELD_CURRENCY_COUNTRIES_MAPPINGS = 'currency_countries_mappings';

	// Field values
	const OPTION_DISABLED = 'disabled';
	const OPTION_BILLING_COUNTRY = 'billing_country';
	const OPTION_SHIPPING_COUNTRY = 'shipping_country';

	// @var string The default Exchange Rates Model class to use when the configured one is not valid.
	const DEFAULT_EXCHANGE_RATES_PROVIDER = '\Aelia\WC\CurrencySwitcher\Exchange_Rates_OpenExchangeRates_Model';

	// @var array A list of the currencies supported by WooCommerce
	private $_woocommerce_currencies;
	// @var WC_Aelia_ExchangeRatesModel The model which will retrieve the latest Exchange Rates.
	private $_exchange_rates_model;
	// @var array The definition of the hook that will be called to update the Exchange Rates on a scheduled basis.
	protected $_exchange_rates_update_hook = 'exchange_rates_update_hook';
	// @var array A fist of available Exchange Rates Models.
	protected $exchange_rates_models = array();
	// @var array Stores the price decimals of the currencies. Used for caching purposes.
	protected $_prices_decimals = array();

	// @var The default currency to use when the one detected by the geolocation is not available
	protected $_default_geoip_currency;

	// Default WooCommerce settings
	public $woocommerce_currency_decimals = 2;
	public $woocommerce_currency_position;
	public $woocommerce_price_thousand_sep;
	public $woocommerce_price_decimal_sep;

	/**
	 * Stores a list of the payment gateways enabled for a specific currency.
	 *
	 * @var array
	 * @since 5.1.3.240215
	 */
	protected $_woocommerce_payment_gateways = [];


	/**
	 * Registers a model used to retrieve Exchange Rates.
	 */
	protected function register_exchange_rates_model($class_name, $label) {
		if(!class_exists($class_name) ||
			 !in_array('Aelia\WC\IExchangeRatesModel', class_implements($class_name))) {
			throw new Exception(sprintf(__('Attempted to register class "%s" as an Exchange Rates ' .
																		 'model, but the class does not exist, or does not implement '.
																		 'Aelia\WC\IExchangeRatesModel interface.', $this->textdomain),
																	$class_name));
		}

		$model_id = isset($class_name::$id) ? $class_name::$id : md5($class_name);
		$model_info = new stdClass();
		$model_info->class_name = $class_name;
		$model_info->label = $label;
		$this->exchange_rates_models[$model_id] = $model_info;
	}

	/**
	 * Registers all the available models to retrieve Exchange Rates.
	 */
	protected function register_exchange_rates_models() {
		$namespace = 'Aelia\\WC\\CurrencySwitcher\\';
		// Add OFX model
		// @since 4.5.13.180118
		$this->register_exchange_rates_model($namespace . 'WC_Aelia_OFXModel', __('OFX', $this->textdomain));
		$this->register_exchange_rates_model($namespace . 'Exchange_Rates_OpenExchangeRates_Model', __('Open Exchange Rates', $this->textdomain));
		$this->register_exchange_rates_model($namespace . 'WC_Aelia_TCBModel', __('Turkey Central Bank', $this->textdomain));
		// Yahoo! Finance and WebServiceX have been removed as they are no longer available
		// @since 4.12.6.210825

		// Add ECB Model
		// @since 5.0.4.230626
		$this->register_exchange_rates_model($namespace . 'Aelia_CS_Exchange_Rates_ECB_Model', __('European Central Bank (ECB)', $this->textdomain));

		// Allow 3rd parties to add their models
		// @since 4.5.13.180118
		$this->exchange_rates_models = apply_filters('wc_aelia_cs_exchange_rates_models', $this->exchange_rates_models);
	}

	/**
	 * Returns a list of the registered exchange rates models.
	 *
	 * @return array
	 * @since 4.6.2.180725
	 */
	protected function get_exchange_rates_models() {
		if(empty($this->exchange_rates_models)) {
			$this->register_exchange_rates_models();
		}

		return $this->exchange_rates_models;
	}

	/**
	 * Returns the options related to the various Exchange Rates providers
	 */
	public function exchange_rates_providers_options() {
		$result = array();
		foreach($this->get_exchange_rates_models() as $key => $properties) {
			$result[$key] = $properties->label ?? __('Label not found', $this->textdomain);
		}
		asort($result);

		return $result;
	}

	/**
	 * Returns the Key used to register an Exchange Rates Model. This function
	 * is mainly used to identify which Sections in the Options Page contain
	 * settings for each Exchange Rates Model.
	 *
	 * @param string class_name The class of the Exchange Rates Model.
	 * @return string|null The key used to register the Model, or null ifnot found.
	 */
	public function get_exchange_rates_model_key($class_name) {
		foreach($this->get_exchange_rates_models() as $key => $properties) {
			if(($properties->class_name ?? '') === $class_name) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Get the instance of the Exchange Rate Model to use to retrieve the rates.
	 *
	 * @param string key The key identifying the Exchange Rate Model Class.
	 * @param array An array of Settings that can be used to override the ones
	 * currently saved in the configuration.
	 * @param string default_class The Exchange Rates Model class to use as a default.
	 * @return WC_Aelia_ExchangeRatesModel.
	 */
	protected function get_exchange_rates_model_instance($key,
																											 array $settings = null,
																											 $default_class = self::DEFAULT_EXCHANGE_RATES_PROVIDER) {
		$exchange_rates_models = $this->get_exchange_rates_models();
		$model_info = $exchange_rates_models[$key];
		$model_class = isset($model_info->class_name) ? $model_info->class_name : $default_class;

		return new $model_class($settings);
	}

	/**
	 * Returns the instance of the Exchange Rate Model.
	 *
	 * @param array settings An array of Settings.
	 * @return WC_Aelia_ExchangeRatesModel.
	 */
	protected function exchange_rates_model(array $settings = array()) {
		if(empty($this->_exchange_rates_model)) {
			$exchange_rates_model_key = $settings[self::FIELD_EXCHANGE_RATES_PROVIDER] ?? $this->current_settings(self::FIELD_EXCHANGE_RATES_PROVIDER);
			$this->_exchange_rates_model = $this->get_exchange_rates_model_instance($exchange_rates_model_key,
																																							$settings);
		}

		return $this->_exchange_rates_model;
	}

	/**
	 * Indicates if debug mode is active.
	 *
	 * @return bool
	 */
	public function debug_mode() {
		return $this->current_settings(self::FIELD_DEBUG_MODE_ENABLED, false) == '1';
	}

	/**
	 * Indicates if geolocation debug mode is active.
	 *
	 * @return bool
	 */
	public function debug_geolocation_currency_detection() {
		return !empty($_GET[Definitions::ARG_DEBUG_GEOLOCATION_DETECTION]) &&
					 $this->debug_mode() &&
					 $this->currency_geolocation_enabled();
	}

	/**
	 * Returns the default currency to use when Geolocation featue is eanbled and
	 * visitor's currency doesn't match any of the enabled ones.
	 *
	 * @return string A currency code.
	 */
	public function default_geoip_currency() {
		if(empty($this->_default_geoip_currency)) {
			// Get the configured default currency, defaulting to the base currency if
			// none was set
			$this->_default_geoip_currency = $this->current_settings(self::FIELD_IPGEOLOCATION_DEFAULT_CURRENCY,
																															 $this->base_currency());
			// Default to base currency if the one set as a default is not enabled.
			// This can only happen if a currency is enabled, set as default, then
			// disabled, but it's worth handling the condition to avoid causing issues
			if(!$this->is_currency_enabled($this->_default_geoip_currency)) {
				$this->_default_geoip_currency = $this->base_currency();
			}
		}

		return $this->_default_geoip_currency;
	}

	/**
	 * Getter for private "_exchange_rates_update_hook" property.
	 *
	 * @return string Value of "_exchange_rates_update_hook" property.
	 */
	public function exchange_rates_update_hook() {
		return $this->_exchange_rates_update_hook;
	}

	/**
	 * Returns the default settings for the plugin. Used mainly at first
	 * installation.
	 *
	 * @param string key If specified, method will return only the setting identified
	 * by the key.
	 * @param mixed default The default value to return if the setting requested
	 * via the "key" argument is not found.
	 * @return array|mixed The default settings, or the value of the specified
	 * setting.
	 *
	 * @see WC_Aelia_Settings:default_settings().
	 */
	public function default_settings($key = null, $default = null) {
		$woocommerce_base_currency = $this->base_currency();

		$default_options = array(
			self::FIELD_ENABLED_CURRENCIES => array(
				$woocommerce_base_currency,
			),
			self::FIELD_EXCHANGE_RATES => array(
				$woocommerce_base_currency => array(
					'rate' => 1,
					'set_manually' => 1,
					// For base currency, take the decimals from the WooCommerce configuration
					'decimals' => $this->woocommerce_currency_decimals,
					'thousand_separator' => $this->woocommerce_price_thousand_sep,
					'decimal_separator' => $this->woocommerce_price_decimal_sep,
					'rate_markup' => 0,
				),
			),
			self::FIELD_PAYMENT_GATEWAYS => array(
				$woocommerce_base_currency => array_keys($this->woocommerce_payment_gateways()),
			),
			self::FIELD_FORCE_CURRENCY_BY_COUNTRY => self::OPTION_DISABLED,
			self::FIELD_CURRENCY_VIA_URL_ENABLED => false,
			self::FIELD_DEBUG_MODE_ENABLED => false,

			// @since 4.13.0.220104
			self::FIELD_CURRENCY_COUNTRIES_MAPPINGS => [],
		);

		if(empty($key)) {
			return $default_options;
		}
		else {
			return $default_options[$key] ?? $default;
		}
	}

	/**
	 * Returns the Currency Settings to apply when a Currency is selected for
	 * the first time and has no settings.
	 *
	 * @return array An array of settings.
	 */
	public function default_currency_settings() {
		return array(
			'rate' => '',
			'rate_markup' => '',
			'set_manually' => 0,
			'enabled_gateways' => array_keys($this->woocommerce_payment_gateways()),
			// The default decimals will have to be retrieved using default_currency_decimals() function
			//'decimals',
		);
	}

	/**
	 * Returns a list of Schedule options, retrieved from WordPress list.
	 *
	 * @return array An array of Schedule ID => Schedule Name pairs.
	 */
	public function get_schedule_options() {
		$wp_schedules = wp_get_schedules();
		uasort($wp_schedules, array($this, 'sort_schedules'));

		$result = array();
		foreach($wp_schedules as $schedule_id => $settings) {
			$result[$schedule_id] = $settings['display'];
		}
		return $result;
	}

	/**
	 * Returns an array of Currency => Exchange Rate pairs.
	 *
	 * @param bool include_markup Indicates if the returned exchange rates should
	 * include the markup (if one was specified).
	 * @return array
	 */
	public function get_exchange_rates($include_markup = true) {
		$result = array();
		$exchange_rates_settings = $this->current_settings(self::FIELD_EXCHANGE_RATES);

		if(!is_array($exchange_rates_settings)) {
			$exchange_rates_settings = array();
		}

		// Return all exchange rates, excluding the invalid ones
		foreach($exchange_rates_settings as $currency => $settings) {
			if($currency == $this->base_currency()) {
				// Exchange rate for the base currency is always 1
				$result[$currency] = 1;
				continue;
			}

			if(!empty($settings['rate']) && is_numeric($settings['rate'])) {
				$exchange_rate = (float)$settings['rate'];
			}
			else {
				$exchange_rate = 0;
			}

			// Add the markup to the exchange rate, if one was specified
			if($include_markup) {
				$rate_markup = isset($settings['rate_markup']) ? trim($settings['rate_markup']) : '';

				// If the rate markup is empty, we can save processing time by just
				// ignoring it
				// @since 4.8.12.200605
				if(!empty($rate_markup)) {
					// We can add a numeric markup as it is
					if(is_numeric($rate_markup)) {
						$exchange_rate += (float)$rate_markup;
					}
					else {
						// Interpret a percentage markup, such as "10%"
						// @since 4.6.5.180828
						$markup_multiply_factor = aelia_get_percentage_multiply_factor($rate_markup);

						if(is_numeric($markup_multiply_factor)) {
							$exchange_rate = $exchange_rate * $markup_multiply_factor;
						}
					}
				}
			}

			$result[$currency] = $exchange_rate;
		}

		return $result;
	}

	/**
	 * Returns the number of decimals to be used for a specifc currency.
	 *
	 * @param string currency A currency code
	 * @return array
	 */
	public function get_currency_decimals($currency) {
		$default_decimals = default_currency_decimals($currency, $this->woocommerce_currency_decimals);
		$exchange_rates = $this->current_settings(self::FIELD_EXCHANGE_RATES);
		$currency_settings = isset($exchange_rates[$currency]) ? $exchange_rates[$currency] : false;

		// If no decimals are configured, return default setting
		if(!is_array($currency_settings)) {
			return $default_decimals;
		}

		// If the "decimals" setting is valid (not empty and numeric), use it
		if(isset($currency_settings['decimals']) && is_numeric($currency_settings['decimals'])) {
			return $currency_settings['decimals'];
		}

		// If a valid "decimals" setting was not found, return the default value
		return $default_decimals;
	}

	protected function get_currency_setting($currency, $setting_key, $default) {
		$currencies_settings = $this->current_settings(self::FIELD_EXCHANGE_RATES);
		$currency_settings = isset($currencies_settings[$currency]) ? $currencies_settings[$currency] : false;

		// If no currency settings are configured, return default value
		if(!is_array($currency_settings)) {
			return $default;
		}

		return isset($currency_settings[$setting_key]) ? $currency_settings[$setting_key] : $default;
	}

	/**
	 * Returns the position of the currency symbol for a given currency.
	 *
	 * @param string currency A currency code.
	 * @return int
	 */
	public function get_currency_symbol_position($currency) {
		static $currency_symbol_positions = array();
		// Cache the currency symbol position, once it has been determined.
		// That information could be fetched hundreds of times per page load, caching
		// it can improve performance.
		// @since 4.9.5.201125
		if(!isset($currency_symbol_positions[$currency])) {
			$currency_symbol_positions[$currency] = $this->get_currency_setting($currency, 'symbol_position', $this->woocommerce_currency_position);
		}

		return $currency_symbol_positions[$currency];
	}

	/**
	 * Returns the thousand separator for a given currency.
	 *
	 * @param string currency A currency code.
	 * @return string
	 */
	public function get_currency_thousand_separator($currency) {
		static $thousand_separators = array();
		// Cache the thousands separator, once it has been determined.
		// That information could be fetched hundreds of times per page load, caching
		// it can improve performance.
		// @since 4.9.5.201125
		if(!isset($thousand_separators[$currency])) {
			$thousand_separators[$currency] = $this->get_currency_setting($currency, 'thousand_separator', $this->woocommerce_price_thousand_sep);
		}

		return $thousand_separators[$currency];
	}

	/**
	 * Returns the decimal separator for a given currency.
	 *
	 * @param string currency A currency code.
	 * @return string
	 */
	public function get_currency_decimal_separator($currency) {
		static $decimal_separators = array();
		// Cache the decimal separator, once it has been determined.
		// That information could be fetched hundreds of times per page load, caching
		// it can improve performance.
		// @since 4.9.5.201125
		if(!isset($decimal_separators[$currency])) {
			$decimal_separators[$currency] = $this->get_currency_setting($currency, 'decimal_separator', $this->woocommerce_price_decimal_sep);
		}

		return $decimal_separators[$currency];
	}

	/**
	 * Returns the symbol to be used for a specifc currency.
	 *
	 * @param string currency A currency code.
	 * @return array
	 */
	public function get_currency_symbol($currency, $default_symbol = null) {
		static $currency_symbols = array();
		// Return the cached symbol, if set.
		// That information could be fetched hundreds of times per page load, caching
		// it can improve performance.
		// @since 4.9.5.201125
		if(!isset($currency_symbols[$currency])) {
			$exchange_rates = $this->current_settings(self::FIELD_EXCHANGE_RATES);
			$currency_settings = isset($exchange_rates[$currency]) ? $exchange_rates[$currency] : false;

			// If no settings are found for the currency, return the default
			if(!is_array($currency_settings) || empty($currency_settings['symbol'])) {
				$currency_symbols[$currency] = $default_symbol;
			}
			else {
				$currency_symbols[$currency] = $currency_settings['symbol'];
			}
		}

		return $currency_symbols[$currency];
	}

	/**
	 * Returns an the Exchange Rate of a Currency relative to the base currency.
	 *
	 * @param bool include_markup Indicates if the returned exchange rates should
	 * include the markup (if one was specified).
	 * @return mixed A number indicating the Exchange Rate, or false if the currency
	 * is not configured properly.
	 */
	public function get_exchange_rate($currency, $include_markup = true) {
		$exchange_rates = $this->get_exchange_rates($include_markup);
		return isset($exchange_rates[$currency]) ? $exchange_rates[$currency] : false;
	}

	/**
	 * Returns an array containing the Currencies that have been enabled.
	 *
	 * @return array
	 */
	public function get_enabled_currencies() {
		$enabled_currencies = $this->current_settings(self::FIELD_ENABLED_CURRENCIES);
		if(!is_array($enabled_currencies) || empty($enabled_currencies)) {
			// Base currency is always enabled
			$enabled_currencies	= array($this->base_currency());
		}
		return array_unique($enabled_currencies);
	}

	/**
	 * Indicates if a specific Currency is Enabled.
	 *
	 * @param string currency_code The currency code to verify.
	 * @return bool
	 */
	public function is_currency_enabled($currency_code) {
		$enabled_currencies = $this->get_enabled_currencies();
		return in_array($currency_code, $enabled_currencies);
	}

	/**
	 * Indicates if the automatic selection of the Currency based on User's
	 * geographical location is enabled.
	 *
	 * @return bool
	 */
	public function currency_geolocation_enabled() {
		return ($this->current_settings(self::FIELD_IPGEOLOCATION_ENABLED) == 1);
	}

	/**
	 * Callback method, used with uasort() function.
	 * Sorts WordPress Scheduling options by interval (ascending). In case of two
	 * identical intervals, it sorts them by label (comparison is case-insensitive).
	 *
	 * @param array a First Schedule Option.
	 * @param array b Second Schedule Option.
	 * @return int Zero if (a == b), -1 if (a < b), 1 if (a > b).
	 *
	 * @see uasort().
	 */
	public function sort_schedules($a, $b) {
		if($a['interval'] == $b['interval']) {
			return strcasecmp($a['display'], $b['display']);
		}

		return ($a['interval'] < $b['interval']) ? -1 : 1;
	}

	/**
	 * Returns an array of the Currencies supported by WooCommerce.
	 *
	 * @return array An array of Currencies.
	 */
	public function woocommerce_currencies() {
		if(empty($this->_woocommerce_currencies)) {
			$this->_woocommerce_currencies = get_woocommerce_currencies();
		}
		return $this->_woocommerce_currencies;
	}

	/**
	 * Returns an array of the Payment Gateways enabled in WooCommerce. This method
	 * is used in place of standard WC_Payment_Gateways::get_available_payment_gateways()
	 * because the latter fires an apply_filter, which is then intercepted by
	 * the Currency Switcher to remove unavailable gateways depending on the
	 * selected currency. If the standard method were to be used, we would risk
	 * to trigger an infinite loop.
	 *
	 * @return array An array of payment gateways.
	 */
	public function woocommerce_payment_gateways() {
		if(empty($this->_woocommerce_payment_gateways)) {
			$this->_woocommerce_payment_gateways = array();
			foreach(WC()->payment_gateways()->payment_gateways() as $gateway) {
				// Check if the payment gateway is available. If it is, add it to the list of available options
				// on the Payment Gateways settings page.
				//
				// The check for $gateway->is_available() is to support "quirky" payment gateways, such as
				// the PayPal Credit Cards added by the WooCommerce Payments plugin, which doesn't set its
				// "enabled" property to "yes".
				// @since 4.12.5.210819
				if(apply_filters('wc_aelia_cs_settings_payment_gateway_available', ($gateway->enabled == 'yes' || (method_exists($gateway, 'is_available') && $gateway->is_available())), $gateway)) {
					$this->_woocommerce_payment_gateways[$gateway->id] = $gateway;
				}
			}
		}
		return $this->_woocommerce_payment_gateways;
	}

	/**
	 * Returns the payment gateways enabled for a currency.
	 *
	 * @param string currency The currency.
	 * @return array
	 */
	public function currency_payment_gateways($currency) {
		$current_settings = $this->current_settings(self::FIELD_PAYMENT_GATEWAYS);
		return isset($current_settings[$currency]['enabled_gateways']) ? $current_settings[$currency]['enabled_gateways'] : array();
	}

	/**
	 * Returns the description of a Currency.
	 *
	 * @param string currency The currency code.
	 * @return string The Currency description.
	 * @return string The Currency description.
	 */
	public function get_currency_description($currency) {
		$currencies = $this->woocommerce_currencies();

		return isset($currencies[$currency]) ? $currencies[$currency] : false;
	}

	/**
	 * Retrieves the latest Exchange Rates from a remote provider.
	 *
	 * @param array settings Current Plugin settings.
	 * @return array An array of Currency => Exchange Rate pairs.
	 */
	protected function fetch_latest_exchange_rates(array $settings = null) {
		$settings = isset($settings) ? $settings : $this->current_settings();

		$enabled_currencies = isset($settings[self::FIELD_ENABLED_CURRENCIES]) ? $settings[self::FIELD_ENABLED_CURRENCIES] : array();

		$exchange_rates = isset($settings[self::FIELD_EXCHANGE_RATES]) ? $settings[self::FIELD_EXCHANGE_RATES] : array();

		$currencies_to_update = array();
		$current_exchange_rates = array();
		// If a Currency is configured to have its Exchange Rate set manually,
		// remove it from the list of the Currencies for which to retrieve the
		// Exchange Rate
		foreach($enabled_currencies as $currency) {
			if(empty($exchange_rates[$currency]['set_manually'])) {
				$currencies_to_update[] = $currency;
				$current_exchange_rates[$currency] = isset($exchange_rates[$currency]['rate']) ? $exchange_rates[$currency]['rate'] : '';
			}
		}

		if(empty($currencies_to_update)) {
			return array();
		}

		$latest_exchange_rates = $this->exchange_rates_model($settings)->get_exchange_rates($this->base_currency(),
																																												$currencies_to_update);
		$result = array_merge($current_exchange_rates, $latest_exchange_rates);

		return $result;
	}

	/**
	 * Updates a list of Exchange Rates settings by replacing the rates with new
	 * ones passed as a parameter.
	 *
	 * @param array exchange_rates The list of Exchange Rate settings to be updated.
	 * @param array new_exchange_rates The new Exchange Rates.
	 * @return array The updated Exchange Rate settings.
	 */
	protected function set_exchange_rates($exchange_rates, array $new_exchange_rates) {
		$exchange_rates = empty($exchange_rates) ? array() : $exchange_rates;

		foreach($new_exchange_rates as $currency => $rate) {
			// Base currency has a fixed exchange rate of 1 (it doesn't need to be
			// converted)
			if($currency == $this->base_currency()) {
				$exchange_rates[$currency]['rate'] = 1;
				continue;
			}

			$currency_settings = isset($exchange_rates[$currency]) ? $exchange_rates[$currency] : $this->default_currency_settings();

			// Update the exchange rate unless the currency is set to "set manually"
			// to prevent automatic updates
			if(empty($currency_settings['set_manually'])) {
				$currency_settings['rate'] = $rate;
			}
			$exchange_rates[$currency] = $currency_settings;
		}
		return $exchange_rates;
	}

	/**
	 * Updates the Plugin Settings received as an argument with the latest Exchange
	 * Rates, adding a settings error if the operation fails.
	 *
	 * @param array settings Current Plugin settings.
	 */
	public function update_exchange_rates(array &$settings, &$errors = array()) {
		$latest_exchange_rates = $this->fetch_latest_exchange_rates($settings);
		$exchange_rates_model_errors = $this->exchange_rates_model()->get_errors();

		if(($latest_exchange_rates === null) ||
			 !empty($exchange_rates_model_errors)) {
			$result = empty($exchange_rates_model_errors);

			foreach($exchange_rates_model_errors as $code => $message) {
				$errors['exchange-rates-error-' . $code] = $message;
			}
		}
		else {
			$exchange_rates = isset($settings[self::FIELD_EXCHANGE_RATES]) ? $settings[self::FIELD_EXCHANGE_RATES] : array();

			// Update the exchange rates and add them to the settings to be saved
			$settings[self::FIELD_EXCHANGE_RATES] = $this->set_exchange_rates($exchange_rates, $latest_exchange_rates);

			$result = true;
		}

		/* Invalidate product cache when exchange rates are updated. This is
		 * necessary to ensure that the product prices are recalculated using the
		 * new rates.
		 * @since 4.2.6.150911
		 * @since WC 2.4
		 */
		WC_Aelia_CurrencySwitcher::clear_products_cache();

		do_action('wc_aelia_cs_exchange_rates_updated');
		return $result;
	}

	/**
	 * Validates the settings specified via the Options page.
	 *
	 * @param array settings An array of settings.
	 */
	public function validate_settings($settings) {
		// Tweak, to be reviewed
		// WordPress seems to trigger the validation multiple times under some
		// circumstances. This trick will avoid re-validating the data that was
		// already processed earlier
		if(!empty($settings['validation_complete'])) {
			return $settings;
		}

		$processed_settings = $this->current_settings();
		$woocommerce_currency = $this->base_currency();
		$enabled_currencies = isset($settings[self::FIELD_ENABLED_CURRENCIES]) ? $settings[self::FIELD_ENABLED_CURRENCIES] : array();
		$new_enabled_currencies = isset($processed_settings[self::FIELD_ENABLED_CURRENCIES]) ? $processed_settings[self::FIELD_ENABLED_CURRENCIES] : array();

		// Retrieve the new currencies eventually added to the "enabled" list
		$currencies_diff = array_diff($enabled_currencies, $new_enabled_currencies);

		// Validate Exchange Rates Provider settings
		$exchange_rates_provider_ok = $this->_validate_exchange_rates_provider_settings($settings);
		if($exchange_rates_provider_ok === true) {
			// Save Exchange Rates providers settings
			$processed_settings[self::FIELD_EXCHANGE_RATES_PROVIDER] = isset($settings[self::FIELD_EXCHANGE_RATES_PROVIDER]) ? $settings[self::FIELD_EXCHANGE_RATES_PROVIDER] : false;
			$processed_settings[self::FIELD_OPENEXCHANGE_API_KEY] = isset($settings[self::FIELD_OPENEXCHANGE_API_KEY]) ? trim($settings[self::FIELD_OPENEXCHANGE_API_KEY]) : false;
		}

		$this->set_exchange_rates_update_schedule($processed_settings, $settings);

		// Validate enabled currencies
		if($this->_validate_enabled_currencies($enabled_currencies) === true) {
			$processed_settings[self::FIELD_ENABLED_CURRENCIES] = $enabled_currencies;

			// Validate Exchange Rates
			$exchange_rates = isset($settings[self::FIELD_EXCHANGE_RATES]) ? $settings[self::FIELD_EXCHANGE_RATES] : array();

			if($this->_validate_exchange_rates($exchange_rates) === true) {
				$processed_settings[self::FIELD_EXCHANGE_RATES] = $exchange_rates;
			}

			// We can update exchange rates only if an exchange rates provider has been
			// configured correctly
			if($exchange_rates_provider_ok === true) {
				// Update Exchange Rates in three cases:
				// - If none is present
				// - If one or more new currencies have been enabled
				// - If button "Save and update Exchange Rates" has been clicked
				if(empty($processed_settings[self::FIELD_EXCHANGE_RATES]) ||
					 !empty($currencies_diff) ||
					 (!empty($_POST['wc_aelia_currency_switcher']['update_exchange_rates_button']))) {
					if($this->update_exchange_rates($processed_settings, $errors) === true) {
						// This is not an "error", but a confirmation message. Unfortunately,
						// WordPress only has "add_settings_error" to add messages of any type
						add_settings_error(self::SETTINGS_KEY,
										 'exchange-rates-updated',
										 __('Settings saved. Exchange Rates have been updated.', $this->textdomain),
										 'updated');
						// Save the timestamp of last update in GMT
						// @since 4.9.6.201207
						$processed_settings[self::FIELD_EXCHANGE_RATES_LAST_UPDATE] = time();
					}
					else {
						$this->add_multiple_settings_errors($errors);
					}
				}
			}
		}

		// Validate enabled payment gateways for each currency
		$enabled_payment_gateways = isset($settings[self::FIELD_PAYMENT_GATEWAYS]) ? $settings[self::FIELD_PAYMENT_GATEWAYS] : array();

		if($this->_validate_payment_gateways($enabled_currencies, $enabled_payment_gateways) === true) {
			$processed_settings[self::FIELD_PAYMENT_GATEWAYS] = $enabled_payment_gateways;
		}

		// Save Exchange Rates Auto-update settings
		$processed_settings[self::FIELD_EXCHANGE_RATES_UPDATE_ENABLE] = isset($settings[self::FIELD_EXCHANGE_RATES_UPDATE_ENABLE]) ? $settings[self::FIELD_EXCHANGE_RATES_UPDATE_ENABLE] : false;
		$processed_settings[self::FIELD_EXCHANGE_RATES_UPDATE_SCHEDULE] = isset($settings[self::FIELD_EXCHANGE_RATES_UPDATE_SCHEDULE]) ? $settings[self::FIELD_EXCHANGE_RATES_UPDATE_SCHEDULE] : false;

		// Save IP Geolocation Settings
		$processed_settings[self::FIELD_IPGEOLOCATION_ENABLED] = isset($settings[self::FIELD_IPGEOLOCATION_ENABLED]) ? $settings[self::FIELD_IPGEOLOCATION_ENABLED] : false;
		$processed_settings[self::FIELD_IPGEOLOCATION_DEFAULT_CURRENCY] = isset($settings[self::FIELD_IPGEOLOCATION_DEFAULT_CURRENCY]) ? $settings[self::FIELD_IPGEOLOCATION_DEFAULT_CURRENCY] : $woocommerce_currency;

		// "Force currency by country" settings
		$processed_settings[self::FIELD_FORCE_CURRENCY_BY_COUNTRY] = isset($settings[self::FIELD_FORCE_CURRENCY_BY_COUNTRY]) ? $settings[self::FIELD_FORCE_CURRENCY_BY_COUNTRY] : 0;
		$processed_settings[self::FIELD_CURRENCY_VIA_URL_ENABLED] = isset($settings[self::FIELD_CURRENCY_VIA_URL_ENABLED]) ? $settings[self::FIELD_CURRENCY_VIA_URL_ENABLED] : 0;

		// Debug settings
		$processed_settings[self::FIELD_DEBUG_MODE_ENABLED] = isset($settings[self::FIELD_DEBUG_MODE_ENABLED]) ? $settings[self::FIELD_DEBUG_MODE_ENABLED] : 0;

		// Currency/Country mappings
		// @since 4.13.0.220104
		$processed_settings[self::FIELD_CURRENCY_COUNTRIES_MAPPINGS] = $settings[self::FIELD_CURRENCY_COUNTRIES_MAPPINGS] ?? [];

		$processed_settings['validation_complete'] = true;

		/* Invalidate product cache, to ensure that the product prices are
		 * recalculated using the new settings.
		 * @since 4.2.8.150917
		 * @since WC 2.4
		 */
		WC_Aelia_CurrencySwitcher::clear_products_cache();

		// Return the array processing any additional functions filtered by this action.
		return apply_filters('wc_aelia_currencyswitcher_validate_settings', $processed_settings, $settings);
	}

	/**
	 * Stores the currency settings used by default in WooCommerce. This will allow
	 * the plugin to retrieve them after its override filters will be enabled.
	 *
	 * @since 4.0.1.150318
	 */
	protected function store_default_currency_settings() {
		$this->woocommerce_currency_decimals = (int)get_option('woocommerce_price_num_decimals');
		$this->woocommerce_currency_position = (int)get_option('woocommerce_currency_pos');
		$this->woocommerce_price_thousand_sep = wp_specialchars_decode(stripslashes(get_option('woocommerce_price_thousand_sep')), ENT_QUOTES);
		$this->woocommerce_price_decimal_sep = wp_specialchars_decode(stripslashes(get_option('woocommerce_price_decimal_sep')), ENT_QUOTES);
	}

	/**
	 * Class constructor.
	 *
	 * @param string settings_key The key used to store and retrieve the plugin settings.
	 * @param string textdomain The text domain used for localisation.
	 * @param string renderer The renderer to use to generate the settings page.
	 * @return Aelia\WC\Settings
	 */
	public function __construct($settings_key, $textdomain, Settings_Renderer $renderer) {
		parent::__construct($settings_key, $textdomain, $renderer);

		$this->store_default_currency_settings();

		add_action('admin_init', array($this, 'init_settings'));

		// If no settings are registered, save the default ones
		if($this->load() === null) {
			$this->save();
		}
	}

	/**
	 * Updates the Exchange Rates. Triggered by a Scheduled Task.
	 */
	public function scheduled_update_exchange_rates() {
		$settings = $this->current_settings();
		if($this->update_exchange_rates($settings) === true) {
			// Save the timestamp of last update in GMT
			// @since 4.9.6.201207
			$settings[self::FIELD_EXCHANGE_RATES_LAST_UPDATE] = time();
		}

		$this->save($settings);
	}

	/*** Validation methods ***/
	/**
	 * Validates a list of enabled currencies.
	 *
	 * @param array A list of currencies.
	 * @return bool True, if the validation succeeds, False otherwise.
	 */
	protected function _validate_enabled_currencies(&$enabled_currencies) {
		$woocommerce_currency = $this->base_currency();
		if(empty($enabled_currencies)) {
			$enabled_currencies = array();
		}

		// WooCommerce Base Currency must be enabled, therefore it's forcibly added
		// to the list
		if(array_search($woocommerce_currency, $enabled_currencies) === false) {
			$enabled_currencies[] = $woocommerce_currency;
		}
		return true;
	}

	/**
	 * Validates a list of Exchange Rates.
	 *
	 * @param array A list of Exchange Rates.
	 * @return bool True, if the validation succeeds, False otherwise.
	 */
	protected function _validate_exchange_rates(&$exchange_rates) {
		$result = true;
		foreach($exchange_rates as $currency => $settings) {
			// Trim the exchange rate, if present. This is to remove whitespace entered accidentally,
			// which could cause the rate to be considered invalid
			// @since 4.8.13.200617
			$exchange_rate = isset($settings['rate']) ? trim($settings['rate']) : false;
			$exchange_rates[$currency]['rate'] = $exchange_rate;

			if(!empty($settings['set_manually']) &&
				!is_numeric($exchange_rate)) {
				add_settings_error(self::SETTINGS_KEY,
													 'invalid-rate',
													 sprintf(__('You chose to manually set the exchange rate for currency %s, ' .
																			'but the specified rate "%s" is not valid.',
																			$this->textdomain),
																 $currency,
																 $exchange_rate));
				$result = false;
			}
		}
		return $result;
	}

	/**
	 * Validates settings for the selected Exchange Rates provider.
	 *
	 * @param array settings An array of settings.
	 * @return bool
	 */
	protected function _validate_exchange_rates_provider_settings($settings) {
		// Validate settings for Open Exchange Rates
		$selected_provider = isset($settings[self::FIELD_EXCHANGE_RATES_PROVIDER]) ? $settings[self::FIELD_EXCHANGE_RATES_PROVIDER] : false;

		if(empty($selected_provider)) {
			return false;
		}

		if($selected_provider == $this->get_exchange_rates_model_key('Aelia\WC\CurrencySwitcher\Exchange_Rates_OpenExchangeRates_Model')) {
			return $this->_validate_openexchangerates_settings($settings);
		}
		return true;
	}

	/**
	 * Validates settings provided for Open Exchange Rates.
	 *
	 * @param array settings An array of settings.
	 * @return bool
	 */
	protected function _validate_openexchangerates_settings($settings) {
		$api_key = trim($settings[self::FIELD_OPENEXCHANGE_API_KEY] ?? '');
		if(empty($api_key)) {
			add_settings_error(self::SETTINGS_KEY,
								 'invalid-openexchangerates-api-key',
								 __('You must specify an API Key to use Open Exchange Rates service.',
										$this->textdomain));
			return false;
		}

		return true;
	}

	/**
	 * Verifies that there is at leasto one payment gateway enabled for each currency, showing
	 * an error if that's not the case.
	 *
	 * @param array $enabled_currencies
	 * @param array $enabled_payment_gateways
	 * @return bool
	 */
	protected function _validate_payment_gateways($enabled_currencies, $enabled_payment_gateways): bool {
		$result = true;

		$available_payment_gateways_ids = array_keys($this->woocommerce_payment_gateways());
		foreach($enabled_currencies as $currency) {
			$currency_gateways = $enabled_payment_gateways[$currency]['enabled_gateways'] ?? [];

			if(empty($currency_gateways)) {
				add_settings_error(self::SETTINGS_KEY,
													 'no-payment-gateways-for-currency',
													 sprintf(__('You have to enable at least one payment gateway for ' .
																			'currency "%s".',
																			$this->textdomain),
																 $currency));
				$result = false;
				continue;
			}

			// Check that all payment gateways exist amongst the enabled ones
			$invalid_gateways = array();
			foreach($currency_gateways as $gateway_id) {
				if(!in_array($gateway_id, $available_payment_gateways_ids)) {
					$invalid_gateways[] = $gateway_id;
				}
			}
			if(!empty($invalid_gateways)) {
				add_settings_error(self::SETTINGS_KEY,
													 'invalid-payment-gateways-for-currency',
													 sprintf(__('The following payment gateways, selected for currency ' .
																			'"%s", are not valid: %s',
																			$this->textdomain),
																 $currency,
																 implode(', ', $invalid_gateways)));
				$result = false;
			}
		}
		return $result;
	}

	/**
	 * Configures the schedule to automatically update the Exchange Rates.
	 *
	 * @param array current_settings An array containing current plugin settings.
	 * @param array new_settings An array containing new plugin settings.
	 */
	protected function set_exchange_rates_update_schedule(array $current_settings, array $new_settings) {
		// Clear Exchange Rates Update schedule, if it was disabled
		$exchange_rates_schedule_enabled = $new_settings[self::FIELD_EXCHANGE_RATES_UPDATE_ENABLE] ?? 0;
		if($exchange_rates_schedule_enabled != self::ENABLED_YES) {
			wp_clear_scheduled_hook($this->_exchange_rates_update_hook);
		}
		else {
			// If Exchange Rates Update is still scheduled, check if its schedule changed.
			// If it changed, remove old schedule and set a new one.
			$new_exchange_rates_update_schedule = $new_settings[self::FIELD_EXCHANGE_RATES_UPDATE_SCHEDULE] ?? null;
			if((($current_settings[self::FIELD_EXCHANGE_RATES_UPDATE_SCHEDULE] ?? null) != $new_exchange_rates_update_schedule) ||
				 (($current_settings[self::FIELD_EXCHANGE_RATES_UPDATE_ENABLE] ?? 0) != $exchange_rates_schedule_enabled)) {
				wp_clear_scheduled_hook($this->_exchange_rates_update_hook);
				// Schedule the next update, using current GMT timestamp as a starting point
				// @since 4.9.6.201207
				wp_schedule_event(time(), $new_exchange_rates_update_schedule, $this->_exchange_rates_update_hook);
			}
		}
	}

/**
	 * Returns information about the schedule of the automatic updates of exchange
	 * rates.
	 *
	 * @return array An array with the next and last update of the exchange rates.
	 * @since 4.12.4.210805
	 */
	public function get_exchange_rates_schedule_info(): array {
		// Retrieve the timestamp of next scheduled exchange rates update
		if(wp_get_schedule($this->_exchange_rates_update_hook) === false) {
			$next_update_schedule = __('Not Scheduled', $this->textdomain);
		}
		else {
			// Format the "next update" timestamp after converting it from GMT to site's time zone
			// @since 4.9.6.201207
			$next_update_schedule = date_i18n(get_datetime_format(), strtotime(get_date_from_gmt(date('Y-m-d H:i:s', wp_next_scheduled($this->_exchange_rates_update_hook)))));
		}

		// Retrieve the timestamp of last update
		if(($last_update_timestamp = $this->current_settings(self::FIELD_EXCHANGE_RATES_LAST_UPDATE)) != null) {
			// Format the "last update" timestamp after converting it from GMT to site's time zone
			// @since 4.9.6.201207
			$last_update_timestamp_fmt = date_i18n(get_datetime_format(), strtotime(get_date_from_gmt(date('Y-m-d H:i:s', $last_update_timestamp))));
		}
		else {
			$last_update_timestamp_fmt = __('Never updated', $this->textdomain);
		}

		return [
			'next_update' => $next_update_schedule,
			'last_update' => $last_update_timestamp_fmt,
		];
	}

	/**
	 * Returns the amount of decimals to be used for a specific currency.
	 *
	 * @param string currency A currency code.
	 * @return int
	 */
	public function price_decimals($currency = null) {
		$currency = empty($currency) ? $this->base_currency() : $currency;

		// If the number of decimals is not numeric, fetch a numeric value for it
		// @since 4.12.4.210805
		if(!is_numeric($this->_prices_decimals[$currency] ?? false)) {
			$this->_prices_decimals[$currency] = $this->get_currency_decimals($currency);
		}

		return $this->_prices_decimals[$currency];
	}
}
