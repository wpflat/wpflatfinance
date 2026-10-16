<?php
namespace BizyFinance\Services;

use BizyCore\Services\Activity;

use BizyFinance\Schema;
use BizyFinance\Settings;

defined( 'ABSPATH' ) || exit;

class Invoices {

	const OPEN_STATUSES = array( 'sent', 'due', 'partially_paid', 'overdue' );

	public static function next_number() {
		global $wpdb;
		$tbl = Schema::table( 'invoices' );
		$s   = Settings::get();
		$n   = max( 1, (int) $s['invoice_next'] );
		do {
			$num    = $s['invoice_prefix'] . str_pad( (string) $n, 4, '0', STR_PAD_LEFT );
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE invoice_number=%s", $num ) ); // phpcs:ignore WordPress.DB
			++$n;
		} while ( $exists );
		Settings::update( array( 'invoice_next' => $n ) );
		return $num;
	}

	/* ───────────────────────── Totals ───────────────────────── */

	/**
	 * @param array  $lines Each: quantity, unit_price.
	 * @param string $type  none | percent | fixed
	 * @return array{subtotal: float, discount_amount: float, tax_amount: float, total: float}
	 */
	public static function compute_totals( array $lines, $type, $value, $tax_rate ) {
		$subtotal = 0.0;
		foreach ( $lines as $l ) {
			$subtotal += round( (float) ( isset( $l['quantity'] ) ? $l['quantity'] : 1 ) * (float) ( isset( $l['unit_price'] ) ? $l['unit_price'] : 0 ), 2 );
		}
		$subtotal = round( $subtotal, 2 );
		$value    = max( 0, (float) $value );
		if ( 'percent' === $type ) {
			$discount = round( $subtotal * min( 100, $value ) / 100, 2 );
		} elseif ( 'fixed' === $type ) {
			$discount = round( min( $value, $subtotal ), 2 );
		} else {
			$discount = 0.0;
		}
		$taxable = $subtotal - $discount;
		$tax     = round( $taxable * max( 0, (float) $tax_rate ) / 100, 2 );
		return array(
			'subtotal'        => $subtotal,
			'discount_amount' => $discount,
			'tax_amount'      => $tax,
			'total'           => round( $taxable + $tax, 2 ),
		);
	}

	/* ───────────────────────── Lines ───────────────────────── */

