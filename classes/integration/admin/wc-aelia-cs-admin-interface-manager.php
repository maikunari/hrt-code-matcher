<?php
namespace Aelia\WC\CurrencySwitcher;
if(!defined('ABSPATH')) { exit; } // Exit if accessed directly

use Aelia\WC\CurrencySwitcher\Widgets\Currency_Selector;

/**
 * Handles the rendering of the elements of the Admin interface.
 */
class WC_Aelia_CS_Admin_Interface_Manager {
	protected $admin_views_path;

	// @var Aelia_Order The order being displayed. Used to determine the currency to be used
	protected $current_order;

	/**
	 * Adds meta boxes to the admin interface.
	 *
	 * @see add_meta_boxes().
	 */
	public function add_meta_boxes() {
		// Determine the ID of the edit order screen dynamically,
		// checking if the HPOS feature is enabled
		// @since 5.0.0.230203
		$edit_order_screen = aelia_is_hpos_feature_enabled() ? wc_get_page_screen_id('shop-order') : 'shop_order';

		add_meta_box('aelia_cs_order_currency_box',
								 __('Order currency', Definitions::TEXT_DOMAIN),
								 [$this, 'render_currency_selector_widget'],
								 $edit_order_screen,
								 'side',
								 'default');
	}

	/**
	 * Renders the currency selector widget in "new order" page.
	 *
	 * @param WC_Order|WP_Post $object
	 * @return void
	 */
	public function render_currency_selector_widget($object): void {
		$order = $object instanceof \WC_Order ? $object : wc_get_order($object->ID);

		if(Orders_Integration::allow_setting_order_currency($order)) {
			?>
			<p><?php
				echo wp_kses_post(implode(' ', [
					__('Set currency for this order.', Definitions::TEXT_DOMAIN),
				]));
			?></p>
			<?php
			$currency_selector_options = array(
				'title' => '',
				'widget_type' => 'dropdown',
				// Force the display of the currency selection button, which is required
				// on the Edit Order page
				// @since 4.12.6.210825
				'show_currency_selection_button' => true,
				'currency_selection_button_label' => __('Set order currency', Definitions::TEXT_DOMAIN),
				// @since 5.0.0.230203
				'selected_currency' => $order->get_currency(),
			);

			echo Currency_Selector::render_currency_selector($currency_selector_options);
			?>
			<p><?php
				echo wp_kses_post(implode(' ', [
					'<strong>',
					__('NOTE', Definitions::TEXT_DOMAIN),
					'</strong>: ',
					__('You can only set the order currency as long as the order is editable and it does not contain items.', Definitions::TEXT_DOMAIN),
					__('Please refer to our documentation for more information:', Definitions::TEXT_DOMAIN),
					sprintf('<a href="%2$s" target="_blank">%1$s</a>.', __('Aelia Currency Switcher - Setting the currency for manual orders',
									Definitions::TEXT_DOMAIN),
									Definitions::URL_MANUAL_ORDER_CURRENCY),
				]));
			?></p>
			<?php
		}
		else {
			$order_currency = $order->get_currency();
			// Prepare the text to use to display the order currency
			$order_currency_text = $order_currency;

			$currency_name = WC_Aelia_Currencies_Manager::get_currency_name($order_currency);
			// If a currency name is returned, append it to the code for displau.
			// If a currency name cannot be found, the method will return the currency
			// code itself. In such case, there would be no point in displaying the
			// code twice.
			if($currency_name != $order_currency) {
				$order_currency_text .= ' - ' . $currency_name;
			}
			?>
			<h4 class="order-currency"><?php
				echo esc_html($order_currency_text);
			?></h4>
			<?php
		}
	}

	/**
	 * Indicates if we are creating a new order. This method can detectd the "add order"
	 * action when the HPOS feature is enabled or disabled.
	 *
	 * @return bool
	 * @since 5.0.3.230503
	 */
	protected static function adding_new_order(): bool {
		if(aelia_is_hpos_feature_enabled()) {
			$adding_new_order = (($_GET['page'] ?? null) === 'wc-orders') && (($_GET['action'] ?? null) === 'new');
		}
		else {
			if(!function_exists('get_current_screen')) {
				return false;
			}

			$screen = get_current_screen();

			$adding_new_order = is_object($screen) && ($screen->post_type === 'shop_order') && ($screen->action === 'add');
		}
		return $adding_new_order;
	}

