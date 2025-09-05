<?php
namespace Aelia\WC\CurrencySwitcher\Currencies;

use Aelia\WC\CurrencySwitcher\WC_Aelia_Currencies_Manager;

if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

/**
 * Handles the flags linked to the various currencies.
 *
 * @since 4.12.0.210628
 */
class Country_Flags extends \Aelia\WC\Countries\Country_Flags {
	/**
	 * Returns a list of the flag for a list of currencies.
	 *
	 * @param array $filter_currencies If not empty, only the flags for the specified currencies will be returned.
	 * @return array
	 */
	public static function get_currency_flags(array $filter_currencies = []): array {
		// Fetch a list with country => currency association, then flip it to have
		// the currency as the key
		//
		// IMPORTANT
		// The list of countries and currencies must be the default one, disregarding any
		// custom currency/country mapping. This is necessary to prevent issues like country
		// codes being "lost" because they are linked to a currency code that appears more than
		// once in the array, and is discarded by the array_flip() call.
		// @since 4.13.9.220519
		// @link https://bitbucket.org/businessdad/woocommerce-currency-switcher/issues/17/bug-a-custom-currency-country-mapping
		$currencies = array_flip(WC_Aelia_Currencies_Manager::get_countries_currencies(true));

		$currency_flags = apply_filters('wc_aelia_cs_currency_country_map', array_merge($currencies, [
			// Set a default flag for currencies used in multiple countries (currency unions)
			// @link https://en.wikipedia.org/wiki/Currency_union
			'EUR' => 'EU',
			'USD' => 'US',
			'GBP' => 'GB',
			'HKD' => 'HK',
			'SGD' => 'SG',
			'AUD' => 'AU',
			'INR' => 'IN',
			'NZD' => 'NZ',
			'ILS' => 'IL',
			'RUB' => 'RU',
			'ZAR' => 'ZA',
			'CHF' => 'CH',
			'TRY' => 'TR',
			'JOD' => 'JO',
			'NOK' => 'NO',
			'DKK' => 'DK',

			// East Caribbean Dollar - Use Saint Lucia's flag (it's the biggest island)
			'XCD' => 'LC',
			// Netherlands Antillean Gilder - Use Sint Maarten's flag
			'ANG' => 'SX',
			// West African CFA Franc - Use Senegal's flag
			'XOF' => 'SN',
			// Central African CFA Franc - Use Cameroons's flag
			'XAF' => 'CM',
			// Moroccan Dirham - Use Morocco's flag
			'MAD' => 'MA',
			// CFP franc (French Overseas Collectivities) - Use French Polynesia's flag
			'XPF' => 'PF',
		]));

		// If a filter was passed, only keep the currencies from the filter list
		if(!empty($filter_currencies)) {
			$currency_flags = array_filter($currency_flags, function($currency_code) use ($filter_currencies) {
				return in_array($currency_code, $filter_currencies);
			}, ARRAY_FILTER_USE_KEY);
		}

		// Fetch the base URL for the currency icons
		$images_path = self::get_images_path();
		$images_url = self::get_images_url();

		// Prepare the list of currency URLs
		$currency_flags = array_map(function($country_code) use ($images_url, $images_path) {
			// If there isn't an image for the specified country code, return a default image
			if(!file_exists("{$images_path}/{$country_code}.svg")) {
				$country_code = 'UNAVAILABLE';
			}

			// Allow 3rd parties to replace the URL to a country flag
			return apply_filters('wc_aelia_country_flag_url', "{$images_url}/{$country_code}.svg", $country_code);
		}, $currency_flags);

		return apply_filters('wc_aelia_currency_flags_urls', $currency_flags);
	}
}
