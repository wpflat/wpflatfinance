<?php
namespace WPFlatFinance\Services;

use WPFlatFinance\Schema;

defined( 'ABSPATH' ) || exit;

/** Counts + amounts per bill status and currency, for one-off charges or recurring cycles. */
class BillSummary {

	const STATUSES = array( 'not_invoiced', 'awaiting', 'overdue', 'paid', 'waived' );

	/** @return array{ status: array{ cur: array{amount: float, count: int} } } */
	public static function for_kind( $kind, $client_id = 0 ) {
		global $wpdb;
		$inv = Schema::table( 'invoices' );
		$cid = (int) $client_id ? ' AND t.client_id=' . (int) $client_id : '';

		if ( 'recurring' === $kind ) {
			$tbl   = Schema::table( 'renewals' );
			$open  = "('upcoming','due')";
			$scope = " WHERE (t.status IN ('upcoming','due') OR t.invoice_id>0) AND t.price>0 AND t.status NOT IN ('skipped','cancelled')$cid";
			$waive = "'skipped','cancelled'";
		} else {
			$tbl   = Schema::table( 'charges' );
			$open  = "('pending')";
			$scope = " WHERE 1=1$cid";
			$waive = "'waived','cancelled'";
		}

		$sql = "SELECT CASE
				WHEN t.status IN ($waive) THEN 'waived'
				WHEN t.invoice_id=0 THEN IF(t.status IN $open, 'not_invoiced', 'closed')
				WHEN i.status='paid' THEN 'paid'
				WHEN i.status='overdue' THEN 'overdue'
				WHEN i.status='cancelled' THEN 'not_invoiced'
				ELSE 'awaiting' END AS bs, t.currency, SUM(t.price) total, COUNT(*) n
			FROM $tbl t LEFT JOIN $inv i ON i.id=t.invoice_id $scope GROUP BY bs, t.currency"; // phpcs:ignore WordPress.DB

		$out = array();
		foreach ( self::STATUSES as $s ) {
			$out[ $s ] = array();
		}
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			if ( ! isset( $out[ $r['bs'] ] ) ) {
				continue; // 'closed' rows are history, not pipeline
			}
			$out[ $r['bs'] ][ $r['currency'] ] = array( 'amount' => round( (float) $r['total'], 2 ), 'count' => (int) $r['n'] );
		}
		return $out;
	}
}
