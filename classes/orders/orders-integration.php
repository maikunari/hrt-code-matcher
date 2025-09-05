<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Traits\Logger_Trait;
use Aelia\WC\Traits\Singleton;
use WC_Abstract_Order;
use WC_Data_Store;
use WC_Order;
use WC_Order_Item;
use WC_Order_Refund;

/**
 * Implements functions to handle orders, such as storing and updating meta,
 * converting totals, etc.
 *
 * @since 4.11.0.210517
 */
class Orders_Integration {
	use Singleton;
	use Logger_Trait;

	/**
	 * A list of orders corresponding to item IDs. Used to retrieve the order ID starting from one of the items it contains.
	 *
	 * @var array
	 */
	private $items_orders = [];

	/**
	 * Retrieves the order to which an order item belongs.
	 *
	 * @param int order_item_id The order item.
	 * @return Aelia_Order
	 */
	protected function get_order_from_item($order_item_id) {
		// Check if the order is stored in the internal list
		if(empty($this->items_orders[$order_item_id])) {
			// Cache the order after retrieving it. This will reduce the amount of queries executed
			$this->items_orders[$order_item_id] = Aelia_Order::get_by_item_id($order_item_id);
		}

		return $this->items_orders[$order_item_id];
	}

	/**
	 * Constructor.
	 */
	public function __construct()	{
		add_filter('update_order_item_metadata', array($this, 'update_order_item_metadata'), 10, 4);
		add_filter('add_order_item_metadata', array($this, 'update_order_item_metadata'), 10, 4);

		add_filter('woocommerce_hidden_order_itemmeta', array($this, 'woocommerce_hidden_order_itemmeta'), 10, 1);

		// Handle the custom meta data, supporting also the HPOS feature
		// @since 5.0.0.230203
		add_action('woocommerce_after_order_object_save', [$this, 'woocommerce_after_order_object_save'], 50, 2);
		add_action('woocommerce_after_order_refund_object_save', [$this, 'woocommerce_after_order_refund_object_save'], 50, 2);
		add_action('woocommerce_process_shop_order_meta', [$this, 'woocommerce_process_shop_order_meta'], 100, 2);
	}

	/**
	 * Add custom item metadata added by the plugin.
	 *
	 * @param array item_meta The original metadata to hide.
	 * @return array
	 */
	public function woocommerce_hidden_order_itemmeta($item_meta) {
		$custom_order_item_meta = array(
			'_line_subtotal_base_currency',
			'_line_subtotal_tax_base_currency',
			'_line_tax_base_currency',
			'_line_total_base_currency',
			'tax_amount_base_currency',
			'shipping_tax_amount_base_currency',
			'discount_amount_base_currency',
			'discount_amount_tax_base_currency',
		);
		return array_merge($item_meta, $custom_order_item_meta);
	}

	/**
	 * Indicates if the specified meta key corresponds to a value that should be calculated
	 * in shop's base currency.
	 *
	 * @param string $meta_key
	 * @param mixed $meta_value
	 * @return boolean
	 * @since 4.11.0.210517
	 */
	protected static function should_calculate_order_item_meta_in_base_currency(string $meta_key, $meta_value): bool {
		$meta_to_calculate_in_base_currency = array(
			'_line_subtotal',
			'_line_subtotal_tax',
			'_line_tax',
			'_line_total',
			'tax_amount',
			'shipping_tax_amount',
			'discount_amount',
			'discount_amount_tax',
		);

		// The value should be recalculated in the shop's base currency only if it's part of the list, and if
		// it's numeric
		// @since 5.1.12.241003
		return in_array($meta_key, $meta_to_calculate_in_base_currency) && is_numeric($meta_value);
	}

	/**
	 * Indicates if it should be possible to set the currency for an order. This should be possible
	 * when the following conditions are satisfied:
	 * 1. The order must be editable.
	 * 2. The order must not contain items with a price. This is important because changing the
	 *    currency of an existing order would not recalculate the item prices. This would lead to the
	 *    existing values being interpreted as being in the wrong currency.
	 *
	 * @param WC_Order $order
	 * @return boolean
	 */
	public static function allow_setting_order_currency(WC_Order $order): bool {
		return (bool)apply_filters('wc_aelia_cs_allow_setting_order_currency', empty($order->get_items()) && $order->is_editable(), $order);
	}

