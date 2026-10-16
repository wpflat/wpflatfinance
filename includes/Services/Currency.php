<?php
namespace BizyFinance\Services;

use BizyFinance\Schema;
use BizyFinance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Currencies the business deals with, and the admin-set exchange rates behind "combined" totals.
 * A rate is "1 unit of this currency = N units of the base currency" (a single current rate, not per month).
 */
class Currency {

	/** Tables that carry a currency column, for "is this currency in use?" checks. */
	const TABLES = array( 'clients', 'client_services', 'renewals', 'charges', 'invoices', 'payments', 'expenses', 'recurring_expenses', 'projects', 'team_members', 'pay_items', 'payouts' );

	public static function base() {
		$b = (string) Settings::get( 'base_currency' );
		return '' !== $b ? $b : (string) Settings::get( 'default_currency' );
	}

	/** Codes switched on in Settings → Currencies. */
	public static function active_codes() {
		$out = array();
		foreach ( (array) Settings::get( 'currencies' ) as $c ) {
			if ( ! isset( $c['active'] ) || $c['active'] ) {
				$out[] = $c['code'];
			}
		}
		return $out;
	}

	/** Rate of $code into the base currency; null when none is set. */
	public static function rate( $code ) {
		$code = strtoupper( (string) $code );
		if ( $code === self::base() ) {
			return 1.0;
		}
		$rates = (array) Settings::get( 'rates' );
		return isset( $rates[ $code ] ) && (float) $rates[ $code ] > 0 ? (float) $rates[ $code ] : null;
	}

	/** Convert into $to (default: the base currency). Null when a needed rate is missing. */
	public static function convert( $amount, $from, $to = '' ) {
		$to   = '' !== $to ? strtoupper( $to ) : self::base();
		$from = strtoupper( (string) $from );
		if ( $from === $to ) {
			return (float) $amount;
		}
		$rf = self::rate( $from );
		$rt = self::rate( $to );
		if ( null === $rf || null === $rt ) {
			return null;
		}
		return round( (float) $amount * $rf / $rt, 2 );
	}

	/**
	 * Collapse a per-currency map {CUR:{amount,count}} into one base-currency figure.
	 *
	 * @return array{amount:float,count:int,missing:string[]} Currencies with no rate are listed in `missing` and left out.
	 */
	public static function combine( array $map, $to = '' ) {
		$total   = 0.0;
		$count   = 0;
		$missing = array();
		foreach ( $map as $code => $v ) {
			$amount = is_array( $v ) ? (float) $v['amount'] : (float) $v;
			$c      = self::convert( $amount, $code, $to );
			if ( null === $c ) {
				$missing[] = $code;
				continue;
			}
			$total += $c;
			$count += is_array( $v ) && isset( $v['count'] ) ? (int) $v['count'] : 0;
		}
		return array( 'amount' => round( $total, 2 ), 'count' => $count, 'missing' => $missing );
	}

	/** How many records use each currency code (so one that is in use cannot quietly disappear). */
	public static function usage() {
		global $wpdb;
		$out = array();
		foreach ( self::TABLES as $t ) {
			$tbl = Schema::table( $t );
			$col = 'services' === $t ? 'default_currency' : 'currency';
			foreach ( (array) $wpdb->get_results( "SELECT $col AS c, COUNT(*) AS n FROM $tbl WHERE $col <> '' GROUP BY $col", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
				$out[ $r['c'] ] = ( isset( $out[ $r['c'] ] ) ? $out[ $r['c'] ] : 0 ) + (int) $r['n'];
			}
		}
		return $out;
	}
}
