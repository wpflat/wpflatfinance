<?php
namespace WPFlatFinance\Services;

use WPFlatFinance\Schema;
use WPFlatFinance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic payment reminders. Each step is a number of minutes from the start of the invoice's due date
 * (negative = before, 0 = on the day, positive = after), e.g. -4320 is three days before. When several steps are
 * already past, only the latest one is emailed so nobody gets a burst; every step is sent at most once per invoice.
 */
class Reminders {

	const SENT = 'wpflatfinance_reminders_sent';

	public static function init() {
		add_action( 'wpflatcore_daily', array( __CLASS__, 'run' ), 20 );
	}

	/** @param int|null $now Unix time to act as "now" (tests). @return array What was sent. */
	public static function run( $now = null ) {
		global $wpdb;
		if ( ! Settings::get( 'reminders_on' ) ) {
			return array();
		}
		$steps = array_map( 'intval', (array) Settings::get( 'reminder_steps' ) );
		sort( $steps );
		if ( ! $steps ) {
			return array();
		}
		$now  = null === $now ? time() : (int) $now;
		$tz   = wp_timezone();
		$sent = (array) get_option( self::SENT, array() );
		$out  = array();
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'invoices' ) . " WHERE status IN ('sent','due','partially_paid','overdue') AND due_date IS NOT NULL", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $r ) {
			if ( (float) $r['amount'] - (float) $r['amount_paid'] <= 0.004 ) {
				continue;
			}
			$next = Invoice_Mail::next_due( $r );
			if ( ! $next['date'] ) {
				continue;
			}
			$due  = ( new \DateTimeImmutable( $next['date'] . ' 00:00:00', $tz ) )->getTimestamp();
			$id   = (int) $r['id'];
			$all  = isset( $sent[ $id ] ) ? (array) $sent[ $id ] : array();
			$done = isset( $all[ $next['date'] ] ) ? (array) $all[ $next['date'] ] : array(); // steps already handled for this due date
			$due_steps = array();
			foreach ( $steps as $m ) {
				if ( $due + $m * 60 <= $now && ! isset( $done[ $m ] ) ) {
					$due_steps[] = $m;
				}
			}
			if ( ! $due_steps ) {
				continue;
			}
			$last = array_pop( $due_steps );
			foreach ( $due_steps as $m ) {
				$done[ $m ] = 0; // skipped: a later step is already due
			}
			$res = Invoice_Mail::send_reminder( $id );
			if ( ! is_wp_error( $res ) && ! empty( $res['ok'] ) ) {
				$done[ $last ] = $now;
				$out[]         = array( 'invoice_id' => $id, 'step' => $last, 'to' => $res['to'], 'cc' => $res['cc'] );
			}
			$all[ $next['date'] ] = $done;
			$sent[ $id ]        = $all;
		}
		update_option( self::SENT, $sent, false );
		return $out;
	}
}
