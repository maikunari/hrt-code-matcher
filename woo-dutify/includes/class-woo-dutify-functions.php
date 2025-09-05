<?php
/**
 * Common Functions
 *
 * @class WOO_DUTIFY_Functions
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class WOO_DUTIFY_Functions {
	const MAX_REQUEST_ATTEMPTS = 2;
	const DUTIFY_BASE_URL = 'https://dutify.com';

	public $input_currency_code = '';
	public $woo_dutify_api_token = '';
	public $url = '';
	public $response = array();
	public $error_message = '';
	public $apiBody = null;
	public $cart = array();
	public $product = array();
	public $product_title = '';
	public $woo_dutify_is_enabled = '';
	public $woo_dutify_only_checkout_calculations = '';
	public $woo_dutify_duty_calculation = '';
	public $woo_dutify_sales_tax_calculation = '';
	public $woo_dutify_additional_tax_calculation = '';
	public $woo_dutify_excise_tax_calculation = '';
	public $woo_dutify_use_preferential_rates = '';
	public $woo_dutify_use_ddu = '';
	public $woo_dutify_duty_tax_label = '';
	public $woo_dutify_handling_fee_label = '';
	public $woo_dutify_ddu_label = '';
	public $product_id = 0;
	public $item_id = 0;
	public $tax_display_cart = '';
	public $unit_price = 0;
	public $quantity = 0;
	public $attributes = array();
	public $name = '';
	public $pa_dutify_class_id = array();
	public $pa_dutify_country_origin = array();
	public $product_classification_id = 0;
	public $origin_country_code = '';
	public $pa_dutify_hs_code = '';
	public $pa_dutify_hs_code_country = '';
	public $product_classification_hs = '';
	public $product_classification_hs_country_code = '';
	public $item = array();
	public $line_items = array();
	public $store_raw_country = '';
	public $split_country = array();
	public $store_country = '';
	public $shipping_country = '';
	public $user_country = '';
	public $user_ip = '';
	public $freegeoipjson = '';
	public $jsondata = null;
	public $export_country_code = '';
	public $import_country_code = '';
	public $shipping_cost = 0;
	public $store_name = '';
	public $requestData = array();
	public $combined_duties_total = 0;
	public $handling_fee_total = 0;

	/**
	 * Constructor
	 *
	 * @access public
	 */
	public function __construct() {

		add_action('woocommerce_cart_calculate_fees', array($this, 'woo_add_cart_fee'));
		add_action('woocommerce_checkout_create_order_line_item', array($this, 'dutify_add_values_to_order_item_meta'), 10, 4);
		add_filter('woocommerce_order_item_get_formatted_meta_data', array($this, 'unset_specific_order_item_meta_data'), 10, 2);

	}


	public static function dutify_call_api($data) {

		$input_currency_code = get_woocommerce_currency();

		$woo_dutify_api_token = WC_Admin_Settings::get_option('woo_dutify_api_token');

		$url = '/api/v1/landed_cost_calculator?output_currency_code=' . $input_currency_code;


		# user agent to include current wordpress version, woocommerce version and dutify plugin version
		$user_agent = 'WordPress/' . get_bloginfo('version') . '; WooCommerce/' . WC()->version . '; Plugin/' . WOO_DUTIFY_VERSION;
		$response = wp_remote_post(self::DUTIFY_BASE_URL . $url, array(
			'method'      => 'POST',
			'httpversion' => '1.1',
			'body'    	  => $data,
			'headers'     => array("X-API-KEY" => $woo_dutify_api_token, "Content-type" => "application/json", "Accept" => "application/json", "User-Agent" => $user_agent)
			)
		);

		if (is_wp_error($response)) {
			$error_message = $response->get_error_message();
			throw new Exception("Something went wrong: $error_message");
		} else {
			$apiBody = json_decode(wp_remote_retrieve_body($response), true);
			return $apiBody;
		}

	}



	function dutify_add_values_to_order_item_meta($item, $cart_item_key, $values, $order) {

		if(isset($values['hs_code'])) {
			$item->add_meta_data(__('HS Code', WOO_DUTIFY_TEXT_DOMAIN), $values['hs_code'], true);
		}
	}



	function unset_specific_order_item_meta_data($formatted_meta, $item){
		// Disable for emails and order received page
		if(is_admin())
			return $formatted_meta;

		if(is_wc_endpoint_url() && !is_wc_endpoint_url('order-received'))
			return $formatted_meta;

		foreach($formatted_meta as $key => $meta){
			if(in_array($meta->key, array('HS Code')))
				unset($formatted_meta[$key]);
		}

		return $formatted_meta;
	}


	public static function add_hs_code_text_to_cart_item() {

		$cart = WC()->cart->cart_contents;
		foreach($cart as $cart_item_id => $cart_item) {
			if(!empty($_SESSION[$cart_item['key']]))
			{
				$cart_item['hs_code'] = $_SESSION[$cart_item['key']];
				WC()->cart->cart_contents[$cart_item_id] = $cart_item;
			}
		}
		WC()->cart->set_session();

	}


	/**
	 * Add Fee
	 *
	 * @return array
	 */
	function woo_add_cart_fee()
	{

		if (is_admin() && !defined('DOING_AJAX')) {
			return;
		}

		$woo_dutify_is_enabled = WC_Admin_Settings::get_option('woo_dutify_is_enabled');

		if($woo_dutify_is_enabled == 'yes')
		{

			$woo_dutify_only_checkout_calculations = WC_Admin_Settings::get_option('woo_dutify_only_checkout_calculations');

			if ($woo_dutify_only_checkout_calculations == 'yes' && !is_checkout()) {
				return;
			}

			# get the calculation preference from the settings
			$woo_dutify_duty_calculation = WC_Admin_Settings::get_option("woo_dutify_duty_calculation");
			$woo_dutify_sales_tax_calculation = WC_Admin_Settings::get_option("woo_dutify_sales_tax_calculation");
			$woo_dutify_additional_tax_calculation = WC_Admin_Settings::get_option("woo_dutify_additional_tax_calculation");
			$woo_dutify_excise_tax_calculation = WC_Admin_Settings::get_option("woo_dutify_excise_tax_calculation");
			$woo_dutify_use_preferential_rates = WC_Admin_Settings::get_option("woo_dutify_use_preferential_rates");
			$woo_dutify_use_ddu = WC_Admin_Settings::get_option("woo_dutify_use_ddu");

			# labels
			$woo_dutify_duty_tax_label = WC_Admin_Settings::get_option("woo_dutify_duty_tax_label");
			$woo_dutify_handling_fee_label = WC_Admin_Settings::get_option('woo_dutify_handling_fee_label');
			$woo_dutify_ddu_label = WC_Admin_Settings::get_option("woo_dutify_duty_tax_ddu_label");

			$certificate_of_origin = false;
			$incorporate_handling_fee = true;

			if(!empty($woo_dutify_handling_fee_label)) {
				$incorporate_handling_fee = false;
			}

			if($woo_dutify_use_preferential_rates == 'yes') {
				$certificate_of_origin = true;
			}

			if (WC()->cart->get_cart_contents_count() > 0)
			{

				foreach(WC()->cart->get_cart() as $cart_item)
				{
					$item_id = $cart_item['key'];
					$product_id = $cart_item['product_id'];
					$product = $cart_item['data'];
					$tax_display_cart = get_option('woocommerce_tax_display_cart');
					$product_title = get_the_title($product_id);
					$unit_price = wc_get_price_excluding_tax($product);
					$quantity = $cart_item['quantity'];
					$attributes = $product->get_attributes();

				// If product has attributes, get classification id, origin country code, hs code, and hs code country
				if (!empty($attributes)) {
					$product_terms = wc_get_product_terms($product_id, 'pa_dutify_class_id', array('fields' => 'names'));
					$product_classification_id = array_shift($product_terms);
					// Nullify $product_classification_id if empty string
					if (empty($product_classification_id)) {
						$product_classification_id = null;
					}

					$product_terms = wc_get_product_terms($product_id, 'pa_dutify_country_origin', array('fields' => 'names'));
					// Nullify $origin_country_code if empty string
					$origin_country_code = array_shift($product_terms);
					$origin_country_code = !empty($origin_country_code) ? strtoupper($origin_country_code) : null;

					$product_terms = wc_get_product_terms($product_id, 'pa_dutify_hs_code', array('fields' => 'names'));
					$product_classification_hs = array_shift($product_terms);
					// Nullify $product_classification_hs if empty string
					if (empty($product_classification_hs)) {
						$product_classification_hs = null;
					}

					$product_terms = wc_get_product_terms($product_id, 'pa_dutify_hs_code_country', array('fields' => 'names'));
					// Nullify $product_classification_hs_country_code if empty string
					$product_classification_hs_country_code = array_shift($product_terms);
					$product_classification_hs_country_code = !empty($product_classification_hs_country_code) ? strtoupper($product_classification_hs_country_code) : null;
				} else {
					$product_classification_id = null;
					$origin_country_code = null;
					$product_classification_hs = null;
					$product_classification_hs_country_code = null;
				}

					// Populate item details
					$item['origin_country_code'] = $origin_country_code;
					$item['certificate_of_origin'] = $certificate_of_origin;
					$item['unit_price'] = $unit_price;
					$item['quantity'] = $quantity;
					$item['product_title'] = $product_title;
					$item['product_classification_id'] = $product_classification_id;
					$item['external_id'] = $item_id;
					$item['product_classification_hs'] = $product_classification_hs;
					$item['product_classification_hs_country_code'] = $product_classification_hs_country_code;

					// Add item to line items array
					$line_items[] = $item;
				}

				$store_raw_country = get_option('woocommerce_default_country');
				$split_country = explode(":", $store_raw_country);
				$store_country = strtoupper($split_country[0]);

				$shipping_country = WC()->customer->get_shipping_country();
				$shipping_state = WC()->customer->get_shipping_state();

				if (!empty($shipping_country))
				{
					$user_country = $shipping_country;
				}
				else
				{
					$user_ip = getenv('HTTP_CLIENT_IP') ? : getenv('HTTP_X_FORWARDED_FOR') ? : getenv('HTTP_X_FORWARDED') ? : getenv('HTTP_FORWARDED_FOR') ? : getenv('HTTP_FORWARDED') ? : getenv('REMOTE_ADDR');
					$freegeoipjson = file_get_contents("http://ipinfo.io/" . $user_ip);
					$jsondata = json_decode($freegeoipjson);
					$user_country = $jsondata->country;
				}

				if (!empty($shipping_state))
				{
					$user_state = $shipping_state;
				}
				else
				{
					$user_state = '';
				}


				$export_country_code = $store_country;
				$import_country_code = $user_country;
				$import_state_code = $user_state;
				$input_currency_code = get_woocommerce_currency();

				$shipping_cost = WC()->cart->get_shipping_total();

				$store_name = get_bloginfo('name') . ' - WooCommerce Dutify Integration';

				if(is_numeric($shipping_cost) && $shipping_cost > 0)
				{
					$requestData = array(
						"data" => array(
							"export_country_code" => $export_country_code,
							"import_country_code" => $import_country_code,
							"import_state_code" => $import_state_code,
							"input_currency_code" => $input_currency_code,
							"description"		  => $store_name,
							"shipping_cost" 	  => $shipping_cost,
							"line_items" 		  => $line_items
						)
					);
				}
				else
				{
					$requestData = array(
						"data" => array(
							"export_country_code" => $export_country_code,
							"import_country_code" => $import_country_code,
							"import_state_code" => $import_state_code,
							"input_currency_code" => $input_currency_code,
							"description"		  => $store_name,
							"line_items" 		  => $line_items
						)
					);
				}

				# only call the API if the export and import country codes are different
				if($export_country_code != $import_country_code)
				{
					$maxAttempts = self::MAX_REQUEST_ATTEMPTS;

					for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
						try {
							$response = WOO_DUTIFY_Functions::dutify_call_api(json_encode($requestData));

							// If the API call is successful, break the loop.
							if ($response) {
								break;
							}
						} catch (Exception $e) {
							// If this was the last attempt, rethrow the exception.
							if ($attempt === $maxAttempts - 1) {
								throw $e;
							}
						}

						// Wait for an exponential amount of time.
						sleep(pow(2, $attempt));
					}
				}

				# if the API call is successful, add the fees to the cart
				if (!empty($response) && !empty($response['data']) && !empty($response['data']['type']) && $response['data']['type'] == 'landed_cost_result')
				{
					$attributes = $response['data']['attributes'];

					# get dutify response attributes
					$duty_total = $attributes['duty_total'];
					$sales_tax_total = $attributes['sales_tax_total'];
					$additional_tax_and_charges_total = $attributes['additional_tax_and_charges_total'];
					$excise_total = $attributes['excise_total'];
					$handling_fee_total =  $attributes['handling_fee_total'];

					$combined_duties_total = 0.0;

					if ($woo_dutify_duty_calculation == 'yes') {
						$combined_duties_total += $duty_total;
					}

					if ($woo_dutify_sales_tax_calculation == 'yes') {
						$combined_duties_total += $sales_tax_total;
					}

					if ($woo_dutify_additional_tax_calculation == 'yes') {
						$combined_duties_total += $additional_tax_and_charges_total;
					}

					if ($woo_dutify_excise_tax_calculation == 'yes') {
						$combined_duties_total += $excise_total;
					}

					if ($woo_dutify_use_ddu == 'yes') {
						WC()->cart->add_fee($woo_dutify_duty_tax_label, $combined_duties_total, false);
						WC()->cart->add_fee($woo_dutify_ddu_label, -$combined_duties_total, false);
					} else {
						if ($incorporate_handling_fee) {
							WC()->cart->add_fee($woo_dutify_duty_tax_label, $combined_duties_total + $handling_fee_total, false);
						} else {
							WC()->cart->add_fee($woo_dutify_duty_tax_label, $combined_duties_total, false);
							WC()->cart->add_fee($woo_dutify_handling_fee_label, $handling_fee_total, false);
						}
					}

					if(!empty($response['included']))
					{
						foreach($response['included'] as $attr)
						{
							if($attr['type'] == 'landed_cost_result_item')
							{
								if(!empty($attr['attributes']['hs_code']))
								{
									$hs_code = $attr['attributes']['hs_code'];
									$_SESSION[$attr['attributes']['external_id']] = $hs_code;
								}
							}
						}

						WOO_DUTIFY_Functions::add_hs_code_text_to_cart_item();

					}

				}
			}

		}

	}

}

new WOO_DUTIFY_Functions();
