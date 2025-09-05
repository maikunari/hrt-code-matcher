<?php
namespace Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Legacy;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\Messages;
use Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Database_Updater_Task;
use Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Database_Updater_Task_Result;
use Aelia\WC\AFC\Scheduler\Scheduled_Task_Settings;
use Aelia\WC\AFC\Scheduler\Tasks\IScheduled_Task;
use Aelia\WC\AFC\Scheduler\Tasks\Task_Result;
use Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Traits\Trait_Order_Items_Currency_Conversion;
use Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher;

/**
 * Legacy (post tables) database updater.
 *
 * This class runs the following database updates for version 5.0.3.230626:
 * - Add "_base_currency" amounts to existing orders, converting the values from the original order currency.
 * - Add "_base_currency_exchange_rate" to orders.
 * - Add "_base_currency" amounts to the order items.
 * - Add "_base_currency" amounts to the refunds linked to the processed orders.
 *
 * @since 4.16.0.230623
 */
class Database_Updater_Version_5_0_3_230626 extends Database_Updater_Task implements IScheduled_Task {
	use Trait_Order_Items_Currency_Conversion;

	/**
	 * The task ID.
	 *
	 * @var string
	 */
	protected static $id = 'aelia_cs_legacy_import_sales_data_5_0_3_230626';

	/**
	 * Indicates the version to which the database will be upgraded after
	 * running the task.
	 *
	 * @var string
	 */
	protected static $database_version = '5.0.3.230626';

	/**
	 * Returns a list of orders that have to be updated by this task.
	 *
	 * @return array
	 * @since 5.0.4.230626
	 */
	protected function get_orders_to_update(): array {
		global $wpdb;

		// Prepare the list of attributes to convert
		// @see Abstract_WC_Order_Data_Store_CPT::update_post_meta()
		$order_meta_keys_to_properties = [
			'_order_total' => 'total',
			'_cart_discount' => 'discount_total',
			'_cart_discount_tax' => 'discount_tax',
			'_order_tax' => 'cart_tax',
			'_order_shipping' => 'shipping_total',
			'_order_shipping_tax' => 'shipping_tax',
		];

		// Build the array of meta keys to search. These keys will be used to
		// identify orders that are missing data in the shop's base currency
		$meta_keys_to_search = array_map(function($entry) {
			return $entry . '_base_currency';
		}, array_keys($order_meta_keys_to_properties));
		$meta_keys_to_search[] = '_base_currency_exchange_rate';

		// Build a dataset of meta keys that can be used as a JOIN inside the
		// main query. This will allow to find which meta keys are missing, if any
		// @since 5.1.5.240327
		$meta_keys_join = implode("\nUNION ALL\n", array_map(function($entry) use ($wpdb) {
			return $wpdb->prepare('SELECT %s AS meta_key', $entry);
		}, $meta_keys_to_search));

		// Fetch a list of the orders for which the meta in base currency is missing
		$SQL = "
			SELECT DISTINCT
				posts.ID AS order_id
			FROM
				{$wpdb->posts} AS posts
			-- Use the dataset created on the fly to join the orders with the
			-- meta keys. This will allow to join with the post meta to find all the
			-- meta keys that do or do not have a match in the post meta, then filter
			-- the result to only keep the latter
			-- @since 5.1.5.240327
			JOIN
				(
					{$meta_keys_join}
				) as meta_keys
			LEFT JOIN
				{$wpdb->postmeta} AS meta_order_base_currency ON
					(meta_order_base_currency.post_id = posts.ID) AND
					(meta_order_base_currency.meta_key = meta_keys.meta_key)
			WHERE
				(posts.id >= {$this->settings->offset})
				AND (posts.post_type = 'shop_order')
				-- Only take orders that are missing the meta value in the shop's base currency
				AND (meta_order_base_currency.meta_value IS NULL)
			ORDER BY
				posts.ID ASC
			LIMIT {$this->settings->batch_size}
		";

		$this->get_logger()->debug(__('Debugging query used to fetch orders.', Definitions::TEXT_DOMAIN), [
			'SQL' => $SQL,
		]);

		$orders_to_update = $this->select($SQL);

		return is_array($orders_to_update) ? $orders_to_update : [];
	}

