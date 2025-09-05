<?php
/**
 * Registers Dutify Settings
 *
 * @class WOO_DUTIFY_Settings
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * WOO_DUTIFY_Settings Class
 */
class WOO_DUTIFY_Settings {

	/**
	 * Constructor
	 *
	 * @access public
	 */
	public function __construct() {
		// Add/Save Settings tab to WooCommerce Settings
		add_filter('woocommerce_settings_tabs_array', array($this, 'add_settings_tab'), 69);
		add_action('woocommerce_settings_tabs_woo_dutify', array($this, 'add_settings'));
		add_action('woocommerce_update_options_woo_dutify', array($this, 'update_settings'));

		// Custom Field Types
		add_action('woocommerce_admin_field_woo_dutify_title', array($this, 'field_woo_dutify_title'));

		// Custom Field Types Before Save
		add_filter('woocommerce_admin_settings_sanitize_option', array($this, 'woo_dutify_options_before_save'), 10, 3);
	}

	/**
	 * Add Dutify Settings Tab to WooCommerce Settings
	 */
	public static function add_settings_tab($settings_tabs) {
		$settings_tabs['woo_dutify'] = 'Dutify';
        return $settings_tabs;
	}

	/**
	 * Add Dutify Setting Fields to WooCommerce Dutify Settings Tab
	 */
	public static function add_settings() {
		woocommerce_admin_fields(self::get_settings());
	}

	/**
	 * Update Dutify Settings Tab
	 */
	public static function update_settings() {
		woocommerce_update_options(self::get_settings());
	}

