<?php
namespace BizyMoney\Services;

use BizyCore\Services\Activity;

use BizyMoney\Schema;
use BizyMoney\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What the team is owed (pay items) and what has been paid (payouts).
 * Money becomes owed when work is marked done: a phase's allocations, or a task's own pay.
 * Paid items are never edited — payouts are voided and re-made instead.
 */
class Pay {

	/* ───────────────────────── Allocations ───────────────────────── */

	/** Amount an allocation is worth: fixed, or a percentage of the phase's team budget. */
	public static function allocation_amount( array $a, array $phase ) {
		if ( 'percent' === $a['mode'] ) {
			return round( (float) $phase['team_budget'] * (float) $a['value'] / 100, 2 );
		}
		return round( (float) $a['value'], 2 );
	}

	public static function project_of( $project_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::pj() . ' x WHERE x.id=%d', (int) $project_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private static function currency_for_project( $project ) {
		return ( $project && ! empty( $project['currency'] ) ) ? $project['currency'] : Settings::get( 'default_currency' );
	}

	public static function member_department( $member_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT department_id FROM ' . Schema::table( 'team_members' ) . ' WHERE id=%d', (int) $member_id ) ); // phpcs:ignore WordPress.DB
	}

	/** Department of a phase for pay and analytics: the phase's own, else its project's, else none (0). */
	public static function phase_department( $phase_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ph.department_id AS pd, p.department_id AS jd FROM ' . Schema::table( 'project_phases' ) . ' ph LEFT JOIN ' . Schema::table( 'projects' ) . ' p ON p.id=ph.project_id WHERE ph.id=%d', (int) $phase_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return 0;
		}
		return (int) $row['pd'] ? (int) $row['pd'] : (int) $row['jd'];
	}

	/** Department stamped on a new pay item: phase → project → the person's own. */
	public static function item_department( $phase_id, $project_id, $member_id ) {
		$d = (int) $phase_id ? self::phase_department( $phase_id ) : 0;
		if ( ! $d && (int) $project_id ) {
			$p = self::project_of( $project_id );
			$d = $p ? (int) $p['department_id'] : 0;
		}
		return $d ? $d : self::member_department( $member_id );
	}

