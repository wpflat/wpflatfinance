<?php
namespace BizyFinance\Services;

use BizyFinance\Schema;
use BizyCore\Lookups;

defined( 'ABSPATH' ) || exit;

/** Recurring bills: subscriptions (client services with a cycle), their value and next cycle. */
class Subscriptions {

	/** Price per month for a client-service row (billable treatments only). 0 for non-recurring. */
	public static function monthly_value( array $cs ) {
		$period = isset( $cs['billing_period'] ) ? $cs['billing_period'] : '';
		if ( ! $period || 'none' === $period ) {
			return 0.0;
		}
		$price  = Billing::billable_price( $cs['billing_treatment'], $cs['client_price'] );
		$months = Lookups::months_for( $period );
		if ( $months <= 0 ) {
			$days   = (int) ( isset( $cs['interval_days'] ) ? $cs['interval_days'] : 0 );
			$months = $days > 0 ? $days / 30.4375 : 0;
		}
		return $months > 0 ? round( $price / $months, 2 ) : 0.0;
	}

	/**
	 * Active recurring revenue per currency: { cur: { amount (MRR), count } }.
	 *
	 * @param int[]|null $client_ids Restrict to these clients (null = all).
	 */
	public static function mrr_totals( $client_ids = null, $client_id = 0 ) {
		global $wpdb;
		$where = " WHERE status='active' AND billing_period NOT IN ('','none')";
		if ( is_array( $client_ids ) ) {
			$where .= $client_ids ? ' AND client_id IN (' . implode( ',', array_map( 'intval', $client_ids ) ) . ')' : ' AND 1=0';
		}
		if ( $client_id ) {
			$where .= ' AND client_id=' . (int) $client_id;
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'client_services' ) . $where, ARRAY_A ) as $cs ) { // phpcs:ignore WordPress.DB
			$v = self::monthly_value( $cs );
			if ( $v <= 0 ) {
				continue;
			}
			$c = $cs['currency'];
			if ( ! isset( $out[ $c ] ) ) {
				$out[ $c ] = array( 'amount' => 0, 'count' => 0 );
			}
			$out[ $c ]['amount'] = round( $out[ $c ]['amount'] + $v, 2 );
			++$out[ $c ]['count'];
		}
		return $out;
	}

	/** The next open cycle (renewal) per client-service id, with its invoice info. */
	public static function next_cycles( array $cs_ids ) {
		global $wpdb;
		if ( ! $cs_ids ) {
			return array();
		}
		$in   = implode( ',', array_map( 'intval', $cs_ids ) );
		$rows = $wpdb->get_results( 'SELECT r.id, r.client_service_id, r.due_date, r.price, r.currency, r.status, r.invoice_id, inv.invoice_number, inv.status AS invoice_status FROM ' . Schema::table( 'renewals' ) . ' r LEFT JOIN ' . Schema::table( 'invoices' ) . " inv ON inv.id=r.invoice_id WHERE r.client_service_id IN ($in) AND r.status IN ('upcoming','due') ORDER BY r.due_date ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( isset( $out[ $r['client_service_id'] ] ) ) {
				continue; // earliest open cycle wins
			}
			$out[ $r['client_service_id'] ] = array(
				'id'             => (int) $r['id'],
				'due_date'       => $r['due_date'],
				'price'          => (float) $r['price'],
				'currency'       => $r['currency'],
				'invoice_id'     => (int) $r['invoice_id'],
				'invoice_number' => $r['invoice_number'],
				'bill_status'    => Billing::bill_status( $r, array( 'upcoming', 'due' ) ),
			);
		}
		return $out;
	}
}
