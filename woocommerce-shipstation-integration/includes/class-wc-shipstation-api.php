<?php
/**
 * Class WC_Shipstation_API file.
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once WC_SHIPSTATION_ABSPATH . 'includes/api-requests/class-wc-shipstation-api-request.php';

/**
 * WC_Shipstation_API Class
 */
class WC_Shipstation_API extends WC_Shipstation_API_Request {

	/**
	 * Stores whether or not shipstation has been authenticated.
	 *
	 * @var boolean
	 */
	private static $authenticated = false;

	/**
	 * Being used to store $_GET variable from ShipStation API request.
	 *
	 * @var array
	 */
	protected $request;

	/**
	 * Constructor
	 */
	public function __construct() {
		nocache_headers();

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', 'true' );
		}

		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', 'true' );
		}

		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', 'true' );
		}

		self::$authenticated = false;

		$this->request();
	}

	/**
	 * Has API been authenticated?
	 *
	 * @return bool
	 */
	public static function authenticated() {
		return self::$authenticated;
	}

	/**
	 * Handle the request
	 */
	public function request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended --- Using WC_ShipStation_Integration::$auth_key for security verification
		if ( empty( $_GET['auth_key'] ) ) {
			$this->trigger_error( esc_html__( 'Authentication key is required!', 'woocommerce-shipstation-integration' ) );
		}

		if ( ! hash_equals( sanitize_text_field( wp_unslash( $_GET['auth_key'] ) ), WC_ShipStation_Integration::$auth_key ) ) {
			$this->trigger_error( esc_html__( 'Invalid authentication key', 'woocommerce-shipstation-integration' ) );
		}

		$request = $_GET;

		if ( isset( $request['action'] ) ) {
			$this->request = array_map( 'sanitize_text_field', $request );
		} else {
			$this->trigger_error( esc_html__( 'Invalid request', 'woocommerce-shipstation-integration' ) );
		}

		self::$authenticated = true;

		if ( in_array( $this->request['action'], array( 'export', 'shipnotify' ), true ) ) {
			$mask = array(
				'auth_key' => '***',
			);

			$obfuscated_request = $mask + $this->request;

			/* translators: 1: query string */
			$this->log( sprintf( esc_html__( 'Input params: %s', 'woocommerce-shipstation-integration' ), http_build_query( $obfuscated_request ) ) );
			$request_class = include WC_SHIPSTATION_ABSPATH . 'includes/api-requests/class-wc-shipstation-api-' . $this->request['action'] . '.php';
			$request_class->request();
		} else {
			$this->trigger_error( esc_html__( 'Invalid request', 'woocommerce-shipstation-integration' ) );
		}

		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}

new WC_Shipstation_API();
