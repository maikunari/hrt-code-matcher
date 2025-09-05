<?php
namespace Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\HPOS;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Legacy\Database_Updater_Version_5_0_3_230626 as Legacy_Database_Updater_Version_5_0_3_230626;

/**
 * HPOS database updater.
 *
 * This class runs the following database updates for version 5.0.3.230626:
 * - Add "_base_currency" amounts to existing orders, converting the values from the original order currency.
 * - Add "_base_currency_exchange_rate" to orders.
 * - Add "_base_currency" amounts to the order items.
 * - Add "_base_currency" amounts to the refunds linked to the processed orders.
 *
 * @since 5.0.4.230626
 */
class Database_Updater_Version_5_0_3_230626 extends Legacy_Database_Updater_Version_5_0_3_230626 {
	/**
	 * The task ID.
	 *
	 * @var string
	 */
	protected static $id = 'aelia_cs_hpos_import_sales_data_5_0_3_230626';

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
				ORDERS.id AS order_id
			FROM
				{$wpdb->prefix}wc_orders AS ORDERS
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
				{$wpdb->prefix}wc_orders_meta AS meta_order_base_currency ON
					(meta_order_base_currency.order_id = ORDERS.ID) AND
					(meta_order_base_currency.meta_key = meta_keys.meta_key)
			WHERE
				(ORDERS.id >= {$this->settings->offset})
				AND (ORDERS.type = 'shop_order')
				-- Only take orders that are missing the meta value in the shop's base currency
				AND (meta_order_base_currency.meta_value IS NULL)
			ORDER BY
				ORDERS.ID ASC
			LIMIT {$this->settings->batch_size}
		";

		$this->get_logger()->debug(__('Debugging query used to fetch orders.', Definitions::TEXT_DOMAIN), [
			'SQL' => $SQL,
		]);

		$orders_to_update = $this->select($SQL);

		return is_array($orders_to_update) ? $orders_to_update : [];
	}
}