	/**
	 * Adds line totals in base currency for each product in an order.
	 *
	 * @param null $check
	 * @param int $order_item_id The ID of the order item.
	 * @param string $meta_key The meta key being saved.
	 * @param mixed $meta_value The value being saved.
	 * @return null|bool
	 *
	 * @see update_metadata().
	 */
	public function update_order_item_metadata($check, $order_item_id, $meta_key, $meta_value) {
		// Convert line totals into base Currency (they are saved in the currency used
		// to complete the transaction)
		if(self::should_calculate_order_item_meta_in_base_currency($meta_key, $meta_value)) {
			// Load the order
			$order = $this->get_order_from_item($order_item_id);

			$order_id = $order->get_id();
			if(empty($order_id)) {
				// An empty order id indicates that something is not right. Without it,
				// we cannot calculate the amounts in base currency
				$this->get_logger()->info(__('Could not find the order linked to the order item. Calculation of metadata in base currency skipped.', Definitions::TEXT_DOMAIN),
																	array(
																		'Order Item ID' => $order_item_id,
																		'Meta Key' => $meta_key,
																	));
			}
			else {
				// Use the CRUD object to edit the order item
				// @since 5.1.12.241003
				$order_item = $order->get_item($order_item_id);
				if($order_item instanceof WC_Order_Item) {
					// Fetch the order currency. If the order currency is empty, it means that we are in checkout phase.
					// WooCommerce saves the order currency AFTER the Order Total (a bit nonsensical, but that's the way
					// it is). In such case, we can take the active currency, which is used to place the order
					$order_currency = $order->get_currency();
					if(empty($order_currency)) {
						$order_currency = get_woocommerce_currency();
					}

					$base_currency = WC_Aelia_CurrencySwitcher::settings()->base_currency();

					// Fetch the exchange rate to convert the order amounts to the shop's base currency.
					// If the rate saved against the order is empty, or invalid, then calculate it using
					// current exchange rates.
					//
					// The filter `wc_aelia_cs_update_order_props_in_currency_exchange_rate` will allow 3rd
					// parties to alter the exchange rate, if needed.
					//
					// @since 5.2.2.241028
					$base_currency_exchange_rate = $this->get_order_base_currency_exchange_rate($order);

					if($base_currency_exchange_rate <= 0) {
						// Replace the empty, or invalid exchange rate with a valid one, calculated using current exchange rates
						$base_currency_exchange_rate = WC_Aelia_CurrencySwitcher::instance()->convert(1, $order_currency, $base_currency,	\Aelia\WC\ExchangeRatesModel::EXCHANGE_RATE_DECIMALS, false);
					}

					// Calculate the amounts in the base currency, using the exchange rate stored against the order
					// @since 5.1.12.241003
					$amount_in_base_currency = wc_float_to_string(round($meta_value * $base_currency_exchange_rate, \Aelia\WC\ExchangeRatesModel::EXCHANGE_RATE_DECIMALS));

					// Save the meta data using the CRUD object for order items
					// @since 5.1.12.241003
					$order_item->update_meta_data($meta_key . '_base_currency', $amount_in_base_currency);
					$order_item->save_meta_data();
				}
			}
		}

		return $check;
	}

	/**
	 * Updates the order meta in shop's base currency (order total, discount
	 * total, shipping total, etc).
	 *
	 * @param WC_Order order
	 * @since 5.0.0.230203
	 */
	protected function update_order_props_in_currency(WC_Abstract_Order $order) {
		// The following list maps a meta key with the corresponding property, added
		// in WC 3.0. The mapping has to be meta key -> object property
		$meta_to_process = array(
			// Orders
			'_order_total' => 'total',
			'_cart_discount' => 'discount_total',
			'_order_shipping' => 'shipping_total',
			'_order_tax' => 'cart_tax',
			'_order_shipping_tax' => 'shipping_tax',
			'_cart_discount_tax' => 'discount_tax',
			// Refunds
			'_refund_amount' => 'amount',
		);

		if(empty($meta_to_process)) {
			return;
		}

		// Fetch the exchange rate to convert the order amounts to the shop's base currency.
		// If the rate saved against the order is empty, or invalid, then calculate it using
		// current exchange rates.
		//
		// The filter `wc_aelia_cs_update_order_props_in_currency_exchange_rate` will allow 3rd
		// parties to alter the exchange rate, if needed.
		//
		// @since 5.2.2.241028
		$base_currency_exchange_rate = $this->get_order_base_currency_exchange_rate($order);

		if($base_currency_exchange_rate <= 0) {
			// If the order doesn't have a valid base currency exchange rate, e.g. because it's new, calculate and save the exchange rate against it
			$this->get_logger()->info(__('Found order without the base currency exchange rate meta.', Definitions::TEXT_DOMAIN), [
				'Order ID' => $order->get_id(),
			]);

			// Calculate and save the base currency exchange rate against the order
			// @since 5.2.1.241009
			$base_currency_exchange_rate = $this->update_order_base_currency_exchange_rate($order, $order->get_currency());
		}

		// Calculate the amount in base currency for each property, and save it against the order meta
		foreach($meta_to_process as $meta_key => $prop_name) {
			$property_getter_method = "get_$prop_name";
			if(!method_exists($order, $property_getter_method)) {
				$this->get_logger()->info(esc_html(implode(' ', [
					__('Could not fetch the value of an order property, method does not exist.', Definitions::TEXT_DOMAIN),
					__('IMPORTANT', Definitions::TEXT_DOMAIN) . ':',
					__('This is not necessarily an error.', Definitions::TEXT_DOMAIN),
					__('Some methods are present only in specific order types (e.g. only in refunds).', Definitions::TEXT_DOMAIN),
				])), [
					'Order ID' => $order->get_id(),
					'Order Type' => $order->get_type(),
					'Meta Key' => $meta_key,
					'Property' => $prop_name,
					'Method' => $property_getter_method,
				]);
				continue;
			}

			// Get the original value of the property
      $original_amount = $order->{$property_getter_method}();

			$amount_in_base_currency = null;
			if(is_numeric($original_amount)) {
				// Calculate the amounts in the base currency, using the exchange rate stored against the order
				// @since 5.1.12.241003
				$amount_in_base_currency = round(floatval($original_amount) * $base_currency_exchange_rate, \Aelia\WC\ExchangeRatesModel::EXCHANGE_RATE_DECIMALS);
			}
			$order->update_meta_data($meta_key . '_base_currency', $amount_in_base_currency);
		}

		$order->save_meta_data();
	}

