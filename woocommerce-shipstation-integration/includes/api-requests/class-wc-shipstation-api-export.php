<?php
/**
 * WC_Shipstation_API_Export file.
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WooCommerce\ShipStation\Order_Util;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;

/**
 * WC_Shipstation_API_Export Class
 */
class WC_Shipstation_API_Export extends WC_Shipstation_API_Request {
	use Order_Util;

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( ! WC_Shipstation_API::authenticated() ) {
			exit;
		}
	}

	/**
	 * Preparing `IN` sql statement using `WPDB::prepare()`.
	 *
	 * @param array $values IN values.
	 */
	private static function prepare_in( $values ) {
		return implode(
			',',
			array_map(
				function ( $value ) {
					global $wpdb;

					// Use the official prepare() function to sanitize the value.
					return $wpdb->prepare( '%s', $value );
				},
				$values
			)
		);
	}

	/**
	 * Do the request
	 */
	public function request() {
		global $wpdb;
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase --- ShipStation provides an object with camelCase properties and method
		// phpcs:disable WordPress.Security.NonceVerification.Recommended --- Using WC_ShipStation_Integration::$auth_key for security verification
		$this->validate_input( array( 'start_date', 'end_date' ) );

		header( 'Content-Type: text/xml' );
		$xml               = new DOMDocument( '1.0', 'utf-8' );
		$xml->formatOutput = true;
		$page              = max( 1, isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1 );
		$exported          = 0;
		$tz_offset         = get_option( 'gmt_offset' ) * 3600;
		$raw_start_date    = isset( $_GET['start_date'] ) ? urldecode( wc_clean( wp_unslash( $_GET['start_date'] ) ) ) : false;
		$raw_end_date      = isset( $_GET['end_date'] ) ? urldecode( wc_clean( wp_unslash( $_GET['end_date'] ) ) ) : false;
		$store_weight_unit = get_option( 'woocommerce_weight_unit' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// Parse start and end date.
		if ( $raw_start_date && false === strtotime( $raw_start_date ) ) {
			$month      = substr( $raw_start_date, 0, 2 );
			$day        = substr( $raw_start_date, 2, 2 );
			$year       = substr( $raw_start_date, 4, 4 );
			$time       = substr( $raw_start_date, 9, 4 );
			$start_date = gmdate( 'Y-m-d H:i:s', strtotime( $year . '-' . $month . '-' . $day . ' ' . $time ) );
		} else {
			$start_date = gmdate( 'Y-m-d H:i:s', strtotime( $raw_start_date ) );
		}

		if ( $raw_end_date && false === strtotime( $raw_end_date ) ) {
			$month    = substr( $raw_end_date, 0, 2 );
			$day      = substr( $raw_end_date, 2, 2 );
			$year     = substr( $raw_end_date, 4, 4 );
			$time     = substr( $raw_end_date, 9, 4 );
			$end_date = gmdate( 'Y-m-d H:i:s', strtotime( $year . '-' . $month . '-' . $day . ' ' . $time ) );
		} else {
			$end_date = gmdate( 'Y-m-d H:i:s', strtotime( $raw_end_date ) );
		}

		$orders_to_export = wc_get_orders(
			array(
				'date_modified' => strtotime( $start_date ) . '...' . strtotime( $end_date ),
				'type'          => 'shop_order',
				'status'        => WC_ShipStation_Integration::$export_statuses,
				'return'        => 'ids',
				'orderby'       => 'date_modified',
				'order'         => 'DESC',
				'paged'         => $page,
				'limit'         => WC_SHIPSTATION_EXPORT_LIMIT,
			)
		);

		$total_orders_to_export = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'date_modified' => strtotime( $start_date ) . '...' . strtotime( $end_date ),
				'status'        => WC_ShipStation_Integration::$export_statuses,
				'paginate'      => true,
				'return'        => 'ids',
			)
		);

		$max_results = $total_orders_to_export->total;

		$orders_xml = $xml->createElement( 'Orders' );

		foreach ( $orders_to_export as $order_id ) {
			/**
			 * Allow third party to skip the export of certain order ID.
			 *
			 * @param boolean Flag to skip the export.
			 * @param int Order ID.
			 *
			 * @since 4.1.42
			 */
			if ( ! apply_filters( 'woocommerce_shipstation_export_order', true, $order_id ) ) {
				continue;
			}

			/**
			 * Allow third party to change the order object.
			 *
			 * @param WC_Order $order Order object.
			 *
			 * @since 4.1.42
			 */
			$order = apply_filters( 'woocommerce_shipstation_export_get_order', wc_get_order( $order_id ) );

			if ( ! self::is_wc_order( $order ) ) {
				/* translators: 1: order id */
				$this->log( sprintf( __( 'Order %s can not be found.', 'woocommerce-shipstation-integration' ), $order_id ) );
				continue;
			}

			/**
			 * Currency code and exchange rate filters.
			 *
			 * These two filters allow 3rd parties to modify the currency code
			 * and exchange rate used for the order before exporting to ShipStation.
			 *
			 * This can be necessary in cases where the order currency doesn't match
			 * the ShipStation account currency. ShipStation does not do currency
			 * conversion, so the conversion must be done before the order is exported.
			 *
			 * @param string $currency_code The currency code to use for the order.
			 *
			 * @since 4.3.7
			 */
			$currency_code = apply_filters( 'woocommerce_shipstation_export_currency_code', $order->get_currency(), $order );
			/**
			 * Allow 3rd parties to modify the exchange rate used for the order before exporting to ShipStation.
			 *
			 * @param float $exchange_rate The exchange rate to use for the order.
			 *
			 * @since 4.3.7
			 */
			$exchange_rate = apply_filters( 'woocommerce_shipstation_export_exchange_rate', 1.00, $order );

			$order_xml              = $xml->createElement( 'Order' );
			$formatted_order_number = ltrim( $order->get_order_number(), '#' );
			$this->xml_append( $order_xml, 'OrderNumber', $formatted_order_number );
			$this->xml_append( $order_xml, 'OrderID', $order_id );

			// Sequence of date ordering: date paid > date completed > date created.
			$order_timestamp = $order->get_date_paid() ? $order->get_date_paid() : ( $order->get_date_completed() ? $order->get_date_completed() : $order->get_date_created() );
			$order_timestamp = $order_timestamp->getOffsetTimestamp();

			$order_timestamp -= $tz_offset;
			$order_status     = ( 'refunded' === $order->get_status() ) ? 'cancelled' : $order->get_status();
			$this->xml_append( $order_xml, 'OrderDate', gmdate( 'm/d/Y H:i', $order_timestamp ), false );
			$this->xml_append( $order_xml, 'OrderStatus', $order_status );
			$this->xml_append( $order_xml, 'PaymentMethod', $order->get_payment_method() );
			$this->xml_append( $order_xml, 'OrderPaymentMethodTitle', $order->get_payment_method_title() );
			$last_modified = strtotime( $order->get_date_modified()->date( 'm/d/Y H:i' ) ) - $tz_offset;
			$this->xml_append( $order_xml, 'LastModified', gmdate( 'm/d/Y H:i', $last_modified ), false );
			$this->xml_append( $order_xml, 'ShippingMethod', implode( ' | ', $this->get_shipping_methods( $order ) ) );

			$this->xml_append( $order_xml, 'CurrencyCode', $currency_code, false );

			$order_total = $order->get_total() - $order->get_total_refunded();
			$tax_amount  = wc_round_tax_total( $order->get_total_tax() );

			// Maybe convert the order total and tax amount.
			if ( 1.00 !== $exchange_rate ) {
				$order_total = wc_format_decimal( ( $order_total * $exchange_rate ), wc_get_price_decimals() );
				$tax_amount  = wc_round_tax_total( $order->get_total_tax() * $exchange_rate );
			}

			$this->xml_append( $order_xml, 'OrderTotal', $order_total, false );
			$this->xml_append( $order_xml, 'TaxAmount', $tax_amount, false );

			if ( class_exists( 'WC_COG' ) ) {
				$wc_cog_order_total_cost = wc_format_decimal( $order->get_meta( '_wc_cog_order_total_cost', true ) );

				// Maybe convert the order total cost of goods.
				if ( 1.00 !== $exchange_rate ) {
					$wc_cog_order_total_cost = wc_format_decimal( ( $wc_cog_order_total_cost * $exchange_rate ), wc_get_price_decimals() );
				}

				$this->xml_append( $order_xml, 'CostOfGoods', $wc_cog_order_total_cost, false );
			}

			$shipping_total = $order->get_shipping_total();

			// Maybe convert the shipping total.
			if ( 1.00 !== $exchange_rate ) {
				$shipping_total = wc_format_decimal( ( $shipping_total * $exchange_rate ), wc_get_price_decimals() );
			}

			$this->xml_append( $order_xml, 'ShippingAmount', $shipping_total, false );
			$this->xml_append( $order_xml, 'CustomerNotes', $order->get_customer_note() );
			$this->xml_append( $order_xml, 'InternalNotes', implode( ' | ', $this->get_order_notes( $order ) ) );

			// Custom fields - 1 is used for coupon codes.
			$this->xml_append( $order_xml, 'CustomField1', implode( ' | ', version_compare( WC_VERSION, '3.7', 'ge' ) ? $order->get_coupon_codes() : $order->get_used_coupons() ) );

			// Custom fields 2 and 3 can be mapped to a custom field via the following filters.

			/**
			 * Custom fields 2 can be mapped to a custom field via the following filters.
			 *
			 * @since 4.0.1
			 */
			$meta_key = apply_filters( 'woocommerce_shipstation_export_custom_field_2', '' );
			if ( $meta_key ) {
				/**
				 * Allowing third party to modify the custom field 2 value.
				 *
				 * @since 4.1.0
				 */
				$this->xml_append( $order_xml, 'CustomField2', apply_filters( 'woocommerce_shipstation_export_custom_field_2_value', $order->get_meta( $meta_key, true ), $order_id ) );
			}

			/**
			 * Custom fields 3 can be mapped to a custom field via the following filters.
			 *
			 * @since 4.0.1
			 */
			$meta_key = apply_filters( 'woocommerce_shipstation_export_custom_field_3', '' );
			if ( $meta_key ) {
				/**
				 * Allowing third party to modify the custom field 3 value.
				 *
				 * @since 4.1.0
				 */
				$this->xml_append( $order_xml, 'CustomField3', apply_filters( 'woocommerce_shipstation_export_custom_field_3_value', $order->get_meta( $meta_key, true ), $order_id ) );
			}

			// Customer data.
			$customer_xml = $xml->createElement( 'Customer' );
			$this->xml_append( $customer_xml, 'CustomerCode', $order->get_billing_email() );

			$billto_xml = $xml->createElement( 'BillTo' );
			$this->xml_append( $billto_xml, 'Name', $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$this->xml_append( $billto_xml, 'Company', $order->get_billing_company() );
			$this->xml_append( $billto_xml, 'Phone', $order->get_billing_phone() );
			$this->xml_append( $billto_xml, 'Email', $order->get_billing_email() );
			$customer_xml->appendChild( $billto_xml );

			$shipto_xml   = $xml->createElement( 'ShipTo' );
			$address_data = $this->get_address_data( $order );

			$this->xml_append( $shipto_xml, 'Name', $address_data['name'] );
			$this->xml_append( $shipto_xml, 'Company', $address_data['company'] );
			$this->xml_append( $shipto_xml, 'Address1', $address_data['address1'] );
			$this->xml_append( $shipto_xml, 'Address2', $address_data['address2'] );
			$this->xml_append( $shipto_xml, 'City', $address_data['city'] );
			$this->xml_append( $shipto_xml, 'State', $address_data['state'] );
			$this->xml_append( $shipto_xml, 'PostalCode', $address_data['postcode'] );
			$this->xml_append( $shipto_xml, 'Country', $address_data['country'] );
			$this->xml_append( $shipto_xml, 'Phone', $address_data['phone'] );

			$customer_xml->appendChild( $shipto_xml );

			$order_xml->appendChild( $customer_xml );

			// Item data.
			$found_item = false;
			$items_xml  = $xml->createElement( 'Items' );
			// Merge arrays without loosing indexes.
			$order_items = $order->get_items() + $order->get_items( 'fee' );
			foreach ( $order_items as $item_id => $item ) {
				$product                = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;
				$item_needs_no_shipping = ! $product || ! $product->needs_shipping();
				$item_not_a_fee         = 'fee' !== $item->get_type();

				/**
				 * Allow third party to exclude the item for when an item does not need shipping or is a fee.
				 *
				 * @since 4.1.31
				 */
				if ( apply_filters( 'woocommerce_shipstation_no_shipping_item', $item_needs_no_shipping && $item_not_a_fee, $product, $item ) ) {
					continue;
				}

				$found_item = true;
				$item_xml   = $xml->createElement( 'Item' );
				$this->xml_append( $item_xml, 'LineItemID', $item_id );

				if ( 'fee' === $item->get_type() ) {
					$this->xml_append( $item_xml, 'Name', $item->get_name() );
					$this->xml_append( $item_xml, 'Quantity', 1, false );

					$item_total = $order->get_item_total( $item, false, true );

					// Maybe convert fee item total.
					if ( 1.00 !== $exchange_rate ) {
						$item_total = wc_format_decimal( ( $item_total * $exchange_rate ), wc_get_price_decimals() );
					}

					$this->xml_append( $item_xml, 'UnitPrice', $item_total, false );
				}

				// handle product specific data.
				if ( $product && $product->needs_shipping() ) {
					$this->xml_append( $item_xml, 'SKU', $product->get_sku() );
					$this->xml_append( $item_xml, 'Name', $item->get_name() );
					// image data.
					$image_id  = $product->get_image_id();
					$image_src = $image_id ? wp_get_attachment_image_src( $image_id, 'woocommerce_gallery_thumbnail' ) : '';
					$image_url = is_array( $image_src ) ? current( $image_src ) : '';

					$this->xml_append( $item_xml, 'ImageUrl', $image_url );

					if ( 'kg' === $store_weight_unit ) {
						$this->xml_append( $item_xml, 'Weight', wc_get_weight( $product->get_weight(), 'g' ), false );
						$this->xml_append( $item_xml, 'WeightUnits', 'Grams', false );
					} else {
						$this->xml_append( $item_xml, 'Weight', $product->get_weight(), false );
						$this->xml_append( $item_xml, 'WeightUnits', $this->get_shipstation_weight_units( $store_weight_unit ), false );
					}

					// current item quantity - refunded quantity.
					$item_qty = $item->get_quantity() - abs( $order->get_qty_refunded_for_item( $item_id ) );
					$this->xml_append( $item_xml, 'Quantity', $item_qty, false );

					$item_subtotal = $order->get_item_subtotal( $item, false, true );

					// Maybe convert item subtotal.
					if ( 1.00 !== $exchange_rate ) {
						$item_subtotal = wc_format_decimal( ( $item_subtotal * $exchange_rate ), wc_get_price_decimals() );
					}

					$this->xml_append( $item_xml, 'UnitPrice', $item_subtotal, false );
				}

				if ( $item->get_meta_data() ) {
					add_filter( 'woocommerce_is_attribute_in_product_name', '__return_false' );
					$formatted_meta = $item->get_formatted_meta_data();

					if ( ! empty( $formatted_meta ) ) {
						$options_xml = $xml->createElement( 'Options' );

						foreach ( $formatted_meta as $meta_key => $meta ) {
							$option_xml = $xml->createElement( 'Option' );
							$this->xml_append( $option_xml, 'Name', $meta->display_key );
							$this->xml_append( $option_xml, 'Value', wp_strip_all_tags( $meta->display_value ) );
							$options_xml->appendChild( $option_xml );
						}

						$item_xml->appendChild( $options_xml );
					}
				}

				$items_xml->appendChild( $item_xml );
			}

			if ( ! $found_item ) {
				continue;
			}

			// Append cart level discount line.
			if ( $order->get_total_discount() ) {
				$item_xml = $xml->createElement( 'Item' );
				$this->xml_append( $item_xml, 'SKU', 'total-discount' );
				$this->xml_append( $item_xml, 'Name', __( 'Total Discount', 'woocommerce-shipstation-integration' ) );
				$this->xml_append( $item_xml, 'Adjustment', 'true', false );
				$this->xml_append( $item_xml, 'Quantity', 1, false );

				$order_total_discount = $order->get_total_discount() * -1;

				// Maybe convert order total discount.
				if ( 1.00 !== $exchange_rate ) {
					$order_total_discount = wc_format_decimal( ( $order_total_discount * $exchange_rate ), wc_get_price_decimals() );
				}

				$this->xml_append( $item_xml, 'UnitPrice', $order_total_discount, false );
				$items_xml->appendChild( $item_xml );
			}

			// Append items XML.
			$order_xml->appendChild( $items_xml );

			/**
			 * Allow third party to modify the XML that will be exported.
			 *
			 * @since 4.1.39
			 */
			$orders_xml->appendChild( apply_filters( 'woocommerce_shipstation_export_order_xml', $order_xml ) );

			++$exported;

			// Add order note to indicate it has been exported to Shipstation.
			if ( 'yes' !== $order->get_meta( '_shipstation_exported', true ) ) {
				$order->add_order_note( __( 'Order has been exported to Shipstation', 'woocommerce-shipstation-integration' ) );
				$order->update_meta_data( '_shipstation_exported', 'yes' );
				$order->save_meta_data();
			}
		}

		$orders_xml->setAttribute( 'page', $page );
		$orders_xml->setAttribute( 'pages', ceil( $max_results / WC_SHIPSTATION_EXPORT_LIMIT ) );
		$xml->appendChild( $orders_xml );
		echo $xml->saveXML(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, we want the output to be XML.

		/* translators: 1: total count */
		$this->log( sprintf( __( 'Exported %s orders', 'woocommerce-shipstation-integration' ), $exported ) );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Get address data from Order.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @result array.
	 */
	public function get_address_data( $order ) {
		$shipping_country = $order->get_shipping_country();
		$shipping_address = $order->get_shipping_address_1();

		$address = array();

		if ( empty( $shipping_country ) && empty( $shipping_address ) ) {
			$name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

			$address['name']     = $name;
			$address['company']  = $order->get_billing_company();
			$address['address1'] = $order->get_billing_address_1();
			$address['address2'] = $order->get_billing_address_2();
			$address['city']     = $order->get_billing_city();
			$address['state']    = $order->get_billing_state();
			$address['postcode'] = $order->get_billing_postcode();
			$address['country']  = $order->get_billing_country();
			$address['phone']    = $order->get_billing_phone();
		} else {
			$name = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();

			$address['name']     = $name;
			$address['company']  = $order->get_shipping_company();
			$address['address1'] = $order->get_shipping_address_1();
			$address['address2'] = $order->get_shipping_address_2();
			$address['city']     = $order->get_shipping_city();
			$address['state']    = $order->get_shipping_state();
			$address['postcode'] = $order->get_shipping_postcode();
			$address['country']  = $order->get_shipping_country();
			$address['phone']    = $order->get_billing_phone();
		}

		/**
		 * Allow third party to modify the address data.
		 *
		 * @param array Address data.
		 * @param WC_Order Order object.
		 * @param boolean
		 *
		 * @since 4.2.0
		 */
		return apply_filters( 'woocommerce_shipstation_export_address_data', $address, $order, true );
	}

	/**
	 * Get shipping method names
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return array
	 */
	private function get_shipping_methods( $order ) {
		$shipping_methods      = $order->get_shipping_methods();
		$shipping_method_names = array();

		foreach ( $shipping_methods as $shipping_method ) {
			// Replace non-AlNum characters with space.
			$method_name             = preg_replace( '/[^A-Za-z0-9 \-\.\_,]/', '', $shipping_method['name'] );
			$shipping_method_names[] = $method_name;
		}

		return $shipping_method_names;
	}

	/**
	 * Get Order Notes
	 *
	 * @param  WC_Order $order Order object.
	 * @return array
	 */
	private function get_order_notes( $order ) {
		$args = array(
			'post_id' => $order->get_id(),
			'approve' => 'approve',
			'type'    => 'order_note',
		);

		remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10, 1 );
		$notes = get_comments( $args );
		add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10, 1 );

		$order_notes = array();

		foreach ( $notes as $note ) {
			if ( 'WooCommerce' !== $note->comment_author ) {
				$order_notes[] = $note->comment_content;
			}
		}

		return $order_notes;
	}

	/**
	 * Append XML as cdata.
	 *
	 * @param DomDocument $append_to XML Dom to append to.
	 * @param string      $name Element name.
	 * @param mixed       $value Element value.
	 * @param boolean     $cdata Using cData or not.
	 */
	private function xml_append( $append_to, $name, $value, $cdata = true ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase --- Using DomDocument class from PHP which using camelCase
		$data = $append_to->appendChild( $append_to->ownerDocument->createElement( $name ) );

		if ( $cdata ) {
			$child_node = empty( $append_to->ownerDocument->createCDATASection( $value ) ) ? $append_to->ownerDocument->createCDATASection( '' ) : $append_to->ownerDocument->createCDATASection( $value );
		} else {
			$child_node = empty( $append_to->ownerDocument->createTextNode( $value ) ) ? $append_to->ownerDocument->createTextNode( '' ) : $append_to->ownerDocument->createTextNode( $value );
		}

		$data->appendChild( $child_node );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Convert weight unit abbreviation to Shipstation enum (Pounds, Ounces, Grams).
	 *
	 * @param string $unit_abbreviation Weight unit abbreviation.
	 */
	private function get_shipstation_weight_units( $unit_abbreviation ) {
		switch ( $unit_abbreviation ) {
			case 'lbs':
				return 'Pounds';
			case 'oz':
				return 'Ounces';
			case 'g':
				return 'Grams';
			default:
				return $unit_abbreviation;
		}
	}
}

return new WC_Shipstation_API_Export();
