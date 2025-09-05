<?php
namespace Aelia\WC\CurrencySwitcher\WC36;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use \WC_Product;
use \WC_Product_Variation;
use \WC_Product_External;
use \WC_Product_Grouped;

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
 * @since 4.7.7.190706
 */
class WC_Aelia_CurrencyPrices_Manager extends \Aelia\WC\CurrencySwitcher\WC32\WC_Aelia_CurrencyPrices_Manager {
	/**
	 * Removes the hooks for the conversion of product prices.
	 *
	 * @since 4.7.7.190706
	 */
	protected function remove_product_price_hooks() {
		// Remove filters to convert variation prices
		remove_filter('woocommerce_variation_prices_price', array($this, 'woocommerce_variation_prices_price'), 5, 3);
		remove_filter('woocommerce_variation_prices_regular_price', array($this, 'woocommerce_variation_prices_regular_price'), 5, 3);
		remove_filter('woocommerce_variation_prices_sale_price', array($this, 'woocommerce_variation_prices_sale_price'), 5, 3);

		// Remove filters to convert product prices, based on selected currency
		remove_filter('woocommerce_product_get_price', array($this, 'woocommerce_product_get_price'), 5, 2);
		remove_filter('woocommerce_product_get_regular_price', array($this, 'woocommerce_product_get_regular_price'), 5, 2);
		remove_filter('woocommerce_product_get_sale_price', array($this, 'woocommerce_product_get_sale_price'), 5, 2);

		remove_filter('woocommerce_product_variation_get_price', array($this, 'woocommerce_product_get_price'), 5, 2);
		remove_filter('woocommerce_product_variation_get_regular_price', array($this, 'woocommerce_product_get_regular_price'), 5, 2);
		remove_filter('woocommerce_product_variation_get_sale_price', array($this, 'woocommerce_product_get_sale_price'), 5, 2);

		// WC 2.7+
		remove_filter('woocommerce_product_get_variation_prices_including_taxes', array($this, 'woocommerce_product_get_variation_prices_including_taxes'), 5, 2);
	}

	/**
	 * Sets the hooks required by the class.
	 */
	protected function set_hooks() {
		parent::set_hooks();

		// Since WC 4.3, the patch used to prevent the overwriting of product prices while creating manual
		// order is no longer needed. In fact, it can cause the duplication of order meta
		//
		// @since 4.13.7.220501
		// @link https://aelia.freshdesk.com/a/tickets/96491
		// @link https://bitbucket.org/businessdad/woocommerce-currency-switcher/issues/16
		if(aelia_wc_version_is('<', '4.3.0')) {
			add_action('wp_ajax_woocommerce_add_order_item', array($this, 'wp_ajax_woocommerce_add_order_item'), 1);
		}

		// Filter to allow 3rd parties to fetch a product's base currency
		// @since 4.9.11.210114
		add_filter('wc_aelia_cs_get_product_base_currency', array($this, 'wc_aelia_cs_get_product_base_currency'), 10, 2);
		// Filter to allow 3rd parties to fetch a product's base price in a specific currency
		// @since 4.9.11.210114
		add_filter('wc_aelia_cs_get_product_base_price_in_currency', array($this, 'wc_aelia_cs_get_product_base_price_in_currency'), 10, 4);
	}

	/**
	 * Perform actions when an item is added to an order via Ajax (manual orders).
	 * - Adds hook to intercept the adding of an item to an order. That will allow
	 *   to set the order item's price in the correct currency, without risking to
	 *   save the product prices to the database when the stock is updated
	 *
	 * @since WC 3.6.3
	 * @deprecated 4.13.7.220501
	 */
	public function wp_ajax_woocommerce_add_order_item() {
		// Remove the product conversion hooks before an item is added to a manual
		// order. This is to avoid setting the prices against a product instance that
		// will also be used to update the stock, causing the prices stored in the
		// database to be overwritten
		// @link https://github.com/woocommerce/woocommerce/issues/23952
		$this->remove_product_price_hooks();

		add_action('woocommerce_ajax_add_order_item_meta', array($this, 'before_woocommerce_ajax_add_order_item_meta'), 1, 3);
		add_action('woocommerce_ajax_add_order_item_meta', array($this, 'after_woocommerce_ajax_add_order_item_meta'), 9999, 3);
	}

	/**
	 * Performs actions after an item has been added to an order from the Edit Order
	 * page, to ensure that the item is created with the correct price:
	 * - Enables the product price conversion filters.
	 * - Replaces a newly added item with an item with same ID, but with the correct
	 *   currency prices.
	 *
	 * @param int $item_id
	 * @param WC_Order_Item $item
	 * @param WC_Order $order
	 * @deprecated 4.13.7.220501
	 */
	public function before_woocommerce_ajax_add_order_item_meta($item_id, $item, $order) {
		// Enable the price conversion filters and get a new product instance for the
		// item. This will allow to set the correct currency prices, without affecting the
		// "main" product instance used in WC_AJAX::add_order_item(), which is the one
		// used to update the product's stock
		$this->set_product_price_hooks(true);
		$product = $item->get_product();

		// Replace the item just added with a new item with the same
		// product, but with the prices in order's currency.
		// The new item will also take the same ID as the original one, so that plugins
		// like Bundles can remove it after replacing it with bundled items.
		// @link https://github.com/woocommerce/woocommerce/issues/24089
		$new_item_id = $order->add_product($product, $item->get_quantity(), array(
			'id' => $item_id,
		));
		// Save the order to update the items against the order instance
		$order->save();
	}

