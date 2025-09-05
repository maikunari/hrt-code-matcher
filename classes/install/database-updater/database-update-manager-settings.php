<?php
namespace Aelia\WC\CurrencySwitcher\Install\Database;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Base_Class_Settings;
use Aelia\WC\CurrencySwitcher\Definitions;

/**
 * Describes the settings to be used by a the Database_Update_Manager class.
 *
 * @since 4.16.0.230623
 */
class Database_Update_Manager_Settings extends Base_Class_Settings {
	/**
	 * The ID of the plugin for which the database updater task is being executed.
	 *
	 * @var string
	 */
	protected $plugin_slug = null;

	/**
	 * The version of the plugin that generated the settings. Used for
	 * logging and to compare the database version.
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

	/*
	 * The task scheduler to be used to run the updates in the background.
	 *
	 * @var Aelia\WC\AFC\Scheduler\Task_Scheduler
	 */
	protected $task_scheduler;
}
