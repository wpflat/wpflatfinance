<?php
namespace BizyMoney\Services;

use BizyCore\Services\Activity;

use BizyMoney\Schema;

defined( 'ABSPATH' ) || exit;

/** Monthly pay run: one salary earning per salaried person per month, then pay people from one screen. */
class Payroll {

	public static function clean_period( $period ) {
		return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period ) ? $period : gmdate( 'Y-m', strtotime( Billing::today() ) );
	}

	private static function bounds( $period ) {
		$start = $period . '-01';
		return array( $start, gmdate( 'Y-m-t', strtotime( $start ) ) );
	}

	/** Active salaried people who were with the business during the month. */
	public static function eligible( $period ) {
		global $wpdb;
		list( $start, $end ) = self::bounds( $period );
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::tm() . " tm WHERE status='active' AND salary_amount > 0 AND (start_date IS NULL OR start_date <= %s) AND (end_date IS NULL OR end_date >= %s) ORDER BY name", $end, $start ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** Create the month's salary earnings (never twice for the same person and month). */
	public static function run( $period ) {
		global $wpdb;
		$period = self::clean_period( $period );
		list( , $end ) = self::bounds( $period );
		$items   = Schema::table( 'pay_items' );
		$now     = current_time( 'mysql' );
		$label   = wp_date( 'F Y', strtotime( $period . '-01' ) );
		$created = 0;
		$skipped = 0;
		$total   = 0.0;
		foreach ( self::eligible( $period ) as $m ) {
			$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $items WHERE team_member_id=%d AND kind='salary' AND period=%s AND status <> 'void'", $m['id'], $period ) ); // phpcs:ignore WordPress.DB
			if ( $has ) {
				$skipped++;
				continue;
			}
			$wpdb->insert( $items, array( 'team_member_id' => (int) $m['id'], 'kind' => 'salary', 'period' => $period, 'description' => 'Salary — ' . $label, 'amount' => round( (float) $m['salary_amount'], 2 ), 'currency' => $m['currency'] ? $m['currency'] : \BizyMoney\Settings::get( 'default_currency' ), 'earned_on' => $end, 'status' => 'payable', 'department_id' => (int) $m['department_id'], 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
			$created++;
			$total += (float) $m['salary_amount'];
		}
		if ( $created ) {
			Activity::log( 'payroll', 0, 0, 'run', sprintf( 'Payroll for %s: %d salar%s added (%s)', $label, $created, 1 === $created ? 'y' : 'ies', number_format( $total, 2 ) ) );
		}
		return array( 'period' => $period, 'created' => $created, 'skipped' => $skipped );
	}

	/** One row per person for the screen: this month's salary + everything else they are owed. */
	public static function overview( $period ) {
		global $wpdb;
		$period = self::clean_period( $period );
		list( $start, $end ) = self::bounds( $period );
		$tm = Schema::tm();
		$pi = Schema::table( 'pay_items' );

		$ids = array();
		foreach ( self::eligible( $period ) as $m ) {
			$ids[ (int) $m['id'] ] = true;
		}
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT team_member_id FROM $pi WHERE status='payable'" ) as $mid ) { // phpcs:ignore WordPress.DB
			$ids[ (int) $mid ] = true;
		}
		$rows = array();
		if ( $ids ) {
			$in      = implode( ',', array_keys( $ids ) );
			$members = $wpdb->get_results( "SELECT t.*, dp.name AS department_name FROM $tm t LEFT JOIN " . Schema::table( 'brands' ) . " dp ON dp.id=t.department_id WHERE t.id IN ($in) ORDER BY t.name", ARRAY_A ); // phpcs:ignore WordPress.DB
			$items   = $wpdb->get_results( "SELECT i.*, pj.name AS project_name, ph.name AS phase_name FROM $pi i LEFT JOIN " . Schema::table( 'projects' ) . ' pj ON pj.id=i.project_id LEFT JOIN ' . Schema::table( 'project_phases' ) . " ph ON ph.id=i.phase_id WHERE i.team_member_id IN ($in) AND i.status IN ('payable') ORDER BY i.earned_on, i.id", ARRAY_A ); // phpcs:ignore WordPress.DB
			$by      = array();
			foreach ( (array) $items as $it ) {
				$it['amount']                  = (float) $it['amount'];
				$by[ (int) $it['team_member_id'] ][] = $it;
			}
			$salary_month = $wpdb->get_results( $wpdb->prepare( "SELECT i.*, po.number AS payout_number FROM $pi i LEFT JOIN " . Schema::table( 'payouts' ) . " po ON po.id=i.payout_id WHERE i.kind='salary' AND i.period=%s AND i.status <> 'void' AND i.team_member_id IN ($in)", $period ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$sal = array();
			foreach ( (array) $salary_month as $s ) {
				$sal[ (int) $s['team_member_id'] ] = $s;
			}
			foreach ( $members as $m ) {
				$mid   = (int) $m['id'];
				$owed  = array();
				$other = array();
				$list  = isset( $by[ $mid ] ) ? $by[ $mid ] : array();
				foreach ( $list as $it ) {
					$owed[ $it['currency'] ] = round( ( isset( $owed[ $it['currency'] ] ) ? $owed[ $it['currency'] ] : 0 ) + $it['amount'], 2 );
					if ( ! ( 'salary' === $it['kind'] && $it['period'] === $period ) ) {
						$other[ $it['currency'] ] = round( ( isset( $other[ $it['currency'] ] ) ? $other[ $it['currency'] ] : 0 ) + $it['amount'], 2 );
					}
				}
				$s = isset( $sal[ $mid ] ) ? $sal[ $mid ] : null;
				$rows[] = array(
					'id'            => $mid,
					'name'          => $m['name'],
					'role'          => $m['role'],
					'kind'          => $m['kind'],
					'department_name' => $m['department_name'],
					'salary_amount' => (float) $m['salary_amount'],
					'currency'      => $m['currency'] ? $m['currency'] : \BizyMoney\Settings::get( 'default_currency' ),
					'salary_due'    => in_array( $mid, array_map( function ( $x ) {
						return (int) $x['id'];
					}, self::eligible( $period ) ), true ),
					'salary'        => $s ? array( 'id' => (int) $s['id'], 'status' => $s['status'], 'amount' => (float) $s['amount'], 'payout_number' => $s['payout_number'] ) : null,
					'other_owed'    => $other,
					'owed'          => $owed,
					'items'         => $list,
				);
			}
		}
		$totals = array( 'owed' => array(), 'salaries' => array(), 'paid_in_month' => array() );
		foreach ( $rows as $r ) {
			foreach ( $r['owed'] as $c => $a ) {
				$totals['owed'][ $c ] = array( 'amount' => round( ( isset( $totals['owed'][ $c ] ) ? $totals['owed'][ $c ]['amount'] : 0 ) + $a, 2 ), 'count' => 1 );
			}
			if ( $r['salary'] ) {
				$totals['salaries'][ $r['currency'] ] = array( 'amount' => round( ( isset( $totals['salaries'][ $r['currency'] ] ) ? $totals['salaries'][ $r['currency'] ]['amount'] : 0 ) + $r['salary']['amount'], 2 ), 'count' => 1 );
			}
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT currency, SUM(amount) t FROM ' . Schema::table( 'payouts' ) . " WHERE status='paid' AND paid_on BETWEEN %s AND %s GROUP BY currency", $start, $end ), ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB
			$totals['paid_in_month'][ $p['currency'] ] = array( 'amount' => round( (float) $p['t'], 2 ), 'count' => 1 );
		}
		$missing = 0;
		foreach ( $rows as $r ) {
			if ( $r['salary_due'] && ! $r['salary'] ) {
				$missing++;
			}
		}
		return array( 'period' => $period, 'label' => wp_date( 'F Y', strtotime( $start ) ), 'members' => $rows, 'totals' => $totals, 'salaries_missing' => $missing );
	}

	/** Pay each person everything they are owed (one payout per person and currency). */
	public static function pay_members( array $member_ids, $paid_on, $method, $reference ) {
		global $wpdb;
		$pi  = Schema::table( 'pay_items' );
		$out = array();
		foreach ( array_unique( array_map( 'intval', $member_ids ) ) as $mid ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, currency, amount FROM $pi WHERE team_member_id=%d AND status='payable'", $mid ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$by   = array();
			foreach ( (array) $rows as $r ) {
				$by[ $r['currency'] ][] = (int) $r['id'];
			}
			foreach ( $by as $cur => $ids ) {
				$res   = Pay::create_payout( $mid, $ids, $paid_on, $method, $reference, '' );
				$out[] = is_wp_error( $res ) ? array( 'member_id' => $mid, 'error' => $res->get_error_message() ) : array( 'member_id' => $mid, 'number' => $res['number'], 'amount' => (float) $res['amount'], 'currency' => $cur );
			}
		}
		return $out;
	}
}
