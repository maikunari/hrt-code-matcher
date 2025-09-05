<?php if(!defined('ABSPATH')) { exit; } // Exit if accessed directly
/*
Plugin Name: Aelia Currency Switcher for WooCommerce
Plugin URI: https://aelia.co/shop/currency-switcher-woocommerce/
Description: WooCommerce Currency Switcher. Allows to switch currency on the fly and perform all transactions in such currency.
Author: Aelia
Author URI: https://aelia.co
Version: 5.2.9.250523
Update URI: https://api.freemius.com
License: GPLv3 (https://www.gnu.org/licenses/gpl-3.0.html)
Text Domain: woocommerce-aelia-currencyswitcher
Domain Path: /languages
WC requires at least: 3.0
WC tested up to: 9.9
Requires PHP: 7.1
*/

require_once __DIR__ . '/src/lib/classes/install/requirements/aelia-wc-currencyswitcher-requirementscheck.php';

// If requirements are not met, deactivate the plugin
if(Aelia_WC_CurrencySwitcher_RequirementsChecks::factory()->check_requirements()) {
	require_once dirname(__FILE__) . '/src/plugin-main.php';

	// Register this plugin file for auto-updates, if such capability exists
	if(!empty($GLOBALS['woocommerce-aelia-currencyswitcher']) && method_exists($GLOBALS['woocommerce-aelia-currencyswitcher'], 'set_main_plugin_file')) {
		// Set the path and name of the main plugin file (i.e. this file), for update
		// checks. This is needed because this is the main plugin file, but the updates
		// will be checked from within plugin-main.php
		$GLOBALS['woocommerce-aelia-currencyswitcher']->set_main_plugin_file(__FILE__);

		
		$GLOBALS['woocommerce-aelia-currencyswitcher']->init_freemius();
		
	}
}

// Declare support for HPOS tables
// @since 5.0.0.230203
add_action('before_woocommerce_init', function() {
	if(function_exists('aelia_declare_feature_support')) {
		aelia_declare_feature_support(__FILE__, 'custom_order_tables', true);
	}
});
