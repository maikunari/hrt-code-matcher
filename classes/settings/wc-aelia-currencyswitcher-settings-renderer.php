<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\Integrations\Freemius\Freemius_Plugin_Integration;
use Aelia\WC\IP2Location;
use InvalidArgumentException;

/**
 * Implements a class that will render the settings page.
 */
class Settings_Renderer extends \Aelia\WC\Settings_Renderer {
	// @var string The URL to the support portal.
	const SUPPORT_URL = 'https://aelia.freshdesk.com/support/home';
	// @var string The URL to the contact form for general enquiries.
	const CONTACT_URL = 'https://aelia.co/contact/';

	/*** Settings Tabs ***/
	const TAB_GENERAL = 'general';
	const TAB_GEOLOCATION = 'geolocation';
	const TAB_PAYMENT_GATEWAYS = 'paymentgateways';
	const TAB_CURRENCY_SELECTION = 'currencyselection';
	const TAB_DOCUMENTATION = 'documentation';
	const TAB_SUPPORT = 'support';
	// @since 4.11.1.210520
	const TAB_LICENSE = 'license';
	// @since 4.13.0.220104
	const TAB_CURRENCY_COUNTRY_MAPPING = 'currency_country_mapping';

	/*** Settings sections ***/
	// @var string The ID of the Section containing Enabled Currencies settings
	const SECTION_CURRENCIES = 'currencies_section';
	// @var string The ID of the Section containing Exchange Rates settings
	const SECTION_EXCHANGE_RATES = 'exchange_rates_section';
	// @var string The ID of the Section containing Exchange Rates Auto Update settings
	const SECTION_EXCHANGE_RATES_UPDATE = 'exchange_rates_section_update';

	const SECTION_OPENEXCHANGERATES_SETTINGS = 'openexchangerates_section';
	const SECTION_IPGEOLOCATION_SETTINGS = 'ipgeolocation_section';
	const SECTION_PAYMENT_GATEWAYS_SETTINGS = 'paymentgateways_section';
	const SECTION_CURRENCY_SELECTION_WIDGETS = 'currencyselection_widgets_section';
	const SECTION_SUPPORT = 'support_section';

	
	// @since 4.11.1.210520
	const SECTION_FREEMIUS_ACCOUNT = 'freemius_account_section';
	const SECTION_FREEMIUS_PRICING = 'freemius_pricing_section';
	const SECTION_FREEMIUS_CONTACT = 'freemius_contact_section';
	

	// @since 4.13.0.220104
	const SECTION_CURRENCIES_COUNTRIES_MAPPING = 'currencies_countries_mapping_section';

	/**
	 * Event handler, fired when setting page is loaded.
	 */
	public function options_page_load() {
		if(!WC_Aelia_CurrencySwitcher::doing_ajax() && ($_GET['settings-updated'] ?? false)) {
      // Plugin settings have been saved. Display a message, or do anything you like.
			do_action('wc_aelia_currencyswitcher_settings_saved');
		}
	}

	/**
	 * Returns the tabs to be used to render the Settings page.
	 *
	 * @since 4.12.4.210805
	 */
	protected function get_settings_tabs() {
		return [
			// General settings
			self::TAB_GENERAL => [
				'id' => self::TAB_GENERAL,
				'label' => __('General', Definitions::TEXT_DOMAIN),
				'priority' => 100,
			],
			// Geolocation settings
			self::TAB_GEOLOCATION => [
				'id' => self::TAB_GEOLOCATION,
				'label' => __('Geolocation', Definitions::TEXT_DOMAIN),
				'priority' => 110,
			],
			// Payment gateways settings
			self::TAB_PAYMENT_GATEWAYS => [
				'id' => self::TAB_PAYMENT_GATEWAYS,
				'label' => __('Payment Gateways', Definitions::TEXT_DOMAIN),
				'priority' => 120,
			],
			// Currency selection settings
			self::TAB_CURRENCY_SELECTION => [
				'id' => self::TAB_CURRENCY_SELECTION,
				'label' => __('Currency Selection', Definitions::TEXT_DOMAIN),
				'priority' => 130,
			],
			// Currency/country mapping
			// @since 4.13.0.220104
			self::TAB_CURRENCY_COUNTRY_MAPPING => [
				'id' => self::TAB_CURRENCY_COUNTRY_MAPPING,
				'label' => __('Currency/Country Mapping', Definitions::TEXT_DOMAIN),
				'priority' => 135,
			],
			// Support
			self::TAB_SUPPORT => [
				'id' => self::TAB_SUPPORT,
				'label' => __('Support', Definitions::TEXT_DOMAIN),
				'priority' => 150,
			],
			
			// Freemius License tab
			// @since 4.11.1.210520
			self::TAB_LICENSE => [
				'id' => self::TAB_LICENSE,
				'label' => __('License', Definitions::TEXT_DOMAIN),
				'priority' => 160,
			],
			
		];
	}

	/**
	 * Returns the plugin settings sections.
	 *
	 * @since 4.12.4.210805
	 */
	protected function get_settings_sections() {
		$settings_sections = [ // NOSONAR
			self::TAB_GENERAL => [
				// Currencies section
				[
					'id' => self::SECTION_CURRENCIES,
					'label' => __('Enabled Currencies', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'enabled_currencies_section_callback'),
					'priority' => 100,
				],
				// Exchange Rates section
				[
					'id' => self::SECTION_EXCHANGE_RATES,
					'label' => __('Currency Settings', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'exchange_rates_settings_section_callback'),
					'priority' => 110,
				],
				// Exchange Rates auto-update section
				[
					'id' => self::SECTION_EXCHANGE_RATES_UPDATE,
					'label' => __('Exchange Rates - Automatic Update Settings', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'exchange_rates_update_section_callback'),
					'priority' => 120,
				],
				// Open Exchange Rates provider section
				[
					'id' => self::SECTION_OPENEXCHANGERATES_SETTINGS,
					'label' => __('Open Exchange Rates Settings', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'openexchangerates_settings_section_callback'),
					'priority' => 130,
				],
			],
			self::TAB_GEOLOCATION => [
				// Geolocation section
				[
					'id' => self::SECTION_IPGEOLOCATION_SETTINGS,
					'label' => __('Currency Geolocation Settings', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'ipgeolocation_settings_section_callback'),
					'priority' => 140,
				],
			],
			self::TAB_PAYMENT_GATEWAYS => [
				// Payment Gateways section
				[
					'id' => self::SECTION_PAYMENT_GATEWAYS_SETTINGS,
					'label' => __('Payment Gateways Settings', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'paymentgateways_settings_section_callback'),
					'priority' => 150,
				],
			],
			self::TAB_CURRENCY_SELECTION => [
				// Currency Selection section
				[
					'id' => self::SECTION_CURRENCY_SELECTION_WIDGETS,
					'label' => __('Currency selection widgets', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'currencyselection_widgets_settings_section_callback'),
					'priority' => 160,
				],
			],
			// @since 4.13.0.220104
			self::TAB_CURRENCY_COUNTRY_MAPPING => [
				// Currency/Country Mapping
				[
					'id' => self::SECTION_CURRENCIES_COUNTRIES_MAPPING,
					'label' => __('Currency/Country Mapping ', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'currency_countries_section_callback'),
					'priority' => 160,
				],
			],
			self::TAB_SUPPORT => [
				// Legacy Licensing feature removed

				
				// Freemius Support section
				// @since 4.11.1.210520
				[
					'id' => self::SECTION_SUPPORT,
					'label' => __('Troubleshooting', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'freemius_support_section_callback'),
					'priority' => 180,
				],
				// Freemius Contact section
				// @since 4.11.1.210520
				[
					'id' => self::SECTION_FREEMIUS_CONTACT,
					'label' => __('Contact', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'freemius_contact_section_callback'),
					'priority' => 190,
				],
				
			],
			
			self::TAB_LICENSE => [
				// Account section
				// @since 4.11.1.210520
				[
					'id' => self::SECTION_FREEMIUS_ACCOUNT,
					'label' => __('Account and licence', Definitions::TEXT_DOMAIN),
					'callback' => array($this, 'freemius_account_section_callback'),
					'priority' => 300,
				],
			],
			
		];

		
		// TODO Add rendering of pricing section when the bugs affecting that page are fixed
		// @link https://github.com/Freemius/wordpress-sdk/issues/498
		// $fs_integration = Freemius_Plugin_Integration::get_freemius_integration(Definitions::PLUGIN_SLUG);
		// if(isset($fs_integration) && $fs_integration->is_pricing_page_visible()) {
		// 	$settings_sections[self::TAB_LICENSE][] = [
		// 		'id' => self::SECTION_FREEMIUS_PRICING,
		// 		'label' => __('Plans and pricing', Definitions::TEXT_DOMAIN),
		// 		'callback' => array($this, 'account_pricing_callback'),
		// 		'priority' => 350,
		// 	];
		// }
		

		return $settings_sections;
	}

