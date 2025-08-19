<?php
/**
 * WC_Shipstation_API_Request file.
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WC_Shipstation_API_Request Class
 */
abstract class WC_Shipstation_API_Request {

	/**
	 * Stores logger class.
	 *
	 * @var WC_Logger
	 */
	private $log = null;

	/**
	 * Log something.
	 *
	 * @param string $message Log message.
	 */
	public function log( $message ) {
		if ( ! WC_ShipStation_Integration::$logging_enabled ) {
			return;
		}
		if ( is_null( $this->log ) ) {
			$this->log = new WC_Logger();
		}
		$this->log->add( 'shipstation', $message );
	}

	/**
	 * Run the request
	 */
	public function request() {}

	/**
	 * Validate data.
	 *
	 * @param array $required_fields fields to look for.
	 */
	protected function validate_input( $required_fields ) {
		foreach ( $required_fields as $required ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended --- Using WC_ShipStation_Integration::$auth_key for security verification
			if ( empty( $_GET[ $required ] ) ) {
				/* translators: 1: field name */
				$this->trigger_error( sprintf( esc_html__( 'Missing required param: %s', 'woocommerce-shipstation-integration' ), esc_html( $required ) ) );
			}
		}
	}

	/**
	 * Trigger and log an error.
	 *
	 * @param string $message Error message.
	 * @param int    $status_code Error status code.
	 */
	public function trigger_error( $message, $status_code = 400 ) { //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, false positive on function parameter.
		$this->log( $message );
		wp_send_json_error( $message, $status_code );
	}
}
