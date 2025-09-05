<?php
namespace Aelia\WC\CurrencySwitcher\Integration\Blocks;

use Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher;

if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

/**
 * Base class to implement support for Gutenberg blocks.
 *
 * @since 4.10.0.210312
 */
abstract class WC_Aelia_CS_Base_Block_Integration {
	/**
	 * Returns the instance of the Currency Switcher.
	 *
	 * @return Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher
	 */
	protected function cs() {
		return WC_Aelia_CurrencySwitcher::instance();
	}

	/**
	 * Returns the instance of the logger to be used by the class.
	 *
	 * @return @return Aelia\WC\Logger
	 */
	protected function get_logger() {
		return $this->cs()->get_logger();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Dummy, implemented for consistency with other classes.
	}


	/**
	 * Indicates if a rest request matches a given route.
	 *
	 * @param array $routes
	 * @return boolean
	 * @since 4.13.4.220315
	 */
	protected static function rest_request_matches_route(\WP_Rest_Request $request, array $routes): bool {
		// Extract the URI, which will indicate the request being handled
		return in_array(trailingslashit($request->get_route()), $routes);
	}
}