	/**
	 * Transforms an array of currency codes into an associative array of
	 * currency code => currency description entries. Currency labels are retrieved
	 * from the list of currencies available in WooCommerce.
	 *
	 * @param array currencies An array of currency codes.
	 * @return array
	 */
	protected function add_currency_labels(array $currencies) {
		$woocommerce_currencies = get_woocommerce_currencies();
		$result = [];
		foreach($currencies as $currency_code) {
			$result[$currency_code] = $woocommerce_currencies[$currency_code] ?? sprintf(__('Label not found for currency "%s"', Definitions::TEXT_DOMAIN), $currency_code);
		}

		return $result;
	}

	/**
	 * Returns a sample URL to illustrate of the "currency by URL" feature works.
	 *
	 * @return string
	 * @since 4.12.4.210805
	 */
	protected static function get_sample_currency_selection_url(): string {
		$shop_page_id = wc_get_page_id('shop');
		$shop_url = get_permalink($shop_page_id);
		if(strpos($shop_url, '?') !== false) {
			$shop_url .= '&';
		}
		else {
			$shop_url .= '?';
		}
		$shop_url .= Definitions::ARG_CURRENCY . '=USD';
		return "<a href=\"$shop_url\">$shop_url</a>";
	}

	/**
	 * Configures the plugin settings fields.
	 *
	 * @return array
	 * @since 4.12.4.210805
	 */
	protected function get_settings_fields(): array { // NOSONAR
		// Load currently enabled currencies. WooCommerce default currency is always enabled
		$enabled_currencies = array_unique(array_merge($this->_settings_controller->get_enabled_currencies(), [$this->_settings_controller->base_currency()]));

		// Fetch the details of the automatic update of exchange rates
		// @since 4.12.4.210805
		$schedule_info = $this->_settings_controller->get_exchange_rates_schedule_info();

		return [
			self::SECTION_CURRENCIES => [
				// "Enabled Currencies" field
				[
					'id' => Settings::FIELD_ENABLED_CURRENCIES,
					'label' => implode(' ', [
						__('Select the Currencies that you would like to accept and click on the "Save" button.', Definitions::TEXT_DOMAIN),
						__('After saving, the list of currencies will be updated with your selection.', Definitions::TEXT_DOMAIN),
					]),
					'description' => implode(' ', [
						'<strong>' . __('Note', Definitions::TEXT_DOMAIN) . '</strong>:',
						sprintf(__('WooCommerce base currency (%s) will be enabled automatically.', Definitions::TEXT_DOMAIN), $this->_settings_controller->base_currency()),
					]),
					'css_class' => '',
					'attributes' => [
						'multiple' => 'multiple',
					],
					'type' => 'dropdown',
					'options' => $this->_settings_controller->woocommerce_currencies(),
				],
			],
			// Exchange rates section
			self::SECTION_EXCHANGE_RATES => [
				// Exchange Rates table
				[
					'id' => Settings::FIELD_EXCHANGE_RATES,
					'label' => __('Set the Exchange Rates for each currency.', Definitions::TEXT_DOMAIN),
					'description' => '',
					'css_class' => Settings::FIELD_EXCHANGE_RATES,
					'attributes' => [
						'settings_key' => $this->_settings_key,
						'exchange_rates' => $this->current_settings(Settings::FIELD_EXCHANGE_RATES, $this->default_settings(Settings::FIELD_EXCHANGE_RATES, [])),
						'id' => Settings::FIELD_EXCHANGE_RATES,
						'label_for' => Settings::FIELD_EXCHANGE_RATES,
						'attributes' => [
							'class' => Settings::FIELD_EXCHANGE_RATES,
						],
					],
					'type' => 'custom',
					'render_callback' => [$this, 'render_exchange_rates_options'],
				],
			],
			self::SECTION_EXCHANGE_RATES_UPDATE => [
				// Enable automatic update of exchange rates
				[
					'id' => Settings::FIELD_EXCHANGE_RATES_UPDATE_ENABLE,
					'label' => __('Tick this box to enable automatic updating of exchange rates.', Definitions::TEXT_DOMAIN),
					'description' => '',
					'css_class' => '',
					'attributes' => [],
					'type' => 'checkbox',
				],
				// Exchange Rates Schedule options
				[
					'id' => Settings::FIELD_EXCHANGE_RATES_UPDATE_SCHEDULE,
					'label' => implode(' ', [
						__('Select how often you would like to update the exchange rates.', Definitions::TEXT_DOMAIN),
						'<br /><strong>' . __('Important', Definitions::TEXT_DOMAIN) . '</strong>:',
						__('We recommend not to exceed "once per hour". Once or twice per day should be sufficient.', Definitions::TEXT_DOMAIN),
					]),
					'description' => implode(' ', [
						'<p>',
						__('Last update', Definitions::TEXT_DOMAIN) . ': ',
						sprintf('<span id="last_exchange_rates_update">%s</span>', $schedule_info['last_update']),
						'</p>',
						'<p>',
						__('Next update', Definitions::TEXT_DOMAIN) . ': ',
						sprintf('<span id="next_exchange_rates_update">%s</span>', $schedule_info['next_update']),
						'</p>',
					]),
					'css_class' => 'exchange_rates_schedule',
					'attributes' => [],
					'type' => 'dropdown',
					'options' => $this->_settings_controller->get_schedule_options(),
				],
				// Exchange Rates Provider
				[
					'id' => Settings::FIELD_EXCHANGE_RATES_PROVIDER,
					'label' => __('Exchange rates provider', Definitions::TEXT_DOMAIN),
					'description' => __('Select the provider from which the exchange rates will be fetched.', Definitions::TEXT_DOMAIN),
					'css_class' => Settings::FIELD_EXCHANGE_RATES_PROVIDER . ' exchange_rates_provider',
					'attributes' => [
					],
					'type' => 'dropdown',
					'options' => $this->_settings_controller->exchange_rates_providers_options(),
				],
			],
			// Open Exchange Rates section
			self::SECTION_OPENEXCHANGERATES_SETTINGS => [
				// Open Exchange Rates API key
				[
					'id' => Settings::FIELD_OPENEXCHANGE_API_KEY,
					'label' => __('Open Exchange Rates API Key', Definitions::TEXT_DOMAIN),
					'description' => implode(' ', [
						'<strong>' . __('An API key, available free of charge, is required to use the Open Exchange Rates service.', Definitions::TEXT_DOMAIN) . '</strong></br>',
						sprintf(__('If you do not have an API Key, please visit <a href="%1$s">%1$s</a> to register and get a free key.', Definitions::TEXT_DOMAIN), 'https://openexchangerates.org/signup/free'),
						'<br/>',
						__('Alternatively, please select a different exchange rates provider, such as OFX, which does not require an API key.', Definitions::TEXT_DOMAIN)
					]),
					'css_class' => '',
					'attributes' => [],
					'type' => 'text',
				],
			],
			// Geolocation section
			self::SECTION_IPGEOLOCATION_SETTINGS => [
				// Enable geolocation
				[
					'id' => Settings::FIELD_IPGEOLOCATION_ENABLED,
					'label' => __('Enable automatic selection of Currency depending on Visitors\' location.', Definitions::TEXT_DOMAIN),
					'description' => '',
					'css_class' => '',
					'attributes' => [],
					'type' => 'checkbox',
				],
				// Geolocation default currency
				[
					'id' => Settings::FIELD_IPGEOLOCATION_DEFAULT_CURRENCY,
					'label' => __('Default currency', Definitions::TEXT_DOMAIN),
					'description' => __('Select the currency to use by default when a visitor comes from a country whose currency ' .
															'is not supported by your site, or when the geolocation resolution fails.', Definitions::TEXT_DOMAIN),
					'css_class' => '',
					'attributes' => [
					],
					'type' => 'dropdown',
					'options' => $this->add_currency_labels($enabled_currencies),
				],
			],
			// Payment gateways section
			self::SECTION_PAYMENT_GATEWAYS_SETTINGS => [
				// Payment gateways by currency
				[
					'id' => Settings::FIELD_PAYMENT_GATEWAYS,
					'label' => __('Set the payment gateways available when paying in each currency.', Definitions::TEXT_DOMAIN),
					'description' => '',
					'css_class' => Settings::FIELD_PAYMENT_GATEWAYS,
					'attributes' => [
						'settings_key' => $this->_settings_key,
						'enabled_currencies' => $enabled_currencies,
						'payment_gateways' => $this->current_settings(Settings::FIELD_PAYMENT_GATEWAYS),
						'id' => Settings::FIELD_PAYMENT_GATEWAYS,
						'label_for' => Settings::FIELD_PAYMENT_GATEWAYS,
						'attributes' => [
							'class' => Settings::FIELD_PAYMENT_GATEWAYS,
						],
					],
					'type' => 'custom',
					'render_callback' => [$this, 'render_payment_gateways_options'],
				],
			],
			// Currency selection section
			self::SECTION_CURRENCY_SELECTION_WIDGETS => [
				// Enable currency selection via the URL
				[
					'id' => Settings::FIELD_CURRENCY_VIA_URL_ENABLED,
					'label' => __('Allow to select a currency via the page URL', Definitions::TEXT_DOMAIN),
					'description' => implode(' ', [
						__('When enabled, it allows to select a currency by passing it via the URL.', Definitions::TEXT_DOMAIN),
						sprintf(__('For example, %s would select USD.', Definitions::TEXT_DOMAIN), self::get_sample_currency_selection_url()),
					]),
					'css_class' => '',
					'attributes' => [],
					'type' => 'checkbox',
				],
				// Force currency by country option
				[
					'id' => Settings::FIELD_FORCE_CURRENCY_BY_COUNTRY,
					'label' => __('Force currency selection by customer country', Definitions::TEXT_DOMAIN),
					'description' => implode(' ', [
						__('When enabled, it forces the shop currency to the one in use in the country '.
							'selected by the customer. This option also adds a new widget that allows ' .
							'customers to choose the country before reaching the checkout page, ' .
							'showing them the prices in the appropriate currency while they browse the site ',
							Definitions::TEXT_DOMAIN),
						'<br /><br />',
						__('<strong>Important</strong>: if you enable this option, <strong>do not use the currency ' .
							 'selector widget, or the currency selection via URL.</strong>',
							 Definitions::TEXT_DOMAIN),
						__('Any currency selection made via the selector widget, or via URL arguments will be ' .
							 "ignored, and the currency will always be set based on customer's country.",
							 Definitions::TEXT_DOMAIN),
					]),
					'css_class' => '',
					'attributes' => [
					],
					'type' => 'dropdown',
					'options' => [
						Settings::OPTION_DISABLED => __('Disabled', Definitions::TEXT_DOMAIN),
						Settings::OPTION_BILLING_COUNTRY => __('Billing country', Definitions::TEXT_DOMAIN),
						Settings::OPTION_SHIPPING_COUNTRY => __('Shipping country', Definitions::TEXT_DOMAIN),
					],
				],
			],

			// Currency/Country Mapping section
			self::SECTION_CURRENCIES_COUNTRIES_MAPPING => [
				// Currency/Country Mapping list
				[
					'id' => Settings::FIELD_CURRENCY_COUNTRIES_MAPPINGS,
					'label' => '',
					'description' => '',
					'css_class' => Settings::FIELD_CURRENCY_COUNTRIES_MAPPINGS,
					'attributes' => [
						'settings_key' => $this->_settings_key,
						'currency_countries_mappings' => $this->current_settings(Settings::FIELD_CURRENCY_COUNTRIES_MAPPINGS, $this->default_settings(Settings::FIELD_CURRENCY_COUNTRIES_MAPPINGS, [])),
						'id' => Settings::FIELD_CURRENCY_COUNTRIES_MAPPINGS,
						'label_for' => Settings::FIELD_CURRENCY_COUNTRIES_MAPPINGS,
						'attributes' => [
						],
					],
					'type' => 'custom',
					'render_callback' => [$this, 'render_currency_countries_mappings'],
				],
			],

			// Support and troubleshooting section
			self::SECTION_SUPPORT => [
				// Debug mode setting
				[
					'id' => Settings::FIELD_DEBUG_MODE_ENABLED,
					'label' => __('Enable debug mode', Definitions::TEXT_DOMAIN),
					'description' => implode(' ', [
						// Show the new name of log files, to indicate that they contain a timestamp
						// @since 4.8.13.200617
						sprintf(__('When the debug mode is enabled, the plugin will log events to a file named <code>%s</code>', Definitions::TEXT_DOMAIN),
										str_replace('.log', '-[TIMESTAMP].log', \Aelia\WC\Logger::get_log_file_name(Definitions::PLUGIN_SLUG))),
						'<strong>' . __('Note', Definitions::TEXT_DOMAIN) . '</strong>:',
						__('depending on the configuration, the file name might be slightly different and could contain a date/time.', Definitions::TEXT_DOMAIN),
					]),
					'css_class' => '',
					'attributes' => [],
					'type' => 'checkbox',
				],
				// Action to allow administrators to trigger the import of sales data for reports
				// @since 5.0.4.230626
				[
					'id' => Settings::FIELD_IMPORT_SALES_DATA,
					'label' => __('Import sales data', Definitions::TEXT_DOMAIN),
					'css_class' => '',
					'attributes' => [
						'order_data_import_running' => apply_filters('wc_aelia_cs_order_data_import_running', false),
					],
					'type' => 'custom',
					'render_callback' => [$this, 'render_order_import_action_field'],
				],
			],
		];
	}

