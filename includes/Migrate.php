<?php
namespace BizyFinance;

defined( 'ABSPATH' ) || exit;

/**
 * BizyFinance used to be called BizyMoney. A site that ran BizyMoney keeps everything: its tables, settings, team role,
 * permissions and the on/off switch move to the new names once, the first time BizyFinance runs. Safe to run again.
 */
class Migrate {

	const DONE = 'bizyfinance_from_bizymoney';

	public static function init() {
		if ( get_option( self::DONE ) ) {
			return;
		}
		self::data();
		add_action( 'bizycore_ready', array( __CLASS__, 'access' ), 5 );
	}

	/** Tables and options. Runs before BizyFinance creates its own tables, so nothing is created empty beside the old data. */
	private static function data() {
		global $wpdb;
		foreach ( Schema::OWN as $short ) {
			$old = $wpdb->prefix . 'bizymoney_' . $short;
			$new = $wpdb->prefix . 'bizyfinance_' . $short;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) && ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) ) { // phpcs:ignore WordPress.DB
				$wpdb->query( 'RENAME TABLE `' . esc_sql( $old ) . '` TO `' . esc_sql( $new ) . '`' ); // phpcs:ignore WordPress.DB
			}
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", $wpdb->esc_like( 'bizymoney_' ) . '%' ) ) as $name ) { // phpcs:ignore WordPress.DB
			$new = 'bizyfinance_' . substr( $name, 10 );
			if ( false === get_option( $new ) ) {
				update_option( $new, get_option( $name ), false );
			}
			delete_option( $name );
		}
		delete_option( Install::OPTION ); // BizyFinance checks and completes the renamed tables
	}

	/** Role, permissions and the module switch. Needs BizyCore to be ready. */
	public static function access() {
		global $wpdb;
		if ( class_exists( '\BizyCore\Roles' ) ) {
			\BizyCore\Roles::sync(); // creates the Bizy Finance Team role
		}
		$caps = array( 'bizymoney_billing' => 'bizyfinance_billing', 'bizymoney_finance' => 'bizyfinance_finance' );
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $caps as $old => $new ) {
				if ( $role->has_cap( $old ) ) {
					$role->add_cap( $new, (bool) $role->capabilities[ $old ] );
					$role->remove_cap( $old );
				}
			}
		}
		if ( get_role( 'bizy_finance_team' ) && get_role( 'bizy_money_team' ) ) {
			foreach ( get_users( array( 'role' => 'bizy_money_team', 'fields' => 'ID' ) ) as $uid ) {
				$u = new \WP_User( (int) $uid );
				$u->add_role( 'bizy_finance_team' );
				$u->remove_role( 'bizy_money_team' );
			}
			remove_role( 'bizy_money_team' );
		}
		$caps_key = $wpdb->prefix . 'capabilities';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key=%s AND meta_value LIKE %s", $caps_key, '%bizymoney_%' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$u = new \WP_User( (int) $r['user_id'] );
			foreach ( $caps as $old => $new ) {
				if ( $u->has_cap( $old ) ) {
					$u->add_cap( $new );
					$u->remove_cap( $old );
				}
			}
		}
		$swap = function ( $list ) {
			return array_values( array_unique( array_map( function ( $m ) {
				return 'money' === $m ? 'finance' : $m;
			}, (array) $list ) ) );
		};
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM $wpdb->usermeta WHERE meta_key=%s", 'bizycore_manager_modules' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$v = maybe_unserialize( $r['meta_value'] );
			if ( is_array( $v ) && in_array( 'money', $v, true ) ) {
				update_user_meta( (int) $r['user_id'], 'bizycore_manager_modules', $swap( $v ) );
			}
		}
		$off = get_option( 'bizycore_modules_off', array() );
		if ( is_array( $off ) && in_array( 'money', $off, true ) ) {
			update_option( 'bizycore_modules_off', $swap( $off ), false );
		}
		$st = get_option( 'bizycore_legacy' );
		if ( is_array( $st ) && isset( $st['modules']['money'] ) && ! isset( $st['modules']['finance'] ) ) {
			$st['modules']['finance'] = $st['modules']['money'];
			unset( $st['modules']['money'] );
			update_option( 'bizycore_legacy', $st, false );
		}
		update_option( self::DONE, 1, false );
	}
}