	/**
	 * Performs actions after an item has been added to an order from the Edit Order
	 * page, and after other actors had the chance to do their part:
	 * - Removes the product price conversion filters.
	 *
	 * @param int $item_id
	 * @param WC_Order_Item $item
	 * @param WC_Order $order
	 * @deprecated 4.13.7.220501
	 */
	public function after_woocommerce_ajax_add_order_item_meta($item_id, $item, $order) {
		// Remove the price conversion filters, to prevent them from affecting the product
		// instance for the next order item
		$this->remove_product_price_hooks();
	}

	/**
	 * Returns a product's base currency.
	 *
	 * @param string base_currency The original base currency passed to the filter.
	 * @param int product_id
	 * @return string
	 * @since 4.9.11.210114
	 */
	public function wc_aelia_cs_get_product_base_currency($base_currency, $product_id) {
		return $this->get_product_base_currency($product_id);
	}

	/**
	 * Returns the price of a product in the target currency. This method works as follows:
	 * 1. If there is a valid price entered in the target currency, return that price.
	 * 2. If there is a valid price entered in the product's base currency, convert that price from product's
	 *    base currency and return it.
	 * 3. If there is a valid price shop's base currency, convert that price and return it.
	 *
	 * @param \WC_Product $product
	 * @param array $currency_prices
	 * @param string $target_currency
	 * @param string $price_type
	 * @return double | string
	 * @since 4.9.11.210114
	 */
	protected function get_product_base_price_in_currency(\WC_Product $product, array $currency_prices, $target_currency, $price_type) {
		$shop_base_currency = get_option('woocommerce_currency');
		$product_base_currency = $this->get_product_base_currency($product);

		if(!empty($currency_prices[$target_currency]) && is_numeric($currency_prices[$target_currency]) && ($currency_prices[$target_currency] > 0)) {
			// If a valid regular price in the target currency has been set, take it
			$product_base_price_in_currency = $currency_prices[$target_currency];
		}
		else {
			// If a regular price in the target currency is NOT set, try to take the price in product's base currency, and convert it
			if(!empty($currency_prices[$product_base_currency]) && is_numeric($currency_prices[$product_base_currency]) && ($currency_prices[$product_base_currency] > 0)) {
				$source_currency = $product_base_currency;
				$product_price_to_convert = $currency_prices[$product_base_currency];
			}
			else {
				// If both the price in the target currency and product's base currency are not valid, take the price in shop's base currency
				$source_currency = $shop_base_currency;
				$product_price_to_convert = $currency_prices[$shop_base_currency];
			}
			// Convert the product price from product's base currency, or shop base currency, to the target currency
			$product_base_price_in_currency = is_numeric($product_price_to_convert) ? apply_filters('wc_aelia_cs_convert', $product_price_to_convert, $source_currency, $target_currency) : '';

			// Allow 3rd parties to modify the converted price
			// @since 4.11.1.210520
			$product_base_price_in_currency = apply_filters('wc_aelia_cs_converted_product_base_price', $product_base_price_in_currency, $product_price_to_convert, $source_currency, $target_currency, $product, $price_type);
		}
		return $product_base_price_in_currency;
	}

	/**
	 * Returns the base price of a product in the target currency. This is the analogous of calling WC_Product::edit(),
	 * returning the raw currency-specific prices from the database, without any other filters.
	 *
	 * @param \WC_Product $product
	 * @param array $price_type
	 * @param string $target_currency
	 * @return double | string
	 * @since 4.9.11.210114
	 */
	public function wc_aelia_cs_get_product_base_price_in_currency($price, $product, $price_type = 'price', $target_currency = null) {
		if(is_numeric($product)) {
			$product = wc_get_product($product);
		}

		if(!$product instanceof \WC_Product) {
			return '';
		}

		$target_currency = $target_currency ?? get_woocommerce_currency();

		// Determine the regular price
		$regular_prices_key = $product instanceof WC_Product_Variation ? static::FIELD_VARIABLE_REGULAR_CURRENCY_PRICES : static::FIELD_REGULAR_CURRENCY_PRICES;
		$product_base_regular_price = $this->get_product_base_price_in_currency($product, $this->get_product_currency_prices($product, $regular_prices_key), $target_currency, $price_type);

		// Determine the sale price
		$sale_prices_key = $product instanceof WC_Product_Variation ? static::FIELD_VARIABLE_SALE_CURRENCY_PRICES : static::FIELD_SALE_CURRENCY_PRICES;
		$product_base_sale_price = $this->get_product_base_price_in_currency($product, $this->get_product_currency_prices($product, $sale_prices_key), $target_currency, $price_type);

		switch($price_type) {
			case 'regular':
				$result = $product_base_regular_price;
				break;
			case 'sale':
				$result = $product_base_sale_price;
				break;
			case 'price':
			default:
				// If we are in the sale period, and a sale price was specified, take the sale price as the source
				// for the "_price" meta. If not, take the regular price
				if(is_numeric($product_base_sale_price) && ($product_base_sale_price < $product_base_regular_price) && self::is_product_sale_period_active($product)) {
					$product_base_price = $product_base_sale_price;
				}
				else {
					$product_base_price = $product_base_regular_price;
				}
				$result = $product_base_price;
				break;
		}
		return $result;
	}

	/**
	 * Indicates if we're currently within the sale period (date from/to) for a product.
	 *
	 * @param WC_Product $product
	 * @return bool
	 * @since 4.9.11.210114
	 */
	protected static function is_product_sale_period_active(\WC_Product $product) {
		if($product->get_date_on_sale_from() && $product->get_date_on_sale_from()->getTimestamp() > time()) {
			return false;
		}

		if($product->get_date_on_sale_to() && $product->get_date_on_sale_to()->getTimestamp() < time()) {
			return false;
		}
		return true;
	}
}
