<?php
namespace Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Traits;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher;
use WC_Abstract_Order;

/**
* Implements some methods to convert the amounts of items linked to orders and
* refunds to the shop's base curreecy. That information is used to generate
* sales reports and analytics.
*
* IMPORTANT
* This trait MUST be used only to extend an instance of a Database_Updater_Task.
* It may not work properly if used within other classes, and even throw a fatal error.
*
* @since 4.16.0.230623
* @see Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Database_Updater_Task
*/
trait Trait_Order_Items_Currency_Conversion {
	/**
	 * Indicates if an order/refund should be skipped.
	 *
	 * @param WC_Order $order
	 * @return boolean
	 */
	protected function should_skip_order($order): bool {
		// Skip invalid orders
		if(!$order instanceof \WC_Abstract_Order) {
			$this->get_logger()->warning(__('Invalid Order ID found.', Definitions::TEXT_DOMAIN), [
				'Order ID' => $order->get_id(),
				'Object Class' => get_class($order),
			]);
			return true;
		}

		// If the order currency is empty, for whatever reason, no conversion can be performed.
		// It's not possible to assume that a specific currency was used, there is no "correct"
		// default
		if(empty($order->get_currency())) {
			$this->get_logger()->warning(implode(' ', [
				__('Found order/refund without a valid currency, could not calculate its amounts in the shop base currency.', Definitions::TEXT_DOMAIN),
				__('This may lead to imprecise results in the reports.', Definitions::TEXT_DOMAIN),
			]), [
				'Object ID' => $order->get_id(),
				'Object Type' => is_callable([$order, 'get_type']) ? $order->get_type() : __('Unknown', Definitions::TEXT_DOMAIN),
			]);

			return true;
		}

		return false;
	}

	/**
	 * Given an order, or a refund, this method performs the following operations:
	 * - Calculates and stores the order totals in the shop's base currency.
	 * - Calculates and stores the amounts for the order item in the shop's base currency.
	 * - Stores the exchange rates to convert an amount from the order currency to the shop's base currency.
	 *
	 * NOTE
	 * This method is also used to process refunds. Refunds share most properties with orders, and can be
	 * handled the same way. For simplicity, both orders and refunds referred to as "order" in the method.
	 *
	 * @param WC_Abstract_Order $order An order entity. It can be a WC_Order, or a WC_Order_Refund instance.
	 * @param string $base_currency The base currency to which the order and order item amounts should be converted.
	 * @param array $meta_keys_to_properties A map of meta key -> property name, to be used to convert the
	 * properties of an order.
	 * @return void
	 * @see WC_Order
	 * @see WC_Order_Refund
	 */
	protected function calculate_amounts_for_order(WC_Abstract_Order $order, string $base_currency, array $meta_keys_to_properties = []): void {
		if(empty($meta_keys_to_properties)) {
			$meta_keys_to_properties = [
				// Orders and refunds
				'_order_total' => 'total',
				'_cart_discount' => 'discount_total',
				'_cart_discount_tax' => 'discount_tax',
				'_order_tax' => 'cart_tax',
				'_order_shipping' => 'shipping_total',
				'_order_shipping_tax' => 'shipping_tax',
				// Refunds
				'_refund_amount' => 'amount',
			];
		}

		// Cache the order currency, to avoid calling the same method multiple times for each order
		$order_currency = $order->get_currency();
		// Cache the order ID, to avoid calling the same method multiple times for each order
		$order_id = $order->get_id();

		$this->get_logger()->notice(__('Calculating amounts in base currency for an an order/refund.', Definitions::TEXT_DOMAIN), [
			'Order ID' => $order->get_id(),
			'Object Type' => $order->get_type(),
		]);


		// Fetch the base currency exchange rate from the order. If it's already stored, we can use it to calculate the value
		// of any missing meta, instead of fetching a rate from today
		// @since 5.1.5.240327
		$base_currency_exchange_rate = apply_filters('wc_aelia_cs_db_updater_order_base_currency_exchange_rate', $order->get_meta('_base_currency_exchange_rate'), $order);

		// If the base currency exchange rate from the order is not valid, calculate the base currency exchange rate using
		// the current rates
		// @since 5.1.5.240327
		if(!is_numeric($base_currency_exchange_rate) || ($base_currency_exchange_rate <= 0)) {
			// Store the exchange rate from the order currency to the base currency. Doing it now will
			// save the time of doing it in another loop
			// @since 4.16.0.230623
			$base_currency_exchange_rate = $this->convert_amount(1, $order_currency, $base_currency);
			$order->update_meta_data('_base_currency_exchange_rate', $base_currency_exchange_rate);
		}

		// Process all the order attributes that need conversion
		foreach($meta_keys_to_properties as $meta_key => $order_property) {
			$method_name = "get_{$order_property}";
			if(!method_exists($order, $method_name)) {
				$this->get_logger()->info(implode(' ', [
					__('Tried to call an invalid method on an order object. Skipping attribute.', Definitions::TEXT_DOMAIN),
				]), [
					'Order ID' => $order_id,
					'Meta Key' => $meta_key,
					'Order Property' => $method_name,
					'Method Name' => $method_name,
				]);
				continue;
			}

			// Fetch the value to convert
			$value_to_convert = $order->$method_name();

			// If the meta value is not numeric, assume that it's zero
			if(!is_numeric($value_to_convert)) {
				$this->get_logger()->warning(__('Invalid, non-numeric value found for order attribute to convert. The value in base currency will be assumed to be zero.', Definitions::TEXT_DOMAIN), [
					'Order ID' => $order_id,
					'Meta Key' => $meta_key,
					'Meta Value' => $value_to_convert,
				]);

				$value_in_base_currency = 0;
			}
			else {
				// Use the base currency exchange rate to perform the conversion. This will spare the
				// time that would be used to call the conversion function provided by the Currency Switcher
				$value_in_base_currency = floatval($value_to_convert) * $base_currency_exchange_rate;

				$this->get_logger()->debug(__('Converted order attribute to the shop base currency.', Definitions::TEXT_DOMAIN), [
					'Order ID' => $order_id,
					'Order Property' => $method_name,
					'Meta Key' => $meta_key,
					'Order Currency' => $order_currency,
					'Original Value' => $value_to_convert,
					'Converted Value' => $value_in_base_currency,
				]);
			}

			$order->update_meta_data("{$meta_key}_base_currency", $value_in_base_currency);
		}

		// Save the meta data to the database
		$order->save_meta_data();

		// Calculate the amounts in base currency for the order items
		// @since 4.16.0.230623
		$this->calculate_amounts_for_order_items($order, $base_currency_exchange_rate);

		$this->get_logger()->notice(__('Order processed successfully.', Definitions::TEXT_DOMAIN), [
			'Order ID' => $order_id,
		]);
	}