	/**
	 * Overrides the active currency, depending on the Admin page being rendered.
	 *
	 * @param string currency The currency passed to the filter.
	 * @return string
	 */
	public function woocommerce_currency($currency) {
		// Holds a list of order ID => currency pairs. Used as a basic
		// caching mechanism
		// @since 5.0.3.230503
		static $order_currencies = [];

		// Adding a new order, the active currency can be taken as a default
		if(self::adding_new_order()) {
			// When adding an order, the order currency is not yet set. We can take the
			// last selected currency as a default
			// @since 5.0.3.230503
			return WC_Aelia_CurrencySwitcher::instance()->get_selected_currency();
		}

		// Fetch the ID of the order being added or modified
		// @since 5.0.3.230503
		$editing_order_id = is_object($this->current_order)? $this->current_order->get_id() : WC_Aelia_CurrencySwitcher::editing_order();

		// If we already know the currency for the order, just return it
		if(!empty($order_currencies[$editing_order_id])) {
			return $order_currencies[$editing_order_id];
		}

		// If we know which order we are handling, we can take its currency immediately
		if(is_object($this->current_order)) {
			$order_currency = $this->current_order->get_currency();

			if(!empty($order_currency)) {
				// Cache the order currency, to save processing the next time the method runs
				// @since 5.0.3.230503
				$order_currencies[$editing_order_id] = $order_currency;

				$currency = $order_currency;
			}
		}
		else {
			// If we're editing an existing order, we must take the currency from it
			//
			// IMPORTANT
			// If the "compatibility mode" is enabled in the HPOS option, loading an order instance at this point
			// will automatically trigger the synchronisation of HPOS data to the legacy tables. This can cause an
			// error if the custom post types (e.g. "shop_order", "shop_order_refund", etc) haven't yet been registered.
			// To prevent this, we must wait loading the order instance until after the "woocommerce_after_register_post_type"
			// event
			//
			// @since 5.2.8.250501
			if(is_admin() && !defined('DOING_AJAX') && !empty($editing_order_id) && did_action('woocommerce_after_register_post_type')) {

				// Disable this filter temporarily, to prevent infinite recursion. This
				// is required due to changes in the admin pages in WooCommerce 2.7
				// @since @since 4.4.0.161221
				// @since WC 2.7
				remove_filter('woocommerce_currency', [$this, 'woocommerce_currency'], 20, 1);

				$order_currency = WC_Aelia_CurrencySwitcher::instance()->get_order_currency($editing_order_id);

				// Restore the filter
				add_filter('woocommerce_currency', [$this, 'woocommerce_currency'], 20, 1);

				if(!empty($order_currency)) {
					// Cache the order currency, to save processing the next time the method runs
					// @since 5.0.3.230503
					$order_currencies[$editing_order_id] = $order_currency;

					$currency = $order_currency;
				}
			}

			return $currency;
		}
	}

	/**
	 * Sets the hooks required by the class.
	 */
	protected function set_hooks() {
		add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
		add_action('manage_shop_order_posts_custom_column', [$this, 'manage_shop_order_posts_custom_column'], 1, 2);
		add_action('manage_shop_order_posts_custom_column', [$this, 'clear_current_order'], 50);

		add_filter('woocommerce_currency', [$this, 'woocommerce_currency'], 20, 1);

		// Coupons UI
		add_action('woocommerce_coupon_data_tabs', [$this, 'woocommerce_coupon_data_tabs'], 10);
		add_action('woocommerce_coupon_data_panels', [$this, 'woocommerce_coupon_data_panels'], 10);

		// @since 5.0.2.230413
		// @link https://developer.woocommerce.com/2022/10/11/hpos-upgrade-faqs/
		add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'manage_woocommerce_page_wc_orders_custom_column'], 1, 2);
		add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'clear_current_order'], 50);
	}