	/**
	 * Renders the Options page for the plugin.
	 */
	public function render_options_page() {
		if(!defined('AELIA_CS_SETTINGS_PAGE')) {
			define('AELIA_CS_SETTINGS_PAGE', true);
		}
		// Prepare settings page for rendering
		$this->init_settings_page();

		echo '<div class="wrap">';
		echo '<div class="icon32" id="icon-options-general"></div>';
		echo '<h2>';
		echo __('WooCommerce Currency Switcher', Definitions::TEXT_DOMAIN);
		printf('&nbsp;(v. %s)', WC_Aelia_CurrencySwitcher::$version);
		echo '</h2>';
		echo
			'<p>' .
			__('In this page, you can configure the parameters for the WooCommerce Currency Switcher. '.
				 'To get started, please select the Currencies that you would like to enable on your ' .
				 'website. Your Customers will then be able to select one of those Currencies to buy ' .
				 'products from your shop.',
				 Definitions::TEXT_DOMAIN) .
			'</p>';
		echo
			'<p>' .
				 __('<strong>Important</strong>: when Customers will place an order, the transaction will be completed ' .
				 '<strong>using the currency they selected</strong>. Please make sure that your Payment Gateways ' .
				 'are configured to accept the Currencies you enable.',
				 Definitions::TEXT_DOMAIN) .
			'</p>';

		settings_errors();
		echo '<form id="' . $this->_settings_key . '_form" method="post" action="options.php">';
		settings_fields($this->_settings_key);

		$this->render_settings_sections($this->_settings_key);
		echo '<div class="buttons">';
		submit_button(__('Save Changes', Definitions::TEXT_DOMAIN),
									'primary',
									'submit',
									false);
		submit_button(__('Save and Update Exchange Rates', Definitions::TEXT_DOMAIN),
									'secondary',
									$this->_settings_key . '[update_exchange_rates_button]',
									false);
		echo '</div>';
		echo '</form>';
		echo '</div>'; // Closing <div class="wrap">
	}

