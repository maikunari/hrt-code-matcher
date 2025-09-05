<?php
/**
 * Plugin Name: WooCommerce Dutify
 * Description: Dutify's WooCommerce plugin. 
 * Version: 2.6.1
 * Requires at least: 6.3
 * Requires PHP:      7.2
 * Author: Dutify
 * Author URI: https://dutify.com
 */
 
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'WOO_Dutify' ) ) :

/**
 * Main WOO_Dutify Class
 *
 * @class WOO_Dutify
 */
final class WOO_Dutify {

	/**
	 * @var string
	 */
	public $version = '2.6.1';

	/**
	 * @var WOO_Dutify The single instance of the class
	 */
	protected static $_instance = null;

	/**
	 * Main WOO_Dutify Instance
	 *
	 * Ensures only one instance of WOO_Dutify is loaded or can be loaded
	 *
	 * @return Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * WOO_Dutify Constructor
	 * @access public
	 */
	public function __construct() {
	
		// Return if WooCommerce plugin is not activate
		if( ! function_exists( 'is_plugin_active' ) ) {
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		
		if( ! (
			in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) )
			||
			is_plugin_active( 'woocommerce/woocommerce.php' )
			||
			is_plugin_active_for_network( 'woocommerce/woocommerce.php' )
		) ) {
			return;
		}

		// Define constants
		$this->define_constants();
		
		// Include required files
		$this->includes();

		// Hooks
		add_action( 'init', array( $this, 'init' ), 0 );
		add_filter( 'plugin_action_links_' . WOO_DUTIFY_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}


	/**
	 * Define WOO_DUTIFY Constants
	 */
	private function define_constants() {
		define( 'WOO_DUTIFY_PLUGIN_FILE', __FILE__ );
		define( 'WOO_DUTIFY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
		define( 'WOO_DUTIFY_VERSION', $this->version );
		define( 'WOO_DUTIFY_PLUGIN_URL', $this->plugin_url() );
		define( 'WOO_DUTIFY_PLUGIN_PATH', $this->plugin_path() );
		define( 'WOO_DUTIFY_TEXT_DOMAIN', 'woo_dutify' );
	}
	
	/**
	 * Include required core files
	 */
	private function includes() {
		
		if( !is_admin() ) {
			include_once( 'includes/class-woo-dutify-functions.php' );
		}
		
		if( is_admin() ) {
			include_once( 'includes/admin/class-woo-dutify-settings.php' );
		}
	}
	
	/**
	 * Load Localisation files
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'woo_dutify', false, plugin_basename( dirname( __FILE__ ) ) . "/i18n/languages" );
	}

	/**
	 * Get the plugin url
	 *
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}
	
	/**
	 * Get the plugin path
	 *
	 * @return string
	 */
	public function plugin_path() {
		return untrailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Init Cards when WordPress Initialises
	 */
	public function init() {
		$this->load_plugin_textdomain();
		do_action( 'woo_dutify_init' );
		
		$attributes = array();
		$slugs = array();
		$result = 0;
		
		$attributes = wc_get_attribute_taxonomies();

		$slugs = wp_list_pluck( $attributes, 'attribute_name' );
		
		if ( ! in_array( 'dutify_class_id', $slugs ) ) {

			$args = array();
			
			$args = array(
				'slug'    => 'dutify_class_id',
				'name'   => __( 'Dutify Classification ID', WOO_DUTIFY_TEXT_DOMAIN ),
				'type'    => 'select',
				'orderby' => 'menu_order',
				'has_archives'  => false,
			);

			$result = wc_create_attribute( $args );
			
		}
		
		if ( ! in_array( 'dutify_country_origin', $slugs ) ) {
			
			$args = array();

			$args = array(
				'slug'    => 'dutify_country_origin',
				'name'   => __( 'Dutify Country of Origin', WOO_DUTIFY_TEXT_DOMAIN ),
				'type'    => 'select',
				'orderby' => 'menu_order',
				'has_archives'  => false,
			);

			$result = wc_create_attribute( $args );
			
		}

		if ( ! in_array( 'dutify_hs_code', $slugs ) ) {
			$args = array(
				'slug'    => 'dutify_hs_code',
				'name'   => __( 'Dutify HS Code', WOO_DUTIFY_TEXT_DOMAIN ),
				'type'    => 'select',
				'orderby' => 'menu_order',
				'has_archives'  => false,
			);
			$result = wc_create_attribute( $args );
		}

		if ( ! in_array( 'dutify_hs_code_country', $slugs ) ) {
			$args = array(
				'slug'    => 'dutify_hs_code_country',
				'name'   => __( 'Dutify HS Code Country', WOO_DUTIFY_TEXT_DOMAIN ),
				'type'    => 'select',
				'orderby' => 'menu_order',
				'has_archives'  => false,
			);
			$result = wc_create_attribute( $args );
		}
		
	}	
	
	/**
	 * Show action links on the plugin screen.
	 *
	 * @param	mixed $links Plugin Action links
	 * @return	array
	 */
	public function plugin_action_links( $links ) {
		$action_links = array(
			'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=woo_dutify' ) . '" title="' . esc_attr( __( 'View Settings', WOO_DUTIFY_TEXT_DOMAIN ) ) . '">' . __( 'Settings', WOO_DUTIFY_TEXT_DOMAIN ) . '</a>',
		);

		return array_merge( $action_links, $links );
	}

}

endif;

/**
 * Returns the main instance of WOO_Dutify to prevent the need to use globals
 *
 * @return WOO_Dutify
 */

function WOO_DUTIFY() {
	return WOO_Dutify::instance();
}

// Global for backwards compatibility.
$GLOBALS['woo_dutify'] = WOO_DUTIFY();
