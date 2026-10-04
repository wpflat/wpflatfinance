<?php
namespace BizyMoney\Services;

use BizyMoney\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Shared finance helpers (department attribution, cost snapshots).
 * Cost and department data is staff-only: it must never reach the public invoice page, emails or PDFs.
 */
class Finance {

	/** The client's primary department (falls back to its first one), or 0. */
	public static function client_department( $client_id ) {
		return \BizyCore\Services\Departments::client_primary( $client_id );
	}

	/**
	 * Fill in missing cost snapshots on older invoice lines: from the bill they came from,
	 * else from the catalog item they match by name. Safe to run again (only touches cost = 0).
	 *
	 * @return int Lines updated.
	 */
	public static function backfill_costs() {
		global $wpdb;
		$it       = Schema::table( 'invoice_items' );
		$inv      = Schema::table( 'invoices' );
		$services = $wpdb->get_results( 'SELECT id, name, default_cost FROM ' . Schema::table( 'catalog' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$by_name  = array();
		foreach ( (array) $services as $s ) {
			$by_name[ strtolower( trim( $s['name'] ) ) ] = $s;
		}
		$n = 0;
		foreach ( (array) $wpdb->get_results( "SELECT it.*, i.client_id FROM $it it JOIN $inv i ON i.id=it.invoice_id WHERE it.cost = 0", ARRAY_A ) as $l ) { // phpcs:ignore WordPress.DB
			$cost = 0.0;
			$svc  = (int) $l['service_id'];
			if ( 'renewal' === $l['source_type'] ) {
				$cost = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT cost FROM ' . Schema::table( 'renewals' ) . ' WHERE id=%d', (int) $l['source_id'] ) ); // phpcs:ignore WordPress.DB
				$svc  = $svc ? $svc : self::source_service( 'renewal', $l['source_id'] );
			} elseif ( 'charge' === $l['source_type'] ) {
				$cost = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT cost FROM ' . Schema::table( 'charges' ) . ' WHERE id=%d', (int) $l['source_id'] ) ); // phpcs:ignore WordPress.DB
				$svc  = $svc ? $svc : self::source_service( 'charge', $l['source_id'] );
			} else {
				$name = strtolower( trim( preg_replace( '/\s*(\(per [^)]*\)|—.*)$/u', '', $l['description'] ) ) );
				if ( isset( $by_name[ $name ] ) ) {
					$svc  = $svc ? $svc : (int) $by_name[ $name ]['id'];
					$cost = (float) $by_name[ $name ]['default_cost'] * ( 'quantity' === $l['kind'] ? (float) $l['quantity'] : 1 );
				}
			}
			$dept = self::line_department( $svc, $l['client_id'], $l['source_type'], $l['source_id'], (int) $wpdb->get_var( $wpdb->prepare( 'SELECT department_id FROM ' . $inv . ' WHERE id=%d', (int) $l['invoice_id'] ) ) ); // phpcs:ignore WordPress.DB
			if ( $cost > 0 || $svc !== (int) $l['service_id'] || $dept !== (int) $l['department_id'] ) {
				$wpdb->update( $it, array( 'cost' => round( $cost, 2 ), 'service_id' => $svc, 'department_id' => $dept ), array( 'id' => $l['id'] ) );
				$n += $cost > 0 ? 1 : 0;
			}
		}
		return $n;
	}

	/** The catalog item behind a charge / renewal (0 when unknown). */
	public static function source_service( $type, $id ) {
		global $wpdb;
		$cs = Schema::table( 'client_services' );
		if ( 'renewal' === $type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT cs.service_id FROM ' . Schema::table( 'renewals' ) . " r JOIN $cs cs ON cs.id=r.client_service_id WHERE r.id=%d", (int) $id ) ); // phpcs:ignore WordPress.DB
		}
		if ( 'charge' === $type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IF(ch.service_id>0, ch.service_id, COALESCE(cs.service_id,0)) FROM ' . Schema::table( 'charges' ) . " ch LEFT JOIN $cs cs ON cs.id=ch.client_service_id WHERE ch.id=%d", (int) $id ) ); // phpcs:ignore WordPress.DB
		}
		return 0;
	}

	/** Department set directly on a bill: a one-off charge, or the subscription / retainer behind a renewal cycle. */
	public static function source_department( $type, $id ) {
		global $wpdb;
		if ( 'charge' === $type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT department_id FROM ' . Schema::table( 'charges' ) . ' WHERE id=%d', (int) $id ) ); // phpcs:ignore WordPress.DB
		}
		if ( 'renewal' === $type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT cs.department_id FROM ' . Schema::table( 'renewals' ) . ' r JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=r.client_service_id WHERE r.id=%d', (int) $id ) ); // phpcs:ignore WordPress.DB
		}
		return 0;
	}

	/**
	 * Department for an invoice line, most specific first: the invoice's own department (when it is attached to one),
	 * the bill the line came from, the catalog item, then the client's primary department.
	 */
	public static function line_department( $service_id, $client_id, $source_type = '', $source_id = 0, $invoice_dept = 0 ) {
		global $wpdb;
		if ( (int) $invoice_dept > 0 ) {
			return (int) $invoice_dept;
		}
		if ( $source_type && (int) $source_id > 0 ) {
			$d = self::source_department( $source_type, $source_id );
			if ( $d > 0 ) {
				return $d;
			}
		}
		if ( (int) $service_id > 0 ) {
			$d = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT department_id FROM ' . Schema::table( 'catalog' ) . ' WHERE id=%d', (int) $service_id ) ); // phpcs:ignore WordPress.DB
			if ( $d > 0 ) {
				return $d;
			}
		}
		return self::client_department( $client_id );
	}

	/** Default department for a new bill: the catalog item's, else the client's. */
	public static function default_department( $service_id, $client_id ) {
		return self::line_department( $service_id, $client_id );
	}
}
