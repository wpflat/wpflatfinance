<?php
namespace WPFlatFinance\Services;

use WPFlatFinance\Schema;
use WPFlatFinance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The "To bill" pipeline: renewals + one-off charges that are not yet on an invoice,
 * each with a derived billing state (overdue / ready / scheduled / unscheduled).
 */
class BillingQueue {

	const STATES = array( 'overdue', 'ready', 'scheduled', 'unscheduled' );

	public static function window() {
		return max( 0, (int) Settings::get( 'due_soon_days' ) );
	}

	public static function state_for( $date, $today, $soon ) {
		if ( empty( $date ) ) {
			return 'unscheduled';
		}
		if ( $date < $today ) {
			return 'overdue';
		}
		return $date <= $soon ? 'ready' : 'scheduled';
	}

	/**
	 * All un-invoiced billable items, most urgent first.
	 *
	 * @param array $f client_ids (int[]|null), client_id, type, currency, search.
	 */
	public static function items( array $f = array() ) {
		global $wpdb;
		$today = Billing::today();
		$soon  = gmdate( 'Y-m-d', strtotime( "$today +" . self::window() . ' days' ) );

		$cl = Schema::table( 'clients' );
		$cs = Schema::table( 'client_services' );
		$sv = Schema::table( 'catalog' );

		$scope = '';
		if ( isset( $f['client_ids'] ) && is_array( $f['client_ids'] ) ) {
			$ids   = array_map( 'intval', $f['client_ids'] );
			$scope = $ids ? ' AND %T%.client_id IN (' . implode( ',', $ids ) . ')' : ' AND 1=0';
		}
		if ( ! empty( $f['client_id'] ) ) {
			$scope .= ' AND %T%.client_id=' . (int) $f['client_id'];
		}

		$out = array();

		if ( empty( $f['type'] ) || 'renewal' === $f['type'] ) {
			$sql  = "SELECT r.id, r.client_id, r.due_date, r.price, r.quantity, r.unit_price, r.subject, r.cost, r.currency, r.frequency, r.interval_days, c.name AS client_name, s.name AS service_name, r.client_service_id
				FROM " . Schema::table( 'renewals' ) . " r LEFT JOIN $cl c ON c.id=r.client_id LEFT JOIN $cs cs ON cs.id=r.client_service_id LEFT JOIN $sv s ON s.id=cs.service_id
				WHERE r.status IN ('upcoming','due') AND r.invoice_id=0 AND r.price > 0" . str_replace( '%T%', 'r', $scope ); // phpcs:ignore WordPress.DB
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$next_due = Renewals::next_date( $r['due_date'], $r['frequency'], $r['interval_days'] );
				$out[]    = array(
					'period_start'      => $r['due_date'],
					'period_end'        => $next_due ? gmdate( 'Y-m-d', strtotime( $next_due . ' -1 day' ) ) : null,
					'key'               => 'renewal-' . $r['id'],
					'type'              => 'renewal',
					'kind'              => 'recurring',
					'ref_id'            => (int) $r['id'],
					'client_id'         => (int) $r['client_id'],
					'client_name'       => $r['client_name'],
					'client_service_id' => (int) $r['client_service_id'],
					'label'             => '' !== (string) $r['subject'] ? $r['service_name'] . ' — ' . $r['subject'] : $r['service_name'] . ' renewal',
					'subject'           => (string) $r['subject'],
					'sub'               => $r['frequency'],
					'date'              => $r['due_date'],
					'price'             => (float) $r['price'],
					'quantity'          => (float) $r['quantity'],
					'unit_price'        => (float) $r['unit_price'],
					'cost'              => (float) $r['cost'],
					'currency'          => $r['currency'],
					'state'             => self::state_for( $r['due_date'], $today, $soon ),
				);
			}
		}

		if ( empty( $f['type'] ) || 'charge' === $f['type'] ) {
			$sql  = "SELECT ch.id, ch.client_id, ch.description, ch.bill_on_date, ch.price, ch.cost, ch.currency, ch.client_service_id, c.name AS client_name, s.name AS service_name
				FROM " . Schema::table( 'charges' ) . " ch LEFT JOIN $cl c ON c.id=ch.client_id LEFT JOIN $cs cs ON cs.id=ch.client_service_id LEFT JOIN $sv s ON s.id=IF(ch.service_id>0, ch.service_id, cs.service_id)
				WHERE ch.status='pending'" . str_replace( '%T%', 'ch', $scope ); // phpcs:ignore WordPress.DB
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$out[] = array(
					'period_start'      => null,
					'period_end'        => null,
					'key'               => 'charge-' . $r['id'],
					'type'              => 'charge',
					'kind'              => 'one_off',
					'ref_id'            => (int) $r['id'],
					'client_id'         => (int) $r['client_id'],
					'client_name'       => $r['client_name'],
					'client_service_id' => (int) $r['client_service_id'],
					'label'             => $r['description'],
					'sub'               => (string) $r['service_name'],
					'date'              => $r['bill_on_date'],
					'price'             => (float) $r['price'],
					'cost'              => (float) $r['cost'],
					'currency'          => $r['currency'],
					'state'             => self::state_for( $r['bill_on_date'], $today, $soon ),
				);
			}
		}

		if ( ! empty( $f['currency'] ) ) {
			$out = array_values( array_filter( $out, function ( $i ) use ( $f ) {
				return $i['currency'] === strtoupper( $f['currency'] );
			} ) );
		}
		if ( ! empty( $f['search'] ) ) {
			$q   = strtolower( $f['search'] );
			$out = array_values( array_filter( $out, function ( $i ) use ( $q ) {
				return false !== strpos( strtolower( $i['client_name'] . ' ' . $i['label'] . ' ' . $i['sub'] ), $q );
			} ) );
		}

		$rank = array( 'overdue' => 0, 'ready' => 1, 'scheduled' => 2, 'unscheduled' => 3 );
		foreach ( $out as &$i ) {
			$i['days'] = $i['date'] ? (int) round( ( strtotime( $i['date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS ) : null;
		}
		unset( $i );
		usort( $out, function ( $a, $b ) use ( $rank ) {
			if ( $rank[ $a['state'] ] !== $rank[ $b['state'] ] ) {
				return $rank[ $a['state'] ] <=> $rank[ $b['state'] ];
			}
			return strcmp( (string) $a['date'], (string) $b['date'] ) ?: strcmp( $a['key'], $b['key'] );
		} );
		return $out;
	}

	/** { state: { currency: { amount, count } } } */
	public static function summarise( array $items ) {
		$sum = array();
		foreach ( self::STATES as $s ) {
			$sum[ $s ] = array();
		}
		foreach ( $items as $i ) {
			$c = $i['currency'];
			if ( ! isset( $sum[ $i['state'] ][ $c ] ) ) {
				$sum[ $i['state'] ][ $c ] = array( 'amount' => 0, 'count' => 0 );
			}
			$sum[ $i['state'] ][ $c ]['amount'] = round( $sum[ $i['state'] ][ $c ]['amount'] + $i['price'], 2 );
			++$sum[ $i['state'] ][ $c ]['count'];
		}
		return $sum;
	}
}
