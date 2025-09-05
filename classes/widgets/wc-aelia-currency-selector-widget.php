<?php
namespace Aelia\WC\CurrencySwitcher\Widgets;

use Aelia\WC\CurrencySwitcher\Definitions;
use Aelia\WC\CurrencySwitcher\Settings;
use Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher;

if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

/**
 * Displays the currency selector widget.
 *
 * @since 4.12.0.210628
 */
class Currency_Selector extends \WP_Widget {
	// Widget types
	const TYPE_DROPDOWN = 'dropdown';
	const TYPE_BUTTONS = 'buttons';
	// @since 4.12.0.210628
	const TYPE_DROPDOWN_FLAGS = 'dropdown_flags';

	// Currency display options
	// @since 4.12.0.210628
	const SHOW_CURRENCY_CODE = 'show_currency_code';
	const SHOW_CURRENCY_NAME = 'show_currency_name';

	// @var bool Indicates if one or more currencies were configured incorrectly.
	public $misconfigured_currencies = false;

	/**
	 * Returns a list of the available widget types and their attributes.
	 *
	 * @return array
	 */
	protected function widget_types(): array {
		$widget_types = array(
			self::TYPE_DROPDOWN => array(
				'name' => __('Dropdown', Definitions::TEXT_DOMAIN),
				'template' => 'currency-selector-widget-dropdown',
				'title' => __('Displays a dropdown with all the enabled currencies', Definitions::TEXT_DOMAIN),
			),
			self::TYPE_BUTTONS => array(
				'name' => __('Buttons', Definitions::TEXT_DOMAIN),
				'template' => 'currency-selector-widget-buttons',
				'title' => __('Displays one button for each currency', Definitions::TEXT_DOMAIN),
			),
			// Dropdown widget with flags
			// @since 4.12.0.210628
			self::TYPE_DROPDOWN_FLAGS => array(
				'name' => __('Dropdown with flags', Definitions::TEXT_DOMAIN),
				'template' => 'currency-selector-widget-dropdown-flags',
				'title' => __('Displays a dropdown with all the enabled currencies, with a flag next to each currency', Definitions::TEXT_DOMAIN),
			),
		);

		$widget_types = apply_filters('wc_aelia_cs_currency_selector_widget_types', $widget_types);
		return $widget_types;
	}

	/**
	 * Retrieves the template that will be used to render the widget.
	 *
	 * @param string template_type The template type.
	 * @return string
	 */
	protected function get_widget_template($template_type) {
		$widget_types = $this->widget_types();
		$type_info = $widget_types[$template_type] ?? false;
		// If an invalid type is passed, default to a dropdown widget
		if(empty($type_info)) {
			$type_info = $this->widget_types()[self::TYPE_DROPDOWN];
		}

		return $type_info['template'];
	}

	/**
	 *	Class constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wc_aelia_currencyswitcher_widget',
			'WooCommerce Currency Switcher - Currency Selector',
			array('description' => __('Allow to switch currency on the fly and perform all transactions in such currency', Definitions::TEXT_DOMAIN),)
		);

		$this->set_hooks();
	}

	/**
	 * Loads the CSS files required by the Widget.
	 */
	private function load_css() {
	}

	/**
	 * Loads the JavaScript files required by the Widget.
	 */
	private function load_js() {
	}

	/**
	 * Front-end display of widget.
	 *
	 * @param array $widget_args Widget arguments.
	 * @param array $instance Saved values from database.
	 * @see WP_Widget::widget()
	 */
	public function widget($widget_args, $instance = array()) {
		$this->load_css();
		$this->load_js();

		if(!is_array($widget_args)) {
			$widget_args = array();
		}

		$widget_args = array_merge(
			$instance,
			$widget_args
		);

		$widget_type = $widget_args['widget_type'] ?? self::TYPE_DROPDOWN;
		$widget_template_name = $this->get_widget_template($widget_type);
		$widget_template_file = WC_Aelia_CurrencySwitcher::instance()->get_template_file($widget_template_name);

		if(empty($widget_template_file)) {
			$this->display_invalid_widget_type_error($widget_type);
		}
		else {
			$widget_args['title'] = apply_filters('wc_aelia_currencyswitcher_widget_title', get_value('title', $widget_args));
			$widget_args['currency_options'] = apply_filters('wc_aelia_currencyswitcher_widget_currency_options', $this->get_currency_options(), $widget_type, $widget_args['title'], $widget_template_name);

			$selected_currency = get_value('selected_currency', $widget_args);
			if(empty($selected_currency)) {
				$widget_args['selected_currency'] = WC_Aelia_CurrencySwitcher::instance()->get_selected_currency();
			}

			// Display the Widget
			include $widget_template_file;
		}
	}

