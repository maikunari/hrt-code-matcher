<?php
namespace Aelia\WC\CurrencySwitcher\WC32;
if(!defined('ABSPATH')) exit; // Exit if accessed directly

use \WC_Product;
use \WC_Product_Simple;
use \WC_Product_Variation;
use \WC_Product_External;
use \WC_Product_Grouped;
use \WC_Cache_Helper;
use \Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher;
use \Aelia\WC\CurrencySwitcher\Definitions;

interface IWC_Aelia_CurrencyPrices_Manager {
	public function convert_product_prices(WC_Product $product, $currency);
	public function convert_external_product_prices(WC_Product_External $product, $currency);
	public function convert_grouped_product_prices(WC_Product_Grouped $product, $currency);
	public function convert_simple_product_prices(WC_Product $product, $currency);
	public function convert_variable_product_prices(WC_Product $product, $currency);
	public function convert_variation_product_prices(WC_Product_Variation $product, $currency);
}

/**
 * Handles currency conversion for the various product types.
 * Due to its architecture, this class should not be instantiated twice. To get
 * the instance of the class, call WC_Aelia_CurrencyPrices_Manager::Instance().
 *
 * @since WooCommerce 3.2
 * @since 4.5.0.170901
 */
class WC_Aelia_CurrencyPrices_Manager extends \Aelia\WC\CurrencySwitcher\WC27\WC_Aelia_CurrencyPrices_Manager {
	/**
	 * Returns the currency in for the costs of a shipping rate.
	 *
	 * @param WC_Shipping_Rate $shipping_rate
	 * @param string $default
	 * @return string
	 * @since 4.10.0.210312
	 */
	protected function get_shipping_rate_currency(\WC_Shipping_Rate $shipping_rate, string $default): string {
		// If the shpping rate has a currency property, take it as it is
		// @since 4.8.13.200617
		if(!empty($shipping_rate->currency)) {
			return $shipping_rate->currency;
		}

		// As a fallback mechanism, try to extract the currency from the meta
		$shipping_rate_meta = $shipping_rate->get_meta_data();
		return $shipping_rate_meta['currency'] ?? $default;
	}

	/**
	 * Processes shipping rates before they are used by WooCommerce. Used to
	 * convert shipping costs into the selected Currency.
	 *
	 * @param array An array of WC_Shipping_Rate classes.
	 * @return array An array of WC_Shipping_Rate classes, with their costs
	 * converted into Currency.
	 * @since 4.4.21.170830
	 */
	public function woocommerce_package_rates($available_shipping_rates) {
		$selected_currency = $this->get_selected_currency();
		$base_currency = $this->base_currency();

		foreach($available_shipping_rates as $shipping_rate) {
			// Skip invalid rates
			// @since 4.10.0.210312
			if(!$shipping_rate instanceof \WC_Shipping_Rate) {
				continue;
			}

			// Legacy check. 3rd parties can set WC_Shipping_Rate::$shipping_prices_in_currency to true
			// to indicate that the rate's costs are already in the active currency, skipping the conversion
			if(!empty($shipping_rate->shipping_prices_in_currency)) {
				continue;
			}

			// Fetch the shipping rate currency. This will be the currency from which to convert the rate's amounts
			// @since 4.10.0.210312
			$shipping_rate_currency = $this->get_shipping_rate_currency($shipping_rate, $base_currency);

			// If the shipping rate is already in the target currency, leave it as it is
			// @since 4.8.13.200617
			if($shipping_rate_currency === $selected_currency) {
				continue;
			}

			// Convert shipping cost
			$cost = $shipping_rate->get_cost();
			if(!is_array($cost)) {
				// Convert a simple total cost into currency
				$shipping_rate->set_cost($this->currencyswitcher()->convert($cost,
																																		$shipping_rate_currency,
																																		$selected_currency));
			}
			else {
				// Based on documentation, class can contain an array of costs in case
				// of shipping costs applied per item. In such case, each one has to
				// be converted
				foreach($cost as $cost_key => $cost_value) {
					$cost[$cost_key] = $this->currencyswitcher()->convert($cost_value,
																																$shipping_rate_currency,
																																$selected_currency);
				}
				$shipping_rate->set_cost($cost);
			}

			// Convert shipping taxes
			$taxes = $shipping_rate->get_taxes();
			if(!is_array($taxes)) {
				// Convert a simple total taxes into currency
				$shipping_rate->set_taxes($this->currencyswitcher()->convert($taxes,
																																		 $shipping_rate_currency,
																																		 $selected_currency));
			}
			else {
				// Based on documentation, class can contain an array of taxes in case
				// of shipping taxes applied per item. In such case, each one has to
				// be converted
				foreach($taxes as $taxes_key => $taxes_value) {
					$taxes[$taxes_key] = $this->currencyswitcher()->convert($taxes_value,
																																	$shipping_rate_currency,
																																	$selected_currency);
				}
				$shipping_rate->set_taxes($taxes);
			}

			// Set the currency against the shipping rate, to prevent further conversions
			// @since 4.13.10.220604
			$shipping_rate->add_meta_data('currency', $selected_currency);
		}

		return $available_shipping_rates;
	}
}