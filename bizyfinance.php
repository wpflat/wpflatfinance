<?php
/**
 * Plugin Name: BizyFinance
 * Description: The catalog, invoices, billing, payments, expenses, payroll and profit for the Bizy suite. Needs BizyCore.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Digitalzooks
 * License: GPL-2.0-or-later
 * Text Domain: bizyfinance
 */

defined( 'ABSPATH' ) || exit;

define( 'BIZYFINANCE_VERSION', '0.2.0' );
define( 'BIZYFINANCE_FILE', __FILE__ );
define( 'BIZYFINANCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIZYFINANCE_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! defined( 'BIZYCORE_VERSION' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>BizyFinance</strong> needs the <strong>BizyCore</strong> plugin. Install and activate BizyCore, then BizyFinance will start.</p></div>';
					}
				}
			);
			return;
		}
		spl_autoload_register(
			function ( $class ) {
				if ( 0 === strpos( $class, 'BizyFinance\\' ) ) {
					$path = BIZYFINANCE_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, 12 ) ) . '.php';
					if ( is_readable( $path ) ) {
						require_once $path;
					}
				}
			}
		);
		BizyFinance\Module::init();
	},
	1
);
