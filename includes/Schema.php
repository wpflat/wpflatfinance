<?php
namespace BizyMoney;

defined( 'ABSPATH' ) || exit;

/**
 * Table names. BizyMoney's own tables are bizymoney_*; the code still asks for the short old names, and this maps
 * them: money tables to bizymoney_*, Core's to bizy_*, BizyTasks' to bizytasks_* (only when BizyTasks is on).
 */
class Schema {

	/** BizyMoney's own tables (short names). */
	const OWN = array( 'catalog', 'client_services', 'renewals', 'charges', 'invoices', 'invoice_items', 'invoice_installments', 'payments', 'email_log', 'expenses', 'recurring_expenses', 'pay_allocations', 'pay_items', 'payouts', 'client_billing', 'team_pay', 'project_money', 'phase_money', 'task_money' );

	const CORE = array( 'clients' => 'bizy_clients', 'brands' => 'bizy_departments', 'departments' => 'bizy_departments', 'client_brands' => 'bizy_client_departments', 'client_contacts' => 'bizy_client_contacts', 'team_members' => 'bizy_team_members', 'activity' => 'bizy_activity', 'lookups' => 'bizy_lookups', 'threads' => 'bizy_threads', 'messages' => 'bizy_messages' );

	const TASKS = array( 'projects' => 'bizytasks_projects', 'project_phases' => 'bizytasks_phases', 'tasks' => 'bizytasks_tasks' );

	public static function table( $name ) {
		global $wpdb;
		if ( 'services' === $name ) {
			$name = 'catalog';
		}
		if ( in_array( $name, self::OWN, true ) ) {
			return $wpdb->prefix . 'bizymoney_' . $name;
		}
		if ( isset( self::CORE[ $name ] ) ) {
			return $wpdb->prefix . self::CORE[ $name ];
		}
		if ( isset( self::TASKS[ $name ] ) ) {
			return $wpdb->prefix . self::TASKS[ $name ];
		}
		return $wpdb->prefix . $name;
	}

	/** Is BizyTasks installed and on? Its projects, phases and tasks only exist then. */
	public static function tasks_on() {
		return \BizyCore\Registry::enabled( 'tasks' );
	}

	/*
	 * Read-only "tables" that bring the money fields back next to the records they belong to, so a query written
	 * for the single plugin keeps working: FROM ' . Schema::tm() . ' tm … (they need an alias).
	 */

	/** Team members with their pay: salary_amount, salary_period, currency, bank_name, account_name, account_number. */
	public static function tm() {
		return '(SELECT m.*, p.salary_amount, p.salary_period, p.currency, p.bank_name, p.account_name, p.account_number FROM ' . self::table( 'team_members' ) . ' m LEFT JOIN ' . self::table( 'team_pay' ) . ' p ON p.team_member_id=m.id)';
	}

	/** Projects with their price and currency. */
	public static function pj() {
		return '(SELECT x.*, COALESCE(pm.price,0) AS price, COALESCE(pm.currency,\'\') AS currency FROM ' . self::table( 'projects' ) . ' x LEFT JOIN ' . self::table( 'project_money' ) . ' pm ON pm.project_id=x.id)';
	}

	/** Phases with their price and team budget. */
	public static function ph() {
		return '(SELECT x.*, COALESCE(pm.price,0) AS price, COALESCE(pm.team_budget,0) AS team_budget FROM ' . self::table( 'project_phases' ) . ' x LEFT JOIN ' . self::table( 'phase_money' ) . ' pm ON pm.phase_id=x.id)';
	}

	/** Tasks with their pay amount. */
	public static function tk() {
		return '(SELECT x.*, COALESCE(tm.pay_amount,0) AS pay_amount, COALESCE(tm.invoice_id,0) AS invoice_id, COALESCE(tm.client_service_id,0) AS client_service_id FROM ' . self::table( 'tasks' ) . ' x LEFT JOIN ' . self::table( 'task_money' ) . ' tm ON tm.task_id=x.id)';
	}
}