	/** A phase changed department: still-payable items follow it; paid and voided ones stay as they were. */
	public static function restamp_phase( $phase_id ) {
		global $wpdb;
		$items = Schema::table( 'pay_items' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, team_member_id, project_id, department_id FROM $items WHERE phase_id=%d AND status='payable'", (int) $phase_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$n     = 0;
		foreach ( (array) $rows as $r ) {
			$d = self::item_department( $phase_id, $r['project_id'], $r['team_member_id'] );
			if ( $d !== (int) $r['department_id'] ) {
				$wpdb->update( $items, array( 'department_id' => $d, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $r['id'] ) );
				++$n;
			}
		}
		return $n;
	}

	/** Bring the pay items of a phase in line with its status and allocations (idempotent). */
	public static function sync_phase( $phase_id ) {
		global $wpdb;
		$ph = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::ph() . ' x WHERE x.id=%d', (int) $phase_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $ph ) {
			return array( 'created' => 0, 'updated' => 0, 'voided' => 0, 'amount' => 0 );
		}
		$project = self::project_of( $ph['project_id'] );
		$cur     = self::currency_for_project( $project );
		$items   = Schema::table( 'pay_items' );
		$now     = current_time( 'mysql' );
		$done    = 'done' === $ph['status'];
		$out     = array( 'created' => 0, 'updated' => 0, 'voided' => 0, 'amount' => 0 );

		$allocs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'pay_allocations' ) . " WHERE scope='phase' AND scope_id=%d", $ph['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $allocs as $a ) {
			$amount = self::allocation_amount( $a, $ph );
			$item   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $items WHERE allocation_id=%d AND status <> 'void' ORDER BY id DESC LIMIT 1", $a['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$desc   = $ph['name'] . ( $project ? ' — ' . $project['name'] : '' );
			if ( $done ) {
				if ( ! $item && $amount > 0 ) {
					$dept = self::item_department( $ph['id'], $ph['project_id'], $a['team_member_id'] );
					$wpdb->insert( $items, array( 'team_member_id' => (int) $a['team_member_id'], 'kind' => 'phase', 'project_id' => (int) $ph['project_id'], 'phase_id' => (int) $ph['id'], 'allocation_id' => (int) $a['id'], 'description' => mb_substr( $desc, 0, 255 ), 'amount' => $amount, 'currency' => $cur, 'earned_on' => Billing::today(), 'status' => 'payable', 'department_id' => $dept, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
					$out['created']++;
					$out['amount'] += $amount;
				} elseif ( $item && 'payable' === $item['status'] && abs( (float) $item['amount'] - $amount ) > 0.004 ) {
					$wpdb->update( $items, array( 'amount' => $amount, 'description' => mb_substr( $desc, 0, 255 ), 'updated_at' => $now ), array( 'id' => $item['id'] ) );
					$out['updated']++;
				}
			} elseif ( $item && 'payable' === $item['status'] ) {
				$wpdb->update( $items, array( 'status' => 'void', 'updated_at' => $now ), array( 'id' => $item['id'] ) );
				$out['voided']++;
			}
		}
		if ( $out['created'] ) {
			Activity::log( 'pay_items', 0, $project ? (int) $project['client_id'] : 0, 'earned', sprintf( 'Phase “%s” done — %s added to what the team is owed', $ph['name'], number_format( $out['amount'], 2 ) ) );
		}
		return $out;
	}

	/** A task with its own pay: owed once it is done, voided if reopened (unless already paid). */
	public static function sync_task( array $task ) {
		global $wpdb;
		$items = Schema::table( 'pay_items' );
		$now   = current_time( 'mysql' );
		$item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $items WHERE task_id=%d AND kind='task' AND status <> 'void' ORDER BY id DESC LIMIT 1", $task['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$amount = round( (float) $task['pay_amount'], 2 );
		$ready  = 'done' === $task['status'] && $amount > 0 && (int) $task['assignee_id'] > 0;
		if ( $ready ) {
			$project = (int) $task['project_id'] ? self::project_of( $task['project_id'] ) : null;
			$cur     = self::currency_for_project( $project );
			$dept    = self::item_department( $task['phase_id'], $task['project_id'], $task['assignee_id'] );
			if ( ! $item ) {
				$wpdb->insert( $items, array( 'team_member_id' => (int) $task['assignee_id'], 'kind' => 'task', 'project_id' => (int) $task['project_id'], 'phase_id' => (int) $task['phase_id'], 'task_id' => (int) $task['id'], 'description' => mb_substr( $task['title'], 0, 255 ), 'amount' => $amount, 'currency' => $cur, 'earned_on' => Billing::today(), 'status' => 'payable', 'department_id' => $dept, 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
			} elseif ( 'payable' === $item['status'] ) {
				$wpdb->update( $items, array( 'amount' => $amount, 'team_member_id' => (int) $task['assignee_id'], 'updated_at' => $now ), array( 'id' => $item['id'] ) );
			}
		} elseif ( $item && 'payable' === $item['status'] ) {
			$wpdb->update( $items, array( 'status' => 'void', 'updated_at' => $now ), array( 'id' => $item['id'] ) );
		}
	}

	/* ───────────────────────── Payouts ───────────────────────── */

	public static function next_payout_number() {
		$n = max( 1, (int) get_option( 'bizymoney_payout_next', 1 ) );
		update_option( 'bizymoney_payout_next', $n + 1 );
		return 'PAY-' . str_pad( (string) $n, 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Pay a set of owed items to one person in one payout.
	 *
	 * @return array|\WP_Error The payout row.
	 */
	public static function create_payout( $member_id, array $item_ids, $paid_on, $method, $reference, $notes ) {
		global $wpdb;
		$member_id = (int) $member_id;
		$item_ids  = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );
		if ( ! $member_id || ! $item_ids ) {
			return new \WP_Error( 'bizymoney_invalid', 'Choose who to pay and at least one owed item.', array( 'status' => 400 ) );
		}
		$items = Schema::table( 'pay_items' );
		$in    = implode( ',', $item_ids );
		$rows  = $wpdb->get_results( "SELECT * FROM $items WHERE id IN ($in)", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( count( $rows ) !== count( $item_ids ) ) {
			return new \WP_Error( 'bizymoney_invalid', 'Some of those items no longer exist.', array( 'status' => 400 ) );
		}
		$total = 0.0;
		$cur   = '';
		foreach ( $rows as $r ) {
			if ( (int) $r['team_member_id'] !== $member_id || 'payable' !== $r['status'] ) {
				return new \WP_Error( 'bizymoney_invalid', 'Only unpaid items belonging to that person can be paid.', array( 'status' => 400 ) );
			}
			if ( $cur && $cur !== $r['currency'] ) {
				return new \WP_Error( 'bizymoney_currency', 'Pay one currency at a time.', array( 'status' => 400 ) );
			}
			$cur    = $r['currency'];
			$total += (float) $r['amount'];
		}
		$total = round( $total, 2 );
		if ( $total <= 0 ) {
			return new \WP_Error( 'bizymoney_invalid', 'The total to pay must be more than zero.', array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( Schema::table( 'payouts' ), array( 'number' => self::next_payout_number(), 'team_member_id' => $member_id, 'paid_on' => $paid_on ? $paid_on : Billing::today(), 'method' => sanitize_text_field( $method ), 'reference' => sanitize_text_field( $reference ), 'amount' => $total, 'currency' => $cur, 'notes' => sanitize_textarea_field( $notes ), 'status' => 'paid', 'department_id' => self::member_department( $member_id ), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
		$pid = (int) $wpdb->insert_id;
		if ( ! $pid ) {
			return new \WP_Error( 'bizymoney_db', 'Could not record the payout.', array( 'status' => 500 ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $items SET status='paid', payout_id=%d, updated_at=%s WHERE id IN ($in)", $pid, $now ) ); // phpcs:ignore WordPress.DB
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'payouts' ) . ' WHERE id=%d', $pid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . Schema::table( 'team_members' ) . ' WHERE id=%d', $member_id ) ); // phpcs:ignore WordPress.DB
		Activity::log( 'payouts', $pid, 0, 'paid', sprintf( '%s paid: %s %s to %s (%d item%s)', $row['number'], $cur, number_format( $total, 2 ), $name, count( $rows ), 1 === count( $rows ) ? '' : 's' ) );
		return $row;
	}

	public static function void_payout( $id ) {
		global $wpdb;
		$po  = Schema::table( 'payouts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $po WHERE id=%d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return new \WP_Error( 'bizymoney_not_found', 'Payout not found.', array( 'status' => 404 ) );
		}
		if ( 'void' === $row['status'] ) {
			return $row;
		}
		$now = current_time( 'mysql' );
		$wpdb->update( $po, array( 'status' => 'void', 'updated_at' => $now ), array( 'id' => $row['id'] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'pay_items' ) . " SET status='payable', payout_id=0, updated_at=%s WHERE payout_id=%d", $now, $row['id'] ) ); // phpcs:ignore WordPress.DB
		Activity::log( 'payouts', (int) $row['id'], 0, 'voided', sprintf( '%s voided — the items are owed again', $row['number'] ) );
		$row['status'] = 'void';
		return $row;
	}

	/* ───────────────────────── Project money ───────────────────────── */

	/** Staff-only money picture of a project: price, team budget, allocated, earned, paid, owed, margin. */
	public static function project_money( array $project ) {
		global $wpdb;
		$pid    = (int) $project['id'];
		$phases = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::ph() . ' x WHERE x.project_id=%d', $pid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$budget = 0.0;
		$alloc  = 0.0;
		$by_id  = array();
		foreach ( (array) $phases as $p ) {
			$budget        += (float) $p['team_budget'];
			$by_id[ $p['id'] ] = $p;
		}
		if ( $by_id ) {
			$in = implode( ',', array_map( 'intval', array_keys( $by_id ) ) );
			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'pay_allocations' ) . " WHERE scope='phase' AND scope_id IN ($in)", ARRAY_A ) as $a ) { // phpcs:ignore WordPress.DB
				$alloc += self::allocation_amount( $a, $by_id[ $a['scope_id'] ] );
			}
		}
		$pi   = Schema::table( 'pay_items' );
		$sums = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) earned, COALESCE(SUM(IF(status='paid',amount,0)),0) paid, COALESCE(SUM(IF(status='payable',amount,0)),0) owed FROM $pi WHERE project_id=%d AND status <> 'void'", $pid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$price = (float) $project['price'];
		$cost  = max( $alloc, (float) $sums['earned'] );
		return array(
			'price'     => round( $price, 2 ),
			'budget'    => round( $budget, 2 ),
			'allocated' => round( $alloc, 2 ),
			'earned'    => round( (float) $sums['earned'], 2 ),
			'paid'      => round( (float) $sums['paid'], 2 ),
			'owed'      => round( (float) $sums['owed'], 2 ),
			'margin'    => round( $price - $cost, 2 ),
		);
	}
}
