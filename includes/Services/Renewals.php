<?php
namespace BizyMoney\Services;

use BizyMoney\Schema;
use BizyCore\Lookups;

defined( 'ABSPATH' ) || exit;

class Renewals {

	/** Next due date after $due for a frequency; null when the service does not recur. */
	public static function next_date( $due, $frequency, $interval_days = 0 ) {
		if ( ! $due || ! $frequency || 'none' === $frequency ) {
			return null;
		}
		$months = Lookups::months_for( $frequency );
		if ( $months > 0 ) {
			return Billing::add_months( $due, $months );
		}
		if ( (int) $interval_days > 0 ) {
			return gmdate( 'Y-m-d', strtotime( $due . ' +' . (int) $interval_days . ' days' ) );
		}
		return null;
	}

	/**
	 * Keep exactly one open renewal in step with a client service's next renewal date.
	 */
	public static function sync_from_client_service( array $cs ) {
		global $wpdb;
		$ren = Schema::table( 'renewals' );

		$recurring = ! empty( $cs['billing_period'] ) && 'none' !== $cs['billing_period'];
		if ( empty( $cs['next_renewal_date'] ) || ! $recurring || 'active' !== $cs['status'] ) {
			return;
		}

		$fields = array(
			'due_date'      => $cs['next_renewal_date'],
			'frequency'     => $cs['billing_period'],
			'interval_days' => (int) $cs['interval_days'],
			'cost'          => $cs['cost'],
			'price'         => Billing::billable_price( $cs['billing_treatment'], $cs['client_price'] ),
			'currency'      => $cs['currency'],
			'subject'       => isset( $cs['subject'] ) ? $cs['subject'] : '',
		);
		// Per-unit subscriptions keep their quantity so the invoice can show qty x unit price.
		$per_unit           = (float) $cs['unit_price'] > 0 && (float) $fields['price'] === round( (float) $cs['client_price'], 2 );
		$fields['quantity']   = $per_unit ? (float) $cs['quantity'] : 1;
		$fields['unit_price'] = $per_unit ? (float) $cs['unit_price'] : 0;

		$open = $wpdb->get_row( $wpdb->prepare( "SELECT id, invoice_id FROM $ren WHERE client_service_id=%d AND status IN ('upcoming','due') ORDER BY due_date ASC LIMIT 1", $cs['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$now  = current_time( 'mysql' );

		if ( $open ) {
			if ( (int) $open['invoice_id'] > 0 ) {
				return; // Already billed; don't rewrite figures.
			}
			$wpdb->update( $ren, $fields + array( 'updated_at' => $now ), array( 'id' => $open['id'] ) );
			return;
		}

		$wpdb->insert(
			$ren,
			$fields + array(
				'client_service_id' => (int) $cs['id'],
				'client_id'         => (int) $cs['client_id'],
				'status'            => 'upcoming',
				'created_by'        => get_current_user_id(),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
	}

	/** Mark a renewal renewed and roll the next cycle forward. Returns the next renewal row or null. */
	public static function mark_renewed( array $r ) {
		global $wpdb;
		$ren   = Schema::table( 'renewals' );
		$today = Billing::today();

		$wpdb->update( $ren, array( 'status' => 'renewed', 'renewed_on' => $today, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $r['id'] ) );

		$next = self::next_date( $r['due_date'], $r['frequency'], $r['interval_days'] );
		if ( ! $next ) {
			return null;
		}

		// Don't duplicate if an open renewal already exists for this service.
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ren WHERE client_service_id=%d AND status IN ('upcoming','due')", $r['client_service_id'] ) ); // phpcs:ignore WordPress.DB
		$now    = current_time( 'mysql' );
		$cs     = Schema::table( 'client_services' );
		$wpdb->update( $cs, array( 'next_renewal_date' => $next, 'updated_at' => $now ), array( 'id' => $r['client_service_id'] ) );
		if ( $exists ) {
			return null;
		}

		$wpdb->insert(
			$ren,
			array(
				'client_service_id' => (int) $r['client_service_id'],
				'client_id'         => (int) $r['client_id'],
				'due_date'          => $next,
				'frequency'         => $r['frequency'],
				'interval_days'     => (int) $r['interval_days'],
				'cost'              => $r['cost'],
				'price'             => $r['price'],
				'quantity'          => isset( $r['quantity'] ) ? $r['quantity'] : 1,
				'unit_price'        => isset( $r['unit_price'] ) ? $r['unit_price'] : 0,
				'subject'           => isset( $r['subject'] ) ? $r['subject'] : '',
				'currency'          => $r['currency'],
				'status'            => 'upcoming',
				'created_by'        => get_current_user_id(),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		return array( 'id' => (int) $wpdb->insert_id, 'due_date' => $next );
	}

	/**
	 * Adds pending-charge totals to renewals so the UI can show "Total to bill".
	 */
	public static function decorate( array $items ) {
		global $wpdb;
		$ch   = Schema::table( 'charges' );
		$open = array();
		foreach ( $items as $r ) {
			if ( in_array( $r['status'], array( 'upcoming', 'due' ), true ) ) {
				$open[ $r['client_id'] ] = true;
			}
		}
		$charges = array();
		if ( $open ) {
			$ids = implode( ',', array_map( 'intval', array_keys( $open ) ) );
			$rows = $wpdb->get_results( "SELECT id, client_id, renewal_id, price, currency, bill_on_date FROM $ch WHERE status='pending' AND client_id IN ($ids)", ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $c ) {
				$charges[ $c['client_id'] ][] = $c;
			}
		}

		foreach ( $items as &$r ) {
			$r['pending_charge_ids'] = array();
			$r['pending_charges']    = array();
			$r['pending_total']      = 0;
			if ( in_array( $r['status'], array( 'upcoming', 'due' ), true ) && empty( $r['invoice_id'] ) && ! empty( $charges[ $r['client_id'] ] ) ) {
				foreach ( $charges[ $r['client_id'] ] as $c ) {
					if ( $c['currency'] !== $r['currency'] ) {
						continue;
					}
					$linked = (int) $c['renewal_id'] === (int) $r['id'];
					$by_date = ! empty( $c['bill_on_date'] ) && ! empty( $r['due_date'] ) && $c['bill_on_date'] <= $r['due_date'];
					if ( $linked || $by_date ) {
						$r['pending_charge_ids'][] = (int) $c['id'];
						$r['pending_charges'][]    = array( 'id' => (int) $c['id'], 'price' => (float) $c['price'] );
						$r['pending_total']       += (float) $c['price'];
					}
				}
			}
			$r['total_to_bill'] = ( empty( $r['invoice_id'] ) ? (float) $r['price'] : 0 ) + $r['pending_total'];
		}
		return $items;
	}
}
