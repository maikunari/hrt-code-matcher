<?php
namespace Aelia\WC\CurrencySwitcher\API;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Base_Class_Settings;

/**
 * Describes the arguments to be passed by instances of the REST API class.
 *
 * @since 5.0.4.230626
 */
class REST_API_Manager_Settings extends Base_Class_Settings {
	/**
	 * The ID of the plugin that generated the settings. Used for
	 * logging.
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
