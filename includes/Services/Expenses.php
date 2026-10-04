<?php
namespace BizyMoney\Services;

use BizyCore\Services\Activity;

use BizyMoney\Schema;
use BizyCore\Lookups;

defined( 'ABSPATH' ) || exit;

/** Expenses: recurring templates that post themselves, and the monthly summary. */
class Expenses {

	/** Cost per month of a recurring template (annual 120,000 → 10,000). */
	public static function monthly_value( $amount, $period ) {
		$months = Lookups::months_for( $period );
		return $months > 0 ? round( (float) $amount / $months, 2 ) : 0.0;
	}

	/**
	 * Post every recurring expense that has come due (catches up missed periods, max 12 per template).
	 *
	 * @return int Number of expenses posted.
	 */
	public static function post_due() {
		global $wpdb;
		$rt    = Schema::table( 'recurring_expenses' );
		$ex    = Schema::table( 'expenses' );
		$today = Billing::today();
		$now   = current_time( 'mysql' );
		$n     = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $rt WHERE status='active' AND next_date IS NOT NULL AND next_date <= %s", $today ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$date  = $r['next_date'];
			$guard = 0;
			while ( $date && $date <= $today && $guard++ < 12 ) {
				$wpdb->insert(
					$ex,
					array(
						'spent_on'      => $date,
						'amount'        => $r['amount'],
						'currency'      => $r['currency'],
						'category'      => $r['category'],
						'vendor'        => $r['vendor'],
						'description'   => $r['name'],
						'department_id' => (int) $r['department_id'],
						'recurring_id'  => (int) $r['id'],
						'status'        => 'paid',
						'notes'         => 'Posted automatically from a recurring expense.',
						'created_by'    => 0,
						'created_at'    => $now,
						'updated_at'    => $now,
					)
				);
				$n++;
				$date = Renewals::next_date( $date, $r['period'] );
			}
			if ( $date ) {
				$wpdb->update( $rt, array( 'next_date' => $date, 'updated_at' => $now ), array( 'id' => $r['id'] ) );
			}
		}
		if ( $n ) {
			Activity::log( 'expenses', 0, 0, 'posted', sprintf( '%d recurring expense%s posted', $n, 1 === $n ? '' : 's' ) );
		}
		return $n;
	}

	/** Totals per currency for a date range (inclusive). */
	public static function totals( $from, $to, $department_id = 0 ) {
		global $wpdb;
		$ex  = Schema::table( 'expenses' );
		$sql = $wpdb->prepare( "SELECT currency, SUM(amount) total, COUNT(*) n FROM $ex WHERE spent_on BETWEEN %s AND %s", $from, $to ); // phpcs:ignore WordPress.DB
		if ( $department_id ) {
			$sql .= $wpdb->prepare( ' AND department_id=%d', $department_id ); // phpcs:ignore WordPress.DB
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql . ' GROUP BY currency', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r['currency'] ] = array( 'amount' => round( (float) $r['total'], 2 ), 'count' => (int) $r['n'] );
		}
		return $out;
	}

	public static function summary() {
		global $wpdb;
		$today = Billing::today();
		$m0    = gmdate( 'Y-m-01', strtotime( $today ) );
		$m1    = gmdate( 'Y-m-t', strtotime( $today ) );
		$p0    = gmdate( 'Y-m-01', strtotime( $m0 . ' -1 month' ) );
		$p1    = gmdate( 'Y-m-t', strtotime( $p0 ) );
		$ex    = Schema::table( 'expenses' );
		$rt    = Schema::table( 'recurring_expenses' );

		$cats = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT category, currency, SUM(amount) total FROM $ex WHERE spent_on BETWEEN %s AND %s GROUP BY category, currency ORDER BY total DESC", $m0, $m1 ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$cats[ $r['category'] ][ $r['currency'] ] = array( 'amount' => round( (float) $r['total'], 2 ), 'count' => 1 );
		}
		$recurring = array();
		foreach ( (array) $wpdb->get_results( "SELECT amount, currency, period FROM $rt WHERE status='active'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$cur = $r['currency'];
			if ( ! isset( $recurring[ $cur ] ) ) {
				$recurring[ $cur ] = array( 'amount' => 0.0, 'count' => 0 );
			}
			$recurring[ $cur ]['amount'] = round( $recurring[ $cur ]['amount'] + self::monthly_value( $r['amount'], $r['period'] ), 2 );
			$recurring[ $cur ]['count']++;
		}
		return array(
			'this_month'     => self::totals( $m0, $m1 ),
			'last_month'     => self::totals( $p0, $p1 ),
			'recurring_monthly' => $recurring,
			'by_category'    => $cats,
		);
	}
}
