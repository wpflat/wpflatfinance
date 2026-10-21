<?php
namespace WPFlatFinance\Services;

use WPFlatFinance\Schema;

defined( 'ABSPATH' ) || exit;

class Charges {

	/**
	 * Resolve when a charge should be billed. Returns array( bill_on_date|null, renewal_id ).
	 */
	public static function resolve_bill_date( array $c ) {
		global $wpdb;
		$from   = ! empty( $c['incurred_on'] ) ? $c['incurred_on'] : Billing::today();
		$timing = $c['billing_timing'];

		switch ( $timing ) {
			case 'now':
				return array( $from, 0 );

			case 'end_of_month':
				return array( gmdate( 'Y-m-t', strtotime( $from ) ), 0 );

			case 'end_of_quarter':
				$m     = (int) gmdate( 'n', strtotime( $from ) );
				$qend  = (int) ceil( $m / 3 ) * 3;
				$y     = gmdate( 'Y', strtotime( $from ) );
				return array( gmdate( 'Y-m-t', strtotime( sprintf( '%s-%02d-01', $y, $qend ) ) ), 0 );

			case 'next_renewal':
				$ren = Schema::table( 'renewals' );
				if ( ! empty( $c['client_service_id'] ) ) {
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, due_date FROM $ren WHERE client_service_id=%d AND status IN ('upcoming','due') AND invoice_id=0 AND due_date >= %s ORDER BY due_date ASC LIMIT 1", $c['client_service_id'], $from ), ARRAY_A ); // phpcs:ignore WordPress.DB
				} else {
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, due_date FROM $ren WHERE client_id=%d AND status IN ('upcoming','due') AND invoice_id=0 AND due_date >= %s ORDER BY due_date ASC LIMIT 1", $c['client_id'], $from ), ARRAY_A ); // phpcs:ignore WordPress.DB
				}
				return $row ? array( $row['due_date'], (int) $row['id'] ) : array( null, 0 );
		}

		return array( isset( $c['bill_on_date'] ) ? $c['bill_on_date'] : null, 0 );
	}
}
