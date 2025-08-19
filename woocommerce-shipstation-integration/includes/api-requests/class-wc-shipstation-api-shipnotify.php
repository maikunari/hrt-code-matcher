<?php
/**
 * WC_Shipstation_API_Shipnotify file.
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WC_Shipstation_API_Shipnotify Class
 */
class WC_Shipstation_API_Shipnotify extends WC_Shipstation_API_Request {

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! WC_Shipstation_API::authenticated() ) {
			exit;
		}
	}

	/**
	 * See how many items in the order need shipping.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return int
	 */
	private function order_items_to_ship_count( $order ) {
		$needs_shipping = 0;

		foreach ( $order->get_items() as $item_id => $item ) {

			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;

			if ( ( $product instanceof WC_Product ) && $product->needs_shipping() ) {
				$needs_shipping += ( $item['qty'] - abs( $order->get_qty_refunded_for_item( $item_id ) ) );
			}
		}

		return $needs_shipping;
	}

	/**
	 * Check whether a given item ID is shippable item.
	 *
	 * @since 4.1.16
	 * @version 4.1.16
	 *
	 * @param WC_Order $order   Order object.
	 * @param int      $item_id Item ID.
	 *
	 * @return bool Returns true if item is shippable product.
	 */
	private function is_shippable_item( $order, $item_id ) {
		$item    = $order->get_item( $item_id );
		$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;

		return $product ? $product->needs_shipping() : false;
	}

	/**
	 * Get the order ID from the order number.
	 *
	 * @param string $order_number Order number.
	 * @return integer
	 */
	private function get_order_id( $order_number ) {
		// Try to match an order number in brackets.
		preg_match( '/\((.*?)\)/', $order_number, $matches );
		if ( is_array( $matches ) && isset( $matches[1] ) ) {
			$order_id = $matches[1];

		} elseif ( function_exists( 'wc_sequential_order_numbers' ) ) {
			// Try to convert number for Sequential Order Number.
			$order_id = wc_sequential_order_numbers()->find_order_by_order_number( $order_number );

		} elseif ( function_exists( 'wc_seq_order_number_pro' ) ) {
			// Try to convert number for Sequential Order Number Pro.
			$order_id = wc_seq_order_number_pro()->find_order_by_order_number( $order_number );

		} elseif ( function_exists( 'run_wt_advanced_order_number' ) ) {
			// Try to convert order number for Sequential Order Number for WooCommerce by WebToffee.
			// This plugin does not have any function or method that we can use to convert the number.
			// So need to do it manually.
			$orders = wc_get_orders(
				array(
					'wt_order_number' => $order_number,
					'limit'           => 1,
					'return'          => 'ids',
				)
			);

			$order_id = ( is_array( $orders ) && ! empty( $orders ) ) ? array_shift( $orders ) : 0;
		} else {
			// Default to not converting order number.
			$order_id = $order_number;
		}

		if ( 0 === $order_id ) {
			$order_id = $order_number;
		}

		/**
		 * This order number can be adjusted by using a filter which is done by the
		 * Sequential Order Numbers / Sequential Order Numbers Pro plugins. However
		 * there are also many other plugins which offer this functionality.
		 *
		 * When the ShipNotify request is received the "real" order number is
		 * needed to be able to update the correct order. The plugin uses the
		 * function get_order_id. This function has specific compatibility for both
		 * Sequential Order Numbers & Sequential Order Numbers Pro. However there
		 * is no additional filter for plugins to modify this order ID if needed.
		 *
		 * @param int Order ID.
		 *
		 * @since 4.1.6
		 */
		return apply_filters( 'woocommerce_shipstation_get_order_id', absint( $order_id ) );
	}

	/**
	 * Get Parsed XML response.
	 *
	 * @param  string $xml XML.
	 * @return SimpleXMLElement|false
	 */
	private function get_parsed_xml( $xml ) {
		if ( ! class_exists( 'WC_Safe_DOMDocument' ) ) {
			include_once 'class-wc-safe-domdocument.php';
		}

		libxml_use_internal_errors( true );

		$dom     = new WC_Safe_DOMDocument();
		$success = $dom->loadXML( $xml );

		if ( ! $success ) {
			$this->log( 'wpcom_safe_simplexml_load_string(): Error loading XML string' );
			return false;
		}

		if ( isset( $dom->doctype ) ) {
			$this->log( 'wpcom_safe_simplexml_import_dom(): Unsafe DOCTYPE Detected' );
			return false;
		}

		return simplexml_import_dom( $dom, 'SimpleXMLElement' );
	}

	/**
	 * Handling the request.
	 *
	 * @since 1.0.0
	 * @version 4.1.18
	 */
	public function request() {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase --- ShipStation provides an object with camelCase properties and method
		// phpcs:disable WordPress.Security.NonceVerification.Recommended --- Using WC_ShipStation_Integration::$auth_key for security verification
		$this->validate_input( array( 'order_number', 'carrier' ) );

		$timestamp          = wp_date( 'U' );
		$shipstation_xml    = file_get_contents( 'php://input' );
		$shipped_items      = array();
		$shipped_item_count = 0;
		$order_shipped      = false;
		$xml_order_id       = 0;

		$can_parse_xml = true;

		if ( empty( $shipstation_xml ) ) {
			$can_parse_xml = false;
			$this->log( __( 'Missing ShipNotify XML input.', 'woocommerce-shipstation-integration' ) );

			$mask = array(
				'auth_key'                         => '***',
				'woocommerce-login-nonce'          => '***',
				'_wpnonce'                         => '***',
				'woocommerce-reset-password-nonce' => '***',
			);

			$obfuscated_request = $mask + $_REQUEST;

			// For unknown reason raw post data can be empty. Log all requests
			// information might help figuring out the culprit.
			//
			// @see https://github.com/woocommerce/woocommerce-shipstation/issues/80.
			$this->log( '$_REQUEST: ' . print_r( $obfuscated_request, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r --- Its needed for logging
		}

		if ( ! function_exists( 'simplexml_import_dom' ) ) {
			$can_parse_xml = false;
			$this->log( __( 'Missing SimpleXML extension for parsing ShipStation XML.', 'woocommerce-shipstation-integration' ) );
		}

		$order_number = isset( $_GET['order_number'] ) ? wc_clean( wp_unslash( $_GET['order_number'] ) ) : '0';

		// Try to parse XML first since it can contain the real OrderID.
		if ( $can_parse_xml ) {
			$this->log( __( 'ShipNotify XML: ', 'woocommerce-shipstation-integration' ) . print_r( $shipstation_xml, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r --- Its needed for logging

			try {
				$xml = $this->get_parsed_xml( $shipstation_xml );
			} catch ( Exception $e ) {
				// translators: %s is an error message.
				$this->log( sprintf( __( 'Cannot parse XML : %s', 'woocommerce-shipstation-integration' ), $e->getMessage() ) );
				status_header( 500 );
			}

			if ( isset( $xml->ShipDate ) ) {
				$timestamp = strtotime( (string) $xml->ShipDate );
			}

			if ( isset( $xml->OrderID ) && $order_number !== (string) $xml->OrderID ) {
				$xml_order_id = (int) $xml->OrderID;
			}
		}

		// Get real order ID from XML otherwise try to convert it from the order number.
		$order_id        = ! $xml_order_id ? $this->get_order_id( $order_number ) : $xml_order_id;
		$tracking_number = empty( $_GET['tracking_number'] ) ? '' : wc_clean( wp_unslash( $_GET['tracking_number'] ) );
		$carrier         = empty( $_GET['carrier'] ) ? '' : wc_clean( wp_unslash( $_GET['carrier'] ) );
		$order           = wc_get_order( $order_id );

		if ( false === $order || ! is_object( $order ) ) {
			/* translators: 1: order id */
			$this->log( sprintf( __( 'Order %s can not be found.', 'woocommerce-shipstation-integration' ), $order_id ) );
			exit;
		}

		// Get real order ID from order object.
		$order_id = $order->get_id();
		if ( empty( $order_id ) ) {
			/* translators: 1: order id */
			$this->log( sprintf( __( 'Invalid order ID: %s', 'woocommerce-shipstation-integration' ), $order_id ) );
			exit;
		}

		// Maybe parse items from posted XML (if exists).
		if ( $can_parse_xml && isset( $xml->Items ) ) {
			$items = $xml->Items;
			if ( $items ) {
				foreach ( $items->Item as $item ) {
					$this->log( __( 'ShipNotify Item: ', 'woocommerce-shipstation-integration' ) . print_r( $item, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r --- Its needed for logging

					$item_sku    = wc_clean( (string) $item->SKU );
					$item_name   = wc_clean( (string) $item->Name );
					$qty_shipped = absint( $item->Quantity );

					if ( $item_sku ) {
						$item_sku = ' (' . $item_sku . ')';
					}

					$item_id = wc_clean( (int) $item->LineItemID );
					if ( ! $this->is_shippable_item( $order, $item_id ) ) {
						/* translators: 1: item name */
						$this->log( sprintf( __( 'Item %s is not shippable product. Skipping.', 'woocommerce-shipstation-integration' ), $item_name ) );
						continue;
					}

					$shipped_item_count += $qty_shipped;
					$shipped_items[]     = $item_name . $item_sku . ' x ' . $qty_shipped;
				}
			}
		}

		// Number of items in WC order.
		$total_item_count = $this->order_items_to_ship_count( $order );

		// If we have a list of shipped items, we can customise the note + see
		// if the order is not yet complete.
		if ( count( $shipped_items ) > 0 ) {
			$order_note = sprintf(
				/* translators: 1) shipped items 2) carrier's name 3) shipped date, 4) tracking number */
				__( '%1$s shipped via %2$s on %3$s with tracking number %4$s.', 'woocommerce-shipstation-integration' ),
				esc_html( implode( ', ', $shipped_items ) ),
				esc_html( $carrier ),
				date_i18n( get_option( 'date_format' ), $timestamp ),
				$tracking_number
			);

			$current_shipped_items = max( (int) $order->get_meta( '_shipstation_shipped_item_count', true ), 0 );

			if ( ( $current_shipped_items + $shipped_item_count ) >= $total_item_count ) {
				$order_shipped = true;
			}

			$this->log(
				sprintf(
					/* translators: 1) number of shipped items 2) total shipped items 3) order ID */
					__( 'Shipped %1$d out of %2$d items in order %3$s', 'woocommerce-shipstation-integration' ),
					$shipped_item_count,
					$total_item_count,
					$order_id
				)
			);

			$order->update_meta_data( '_shipstation_shipped_item_count', $current_shipped_items + $shipped_item_count );
			$order->save_meta_data();
		} else {
			// If we don't have items from SS and order items in WC, or cannot parse
			// the XML, just complete the order as a whole.
			$order_shipped = 0 === $total_item_count || ! $can_parse_xml;

			$order_note = sprintf(
				/* translators: 1) carrier's name 2) shipped date, 3) tracking number */
				__( 'Items shipped via %1$s on %2$s with tracking number %3$s (Shipstation).', 'woocommerce-shipstation-integration' ),
				esc_html( $carrier ),
				date_i18n( get_option( 'date_format' ), $timestamp ),
				$tracking_number
			);

			/* translators: 1: order id */
			$this->log( sprintf( __( 'No items found - shipping entire order %d.', 'woocommerce-shipstation-integration' ), $order_id ) );
		}

		$current_status = 'wc-' . $order->get_status();

		// Tracking information - WC Shipment Tracking extension.
		if ( class_exists( 'WC_Shipment_Tracking' ) ) {
			if ( function_exists( 'wc_st_add_tracking_number' ) ) {
				wc_st_add_tracking_number( $order_id, $tracking_number, strtolower( $carrier ), $timestamp );
			} else {
				// You're using Shipment Tracking < 1.4.0. Please update!
				$order->update_meta_data( '_tracking_provider', strtolower( $carrier ) );
				$order->update_meta_data( '_tracking_number', $tracking_number );
				$order->update_meta_data( '_date_shipped', $timestamp );
				$order->save_meta_data();
			}

			$is_customer_note = 0;
		} else {
			$is_customer_note = ( WC_ShipStation_Integration::$shipped_status !== $current_status ) ? 1 : 0;
		}

		$order->add_order_note( $order_note, $is_customer_note );

		// Update order status.
		if ( $order_shipped && WC_ShipStation_Integration::$shipped_status !== $current_status ) {
			$order->update_status( WC_ShipStation_Integration::$shipped_status );

			/* translators: 1) order ID 2) shipment status */
			$this->log( sprintf( __( 'Updated order %1$s to status %2$s', 'woocommerce-shipstation-integration' ), $order_id, WC_ShipStation_Integration::$shipped_status ) );
		}

		/**
		 * Trigger action for other integrations.
		 *
		 * @since 4.0.1
		 */
		do_action(
			'woocommerce_shipstation_shipnotify',
			$order,
			array(
				'tracking_number' => $tracking_number,
				'carrier'         => $carrier,
				'ship_date'       => $timestamp,
				'xml'             => $shipstation_xml,
			)
		);

		status_header( 200 );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}

return new WC_Shipstation_API_Shipnotify();
