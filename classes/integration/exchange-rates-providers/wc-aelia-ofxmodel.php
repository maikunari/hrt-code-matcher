<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use \Exception;

/**
 * Retrieves the Exchange Rates from OFX.
 *
 * @link https://www.ofx.com/en-gb/
 * @since 4.5.13.180118
 */
class WC_Aelia_OFXModel extends \Aelia\WC\ExchangeRatesModel {
	// @var string The provider ID
	public static $id = 'ofx';

	// @var string The base currency used to retrieve the exchange rates.
	protected $_base_currency;

	// @var string The URL template to use to query OFX
	protected $ofx_url = 'https://api.ofx.com/PublicSite.ApiService/OFX/spotrate/Individual/%1$s/%2$s/1?format=json';

	protected function enabled_currencies() {
		return apply_filters('wc_aelia_cs_enabled_currencies', array(get_option('woocommerce_currency')));
	}

	/**
	 * Tranforms the exchange rates received from OFX into an array of
	 * currency code => exchange rate pairs.
	 *
	 * @param string ofx The JSON received from the remote service.
	 * @return array
	 */
	protected function decode_rates($ofx_rates) {
		$exchange_rates = array();

		foreach($ofx_rates as $currency => $rate) {
			if(!is_object($rate) || !isset($rate->InterbankRate)) {
				continue;
			}

			$exchange_rates[$currency] = (float)$rate->InterbankRate;
		}
		// Set the exchange rate for the base currency to 1
		$exchange_rates[$this->_base_currency] = 1;
		return $exchange_rates;
	}

	/**
	 * Fetches all exchange rates from OFX service.
	 *
	 * @return object|bool An object containing the response from Open Exchange, or
	 * False in case of failure.
	 */
	private function fetch_all_rates() {
		$rates = array();

		foreach($this->enabled_currencies() as $currency) {
			// No need to retrieve the exchange rate for the base currency, it's always 1
			if($currency === $this->_base_currency) {
				continue;
			}

			$query_url = sprintf($this->ofx_url, $this->_base_currency, $currency);
			try {
				// Fetch the exchange rates using WP functions
				// @since 5.2.6.250212
				// @link https://bitbucket.org/businessdad/woocommerce-currency-switcher/issues/66/
				$response = wp_remote_get(esc_url_raw(apply_filters('wc_aelia_cs_' . static::$id . '_fetch_rates_request_url', $query_url)));

				if(is_wp_error($response)) {
					$this->add_error(self::ERR_ERROR_RETURNED,
													 sprintf(__('Error returned by OFX. Error code: %1$s. Error message: %2$s.', Definitions::TEXT_DOMAIN),
																	 $response->get_error_code(),
																	 $response->get_error_message())
					);
					return false;
				}

				// Convert the response from JSON to an object and store it into the result. This
				// must be done for one currency at a time, it's how OFX works
				// @since 5.2.6.250212
				$rates[$currency] = json_decode($response['body']);
			}
			catch(Exception $e) {
				$this->add_error(self::ERR_EXCEPTION_OCCURRED,
												 sprintf(__('Exception occurred while retrieving the exchange rates from OFX. ' .
																		'Error message: %s.',
																		Definitions::TEXT_DOMAIN),
																 $e->getMessage()));
				continue;
			}
		}
		return $rates;
	}

	/**
	 * Returns current exchange rates for the specified currency.
	 *
	 * @param string base_currency The base currency.
	 * @return array An array of Currency => Exchange Rate pairs.
	 */
	private function current_rates($base_currency) {
		if(empty($this->_current_rates) ||
			 $this->_base_currency != $base_currency) {

			// Set the base currency for which to retrieve the exchange rates
			$this->_base_currency = $base_currency;

			// Fetch exchange rates
			$ofx_rates = $this->fetch_all_rates();

			if(empty($ofx_rates)) {
				return null;
			}

			// OFX rates are returned as JSON representation of an array of objects.
			// We need to transform it into an array of currency => rate pairs
			$exchange_rates = $this->decode_rates($ofx_rates);

			if(!is_array($exchange_rates)) {
				$this->add_error(self::ERR_UNEXPECTED_ERROR_FETCHING_EXCHANGE_RATES,
												 __('An unexpected error occurred while fetching exchange rates ' .
														'from OFX. The most common cause of this issue is the ' .
														'absence of PHP CURL extension. Please make sure that ' .
														'PHP CURL is installed and configured in your system.',
														Definitions::TEXT_DOMAIN));
				return array();
			}

			$this->_current_rates = $exchange_rates;
		}
		return $this->_current_rates;
	}

	/**
	 * Returns the exchange rate of a currency in respect to a base currency.
	 *
	 * @param string base_currency The code of the base currency.
	 * @param string currency The code of the currency for which to find the
	 * Exchange Rate.
	 * @return float
	 */
	protected function get_rate($base_currency, $currency) {
		$current_rates = $this->current_rates($base_currency);
		return $current_rates[$currency] ?? false;
	}
}
