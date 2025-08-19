<?php
/**
 * Class WC_ShipStation\Order_Util file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\ShipStation;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Trait Order_Util
 *
 * A proxy-style trait that will help keep our code more stable and cleaner during the
 * transition to WC Custom Order Tables.
 */
trait Order_Util {
	/**
	 * Constant variable for admin screen name.
	 *
	 * @var string $legacy_order_admin_screen.
	 */
	public static string $legacy_order_admin_screen = 'shop_order';

	/**
	 * Checks whether the OrderUtil class exists
	 *
	 * @return bool
	 */
	public static function wc_order_util_class_exists(): bool {
		return class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' );
	}

	/**
	 * Checks whether the OrderUtil class and the given method exist
	 *
	 * @param String $method_name Class method name.
	 *
	 * @return bool
	 */
	public static function wc_order_util_method_exists( string $method_name ): bool {
		if ( ! self::wc_order_util_class_exists() ) {
			return false;
		}

		if ( ! method_exists( 'Automattic\WooCommerce\Utilities\OrderUtil', $method_name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks whether we are using custom order tables.
	 *
	 * @return bool
	 */
	public static function custom_orders_table_usage_is_enabled(): bool {
		if ( ! self::wc_order_util_method_exists( 'custom_orders_table_usage_is_enabled' ) ) {
			return false;
		}

		return OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Returns the relevant order screen depending on whether
	 * custom order tables are being used.
	 *
	 * @return string
	 */
	public static function get_order_admin_screen(): string {
		if ( ! self::wc_order_util_method_exists( 'get_order_admin_screen' ) ) {
			return self::$legacy_order_admin_screen;
		}

		return OrderUtil::get_order_admin_screen();
	}

	/**
	 * Check if the object is WP_Post object.
	 *
	 * @param Mixed $post_object Either Post object or Order object.
	 *
	 * @return Boolean
	 */
	public static function is_wp_post( $post_object ): bool {
		return ( $post_object instanceof WP_Post );
	}

	/**
	 * Check if the object is WC_Order object.
	 *
	 * @param Mixed $post_object Either Post object or Order object.
	 *
	 * @return Boolean
	 */
	public static function is_wc_order( $post_object ): bool {
		return ( $post_object instanceof WC_Order );
	}

	/**
	 * Check if the object is either WP_Post or WC_Order object.
	 *
	 * @param Mixed $post_object Either Post object or Order object.
	 *
	 * @return Boolean
	 */
	public static function is_order_or_post( $post_object ): bool {
		return self::is_wp_post( $post_object ) || self::is_wc_order( $post_object );
	}

	/**
	 * Returns the WC_Order object from the object passed to
	 * the add_meta_box callback function.
	 *
	 * @param WC_Order|WP_Post $post_or_order_object Either Post object or Order object.
	 *
	 * @return WC_Order
	 */
	public static function init_theorder_object( $post_or_order_object ): WC_Order {
		if ( ! self::wc_order_util_method_exists( 'init_theorder_object' ) ) {
			return wc_get_order( $post_or_order_object->ID );
		}

		return OrderUtil::init_theorder_object( $post_or_order_object );
	}
}
