<?php
namespace Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\AFC\Traits\Database_Trait;
use Aelia\WC\AFC\Scheduler\Tasks\Base_Scheduled_Task;
use Aelia\WC\AFC\Scheduler\Tasks\Task_Instance_Settings;
use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher;

/**
 * Base class to run database updates.
 *
 * @since 4.16.0.230623
 */
abstract class Database_Updater_Task extends Base_Scheduled_Task {
	use Database_Trait;

	/**
	 * Tasks that should be scheduled "starting immediately" will be scheduled
	 * after the interval specified by this offst. Value in seconds.
	 *
	 * @var int
	 * @since 5.0.5.230703
	 */
	const DEFAULT_TASK_SCHEDULE_START_OFFSET = 120;

	/**
	 * The task ID.
	 *
	 * @var string
	 */
	protected static $id = 'database_updater_task';

	/**
	 * Indicates the version to which the database will be upgraded after
	 * running the task.
	 *
	 * @var string
	 */
	protected static $database_version = '0.0.0';

	/**
	 * Stores the task settings.
	 *
	 * @var Database_Updater_Task_Settings
	 */
	protected $settings;

	/**
	 * Returns the database version for this task.
	 *
	 * @return string
	 */
	public static function get_task_database_version(): string {
		return static::$database_version;
	}

	/**
	 * Given some settings passed via the scheduler hook, returns an instance of the settings
	 * that can be used to instantiate this class.
	 *
	 * @param array $args
	 * @return Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Task_Instance_Settings
	 */
	protected static function get_task_settings_from_callback_args(array $args): Task_Instance_Settings {
		return new Database_Updater_Task_Settings(array_merge($args, [
			// Override the debug mode, taking the value from the latest settings
			'debug_mode' => WC_Aelia_CurrencySwitcher::settings()->debug_mode(),
		]));
	}

	/**
	 * Constructor
	 *
	 * @param Database_Updater_Task_Settings $settings
	 */
	public function __construct(Task_Instance_Settings $settings) {
		if(!$settings instanceof Database_Updater_Task_Settings) {
			throw new \InvalidArgumentException(sprintf(
				__('Invalid argument specified. Expected type "Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater\Database_Updater_Task_Settings". Received: "%1$s".', Definitions::TEXT_DOMAIN),
				gettype($settings))
			);
		}

		$this->settings = $settings;
		// Ensure that the task uses the logger from the Currency Switcher. This is overriding the
		// settings, which, technically, is not very clean, but the current architecture doesn't
		// yet allow to pass a specific logger to tasks that are instantiated by the Task Scheduler
		// in the AFC. Due to that, all tasks end up using the AFC logger, filling it with unrelated
		// entries
		$this->set_logger(WC_Aelia_CurrencySwitcher::instance()->get_logger());
	}

	/**
	 * Returns a list of the exchange rates configured in the Currency Switcher.
	 *
	 * @param bool $refresh Indicates if the exchange rates should be refreshed, by
	 * taking the latest ones from the Currency Switcher. When set to false, this
	 * method will keep the exchange rates that were passed to the task, and just fetch
	 * the missing ones from the Currency Switcher.
	 * @return array
	 * @since 4.16.0.230623
	 */
	protected function get_exchange_rates(bool $refresh = false): array {
		if(!is_array($this->settings->exchange_rates)) {
			$this->settings->exchange_rates = [];
		}

		// If the refresh flag is set, start with an empty list of exchange rates. If not,
		// take the rates already stored in the settings and fetch any missing one from
		// the Currency Switcher
		$exchange_rates = $refresh ? [] : $this->settings->exchange_rates;

		// Fetch the list of enabled currencies
		$base_currency = apply_filters('wc_aelia_cs_base_currency', get_option('woocommerce_currency'));

		// Fetch the exchange rate for each currency and cache it
		foreach(apply_filters('wc_aelia_cs_enabled_currencies', [$base_currency]) as $currency_code) {
			// Fetch the exchange rate from the Currency Switcher if one of the following conditions is satisfied:
			// - There isn't an exchange rate for the currency already stored
			// - The exchange rate currently stored is not numeric
			// - The exchange rate currently stored is zero or lower
			if(!isset($exchange_rates[$currency_code]) || !is_numeric($exchange_rates[$currency_code]) || ($exchange_rates[$currency_code] <= 0)) {
				$exchange_rates[$currency_code] = WC_Aelia_CurrencySwitcher::settings()->get_exchange_rate($currency_code);
			}
		}

		// Replace the exchange rates store in the settings, so that they can be used later
		$this->settings->exchange_rates = $exchange_rates;

		return $this->settings->exchange_rates;
	}