	/**
	 * Returns the title for the menu item that will bring to the plugin's
	 * settings page.
	 *
	 * @return string
	 * @since 4.4.10.170316
	 */
	protected function menu_title() {
		return __('Currency Switcher', Definitions::TEXT_DOMAIN);
	}

	/**
	 * Returns the slug for the menu item that will bring to the plugin's
	 * settings page.
	 *
	 * @return string
	 * @since 4.4.10.170316
	 */
	protected function menu_slug() {
		return Definitions::MENU_SLUG;
	}

	/**
	 * Returns the title for the settings page.
	 *
	 * @return string
	 * @since 4.4.10.170316
	 */
	protected function page_title() {
		return __('Currency Switcher - Settings', Definitions::TEXT_DOMAIN) .
					 sprintf('&nbsp;(v. %s)', WC_Aelia_CurrencySwitcher::$version);
	}


	/*** Settings sections callbacks ***/
	public function enabled_currencies_section_callback() {
		// Dummy
	}

	/**
	 * Renders the exchange rates section.
	 *
	 * @return void
	 */
	public function exchange_rates_settings_section_callback(): void {
		?>
		<p><?php
			echo wp_kses_post(implode(' ', [
				__('In this section you can enter the Exchange Rates which you would like to use to convert prices from your base currency to other currencies.', Definitions::TEXT_DOMAIN),
				__('Exchange Rates will be fetched on a regular basis from the Provider of your choice.', Definitions::TEXT_DOMAIN),
				__('If you wish to lock an Exchange Rate to a specific value, and not have it updated ' .
					 'automatically, simply tick the corresponding box in the <strong>Set Manually</strong> column.', Definitions::TEXT_DOMAIN),
			]));
		?></p>
		<p class="notice-warning"><?php
			echo wp_kses_post(implode(' ', [
				'<strong>' . __('Important', Definitions::TEXT_DOMAIN) . '</strong>:',
				__('you must enter an exchange rate for every enabled currency, even if you plan to only enter product prices entered manually.', Definitions::TEXT_DOMAIN),
				__('The exchange rates are also used to calculate an estimate of order amounts in base currency, for reporting purposes, ' .
					 'therefore it is important that they contain sensible values.', Definitions::TEXT_DOMAIN),
				'<strong>' . __('Currencies with an invalid exchange rate will be considered disabled and will not be used by the Currency Switcher', Definitions::TEXT_DOMAIN) . '</strong>.',
			]));
		?></p>
		<?php
	}

