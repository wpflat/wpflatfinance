<?php
namespace WPFlatFinance;

defined( 'ABSPATH' ) || exit;

/**
 * Moving up from WPFlat Invoice. When WPFlat Finance starts on a site that has been using WPFlat Invoice, it copies the invoicing data
 * across (invoices and their lines, instalments, payments, the catalog, recurring billing, billing currencies, the email log, settings
 * and who may bill), keeps every id so links and public invoice addresses carry on working, and switches WPFlat Invoice off. WPFlat
 * Invoice's own tables are left untouched as a copy. If WPFlat Finance already holds invoicing data nothing is copied (nothing is mixed).
 */
class Handover {

	const DONE   = 'wpflatfinance_from_invoice';
	const NOTICE = 'wpflatfinance_handover_notice';
	const PLUGIN = 'wpflatinvoice/wpflatinvoice.php';

	/** Short table names shared by the two plugins (identical meaning, same ids). */
	const TABLES = array( 'catalog', 'client_services', 'renewals', 'charges', 'invoices', 'invoice_items', 'invoice_installments', 'payments', 'email_log', 'client_billing' );

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		if ( get_option( self::DONE ) ) {
			return;
		}
		$active = in_array( self::PLUGIN, (array) get_option( 'active_plugins', array() ), true );
		if ( ! $active && ! self::old_tables_exist() ) {
			return; // nothing to take over
		}
		if ( ! self::old_tables_exist() ) { // WPFlat Invoice was on but never stored anything
			self::finish( array(), 'WPFlat Invoice was switched off. It had no invoices yet.' );
			return;
		}
		$have = self::counts( true );
		$old  = self::counts( false );
		if ( array_sum( $have ) > 0 && array_sum( $old ) > 0 ) {
			self::finish( array(), 'WPFlat Finance already has invoicing records, so nothing was copied from WPFlat Invoice and the two were not mixed. WPFlat Invoice was switched off; its data is still in the database.', true );
			return;
		}
		if ( array_sum( $old ) > 0 ) {
			self::copy();
		}
		self::options();
		add_action( 'init', array( __CLASS__, 'access' ), 1 );
		self::finish( $old, '' );
	}

	private static function old_tables_exist() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wpflatinvoice_invoices' ) ); // phpcs:ignore WordPress.DB
	}

	private static function counts( $finance ) {
		global $wpdb;
		$out = array();
		foreach ( self::TABLES as $t ) {
			$name = $wpdb->prefix . ( $finance ? 'wpflatfinance_' : 'wpflatinvoice_' ) . $t;
			$out[ $t ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$name`" ) : 0; // phpcs:ignore WordPress.DB
		}
		return $out;
	}

	private static function copy() {
		global $wpdb;
		foreach ( self::TABLES as $t ) {
			$from = $wpdb->prefix . 'wpflatinvoice_' . $t;
			$to   = $wpdb->prefix . 'wpflatfinance_' . $t;
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $from ) ) || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $to ) ) ) { // phpcs:ignore WordPress.DB
				continue;
			}
			$cols = array_intersect( (array) $wpdb->get_col( "SHOW COLUMNS FROM `$from`" ), (array) $wpdb->get_col( "SHOW COLUMNS FROM `$to`" ) ); // phpcs:ignore WordPress.DB
			if ( ! $cols ) {
				continue;
			}
			$list = '`' . implode( '`,`', $cols ) . '`';
			$wpdb->query( "INSERT INTO `$to` ($list) SELECT $list FROM `$from`" ); // phpcs:ignore WordPress.DB
		}
	}

	/** Settings: what was set up in WPFlat Invoice wins over Finance's fresh defaults. */
	private static function options() {
		$old = get_option( 'wpflatinvoice_settings', null );
		if ( is_array( $old ) ) {
			update_option( 'wpflatfinance_settings', array_merge( (array) get_option( 'wpflatfinance_settings', array() ), $old ), false );
		}
		$chk = get_option( 'wpflatinvoice_invoice_checks', null );
		if ( null !== $chk && false === get_option( 'wpflatfinance_invoice_checks', false ) ) {
			update_option( 'wpflatfinance_invoice_checks', $chk, false );
		}
	}

	/** Who may bill: the permission and the team role move to their WPFlat Finance names. */
	public static function access() {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( 'wpflatinvoice_billing' ) ) {
				$role->add_cap( 'wpflatfinance_billing', (bool) $role->capabilities['wpflatinvoice_billing'] );
				$role->remove_cap( 'wpflatinvoice_billing' );
			}
		}
		if ( get_role( 'wpflat_invoice_team' ) ) {
			$new = get_role( 'wpflat_finance_team' );
			if ( ! $new ) {
				add_role( 'wpflat_finance_team', 'WPFlat Finance Team', (array) get_role( 'wpflat_invoice_team' )->capabilities );
			}
			foreach ( get_users( array( 'role' => 'wpflat_invoice_team', 'fields' => 'ID' ) ) as $uid ) {
				$u = new \WP_User( (int) $uid );
				$u->add_role( 'wpflat_finance_team' );
				$u->remove_role( 'wpflat_invoice_team' );
			}
			remove_role( 'wpflat_invoice_team' );
		}
		global $wpdb;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key=%s AND meta_value LIKE %s", $wpdb->prefix . 'capabilities', '%wpflatinvoice_billing%' ) ) as $uid ) { // phpcs:ignore WordPress.DB
			$u = new \WP_User( (int) $uid );
			$u->add_cap( 'wpflatfinance_billing' );
			$u->remove_cap( 'wpflatinvoice_billing' );
		}
	}

	private static function finish( array $counts, $message, $skipped = false ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( self::PLUGIN, true );
		update_option( self::DONE, '1', false );
		if ( '' === $message ) {
			$parts = array();
			foreach ( array( 'invoices' => 'invoices', 'payments' => 'payments', 'catalog' => 'catalog items', 'renewals' => 'recurring bills', 'charges' => 'one-off bills' ) as $k => $label ) {
				if ( ! empty( $counts[ $k ] ) ) {
					$parts[] = $counts[ $k ] . ' ' . $label;
				}
			}
			$message = 'Your invoicing moved from WPFlat Invoice into WPFlat Finance' . ( $parts ? ': ' . implode( ', ', $parts ) : '' ) . '. Everything keeps its number and its public link. WPFlat Invoice was switched off; you can delete it.';
		}
		update_option( self::NOTICE, array( 'message' => $message, 'skipped' => (bool) $skipped ), false );
		if ( class_exists( '\WPFlatCore\Services\Activity' ) && ! $skipped ) {
			\WPFlatCore\Services\Activity::log( 'settings', 0, 0, 'updated', 'Invoicing moved from WPFlat Invoice into WPFlat Finance' );
		}
	}

	public static function notice() {
		$n = get_option( self::NOTICE );
		if ( ! is_array( $n ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-' . ( ! empty( $n['skipped'] ) ? 'warning' : 'success' ) . ' is-dismissible" data-wpflat-handover><p><strong>WPFlat Finance:</strong> ' . esc_html( $n['message'] ) . '</p></div>';
		delete_option( self::NOTICE ); // shown once
	}
}
