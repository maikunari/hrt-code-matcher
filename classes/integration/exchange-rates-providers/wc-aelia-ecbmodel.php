<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\Definitions;
use \Exception;

/**
 * Retrieves the exchange rates from the European Central Bank.
 *
 * @since 5.0.4.230626
 * @link https://www.ecb.europa.eu/stats/exchange/eurofxref/html/index.en.html
 */
class Aelia_CS_Exchange_Rates_ECB_Model extends \Aelia\WC\ExchangeRatesModel {
	// @var string The URL template to use to query ECB
	protected $ecb_api_rates_url = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

	/**
	 * Stores the rates fetched by the model.
	 *
	 * @var array
	 */
	protected $_current_rates = [];

	/**
	 * Tranforms the exchange rates received from ECB into an array of
	 * currency code => exchange rate pairs.
	 *
	 * @param string ecb_rates The XML received from ECB.
	 * @retur array
	 */
	protected function decode_rates($ecb_rates) {
		$exchange_rates = [];

		foreach($ecb_rates->Cube->Cube->Cube as $rate) {
			$exchange_rates[(string)$rate['currency']] = (float)$rate['rate'];
		}
		// ECB feed is based against EUR, but it doesn't contain such currency. We
		// can safely add it manually, with an exchange rate of 1
		$exchange_rates['EUR'] = 1;
		return $exchange_rates;
	}

	/**
	 * Fetches all exchange rates from ECB API.
	 *
	 * @return object|bool An object containing the response from Open Exchange, or
	 * False in case of failure.
	 */
	protected function fetch_all_rates() {
		try {
			// Fetch the exchange rates using WP functions
			// @since 5.2.6.250212
			// @link https://bitbucket.org/businessdad/woocommerce-currency-switcher/issues/66/
			$response = wp_remote_get(esc_url_raw(apply_filters('wc_aelia_cs_ecb_fetch_rates_request_url', $this->ecb_api_rates_url)));

			if(is_wp_error($response)) {
				$this->add_error(self::ERR_ERROR_RETURNED,
												 sprintf(__('Error returned by Open Exchange Rates. Error code: %1$s. Error message: %2$s.', Definitions::TEXT_DOMAIN),
																 $response->get_error_code(),
																 $response->get_error_message())
				);
				return false;
			}

			// Convert the response to an XML object and return it. The conversion is done with the errors and warnings
			// hidden by default, because the error handling is already covered
			// @since 5.2.6.250212
			return simplexml_load_string($response['body'], 'SimpleXMLElement', apply_filters('wc_aelia_cs_ecb_convert_xml_rates_flags', LIBXML_NOERROR | LIBXML_NOWARNING));
		}
		catch(Exception $e) {
			$this->add_error(self::ERR_EXCEPTION_OCCURRED,
											 esc_html(sprintf(__('Exception occurred while retrieving the exchange rates from ECB. Error message: %s.', Definitions::TEXT_DOMAIN),
											 									$e->getMessage())));
			return false;
		}
	}

	/**
	 * Returns current exchange rates for the specified currency.
	 *
	 * @param string base_currency The base currency.
	 * @return array An array of Currency => Exchange Rate pairs.
	 */
	protected function current_rates($base_currency) {
		if(empty($this->_current_rates) || ($this->_base_currency != $base_currency)) {

			$cache_key = md5(get_class($this)) . $base_currency;
			// Try to get the cached rates for the specified base currency, if any
			$this->_current_rates = $this->get_cached_exchange_rates($cache_key);
			if(!empty($this->_current_rates)) {
				return $this->_current_rates;
			}

			// Fetch exchange rates
			$ecb_exchange_rates = $this->fetch_all_rates();
			if($ecb_exchange_rates === false) {
				return null;
			}

			// ECB rates are returned as JSON representation of an array of objects.
			// We need to transform it into an array of currency => rate pairs
			$exchange_rates = $this->decode_rates($ecb_exchange_rates);
			if(!is_array($exchange_rates)) {
				$this->add_error(self::ERR_UNEXPECTED_ERROR_FETCHING_EXCHANGE_RATES,
												esc_html(implode(' ', [
													__('An unexpected error occurred while fetching exchange rates from the ECB service.', Definitions::TEXT_DOMAIN),
													__('A common cause of this issue is the absence of PHP CURL extension.', Definitions::TEXT_DOMAIN),
													__('Please ensure sure that the PHP CURL extension is installed and configured in your system.', Definitions::TEXT_DOMAIN),
												])));
				$this->_current_rates = [];
			}
			else {
				// Since we didn't get the exchange rates related to the base currency,
				// but in the default base currency used by OpenExchange, we need to
				// recalculate them against the base currency we would like to use
				$this->_current_rates = $this->rebase_rates($exchange_rates, $base_currency);
				$this->_base_currency = $base_currency;

				// Cache the exchange rates
				$this->cache_exchange_rates($cache_key, $this->_current_rates);
			}
		}
		return $this->_current_rates;
	}

	/**
	 * Recaculates the exchange rates using another base currency. This method
	 * is invoked because the rates fetched from ECB are relative to BitCoin,
	 * but another currency is most likely is used by WooCommerce.
	 *
	 * @param array exchange_rates The exchange rates retrieved from ECB.
	 * @param string base_currency The base currency against which the rates should be recalculated.
	 * @return array An array of currency => exchange rate pairs.
	 */
	protected function rebase_rates(array $exchange_rates, $base_currency) {
		$recalc_rate = $exchange_rates[$base_currency] ?? null;

		if(empty($recalc_rate)) {
			$this->add_error(self::ERR_BASE_CURRENCY_NOT_FOUND,
											 esc_html(sprintf(__('Could not rebase rates against base currency "%s". Currency not found in data returned by ECB.', Definitions::TEXT_DOMAIN),
																				$base_currency)));
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
	 * @param string currency The code of the currency for which to find the exchange rate.
	 * @return float
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
	 * @return Exchange_Rates_ECB_Model.
	 */
	public function __construct($settings = null) {
		parent::__construct($settings);
	}
}
