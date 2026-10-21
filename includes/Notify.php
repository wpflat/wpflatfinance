<?php
namespace WPFlatFinance;

use WPFlatCore\Portal;
use WPFlatCore\Services\Notifications as N;

defined( 'ABSPATH' ) || exit;

/** The notifications WPFlat Finance raises, and the daily reminders for invoices that are due or late. */
class Notify {

	public static function init() {
		add_action( 'wpflatfinance_invoice_sent', array( __CLASS__, 'invoice_sent' ) );
		add_action( 'wpflatfinance_invoice_status', array( __CLASS__, 'invoice_status' ), 10, 3 );
		add_action( 'wpflatfinance_payment_saved', array( __CLASS__, 'payment' ), 10, 2 );
		add_action( 'wpflatcore_daily', array( __CLASS__, 'daily' ), 30 );
	}

	public static function types() {
		N::register( 'client_invoice_sent', array( 'label' => 'Invoice', 'audience' => 'client', 'group' => 'Billing', 'description' => 'A new invoice is ready', 'email' => 'off', 'external' => true ) );
		N::register( 'client_invoice_due', array( 'label' => 'Invoice', 'audience' => 'client', 'group' => 'Billing', 'description' => 'An invoice is due soon', 'email' => 'instant' ) );
		N::register( 'client_invoice_overdue', array( 'label' => 'Invoice', 'audience' => 'client', 'group' => 'Billing', 'description' => 'An invoice is overdue (reminders)', 'email' => 'instant' ) );
		N::register( 'client_payment', array( 'label' => 'Payment', 'audience' => 'client', 'group' => 'Billing', 'description' => 'We received a payment from you', 'email' => 'instant' ) );
		N::register( 'team_invoice_paid', array( 'label' => 'Invoice', 'audience' => 'team', 'group' => 'Finance', 'description' => 'An invoice is paid in full', 'email' => 'off' ) );
		N::register( 'team_invoice_overdue', array( 'label' => 'Invoice', 'audience' => 'team', 'group' => 'Finance', 'description' => 'An invoice becomes overdue', 'email' => 'digest' ) );
	}

	private static function money( $n, $cur ) {
		$sym = $cur;
		foreach ( (array) Settings::get( 'currencies' ) as $c ) {
			if ( isset( $c['code'] ) && $c['code'] === $cur ) {
				$sym = $c['symbol'] ? $c['symbol'] : $cur;
			}
		}
		$n = (float) $n;
		return $sym . number_format( $n, abs( $n - round( $n ) ) > 0.004 ? 2 : 0 );
	}

	private static function plink( $path ) {
		return Portal::url( $path );
	}

	private static function invoice( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT i.*, c.name AS company_name FROM ' . Schema::table( 'invoices' ) . ' i LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=i.client_id WHERE i.id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function invoice_sent( $id ) {
		$i = self::invoice( $id );
		if ( ! $i || in_array( $i['status'], array( 'draft', 'cancelled' ), true ) ) {
			return;
		}
		N::emit( 'client_invoice_sent', N::client_users( $i['client_id'] ), 'New invoice ' . $i['invoice_number'], sprintf( '%s — %s, due %s.', $i['title'], self::money( $i['amount'], $i['currency'] ), $i['due_date'] ? wp_date( get_option( 'date_format' ), strtotime( $i['due_date'] ) ) : 'on receipt' ), self::plink( 'billing/' . $i['id'] ), $i['client_id'], 'invoice', $i['id'], 'sent:' . $i['id'] );
	}

	public static function invoice_status( $id, $old, $new ) {
		$i = self::invoice( $id );
		if ( ! $i ) {
			return;
		}
		if ( 'paid' === $new ) {
			N::emit( 'team_invoice_paid', N::users_with( 'wpflatfinance_finance' ), $i['company_name'] . ' paid invoice ' . $i['invoice_number'], self::money( $i['amount'], $i['currency'] ) . ' received in full.', '/billing/invoices/' . $i['id'], $i['client_id'], 'invoice', $i['id'], 'paid:' . $i['id'] );
		} elseif ( 'overdue' === $new ) {
			N::emit( 'team_invoice_overdue', N::users_with( 'wpflatfinance_finance' ), 'Invoice ' . $i['invoice_number'] . ' is overdue', sprintf( '%s owes %s (due %s).', $i['company_name'], self::money( (float) $i['amount'] - (float) $i['amount_paid'], $i['currency'] ), $i['due_date'] ), '/billing/invoices/' . $i['id'], $i['client_id'], 'invoice', $i['id'], 'overdue:' . $i['id'] . ':' . $i['due_date'] );
		}
	}

	public static function payment( $row, $existing ) {
		if ( $existing || empty( $row['invoice_id'] ) || (float) $row['amount'] <= 0 ) {
			return;
		}
		$i = self::invoice( $row['invoice_id'] );
		if ( ! $i ) {
			return;
		}
		N::emit( 'client_payment', N::client_users( $i['client_id'] ), 'Payment received — thank you', sprintf( 'We received %s for invoice %s.', self::money( $row['amount'], $row['currency'] ? $row['currency'] : $i['currency'] ), $i['invoice_number'] ), self::plink( 'billing/' . $i['id'] ), $i['client_id'], 'payment', $row['id'], 'pay:' . $row['id'] );
	}

	/** Invoices coming due, and invoices still unpaid after their due date, reminded to the client at the owner's pace. */
	public static function daily() {
		global $wpdb;
		if ( ! \WPFlatCore\Registry::enabled( 'finance' ) ) {
			return;
		}
		$today = current_time( 'Y-m-d' );
		$due   = (int) \WPFlatCore\Settings::get( 'notify_due_days' );
		$every = max( 1, (int) \WPFlatCore\Settings::get( 'notify_overdue_every' ) );
		$max   = (int) \WPFlatCore\Settings::get( 'notify_overdue_max' );
		$rows  = (array) $wpdb->get_results( 'SELECT i.*, c.name AS company_name FROM ' . Schema::table( 'invoices' ) . ' i LEFT JOIN ' . Schema::table( 'clients' ) . " c ON c.id=i.client_id WHERE i.status IN ('sent','due','partially_paid','overdue') AND i.due_date IS NOT NULL", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $i ) {
			$days = (int) floor( ( strtotime( $i['due_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS );
			$bal  = max( 0, (float) $i['amount'] - (float) $i['amount_paid'] );
			if ( $due > 0 && $days >= 0 && $days <= $due ) {
				N::emit( 'client_invoice_due', N::client_users( $i['client_id'] ), 'Invoice ' . $i['invoice_number'] . ( 0 === $days ? ' is due today' : ' is due in ' . $days . ' day' . ( 1 === $days ? '' : 's' ) ), self::money( $bal, $i['currency'] ) . ' outstanding. ' . $i['title'], self::plink( 'billing/' . $i['id'] ), $i['client_id'], 'invoice', $i['id'], 'due:' . $i['id'] . ':' . $i['due_date'] );
			} elseif ( $days < 0 && $max > 0 ) {
				$k = (int) floor( -$days / $every );
				if ( $k < $max ) {
					N::emit( 'client_invoice_overdue', N::client_users( $i['client_id'] ), 'Invoice ' . $i['invoice_number'] . ' is overdue', self::money( $bal, $i['currency'] ) . ' was due on ' . wp_date( get_option( 'date_format' ), strtotime( $i['due_date'] ) ) . '. Please arrange payment or get in touch.', self::plink( 'billing/' . $i['id'] ), $i['client_id'], 'invoice', $i['id'], 'late:' . $i['id'] . ':' . $k );
				}
			}
		}
	}
}
