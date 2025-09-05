<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Traits\Logger_Trait;
use Aelia\WC\Traits\Singleton;

/**
 * Implements functions to handle the addons for the Currency Switcher,
 * such as the Shipping Pricing addon.
 *
 * @since 4.11.0.210517
 */
class Addons_Manager {
	use Singleton;
	use Logger_Trait;

	public function __construct()	{
		add_action('plugins_loaded', array($this, 'load_addons'));
	}

	/**
	 * Loads addon modules for the Aelia Currency Switcher.
	 *
	 * @return void
	 */
	public function load_addons(): void {
		$addons_dir = WC_Aelia_CurrencySwitcher::addons_dir();

		// Variable $aelia_cs_loading_addons can be checked inside each sub-plugin
		// file, so that the plugin will know if it's being loaded as an addon, or
		// as a standalone plugin
		$aelia_cs_loading_addons = true; // NOSONAR
		$addons = apply_filters('wc_aelia_currency_switcher_addons', array(
			$addons_dir . '/aelia-currencyswitcher-shipping-pricing/aelia-currencyswitcher-shipping-pricing.php',
		));

		foreach($addons as $addon_file_name) {
			if(is_readable($addon_file_name)) {
				require_once $addon_file_name;
			}
			else {
				$this->get_logger()->warning(__('A registered addon could not be loaded.', Definitions::TEXT_DOMAIN), array(
					'Addon File Name' => $addon_file_name,
				));
			}
		}
		$aelia_cs_loading_addons = false;
	}
}