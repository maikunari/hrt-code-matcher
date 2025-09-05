<?php
namespace Aelia\WC\CurrencySwitcher\Install;

use Aelia\WC\CurrencySwitcher\Install\Database\Database_Update_Manager;
use Aelia\WC\CurrencySwitcher\Install\Database\Database_Update_Manager_Settings;
use Aelia\WC\CurrencySwitcher\Traits\Logger_Trait;

if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

/**
 * Helper class to handle installation and update operations required by the
 * plugin.
 *
 * @since 4.16.0.230623
 */
class Install_Manager {
	use Logger_Trait;

	/**
	 * Stores the install manager settings.
	 *
	 * @var Install_Manager_Settings
	 */
	protected $settings;

	/**
	 * Initializes the install manager and runs the task require to complete the
	 * installation and update the database.
	 *
	 * @param Install_Manager_Settings $settings
	 * @return void
	 */
	public static function init(Install_Manager_Settings $settings): void {
		$install_manager = new static($settings);

		// Ensure that the woocommerce_init event has been executed, before performing
		// operations that depend on it
		// @since 5.1.3.240205
		if(did_action('woocommerce_init')) {
			// Update the database
			$install_manager->run_database_updates();
		}
	}

	/**
	 * Constructor.
	 *
	 * @param Install_Manager_Settings $settings
	 */
	public function __construct(Install_Manager_Settings $settings) {
		$this->settings = $settings;

		// Initialise the logger, using the instance passed with the settings
		$this->set_logger($settings->logger_instance);
		self::$_debug_mode = $settings->debug_mode;
	}

	/**
	 * Executes the tasks to update the database.
	 *
	 * @return void
	 */
	public function run_database_updates(): void {
		Database_Update_Manager::run_updates(new Database_Update_Manager_Settings([
			'plugin_slug' => $this->settings->plugin_slug,
			// @since 5.0.5.230703
			'plugin_version' => $this->settings->plugin_version,
			'logger_instance' => $this->settings->logger_instance,
			'debug_mode' => $this->settings->debug_mode,
			'task_scheduler' => aelia_get_task_scheduler(),
		]));
	}

}
