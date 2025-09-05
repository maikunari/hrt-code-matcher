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
 * Run the following database updates for version 5.0.3.230626:
 * - Add "_base_currency_exchange_rate" to any refund that has not been processed by previous
 *   update steps.
 * - Add "_base_currency" amounts to the refunds.
 * - Add "_base_currency" amounts to the refund items.
 *
 * @since 4.16.0.230623
 */
class Database_Updater_Version_5_0_4_230626 extends Database_Updater_Task implements IScheduled_Task {
	use Trait_Order_Items_Currency_Conversion;

	/**
	 * The task ID.
	 *
	 * @var string
	 */
	protected static $id = 'aelia_cs_legacy_import_sales_data_5_0_4_230626';

	/**
	 * Indicates the version to which the database will be upgraded after
	 * running the task.
	 *
	 * @var string
	 */
	protected static $database_version = '5.0.4.230626';

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
				REFUNDS.ID AS refund_id
			FROM
				{$wpdb->posts} REFUNDS
				LEFT JOIN
				{$wpdb->postmeta} REFUND_META ON
					(REFUND_META.post_id = REFUNDS.ID) AND
					(REFUND_META.meta_key = '_base_currency_exchange_rate')
			WHERE
				(REFUNDS.ID >= {$this->settings->offset}) AND
				(REFUNDS.post_type = 'shop_order_refund') AND
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

	/**
	 * Runs the task and returns the result.
	 *
	 * @return Task_Result
	 */
	public function run(): Task_Result {
		$this->get_logger()->notice(implode(' ', [
			__('Database Updater.', Definitions::TEXT_DOMAIN),
			__('Calculating amounts in base currency for orphaned refunds.', Definitions::TEXT_DOMAIN),
			]), [
				'Batch Settings' => $this->settings,
			]
		);

		// Fetch the refunds to update
		$refunds_to_update = $this->get_refunds_to_update();

		$total_refunds_to_update = count($refunds_to_update);

		// If there are no more refunds to update, the operation is considered complete
		if($total_refunds_to_update <= 0) {
			$this->get_logger()->notice(__('No refunds found that require a calculation of totals in base currency.', Definitions::TEXT_DOMAIN));

			// If the operation has been completed, update the database version and stop
			$this->set_database_version($this->settings->plugin_slug, self::$database_version);

			// Prepare an object with the result of the task execution
			return new Database_Updater_Task_Result([
				'code' => Definitions::RES_OK,
				'settings' => $this->settings,
			]);
		}

		$this->get_logger()->notice(__('Found refunds for which the totals in base currency need to be calculated.', Definitions::TEXT_DOMAIN), [
			'refunds Count' => $total_refunds_to_update,
		]);

		// Store the "cache addition" setting and suspend it, to prevent the batch processing from caching a large
		// amount of data unnecessarily
		$last_cache_addition_setting = wp_suspend_cache_addition(true);

		$updated_refunds = 0;
		// Keep track of the last refund ID processed, so that we can start from the next one at the next loop
		$last_refund_id = 0;

		// Fetch the base currency to which the refund and refund item amounts will be converted
		$base_currency = (string)WC_Aelia_CurrencySwitcher::settings()->base_currency();

		// Process each refund
		//
		// IMPORTANT
		// The methods used to calculate the amounts for refunds and refunds items are the same used for orders.
		// This is the reason why they are called should_skip_order(), or calculate_amounts_for_order().
		// A "catch all" name would have been much longer, and possibly made the purpose of the methods unclear.
		foreach(wp_list_pluck($refunds_to_update, 'refund_id') as $refund_id) {
			$this->get_logger()->debug(__('Loading order/refund.', Definitions::TEXT_DOMAIN), [
				'Order ID' => $refund_id,
			]);

			$refund = wc_get_order($refund_id);

			// Skip invalid refunds
			if($this->should_skip_order($refund)) {
				continue;
			}

			try {
				// Calculate the amounts for the order and its items
				// @since 4.16.0.230623
				$this->calculate_amounts_for_order($refund, $base_currency);
			}
			catch(\Exception $e) {
				// Inform the administrators if an unexpected exception occurs
				Messages::admin_message(
					wp_kses_post(implode(' ', [
						__('An unexpected error occurred while calculating the order data in the shop base currency for refunds.', Definitions::TEXT_DOMAIN),
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
						'message_header' => __('Preparation of report data for refunds', Definitions::TEXT_DOMAIN),
					]
				);

				$this->get_logger()->error(implode(' ', [
					__('An exception occurred processing a refund.', Definitions::TEXT_DOMAIN),
					__('Refund skipped.', Definitions::TEXT_DOMAIN),
				]), [
					'Update Task ID' => static::get_id(),
					'Refund ID' => $refund_id,
					'Exception Message' => $e->getMessage(),
				]);
			}

			$updated_refunds++;
			$last_refund_id = $refund_id;
		}

		// Inform the user of the progress
		$this->get_logger()->notice(implode(' ', [
			__('Batch completed.', Definitions::TEXT_DOMAIN),
			__('The next batch will be processed automatically at the next scheduled task run.', Definitions::TEXT_DOMAIN),
		]), [
			'Updated Refunds' => $updated_refunds,
			'Last Processed Refund ID' => $last_refund_id,
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
		// knows that, and it also knows from where it should restart (i.e. the last processed refund)
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
				'offset' => $last_refund_id + 1,
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
			'last_record_id' => $last_refund_id,
		]);
	}
}