	/**
	 * Renders the exchange rates auto-update section.
	 *
	 * @return void
	 */
	public function exchange_rates_update_section_callback(): void {
		?>
		<div><?php
			echo __('In this section you can configure the frequency of automatic updates for the exchange rates.',
			 Definitions::TEXT_DOMAIN);
		?></div>
		<div class="notice-warning">
			<p>
				<strong><?php echo __('Important', Definitions::TEXT_DOMAIN); ?>: </strong>
				<span><?php
					echo __('The first time you save the settings, the rates will fetched from the selected provider.',
									Definitions::TEXT_DOMAIN) .
							 ' ' .
							 __('You will then be able to alter them manually, or schedule them to be updated automatically.',
									Definitions::TEXT_DOMAIN);
				?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the Open Exchange Rates section.
	 *
	 * @return void
	 */
	public function openexchangerates_settings_section_callback(): void {
		$model_key = $this->_settings_controller->get_exchange_rates_model_key('Aelia\WC\CurrencySwitcher\Exchange_Rates_OpenExchangeRates_Model');
		?>
		<div class="exchange_rate_model_settings <?php echo $model_key; ?>">
			<script type="text/javascript">
				jQuery(document).ready(function($) {
					/**
					 * Ensure that the settings can't be saved if Open Exchange Rates is
					 * the selected provider, but no API key was entered.
					 *
					 * @since 4.5.2.171019
					 */
					$('#wc_aelia_currency_switcher_form > .buttons .button[type="submit"]').on('click', function() {
						var $api_key_field = $('#wc_aelia_currency_switcher\\[openexchange_api_key\\]');
						$api_key_field.prop('required', $api_key_field.is(':visible'));
					});
					return true;
				});
			</script>
		</div>
		<?php
	}

	/**
	 * Renders the geolocation section.
	 *
	 * @return void
	 */
	public function ipgeolocation_settings_section_callback(): void {
		?>
		<p class="ipgeolocation_api_settings"><?php
			echo wp_kses_post(implode(' ', [
				__('In this section you can enable the GeoLocation feature, which tries to select a Currency automatically, depending on ' .
					 'visitors\' IP Address the first time they visit the shop.', Definitions::TEXT_DOMAIN),
				__('This feature uses GeoLite data created by MaxMind, available from <a href="https://www.maxmind.com">https://www.maxmind.com</a>.', Definitions::TEXT_DOMAIN),
			]));
		?></p>
		<p class="ipgeolocation_api_settings"><?php
			echo wp_kses_post(sprintf(__('GeoLite database in use: <code>%s</code>.', Definitions::TEXT_DOMAIN), IP2Location::geoip_db_file()));
		?></p>
		<?php
	}

	/**
	 * Renders the payment gateways section.
	 *
	 * @return void
	 */
	public function paymentgateways_settings_section_callback(): void {
		?>
		<p class="paymentgateways_settings"><?php
			echo wp_kses_post(implode(' ', [
				__('In this section you can indicate which payment gateways can be used to pay in each of the enabled currencies.', Definitions::TEXT_DOMAIN),
				__('This feature is useful when you have one or more gateways that do not accept one of the currencies that ' .
					 'you wish to accept, or that are restricted to transactions in a single currency.', Definitions::TEXT_DOMAIN),
			]));
		?></p>
		<?php
	}

	/**
	 * Renders the currency selection section.
	 *
	 * @return void
	 */
	public function currencyselection_widgets_settings_section_callback(): void {
		?>
		<p class="currencyselection_widget_settings"><?php
			echo __('In this section you can specify how visitors to your site will be able to select the currency in which they will see prices and complete orders.', Definitions::TEXT_DOMAIN);
		?></p>
		<?php
	}

	/**
	 * Renders the currency/country mapping section.
	 *
	 * @return void
	 * @since 4.13.0.220104
	 */
	public function currency_countries_section_callback(): void {
		?>
		<p class="currency_countries_mapping_settings"><?php
			echo wp_kses_post(implode(' ', [
				__('In this section you can assign a currency to specific countries.', Definitions::TEXT_DOMAIN),
				__('For example, you could assign Euro to Scandinavian countries, or to the United Kindgom, or US Dollar to South American countries.', Definitions::TEXT_DOMAIN),
				__('The custom mapping will be used by the geolocation feature and, if enabled, by the "force currency by country" feature, which will select the currency based on the new mapping.', Definitions::TEXT_DOMAIN),
				__('If you wish to restore the original currency linked to a country, simply remove the country from the custom mapping field.', Definitions::TEXT_DOMAIN),
				'<br /><br />',
				'<strong>',
				__('Important', Definitions::TEXT_DOMAIN),
				'</strong>',
				'<br />',
				__('Please ensure that you link a country only to one currency.', Definitions::TEXT_DOMAIN),
				__('If you link the same country to multiple currencies, the currency will be taken from the first match found.', Definitions::TEXT_DOMAIN),
			]))
		?></p>
		<?php
	}

	// Legacy Licensing feature removed

	
	/**
	 * Support section (Freemius)
	 *
	 * @return void
	 * @since 4.11.1.210520
	 */
	public function freemius_support_section_callback() {
		?>
		<div class="troubleshooting">
			<p><?php
				echo wp_kses_post(implode(' ', array(
					__('We designed the Currency Switcher plugin to be robust and effective, as well as intuitive and easy to use.', Definitions::TEXT_DOMAIN),
					__('The options in this section can help you troubleshooting any issue that you might encounter while using our solution.', Definitions::TEXT_DOMAIN),
				)));
			?></p>
		</div>
		<?php
	}

	/**
	 * Freemius Contact page.
	 *
	 * @return void
	 * @since 4.11.1.210520
	 */
	public function freemius_contact_section_callback() {
		?>
		<div class="support_information">
			<p><?php
				echo wp_kses_post(__('Should you need assistance, or if you just would like to get in touch with us, please feel free to use the form below.', Definitions::TEXT_DOMAIN));
			?></p>
			<div class="contact_form"><?php
				echo do_shortcode(sprintf('[aelia_freemius_contact_form plugin_slug="%1$s"]', Definitions::PLUGIN_SLUG));
			?></div>
		</div>
		<?php
	}

	/**
	 * Account section. Shows the Freemius account details.
	 *
	 * @return void
	 * @since 4.11.1.210520
	 */
	public function freemius_account_section_callback(): void {
		?>
		<div class="freemius_account"><?php
		if(Freemius_Plugin_Integration::is_plugin_license_activated(Definitions::PLUGIN_SLUG)) {
			echo implode(' ', array(
				__('This plugin is distributed via the Freemius platform, which also handles the customer accounts and the licences.', Definitions::TEXT_DOMAIN),
				sprintf(
					__('You can review the account settings for this plugin <a class="freemius_account_link" href="%1$s" target="_blank">in the dedicated account page</a>.', Definitions::TEXT_DOMAIN),
					Freemius_Plugin_Integration::get_account_page_url(Definitions::PLUGIN_SLUG)
				),
			));
		}
		else {
			$fs_integration = Freemius_Plugin_Integration::get_freemius_integration(Definitions::PLUGIN_SLUG);
			echo implode(' ', [
				__('It looks like this site is not covered by an active licence.', Definitions::TEXT_DOMAIN),
				sprintf(
					__('Please <a class="freemius_activation_link" href="%1$s">complete the activation</a> to receive updates and support for the product.', Definitions::TEXT_DOMAIN),
					$fs_integration->get_activation_url([], !$fs_integration->is_delegated_connection())
				),
			]);
		}
		?></div>
		<?php
	}

	/**
	 * Account section. Shows the pricing options for a plugin.
	 *
	 * @return void
	 * @since 4.11.1.210520
	 */
	public function account_pricing_callback(): void {
		?>
		<div class="freemius_pricing_options"><?php
			echo do_shortcode(sprintf('[aelia_freemius_plugin_pricing plugin_slug="%1$s"]', Definitions::PLUGIN_SLUG));
		?></div>
		<?php
	}
	

	/*** Rendering methods ***/
	/**
	 * Renders a table containing several fields that Admins can use to configure
	 * the Exchange Rates for the enabled Currencies.
	 *
	 * @param array args An array of arguments passed by add_settings_field().
	 * @see add_settings_field().
	 */
	public function render_exchange_rates_options($args) {
		$this->get_field_ids($args, $base_field_id, $base_field_name);

		// Load currently enabled currencies. WooCommerce default currency is always enabled
		// @since 4.12.4.210805
		$enabled_currencies = array_unique(array_merge($this->_settings_controller->get_enabled_currencies(), [$this->_settings_controller->base_currency()]));

		if(!is_array($enabled_currencies)) {
			throw new InvalidArgumentException(__('Argument "enabled_currencies" must be an array.', Definitions::TEXT_DOMAIN));
		}

		// If array contains only one element, it must be the base currency. In
		// such case, simply display a message
		if(count($enabled_currencies) <= 1) {
			echo '<p>' . __('Only the base WooCommerce currency has been enabled, therefore there are ' .
											'no Exchange Rates to be configured', Definitions::TEXT_DOMAIN) . '</p>';
			return;
		}

		// Retrieve the exchange rates
		$exchange_rates = $args[Settings::FIELD_EXCHANGE_RATES] ?? [];

		if(!is_array($exchange_rates)) {
			throw new InvalidArgumentException(__('Argument "exchange_rates" must be an array.', Definitions::TEXT_DOMAIN));
		}

		// Retrieve the Currency used internally by WooCommerce
		$woocommerce_base_currency = $this->_settings_controller->base_currency();

		$html = '<table id="exchange_rates_settings">';
		// Table header
		$html .= '<thead>';
		$html .= '<tr>';
		$html .= '<th class="sort">' . __('Sort', Definitions::TEXT_DOMAIN);
		$html .= '<span class="help-icon" title="' .
				 __('Drag and drop the placeholder next to each currency to reorder them',
						Definitions::TEXT_DOMAIN) .
				 '"></span>';
		$html .= '</th>';
		$html .= '<th class="currency_name">' . __('Currency', Definitions::TEXT_DOMAIN) . '</th>';
		$html .= '<th class="exchange_rate">';
		$html .= __('Exchange Rate', Definitions::TEXT_DOMAIN);
		$html .= '<span class="help-icon" title="' .
						 __('Enter the exchange rate that you would like to use for this currency. The value ' .
								'must use the point as a decimal separator, and it must not include any thousand ' .
								'separator. Example: 123.456',
								Definitions::TEXT_DOMAIN) .
						 '"></span>';
		$html .= '</th>';
		$html .= '<th class="set_manually">' .
						 __('Set Manually', Definitions::TEXT_DOMAIN) .
						 '<span class="help-icon" title="' .
						 __('Tick the box next to a currency if you would like to enter its ' .
								'exchange rate manually. By doing that, the rate you enter for ' .
								'that currency will not change, even if you have enabled the automatic ' .
								'update of exchange rates',
								Definitions::TEXT_DOMAIN) .
						 '"></span>' .
						 '<div class="selectors">' .
						 '<span class="select_all">' . __('Select', Definitions::TEXT_DOMAIN) . '</span>' .
						 '/' .
						 '<span class="deselect_all">' . __('Deselect', Definitions::TEXT_DOMAIN) . '</span>' .
						 __('all', Definitions::TEXT_DOMAIN) .
						 '</div>' .
						 '</th>';
		$html .= '<th class="rate_markup">';
		$html .= __('Rate Markup', Definitions::TEXT_DOMAIN);
		$html .= '<span class="help-icon" title="' .
						 __('If specified, this markup will be added to the standard exchange rate.', Definitions::TEXT_DOMAIN) . ' ' .
						 __("The markup can be be an absolute value, which is added 'as is' to the rate, or a percentage.", Definitions::TEXT_DOMAIN) . '<br><br> ' .
						 '<strong>' .
						 __('Examples', Definitions::TEXT_DOMAIN) .
						 '</strong><br>' .
						 __('Original  exchange rate: 1.23', Definitions::TEXT_DOMAIN) . '<br>' .
						 __('Markup: 0.05 (absolute value). Result: 1.23 + 0.05 = 1.28', Definitions::TEXT_DOMAIN) . '<br>' .
						 __('Markup: 10% (percentage). Result: 1.23 + 10% = 1.353', Definitions::TEXT_DOMAIN) .
						 '"></span>';
		$html .= '</th>';

		$html .= '<th class="thousand_separator">';
		$html .= __('Thousand sep.', Definitions::TEXT_DOMAIN);
		$html .= '<span class="help-icon"
							title="' . __('Enter the thousand separator that you would like to use when ' .
														'this currency is active', Definitions::TEXT_DOMAIN) .
							'"></span>';
		$html .= '</th>';

		$html .= '<th class="decimal_separator">';
		$html .= __('Decimal sep.', Definitions::TEXT_DOMAIN);
		$html .= '<span class="help-icon"
							title="' . __('Enter the decimal separator that you would like to use when ' .
														'this currency is active', Definitions::TEXT_DOMAIN) .
							'"></span>';
		$html .= '</th>';

