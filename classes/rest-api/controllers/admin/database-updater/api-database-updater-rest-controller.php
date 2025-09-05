<?php
namespace Aelia\WC\CurrencySwitcher\API\Controllers\Admin\Database_Updater;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use \WP_REST_Server;
use \WP_REST_Request;
use \WP_REST_Response;
use \WP_Error;
use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\API\Controllers\Base_REST_Controller;
use Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher;

/**
 * Handles the calls made to the REST API to trigger the import of order data.
 *
 * @since 5.0.4.230626
 */
class Database_Updater_REST_Controller extends Base_REST_Controller {
	/**
	 * Registers the routes handled by the controller.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$namespace = $this->namespace . '/' . $this->version;
		register_rest_route($namespace, '/import-order-data', [
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => [$this, 'import_order_data'],
			'permission_callback' => function(WP_REST_Request $request) {
				return $this->validate_api_request($request);
			},
			//'args'                => $this->get_endpoint_args_for_item_schema( false ),
		]);
	}

	/**
	 * Validates an API request. If valid, it sets the under which the request should be processed.
	 *
	 * @param WP_REST_Request $request
	 * @return void
	 */
	public function validate_api_request(WP_REST_Request $request) {
		$request_valid = true;

		if(!current_user_can('manage_woocommerce')) {
			$this->get_logger()->warning(__('The user does not have the permission to use this API.', Definitions::TEXT_DOMAIN), [
				'Request' => $request,
				'Current User' => wp_get_current_user(),
			]);
			$request_valid = false;
		}

		return $request_valid;
	}

  /**
   * S one item from the collection.
   *
   * @param WP_REST_Request $request Full data about the request.
   * @return WP_Error|WP_REST_Response
   */
  public function import_order_data(WP_REST_Request $request) {
		// Build the URL to open the page showing the pending updates
		$pending_actions_url = admin_url('admin.php?page=wc-status&tab=action-scheduler&status=pending&s=' . WC_Aelia_CurrencySwitcher::$plugin_slug);

		// If action "aelia_cs_database_updater_run_updates" was already triggered, stop here.
		// There's no need to trigger it twice
		if(did_action('aelia_cs_database_updater_run_updates') || apply_filters('wc_aelia_cs_order_data_import_running', false)) {
			$this->get_logger()->notice(implode(' ', [
				__('The import of sales data from orders is already running.', Definitions::TEXT_DOMAIN),
				__('Skipping the operation.', Definitions::TEXT_DOMAIN),
			]), [
				'Request' => $request,
				'Current User' => wp_get_current_user(),
			]);

			// Prpepare the response
			$response = new WP_REST_Response([
				'message' => sprintf(
					wp_kses_post(implode(' ', [
						__('The import of sales data from orders is already running.', Definitions::TEXT_DOMAIN),
						/* translators: %1$s: Javascript to reload the page */
						/* translators: %2$s: URL to the pending Actions on the Action Scheduler page */
						__('To see the import status, please <a href="%1$s">reload this page</a> or <a href="%2$s">go to the Scheduled Tasks page</a>.', Definitions::TEXT_DOMAIN),
				])), 'javascript:window.location.reload(true)',	$pending_actions_url),
			], 200);
		}
		else {
			$this->get_logger()->notice(implode(' ', [
				__('Triggering action "aelia_cs_database_updater_run_updates" to start the import.', Definitions::TEXT_DOMAIN),
			]), [
				'Request' => $request,
				'Current User' => wp_get_current_user(),
			]);

			// Trigger the action that will reset the install/upgrade process. This will also import the
			// sales data from the orders
			do_action('aelia_cs_database_updater_run_updates', true);

			// Prpepare the response
			$response = new WP_REST_Response([
				'message' => sprintf(
					wp_kses_post(implode(' ', [
						__('The import of sales data has been scheduled.', Definitions::TEXT_DOMAIN),
						/* translators: %1$s: Javascript to reload the page */
						/* translators: %2$s: URL to the pending Actions on the Action Scheduler page */
						__('To see the import status, please <a href="%1$s">reload this page</a> or <a href="%2$s">go to the Scheduled Tasks page</a>.', Definitions::TEXT_DOMAIN),
				])), 'javascript:window.location.reload(true)',	$pending_actions_url),
			], 200);
		}

		return $response;
  }
}
