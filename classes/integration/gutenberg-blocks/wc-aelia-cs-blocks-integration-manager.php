<?php
namespace Aelia\WC\CurrencySwitcher\Integration\Blocks;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Integrations\Blocks\WC\WC_Aelia_CS_WooCommerce_Checkout_Block_Integration;

/**
 * Loads the integration with Gutenberg blocks.
 *
 * @since 4.10.0.210312
 */
class WC_Aelia_CS_Blocks_Integration_Manager {
	/**
	 * Holds a list of the initialised block integrations.
	 *
	 * @var array
	 */
	protected static $_blocks = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		// @since 4.10.0.210312
		self::$_blocks[] = new WC_Aelia_CS_WooCommerce_Checkout_Block_Integration();
	}
}