	/**
	 * Returns the exchange rate to convert from the shop's base currency to the
	 * target currency.
	 *
	 * @param string $currency The target currency.
	 * @return float|null
	 */
	protected function get_exchange_rate(string $currency): ?float {
		$exchange_rates = $this->get_exchange_rates();

		// If the exchange rate for the source currency is not valid, don't attempt a conversion
		if(!isset($exchange_rates[$currency]) || !is_numeric($this->settings->exchange_rates[$currency]) || ($this->settings->exchange_rates[$currency] <= 0)) {
			$this->log_invalid_exchange_rate($currency);

			return null;
		}
		return $exchange_rates[$currency];
	}

	/**
	 * Logs a message when the exchange rate for a currency could not be found.
	 *
	 * @param string $currency
	 * @return void
	 */
	protected function log_invalid_exchange_rate(string $currency): void {
		static $invalid_exchange_rates = [];

		if(!in_array($currency, $invalid_exchange_rates)) {
			$this->get_logger()->warning(__('Could not find a valid exchange rate for the specified currency.', Definitions::TEXT_DOMAIN), [
				'Invalid Currency' => $currency,
			]);
			$invalid_exchange_rates[] = $currency;
		}
	}

	/**
	 * Indicated if the specified exchange rate is valid.
	 *
	 * @param float $exchange_rate
	 * @return boolean
	 */
	protected static function is_exchange_rate_valid($exchange_rate): bool {
		return is_numeric($exchange_rate) && ($exchange_rate > 0);
	}

	/**
	 * Converts an amount from a Currency to another.
	 *
	 * @param float $amount The amount to convert.
	 * @param string $from_currency The source currency.
	 * @param string $to_currency The destination currency.
	 * @return float The amount converted in the destination currency.
	 */
	public function convert_amount(float $amount, string $from_currency, string $to_currency) {
		static $exchange_rates = [];

		// Generate a key to cache the exchange rate, so that it won't have to be recalculated
		// every time
		$exchange_rate_key = "{$from_currency}_{$to_currency}";

		if(!isset($exchange_rates[$exchange_rate_key])) {
			// Fetch the exchange rate to convert the amount from the shop's base currency to source currency
			$from_currency_exchange_rate = $this->get_exchange_rate($from_currency);
			// Fetch the exchange rate to convert the amount from the shop's base currency to target currency
			$to_currency_exchange_rate = $this->get_exchange_rate($to_currency);

			if(!self::is_exchange_rate_valid($from_currency_exchange_rate) || !self::is_exchange_rate_valid($to_currency_exchange_rate)) {
				throw new \InvalidArgumentException(__('Could not find a valid exchange rate for an order currency.', Definitions::TEXT_DOMAIN));
			}

			// Calculate the exchange rate
			$exchange_rates[$exchange_rate_key] = 1 / $from_currency_exchange_rate * $to_currency_exchange_rate;

			$this->get_logger()->info(__('Calculated exchange rate for currency conversion.', Definitions::TEXT_DOMAIN), [
				'Source Currency' => $from_currency,
				'Target Currency' => $to_currency,
				'Exchange Rate' => $exchange_rates[$exchange_rate_key],
			]);
		}

		// Convert the amount using the found exchange rates
		return $amount * $exchange_rates[$exchange_rate_key];
	}
}