	/**
	 * Get Dutify Settings Fields
	 */
	public static function get_settings() {

		$store_address_1 = '';
		$store_address_2 = '';
		$store_city = '';
		$store_postcode = '';
		$store_raw_country = '';
		$split_country = array();
		$store_country = '';
		$store_state = '';
		$store_address = '';

		// The main address pieces:
		$store_address_1   = get_option('woocommerce_store_address');
		$store_address_2   = get_option('woocommerce_store_address_2');
		$store_city        = get_option('woocommerce_store_city');
		$store_postcode    = get_option('woocommerce_store_postcode');

		// The country/state
		$store_raw_country = get_option('woocommerce_default_country');

		// Split the country/state
		$split_country = explode(":", $store_raw_country);

		// Country and state separated:
		$store_country = WC()->countries->countries[ $split_country[0] ] . ' (' . $split_country[0] . ')';
		$store_state   = $split_country[1];

		$store_address_2 ? $store_address_2 . "<br />" : '';
		$store_address .= '<p>We have automatically detected your ship from address:</p>';
		$store_address .= $store_address_1 . "<br />" . $store_address_2 . $store_city . ", " . $store_state . " " . $store_postcode . "<br />" . $store_country;
		$store_address .= '<p>You can change this setting at:<br /><a href="admin.php?page=wc-settings&tab=general">WooCommerce -> Settings -> General -> Store Address</a><p>';

		$settings = array(
			'section_title_step_1' => array(
				'name' => 'Step 1: Activate your Dutify WooCommerce Plugin',
				'type' => 'title',
				'desc' => "Dutify's WooCommerce plugin improves the international shopping experience for your customers by enabling live duty and tax calculation in your checkout. To get started, create your Dutify account <a href='https://dutify.com/partner' target='_blank'>here</a>. Once you've created your Dutify account, copy the API token from your Dutify account settings and and paste it in the API token field below. Next, backfill the classification ID attribute for each product in your catalog so that we know what type of product you are shipping - you can use <a href='https://dutify.com/hs-lookup' target='_blank'>this tool</a> to find the classification ID for a product.<br><br>Once everything is setup, enable settings in the step below to begin capturing duty and taxes.",
				'id' => 'woo_dutify_section_title_step_1'
			),
			'dutify_enabled' => array(
				'name' => 'Enable Dutify',
				'type' => 'checkbox',
				'desc' => 'Enable Dutify integration',
				'id' => 'woo_dutify_is_enabled',
				'default' => 'yes'
			),
			'api_token' => array(
				'name' => 'API Token',
				'type' => 'password',
				'desc' => 'Your Dutify API Token. You can find this in your Dutify account settings (API access section).',
				'id' => 'woo_dutify_api_token'
			),
			'section_end_step_1' => array(
				'type' => 'sectionend',
				'id' => 'woo_dutify_section_end_step_1'
			),
			// Step 2
			'section_title_step_2' => array(
				'name' => 'Step 2: Configure your Dutify settings',
				'type' => 'title',
				'id' => 'woo_dutify_section_title_step_2'
			),
			'duty_calculation' => array(
				'name' => 'Import duty',
				'type' => 'checkbox',
				'desc' => 'Calculate import duty for cross-border orders from your store.',
				'id' => 'woo_dutify_duty_calculation',
				'default' => 'yes'
			),
			'sales_tax_calculation' => array(
				'name' => 'Sales tax',
				'type' => 'checkbox',
				'desc' => 'Calculate sales tax for cross-border orders from your store.',
				'id' => 'woo_dutify_sales_tax_calculation',
				'default' => 'yes'
			),
			'additional_tax_calculation' => array(
				'name' => 'Additional taxes',
				'type' => 'checkbox',
				'desc' => 'Calculate additional taxes for cross-border orders from your store.',
				'id' => 'woo_dutify_additional_tax_calculation',
				'default' => 'yes'
			),
			'excise_tax_calculation' => array(
				'name' => 'Excise taxes',
				'type' => 'checkbox',
				'desc' => 'Calculate excise taxes for cross-border orders from your store.',
				'id' => 'woo_dutify_excise_tax_calculation',
				'default' => 'yes'
			),
			'use_preferential_rates' => array(
				'name' => 'Enable preferential rates',
				'type' => 'checkbox',
				'desc' => 'If enabled and product have country of origin that qualifies for free-trade agreement. Dutify will calculate duty and taxes based on the reduced rates. Note, products must have country of origin defined and you may require a certificate of origin for each product to qualify for preferential rates.',
				'id' => 'woo_dutify_use_preferential_rates'
			),
			'do_not_collect_duty_tax' => array(
				'name' => 'Do not collect duty & taxes',
				'type' => 'checkbox',
				'desc' => "If enabled, duty and taxes will be calculated and displayed, but they won't be charged. Use this setting if you do not intend to ship Delivery Duty Paid (DDP). Please also note that if this setting is enabled, Handling Fees will not be added or displayed even if they are configured in your Dutify account.",
				'id' => 'woo_dutify_use_ddu'
			),
			'section_end_step_2' => array(
				'type' => 'sectionend',
				'id' => 'woo_dutify_section_end_step_2'
			),
			// Step 3
			'section_title_step_3' => array(
				'name' => 'Step 4: Additional configuration',
				'type' => 'title',
				'id' => 'woo_dutify_section_title_step_3'
			),
			'only_checkout_calculations' => array(
				'name' => 'Checkout only',
				'type' => 'checkbox',
				'desc' => 'If enabled, Dutify will calculate duty and taxes on the checkout page only. If disabled, Dutify will only calculate duty and taxes on the cart and checkout pages.',
				'id' => 'woo_dutify_only_checkout_calculations',
				'default' => 'no'
			),
			'duty_tax_label' => array(
				'name' => 'Duty & Tax Label',
				'type' => 'text',
				'desc' => 'This will change the label used for the duty and taxes row on the cart, checkout and order pages.',
				'default' => 'Duty & Taxes',
				'id' => 'woo_dutify_duty_tax_label',
			),
			'handling_fee_label' => array(
				'name' => 'Handling Fee Label',
				'type' => 'text',
				'desc' => 'Note: This is only applicable if you are using the Dutify handling fee feature, otherwise leave blank. When set, the plugin will display the handling fee as an independent row during the checkout. Alternatively, if the handling fee is configured in your Dutify account and this value is not selected, it will be combined with duty and taxes.',
				'id' => 'woo_dutify_handling_fee_label',
				'default' => 'Handling Fee'
			),
			'duty_tax_ddu_label' => array(
				'name' => 'Do Not Collect Duty & Tax Label',
				'type' => 'text',
				'desc' => 'This will change the labels used for the DDU orders.',
				'id' => 'woo_dutify_duty_tax_ddu_label',
				'default' => 'Duty & Taxes (payable on delivery)'
			),
			'section_end_step_3' => array(
				'type' => 'sectionend',
				'id' => 'woo_dutify_section_end_step_3'
			),
			// Step 4
			'section_title_step_4' => array(
				'name' => 'Ship From Address',
				'type' => 'title',
				'desc' => $store_address,
				'id' => 'woo_dutify_section_title_step_4'
			),
			'section_end_step_4' => array(
				'type' => 'sectionend',
				'id' => 'woo_dutify_section_end_step_4'
			),
		);

		return apply_filters('woo_dutify_setting_fields', $settings);
	}



	/**
	 * Add custom field type - woo_dutify_title
	 */
	public static function field_woo_dutify_title($value) {
		?><tr valign="top">
			<th scope="row" class="titledesc" colspan="2">
				<h4 class="woo-dutify-section-title">
					<?php echo esc_html($value['title']); ?>
				</h4>
			</th>
		</tr><?php
	}


	/**
	 * Filter Custom Setting Field Types before Save
	 */
	public static function woo_dutify_options_before_save($value, $option, $raw_value) {

		if(in_array($option['id'], array('woo_dutify_api_token')))
		{
			//Change $value as per your wish before saving to database;
		}

		return $value;
	}

}

new WOO_DUTIFY_Settings();
