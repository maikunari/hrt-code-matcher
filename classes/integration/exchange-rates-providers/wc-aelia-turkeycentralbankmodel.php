<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use \Exception;
use WP_Error;

/**
 * Retrieves the Exchange Rates from WebServiceEx.
 *
 * @link http://www.tcmb.gov.tr/kurlar/today.xml
 */
class WC_Aelia_TCBModel extends \Aelia\WC\ExchangeRatesModel {
	// @var string The provider ID
	// @since 4.12.6.210825
	public static $id = 'turkey_central_bank';

	// @var string The base currency used to retrieve the exchange rates.
	protected $_base_currency = 'TRY';

	// @var string The URL template to use to query Turkey Central Bank feed.
	private $tcb_url = 'https://www.tcmb.gov.tr/kurlar/today.xml';

	protected function enabled_currencies() {
		return apply_filters('wc_aelia_cs_enabled_currencies', array(get_option('woocommerce_currency')));
	}

	/**
	 * Tranforms the exchange rates received from Turkey Central Bank into an array of
	 * currency code => exchange rate pairs.
	 *
	 * @param string tcb_rates The XML received from Turkey Central Bank.
	 * @retur array
	 */
	protected function decode_rates($tcb_rates) {
		$exchange_rates = array();

		foreach($tcb_rates->Currency as $rate) {
			// Skip rates with a zero exchange rate
			// @since 4.7.14.191126
			if($rate->ForexSelling == 0) {
				continue;
			}

			$currency = (string)$rate['CurrencyCode'];
			$unit = (int)$rate->Unit;

			/* Turkey Central Bank rates are expressed as "X units of foreign currency
			 * correspond to Y Turkish Liras". We need to get the 1 Turkish Lira = X units
			 * of foreign currency"
			 */
			$exchange_rates[$currency] = 1 / (float)$rate->ForexSelling * $unit;
		}
		// Set the exchange rate for the base currency to 1
		$exchange_rates[$this->_base_currency] = 1;

		return $exchange_rates;
	}

	/**
	 * Fetches all exchange rates from Turkey Central Bank API.
	 *
	 * @return object|bool An object containing the response from Open Exchange, or
	 * False in case of failure.
	 */
	private function fetch_all_rates() {
		try {
			// Fetch the exchange rates using WP functions
			// @since 5.2.6.250212
			// @link https://bitbucket.org/businessdad/woocommerce-currency-switcher/issues/66/
			$response = wp_remote_get(esc_url_raw(apply_filters('wc_aelia_cs_' . static::$id . '_fetch_rates_request_url', $this->tcb_url)));

			if(is_wp_error($response)) {
				$this->add_error(self::ERR_ERROR_RETURNED,
												 sprintf(__('Error returned by Turkey Central Bank. Error code: %1$s. Error message: %2$s.', Definitions::TEXT_DOMAIN),
																 $response->get_error_code(),
																 $response->get_error_message())
				);
				return false;
			}

			// Convert the response to an XML object and return it. The conversion is done with the errors and warnings
			// hidden by default, because the error handling is already covered
			// @since 5.2.6.250212
			return simplexml_load_string($response['body'], 'SimpleXMLElement', apply_filters('wc_aelia_cs_' . static::$id . '_convert_xml_rates_flags', LIBXML_NOERROR | LIBXML_NOWARNING));
		}
		catch(Exception $e) {
			$this->add_error(self::ERR_EXCEPTION_OCCURRED,
											 sprintf(__('Exception occurred while retrieving the exchange rates from Turkey Central Bank. ' .
																	'Error message: %s.',
																	Definitions::TEXT_DOMAIN),
															 $e->getMessage()));
			return false;
		}
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

			// Fetch exchange rates
			$tcb_exchange_rates = $this->fetch_all_rates();
			if($tcb_exchange_rates === false) {
				return null;
			}

			// Turkey Central Bank rates are returned as XML representation of an array of objects.
			// We need to transform it into an array of currency => rate pairs
			$exchange_rates = $this->decode_rates($tcb_exchange_rates);

			if(!is_array($exchange_rates)) {
				$this->add_error(self::ERR_UNEXPECTED_ERROR_FETCHING_EXCHANGE_RATES,
												 __('An unexpected error occurred while fetching exchange rates ' .
														'from Turkey Central Bank. The most common cause of this issue is the ' .
														'absence of PHP CURL extension. Please make sure that ' .
														'PHP CURL is installed and configured in your system.',
														Definitions::TEXT_DOMAIN));
				return array();
			}

			// Since we didn't get the exchange rates related to the base currency,
			// but in the default base currency used by OpenExchange, we need to
			// recalculate them against the base currency we would like to use
			$this->_current_rates = $this->rebase_rates($exchange_rates, $base_currency);
			$this->_base_currency = $base_currency;
		}
		return $this->_current_rates;
	}

	/**
	 * Recaculates the exchange rates using another base currency. This method
	 * is invoked because the rates fetched from Turkey Central Bank are relative
	 * to Turkish Lira but another currency is most likely is used by WooCommerce.
	 *
	 * @param array exchange_rates The exchange rates retrieved from Turkey Central Bank.
	 * @param string base_currency The base currency against which the rates should
	 * be recalculated.
	 * @return array An array of currency => exchange rate pairs.
	 */
	private function rebase_rates(array $exchange_rates, $base_currency) {
		$recalc_rate = $exchange_rates[$base_currency] ?? null;

		if(empty($recalc_rate)) {
			$this->add_error(self::ERR_BASE_CURRENCY_NOT_FOUND,
											 sprintf(__('Could not rebase rates against base currency "%s". ' .
																	'Currency not found in data returned by Turkey Central Bank.',
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
