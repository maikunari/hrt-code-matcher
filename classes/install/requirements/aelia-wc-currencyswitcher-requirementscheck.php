<?php
if(!defined('ABSPATH')) exit; // Exit if accessed directly

require_once('aelia-wc-requirementscheck.php');

/**
 * Checks that plugin's requirements are met.
 */
class Aelia_WC_CurrencySwitcher_RequirementsChecks extends Aelia_WC_RequirementsChecks {
	// @var string The namespace for the messages displayed by the class.
	protected $text_domain = 'woocommerce-aelia-currencyswitcher';
	// @var string The plugin for which the requirements are being checked. Change it in descendant classes.
	protected $plugin_name = 'Aelia Currency Switcher for WooCommerce';

	// @var array An array of PHP extensions required by the plugin
	protected $required_extensions = array(
		'curl',
	);

	// @var array An array of WordPress plugins (name => version) required by the plugin.
	protected $required_plugins = array(
		'WooCommerce' => '3.0',
		'Aelia Foundation Classes for WooCommerce' => array(
			'version' => '2.4.9.230616',
			'extra_info' => 'You can get the plugin <a href="https://bit.ly/WC_AFC_S3">from our site</a>, free of charge.',
			'autoload' => true,
			'url' => 'https://bit.ly/WC_AFC_S3',
		),
	);

	/**
	 * Factory method. It MUST be copied to every descendant class, as it has to
	 * be compatible with PHP 5.2 and earlier, so that the class can be instantiated
	 * in any case and and gracefully tell the user if PHP version is insufficient.
	 *
	 * @return Aelia_WC_AFC_RequirementsChecks
	 */
	public static function factory() {
		$instance = new self();
		return $instance;
	}
}
