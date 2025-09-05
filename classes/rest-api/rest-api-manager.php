<?php
namespace Aelia\WC\CurrencySwitcher\API;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\API\Controllers\REST_Controller_Settings;
use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\Traits\Logger_Trait;

/**
 * Handles the API controllers and settings introduced by the plugin.
 *
 * @since 5.0.4.230626
 */
class REST_API_Manager {
	use Logger_Trait;

	/**
	 * Stores the singleton instance of this class.
	 *
	 * @var REST_API_Manager
	 */
	protected static $_instance = null;

	/**
	 * The settings used to initialise the instance of this class.
	 *
	 * @var REST_API_Manager_Settings
	 */
	protected $settings;

	/**
	 * An array of the REST controllers handled by this class.
	 *
	 * @var array
	 */
	protected $rest_controllers = [];

	/**
	 * Alias for the instance() method.
	 *
	 * @return Aelia\WC\CurrencySwitcher\API\REST_API_Manager
	 * @since 0.9.2.211109
	 */
	public static function init(REST_API_Manager_Settings $settings): REST_API_Manager {
		static::$_instance = new static($settings);

		return static::$_instance;
	}

	/**
	 * Returns the singleton instance of the class, if initialised.
	 *
	 * @return REST_API_Manager
	 * @throws Aelia\WC\Exceptions\NotInitializedException If the class has not been initialised.
	 */
	public static function instance(): REST_API_Manager {
		if(empty(self::$_instance)) {
			throw new \Aelia\WC\Exceptions\NotInitializedException(sprintf(__('Class not initialized. You must call %1$s::init() before calling %1$s::instance().', Definitions::TEXT_DOMAIN), __CLASS__));
		}

		return self::$_instance;
	}

	/**
	 * Constructor.
	 *
	 * @param REST_API_Manager_Settings $settings
	 */
	public function __construct(REST_API_Manager_Settings $settings) {
		$this->settings = $settings;

		// Initialise the logger, using the instance passed with the settings
		$this->set_logger($settings->logger_instance);
		self::$_debug_mode = $settings->debug_mode;

		// Register the REST endpoint controllers
		$this->register_controllers();
	}

	/**
	 * Constructor.
	 */
	protected function set_hooks(): void	{
	}

	/**
	 * Initializes the REST API provided by the plugin.
	 *
	 * @return void
	 */
	protected function register_controllers(): void {
		// Internal REST endpoints

		// Database Updater endpoint
		// @since 5.0.4.230626
		$this->rest_controllers[] = new Controllers\Admin\Database_Updater\Database_Updater_REST_Controller(new REST_Controller_Settings([
			'plugin_slug' => $this->settings->plugin_slug,
			// @since 5.0.5.230703
			'plugin_version' => $this->settings->plugin_version,
			'debug_mode' => $this->settings->debug_mode,
			'logger_instance' => $this->settings->logger_instance,
		]));

		// External REST endpoints

		// Webhook API controller
		//$this->rest_controllers[] = new Controllers\External\Webhook_REST_Controller($rest_api_settings);
	}
}