		$html .= '<th class="decimals">';
		$html .= __('Decimals', Definitions::TEXT_DOMAIN);
		$html .= '<span class="help-icon" title="' .
						 __('The number of decimals will be used to round ALL figures. '.
								'Rounding will be mathematical, with halves rounded up. ' .
								'IMPORTANT: this setting affects PRICES and TAXES, which will be rounded to ' .
								'the specified amount of decimals. Do not set the value to zero unless you ' .
								'have a good reason, as that could result in an incorrect rounding of taxes', Definitions::TEXT_DOMAIN) .
						 '"></span>';
		$html .= '</th>';

		$html .= '<th class="symbol">';
		$html .= __('Symbol', Definitions::TEXT_DOMAIN);
		$html .= '<span class="help-icon" title="' .
						 __('The symbol that will be used to represent the currency. You can use this ' .
								'settings to distinguish currencies more easily, for example by displaying ' .
								'US$, AU$, NZ$ and so on.', Definitions::TEXT_DOMAIN) .
						 '"></span>';
		$html .= '</th>';

		$html .= '<th class="symbol_position">';
		$html .= __('Symbol position', Definitions::TEXT_DOMAIN);
		$html .= '</th>';

		$html .= '</tr>';
		$html .= '</thead>';
		$html .= '<tbody>';


		foreach($exchange_rates as $currency => $currency_settings) {
			if($currency == $woocommerce_base_currency) {
				// Render a special line to display settings for base currency
				$html .= $this->render_settings_for_base_currency($woocommerce_base_currency,
																													$exchange_rates,
																													$base_field_id,
																													$base_field_name);

				continue;
			}

			// Discard currencies that are no longer enabled
			if(!in_array($currency, $enabled_currencies)) {
				continue;
			}

			$currency_field_id = $this->group_field($currency, $base_field_id);
			$currency_field_name = $this->group_field($currency, $base_field_name);
			$html .= '<tr>';
			$html .= '<td class="sort handle">&nbsp;</td>';
			// Output currency label
			$html .= '<td class="currency_name">';
			$html .= '<span>' . $this->_settings_controller->get_currency_description($currency) . '</span>';
			$html .= '</td>';

			$currency_settings = array_merge($this->_settings_controller->default_currency_settings(), $currency_settings);

			// Render exchange rate field
			$html .= '<td>';
			$field_args = array(
				'id' => $currency_field_id . '[rate]',
				'value' => $currency_settings['rate'] ?? '',
				'attributes' => array(
					'class' => 'numeric',
				),
			);
			ob_start();
			$this->render_textbox($field_args);
      $field_html = ob_get_contents();
      ob_end_clean();
			$html .= $field_html;
			$html .= '</td>';

			// Render "Set Manually" checkbox
			$html .= '<td class="set_manually">';
			$field_args = array(
				'id' => $currency_field_id . '[set_manually]',
				'value' => 1,
				'attributes' => array(
					'class' => 'exchange_rate_set_manually',
					'checked' => $currency_settings['set_manually'] ?? false,
				),
			);
			ob_start();
			$this->render_checkbox($field_args);
			$field_html = ob_get_contents();
			ob_end_clean();
			$html .= $field_html;
			$html .= '</td>';

			// Render exchange rate markup field
			$html .= '<td>';
			$field_args = array(
				'id' => $currency_field_id . '[rate_markup]',
				'value' => $currency_settings['rate_markup'] ?? '',
				'attributes' => array(
					'class' => 'numeric',
				),
			);
			ob_start();
			$this->render_textbox($field_args);
			$field_html = ob_get_contents();
			ob_end_clean();
			$html .= $field_html;
			$html .= '</td>';

			// Render thousand separator field
			$thousand_separator = $currency_settings['thousand_separator'] ?? $this->_settings_controller->woocommerce_price_thousand_sep;

			$html .= '<td class="thousand_separator">';
			$field_args = array(
				'id' => $currency_field_id . '[thousand_separator]',
				'value' => $thousand_separator,
				'attributes' => array(
					'class' => 'text',
				),
			);
			ob_start();
			$this->render_textbox($field_args);
      $field_html = ob_get_contents();
      ob_end_clean();
			$html .= $field_html;
			$html .= '</td>';

			// Render decimal separator field
			$decimal_separator = $currency_settings['decimal_separator'] ?? $this->_settings_controller->woocommerce_price_decimal_sep;

			$html .= '<td class="decimal_separator">';
			$field_args = array(
				'id' => $currency_field_id . '[decimal_separator]',
				'value' => $decimal_separator,
				'attributes' => array(
					'class' => 'text',
				),
			);
			ob_start();
			$this->render_textbox($field_args);
      $field_html = ob_get_contents();
      ob_end_clean();
			$html .= $field_html;
			$html .= '</td>';

			// Render decimals field
			$default_currency_decimals = default_currency_decimals($currency, $this->_settings_controller->woocommerce_currency_decimals);
			$currency_decimals = $currency_settings['decimals'] ?? null;
			if(!is_numeric($currency_decimals)) {
				$currency_decimals = $default_currency_decimals;
			}

			$html .= '<td class="decimals">';
			$field_args = array(
				'id' => $currency_field_id . '[decimals]',
				'value' => $currency_decimals,
				'attributes' => array(
					'class' => 'numeric',
				),
			);
			ob_start();
			$this->render_textbox($field_args);
      $field_html = ob_get_contents();
      ob_end_clean();
			$html .= $field_html;
			$html .= '</td>';

			// Render currency symbol field
			$currency_symbol = $currency_settings['symbol'] ?? get_woocommerce_currency_symbol($currency);
			$html .= '<td class="symbol">';
			$field_args = array(
				'id' => $currency_field_id . '[symbol]',
				'value' => $currency_symbol,
				'attributes' => array(
					'class' => 'text',
				),
			);
			ob_start();
			$this->render_textbox($field_args);
      $field_html = ob_get_contents();
      ob_end_clean();
			$html .= $field_html;
			$html .= '</td>';

			// Render currency symbol position field
			$html .= '<td class="symbol_position">';
			$field_args = array(
				'id' => $currency_field_id . '[symbol_position]',
				'options' => array(
					'left' => __( 'Left', 'woocommerce' ) . ' (' . $currency_symbol . '99.99)',
					'right' => __( 'Right', 'woocommerce' ) . ' (99.99' . $currency_symbol . ')',
					'left_space' => __( 'Left with space', 'woocommerce' ) . ' (' . $currency_symbol . ' 99.99)',
					'right_space' => __( 'Right with space', 'woocommerce' ) . ' (99.99 ' . $currency_symbol . ')'
				),
				'selected' => $currency_settings['symbol_position'] ?? get_option('woocommerce_currency_pos'),
				'attributes' => array(
					'class' => 'currency_symbol_position',
				),
			);
			ob_start();
			$this->render_dropdown($field_args);
      $field_html = ob_get_contents();
      ob_end_clean();
			$html .= $field_html;
			$html .= '</td>';

			$html .= '</tr>';
		}

