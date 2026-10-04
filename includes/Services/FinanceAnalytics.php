<?php
namespace BizyMoney\Services;

use BizyMoney\Schema;
use BizyMoney\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Finance → Analytics. Same books as Profit (income, direct costs, team pay, expenses) over a range the user picks,
 * bucketed by day/week/month, filtered by department, and shown in one of three currency views:
 *   all       every currency side by side (nothing converted),
 *   CODE      one currency only,
 *   combined  every amount converted to the base currency at the admin-set rates (an estimate).
 * Combined works by converting each row first and then running the very same aggregation, so the three views cannot drift apart.
 */
class FinanceAnalytics {

	const OPEN = array( 'sent', 'due', 'partially_paid', 'overdue' );

	public static function date_or( $d, $fallback ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? $d : $fallback;
	}

	public static function granularity( $from, $to ) {
		$days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
		return $days <= 45 ? 'day' : ( $days <= 200 ? 'week' : 'month' );
	}

	public static function bucket( $date, $gran ) {
		if ( 'day' === $gran ) {
			return $date;
		}
		if ( 'week' === $gran ) {
			return gmdate( 'Y-m-d', strtotime( 'monday this week', strtotime( $date ) ) );
		}
		return substr( $date, 0, 7 );
	}

	public static function buckets( $from, $to, $gran ) {
		$out = array();
		$t   = strtotime( self::bucket( $from, $gran ) . ( 'month' === $gran ? '-01' : '' ) );
		$end = strtotime( $to );
		while ( $t <= $end ) {
			$out[] = array( 'key' => 'month' === $gran ? gmdate( 'Y-m', $t ) : gmdate( 'Y-m-d', $t ), 'label' => 'month' === $gran ? wp_date( 'M Y', $t ) : wp_date( 'M j', $t ) );
			$t     = strtotime( 'month' === $gran ? '+1 month' : ( 'week' === $gran ? '+7 days' : '+1 day' ), $t );
		}
		return $out;
	}

	/** Convert (combined) or filter (one currency) a list of rows; `all` leaves them untouched. */
	private static function norm( array $rows, array $keys, $view, array &$missing, $cur_key = 'cur' ) {
		if ( 'all' === $view ) {
			return $rows;
		}
		$base = Currency::base();
		$out  = array();
		foreach ( $rows as $r ) {
			if ( 'combined' !== $view ) {
				if ( $r[ $cur_key ] === $view ) {
					$out[] = $r;
				}
				continue;
			}
			if ( $r[ $cur_key ] !== $base ) {
				$rate = Currency::rate( $r[ $cur_key ] );
				if ( null === $rate ) {
					$missing[ $r[ $cur_key ] ] = true;
					continue;
				}
				foreach ( $keys as $k ) {
					$r[ $k ] = (float) $r[ $k ] * $rate;
				}
				$r[ $cur_key ] = $base;
			}
			$out[] = $r;
		}
		return $out;
	}

	/** The same for a per-currency map {CUR:{amount,count}}. */
	private static function norm_map( array $map, $view, array &$missing ) {
		if ( 'all' === $view ) {
			return $map;
		}
		if ( 'combined' === $view ) {
			$c = Currency::combine( $map );
			foreach ( $c['missing'] as $m ) {
				$missing[ $m ] = true;
			}
			return $c['amount'] || $c['count'] ? array( Currency::base() => array( 'amount' => $c['amount'], 'count' => $c['count'] ) ) : array();
		}
		return isset( $map[ $view ] ) ? array( $view => $map[ $view ] ) : array();
	}

