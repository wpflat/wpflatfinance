<?php
namespace WPFlatFinance\Services;

use WPFlatFinance\Schema;
use WPFlatFinance\Settings;

defined( 'ABSPATH' ) || exit;

class Billing {

	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/** Treatments for which nothing is billed to the client for the recurring service itself. */
	public static function is_billable( $treatment ) {
		return ! in_array( $treatment, array( 'included_in_retainer', 'cost_only', 'no_charge' ), true );
	}

	public static function billable_price( $treatment, $price ) {
		return self::is_billable( $treatment ) ? (float) $price : 0.0;
	}

	public static function client_currency( $client_id ) {
		global $wpdb;
		$cur = $client_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT currency FROM ' . Schema::table( 'client_billing' ) . ' WHERE client_id=%d', $client_id ) ) : ''; // phpcs:ignore WordPress.DB
		return $cur ? $cur : Settings::get( 'default_currency' );
	}

	/** Date arithmetic that clamps to month end (Jan 31 + 1 month = Feb 28). */
	public static function add_months( $date, $months ) {
		$d    = new \DateTimeImmutable( $date );
		$day  = (int) $d->format( 'j' );
		$base = $d->modify( 'first day of this month' )->modify( '+' . (int) $months . ' months' );
		$last = (int) $base->format( 't' );
		return $base->setDate( (int) $base->format( 'Y' ), (int) $base->format( 'n' ), min( $day, $last ) )->format( 'Y-m-d' );
	}

	/**
	 * One status for any bill (one-off charge or recurring cycle):
	 * not_invoiced | invoice_draft | awaiting | overdue | paid | waived | closed.
	 *
	 * @param array $r Row with status, invoice_id, invoice_status.
	 * @param array $open Item statuses that still need billing.
	 */
	public static function bill_status( array $r, array $open ) {
		$st = isset( $r['status'] ) ? $r['status'] : '';
		if ( in_array( $st, array( 'waived', 'cancelled', 'skipped' ), true ) ) {
			return 'waived';
		}
		if ( empty( $r['invoice_id'] ) ) {
			return in_array( $st, $open, true ) ? 'not_invoiced' : 'closed';
		}
		$inv = isset( $r['invoice_status'] ) ? $r['invoice_status'] : '';
		if ( 'paid' === $inv ) {
			return 'paid';
		}
		if ( 'overdue' === $inv ) {
			return 'overdue';
		}
		if ( 'draft' === $inv ) {
			return 'invoice_draft';
		}
		return 'cancelled' === $inv ? 'not_invoiced' : 'awaiting';
	}

	/** SQL fragment (aliases t = bill table, inv = invoices) for a bill_status list filter. */
	public static function bill_status_sql( $filter, $open_sql ) {
		switch ( $filter ) {
			case 'not_invoiced':
				return "t.invoice_id=0 AND t.status IN ($open_sql)";
			case 'awaiting':
				return "t.invoice_id>0 AND inv.status IN ('draft','sent','due','partially_paid')";
			case 'overdue':
				return "t.invoice_id>0 AND inv.status='overdue'";
			case 'paid':
				return "t.invoice_id>0 AND inv.status='paid'";
			case 'waived':
				return "t.status IN ('waived','cancelled','skipped')";
		}
		return '';
	}
}