	private static function clean_date( $v ) {
		$v = (string) $v;
		return ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) ? $v : null;
	}

	/** A sensible "what is this for" when none is typed: the one item, "first + N more", or a recurring summary. */
	public static function default_title( array $norm, $issue_date = '' ) {
		$names = array_values( array_filter( array_map( function ( $l ) {
			return isset( $l['description'] ) ? trim( (string) $l['description'] ) : '';
		}, $norm ), 'strlen' ) );
		if ( ! $names ) {
			return '';
		}
		$sources = array_unique( wp_list_pluck( $norm, 'source_type' ) );
		if ( count( $names ) > 1 && array( 'renewal' ) === array_values( $sources ) ) {
			$ts = $issue_date ? strtotime( $issue_date . ' 12:00:00' ) : time();
			return 'Subscriptions & retainers — ' . wp_date( 'F Y', $ts );
		}
		$title = $names[0];
		if ( count( $names ) > 1 ) {
			$title .= ' + ' . ( count( $names ) - 1 ) . ' more';
		}
		return mb_substr( $title, 0, 190 );
	}

	public static function normalize_lines( array $lines ) {
		$out = array();
		foreach ( $lines as $l ) {
			$desc = isset( $l['description'] ) ? trim( sanitize_text_field( (string) $l['description'] ) ) : '';
			$qty  = isset( $l['quantity'] ) && is_numeric( $l['quantity'] ) ? max( 0, round( (float) $l['quantity'], 2 ) ) : 1;
			$unit = isset( $l['unit_price'] ) && is_numeric( $l['unit_price'] ) ? round( (float) $l['unit_price'], 2 ) : 0;
			$kind = isset( $l['kind'] ) && in_array( $l['kind'], array( 'fixed', 'quantity' ), true ) ? $l['kind'] : ( 1.0 === round( $qty, 2 ) ? 'fixed' : 'quantity' );
			if ( 'fixed' === $kind ) {
				$qty = 1; // fixed-price lines (e.g. web development) have no quantity
			}
			$cost = isset( $l['cost'] ) && is_numeric( $l['cost'] ) ? max( 0, round( (float) $l['cost'], 2 ) ) : 0;
			$svc  = absint( isset( $l['service_id'] ) ? $l['service_id'] : 0 );
			$type = isset( $l['source_type'] ) && in_array( $l['source_type'], array( 'charge', 'renewal' ), true ) ? $l['source_type'] : 'manual';
			$sid  = 'manual' === $type ? 0 : absint( isset( $l['source_id'] ) ? $l['source_id'] : 0 );
			if ( 'manual' !== $type && ! $sid ) {
				$type = 'manual';
			}
			if ( '' === $desc && 0.0 === round( $qty * $unit, 2 ) ) {
				continue; // blank row
			}
			$out[] = array(
				'description'       => mb_substr( $desc, 0, 255 ),
				'kind'              => $kind,
				'cost'              => $cost,
				'service_id'        => $svc,
				'quantity'          => $qty,
				'unit_price'        => $unit,
				'amount'            => round( $qty * $unit, 2 ),
				'source_type'       => $type,
				'source_id'         => $sid,
				'client_service_id' => absint( isset( $l['client_service_id'] ) ? $l['client_service_id'] : 0 ),
				'period_start'      => self::clean_date( isset( $l['period_start'] ) ? $l['period_start'] : '' ),
				'period_end'        => self::clean_date( isset( $l['period_end'] ) ? $l['period_end'] : '' ),
			);
		}
		return $out;
	}

	/** Validate that bill-sourced lines point at un-invoiced bills of this client. */
	public static function validate_lines( $client_id, array $lines, $invoice_id = 0 ) {
		global $wpdb;
		foreach ( self::normalize_lines( $lines ) as $l ) {
			if ( 'manual' === $l['source_type'] ) {
				continue;
			}
			$tbl = Schema::table( 'charge' === $l['source_type'] ? 'charges' : 'renewals' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT client_id, invoice_id FROM $tbl WHERE id=%d", $l['source_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( ! $row || (int) $row['client_id'] !== (int) $client_id ) {
				return new \WP_Error( 'bizyfinance_invalid', 'A billed item on this invoice belongs to a different client.', array( 'status' => 400 ) );
			}
			if ( (int) $row['invoice_id'] > 0 && (int) $row['invoice_id'] !== (int) $invoice_id ) {
				return new \WP_Error( 'bizyfinance_invalid', 'A billed item on this invoice is already on another invoice.', array( 'status' => 400 ) );
			}
		}
		return true;
	}

	private static function link_bill( $type, $id, $invoice_id ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		if ( 'charge' === $type ) {
			$wpdb->update( Schema::table( 'charges' ), array( 'status' => 'invoiced', 'invoice_id' => $invoice_id, 'updated_at' => $now ), array( 'id' => $id ) );
		} else {
			$wpdb->update( Schema::table( 'renewals' ), array( 'invoice_id' => $invoice_id, 'updated_at' => $now ), array( 'id' => $id ) );
		}
	}

	private static function release_bill( $type, $id ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		if ( 'charge' === $type ) {
			$wpdb->update( Schema::table( 'charges' ), array( 'status' => 'pending', 'invoice_id' => 0, 'updated_at' => $now ), array( 'id' => $id, 'status' => 'invoiced' ) );
		} else {
			$wpdb->update( Schema::table( 'renewals' ), array( 'invoice_id' => 0, 'updated_at' => $now ), array( 'id' => $id ) );
		}
	}

	/**
	 * Department a payment on this invoice counts under: the invoice's own department, else the one most of its
	 * lines belong to (by amount), else the client's.
	 */
	public static function payment_department( $invoice_id ) {
		global $wpdb;
		$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT client_id, department_id FROM ' . Schema::table( 'invoices' ) . ' WHERE id=%d', (int) $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $inv ) {
			return 0;
		}
		if ( (int) $inv['department_id'] > 0 ) {
			return (int) $inv['department_id'];
		}
		$top = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT department_id FROM ' . Schema::table( 'invoice_items' ) . ' WHERE invoice_id=%d AND department_id > 0 GROUP BY department_id ORDER BY SUM(amount) DESC LIMIT 1', (int) $invoice_id ) ); // phpcs:ignore WordPress.DB
		return $top ? $top : Finance::client_department( $inv['client_id'] );
	}

	/** Payments follow their invoice's department. */
	public static function restamp_payments( $invoice_id ) {
		global $wpdb;
		$wpdb->update( Schema::table( 'payments' ), array( 'department_id' => self::payment_department( $invoice_id ) ), array( 'invoice_id' => (int) $invoice_id ) );
	}

	/** The invoice moved to another department: its lines follow (or fall back to their own when it is set to "by line"). */
	public static function restamp_lines( $invoice_id ) {
		global $wpdb;
		$tbl = Schema::table( 'invoice_items' );
		$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT client_id, department_id FROM ' . Schema::table( 'invoices' ) . ' WHERE id=%d', (int) $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $inv ) {
			return;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, service_id, source_type, source_id FROM $tbl WHERE invoice_id=%d", (int) $invoice_id ), ARRAY_A ) as $l ) { // phpcs:ignore WordPress.DB
			$d = Finance::line_department( $l['service_id'], $inv['client_id'], 'manual' !== $l['source_type'] ? $l['source_type'] : '', $l['source_id'], $inv['department_id'] );
			$wpdb->update( $tbl, array( 'department_id' => $d ), array( 'id' => (int) $l['id'] ) );
		}
		self::restamp_payments( $invoice_id );
	}

	/** Replace an invoice's lines, keeping bill links (charges / renewal cycles) in step. */
	public static function apply_lines( $invoice_id, array $lines ) {
		global $wpdb;
		$tbl   = Schema::table( 'invoice_items' );
		$lines = self::normalize_lines( $lines );

		$inv_row    = $wpdb->get_row( $wpdb->prepare( 'SELECT client_id, department_id FROM ' . Schema::table( 'invoices' ) . ' WHERE id=%d', $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$inv_client = $inv_row ? (int) $inv_row['client_id'] : 0;
		$inv_dept   = $inv_row ? (int) $inv_row['department_id'] : 0;
		$old = $wpdb->get_results( $wpdb->prepare( "SELECT source_type, source_id FROM $tbl WHERE invoice_id=%d AND source_type IN ('charge','renewal')", $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$old_set = array();
		foreach ( (array) $old as $o ) {
			$old_set[ $o['source_type'] . ':' . $o['source_id'] ] = $o;
		}

		$wpdb->delete( $tbl, array( 'invoice_id' => $invoice_id ), array( '%d' ) );
		$new_set = array();
		foreach ( $lines as $l ) {
			$wpdb->insert(
				$tbl,
				array(
					'invoice_id'        => $invoice_id,
					'source_type'       => $l['source_type'],
					'source_id'         => $l['source_id'],
					'client_service_id' => $l['client_service_id'],
					'description'       => $l['description'],
					'kind'              => $l['kind'],
					'cost'              => $l['cost'],
					'service_id'        => $l['service_id'] ? $l['service_id'] : ( 'manual' !== $l['source_type'] ? Finance::source_service( $l['source_type'], $l['source_id'] ) : 0 ),
					'department_id'     => Finance::line_department( $l['service_id'] ? $l['service_id'] : ( 'manual' !== $l['source_type'] ? Finance::source_service( $l['source_type'], $l['source_id'] ) : 0 ), $inv_client, 'manual' !== $l['source_type'] ? $l['source_type'] : '', $l['source_id'], $inv_dept ),
					'period_start'      => $l['period_start'],
					'period_end'        => $l['period_end'],
					'quantity'          => $l['quantity'],
					'unit_price'        => $l['unit_price'],
					'amount'            => $l['amount'],
				)
			);
			if ( 'manual' !== $l['source_type'] ) {
				$new_set[ $l['source_type'] . ':' . $l['source_id'] ] = $l;
			}
		}
		foreach ( $old_set as $k => $o ) {
			if ( ! isset( $new_set[ $k ] ) ) {
				self::release_bill( $o['source_type'], (int) $o['source_id'] );
			}
		}
		foreach ( $new_set as $l ) {
			self::link_bill( $l['source_type'], (int) $l['source_id'], $invoice_id );
		}
	}

	/* ───────────────────────── Instalments ───────────────────────── */

	/**
	 * Validate/normalise a payment schedule against the invoice total.
	 * Rows may give a percent, an amount, or both. Returns [] for "pay in full".
	 *
	 * @return array|\WP_Error
	 */
	public static function normalize_installments( array $rows, $total ) {
		$total = round( (float) $total, 2 );
		$out   = array();
		foreach ( $rows as $r ) {
			$pct = isset( $r['percent'] ) && '' !== $r['percent'] && null !== $r['percent'] && is_numeric( $r['percent'] ) ? round( (float) $r['percent'], 2 ) : null;
			$amt = isset( $r['amount'] ) && '' !== $r['amount'] && null !== $r['amount'] && is_numeric( $r['amount'] ) ? round( (float) $r['amount'], 2 ) : null;
			if ( null === $amt && null !== $pct ) {
				$amt = round( $total * $pct / 100, 2 );
			}
			if ( null === $amt || $amt <= 0 ) {
				return new \WP_Error( 'bizyfinance_invalid', 'Each instalment needs an amount or a percentage.', array( 'status' => 400 ) );
			}
			if ( null === $pct && $total > 0 ) {
				$pct = round( $amt / $total * 100, 2 );
			}
			$out[] = array(
				'label'    => isset( $r['label'] ) ? mb_substr( sanitize_text_field( (string) $r['label'] ), 0, 190 ) : '',
				'due_date' => self::clean_date( isset( $r['due_date'] ) ? $r['due_date'] : '' ),
				'percent'  => $pct,
				'amount'   => $amt,
			);
		}
		if ( count( $out ) < 2 ) {
			return array();
		}
		$sum  = round( array_sum( wp_list_pluck( $out, 'amount' ) ), 2 );
		$diff = round( $total - $sum, 2 );
		if ( abs( $diff ) > 0.01 ) {
			$pct_sum = array_sum( wp_list_pluck( $out, 'percent' ) );
			if ( abs( $pct_sum - 100 ) <= 0.05 && abs( $diff ) <= max( 1, $total * 0.001 ) ) {
				$out[ count( $out ) - 1 ]['amount'] = round( $out[ count( $out ) - 1 ]['amount'] + $diff, 2 ); // rounding remainder
			} else {
				return new \WP_Error( 'bizyfinance_invalid', sprintf( 'The instalments add up to %s but the invoice total is %s.', number_format( $sum, 2 ), number_format( $total, 2 ) ), array( 'status' => 400 ) );
			}
		} elseif ( 0.0 !== $diff ) {
			$out[ count( $out ) - 1 ]['amount'] = round( $out[ count( $out ) - 1 ]['amount'] + $diff, 2 );
		}
		return $out;
	}

	public static function save_installments( $invoice_id, array $rows ) {
		global $wpdb;
		$tbl = Schema::table( 'invoice_installments' );
		$wpdb->delete( $tbl, array( 'invoice_id' => $invoice_id ), array( '%d' ) );
		foreach ( array_values( $rows ) as $i => $r ) {
			$wpdb->insert( $tbl, array( 'invoice_id' => $invoice_id, 'label' => $r['label'], 'due_date' => $r['due_date'], 'percent' => $r['percent'], 'amount' => $r['amount'], 'sort_order' => $i ) );
		}
	}

	/**
	 * The payment schedule with payments allocated oldest-first.
	 * Each: label, due_date, percent, amount, paid, balance, state (paid|partial|overdue|due|upcoming), implicit.
	 */
	public static function schedule( array $inv ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'invoice_installments' ) . ' WHERE invoice_id=%d ORDER BY sort_order, id', $inv['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$implicit = false;
		if ( ! $rows ) {
			$implicit = true;
			$rows     = array( array( 'id' => 0, 'label' => 'Payment in full', 'due_date' => $inv['due_date'], 'percent' => 100, 'amount' => $inv['amount'] ) );
		}
		$paid  = (float) ( isset( $inv['amount_paid'] ) ? $inv['amount_paid'] : 0 );
		$today = Billing::today();
		$out   = array();
		foreach ( $rows as $r ) {
			$amount  = (float) $r['amount'];
			$take    = min( $amount, max( 0, $paid ) );
			$paid   -= $take;
			$balance = round( $amount - $take, 2 );
			if ( $balance <= 0.004 ) {
				$state = 'paid';
			} elseif ( ! empty( $r['due_date'] ) && $r['due_date'] < $today ) {
				$state = 'overdue';
			} elseif ( $take > 0 ) {
				$state = 'partial';
			} elseif ( ! empty( $r['due_date'] ) && $r['due_date'] === $today ) {
				$state = 'due';
			} else {
				$state = 'upcoming';
			}
			$out[] = array(
				'id'       => (int) $r['id'],
				'label'    => $r['label'],
				'due_date' => $r['due_date'],
				'percent'  => null === $r['percent'] ? null : (float) $r['percent'],
				'amount'   => $amount,
				'paid'     => round( $take, 2 ),
				'balance'  => max( 0, $balance ),
				'state'    => $state,
				'implicit' => $implicit,
			);
		}
		return $out;
	}

	/* ───────────────────────── Status ───────────────────────── */

	/**
	 * Recompute amount_paid and status from payments and the schedule.
	 * Draft and cancelled invoices are left as the user set them.
	 */
	public static function refresh_status( $invoice_id ) {
		global $wpdb;
		$tbl = Schema::table( 'invoices' );
		$inv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE id=%d", $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $inv ) {
			return null;
		}

		$paid   = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Schema::table( 'payments' ) . ' WHERE invoice_id=%d', $invoice_id ) ); // phpcs:ignore WordPress.DB
		$amount = (float) $inv['amount'];
		$status = $inv['status'];

		if ( ! in_array( $status, array( 'draft', 'cancelled' ), true ) ) {
			$with_paid = $inv;
			$with_paid['amount_paid'] = $paid; // schedule allocation uses the fresh total; keep $inv's stored value for the change check below
			$states    = wp_list_pluck( self::schedule( $with_paid ), 'state' );
			if ( $amount > 0 && $paid >= $amount - 0.004 ) {
				$status = 'paid';
			} elseif ( in_array( 'overdue', $states, true ) ) {
				$status = 'overdue';
			} elseif ( $paid > 0 ) {
				$status = 'partially_paid';
			} elseif ( in_array( 'due', $states, true ) ) {
				$status = 'due';
			} elseif ( in_array( $status, array( 'paid', 'partially_paid', 'overdue', 'due' ), true ) ) {
				$status = 'sent'; // payment removed / due date moved
			}
		}

		if ( $status !== $inv['status'] || abs( $paid - (float) $inv['amount_paid'] ) > 0.001 ) {
			$wpdb->update( $tbl, array( 'status' => $status, 'amount_paid' => $paid, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $invoice_id ) );
			if ( $status !== $inv['status'] ) {
				do_action( 'bizyfinance_invoice_status', (int) $invoice_id, $inv['status'], $status );
				Activity::log( 'invoices', $invoice_id, $inv['client_id'], 'status', sprintf( 'Invoice %s is now %s', $inv['invoice_number'], str_replace( '_', ' ', $status ) ) );
			}
		}
		return $status;
	}

	/** Send charges back to pending and unlink renewals (invoice cancelled or deleted). */
	public static function release_sources( $invoice_id ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'charges' ) . " SET status='pending', invoice_id=0, updated_at=%s WHERE invoice_id=%d AND status='invoiced'", $now, $invoice_id ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'renewals' ) . ' SET invoice_id=0, updated_at=%s WHERE invoice_id=%d', $now, $invoice_id ) ); // phpcs:ignore WordPress.DB
	}

	/* ───────────────────────── Sharing ───────────────────────── */

	public static function public_url( $token ) {
		return $token ? home_url( '/' . self::slug_base() . '/' . rawurlencode( $token ) ) : '';
	}

	/** First path segment of public invoice links (Settings → invoice link word). */
	public static function slug_base() {
		$s = \BizyFinance\Settings::get();
		$b = isset( $s['invoice_slug_base'] ) ? sanitize_title( $s['invoice_slug_base'] ) : '';
		return '' !== $b ? $b : 'invoice';
	}

	/** Set a custom slug for an invoice link. Returns slug or WP_Error. */
	public static function set_slug( $invoice_id, $slug ) {
		global $wpdb;
		$tbl  = Schema::table( 'invoices' );
		$slug = sanitize_title( $slug );
		if ( strlen( $slug ) < 4 || strlen( $slug ) > 60 ) {
			return new \WP_Error( 'bizyfinance_slug', 'Use 4–60 letters, numbers or dashes.', array( 'status' => 400 ) );
		}
		$taken = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE public_token=%s AND id<>%d", $slug, $invoice_id ) ); // phpcs:ignore WordPress.DB
		if ( $taken ) {
			return new \WP_Error( 'bizyfinance_slug', 'That link is already used by another invoice.', array( 'status' => 409 ) );
		}
		$wpdb->update( $tbl, array( 'public_token' => $slug ), array( 'id' => $invoice_id ) );
		return $slug;
	}

	/** Existing share token, or a new one (always new when $regenerate). */
	public static function ensure_token( $invoice_id, $regenerate = false ) {
		global $wpdb;
		$tbl   = Schema::table( 'invoices' );
		$token = (string) $wpdb->get_var( $wpdb->prepare( "SELECT public_token FROM $tbl WHERE id=%d", $invoice_id ) ); // phpcs:ignore WordPress.DB
		if ( '' === $token || $regenerate ) {
			do {
				$token  = strtolower( wp_generate_password( 10, false, false ) );
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tbl WHERE public_token=%s", $token ) ); // phpcs:ignore WordPress.DB
			} while ( $exists );
			$wpdb->update( $tbl, array( 'public_token' => $token ), array( 'id' => $invoice_id ) );
		}
		return $token;
	}

	public static function mark_sent( $invoice_id ) {
		global $wpdb;
		$tbl = Schema::table( 'invoices' );
		$inv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE id=%d", $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $inv ) {
			return null;
		}
		$data = array( 'sent_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) );
		if ( 'draft' === $inv['status'] ) {
			$data['status'] = 'sent';
		}
		$wpdb->update( $tbl, $data, array( 'id' => $invoice_id ) );
		self::ensure_token( $invoice_id );
		self::refresh_status( $invoice_id );
		Activity::log( 'invoices', $invoice_id, $inv['client_id'], 'sent', sprintf( 'Invoice %s marked as sent', $inv['invoice_number'] ) );
		if ( 'draft' === $inv['status'] || empty( $inv['sent_at'] ) ) {
			do_action( 'bizyfinance_invoice_sent', (int) $invoice_id );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE id=%d", $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/* ───────────────────────── From bills ───────────────────────── */

	/**
	 * Build an invoice from pending charges and/or open renewals of one client.
	 *
	 * @return array|\WP_Error Invoice row.
	 */
	public static function create_from_sources( $client_id, array $charge_ids, array $renewal_ids, array $extra ) {
		global $wpdb;
		$client_id   = (int) $client_id;
		$charge_ids  = array_values( array_unique( array_filter( array_map( 'intval', $charge_ids ) ) ) );
		$renewal_ids = array_values( array_unique( array_filter( array_map( 'intval', $renewal_ids ) ) ) );
		if ( ! $client_id || ( ! $charge_ids && ! $renewal_ids ) ) {
			return new \WP_Error( 'bizyfinance_invalid', 'Select a client and at least one charge or renewal.', array( 'status' => 400 ) );
		}

		$ch       = Schema::table( 'charges' );
		$rn       = Schema::table( 'renewals' );
		$lines    = array();
		$currency = '';

		if ( $charge_ids ) {
			$in   = implode( ',', $charge_ids );
			$rows = $wpdb->get_results( "SELECT * FROM $ch WHERE id IN ($in)", ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( count( $rows ) !== count( $charge_ids ) ) {
				return new \WP_Error( 'bizyfinance_invalid', 'One or more charges were not found.', array( 'status' => 400 ) );
			}
			foreach ( $rows as $c ) {
				if ( (int) $c['client_id'] !== $client_id || 'pending' !== $c['status'] ) {
					return new \WP_Error( 'bizyfinance_invalid', 'Charges must be pending and belong to the selected client.', array( 'status' => 400 ) );
				}
				if ( $currency && $currency !== $c['currency'] ) {
					return new \WP_Error( 'bizyfinance_currency', 'All items on one invoice must share a currency.', array( 'status' => 400 ) );
				}
				$currency = $c['currency'];
				$lines[]  = array( 'description' => $c['description'], 'quantity' => 1, 'unit_price' => (float) $c['price'], 'cost' => (float) $c['cost'], 'service_id' => (int) $c['service_id'], 'source_type' => 'charge', 'source_id' => (int) $c['id'], 'client_service_id' => (int) $c['client_service_id'] );
			}
		}

		if ( $renewal_ids ) {
			$in   = implode( ',', $renewal_ids );
			$rows = $wpdb->get_results( "SELECT r.*, s.name AS service_name, cs.service_id AS cat_service_id FROM $rn r LEFT JOIN " . Schema::table( 'client_services' ) . ' cs ON cs.id=r.client_service_id LEFT JOIN ' . Schema::table( 'catalog' ) . " s ON s.id=cs.service_id WHERE r.id IN ($in)", ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( count( $rows ) !== count( $renewal_ids ) ) {
				return new \WP_Error( 'bizyfinance_invalid', 'One or more renewals were not found.', array( 'status' => 400 ) );
			}
			foreach ( $rows as $r ) {
				if ( (int) $r['client_id'] !== $client_id || (int) $r['invoice_id'] > 0 || ! in_array( $r['status'], array( 'upcoming', 'due' ), true ) ) {
					return new \WP_Error( 'bizyfinance_invalid', 'Renewals must be open, un-invoiced and belong to the selected client.', array( 'status' => 400 ) );
				}
				if ( $currency && $currency !== $r['currency'] ) {
					return new \WP_Error( 'bizyfinance_currency', 'All items on one invoice must share a currency.', array( 'status' => 400 ) );
				}
				$currency = $r['currency'];
				$next_due = Renewals::next_date( $r['due_date'], $r['frequency'], $r['interval_days'] );
				$lines[]  = array(
					'description'       => '' !== trim( (string) $r['subject'] ) ? trim( $r['service_name'] . ' — ' . $r['subject'] ) : trim( (string) $r['service_name'] ),
					'kind'              => (float) $r['unit_price'] > 0 ? 'quantity' : 'fixed',
					'cost'              => (float) $r['cost'],
					'service_id'        => (int) $r['cat_service_id'],
					'quantity'          => (float) $r['unit_price'] > 0 ? (float) $r['quantity'] : 1,
					'unit_price'        => (float) $r['unit_price'] > 0 ? (float) $r['unit_price'] : (float) $r['price'],
					'source_type'       => 'renewal',
					'source_id'         => (int) $r['id'],
					'client_service_id' => (int) $r['client_service_id'],
					'period_start'      => $r['due_date'],
					'period_end'        => $next_due ? gmdate( 'Y-m-d', strtotime( $next_due . ' -1 day' ) ) : null,
				);
			}
		}

		// Attach the invoice to a department when everything on it belongs to the same one; otherwise leave it "by line".
		$dp = array();
		foreach ( $lines as $l ) {
			$dp[] = Finance::line_department( isset( $l['service_id'] ) ? $l['service_id'] : 0, $client_id, isset( $l['source_type'] ) ? $l['source_type'] : '', isset( $l['source_id'] ) ? $l['source_id'] : 0 );
		}
		$dp   = array_unique( $dp );
		$head = 1 === count( $dp ) && (int) $dp[0] > 0 ? (int) $dp[0] : 0;
		$now      = current_time( 'mysql' );
		$today    = Billing::today();
		$days     = (int) Settings::get( 'default_due_days' );
		$tax_rate = (float) Settings::get( 'default_tax_rate' );
		$totals   = self::compute_totals( $lines, 'none', 0, $tax_rate );
		$norm     = self::normalize_lines( $lines );
		$data     = array(
			'client_id'       => $client_id,
			'department_id'   => $head,
			'invoice_number'  => ! empty( $extra['invoice_number'] ) ? sanitize_text_field( $extra['invoice_number'] ) : self::next_number(),
			'title'           => ! empty( $extra['title'] ) ? mb_substr( sanitize_text_field( $extra['title'] ), 0, 190 ) : self::default_title( $norm, ! empty( $extra['issue_date'] ) ? $extra['issue_date'] : $today ),
			'description'     => ! empty( $extra['description'] ) ? sanitize_textarea_field( $extra['description'] ) : implode( '; ', wp_list_pluck( $norm, 'description' ) ),
			'subtotal'        => $totals['subtotal'],
			'tax_rate'        => $tax_rate,
			'tax_amount'      => $totals['tax_amount'],
			'amount'          => $totals['total'],
			'currency'        => $currency,
			'issue_date'      => ! empty( $extra['issue_date'] ) ? sanitize_text_field( $extra['issue_date'] ) : $today,
			'due_date'        => ! empty( $extra['due_date'] ) ? sanitize_text_field( $extra['due_date'] ) : gmdate( 'Y-m-d', strtotime( "$today +$days days" ) ),
			'status'          => ! empty( $extra['status'] ) && in_array( $extra['status'], array( 'draft', 'sent' ), true ) ? $extra['status'] : 'draft',
			'invoice_url'     => ! empty( $extra['invoice_url'] ) ? esc_url_raw( $extra['invoice_url'] ) : '',
			'attachment_id'   => ! empty( $extra['attachment_id'] ) ? absint( $extra['attachment_id'] ) : 0,
			'terms'           => (string) Settings::get( 'invoice_terms' ),
			'notes'           => ! empty( $extra['notes'] ) ? sanitize_textarea_field( $extra['notes'] ) : '',
			'created_by'      => get_current_user_id(),
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$wpdb->insert( Schema::table( 'invoices' ), $data );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new \WP_Error( 'bizyfinance_db', 'Could not create the invoice.', array( 'status' => 500 ) );
		}

		self::apply_lines( $id, $lines );
		if ( 'sent' === $data['status'] ) {
			$wpdb->update( Schema::table( 'invoices' ), array( 'sent_at' => $now ), array( 'id' => $id ) );
		}
		self::refresh_status( $id );
		Activity::log( 'invoices', $id, $client_id, 'created', sprintf( 'Invoice %s created from %d item(s)', $data['invoice_number'], count( $norm ) ) );

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'invoices' ) . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