	/**
	 * If an invalid widget type has been set, display an error so that the site
	 * owner is aware of it.
	 *
	 * @param string widget_type The invalid widget type.
	 */
	protected function display_invalid_widget_type_error($widget_type) {
		?>
		<div class="error">
			<h5 class="title"><?= esc_html__('Error', $this->text_domain) ?></h5>
			<?php
				echo sprintf(wp_kses_post('The country selector widget has not been configured properly. A template for ' .
																	'wiget type "%s" could not be found. Please review the widget settings and ensure ' .
																	'that a valid widget type has been selected. If the issue persists, please ' .
																	'<a href="https://aelia.freshdesk.com/helpdesk/tickets/new" title="Contact support">' .
																	'contact support</a>.', $this->text_domain), $widget_type);
			?>
		</div>
		<?php
	}

	/**
	 * Renders Widget's form in Admin section.
	 *
	 * @param array instance Widgets settings passed when submitting the form.
	 */
 	public function form($instance) {
		// Merge default arguments with the ones for the widget instance
		// @since 4.12.0.210628
		$instance = array_merge([
			'title' => '',
			'widget_type' => self::TYPE_DROPDOWN,
		], $instance);

		// If the currency display mode has not been set for the widget, set the
		// default as follows
		// - For simple dropdown widgets, show the currency name
		// - For all other widgets, show the currency code
		// @since 4.12.0.210628
		if(empty($instance['currency_display_mode'])) {
			$instance['currency_display_mode'] = ($instance['widget_type'] === self::TYPE_DROPDOWN) ? self::SHOW_CURRENCY_NAME : self::SHOW_CURRENCY_CODE;
		}

		// Widget title field
		$title_field_id = $this->get_field_id('title');
		$title_field_name = $this->get_field_name('title');
		?>
		<p>
			<label for="<?= $title_field_id ?>"><?= esc_html__('Title', Definitions::TEXT_DOMAIN) ?></label>
			<input type="text" class="widefat" id="<?= esc_attr($title_field_id) ?>" name="<?= $title_field_name ?>" value="<?= esc_attr($instance['title']) ?>" />
		</p>
		<?php

		// Widget type field (dropdown, dropdown with flags, buttons, etc)
		$widget_type_field_id = $this->get_field_id('widget_type');
		$widget_type_field_name = $this->get_field_name('widget_type');
		?>
		<p>
			<label for="<?= $widget_type_field_id ?>"><?= esc_html__('Widget type', Definitions::TEXT_DOMAIN) ?></label>
			<select class="widefat" id="<?= esc_attr($widget_type_field_id) ?>" name="<?= $widget_type_field_name ?>">
				<?php foreach($this->widget_types() as $type_id => $type_info): ?>
					<option value="<?= esc_attr($type_id) ?>" title="<?= esc_attr($type_info['title']) ?>" <?php selected($instance['widget_type'], $type_id) ?> ><?php
						echo esc_html($type_info['name']);
					?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
		// Option to show currency codes, instead of names
		// @since 4.12.0.210628
		$widget_currency_display_mode_field_id = $this->get_field_id('currency_display_mode');
		$widget_currency_display_mode_field_name = $this->get_field_name('currency_display_mode');
		?>
		<p>
			<label for="<?= $widget_currency_display_mode_field_id ?>"><?= esc_html__('Show', Definitions::TEXT_DOMAIN) ?></label>
			<select class="widefat" id="<?= esc_attr($widget_currency_display_mode_field_id) ?>" name="<?= $widget_currency_display_mode_field_name ?>">
				<option value="<?= self::SHOW_CURRENCY_NAME ?>" <?php selected($instance['currency_display_mode'], self::SHOW_CURRENCY_NAME) ?>>
					<?= esc_html__('Currency name (e.g. "United States Dollar")', Definitions::TEXT_DOMAIN) ?>
				</option>
				<option value="<?= self::SHOW_CURRENCY_CODE ?>" <?php selected($instance['currency_display_mode'], self::SHOW_CURRENCY_CODE) ?>>
					<?= esc_html__('Currency code (e.g. "USD")', Definitions::TEXT_DOMAIN) ?>
				</option>
			</select>
		</p>
		<?php
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 * @return array Updated safe values to be saved.
	 * @see WP_Widget::update()
	 */
	public function update($new_instance, $old_instance) {
		$instance = array();
		$instance['title'] = strip_tags(stripslashes($new_instance['title'] ?? ''));
		$instance['widget_type'] = strip_tags(stripslashes($new_instance['widget_type'] ?? ''));
		// @since 4.12.0.210628
		$instance['currency_display_mode'] = strip_tags(stripslashes($new_instance['currency_display_mode'] ?? ''));

		return $instance;
	}

	/**
	 * Returns an array of Currency => Currency Name pairs.
	 *
	 * @return array An array of Currency => Currency Name pairs.
	 */
	protected function get_currency_options() {
		$result = array();

		$settings_controller = WC_Aelia_CurrencySwitcher::settings();

		$enabled_currencies = $settings_controller->get_enabled_currencies();
		$exchange_rates = $settings_controller->get_exchange_rates();

		$woocommerce_currencies = get_woocommerce_currencies();

		foreach($exchange_rates as $currency => $fx_rate) {
			// Display only Currencies supported by WooCommerce
			$currency_name = !empty($woocommerce_currencies[$currency]) ? $woocommerce_currencies[$currency] : false;
			if(!empty($currency_name)) {
				// Skip currencies that are not enabled
				if(!in_array($currency, $enabled_currencies)) {
					continue;
				}

				// Display only currencies with a valid Exchange Rate
				if($fx_rate > 0) {
					$result[$currency] = $currency_name;
				}
				else {
					$this->misconfigured_currencies = true;
				}
			}
		}
		return $result;
	}

	/**
	 * Renders the currency selector widget when invoked using a shortcode.
	 *
	 * @param array widget_args An array of arguments for the widget.
	 * @return string
	 */
	public static function render_currency_selector($widget_args) {
		ob_start();

		$class = get_called_class();
		$widget_instance = new $class();
		$widget_instance->widget($widget_args);

		$output = ob_get_contents();
		@ob_end_clean();

		return $output;
	}


	/**
	 * Sets the hooks required by the class.
	 */
	protected function set_hooks() {
		// Display errors on the widget, if needed
		// @since 4.5.7.171124
		add_action('wc_aelia_cs_widget_before_currency_selector_form',
							 array($this, 'wc_aelia_cs_widget_before_currency_selector_form'), 10, 1);
	}

	/**
	 * Displays the errors related to the widget, if any.
	 *
	 * @param WC_Aelia_CurrencySwitcher_Widget widget
	 * @since 4.5.7.171124
	 */
	public function wc_aelia_cs_widget_before_currency_selector_form($widget) {
		// Only show errors to shop administrators
		if(!current_user_can('manage_woocommerce')) {
			return;
		}

		$currency_switcher = WC_Aelia_CurrencySwitcher::instance();
		$error_messages = array();
		// If one or more Currencies are misconfigured, inform the Administrators of
		// such issue
		if(!empty($widget->misconfigured_currencies)) {
			$error_messages[] = $currency_switcher->get_error_message(Definitions::ERR_MISCONFIGURED_CURRENCIES);
		}

		// Check if the "force currency by country" option is enabled only in the
		// frontend
		// @since 4.6.6.181004
		if(!is_admin()) {
			// Check if the "force currency by country" option is enabled. If it is,
			// inform the admin that the manual currency selection will have no effect
			$force_currency_by_country = WC_Aelia_CurrencySwitcher::settings()->get(Settings::FIELD_FORCE_CURRENCY_BY_COUNTRY, Settings::OPTION_DISABLED);
			if($force_currency_by_country != Settings::OPTION_DISABLED) {
				$error_messages[] = $currency_switcher->get_error_message(Definitions::ERR_MANUAL_CURRENCY_SELECTION_DISABLED);
			}
		}

		if(!empty($error_messages)) {
			?>
			<div class="error">
				<h5 class="title"><?= __('Warning', Definitions::TEXT_DOMAIN) ?></h5>
				<ul class="widget_errors">
					<?php foreach($error_messages as $message): ?>
						<li class="error_message"><?= $message ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}
}