	/**
	 * Runs the task and returns the result.
	 *
	 * @return Task_Result
	 */
	public function run(): Task_Result {
		$this->get_logger()->notice(implode(' ', [
			__('Database Updater.', Definitions::TEXT_DOMAIN),
			__('Calculating amounts in base currency for past orders.', Definitions::TEXT_DOMAIN),
			]), [
				'Batch Settings' => $this->settings,
			]
		);

		// Fetch the orders to update
		$orders_to_update = $this->get_orders_to_update();

		$total_orders_to_update = count($orders_to_update);

		// If there are no more orders to update, the operation is considered complete
		if($total_orders_to_update <= 0) {
			$this->get_logger()->notice(__('No orders found that require a calculation of totals in base currency.', Definitions::TEXT_DOMAIN), []);

			// If the operation has been completed, update the database version and stop
			$this->set_database_version($this->settings->plugin_slug, self::$database_version);

			// Prepare an object with the result of the task execution
			return new Database_Updater_Task_Result([
				'code' => Definitions::RES_OK,
				'settings' => $this->settings,
			]);
		}

		$this->get_logger()->notice(__('Found orders for which the totals in base currency need to be calculated.', Definitions::TEXT_DOMAIN), [
			'Orders Count' => $total_orders_to_update,
		]);

		// Store the "cache addition" setting and suspend it, to prevent the batch processing from caching a large
		// amount of data unnecessarily
		$last_cache_addition_setting = wp_suspend_cache_addition(true);

		$updated_orders = 0;
		// Keep track of the last order ID processed, so that we can start from the next one at the next loop
		$last_order_id = 0;

		// Fetch the base currency to which the order and order item amounts will be converted
		$base_currency = (string)WC_Aelia_CurrencySwitcher::settings()->base_currency();

		foreach(wp_list_pluck($orders_to_update, 'order_id') as $order_id) {
			$this->get_logger()->debug(__('Loading order/refund.', Definitions::TEXT_DOMAIN), [
				'Order ID' => $order_id,
			]);

			$order = wc_get_order($order_id);

			// Skip invalid orders
			if($this->should_skip_order($order)) {
				continue;
			}

			try {
				// Calculate the amounts for the order and its items
				// @since 4.16.0.230623
				$this->calculate_amounts_for_order($order, $base_currency);

				// Calculate the amounts in base currency for the refunds linked to the order
				// @since 4.16.0.230623
				$this->calculate_amounts_for_order_refunds($order, $order->get_meta('_base_currency_exchange_rate'));
			}
			catch(\Exception $e) {
				// Inform the administrators if an unexpected exception occurs
				Messages::admin_message(
					wp_kses_post(implode(' ', [
						__('An unexpected error occurred while calculating the order data in the shop base currency for orders.', Definitions::TEXT_DOMAIN),
						$e->getMessage(),
						'<br /><br />',
						__('Please refer to the Currency Switcher log for more details:', Definitions::TEXT_DOMAIN),
						sprintf('<a href="%1$s">%1$s</a>.', admin_url('admin.php?page=wc-status&tab=logs')),
						__('The plugin will automatically resume the database update process later.', Definitions::TEXT_DOMAIN),
					])),
					[
						'sender_id' => $this->settings->plugin_slug,
						'level' => E_USER_WARNING,
						'code' => Definitions::NOTICE_DATABASE_UPDATE_EXCHANGE_RATES_ERROR,
						'dismissable' => false,
						'permissions' => 'manage_woocommerce',
						'message_header' => __('Preparation of report data for orders', Definitions::TEXT_DOMAIN),
					]
				);

				$this->get_logger()->error(implode(' ', [
					__('An exception occurred processing an order.', Definitions::TEXT_DOMAIN),
					__('Order skipped.', Definitions::TEXT_DOMAIN),
				]), [
					'Update Task ID' => static::get_id(),
					'Order ID' => $order_id,
					'Exception Message' => $e->getMessage(),
				]);
			}

			$updated_orders++;
			$last_order_id = $order_id;
		}

		// Inform the user of the progress
		$this->get_logger()->notice(implode(' ', [
			__('Batch completed.', Definitions::TEXT_DOMAIN),
			__('The next batch will be processed automatically at the next scheduled task run.', Definitions::TEXT_DOMAIN),
		]), [
			'Updated Orders' => $updated_orders,
			'Last Processed Order ID' => $last_order_id,
		]);

		// Restore the cache addition setting
		wp_suspend_cache_addition($last_cache_addition_setting);

		// Calculate the offset to be added to the starting time for the scheduled task
		// @since 5.0.5.230703
		$start_time_offset = apply_filters('wc_aelia_cs_scheduled_task_start_time_offset', static::DEFAULT_TASK_SCHEDULE_START_OFFSET, static::get_id(), []);
		// Ensure that the start time offset is a valid integer
		$start_time_offset = is_int($start_time_offset) ? intval($start_time_offset) : static::DEFAULT_TASK_SCHEDULE_START_OFFSET;

		// Schedule the next batch of updates. This will remove the burden of determining if
		// this task requires more runs from the database manager, as the task itself already
		// knows that, and it also knows from where it should restart (i.e. the last processed order)
		$next_batch_schedule_settings = new Scheduled_Task_Settings([
			'group' => $this->settings->plugin_slug,
			'task_id' => self::get_id(),
			'interval' => 0,
			// Schedule the next task with a slight delay, to reduce the continuous load on the server
			'start_timestamp' => (time() + $start_time_offset),
			'debug_mode' => $this->settings->debug_mode,
			'scheduled_event_args' => [
				'plugin_slug' => $this->settings->plugin_slug,
				// @since 5.0.5.230703
				'plugin_version' => $this->settings->plugin_version,
				'batch_size' => $this->settings->batch_size,
				'offset' => $last_order_id + 1,
				// Pass the exchange rates to the next scheduled task, so that they will be the same
				// for each iteration
				'exchange_rates' => $this->get_exchange_rates(),
			],
		]);
		do_action('aelia_cs_database_updater_schedule_task', $next_batch_schedule_settings);

		// Prepare an object with the result of the task execution
		return new Database_Updater_Task_Result([
			'code' => Definitions::RES_OK,
			'settings' => $this->settings,
			'last_record_id' => $last_order_id,
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
			$this->get_logger()->notice(__('Storing amounts in base currency for order item.', Definitions::TEXT_DOMAIN), [
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
						$this->get_logger()->warning(__('Invalid, non-numeric value found for order item attribute to convert. The value in base currency will be assumed to be zero.', Definitions::TEXT_DOMAIN), [
							'Order ID' => $order_id,
							'Order Item ID' => $order_item->get_id(),
							'Meta Key' => $meta_key,
							'Meta Value' => $value_to_convert,
						]);

						$value_in_base_currency = 0;
					}
					else {
						$value_in_base_currency = $value_to_convert * $base_currency_exchange_rate;

						$this->get_logger()->debug(__('Converted order item amount to the shop base currency.', Definitions::TEXT_DOMAIN), [
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

	/**
	 * Calculates the totals in base currency for the refunds linked to an order.
	 *
	 * @param \WC_Order $parent_order The order from which the refunds will be fethced.
	 * @param float $base_currency_exchange_rate The exchange rate to be used for the
	 * conversion. It's accepted as an argument to save time, since the ordr already has it.
	 * @return void
	 * @since 4.16.0.230623
	 */
	protected function calculate_amounts_for_order_refunds(\WC_Order $parent_order, float $base_currency_exchange_rate): void {
		// Prepare the list of attributes to convert
		// @see Abstract_WC_Order_Data_Store_CPT::update_post_meta()
		$refund_meta_keys_to_properties = [
			'_order_total' => 'total',
			'_cart_discount' => 'discount_total',
			'_cart_discount_tax' => 'discount_tax',
			'_order_tax' => 'cart_tax',
			'_order_shipping' => 'shipping_total',
			'_order_shipping_tax' => 'shipping_tax',
			'_refund_amount' => 'amount',
		];

		// Fetch the list of refunds from the orders
		$order_refunds = $parent_order->get_refunds();

		// If there are no refunds to process, stop here
		if(empty($order_refunds)) {
			return;
		}

		$this->get_logger()->notice(__('Calculating amounts in base currency for the refunds linked to an order.', Definitions::TEXT_DOMAIN), [
			'Order ID' => $parent_order->get_id(),
			'Refunds Count' => count($order_refunds),
		]);

		foreach($order_refunds as $refund) {
			// Save the exchange rate from the order against the refund
			$refund->update_meta_data('_base_currency_exchange_rate', $base_currency_exchange_rate);

			$this->get_logger()->notice(__('Processing amounts for order refund.', Definitions::TEXT_DOMAIN), [
				'Order ID' => $parent_order->get_id(),
				'Refund ID' => $refund->get_id(),
			]);

			// Process all the order attributes that need conversion
			foreach($refund_meta_keys_to_properties as $meta_key => $refund_property) {
				$method_name = "get_{$refund_property}";

				// Skip properties that the refund doesn't have. In theory, the only one that
				// actually matters is the _refund_amount. This step is just to convert any
				// other properties that might have been inherited from the order, and missing
				// properties aren't an issue
				if(!method_exists($refund, $method_name)) {
					continue;
				}

				// Fetch the value to convert
				$value_to_convert = $refund->$method_name();

				// If the meta value is not numeric, assume that it's zero
				if(!is_numeric($value_to_convert)) {
					$this->get_logger()->warning(__('Invalid, non-numeric value found for refund attribute to convert. The value in base currency will be assumed to be zero.', Definitions::TEXT_DOMAIN), [
						'Order ID' => $parent_order->get_id(),
						'Refund ID' => $refund->get_id(),
						'Meta Key' => $meta_key,
						'Meta Value' => $value_to_convert,
					]);

					$value_in_base_currency = 0;
				}
				else {
					// Use the base currency exchange rate to perform the conversion. This will spare the
					// time that would be used to call the conversion function provided by the Currency Switcher
					$value_in_base_currency = floatval($value_to_convert) * $base_currency_exchange_rate;

					$this->get_logger()->debug(__('Converted refund attribute to the shop base currency.', Definitions::TEXT_DOMAIN), [
						'Order ID' => $parent_order->get_id(),
						'Refund ID' => $refund->get_id(),
						'Refund Property' => $method_name,
						'Meta Key' => $meta_key,
						'Order Currency' => $parent_order->get_currency(),
						'Original Value' => $value_to_convert,
						'Converted Value' => $value_in_base_currency,
					]);
				}

				$refund->update_meta_data("{$meta_key}_base_currency", $value_in_base_currency);
			}

			// Save the meta data to the database
			$refund->save_meta_data();

			// Update the refund items
			// @since 4.16.0.230623
			$this->calculate_amounts_for_order_items($refund, $base_currency_exchange_rate);

			$this->get_logger()->notice(__('Refund processed successfully.', Definitions::TEXT_DOMAIN), [
				'Order ID' => $parent_order->get_id(),
				'Refund ID' => $refund->get_id(),
			]);
		}

		$this->get_logger()->notice(__('All refunds processed for the order.', Definitions::TEXT_DOMAIN), [
			'Order ID' => $parent_order->get_id(),
		]);
	}
}