/**
	 * Displays additional data in the "orders list" page, such as the order total in shop's
	 * base currency.
	 *
	 * @param string $column_id The column being displayed.
	 * @param int $post_id The ID of the order being displayed.
	 * @since 5.0.2.230413
	 */
	public function manage_shop_order_posts_custom_column($column_id, $post_id) {
		switch($column_id) { // NOSONAR
			case 'order_total':
			case 'total_cost':
				// Keep track of the order being displayed. This information will be used to
				// use the correct formatting for the currency.
				// Here we use a copy of the order as an Aelia_Order instance. The class which provides additional methods
				// for the multi-currency features
				$this->current_order = WC_Aelia_CurrencySwitcher::instance()->get_order($post_id);
				$this->render_order_total_column($this->current_order);

				break;
			default:
				// Deliberately blank. Added for compliance with coding standards
		}
	}

	/**
	 * Renders the order column with the totals, to show them in both the order currency
	 * and the base currency.
	 *
	 * @param string $column_id Column ID to render.
	 * @param WC_Order $order Order object.
	 * @since 5.0.2.230413
	 */
	public function manage_woocommerce_page_wc_orders_custom_column($column_id, $order): void {
		switch($column_id) { // NOSONAR
			case 'order_total':
			case 'total_cost':
				// Keep track of the order being displayed. This information will be used to
				// use the correct formatting for the currency.
				// Here we use a copy of the order as an Aelia_Order instance. The class which provides additional methods
				// for the multi-currency features
				$this->current_order = WC_Aelia_CurrencySwitcher::instance()->get_order($order->get_id());
				$this->render_order_total_column($this->current_order);

				break;
			default:
				// Deliberately blank. Added for compliance with coding standards
		}
	}

	/**
	 * Renders the content of the order total column. This method runs before the one provided
	 * by WooCommerce for the same purpose, and it displays the order total in shop's base currency.
	 *
	 * @param Aelia_Order $order
	 * @return void
	 * @since 5.0.2.230413
	 */
	protected function render_order_total_column(Aelia_Order $order): void {
		$base_currency = WC_Aelia_CurrencySwitcher::settings()->base_currency();

		// If the order is not in base currency, display order total in base currency
		// before the one in order currency. It's not possible to display it after,
		// because WooCommerce core simply outputs the information and it's not
		// possible to modify it
		if($order->get_currency() !== $base_currency) {
			$order_total_base_currency = Currency_Formatting::instance()->format_price(
				$order->get_total_in_base_currency(),
				$base_currency
			);

			// Fetch the refunded total in base currency, to calculate the net total in base
			// currency
			$order_total_refunded_base_currency = $order->get_total_refunded_in_base_currency();
			if(!empty($order_total_refunded_base_currency)) {
				$net_total = $order->get_total_in_base_currency() - $order_total_refunded_base_currency;

				$formatted_total = '<del>' . strip_tags($order_total_base_currency) . '</del> ';
				$formatted_total .= '<ins>' . strip_tags(Currency_Formatting::instance()->format_price(
					$net_total,
					$base_currency
				)) . '</ins>';
			}
			else {
				// When no refunds are present, just show the order total in base currency
				$formatted_total = $order_total_base_currency;
			}

			// Add a tooltip to explain what the extra totals are for. The tooltip also explains why
			// a fully refunded order might show a net higher or lower than zero, due to the difference
			// in the exchange rates used for the original order and the refund(s)
			// @since 4.6.3.180821
			$total_base_currency_title = implode(' ', [
				__('Order total in base currency (estimated).', Definitions::TEXT_DOMAIN),
				'<br/><br/>',
				'<strong>',
				__('Note', Definitions::TEXT_DOMAIN),
				'</strong>:',
				__('Refunded orders might show a net total in base currency higher or lower than zero.', Definitions::TEXT_DOMAIN),
				__('This is normal, and can happen when the exchange rate applied to a refund is different from the rate applied to the original order.', Definitions::TEXT_DOMAIN),
			]);

			echo '<div class="order_total_base_currency">';
			echo '<span class="tips" data-tip="' . esc_attr($total_base_currency_title) . '">(' . wp_kses_post($formatted_total) . ')</span>';
			echo '</div>';
		}
	}

	/**
	 * Resets the current order after the "order total" column is displayed,
	 * to prevent it from changing the active currency.
	 */
	public function clear_current_order(): void {
		$this->current_order = null;
	}

	/**
	 * Loads (includes) a View file.
	 *
	 * @param string view_file_name The name of the view file to include.
	 */
	protected function load_view($view_file_name) {
		$file_to_load = $this->get_view($view_file_name);
		include $file_to_load; // NOSONAR
	}

	/**
	 * Retrieves an admin view.
	 *
	 * @param string The view file name (without path).
	 * @return string
	 */
	protected function get_view($view_file_name) {
		return $this->admin_views_path . '/' . $view_file_name;
	}

	/**
	 * Class constructor.
	 */
	public function __construct() {
		// TODO Determine Views Path dynamically, depending on WooCommerce version
		$this->admin_views_path = WC_Aelia_CurrencySwitcher::instance()->path('views') . '/admin';

		$this->set_hooks();
	}

	/**
	 * Adds a new tab to the Edit Coupon page. The tab will allow to set
	 * currency-specific parameters for a coupon.
	 *
	 * @param array tabs The tabs passed by WooCommerce.
	 * @return array The array of tabs, with the additional one.
	 * @since 3.8.0.150813
	 */
	public function woocommerce_coupon_data_tabs($tabs) {
		$tabs['multi_currency'] = array(
			'label' => __('Multi-currency', Definitions::TEXT_DOMAIN),
			'target' => 'multi_currency_coupon_data',
			'class' => 'multi_currency_coupon_data',
		);

		return $tabs;
	}

	/**
	 * Renders the new tab in Edit Coupon page. The tab will allow to set
	 * currency-specific parameters for a coupon.
	 *
	 * @since 3.8.0.150813
	 */
	public function woocommerce_coupon_data_panels() {
		include $this->get_view('coupons_currencydata_view.php'); // NOSONAR
	}
}