		$html .= '</tbody>';
		$html .= '</table>';

		echo $html;
	}

	/**
	 * Renders a "special" row on the exchange rates table, which contains the
	 * settings for the base currency.
	 *
	 * @param string currency The currency to display on the row.
	 * @param string exchange_rates An array of currency settings.
	 * @param string base_field_id The base ID that will be assigned to the
	 * fields in the row.
	 * @param string base_field_id The base name that will be assigned to the
	 * fields in the row.
	 * @return string The HTML for the row.
	 */
	protected function render_settings_for_base_currency($currency, $exchange_rates, $base_field_id, $base_field_name) {
		$currency_field_id = $this->group_field($currency, $base_field_id);
		$currency_field_name = $this->group_field($currency, $base_field_name);

		$html = '<tr>';
		$html .= '<td class="sort handle">&nbsp;</td>';
		// Output currency label
		$html .= '<td class="currency_name">';
		$html .= '<span>' . $this->_settings_controller->get_currency_description($currency) . '</span>';
		$html .= '</td>';

		$currency_settings = $exchange_rates[$currency] ?? [];
		$currency_settings = array_merge($this->_settings_controller->default_currency_settings(), $currency_settings);

		// Render exchange rate field
		$html .= '<td class="numeric">';
		$html .= '1'; // Exchange rate for base currency is always 1
		$html .= '</td>';

		// Render "Set Manually" checkbox
		$html .= '<td>';
		$html .= '</td>';

		// Render exchange rate markup field
		$html .= '<td>';
		$html .= '</td>';

		// Render thousand separator field
		$currency_decimals = $currency_settings['thousand_separator'] ?? $this->_settings_controller->woocommerce_price_thousand_sep;

		$html .= '<td class="thousand_separator">';
		$field_args = array(
			'id' => $currency_field_id . '[thousand_separator]',
			'value' => $currency_decimals,
			'attributes' => array(
				'class' => 'text',
			),
		);
		ob_start();
		$this->render_textbox($field_args);
		$field_html = ob_get_contents();
		ob_end_clean();
		$html .= $field_html;
		$html .= '</td>';

		// Render decimal separator field
		$decimal_separator = $currency_settings['decimal_separator'] ?? $this->_settings_controller->woocommerce_price_decimal_sep;

		$html .= '<td class="decimal_separator">';
		$field_args = array(
			'id' => $currency_field_id . '[decimal_separator]',
			'value' => $decimal_separator,
			'attributes' => array(
				'class' => 'text',
			),
		);
		ob_start();
		$this->render_textbox($field_args);
		$field_html = ob_get_contents();
		ob_end_clean();
		$html .= $field_html;
		$html .= '</td>';

		// Render decimals field
		$default_currency_decimals = default_currency_decimals($currency, $this->_settings_controller->woocommerce_currency_decimals);
		$currency_decimals = $currency_settings['decimals'] ?? null;
		if(!is_numeric($currency_decimals)) {
			$currency_decimals = $default_currency_decimals;
		}

		$html .= '<td class="decimals">';
		$field_args = array(
			'id' => $currency_field_id . '[decimals]',
			'value' => $currency_decimals,
			'attributes' => array(
				'class' => 'numeric',
			),
		);
		ob_start();
		$this->render_textbox($field_args);
		$field_html = ob_get_contents();
		ob_end_clean();
		$html .= $field_html;
		$html .= '</td>';

		// Render currency symbol field
		$currency_symbol = $currency_settings['symbol'] ?? get_woocommerce_currency_symbol($currency);
		$html .= '<td class="symbol">';
		$field_args = array(
			'id' => $currency_field_id . '[symbol]',
			'value' => $currency_symbol,
			'attributes' => array(
				'class' => 'text',
			),
		);
		ob_start();
		$this->render_textbox($field_args);
		$field_html = ob_get_contents();
		ob_end_clean();
		$html .= $field_html;
		$html .= '</td>';

		// Render currency symbol position field
		$html .= '<td class="symbol_position">';
		$field_args = array(
			'id' => $currency_field_id . '[symbol_position]',
			'options' => array(
				'left' => __( 'Left', 'woocommerce' ) . ' (' . $currency_symbol . '99.99)',
				'right' => __( 'Right', 'woocommerce' ) . ' (99.99' . $currency_symbol . ')',
				'left_space' => __( 'Left with space', 'woocommerce' ) . ' (' . $currency_symbol . ' 99.99)',
				'right_space' => __( 'Right with space', 'woocommerce' ) . ' (99.99 ' . $currency_symbol . ')'
			),
			'selected' => $currency_settings['symbol_position'] ?? get_option('woocommerce_currency_pos'),
			'attributes' => array(
				'class' => 'currency_symbol_position',
			),
		);
		ob_start();
		$this->render_dropdown($field_args);
		$field_html = ob_get_contents();
		ob_end_clean();
		$html .= $field_html;
		$html .= '</td>';

		$html .= '</tr>';

		return $html;
	}


	/**
	 * Renders a table containing a list of currencies and the payment gateways
	 * enabled for each one of them.
	 *
	 * @param array args An array of arguments passed by add_settings_field().
	 * @see add_settings_field().
	 */
	public function render_payment_gateways_options($args) {
		$this->get_field_ids($args, $base_field_id, $base_field_name);

		// Retrieve the enabled currencies
		$enabled_currencies = array_filter($args[Settings::FIELD_ENABLED_CURRENCIES]);
		if(!is_array($enabled_currencies)) {
			throw new InvalidArgumentException(__('Argument "enabled_currencies" must be an array.', Definitions::TEXT_DOMAIN));
		}

		// Retrieve the payment gateways currently set for each currency
		$payment_gateways = $args[Settings::FIELD_PAYMENT_GATEWAYS] ?? [];

		$html = '<table id="payment_gateways_settings">';
		// Table header
		$html .= '<thead>';
		$html .= '<tr>';
		$html .= '<th class="currency">' . __('Currency', Definitions::TEXT_DOMAIN) . '</th>';
		$html .= '<th class="payment_gateways">' . __('Enabled Gateways', Definitions::TEXT_DOMAIN) . '</th>';
		$html .= '</tr>';
		$html .= '</thead>';
		$html .= '<tbody>';

		foreach($enabled_currencies as $currency) {
			$currency_field_id = $this->group_field($currency, $base_field_id);
			$currency_field_name = $this->group_field($currency, $base_field_name);
			$html .= '<tr>';
			// Output currency label
			$html .= '<td>';
			$html .= '<span>' . $this->_settings_controller->get_currency_description($currency) . '</span>';
			$html .= '</td>';

			$currency_settings = $payment_gateways[$currency] ?? $this->_settings_controller->default_currency_settings();

			// Retrieve all enabled Payment Gateways to prepare a list of options to
			// display in the dropdown fields
			$payment_gateways_options = [];
			foreach($this->_settings_controller->woocommerce_payment_gateways() as $gateway_id => $gateway) {
				// Take payment gateway's frontend title or, if it's empty, the internal title
				$gateway_title = !empty($gateway->title) ? $gateway->title : $gateway->method_title;
				$payment_gateways_options[$gateway_id] = $gateway_title;
			}

			// Render payment gateways field
			$html .= '<td>';
			$field_args = array(
				'id' => $currency_field_id . '[enabled_gateways]',
				'options' => $payment_gateways_options,
				'selected' => $currency_settings['enabled_gateways'] ?? '',
				'attributes' => array(
					'class' => 'currency_payment_gateways',
					'multiple' => 'multiple',
				),
			);
			ob_start();
			$this->render_dropdown($field_args);
      $field_html = ob_get_contents();
      ob_end_clean();
			$html .= $field_html;
			$html .= '</td>';

			$html .= '</tr>';
		}

		$html .= '</tbody>';
		$html .= '</table>';

		echo $html;
	}

	/**
	 * Renders a table containing several fields that Admins can use to configure
	 * the Exchange Rates for the enabled Currencies.
	 *
	 * @param array args An array of arguments passed by add_settings_field().
	 * @see add_settings_field().
	 */
	public function render_currency_countries_mappings($args) {
		// Load currently enabled currencies. The default currency is always enabled
		// @since 4.13.0.220104
		$enabled_currencies = array_unique(array_merge($this->_settings_controller->get_enabled_currencies(), [$this->_settings_controller->base_currency()]));

		if(!is_array($enabled_currencies)) {
			throw new InvalidArgumentException(__('Argument "enabled_currencies" must be an array.', Definitions::TEXT_DOMAIN));
		}
		?>
		<table id="currency_country_mappings_settings">
			<thead>
				<tr>
					<th class="currency" scope="col"><?= esc_html__('Currency', Definitions::TEXT_DOMAIN) ?></th>
					<th class="countries" scope="col">
						<?= esc_html__('Countries', Definitions::TEXT_DOMAIN) ?>
						<span class="help-icon" title="<?= esc_attr__('Select the countries tha you would like to link to the currency.', Definitions::TEXT_DOMAIN); ?>"></span>
					</th>
				</tr>
			</thead>
			<tfoot>
			</tfoot>
			</tbody><?php
				// Fetch the field ID and field name that will allow to group fields together
				$this->get_field_ids($args, $base_field_id, $base_field_name);

				// Retrieve the exchange rates
				$currency_country_mappings = $args[Settings::FIELD_CURRENCY_COUNTRIES_MAPPINGS] ?? [];

				foreach($enabled_currencies as $currency) {
					// Discard currencies that are no longer enabled
					if(!in_array($currency, $enabled_currencies)) {
						continue;
					}

					$field_id = $this->group_field($currency, $base_field_id);
					$field_name = $this->group_field($currency, $base_field_name);
				?>
				<tr>
					<td class="currency"><span class="label"><?= esc_html__($this->_settings_controller->get_currency_description($currency)) ?></span></td>
					<td class="countries"><?php
						$field_args = [
							'id' => $field_id . '[countries]',
							'name' => $field_name . '[countries]',
							'selected' => $currency_country_mappings[$currency]['countries'] ?? [],
							'options' => WC()->countries->get_allowed_countries(),
							'attributes' => [
								'class' => 'currency_countries',
								'multiple' => 'multiple',
							],
						];
						// Render the multiselect that allows to choose the countries to link to a currency
						$this->render_dropdown($field_args);
					?>

						<!-- The following triggers  allow to add sets of countries in one shot -->
						<div class="triggers">
							<span class="trigger add_eu_countries"><?= esc_html__('Add European Union countries', Definitions::TEXT_DOMAIN) ?></span>
						</div>
					</td>
				</tr>
				<?php // End of the loop rendering the rows
			}
			?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders an interface to allow the user to trigger the install/upgrade process
	 * and import the sales data from the orders.
	 *
	 * @param array args An array of arguments passed by add_settings_field().
	 */
	public function render_order_import_action_field($args): void {
		$order_data_import_running = $args['order_data_import_running'] ?? false;
		?>
		<div class="order_import">
			<button id="<?= Settings::FIELD_IMPORT_SALES_DATA?>" type="button" <?= disabled($order_data_import_running) ?>><?=
				esc_html__('Import sales data for reports', Definitions::TEXT_DOMAIN)
			?></button>
			<div class="action_result hidden"></div>
			<div class="description"><?php
				if($order_data_import_running) {
					$pending_actions_url = admin_url('admin.php?page=wc-status&tab=action-scheduler&status=pending&s=' . WC_Aelia_CurrencySwitcher::$plugin_slug);

					echo wp_kses_post(implode(' ', [
						__('The import process is already running.', Definitions::TEXT_DOMAIN),
						sprintf(
							__('To view pending tasks, please <a href="%1$s">go to the Scheduled Tasks page</a>.', Definitions::TEXT_DOMAIN),
							$pending_actions_url
						),
					]));
				}
				else {
					$kb_article_sales_data_url = 'https://aelia.freshdesk.com/a/solutions/articles/3000024256';
					echo wp_kses_post(implode(' ', [
						__('Click on this button to repeat the import of the sales data from the orders.', Definitions::TEXT_DOMAIN),
						__('For more information on when to perform this operation, please refer to the following article:', Definitions::TEXT_DOMAIN),
						sprintf('<a href="%2$s">%1$s</a>',
										__('Aelia Currency Switcher - Sales report show lower amounts when the plugin is active', Definitions::TEXT_DOMAIN),
										$kb_article_sales_data_url) . '.',
					]));
				}
			?></div>
		</div>
		<?php
	}
}