	/**
	 * Calculates and stores the amounts in the shop's base currency for order items
	 *
	 * @param \WC_Abstract_Order $order An order or a refund object. The logic can be used
	 * too update items from both orders and refunds.
	 * @param float $base_currency_exchange_rate The exchange rate to be used for the
	 * conversion. It's accepted as an argument to save time, since the ordr already has it.
	 * @return void
	 * @since 4.16.0.230623
	 */
	protected function calculate_amounts_for_order_items(\WC_Abstract_Order $order, float $base_currency_exchange_rate): void {
		// Prepare a list of order item types to process
		$order_item_types = [
			'line_item',
			'product',
			'coupon',
			'fee',
			'shipping',
			'tax',
		];

		// Prepare the list of attributes to convert
		// Imported logic from upgrade to version 3.3.7.140611
		// @see Abstract_WC_Order_Data_Store_CPT::update_post_meta()
		$order_item_meta_keys_to_properties = [
			// Line items (line_item, roducts, fee)
			'_line_subtotal' => 'subtotal',
			'_line_subtotal_tax' => 'subtotal_tax',
			'_line_total' => 'total',
			'_line_tax' => 'total_tax',
			// Tax items
			'tax_amount' => 'tax_total',
			'shipping_tax_amount' => 'shipping_tax_total',
			// Coupons
			// Imported logic from upgrade to version 4.0.6.150604
			'discount_amount' => 'discount',
			'discount_amount_tax' => 'discount_tax',
		];

		$order_id = $order->get_currency();
		$order_currency = $order->get_currency();

		foreach($order->get_items($order_item_types) as $order_item) {
			$this->get_logger()->notice(__('Storing amounts in base currency for order/refund item.', Definitions::TEXT_DOMAIN), [
				'Order ID' => $order_id,
				'Order Item ID' => $order_item->get_id(),
			]);

			// Process all the order attributes that need conversion
			foreach($order_item_meta_keys_to_properties as $meta_key => $order_item_property) {
				$method_name = "get_{$order_item_property}";
				if(method_exists($order_item, $method_name)) {
					// Fetch the value to convert
					$value_to_convert = $order_item->$method_name();

					// If the meta value is not numeric, assume that it's zero
					if(!is_numeric($value_to_convert)) {
						$this->get_logger()->warning(__('Invalid, non-numeric value found for order/refund item attribute to convert. The value in base currency will be assumed to be zero.', Definitions::TEXT_DOMAIN), [
							'Order ID' => $order_id,
							'Order Item ID' => $order_item->get_id(),
							'Meta Key' => $meta_key,
							'Meta Value' => $value_to_convert,
						]);

						$value_in_base_currency = 0;
					}
					else {
						$value_in_base_currency = $value_to_convert * $base_currency_exchange_rate;

						$this->get_logger()->debug(__('Converted order/refund item amount to the shop base currency.', Definitions::TEXT_DOMAIN), [
							'Order ID' => $order_id,
							'Order Item ID' => $order_item->get_id(),
							'Order Item Property' => $method_name,
							'Meta Key' => $meta_key,
							'Order Currency' => $order_currency,
							'Original Value' => $value_to_convert,
							'Converted Value' => $value_in_base_currency,
						]);
					}

					$order_item->update_meta_data("{$meta_key}_base_currency", $value_in_base_currency);
				}
			}

			// Save the meta data to the database
			$order_item->save_meta_data();
		}
	}
}