	public static function query( array $f ) {
		global $wpdb;
		$today = Billing::today();
		$to    = self::date_or( isset( $f['to'] ) ? $f['to'] : '', $today );
		$from  = self::date_or( isset( $f['from'] ) ? $f['from'] : '', gmdate( 'Y-m-d', strtotime( "$to -89 days" ) ) );
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		$dept = null;
		if ( isset( $f['department_id'] ) && 'none' === $f['department_id'] ) {
			$dept = 0;
		} elseif ( ! empty( $f['department_id'] ) && (int) $f['department_id'] > 0 ) {
			$dept = (int) $f['department_id'];
		}
		$codes = array_map( function ( $c ) {
			return $c['code'];
		}, (array) Settings::get( 'currencies' ) );
		$view  = isset( $f['currency'] ) ? (string) $f['currency'] : 'all';
		$view  = ( 'combined' === $view || in_array( $view, $codes, true ) ) ? $view : 'all';
		$ib    = isset( $f['income_basis'] ) && 'paid' === $f['income_basis'] ? 'paid' : 'invoiced';
		$pb    = isset( $f['pay_basis'] ) && 'paid' === $f['pay_basis'] ? 'paid' : 'earned';
		$gran  = self::granularity( $from, $to );
		$miss  = array();

		$inc_raw = Profit::income_rows( $from, $to, $ib );
		$inc     = self::norm( $inc_raw, array( 'income', 'direct' ), $view, $miss );
		$team    = self::norm( Profit::team_rows( $from, $to, $pb ), array( 'amount' ), $view, $miss );
		$exp     = self::norm( Profit::expense_rows( $from, $to ), array( 'amount' ), $view, $miss );

		$summary = Profit::sum( $inc, $team, $exp, $from, $to, $dept );

		// Series: income, costs and profit per bucket, per currency.
		$bl   = self::buckets( $from, $to, $gran );
		$idx  = array();
		foreach ( $bl as $i => $b ) {
			$idx[ $b['key'] ] = $i;
		}
		$acc = array();
		$add = function ( $cur, $date, $k, $v ) use ( &$acc, $idx, $gran, $bl ) {
			$key = self::bucket( $date, $gran );
			if ( ! isset( $idx[ $key ] ) ) {
				return;
			}
			if ( ! isset( $acc[ $cur ] ) ) {
				$acc[ $cur ] = array_fill_keys( array( 'income', 'direct', 'team', 'expenses' ), array_fill( 0, count( $bl ), 0.0 ) );
			}
			$acc[ $cur ][ $k ][ $idx[ $key ] ] += $v;
		};
		$okd = function ( $r ) use ( $dept ) {
			return null === $dept || (int) $r['dept'] === (int) $dept;
		};
		foreach ( $inc as $r ) {
			if ( $okd( $r ) ) {
				$add( $r['cur'], $r['date'], 'income', $r['income'] );
				$add( $r['cur'], $r['date'], 'direct', $r['direct'] );
			}
		}
		foreach ( $team as $r ) {
			if ( $okd( $r ) ) {
				$add( $r['cur'], $r['date'], 'team', $r['amount'] );
			}
		}
		foreach ( $exp as $r ) {
			if ( $okd( $r ) ) {
				$add( $r['cur'], $r['date'], 'expenses', $r['amount'] );
			}
		}
		$series = array();
		foreach ( $acc as $cur => $a ) {
			$series[ $cur ] = array( 'income' => array(), 'costs' => array(), 'profit' => array() );
			foreach ( $bl as $i => $b ) {
				$costs                        = $a['direct'][ $i ] + $a['team'][ $i ] + $a['expenses'][ $i ];
				$series[ $cur ]['income'][]   = round( $a['income'][ $i ], 2 );
				$series[ $cur ]['costs'][]    = round( $costs, 2 );
				$series[ $cur ]['profit'][]   = round( $a['income'][ $i ] - $costs, 2 );
			}
		}

		// By department: every department side by side (not narrowed by the department filter).
		$names = array( 0 => 'General / no department' );
		foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'brands' ) . ' ORDER BY name', ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB
			$names[ (int) $b['id'] ] = $b['name'];
		}
		$departments = array();
		foreach ( $names as $id => $name ) {
			$s = Profit::sum( $inc, $team, $exp, $from, $to, $id );
			if ( $s ) {
				$departments[] = array( 'id' => $id, 'name' => $name, 'values' => $s );
			}
		}

		// Best clients (what we keep), narrowed by department.
		$clients = array();
		foreach ( $inc as $r ) {
			if ( $r['date'] >= $from && $r['date'] <= $to && $okd( $r ) ) {
				$k = $r['client'] . '|' . $r['cur'];
				if ( ! isset( $clients[ $k ] ) ) {
					$clients[ $k ] = array( 'id' => $r['client'], 'currency' => $r['cur'], 'income' => 0.0, 'direct' => 0.0 );
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
			$client_rows[] = array( 'id' => $c['id'], 'name' => isset( $cname[ $c['id'] ] ) ? $cname[ $c['id'] ] : 'Unknown', 'currency' => $c['currency'], 'income' => round( $c['income'], 2 ), 'keep' => round( $c['income'] - $c['direct'], 2 ) );
		}
		usort( $client_rows, function ( $a, $b ) {
			return $b['keep'] <=> $a['keep'];
		} );

		// Expenses by category.
		$cats = array();
		foreach ( $exp as $r ) {
			if ( $okd( $r ) ) {
				$cats[ $r['category'] ][ $r['cur'] ] = ( isset( $cats[ $r['category'] ][ $r['cur'] ] ) ? $cats[ $r['category'] ][ $r['cur'] ] : 0 ) + $r['amount'];
			}
		}
		$cat_rows = array();
		foreach ( $cats as $cat => $vals ) {
			$vals       = array_map( function ( $v ) {
				return round( $v, 2 );
			}, $vals );
			$cat_rows[] = array( 'category' => $cat, 'values' => $vals, 'sort' => array_sum( $vals ) );
		}
		usort( $cat_rows, function ( $a, $b ) {
			return $b['sort'] <=> $a['sort'];
		} );

		// Projects: price vs team pay. A project belongs to a department through itself or any of its phases.
		$project_rows = array();
		$pj_miss      = array();
		foreach ( (array) $wpdb->get_results( 'SELECT p.*, c.name FROM ' . Schema::pj() . ' p LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=p.client_id WHERE p.price > 0 ORDER BY p.id DESC LIMIT 50', ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB
			if ( null !== $dept ) {
				$in_phase = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'project_phases' ) . ' WHERE project_id=%d AND department_id=%d', $p['id'], $dept ) ); // phpcs:ignore WordPress.DB
				if ( (int) $p['department_id'] !== (int) $dept && ! $in_phase ) {
					continue;
				}
			}
			$m   = Pay::project_money( $p );
			$cur = $p['currency'] ? $p['currency'] : Settings::get( 'default_currency' );
			$project_rows[] = array( 'id' => (int) $p['id'], 'name' => $p['name'], 'client_name' => $p['name'], 'cur' => $cur, 'price' => $m['price'], 'team_cost' => max( $m['allocated'], $m['earned'] ), 'margin' => $m['margin'] );
		}
		$project_rows = array_map( function ( $r ) {
			$r['currency'] = $r['cur'];
			unset( $r['cur'] );
			return $r;
		}, self::norm( array_map( function ( $r ) {
			return $r;
		}, $project_rows ), array( 'price', 'team_cost', 'margin' ), $view, $pj_miss ) );

		// Receivables ageing (as of today; invoices carry no department).
		$ag = array();
		foreach ( (array) $wpdb->get_results( 'SELECT currency cur, due_date, (amount - amount_paid) bal FROM ' . Schema::table( 'invoices' ) . " WHERE status IN ('" . implode( "','", self::OPEN ) . "')", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$late = $r['due_date'] ? (int) floor( ( strtotime( $today ) - strtotime( $r['due_date'] ) ) / DAY_IN_SECONDS ) : 0;
			$ag[] = array( 'cur' => $r['cur'], 'bucket' => $late <= 0 ? 'current' : ( $late <= 30 ? 'd30' : ( $late <= 60 ? 'd60' : 'd61' ) ), 'amount' => (float) $r['bal'] );
		}
		$ageing = array();
		foreach ( self::norm( $ag, array( 'amount' ), $view, $miss ) as $r ) {
			if ( ! isset( $ageing[ $r['cur'] ] ) ) {
				$ageing[ $r['cur'] ] = array( 'current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd61' => 0.0 );
			}
			$ageing[ $r['cur'] ][ $r['bucket'] ] += $r['amount'];
		}
		foreach ( $ageing as $c => $b ) {
			$ageing[ $c ] = array_map( function ( $v ) {
				return round( $v, 2 );
			}, $b );
		}

		// Recurring income vs costs per month.
		$rec_in  = Subscriptions::mrr_totals( null );
		$rec_out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT amount, currency, period FROM ' . Schema::table( 'recurring_expenses' ) . " WHERE status='active'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$rec_out[ $r['currency'] ] = array( 'amount' => round( ( isset( $rec_out[ $r['currency'] ] ) ? $rec_out[ $r['currency'] ]['amount'] : 0 ) + Expenses::monthly_value( $r['amount'], $r['period'] ), 2 ), 'count' => 1 );
		}
		foreach ( (array) $wpdb->get_results( 'SELECT salary_amount, currency FROM ' . Schema::tm() . " tm WHERE status='active' AND salary_amount > 0", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$cur             = $r['currency'] ? $r['currency'] : Settings::get( 'default_currency' );
			$rec_out[ $cur ] = array( 'amount' => round( ( isset( $rec_out[ $cur ] ) ? $rec_out[ $cur ]['amount'] : 0 ) + (float) $r['salary_amount'], 2 ), 'count' => 1 );
		}
		$recurring = array( 'income' => self::norm_map( $rec_in, $view, $miss ), 'costs' => self::norm_map( $rec_out, $view, $miss ) );

		// Currency mix: share of income by currency (in the base currency when every rate is known).
		$mix_raw = array();
		foreach ( $inc_raw as $r ) {
			if ( null === $dept || (int) $r['dept'] === (int) $dept ) {
				$mix_raw[ $r['cur'] ] = ( isset( $mix_raw[ $r['cur'] ] ) ? $mix_raw[ $r['cur'] ] : 0 ) + $r['income'];
			}
		}
		$mix   = array();
		$total = 0.0;
		$ok    = true;
		foreach ( $mix_raw as $c => $a ) {
			$conv = Currency::convert( $a, $c );
			$ok   = $ok && null !== $conv;
			$total += (float) $conv;
		}
		foreach ( $mix_raw as $c => $a ) {
			$conv  = Currency::convert( $a, $c );
			$mix[] = array( 'currency' => $c, 'amount' => round( $a, 2 ), 'share_pct' => $ok && $total > 0 ? round( 100 * $conv / $total, 1 ) : null );
		}

		$present = array_keys( $summary + $series + $ageing );
		$missing = array_keys( $miss + $pj_miss );
		return array(
			'range'       => array( 'from' => $from, 'to' => $to ),
			'granularity' => $gran,
			'view'        => $view,
			'department'  => $dept,
			'bases'       => array( 'income' => $ib, 'pay' => $pb ),
			'base'        => Currency::base(),
			'rates'       => (array) Settings::get( 'rates' ),
			'missing'     => $missing,
			'currencies'  => array_values( $present ),
			'summary'     => $summary,
			'buckets'     => wp_list_pluck( $bl, 'label' ),
			'series'      => $series,
			'departments' => $departments,
			'clients'     => array_slice( $client_rows, 0, 10 ),
			'projects'    => $project_rows,
			'categories'  => array_slice( $cat_rows, 0, 12 ),
			'ageing'      => $ageing,
			'recurring'   => $recurring,
			'mix'         => $mix,
		);
	}
}
