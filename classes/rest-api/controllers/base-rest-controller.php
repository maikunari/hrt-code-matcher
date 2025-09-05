<?php
namespace Aelia\WC\CurrencySwitcher\API\Controllers;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use \WP_REST_Controller;
use Aelia\WC\CurrencySwitcher\API\REST_Authentication;
use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\Traits\Logger_Trait;

/**
 * A base class for a REST controller.
 *
 * @since 5.0.4.230626
 */
class Base_REST_Controller extends WP_REST_Controller {
	use Logger_Trait;

	protected $namespace = Definitions::REST_NAMESPACE;
	protected $version = 'v1';

	/**
	 * The settings passed to the API.
	 *
	 * @var REST_Controller_Settings
	 */
	protected $settings;

	/**
	 * The instance of the REST Authentication class.
	 *
	 * @var Aelia\WC\CurrencySwitcher\API\REST_Authentication
	 */
	protected static $_rest_authentication;

	/**
	 * Constructor.
	 *
	 * @param REST_Controller_Settings $settings
	 */
	public function __construct(REST_Controller_Settings $settings) {
		$this->settings = $settings;

		// Initialise the logger, using the instance passed with the settings
		$this->set_logger($settings->logger_instance);
		self::$_debug_mode = $settings->debug_mode;

		// Initialize the authentication class only once
		if(empty(self::$_rest_authentication)) {
			self::$_rest_authentication = new REST_Authentication($settings);
		}

		// Method Base_REST_Controller::register_routes() will be overridden by descendant classes.
		$this->register_routes();

		// Set the actions and filters required by the class
		$this->set_hooks();
	}

	/**
	 * Sets the actions and filters required by the class.
	 */
	protected function set_hooks(): void {
		// To be implemented by descendant classes
	}
}