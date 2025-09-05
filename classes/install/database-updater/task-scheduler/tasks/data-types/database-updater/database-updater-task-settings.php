<?php
namespace Aelia\WC\CurrencySwitcher\Scheduler\Tasks\Database_Updater;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\AFC\Scheduler\Tasks\Task_Instance_Settings;

/**
 * Describes the settings to be used by a Database_Updater_Task.
 *
 * @since 4.16.0.230623
 */
class Database_Updater_Task_Settings extends Task_Instance_Settings {
	/**
	 * The ID of the plugin for which the database updater task is being executed.
	 *
	 * @var string
	 */
	protected $plugin_slug = null;

	/**
	 * The version of the plugin that generated the settings. Used for
	 * logging.
	 *
	 * @var string
	 * @since 5.0.5.230703
	 */
	protected $plugin_version = null;

	/**
	 * Specifies how many records should be fetched in a single batch of
	 * requests.
	 *
	 * @var int
	 */
	protected $batch_size = Definitions::DATABASE_UPDATER_DEFAULT_BATCH_SIZE;

	/**
	 * The offset to be used to process database rows. Used for tasks that
	 * have to process records, such as orders, in batches.
	 *
	 * @var integer
	 */
	protected $offset = 0;

	/**
	 * The exchange rates configured in the Currency Switcher settings.
	 *
	 * @var array
	 */
	protected $exchange_rates = [];
}
