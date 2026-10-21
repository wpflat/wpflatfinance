<?php
namespace WPFlatFinance;

defined( 'ABSPATH' ) || exit;

/**
 * Table names. WPFlat Finance's own tables are wpflatfinance_*; the code still asks for the short old names, and this maps
 * them: money tables to wpflatfinance_*, Core's to wpflat_*, WPFlat Tasks' to wpflattasks_* (only when WPFlat Tasks is on).
 */
class Schema {

	/** WPFlat Finance's own tables (short names). */
	const OWN = array( 'catalog', 'client_services', 'renewals', 'charges', 'invoices', 'invoice_items', 'invoice_installments', 'payments', 'email_log', 'expenses', 'recurring_expenses', 'pay_allocations', 'pay_items', 'payouts', 'client_billing', 'team_pay', 'project_money', 'phase_money', 'task_money' );

	const CORE = array( 'clients' => 'wpflat_clients', 'brands' => 'wpflat_departments', 'departments' => 'wpflat_departments', 'client_brands' => 'wpflat_client_departments', 'client_contacts' => 'wpflat_client_contacts', 'team_members' => 'wpflat_team_members', 'activity' => 'wpflat_activity', 'lookups' => 'wpflat_lookups', 'threads' => 'wpflat_threads', 'messages' => 'wpflat_messages' );

	const TASKS = array( 'projects' => 'wpflattasks_projects', 'project_phases' => 'wpflattasks_phases', 'tasks' => 'wpflattasks_tasks' );

	public static function table( $name ) {
		global $wpdb;
		if ( 'services' === $name ) {
			$name = 'catalog';
		}
		if ( in_array( $name, self::OWN, true ) ) {
			return $wpdb->prefix . 'wpflatfinance_' . $name;
		}
		if ( isset( self::CORE[ $name ] ) ) {
			return $wpdb->prefix . self::CORE[ $name ];
		}
		if ( isset( self::TASKS[ $name ] ) ) {
			return $wpdb->prefix . self::TASKS[ $name ];
		}
		return $wpdb->prefix . $name;
	}

	/** Is WPFlat Tasks installed and on? Its projects, phases and tasks only exist then. */
	public static function tasks_on() {
		return \WPFlatCore\Registry::enabled( 'tasks' );
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
