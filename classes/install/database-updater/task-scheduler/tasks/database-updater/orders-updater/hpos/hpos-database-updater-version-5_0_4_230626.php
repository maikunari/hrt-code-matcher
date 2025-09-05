<?php
namespace Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\HPOS;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Legacy\Database_Updater_Version_5_0_4_230626 as Legacy_Database_Updater_Version_5_0_4_230626;


/**
 * HPOS database updater.
 *
 * This class runs the following database updates for version 5.0.3.230626:
 * - Add "_base_currency_exchange_rate" to any refund that has not been processed by previous
 *   update steps.
 * - Add "_base_currency" amounts to the refunds.
 * - Add "_base_currency" amounts to the refund items.
 *
 * @since 5.0.4.230626
 */
class Database_Updater_Version_5_0_4_230626 extends Legacy_Database_Updater_Version_5_0_4_230626 {
	/**
	 * The task ID.
	 *
	 * @var string
	 */
	protected static $id = 'aelia_cs_hpos_import_sales_data_5_0_4_230626';

	/**
	 * Returns a list of refunds that have to be updated by this task.
	 *
	 * @return array
	 * @since 5.0.4.230626
	 */
	protected function get_refunds_to_update(): array {
		global $wpdb;

		// Fetch a list of the refunds that still need their data to be converted. We just
		// assume that a refund whose data has not been converted is going to be missing the
		// _base_currency_exchange_rate meta
		$SQL = "
			SELECT
				REFUNDS.id AS refund_id
			FROM
				{$wpdb->prefix}wc_orders REFUNDS
				LEFT JOIN
				{$wpdb->prefix}wc_orders_meta REFUND_META ON
					(REFUND_META.post_id = REFUNDS.ID) AND
					(REFUND_META.meta_key = '_base_currency_exchange_rate')
			WHERE
				(REFUNDS.id >= {$this->settings->offset}) AND
				(REFUNDS.type = 'shop_order_refund') AND
				(REFUND_META.meta_value IS NULL)
			ORDER BY
				REFUNDS.ID ASC
			LIMIT {$this->settings->batch_size}
		";
		$this->get_logger()->debug(__('Debugging query used to fetch refunds.', Definitions::TEXT_DOMAIN), [
			'SQL' => $SQL,
		]);

		$refunds_to_update = $this->select($SQL);

		return is_array($refunds_to_update) ? $refunds_to_update : [];
	}
}
