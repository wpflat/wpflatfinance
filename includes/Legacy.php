<?php
namespace WPFlatFinance;

use WPFlatCore\Migration\Legacy as Core_Legacy;
use WPFlatCore\Migration\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the money out of an old BizyDash backup: catalog, subscriptions, charges, invoices and payments, expenses and
 * payroll, plus the money fields that lived on clients, team members, projects, phases and tasks. Ids are kept.
 */
class Legacy {

	/** old table => new short table name */
	const TABLES = array(
		'services' => 'catalog', 'client_services' => 'client_services', 'renewals' => 'renewals', 'charges' => 'charges', 'invoices' => 'invoices', 'invoice_items' => 'invoice_items', 'invoice_installments' => 'invoice_installments',
		'payments' => 'payments', 'expenses' => 'expenses', 'recurring_expenses' => 'recurring_expenses', 'pay_allocations' => 'pay_allocations', 'pay_items' => 'pay_items', 'payouts' => 'payouts', 'email_log' => 'email_log',
	);

	public static function init() {
		add_filter( 'wpflatcore_legacy_import_finance', array( __CLASS__, 'import' ), 10, 2 );
	}

	private static function columns( $table ) {
		global $wpdb;
		return (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::table( $table ), 0 ); // phpcs:ignore WordPress.DB
	}

	/** @return array|\WP_Error */
	public static function import( $report, Reader $reader ) {
		global $wpdb;
		$had = 0;
		foreach ( self::TABLES as $new ) {
			$had += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $new ) ); // phpcs:ignore WordPress.DB
		}
		if ( $had && empty( Core_Legacy::module_state( 'finance' )['done'] ) ) {
			return new \WP_Error( 'wpflatfinance_not_empty', 'WPFlat Finance already has records. Importing would mix them with the old ones. Remove them first, or skip this import.', array( 'status' => 409 ) );
		}
		foreach ( array_merge( array_values( self::TABLES ), array( 'client_billing', 'team_pay', 'project_money', 'phase_money', 'task_money' ) ) as $new ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $new ) ); // phpcs:ignore WordPress.DB
		}
		$out  = array();
		$user = array( 'created_by' );
		$att  = array( 'invoices' => 'attachment_id', 'expenses' => 'attachment_id' );
		foreach ( self::TABLES as $old => $new ) {
			$cols = self::columns( $new );
			$n    = 0;
			$f    = 0;
			foreach ( $reader->rows( $old ) as $row ) {
				$data = array();
				foreach ( $cols as $c ) {
					if ( ! array_key_exists( $c, $row ) ) {
						continue;
					}
					$v = $row[ $c ];
					if ( in_array( $c, $user, true ) ) {
						$v = Core_Legacy::map_user( $v );
					} elseif ( isset( $att[ $old ] ) && $att[ $old ] === $c ) {
						$v = Core_Legacy::attachment_id( $v );
					}
					$data[ $c ] = $v;
				}
				false === $wpdb->insert( Schema::table( $new ), $data ) ? $f++ : $n++;
			}
			$out[ $old ] = $n;
			if ( $f ) {
				$out[ $old . '_failed' ] = $f;
			}
		}
		// Money fields that lived on other plugins' records.
		$out['client_billing'] = 0;
		foreach ( $reader->rows( 'clients' ) as $c ) {
			$wpdb->insert( Schema::table( 'client_billing' ), array( 'client_id' => $c['id'], 'currency' => $c['currency'], 'retainer_amount' => $c['retainer_amount'], 'retainer_period' => $c['retainer_period'] ) );
			$out['client_billing']++;
		}
		$out['team_pay'] = 0;
		foreach ( $reader->rows( 'team_members' ) as $m ) {
			$wpdb->insert( Schema::table( 'team_pay' ), array( 'team_member_id' => $m['id'], 'salary_amount' => $m['salary_amount'], 'salary_period' => $m['salary_period'], 'currency' => $m['currency'], 'bank_name' => $m['bank_name'], 'account_name' => $m['account_name'], 'account_number' => $m['account_number'] ) );
			$out['team_pay']++;
		}
		foreach ( $reader->rows( 'projects' ) as $p ) {
			$wpdb->insert( Schema::table( 'project_money' ), array( 'project_id' => $p['id'], 'price' => $p['price'], 'currency' => $p['currency'] ) );
		}
		foreach ( $reader->rows( 'project_phases' ) as $p ) {
			$wpdb->insert( Schema::table( 'phase_money' ), array( 'phase_id' => $p['id'], 'price' => $p['price'], 'team_budget' => $p['team_budget'] ) );
		}
		foreach ( $reader->rows( 'tasks' ) as $t ) {
			if ( (float) $t['pay_amount'] > 0 || (int) $t['invoice_id'] > 0 || (int) $t['client_service_id'] > 0 ) {
				$wpdb->insert( Schema::table( 'task_money' ), array( 'task_id' => $t['id'], 'pay_amount' => $t['pay_amount'], 'invoice_id' => $t['invoice_id'], 'client_service_id' => $t['client_service_id'] ) );
			}
		}
		// Settings, counters and the lists (billing periods, expense categories) that were set aside by Core.
		$set = Core_Legacy::rest( 'settings' );
		$new = array();
		foreach ( array_keys( Settings::defaults() ) as $k ) {
			if ( array_key_exists( $k, $set ) ) {
				$new[ $k ] = $set[ $k ];
			}
		}
		$opts = Core_Legacy::rest( 'options' );
		if ( ! empty( $opts['bizydash_mail_invoice'] ) ) {
			$new += array_filter( $opts['bizydash_mail_invoice'] );
		}
		if ( $new ) {
			Settings::update( $new );
		}
		if ( isset( $opts['bizydash_payout_next'] ) ) {
			update_option( 'wpflatfinance_payout_next', (int) $opts['bizydash_payout_next'] );
		}
		$lk = 0;
		$lt = \WPFlatCore\Database\Schema::table( 'wpflat_lookups' );
		foreach ( Core_Legacy::rest( 'lookups' ) as $row ) {
			if ( ! in_array( $row['type'], array( 'billing_period', 'billing_treatment', 'expense_category' ), true ) ) {
				continue;
			}
			$data = array( 'label' => $row['label'], 'color' => $row['color'], 'months' => $row['months'], 'sort_order' => $row['sort_order'], 'is_active' => $row['is_active'], 'is_system' => $row['is_system'] );
			$id   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $lt WHERE type=%s AND item_key=%s", $row['type'], $row['item_key'] ) ); // phpcs:ignore WordPress.DB
			$id ? $wpdb->update( $lt, $data, array( 'id' => (int) $id ) ) : $wpdb->insert( $lt, $data + array( 'type' => $row['type'], 'item_key' => $row['item_key'] ) );
			$lk++;
		}
		$out['lists'] = $lk;
		return $out;
	}
}
