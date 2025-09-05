<?php
namespace Aelia\WC\CurrencySwitcher\API;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\API\Controllers\REST_Controller_Settings;
use Aelia\WC\CurrencySwitcher\Traits\Logger_Trait;
use \WP_Error;

/**
 * Authenticates the call made to the REST API provided by the plugin.
 *
 * @since 5.0.4.230626
 */
class REST_Authentication {
	use Logger_Trait;

	/**
	 * The namespace for the REST API.
	 *
	 * @var string
	 */
	protected $namespace = 'aelia/cs';

	/**
	 * The settings passed to the API.
	 *
	 * @var REST_API_Settings
	 */
	protected $settings;

	/**
	 * Authentication error.
	 *
	 * @var WP_Error
	 */
	protected $error = null;

	/**
	 * Logged in user data.
	 *
	 * @var stdClass
	 */
	protected $user = null;

	/**
	 * Initialize authentication actions.
	 */
	public function __construct(REST_Controller_Settings $args) {
		$this->settings = $args;

		$this->set_hooks();
	}

	protected function set_hooks(): void {
		add_filter('determine_current_user', [$this, 'authenticate'], 5);
		add_filter('rest_authentication_errors', [$this, 'authentication_fallback'], 10);
		add_filter('rest_authentication_errors', [$this, 'check_authentication_error'], 15);
		add_filter('rest_post_dispatch', [$this, 'send_unauthorized_headers'], 150);
	}

	/**
	 * Check if is request to our REST API.
	 *
	 * @return bool
	 */
	protected function is_request_to_rest_api(): bool {
		if(empty($_SERVER['REQUEST_URI'])) {
			return false;
		}

		$rest_prefix = trailingslashit(rest_get_url_prefix());
		$request_uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));

		// Check if the request is to our API endpoints.
		return strpos($request_uri, $rest_prefix . $this->namespace) !== false;
	}

	/**
	 * Authenticate the user.
	 *
	 * @param int|false $user_id User ID if one has been determined, false otherwise.
	 * @return int|false
	 */
	public function authenticate($user_id) {
		// Ignore requests that aren't made to our endpoints
		if(!empty($user_id) || !$this->is_request_to_rest_api()) {
			return $user_id;
		}

		return apply_filters('wc_aelia_fi_rest_api_authenticate_user', $user_id, $this);
	}

	/**
	 * Authenticate the user if authentication wasn't performed during the
	 * determine_current_user action.
	 *
	 * Necessary in cases where wp_get_current_user() is called the Freemius integration is loaded.
	 *
	 * @param WP_Error|null|bool $error Error data.
	 * @return WP_Error|null|bool
	 */
	public function authentication_fallback($error) {
		if(!empty($error)) {
			// Another plugin has already declared a failure.
			return $error;
		}

		if(empty($this->error) && empty($this->user) && empty(get_current_user_id())) {
			// Authentication hasn't occurred during `determine_current_user`, so check authentication now
			$user_id = $this->authenticate(false);
			if($user_id) {
				wp_set_current_user($user_id);
				return true;
			}
		}
		return $error;
	}

	/**
	 * Check for authentication error.
	 *
	 * @param WP_Error|null|bool $error Error data.
	 * @return WP_Error|null|bool
	 */
	public function check_authentication_error($error) {
		// Pass through other errors.
		if(!empty($error)) {
			return $error;
		}

		return $this->get_error();
	}

	/**
	 * Set authentication error.
	 *
	 * @param WP_Error $error Authentication error data.
	 */
	public function set_error($error) {
		// Reset user.
		$this->user = null;

		$this->error = $error;
	}

	/**
	 * Get authentication error.
	 *
	 * @return WP_Error|null.
	 */
	protected function get_error() {
		return $this->error;
	}

	/**
	 * If the API token is not provided or invalid, return the correct Basic headers and
	 * an error message.
	 *
	 * @param WP_REST_Response $response Current response being served.
	 * @return WP_REST_Response
	 */
	public function send_unauthorized_headers($response) {
		if(is_wp_error($error = $this->get_error())) {
			$response->header('WWW-Authenticate', 'Basic realm="' . $error->get_error_message() . '"', true);
		}

		return $response;
	}
}
