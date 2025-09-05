<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use \Exception;
use InvalidArgumentException;
use WpOrg\Requests\Exception\InvalidArgument;

/**
 * Retrieves the Exchange Rates from Open Exchange Rates website.
 *
 * @link https://openexchangerates.org/
 */
class Exchange_Rates_OpenExchangeRates_Model extends \Aelia\WC\ExchangeRatesModel {
	// @var string The provider ID
	// @since 4.12.6.210825
	public static $id = 'open_exchange_rates';

	// @var string The URL template to use to query Open Exchange Rates service
	private $_openexchangerates_url = 'http://openexchangerates.org/api/latest.json?app_id=%s';
	private $_base_currency_arg = '&base=%s';

	/**
	 * The API key used to communicate with the Open Exchange Rates service.
	 *
	 * @var string
	 * @since 5.1.3.240215
	 */
	private $_api_key = '';

	/**
	 * Fetches all Exchange Rates from Open Exchange Rates website.
	 *
	 * @param string base_currency The base currency. It can be left empty to
	 * retrieve exchange rates for Open Exchange default currency (USD as of May
	 * 2013).
	 * @return object|bool An object containing the response from Open Exchange, or
	 * False in case of failure.
	 */
	private function fetch_all_rates($base_currency = null) {
		$url = sprintf($this->_openexchangerates_url, $this->_api_key);
		if(!empty($base_currency)) {
			$url .= sprintf($this->_base_currency_arg, $base_currency);
		}

		try {
			// Fetch the exchange rates using WP functions
			// @since 5.2.6.250212
			// @link https://bitbucket.org/businessdad/woocommerce-currency-switcher/issues/66/
			$response = wp_remote_get(esc_url_raw(apply_filters('wc_aelia_cs_' . static::$id . '_fetch_rates_request_url', $url)));

			if(is_wp_error($response)) {
				$this->add_error(self::ERR_ERROR_RETURNED,
												 sprintf(__('Error returned by Open Exchange Rates. Error code: %1$s. Error message: %2$s.', Definitions::TEXT_DOMAIN),
																 $response->get_error_code(),
																 $response->get_error_message())
				);
				return false;
			}

			// Convert the response from JSON to an object and return it
			// @since 5.2.6.250212
			return json_decode($response['body']);
		}
		catch(Exception $e) {
			$this->add_error(self::ERR_EXCEPTION_OCCURRED,
											 sprintf(__('Exception occurred while retrieving the Exchange Rates from Open Exchange Rates. ' .
																	'Base currency: %s. Error message: %s.',
																	Definitions::TEXT_DOMAIN),
															 $base_currency,
															 $e->getMessage()));
			return null;
		}
	}

	/**
	 * Returns current Exchange Rates for the specified currency.
	 *
	 * @param string base_currency The base currency.
	 * @return array An array of Currency => Exchange Rate pairs.
	 */
	private function current_rates($base_currency) {
		if(empty($this->_current_rates) ||
			 $this->_base_currency != $base_currency) {

			// Fetch exchange rates using USD as the base currency. This will work
			// whether the user holds a full API key or a free one.
			$openexchange_data = $this->fetch_all_rates();

			if($openexchange_data === false) {
				return null;
			}

			$exchange_rates = json_decode(json_encode($openexchange_data->rates), true);

			if(!is_array($exchange_rates)) {
				$this->add_error(self::ERR_UNEXPECTED_ERROR_FETCHING_EXCHANGE_RATES,
												 sprintf(__('An unexpected error occurred while fetching exchange rates ' .
																		'from Open Exchange Rates for base currency %s. The most common ' .
																		'causes of this issue are an invalid API key, or the absence of ' .
																		'PHP CURL extension. Please make sure that API key is correct, and ' .
																		'that PHP CURL is installed and configured in your system.',
																		Definitions::TEXT_DOMAIN),
																 $base_currency));
				return array();
			}

			// Since we didn't get the Exchange Rates related to the base currency,
			// but in the default base currency used by OpenExchange, we need to
			// recalculate them against the base currency we would like to use
			$this->_current_rates = $this->rebase_rates($exchange_rates, $base_currency);
			$this->_base_currency = $base_currency;
		}
		return $this->_current_rates;
	}

	/**
	 * Recaculates the Exchange Rates using another base currency. This method
	 * is invoked when the rates fetched from Open Exchange are relative to their
	 * default base rate, but another one is used by WooCommerce.
	 *
	 * @param array exchange_rates The Exchange Rates retrieved from Open Exchange.
	 * @param string base_currency The base currency against which the rates should
	 * be recalculated.
	 * @return array An array of Currency => Exchange Rate pairs.
	 */
	private function rebase_rates(array $exchange_rates, $base_currency) {
		$recalc_rate = $exchange_rates[$base_currency] ?? null;

		if(empty($recalc_rate)) {
			$this->add_error(self::ERR_BASE_CURRENCY_NOT_FOUND,
											 sprintf(__('Could not rebase rates against base currency "%s". ' .
																	'Currency not found in data returned by Open Exchange.',
																	Definitions::TEXT_DOMAIN),
															 $base_currency));
			return null;
		}

		$result = array();
		foreach($exchange_rates as $currency => $rate) {
			$result[$currency] = $rate / $recalc_rate;
		}

		return $result;
	}

	/**
	 * Returns the Exchange Rate of a Currency in respect to a Base Currency.
	 *
	 * @param string base_currency The code of the Base Currency.
	 * @param string currency The code of the Currency for which to find the
	 * Exchange Rate.
	 * @return
	 */
	protected function get_rate($base_currency, $currency) {
		$current_rates = $this->current_rates($base_currency);

		return $current_rates[$currency] ?? false;
	}

	/**
	 * Class constructor.
	 *
	 * @param array An array of Settings that can be used to override the ones
	 * currently saved in the configuration.
	 * @return Exchange_Rates_OpenExchangeRates_Model.
	 */
	public function __construct($settings = null) {
		parent::__construct($settings);

		// API Key is necessary for the Model to work correctly
		$this->_api_key = $settings[Settings::FIELD_OPENEXCHANGE_API_KEY] ?? WC_Aelia_CurrencySwitcher::settings()->current_settings(Settings::FIELD_OPENEXCHANGE_API_KEY);

		if(empty($this->_api_key)) {
			throw new InvalidArgumentException(__('Open Exchange API Key has not been entered. Service cannot be used without such key. See http://openexchangerates.org/ for more details.',
																						Definitions::TEXT_DOMAIN));
		}
	}
}
