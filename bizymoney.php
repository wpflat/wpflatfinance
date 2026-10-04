<?php
/**
 * Plugin Name: BizyMoney
 * Description: The catalog, invoices, billing, payments, expenses, payroll and profit for the Bizy suite. Needs BizyCore.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Digitalzooks
 * License: GPL-2.0-or-later
 * Text Domain: bizymoney
 */

defined( 'ABSPATH' ) || exit;

define( 'BIZYMONEY_VERSION', '0.1.0' );
define( 'BIZYMONEY_FILE', __FILE__ );
define( 'BIZYMONEY_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIZYMONEY_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! defined( 'BIZYCORE_VERSION' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p><strong>BizyMoney</strong> needs the <strong>BizyCore</strong> plugin. Install and activate BizyCore, then BizyMoney will start.</p></div>';
					}
				}
			);
			return;
		}
		spl_autoload_register(
			function ( $class ) {
				if ( 0 === strpos( $class, 'BizyMoney\\' ) ) {
					$path = BIZYMONEY_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, 10 ) ) . '.php';
					if ( is_readable( $path ) ) {
						require_once $path;
					}
				}
			}
		);
		BizyMoney\Module::init();
	},
	1
);
