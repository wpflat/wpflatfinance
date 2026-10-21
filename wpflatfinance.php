<?php
/**
 * Plugin Name: WPFlat Finance
 * Description: The catalog, invoices, billing, payments, expenses, payroll and profit for the WPFlat suite. Needs WPFlat Core.
 * Version: 0.4.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Digitalzooks
 * License: GPL-2.0-or-later
 * Text Domain: wpflatfinance
 */

defined( 'ABSPATH' ) || exit;

define( 'WPFLATFINANCE_VERSION', '0.4.0' );
define( 'WPFLATFINANCE_FILE', __FILE__ );
define( 'WPFLATFINANCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFLATFINANCE_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! defined( 'WPFLATCORE_VERSION' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>WPFlat Finance</strong> needs the <strong>WPFlat Core</strong> plugin. Install and activate WPFlat Core, then WPFlat Finance will start.</p></div>';
					}
				}
			);
			return;
		}
		spl_autoload_register(
			function ( $class ) {
				if ( 0 === strpos( $class, 'WPFlatFinance\\' ) ) {
					$path = WPFLATFINANCE_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, 14 ) ) . '.php';
					if ( is_readable( $path ) ) {
						require_once $path;
					}
				}
			}
		);
		WPFlatFinance\Module::init();
	},
	1
);
