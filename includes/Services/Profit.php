<?php
namespace WPFlatFinance\Services;

use WPFlatFinance\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The profit picture, in plain steps:
 *   Income − Direct costs = What we keep − Team pay − Running expenses = Profit.
 * Everything is per currency (never converted). Income comes from invoice lines (net of discount and tax);
 * direct costs are the cost snapshots on those lines; team pay is salaries, project/task pay and extras;
 * running expenses are overhead only, so nothing is counted twice.
 */
class Profit {

	/* ───────────────────────── Row loaders (all dates inclusive) ───────────────────────── */

	/** Income rows: one per invoice line (or per invoice for legacy invoices without lines). */
	public static function income_rows( $from, $to, $basis ) {
		global $wpdb;
		$inv = Schema::table( 'invoices' );
		$it  = Schema::table( 'invoice_items' );
		$pay = Schema::table( 'payments' );

		if ( 'paid' === $basis ) {
			$events = $wpdb->get_results( $wpdb->prepare( "SELECT p.paid_on d, p.amount pay, i.* FROM $pay p JOIN $inv i ON i.id=p.invoice_id WHERE i.status <> 'cancelled' AND p.paid_on BETWEEN %s AND %s", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$events = $wpdb->get_results( $wpdb->prepare( "SELECT i.issue_date d, i.amount pay, i.* FROM $inv i WHERE i.status NOT IN ('draft','cancelled') AND i.issue_date BETWEEN %s AND %s", $from, $to ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		if ( ! $events ) {
			return array();
		}
		$ids   = array_unique( array_map( 'intval', wp_list_pluck( $events, 'id' ) ) );
		$lines = array();
		foreach ( (array) $wpdb->get_results( "SELECT invoice_id, amount, cost, department_id FROM $it WHERE invoice_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ) as $l ) { // phpcs:ignore WordPress.DB
			$lines[ (int) $l['invoice_id'] ][] = $l;
		}
		$rows = array();
		foreach ( $events as $e ) {
			$A    = (float) $e['amount'];
			$S    = (float) $e['subtotal'];
			$net  = $S > 0 ? ( $S - (float) $e['discount_amount'] ) / $S : ( $A > 0 ? ( $A - (float) $e['tax_amount'] ) / $A : 1 );
			$f    = $A > 0 ? min( 1, (float) $e['pay'] / $A ) : 1; // share of the invoice this event recognises
			$list = isset( $lines[ (int) $e['id'] ] ) ? $lines[ (int) $e['id'] ] : array( array( 'amount' => $S > 0 ? $S : $A - (float) $e['tax_amount'], 'cost' => 0, 'department_id' => 0 ) );
			foreach ( $list as $l ) {
				$rows[] = array( 'date' => $e['d'], 'cur' => $e['currency'], 'client' => (int) $e['client_id'], 'dept' => (int) $e['department_id'] > 0 ? (int) $e['department_id'] : (int) $l['department_id'], 'income' => (float) $l['amount'] * $net * $f, 'direct' => (float) $l['cost'] * $f );
			}
		}
		return $rows;
	}

	public static function team_rows( $from, $to, $basis ) {
		global $wpdb;
		$pi = Schema::table( 'pay_items' );
		if ( 'paid' === $basis ) {
			$sql = $wpdb->prepare( 'SELECT po.paid_on d, i.currency cur, i.team_member_id m, i.project_id pj, i.department_id dept, i.amount a FROM ' . $pi . ' i JOIN ' . Schema::table( 'payouts' ) . " po ON po.id=i.payout_id WHERE po.status='paid' AND i.status='paid' AND po.paid_on BETWEEN %s AND %s", $from, $to ); // phpcs:ignore WordPress.DB
		} else {
			$sql = $wpdb->prepare( "SELECT earned_on d, currency cur, team_member_id m, project_id pj, department_id dept, amount a FROM $pi WHERE status IN ('payable','paid') AND earned_on BETWEEN %s AND %s", $from, $to ); // phpcs:ignore WordPress.DB
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = array( 'date' => $r['d'], 'cur' => $r['cur'], 'member' => (int) $r['m'], 'project' => (int) $r['pj'], 'dept' => (int) $r['dept'], 'amount' => (float) $r['a'] );
		}
		return $out;
	}

	public static function expense_rows( $from, $to ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT spent_on d, currency cur, category c, department_id dept, amount a FROM ' . Schema::table( 'expenses' ) . ' WHERE spent_on BETWEEN %s AND %s', $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = array( 'date' => $r['d'], 'cur' => $r['cur'], 'category' => $r['c'], 'dept' => (int) $r['dept'], 'amount' => (float) $r['a'] );
		}
		return $out;
	}

	/* ───────────────────────── Aggregation ───────────────────────── */

	public static function blank() {
		return array( 'income' => 0.0, 'direct' => 0.0, 'keep' => 0.0, 'team' => 0.0, 'expenses' => 0.0, 'profit' => 0.0, 'margin_pct' => 0 );
	}

	public static function finish( array $b ) {
		foreach ( array( 'income', 'direct', 'team', 'expenses' ) as $k ) {
			$b[ $k ] = round( $b[ $k ], 2 );
		}
		$b['keep']       = round( $b['income'] - $b['direct'], 2 );
		$b['profit']     = round( $b['keep'] - $b['team'] - $b['expenses'], 2 );
		$b['margin_pct'] = $b['income'] > 0 ? (int) round( 100 * $b['profit'] / $b['income'] ) : 0;
		return $b;
	}

	/** $dept: null = everything, 0 = general / no department, N = that department. */
	public static function sum( array $inc, array $team, array $exp, $from, $to, $dept ) {
		$out = array();
		$get = function ( $cur ) use ( &$out ) {
			if ( ! isset( $out[ $cur ] ) ) {
				$out[ $cur ] = self::blank();
			}
		};
		$ok = function ( $row ) use ( $from, $to, $dept ) {
			return $row['date'] >= $from && $row['date'] <= $to && ( null === $dept || (int) $row['dept'] === (int) $dept );
		};
		foreach ( $inc as $r ) {
			if ( $ok( $r ) ) {
				$get( $r['cur'] );
				$out[ $r['cur'] ]['income'] += $r['income'];
				$out[ $r['cur'] ]['direct'] += $r['direct'];
			}
		}
		foreach ( $team as $r ) {
			if ( $ok( $r ) ) {
				$get( $r['cur'] );
				$out[ $r['cur'] ]['team'] += $r['amount'];
			}
		}
		foreach ( $exp as $r ) {
			if ( $ok( $r ) ) {
				$get( $r['cur'] );
				$out[ $r['cur'] ]['expenses'] += $r['amount'];
			}
		}
		foreach ( $out as $cur => $b ) {
			$out[ $cur ] = self::finish( $b );
		}
		return $out;
	}

	/* ───────────────────────── Public API ───────────────────────── */

	/** Lightweight profit-for-a-period (used by Home). */
	public static function quick( $from, $to ) {
		return self::sum( self::income_rows( $from, $to, 'invoiced' ), self::team_rows( $from, $to, 'earned' ), self::expense_rows( $from, $to ), $from, $to, null );
	}

	public static function overview( $from, $to, $income_basis = 'invoiced', $pay_basis = 'earned', $dept = null ) {
		global $wpdb;
		$income_basis = 'paid' === $income_basis ? 'paid' : 'invoiced';
		$pay_basis    = 'paid' === $pay_basis ? 'paid' : 'earned';
		$today        = Billing::today();
		$from         = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? $from : gmdate( 'Y-m-01', strtotime( $today ) );
		$to           = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? $to : gmdate( 'Y-m-t', strtotime( $today ) );
		if ( 'none' === $dept ) {
			$dept = 0;
		} elseif ( null !== $dept && '' !== $dept && (int) $dept > 0 ) {
			$dept = (int) $dept;
		} else {
			$dept = null;
		}

		// One load covering the report range and the six-month chart.
		$m0     = gmdate( 'Y-m-01', strtotime( gmdate( 'Y-m-01', strtotime( $to ) ) . ' -5 months' ) );
		$wide0  = min( $from, $m0 );
		$inc    = self::income_rows( $wide0, $to, $income_basis );
		$team   = self::team_rows( $wide0, $to, $pay_basis );
		$exp    = self::expense_rows( $wide0, $to );

		$summary = self::sum( $inc, $team, $exp, $from, $to, $dept );

		// Six months for the chart.
		$labels = array();
		$months = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$start    = gmdate( 'Y-m-01', strtotime( gmdate( 'Y-m-01', strtotime( $to ) ) . " -$i months" ) );
			$end      = gmdate( 'Y-m-t', strtotime( $start ) );
			$labels[] = wp_date( 'M', strtotime( $start ) );
			$cur_map  = self::sum( $inc, $team, $exp, $start, $end, $dept );
			foreach ( array_keys( $summary + $cur_map ) as $cur ) {
				$b = isset( $cur_map[ $cur ] ) ? $cur_map[ $cur ] : self::blank();
				foreach ( array( 'income' => $b['income'], 'costs' => round( $b['direct'] + $b['team'] + $b['expenses'], 2 ), 'profit' => $b['profit'] ) as $k => $v ) {
					$months[ $cur ][ $k ][ $labels[ count( $labels ) - 1 ] . $start ] = $v;
				}
			}
		}
		$chart = array( 'labels' => $labels, 'currencies' => array() );
		foreach ( $months as $cur => $ks ) {
			foreach ( $ks as $k => $vals ) {
				$chart['currencies'][ $cur ][ $k ] = array_values( $vals );
			}
		}

		// By department (always across all departments, within the range).
		$departments = array();
		$names       = array( 0 => 'General / no department' );
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'brands' ) . ' ORDER BY name', ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB
			$names[ (int) $b['id'] ] = $b['name'];
		}
		foreach ( $names as $id => $name ) {
			$s = self::sum( $inc, $team, $exp, $from, $to, $id );
			if ( $s ) {
				$departments[] = array( 'id' => $id, 'name' => $name, 'values' => $s );
			}
		}

		// By client (income side, within the range and department filter).
		$clients = array();
		foreach ( $inc as $r ) {
			if ( $r['date'] >= $from && $r['date'] <= $to && ( null === $dept || (int) $r['dept'] === (int) $dept ) ) {
				$k = $r['client'] . '|' . $r['cur'];
				if ( ! isset( $clients[ $k ] ) ) {
					$clients[ $k ] = array( 'id' => $r['client'], 'cur' => $r['cur'], 'income' => 0.0, 'direct' => 0.0 );
				}
				$clients[ $k ]['income'] += $r['income'];
				$clients[ $k ]['direct'] += $r['direct'];
			}
		}
		$cname = array();
		if ( $clients ) {
			$cids = implode( ',', array_unique( array_map( 'intval', wp_list_pluck( $clients, 'id' ) ) ) );
			foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'clients' ) . " WHERE id IN ($cids)", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB
				$cname[ (int) $c['id'] ] = $c['name'];
			}
		}
		$client_rows = array();
		foreach ( $clients as $c ) {
			$client_rows[] = array( 'id' => $c['id'], 'name' => isset( $cname[ $c['id'] ] ) ? $cname[ $c['id'] ] : 'Unknown', 'currency' => $c['cur'], 'income' => round( $c['income'], 2 ), 'direct' => round( $c['direct'], 2 ), 'keep' => round( $c['income'] - $c['direct'], 2 ) );
		}
		usort( $client_rows, function ( $a, $b ) {
			return $b['keep'] <=> $a['keep'];
		} );

		// By project: price vs team pay earned (all time for the project).
		$project_rows = array();
		foreach ( (array) $wpdb->get_results( 'SELECT p.*, c.name FROM ' . Schema::pj() . ' p LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=p.client_id WHERE p.price > 0 ORDER BY p.id DESC LIMIT 30', ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB
			if ( null !== $dept && (int) $p['department_id'] !== (int) $dept ) {
				continue;
			}
			$m              = Pay::project_money( $p );
			$project_rows[] = array( 'id' => (int) $p['id'], 'name' => $p['name'], 'client_name' => $p['name'], 'currency' => $p['currency'] ? $p['currency'] : \WPFlatFinance\Settings::get( 'default_currency' ), 'price' => $m['price'], 'team_cost' => max( $m['allocated'], $m['earned'] ), 'margin' => $m['margin'] );
		}

		// Balances and the monthly picture.
		$open = "('sent','due','partially_paid','overdue')";
		$recv = array();
		foreach ( (array) $wpdb->get_results( 'SELECT currency, SUM(amount-amount_paid) t, COUNT(*) n FROM ' . Schema::table( 'invoices' ) . " WHERE status IN $open GROUP BY currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$recv[ $r['currency'] ] = array( 'amount' => round( (float) $r['t'], 2 ), 'count' => (int) $r['n'] );
		}
		$owed = array();
		foreach ( (array) $wpdb->get_results( 'SELECT currency, SUM(amount) t, COUNT(*) n FROM ' . Schema::table( 'pay_items' ) . " WHERE status='payable' GROUP BY currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$owed[ $r['currency'] ] = array( 'amount' => round( (float) $r['t'], 2 ), 'count' => (int) $r['n'] );
		}
		$rec_in  = Subscriptions::mrr_totals( null );
		$rec_out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT amount, currency, period FROM ' . Schema::table( 'recurring_expenses' ) . " WHERE status='active'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$cur = $r['currency'];
			$rec_out[ $cur ] = array( 'amount' => round( ( isset( $rec_out[ $cur ] ) ? $rec_out[ $cur ]['amount'] : 0 ) + Expenses::monthly_value( $r['amount'], $r['period'] ), 2 ), 'count' => 1 );
		}
		foreach ( (array) $wpdb->get_results( 'SELECT salary_amount, currency FROM ' . Schema::tm() . " tm WHERE status='active' AND salary_amount > 0", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$cur = $r['currency'] ? $r['currency'] : \WPFlatFinance\Settings::get( 'default_currency' );
			$rec_out[ $cur ] = array( 'amount' => round( ( isset( $rec_out[ $cur ] ) ? $rec_out[ $cur ]['amount'] : 0 ) + (float) $r['salary_amount'], 2 ), 'count' => 1 );
		}

		return array(
			'range'       => array( 'from' => $from, 'to' => $to ),
			'bases'       => array( 'income' => $income_basis, 'pay' => $pay_basis ),
			'department'  => $dept,
			'summary'     => $summary,
			'chart'       => $chart,
			'departments' => $departments,
			'clients'     => array_slice( $client_rows, 0, 10 ),
			'projects'    => $project_rows,
			'balances'    => array( 'receivable' => $recv, 'payable' => $owed, 'recurring_income' => $rec_in, 'recurring_costs' => $rec_out ),
		);
	}

	/** CSV for the accountant: income lines, expenses or team pay in a date range. */
	public static function export( $type, $from, $to, $dept = 0, $cur = '' ) {
		global $wpdb;
		$rows = array();
		$cur  = preg_match( '/^[A-Z]{3}$/', (string) $cur ) ? $cur : '';
		$dept = (int) $dept;
		// Optional narrowing by department and currency (the analytics filters), as extra AND clauses.
		$and = function ( $dcol, $ccol ) use ( $dept, $cur, $wpdb ) {
			return ( $dept ? $wpdb->prepare( " AND $dcol=%d", $dept ) : '' ) . ( $cur ? $wpdb->prepare( " AND $ccol=%s", $cur ) : '' ); // phpcs:ignore WordPress.DB
		};
		if ( 'expenses' === $type ) {
			$rows[] = array( 'Date', 'Category', 'Vendor', 'Description', 'Department', 'Amount', 'Currency', 'Paid by', 'Reference' );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT e.*, d.name dn FROM ' . Schema::table( 'expenses' ) . ' e LEFT JOIN ' . Schema::table( 'brands' ) . ' d ON d.id=e.department_id WHERE e.spent_on BETWEEN %s AND %s' . $and( 'e.department_id', 'e.currency' ) . ' ORDER BY e.spent_on', $from, $to ), ARRAY_A ) as $e ) { // phpcs:ignore WordPress.DB
				$rows[] = array( $e['spent_on'], $e['category'], $e['vendor'], $e['description'], $e['dn'], $e['amount'], $e['currency'], $e['method'], $e['reference'] );
			}
		} elseif ( 'team' === $type ) {
			$rows[] = array( 'Earned on', 'Person', 'Type', 'Description', 'Project', 'Amount', 'Currency', 'Status', 'Payout' );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT i.*, m.name mn, pj.name pn, po.number pon FROM ' . Schema::table( 'pay_items' ) . ' i LEFT JOIN ' . Schema::table( 'team_members' ) . ' m ON m.id=i.team_member_id LEFT JOIN ' . Schema::table( 'projects' ) . ' pj ON pj.id=i.project_id LEFT JOIN ' . Schema::table( 'payouts' ) . " po ON po.id=i.payout_id WHERE i.earned_on BETWEEN %s AND %s AND i.status <> 'void'" . $and( 'i.department_id', 'i.currency' ) . ' ORDER BY i.earned_on', $from, $to ), ARRAY_A ) as $i ) { // phpcs:ignore WordPress.DB
				$rows[] = array( $i['earned_on'], $i['mn'], $i['kind'], $i['description'], $i['pn'], $i['amount'], $i['currency'], $i['status'], $i['pon'] );
			}
		} else {
			$rows[] = array( 'Issue date', 'Invoice', 'Client', 'Description', 'Amount (before tax and discount)', 'Our cost', 'Currency', 'Invoice status' );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT i.issue_date, i.invoice_number, i.currency, i.status, c.name, it.description, it.amount, it.cost FROM ' . Schema::table( 'invoice_items' ) . ' it JOIN ' . Schema::table( 'invoices' ) . ' i ON i.id=it.invoice_id LEFT JOIN ' . Schema::table( 'clients' ) . " c ON c.id=i.client_id WHERE i.status NOT IN ('draft','cancelled') AND i.issue_date BETWEEN %s AND %s" . $and( 'it.department_id', 'i.currency' ) . ' ORDER BY i.issue_date, i.id', $from, $to ), ARRAY_A ) as $l ) { // phpcs:ignore WordPress.DB
				$rows[] = array( $l['issue_date'], $l['invoice_number'], $l['name'], $l['description'], $l['amount'], $l['cost'], $l['currency'], $l['status'] );
			}
		}
		$fh = fopen( 'php://temp', 'w+' );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array_map( function ( $v ) {
				$v = (string) $v;
				return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v; // stop spreadsheet formula injection
			}, $r ) );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return array( 'filename' => 'bizydash-' . $type . '-' . $from . '-to-' . $to . '.csv', 'csv' => $csv );
	}
}
