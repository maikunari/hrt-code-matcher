<?php
namespace Aelia\WC\CurrencySwitcher\Install;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Base_Class_Settings;

/**
 * Describes the settings to be used by a the Database_Update_Manager class.
 *
 * @since 4.16.0.230623
 */
class Install_Manager_Settings extends Base_Class_Settings {
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
}