	/**
	 * Updates the order properties in shop's base currency when an order is saved.
	 *
	 * @param WC_Order $order
	 * @param WC_Data_Store $data_store
	 * @return void
	 * @since 5.0.0.230203
	 */
	public function woocommerce_after_order_object_save(WC_Order $order, WC_Data_Store $data_store): void {
		// Remove this action from the list, to prevent infinite recursion
		remove_action('woocommerce_after_order_object_save', [$this, 'woocommerce_after_order_object_save'], 50, 2);

		$this->update_order_props_in_currency($order);

		// Restore the action after completing the operation
		add_action('woocommerce_after_order_object_save', [$this, 'woocommerce_after_order_object_save'], 50, 2);
	}

	/**
	 * Updates the order properties in shop's base currency when an order is saved.
	 *
	 * @param WC_Order_Refund $order
	 * @param WC_Data_Store $data_store
	 * @return void
	 * @since 5.0.0.230203
	 */
	public function woocommerce_after_order_refund_object_save(WC_Order_Refund $order, WC_Data_Store $data_store): void {
		// Remove this action from the list, to prevent infinite recursion
		remove_action('woocommerce_after_order_refund_object_save', [$this, 'woocommerce_after_order_refund_object_save'], 50, 2);

		$this->update_order_props_in_currency($order);

		// Restore the action after completing the operation
		add_action('woocommerce_after_order_refund_object_save', [$this, 'woocommerce_after_order_refund_object_save'], 50, 2);
	}

	/**
	 * Fired after an order is saved. It addsa a filter to ensure that the currency
	 * for new orders is set to the active currency.
	 *
	 * @param int $post_id The post (order) ID.
	 * @param object $post The post corresponding to the order that is being been saved.
	 * @return void
	 * @since 5.0.0.230203
	 */
	public function woocommerce_process_shop_order_meta($post_id, $post): void {
		if(isset($_POST[Definitions::ARG_CURRENCY]) && WC_Aelia_CurrencySwitcher::instance()->is_valid_currency($_POST[Definitions::ARG_CURRENCY])) {
			// Set the currency on manually created orders when they are still editable
			$order = wc_get_order($post_id);

			if(($order instanceof WC_Order) && self::allow_setting_order_currency($order)) {
				// If the order currency changes, recalculate the base currency exchange rate
				// @since 5.2.1.241009
				if($order->get_currency() !== $_POST[Definitions::ARG_CURRENCY]) {
					$this->get_logger()->notice(__('Detected change in the currency saved against an order.', Definitions::TEXT_DOMAIN), [
						'Order ID' => $order->get_id(),
						'Original Order Currency' => $order->get_currency(),
						'New Currency' => $_POST[Definitions::ARG_CURRENCY],
					]);

					// Calculate and save the base currency exchange rate against the order
					$this->update_order_base_currency_exchange_rate($order, $_POST[Definitions::ARG_CURRENCY]);
				}

				$order->set_currency($_POST[Definitions::ARG_CURRENCY]);
				$order->save();
			}
		}
	}

