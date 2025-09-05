<?php
namespace Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\AFC\Scheduler\Tasks\IScheduled_Task;
use Aelia\WC\AFC\Scheduler\Tasks\Task_Result;
use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\Messages;
use Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Database_Updater_Task;
use Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Database_Updater_Task_Result;
use Aelia\WC\CurrencySwitcher\Exchange_Rates_OpenExchangeRates_Model;
use Aelia\WC\CurrencySwitcher\WC_Aelia_OFXModel;
use Aelia\WC\CurrencySwitcher\WC_Aelia_TCBModel;

/**
 * Run the following database updates for version 5.0.3.230626:
 * - Add "_base_currency_exchange_rate" to any refund that has not been processed by previous
 *   update steps.
 * - Add "_base_currency" amounts to the refunds.
 * - Add "_base_currency" amounts to the refund items.
 *
 * @since 4.16.0.230623
 */
class Database_Updater_Version_4_12_6_210825 extends Database_Updater_Task implements IScheduled_Task {
	/**
	 * The task ID.
	 *
	 * @var string
	 */
	protected static $id = 'aelia_cs_update_plugin_settings_to_4_12_6_210825';

	/**
	 * Indicates the version to which the database will be upgraded after
	 * running the task.
	 *
	 * @var string
	 */
	protected static $database_version = '4.1.6.210825';

	/**
	 * Replaces the obsolete IDs for exchange rates providers, which were hashes,
	 * with the new IDs.
	 *
	 * @param array $settings
	 * @return array
	 */
	protected function replace_exchage_rates_providers_ids(array $settings): array {
		$exchange_rates_provider_ids = [
			// Open Exchange Rates
			'1b579591bf30050213dc4e85590f4213' => Exchange_Rates_OpenExchangeRates_Model::$id,
			// Turkey Central Bank
			'a760b91d0d72b05f7217b2d91cefda1e' => WC_Aelia_TCBModel::$id,
			// Yahoo! Finance is no longer available and is replaced by OFX
			'61472a11414c0a8692bad93df3de2425' => WC_Aelia_OFXModel::$id,
			// WebServiceX is no longer available and is replaced by OFX
			'a760b91d0d72b05f7217b2d91cefda1e' => WC_Aelia_OFXModel::$id,
		];

		// If the ID saved in the database matches one of the legacy IDs from the list,
		// replace the old value with the new ID
		if(!empty($settings['exchange_rates_provider']) && isset($exchange_rates_provider_ids[$settings['exchange_rates_provider']])) {
			$settings['exchange_rates_provider'] = $settings[$settings['exchange_rates_provider']];
		}

		return $settings;
	}

	/**
	 * Replaces the obsolete ISO codes of currencies with the new counterparts.
	 *
	 * @param array $settings
	 * @return array
	 */
	protected function update_obsolete_currency_codes(array $settings): array {
		if(!empty($settings['enabled_currencies']) && is_array($settings['enabled_currencies'])) {
			foreach($settings['enabled_currencies'] as $idx => $currency_code) {
				// Scan the list of enabled currencies, replacing MRO with MRU if present.
				// The MRO ISO code is no longer in use since 2017
				// @link https://en.wikipedia.org/w/index.php?title=Mauritanian_ouguiya&action=edit&section=4
				if($currency_code === 'MRO') {
					$settings['enabled_currencies'][$idx] = 'MRU';
					break;
				}
			}
		}
		return $settings;
	}

	/**
	 * Runs the task and returns the result.
	 *
	 * @return Task_Result
	 */
	public function run(): Task_Result {
		$this->get_logger()->notice(implode(' ', [
			__('Database Updater.', Definitions::TEXT_DOMAIN),
			__('Updating plugin settings.', Definitions::TEXT_DOMAIN),
			__('Upgrading the IDs of the available exchange rates providers.', Definitions::TEXT_DOMAIN),
			])
		);

		try {
			$current_settings = get_option('wc_aelia_currency_switcher');

			if(is_array($current_settings)) {
				// Replace the ID of exchange rates providers
				$current_settings = $this->replace_exchage_rates_providers_ids($current_settings);

				// Replace currency codes that are no longer in use with the correct ones
				$current_settings = $this->update_obsolete_currency_codes($current_settings);

				update_option('wc_aelia_currency_switcher', $current_settings, false);
			}
		}
		catch(\Exception $e) {
			// Inform the administrators if an unexpected exception occurs
			Messages::admin_message(
				wp_kses_post(implode(' ', [
					__('An unexpected error occurred while updating the Currency Switcher settings.', Definitions::TEXT_DOMAIN),
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
					'message_header' => __('Upgrading plugin settings', Definitions::TEXT_DOMAIN),
				]
			);
		}

		// If the operation has been completed, update the database version
		$this->set_database_version($this->settings->plugin_slug, self::$database_version);

		// Inform the user of the task completion
		$this->get_logger()->notice(implode(' ', [
			__('Task completed.', Definitions::TEXT_DOMAIN),
		]));

		// Prepare an object with the result of the task execution
		return new Database_Updater_Task_Result([
			'code' => Definitions::RES_OK,
			'settings' => $this->settings,
		]);
	}
}

