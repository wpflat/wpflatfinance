<?php
namespace WPFlatFinance;

use WPFlatCore\Portal;
use WPFlatFinance\Schema;

defined( 'ABSPATH' ) || exit;

/** The client portal's "Billing" page: invoices, payments, and what is coming up. Prices only, never costs. */
class Portal_Page {

	public static function register() {
		Portal::register_page( 'billing', array(
			'module'   => 'finance',
			'label'    => 'Billing',
			'order'    => 20,
			'render'   => array( __CLASS__, 'render' ),
			'overview' => array( __CLASS__, 'overview' ),
			'options'  => array(
				array( 'key' => 'portal_show_invoices', 'label' => 'Invoices and payments' ),
				array( 'key' => 'portal_show_upcoming', 'label' => 'Bills coming up (not invoiced yet)' ),
			),
		) );
	}

	public static function render( $ctx, $id ) {
		return $id ? self::invoice_page( $ctx, $id ) : self::billing( $ctx );
	}

	/** The overview cards, and overdue invoices under "Needs your attention". */
	public static function overview( $ctx ) {
		$c      = $ctx['client'];
		$cards  = '';
		$attn   = '';
		$panels = '';
		if ( false !== Settings::get( 'portal_show_invoices' ) ) {
			$o      = self::owed( $c['id'] );
			$cards .= '<a class="stat" href="' . esc_url( Portal::link( 'billing', array( 'show' => 'open' ) ) ) . '"><span class="stat__l">Outstanding</span><span class="stat__v">' . Portal::e( self::money_map( $o['by_currency'] ) ) . '</span><span class="stat__s">' . ( $o['overdue'] ? Portal::e( $o['overdue'] . ' overdue' ) : Portal::e( $o['open'] . ' open invoice' . ( 1 === $o['open'] ? '' : 's' ) ) ) . '</span></a>';
			$late   = array_values( array_filter( self::invoices( $c['id'], 'open' ), function ( $x ) {
				return 'overdue' === $x['status'];
			} ) );
			foreach ( array_slice( $late, 0, 5 ) as $i ) {
				$attn .= '<li><a href="' . esc_url( Portal::link( 'billing/' . $i['id'] ) ) . '"><strong>Invoice ' . Portal::e( $i['invoice_number'] ) . '</strong> is overdue</a><span>' . Portal::e( self::money( $i['balance'], $i['currency'] ) ) . ' · due ' . Portal::e( Portal::date( $i['due_date'] ) ) . '</span></li>';
			}
			if ( count( $late ) > 5 ) {
				$attn .= '<li><a href="' . esc_url( Portal::link( 'billing', array( 'show' => 'open' ) ) ) . '">And ' . ( count( $late ) - 5 ) . ' more overdue invoices</a><span></span></li>';
			}
			$recent = array_slice( self::invoices( $c['id'] ), 0, 5 );
			if ( $recent ) {
				$panels = '<section class="panel"><h2>Recent invoices</h2>' . self::invoice_table( $recent ) . '</section>';
			}
		}
		if ( false !== Settings::get( 'portal_show_upcoming' ) ) {
			$up  = self::upcoming( $c['id'] );
			$sum = array();
			foreach ( $up as $u ) {
				$sum[ $u['currency'] ] = ( isset( $sum[ $u['currency'] ] ) ? $sum[ $u['currency'] ] : 0 ) + $u['amount'];
			}
			$cards .= '<a class="stat" href="' . esc_url( Portal::link( 'billing', array( 'show' => 'upcoming' ) ) ) . '"><span class="stat__l">Coming up to bill</span><span class="stat__v">' . Portal::e( self::money_map( $sum ) ) . '</span><span class="stat__s">' . count( $up ) . ' item' . ( 1 === count( $up ) ? '' : 's' ) . ' not invoiced yet</span></a>';
		}
		return array( 'cards' => $cards, 'attention' => $attn, 'panels' => $panels );
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

	private static function money_map( array $map ) {
		if ( ! $map ) {
			return '—';
		}
		return implode( ' · ', array_map( function ( $c, $v ) {
			return self::money( $v, $c );
		}, array_keys( $map ), $map ) );
	}

	private static function invoice_badge( $status ) {
		$m = array( 'sent' => array( 'Awaiting payment', 'info' ), 'due' => array( 'Due', 'warning' ), 'overdue' => array( 'Overdue', 'danger' ), 'partially_paid' => array( 'Part paid', 'warning' ), 'paid' => array( 'Paid', 'success' ) );
		$x = $m[ $status ] ?? array( ucfirst( $status ), 'neutral' );
		return Portal::badge( $x[0], $x[1] );
	}

	private static function invoice_table( array $rows ) {
		$h = '<div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="num">Amount</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$h .= '<tr><td><a href="' . esc_url( Portal::link( 'billing/' . $r['id'] ) ) . '"><strong>' . Portal::e( $r['invoice_number'] ) . '</strong></a><div class="muted">' . Portal::e( $r['title'] ) . '</div></td><td>' . Portal::e( Portal::date( $r['issue_date'] ) ) . '</td><td>' . Portal::e( Portal::date( $r['due_date'] ) ) . '</td><td class="num">' . Portal::e( self::money( $r['amount'], $r['currency'] ) ) . '</td><td class="num">' . Portal::e( self::money( $r['balance'], $r['currency'] ) ) . '</td><td>' . self::invoice_badge( $r['status'] ) . '</td></tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/** Invoices the client can see (never drafts or cancelled ones). */
	public static function invoices( $client_id, $filter = 'all' ) {
		global $wpdb;
		$where = "i.client_id=%d AND i.status NOT IN ('draft','cancelled')";
		if ( 'open' === $filter ) {
			$where .= " AND i.status IN ('sent','due','partially_paid','overdue')";
		} elseif ( 'paid' === $filter ) {
			$where .= " AND i.status='paid'";
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.invoice_number, i.title, i.amount, i.amount_paid, i.currency, i.issue_date, i.due_date, i.status, i.public_token FROM " . Schema::table( 'invoices' ) . " i WHERE $where ORDER BY i.issue_date DESC, i.id DESC", $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as &$r ) {
			$r['id']      = (int) $r['id'];
			$r['balance'] = max( 0, round( (float) $r['amount'] - (float) $r['amount_paid'], 2 ) );
		}
		return $rows;
	}

	public static function invoice( $client_id, $id ) {
		global $wpdb;
		$i = $wpdb->get_row( $wpdb->prepare( "SELECT id, invoice_number, title, description, amount, amount_paid, subtotal, discount_amount, tax_rate, tax_amount, currency, issue_date, due_date, status, public_token, terms FROM " . Schema::table( 'invoices' ) . " WHERE id=%d AND client_id=%d AND status NOT IN ('draft','cancelled')", $id, $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $i ) {
			return null;
		}
		$i['balance']  = max( 0, round( (float) $i['amount'] - (float) $i['amount_paid'], 2 ) );
		$i['items']    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT description, quantity, unit_price, amount FROM ' . Schema::table( 'invoice_items' ) . ' WHERE invoice_id=%d ORDER BY id', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$i['payments'] = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT amount, currency, paid_on, method, reference FROM ' . Schema::table( 'payments' ) . ' WHERE invoice_id=%d ORDER BY paid_on, id', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $i;
	}

	/** Money still owed, per currency, and how many invoices are overdue. */
	public static function owed( $client_id ) {
		$out = array( 'by_currency' => array(), 'overdue' => 0, 'open' => 0 );
		foreach ( self::invoices( $client_id, 'open' ) as $r ) {
			$out['by_currency'][ $r['currency'] ] = round( ( $out['by_currency'][ $r['currency'] ] ?? 0 ) + $r['balance'], 2 );
			$out['open']++;
			if ( 'overdue' === $r['status'] ) {
				$out['overdue']++;
			}
		}
		return $out;
	}

	/** Bills we have raised for the client that are not on an invoice yet: recurring cycles and one-off charges. Prices only. */
	public static function upcoming( $client_id ) {
		global $wpdb;
		$rows = array();
		$sv   = Schema::table( 'catalog' );
		$cs   = Schema::table( 'client_services' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.id, r.due_date, r.price, r.currency, r.subject, r.frequency, s.name, s.type FROM " . Schema::table( 'renewals' ) . " r LEFT JOIN $cs c ON c.id=r.client_service_id LEFT JOIN $sv s ON s.id=c.service_id WHERE r.client_id=%d AND r.status IN ('upcoming','due') AND r.invoice_id=0 AND r.price>0 ORDER BY r.due_date ASC", $client_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$rows[] = array( 'title' => '' !== (string) $r['subject'] ? $r['name'] . ' — ' . $r['subject'] : (string) $r['name'], 'kind' => 'product' === $r['type'] ? 'Subscription' : 'Recurring', 'date' => $r['due_date'], 'amount' => (float) $r['price'], 'currency' => $r['currency'] );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT description, price, currency, bill_on_date, incurred_on FROM " . Schema::table( 'charges' ) . " WHERE client_id=%d AND status='pending' AND invoice_id=0 AND price>0 ORDER BY COALESCE(bill_on_date, incurred_on) ASC", $client_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$rows[] = array( 'title' => (string) $r['description'], 'kind' => 'One-off', 'date' => $r['bill_on_date'] ? $r['bill_on_date'] : $r['incurred_on'], 'amount' => (float) $r['price'], 'currency' => $r['currency'] );
		}
		usort( $rows, function ( $a, $b ) {
			return strcmp( (string) $a['date'], (string) $b['date'] );
		} );
		return $rows;
	}

	private static function billing( $ctx ) {
		$c    = $ctx['client'];
		$sec  = array( 'invoices' => false !== Settings::get( 'portal_show_invoices' ), 'billing' => false !== Settings::get( 'portal_show_upcoming' ) );
		$show = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : ( $sec['invoices'] ? 'all' : 'upcoming' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'upcoming' === $show && ! $sec['billing'] ) {
			$show = 'all';
		}
		if ( 'upcoming' !== $show && ! $sec['invoices'] ) {
			$show = 'upcoming';
		}
		$h = '<h1>Billing</h1><p class="lead">Invoices we have sent you, and what is coming up.</p><div class="tabs">';
		if ( $sec['invoices'] ) {
			foreach ( array( 'all' => 'All invoices', 'open' => 'Unpaid', 'paid' => 'Paid' ) as $k => $l ) {
				$h .= '<a class="tab' . ( $show === $k ? ' is-on' : '' ) . '" href="' . esc_url( Portal::link( 'billing', array( 'show' => $k ) ) ) . '">' . Portal::e( $l ) . '</a>';
			}
		}
		if ( $sec['billing'] ) {
			$h .= '<a class="tab' . ( 'upcoming' === $show ? ' is-on' : '' ) . '" href="' . esc_url( Portal::link( 'billing', array( 'show' => 'upcoming' ) ) ) . '">Coming up</a>';
		}
		$h .= '</div>';
		if ( 'upcoming' === $show ) {
			$up = self::upcoming( $c['id'] );
			$h .= '<p class="muted">These are charges we have lined up for you that have not been put on an invoice yet. You will receive an invoice for each one.</p>';
			if ( ! $up ) {
				$h .= Portal::empty_state( 'Nothing coming up', 'There is nothing waiting to be invoiced right now.' );
			} else {
				$sum = array();
				$h  .= '<div class="table-wrap"><table><thead><tr><th>What</th><th>Type</th><th>Expected</th><th class="num">Amount</th></tr></thead><tbody>';
				foreach ( $up as $u ) {
					$sum[ $u['currency'] ] = ( $sum[ $u['currency'] ] ?? 0 ) + $u['amount'];
					$h .= '<tr><td><strong>' . Portal::e( $u['title'] ) . '</strong></td><td>' . Portal::badge( $u['kind'] ) . '</td><td>' . Portal::e( Portal::date( $u['date'] ) ) . '</td><td class="num">' . Portal::e( self::money( $u['amount'], $u['currency'] ) ) . '</td></tr>';
				}
				$h .= '</tbody><tfoot><tr><td colspan="3">Total</td><td class="num">' . Portal::e( self::money_map( $sum ) ) . '</td></tr></tfoot></table></div>';
			}
		} else {
			$o = self::owed( $c['id'] );
			if ( $o['open'] ) {
				$h .= '<div class="notice">Outstanding: <strong>' . Portal::e( self::money_map( $o['by_currency'] ) ) . '</strong> across ' . (int) $o['open'] . ' invoice' . ( 1 === $o['open'] ? '' : 's' ) . ( $o['overdue'] ? ' (' . (int) $o['overdue'] . ' overdue)' : '' ) . '.</div>';
			}
			$rows  = self::invoices( $c['id'], $show );
			$per   = 25;
			$pages = max( 1, (int) ceil( count( $rows ) / $per ) );
			$pg    = min( $pages, max( 1, isset( $_GET['pg'] ) ? absint( $_GET['pg'] ) : 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$h    .= $rows ? self::invoice_table( array_slice( $rows, ( $pg - 1 ) * $per, $per ) ) : Portal::empty_state( 'No invoices', 'Invoices we send you will be listed here.' );
			if ( $pages > 1 ) {
				$h .= '<div class="tabs" style="margin-top:14px">' . ( $pg > 1 ? '<a class="tab" href="' . esc_url( Portal::link( 'billing', array( 'show' => $show, 'pg' => $pg - 1 ) ) ) . '">← Newer</a>' : '' ) . '<span class="tab">Page ' . $pg . ' of ' . $pages . '</span>' . ( $pg < $pages ? '<a class="tab" href="' . esc_url( Portal::link( 'billing', array( 'show' => $show, 'pg' => $pg + 1 ) ) ) . '">Older →</a>' : '' ) . '</div>';
			}
		}
		return $h;
	}

	private static function invoice_page( $ctx, $id ) {
		$i = self::invoice( $ctx['client']['id'], $id );
		if ( ! $i ) {
			return Portal::empty_state( 'Not found', 'We could not find that invoice.' );
		}
		$cur = $i['currency'];
		$h   = '<p class="crumb"><a href="' . esc_url( Portal::link( 'billing' ) ) . '">← Billing</a></p><div class="head"><h1>Invoice ' . Portal::e( $i['invoice_number'] ) . '</h1>' . self::invoice_badge( $i['status'] ) . '</div>';
		$h  .= '<p class="lead">' . Portal::e( $i['title'] ) . '</p>';
		$h  .= '<div class="stats"><div class="stat"><span class="stat__l">Amount</span><span class="stat__v">' . Portal::e( self::money( $i['amount'], $cur ) ) . '</span></div><div class="stat"><span class="stat__l">Paid</span><span class="stat__v">' . Portal::e( self::money( $i['amount_paid'], $cur ) ) . '</span></div><div class="stat"><span class="stat__l">Balance</span><span class="stat__v">' . Portal::e( self::money( $i['balance'], $cur ) ) . '</span><span class="stat__s">Due ' . Portal::e( Portal::date( $i['due_date'] ) ) . '</span></div></div>';
		if ( $i['balance'] > 0 && $i['public_token'] ) {
			$h .= '<p><a class="btn" href="' . esc_url( Services\Invoices::public_url( $i['public_token'] ) ) . '">View, print or pay this invoice</a></p>';
		} elseif ( $i['public_token'] ) {
			$h .= '<p><a class="btn btn--ghost" href="' . esc_url( Services\Invoices::public_url( $i['public_token'] ) ) . '">View or print this invoice</a></p>';
		}
		$h .= '<section class="panel"><h2>What it covers</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead><tbody>';
		foreach ( $i['items'] as $it ) {
			$h .= '<tr><td>' . Portal::e( $it['description'] ) . '</td><td class="num">' . Portal::e( rtrim( rtrim( number_format( (float) $it['quantity'], 2 ), '0' ), '.' ) ) . '</td><td class="num">' . Portal::e( self::money( $it['unit_price'], $cur ) ) . '</td><td class="num">' . Portal::e( self::money( $it['amount'], $cur ) ) . '</td></tr>';
		}
		$h .= '</tbody><tfoot>';
		if ( (float) $i['discount_amount'] > 0 || (float) $i['tax_amount'] > 0 ) {
			$h .= '<tr><td colspan="3">Subtotal</td><td class="num">' . Portal::e( self::money( $i['subtotal'], $cur ) ) . '</td></tr>';
			if ( (float) $i['discount_amount'] > 0 ) {
				$h .= '<tr><td colspan="3">Discount</td><td class="num">−' . Portal::e( self::money( $i['discount_amount'], $cur ) ) . '</td></tr>';
			}
			if ( (float) $i['tax_amount'] > 0 ) {
				$h .= '<tr><td colspan="3">' . Portal::e( Settings::get( 'tax_label' ) ?: 'Tax' ) . ' (' . Portal::e( rtrim( rtrim( number_format( (float) $i['tax_rate'], 2 ), '0' ), '.' ) ) . '%)</td><td class="num">' . Portal::e( self::money( $i['tax_amount'], $cur ) ) . '</td></tr>';
			}
		}
		$h .= '<tr><td colspan="3"><strong>Total</strong></td><td class="num"><strong>' . Portal::e( self::money( $i['amount'], $cur ) ) . '</strong></td></tr></tfoot></table></div></section>';
		$h .= '<section class="panel"><h2>Payments received</h2>';
		if ( $i['payments'] ) {
			$h .= '<div class="table-wrap"><table><thead><tr><th>Date</th><th>How</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>';
			foreach ( $i['payments'] as $p ) {
				$h .= '<tr><td>' . Portal::e( Portal::date( $p['paid_on'] ) ) . '</td><td>' . Portal::e( $p['method'] ?: '—' ) . '</td><td>' . Portal::e( $p['reference'] ?: '—' ) . '</td><td class="num">' . Portal::e( self::money( $p['amount'], $p['currency'] ?: $cur ) ) . '</td></tr>';
			}
			$h .= '</tbody></table></div>';
		} else {
			$h .= '<p class="muted">No payment has been recorded yet.</p>';
		}
		$h .= '</section>';
		return $h;
	}
}