	/**
	 * Saves against an order the exchange rate to convert order amounts to the shop's base currency.
	 *
	 * @param WC_Abstract_Order $order
	 * @param string $order_currency
	 * @param boolean $save_order_meta
	 * @return float
	 * @since 5.2.3.241101
	 */
	protected function update_order_base_currency_exchange_rate(WC_Abstract_Order $order, string $order_currency, $save_order_meta = false): float {
		// Replace the empty, or invalid exchange rate with a valid one, calculated using current exchange rates
		$base_currency_exchange_rate = WC_Aelia_CurrencySwitcher::instance()->convert(1, $order_currency, WC_Aelia_CurrencySwitcher::settings()->base_currency(),	\Aelia\WC\ExchangeRatesModel::EXCHANGE_RATE_DECIMALS, false);

		$this->get_logger()->info(__('Calculating and saving the base currency exchange rate against the order.', Definitions::TEXT_DOMAIN), [
			'Order ID' => $order->get_id(),
			'Order Currency' => $order_currency,
			'Base Currency Exchange Rate' => $base_currency_exchange_rate,
		]);

		// Save the new base currency exchange rate against the order. This should
		$order->update_meta_data(Definitions::META_BASE_CURRENCY_EXCHANGE_RATE, $base_currency_exchange_rate);

		// If requested, save the meta data immediately. This is optional, because the exchange rate could be
		// saved as part of other operations, which will take care of saving the meta
		if($save_order_meta) {
			$order->save_meta_data();
		}

		return $base_currency_exchange_rate;
	}

	/**
	 * Fetches from an order the exchange rate to convert order amounts to the shop's base currency. If the fetched
	 * rate is invalid, this method tries to re-sync the order meta before trying again. This is to ensure that
	 * the meta is indeed empty or invalid, and not just that the object "forgot" to load it.
	 *
	 * WHY THE DOUBLE ATTEMPT
	 * In October 2024, we received a report of some issues with the Analytics, which turned out to be caused by
	 * the presence of duplicate data in the database. The duplication occurred since we made a minor optimisation
	 * in  method update_order_props_in_currency(), which caused WooCommerce to "forget" the exchange rate and INSERT
	 * the exchange rate twice into the database, despite the fact that the operation is an UPDATE. This is described
	 * in more detail in issue #64.
	 *
	 * @param WC_Abstract_Order $order
	 * @return float
	 * @since 5.2.3.241101
	 * @see Orders_Integration::update_order_props_in_currency()
	 * @link https://bitbucket.org/businessdad/woocommerce-currency-switcher/issues/64
	 */
	protected function get_order_base_currency_exchange_rate(WC_Abstract_Order $order): float {
		static $loaded_orders = [];

		// Fetch the exchange rate from the order
		$base_currency_exchange_rate = $order->get_meta(Definitions::META_BASE_CURRENCY_EXCHANGE_RATE);

		// If the exchange rate is not valid, try using a new order instance
		if(empty($base_currency_exchange_rate) || !is_numeric($base_currency_exchange_rate) || ($base_currency_exchange_rate <= 0)) {
			$this->get_logger()->info(__('Invalid base currency exchange rate found against an order. Retrying after loading the meta from the database.', Definitions::TEXT_DOMAIN), [
				'Order ID' => $order->get_id(),
				'Base Currency Exchange Rate' => $base_currency_exchange_rate,
			]);

			// Load a new instance of the order, if needed, so that the original one is not affected
			if(!isset($loaded_orders[$order->get_id()])) {
				$this->get_logger()->debug(__('Loading a new order instance to fetch the meta.', Definitions::TEXT_DOMAIN), [
					'Order ID' => $order->get_id(),
					'Base Currency Exchange Rate' => $base_currency_exchange_rate,
				]);

				// Load and cache the order instance
				$loaded_orders[$order->get_id()] = wc_get_order($order->get_id());
			}
			else {
				$this->get_logger()->debug(__('Reloading meta from cached order instance.', Definitions::TEXT_DOMAIN), [
					'Order ID' => $order->get_id(),
					'Base Currency Exchange Rate' => $base_currency_exchange_rate,
				]);

				// If present, reuse the cached order instance
				$loaded_orders[$order->get_id()]->read_meta_data();
			}

			// Fetch the exchange rate from the internal order instance
			$base_currency_exchange_rate = $loaded_orders[$order->get_id()]->get_meta(Definitions::META_BASE_CURRENCY_EXCHANGE_RATE);

			$this->get_logger()->info(__('Base currency exchange rate fetched from internal order instance.', Definitions::TEXT_DOMAIN), [
				'Order ID' => $order->get_id(),
				'Base Currency Exchange Rate' => $base_currency_exchange_rate,
			]);
		}

		$base_currency_exchange_rate = apply_filters('wc_aelia_cs_update_order_props_in_currency_exchange_rate', $base_currency_exchange_rate, $order);

		// If the fetched exchange rate is not numeric, return -1 to indicate that it's an incorrect value
		if(!is_numeric($base_currency_exchange_rate)) {
			return -1;
		}

		return $base_currency_exchange_rate;
	}
}
