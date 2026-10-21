<?php
namespace WPFlatFinance;

use WPFlatCore\Links;
use WPFlatCore\Registry;
use WPFlatFinance\Services\Pay;

defined( 'ABSPATH' ) || exit;

/**
 * What WPFlat Finance adds to the records of WPFlat Core and WPFlat Tasks: a client's billing currency, a team member's pay, a
 * project's price, a phase's budget, a task's pay. It keeps those fields in its own tables and shows them (to people
 * with the finance capability) on the other plugins' rows, so those plugins stay money-free.
 */
class Integrations {

	public static function init() {
		add_filter( 'wpflatcore_decorate_clients', array( __CLASS__, 'clients' ) );
		add_action( 'wpflatcore_saved_clients', array( __CLASS__, 'client_saved' ), 10, 3 );
		add_action( 'wpflatcore_client_deleted', array( __CLASS__, 'client_deleted' ) );
		add_filter( 'wpflatcore_decorate_team', array( __CLASS__, 'team' ) );
		add_action( 'wpflatcore_saved_team', array( __CLASS__, 'team_saved' ), 10, 3 );
		add_filter( 'wpflatcore_decorate_projects', array( __CLASS__, 'projects' ) );
		add_action( 'wpflatcore_saved_projects', array( __CLASS__, 'project_saved' ), 10, 3 );
		add_filter( 'wpflatcore_decorate_project-phases', array( __CLASS__, 'phases' ) );
		add_action( 'wpflatcore_saved_project-phases', array( __CLASS__, 'phase_saved' ), 10, 3 );
		add_filter( 'wpflatcore_before_delete_project-phases', array( __CLASS__, 'phase_before_delete' ), 10, 2 );
		add_action( 'wpflatcore_deleted_project-phases', array( __CLASS__, 'phase_deleted' ) );
		add_action( 'wpflatcore_deleted_projects', array( __CLASS__, 'project_deleted' ) );
		add_action( 'wpflatcore_deleted_team', array( __CLASS__, 'team_deleted' ) );
		add_action( 'wpflatcore_deleted_tasks', array( __CLASS__, 'task_deleted' ) );
		add_filter( 'wpflatcore_decorate_tasks', array( __CLASS__, 'tasks' ) );
		add_action( 'wpflatcore_saved_tasks', array( __CLASS__, 'task_saved' ), 10, 3 );
		add_filter( 'wpflatcore_search', array( __CLASS__, 'search' ), 10, 2 );
		add_filter( 'wpflatcore_message_contexts', array( __CLASS__, 'message_contexts' ), 10, 2 );
		add_filter( 'wpflatcore_message_context_name', array( __CLASS__, 'message_context_name' ), 10, 3 );
		add_filter( 'wpflatcore_home', array( __CLASS__, 'home' ) );
		add_filter( 'wpflatcore_meta', array( __CLASS__, 'meta' ) );
		add_filter( 'wpflatcore_department_card', array( __CLASS__, 'department_card' ), 10, 4 );
		add_filter( 'wpflatcore_department_detail', array( __CLASS__, 'department_detail' ), 10, 4 );
	}

	private static function fin() {
		return current_user_can( 'manage_options' ) || current_user_can( 'wpflatfinance_finance' );
	}

	private static function on() {
		return Registry::enabled( 'finance' );
	}

	private static function upsert( $table, $key, $id, array $data ) {
		global $wpdb;
		$tbl = Schema::table( $table );
		$id  = (int) $id;
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT $key FROM $tbl WHERE $key=%d", $id ) ) ) { // phpcs:ignore WordPress.DB
			$wpdb->update( $tbl, $data, array( $key => $id ) );
		} else {
			$wpdb->insert( $tbl, array( $key => $id ) + $data );
		}
	}

	private static function in( array $rows ) {
		return implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
	}

	/* ---- clients: billing currency and retainer ---- */

	public static function clients( $rows ) {
		global $wpdb;
		if ( ! self::on() || ! $rows ) {
			return $rows;
		}
		$by = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'client_billing' ) . ' WHERE client_id IN (' . self::in( $rows ) . ')', ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB
			$by[ (int) $b['client_id'] ] = $b;
		}
		$def = Settings::get( 'default_currency' );
		foreach ( $rows as &$r ) {
			$b                   = isset( $by[ (int) $r['id'] ] ) ? $by[ (int) $r['id'] ] : array();
			$r['currency']       = ! empty( $b['currency'] ) ? $b['currency'] : $def;
			$r['retainer_amount'] = isset( $b['retainer_amount'] ) ? (float) $b['retainer_amount'] : 0;
			$r['retainer_period'] = isset( $b['retainer_period'] ) ? $b['retainer_period'] : '';
		}
		return $rows;
	}

	public static function client_saved( $row, $existing, $params ) {
		if ( ! self::on() ) {
			return;
		}
		$data = array();
		if ( isset( $params['currency'] ) ) {
			$c               = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $params['currency'] ), 0, 3 ) );
			$data['currency'] = '' !== $c ? $c : Settings::get( 'default_currency' );
		} elseif ( ! $existing ) {
			$data['currency'] = Settings::get( 'default_currency' );
		}
		if ( isset( $params['retainer_amount'] ) ) {
			$data['retainer_amount'] = round( (float) str_replace( ',', '', (string) $params['retainer_amount'] ), 2 );
		}
		if ( isset( $params['retainer_period'] ) ) {
			$data['retainer_period'] = sanitize_key( $params['retainer_period'] );
		}
		if ( $data ) {
			self::upsert( 'client_billing', 'client_id', $row['id'], $data );
		}
	}

	public static function client_deleted( $client_id ) {
		global $wpdb;
		$wpdb->delete( Schema::table( 'client_billing' ), array( 'client_id' => (int) $client_id ) );
	}

	/* ---- team: pay ---- */

	const PAY_FIELDS = array( 'salary_amount', 'salary_period', 'currency', 'bank_name', 'account_name', 'account_number' );

	public static function team( $rows ) {
		global $wpdb;
		if ( ! self::on() || ! $rows || ! self::fin() ) {
			return $rows;
		}
		$in    = self::in( $rows );
		$today = current_time( 'Y-m-d' );
		$pay   = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'team_pay' ) . " WHERE team_member_id IN ($in)", ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB
			$pay[ (int) $p['team_member_id'] ] = $p;
		}
		$owed = array();
		$paid_year = array();
		$paid_all  = array();
		foreach ( (array) $wpdb->get_results( 'SELECT team_member_id m, currency c, SUM(amount) total, COUNT(*) n FROM ' . Schema::table( 'pay_items' ) . " WHERE status='payable' AND team_member_id IN ($in) GROUP BY team_member_id, currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$owed[ (int) $r['m'] ][ $r['c'] ] = array( 'amount' => round( (float) $r['total'], 2 ), 'count' => (int) $r['n'] );
		}
		$y0 = gmdate( 'Y-01-01', strtotime( $today ) );
		foreach ( (array) $wpdb->get_results( 'SELECT team_member_id m, currency c, SUM(amount) total, SUM(IF(paid_on >= \'' . $y0 . '\', amount, 0)) ytd FROM ' . Schema::table( 'payouts' ) . " WHERE status='paid' AND team_member_id IN ($in) GROUP BY team_member_id, currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$paid_all[ (int) $r['m'] ][ $r['c'] ]  = array( 'amount' => round( (float) $r['total'], 2 ), 'count' => 1 );
			$paid_year[ (int) $r['m'] ][ $r['c'] ] = array( 'amount' => round( (float) $r['ytd'], 2 ), 'count' => 1 );
		}
		$def = Settings::get( 'default_currency' );
		foreach ( $rows as &$r ) {
			$id = (int) $r['id'];
			$p  = isset( $pay[ $id ] ) ? $pay[ $id ] : array();
			foreach ( self::PAY_FIELDS as $f ) {
				$r[ $f ] = isset( $p[ $f ] ) ? $p[ $f ] : ( 'salary_amount' === $f ? 0 : ( 'currency' === $f ? $def : ( 'salary_period' === $f ? 'monthly' : '' ) ) );
			}
			$r['salary_amount'] = (float) $r['salary_amount'];
			$r['owed']          = isset( $owed[ $id ] ) ? $owed[ $id ] : array();
			$r['paid_year']     = isset( $paid_year[ $id ] ) ? $paid_year[ $id ] : array();
			$r['paid_all']      = isset( $paid_all[ $id ] ) ? $paid_all[ $id ] : array();
		}
		return $rows;
	}

	public static function team_saved( $row, $existing, $params ) {
		if ( ! self::on() || ! self::fin() ) {
			return;
		}
		$data = array();
		if ( isset( $params['salary_amount'] ) ) {
			$data['salary_amount'] = round( (float) str_replace( ',', '', (string) $params['salary_amount'] ), 2 );
		}
		foreach ( array( 'salary_period', 'bank_name', 'account_name', 'account_number' ) as $f ) {
			if ( isset( $params[ $f ] ) ) {
				$data[ $f ] = sanitize_text_field( (string) $params[ $f ] );
			}
		}
		if ( isset( $params['currency'] ) ) {
			$data['currency'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $params['currency'] ), 0, 3 ) );
		}
		if ( $data ) {
			self::upsert( 'team_pay', 'team_member_id', $row['id'], $data );
		}
	}

	/* ---- WPFlat Tasks: a project's price, a phase's budget, a task's pay ---- */

	public static function projects( $rows ) {
		global $wpdb;
		if ( ! self::on() || ! $rows || ! self::fin() ) {
			return $rows;
		}
		$by = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'project_money' ) . ' WHERE project_id IN (' . self::in( $rows ) . ')', ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB
			$by[ (int) $m['project_id'] ] = $m;
		}
		foreach ( $rows as &$r ) {
			$r['price']    = isset( $by[ (int) $r['id'] ] ) ? (float) $by[ (int) $r['id'] ]['price'] : 0;
			$r['currency'] = isset( $by[ (int) $r['id'] ] ) ? $by[ (int) $r['id'] ]['currency'] : '';
			$r['finance']    = Pay::project_money( $r );
		}
		return $rows;
	}

	public static function project_saved( $row, $existing, $params ) {
		if ( ! self::on() || ! self::fin() || ( ! isset( $params['price'] ) && ! isset( $params['currency'] ) ) ) {
			return;
		}
		$data = array();
		if ( isset( $params['price'] ) ) {
			$data['price'] = round( (float) str_replace( ',', '', (string) $params['price'] ), 2 );
		}
		if ( isset( $params['currency'] ) ) {
			$data['currency'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $params['currency'] ), 0, 3 ) );
		}
		self::upsert( 'project_money', 'project_id', $row['id'], $data );
	}

	public static function phases( $rows ) {
		global $wpdb;
		if ( ! self::on() || ! $rows || ! self::fin() ) {
			return $rows;
		}
		$in = self::in( $rows );
		$m  = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'phase_money' ) . " WHERE phase_id IN ($in)", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB
			$m[ (int) $x['phase_id'] ] = $x;
		}
		$by = array();
		foreach ( (array) $wpdb->get_results( 'SELECT a.*, mm.name AS member_name FROM ' . Schema::table( 'pay_allocations' ) . ' a LEFT JOIN ' . Schema::table( 'team_members' ) . " mm ON mm.id=a.team_member_id WHERE a.scope='phase' AND a.scope_id IN ($in) ORDER BY a.id", ARRAY_A ) as $a ) { // phpcs:ignore WordPress.DB
			$by[ (int) $a['scope_id'] ][] = $a;
		}
		foreach ( $rows as &$r ) {
			$r['price']       = isset( $m[ (int) $r['id'] ] ) ? (float) $m[ (int) $r['id'] ]['price'] : 0;
			$r['team_budget'] = isset( $m[ (int) $r['id'] ] ) ? (float) $m[ (int) $r['id'] ]['team_budget'] : 0;
			$alloc = 0.0;
			$list  = array();
			foreach ( isset( $by[ (int) $r['id'] ] ) ? $by[ (int) $r['id'] ] : array() as $a ) {
				$amt    = Pay::allocation_amount( $a, $r );
				$alloc += $amt;
				$list[] = array( 'id' => (int) $a['id'], 'team_member_id' => (int) $a['team_member_id'], 'member_name' => $a['member_name'], 'mode' => $a['mode'], 'value' => (float) $a['value'], 'amount' => $amt );
			}
			$r['allocations'] = $list;
			$r['allocated']   = round( $alloc, 2 );
			$r['over_budget'] = (float) $r['team_budget'] > 0 && $alloc > (float) $r['team_budget'] + 0.004;
		}
		return $rows;
	}

	public static function phase_saved( $row, $existing, $params ) {
		if ( ! self::on() ) {
			return;
		}
		if ( self::fin() && ( isset( $params['price'] ) || isset( $params['team_budget'] ) ) ) {
			$data = array();
			foreach ( array( 'price', 'team_budget' ) as $f ) {
				if ( isset( $params[ $f ] ) ) {
					$data[ $f ] = round( (float) str_replace( ',', '', (string) $params[ $f ] ), 2 );
				}
			}
			self::upsert( 'phase_money', 'phase_id', $row['id'], $data );
		}
		if ( $existing ) {
			Pay::restamp_phase( $row['id'] ); // a phase may have moved department
		}
		Pay::sync_phase( $row['id'] );
	}

	public static function phase_before_delete( $ok, $row ) {
		global $wpdb;
		if ( ! self::on() ) {
			return $ok;
		}
		$paid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'pay_items' ) . " WHERE phase_id=%d AND status='paid'", $row['id'] ) ); // phpcs:ignore WordPress.DB
		return $paid ? new \WP_Error( 'wpflatfinance_blocked', 'This phase has paid team members. Void the payouts first, or keep the phase.', array( 'status' => 409 ) ) : $ok;
	}

	public static function phase_deleted( $row ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'pay_items' ) . " SET status='void' WHERE phase_id=%d AND status='payable'", $row['id'] ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( Schema::table( 'pay_allocations' ), array( 'scope' => 'phase', 'scope_id' => (int) $row['id'] ) );
		$wpdb->delete( Schema::table( 'phase_money' ), array( 'phase_id' => (int) $row['id'] ) );
	}

	/** WPFlat Tasks removes the project's phases itself, so sweep what belonged to them here. */
	public static function project_deleted( $row ) {
		global $wpdb;
		$pid = (int) $row['id'];
		$wpdb->delete( Schema::table( 'project_money' ), array( 'project_id' => $pid ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'pay_items' ) . " SET status='void' WHERE project_id=%d AND status='payable'", $pid ) ); // phpcs:ignore WordPress.DB
		$ph = Schema::table( 'project_phases' );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'phase_money' ) . " WHERE phase_id NOT IN (SELECT id FROM $ph)" ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'pay_allocations' ) . " WHERE scope='phase' AND scope_id NOT IN (SELECT id FROM $ph)" ); // phpcs:ignore WordPress.DB
	}

	public static function team_deleted( $row ) {
		global $wpdb;
		$wpdb->delete( Schema::table( 'team_pay' ), array( 'team_member_id' => (int) $row['id'] ) );
		$wpdb->delete( Schema::table( 'pay_allocations' ), array( 'team_member_id' => (int) $row['id'] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'pay_items' ) . " SET status='void' WHERE team_member_id=%d AND status='payable'", (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
	}

	public static function task_deleted( $row ) {
		global $wpdb;
		$wpdb->delete( Schema::table( 'task_money' ), array( 'task_id' => (int) $row['id'] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'pay_items' ) . " SET status='void' WHERE task_id=%d AND status='payable'", (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
	}

	public static function tasks( $rows ) {
		global $wpdb;
		if ( ! self::on() || ! $rows || ! self::fin() ) {
			return $rows;
		}
		$by = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'task_money' ) . ' WHERE task_id IN (' . self::in( $rows ) . ')', ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB
			$by[ (int) $m['task_id'] ] = $m;
		}
		foreach ( $rows as &$r ) {
			$r['pay_amount'] = isset( $by[ (int) $r['id'] ] ) ? (float) $by[ (int) $r['id'] ]['pay_amount'] : 0;
		}
		return $rows;
	}

	public static function task_saved( $row, $existing, $params ) {
		if ( ! self::on() ) {
			return;
		}
		if ( self::fin() && isset( $params['pay_amount'] ) ) {
			self::upsert( 'task_money', 'task_id', $row['id'], array( 'pay_amount' => round( (float) str_replace( ',', '', (string) $params['pay_amount'] ), 2 ) ) );
		}
		global $wpdb;
		$row['pay_amount'] = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT pay_amount FROM ' . Schema::table( 'task_money' ) . ' WHERE task_id=%d', $row['id'] ) ); // phpcs:ignore WordPress.DB
		Pay::sync_task( $row );
	}

	/* ---- link types ---- */

	public static function link_types() {
		Links::register_type( 'invoice', array( 'module' => 'finance', 'label' => 'Invoice', 'plural' => 'Invoices', 'resolve' => array( __CLASS__, 'resolve_invoice' ), 'search' => array( __CLASS__, 'search_invoices' ) ) );
		Links::register_type( 'catalog_item', array( 'module' => 'finance', 'label' => 'Catalog item', 'plural' => 'Catalog', 'resolve' => array( __CLASS__, 'resolve_catalog' ), 'search' => array( __CLASS__, 'search_catalog' ) ) );
	}

	public static function resolve_invoice( $id ) {
		global $wpdb;
		$i = $wpdb->get_row( $wpdb->prepare( 'SELECT id, invoice_number, title, status FROM ' . Schema::table( 'invoices' ) . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $i ? array( 'title' => $i['invoice_number'] . ( $i['title'] ? ' — ' . $i['title'] : '' ), 'url' => '#/billing/invoices/' . (int) $id, 'sub' => ucfirst( str_replace( '_', ' ', $i['status'] ) ) ) : null;
	}

	public static function search_invoices( $q, $n ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, invoice_number, title, status FROM ' . Schema::table( 'invoices' ) . ' WHERE invoice_number LIKE %s OR title LIKE %s ORDER BY id DESC LIMIT %d', $like, $like, $n ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( function ( $r ) {
			return array( 'id' => (int) $r['id'], 'title' => $r['invoice_number'] . ( $r['title'] ? ' — ' . $r['title'] : '' ), 'sub' => $r['status'] );
		}, (array) $rows );
	}

	public static function resolve_catalog( $id ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, type FROM ' . Schema::table( 'catalog' ) . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $c ? array( 'title' => $c['name'], 'url' => '#/catalog', 'sub' => ucfirst( $c['type'] ) ) : null;
	}

	public static function search_catalog( $q, $n ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, type FROM ' . Schema::table( 'catalog' ) . ' WHERE name LIKE %s ORDER BY name LIMIT %d', '%' . $wpdb->esc_like( $q ) . '%', $n ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( function ( $r ) {
			return array( 'id' => (int) $r['id'], 'title' => $r['name'], 'sub' => $r['type'] );
		}, (array) $rows );
	}


	/* ---- the browser app: WPFlat Finance's settings, and money on the department pages ---- */

	/** WPFlat Finance's settings reach the browser app in meta.settings: the safe ones for everybody, the full set for people who bill. */
	public static function meta( $m ) {
		if ( ! self::on() ) {
			return $m;
		}
		$own = Settings::get();
		$pub = array();
		foreach ( array( 'currencies', 'default_currency', 'base_currency', 'tax_label' ) as $k ) {
			$pub[ $k ] = $own[ $k ];
		}
		if ( current_user_can( 'wpflatfinance_billing' ) || current_user_can( 'manage_options' ) ) {
			$pub = array_intersect_key( $own, Settings::defaults() );
		}
		$m['settings'] = array_merge( isset( $m['settings'] ) ? (array) $m['settings'] : array(), $pub );
		return $m;
	}

	private static function dept_money( $id, $from, $to ) {
		global $wpdb;
		$from = $from ? $from : gmdate( 'Y-01-01' );
		$to   = $to ? $to : gmdate( 'Y-m-d' );
		$sum  = Services\Profit::sum( Services\Profit::income_rows( $from, $to, 'invoiced' ), Services\Profit::team_rows( $from, $to, 'earned' ), Services\Profit::expense_rows( $from, $to ), $from, $to, (int) $id );
		$out  = array( 'income' => array(), 'profit' => array(), 'receivable' => array() );
		foreach ( $sum as $cur => $b ) {
			$out['income'][ $cur ] = array( 'amount' => $b['income'], 'count' => 1 );
			$out['profit'][ $cur ] = array( 'amount' => $b['profit'], 'count' => 1 );
		}
		$inv = Schema::table( 'invoices' );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT currency, SUM(amount-amount_paid) b, COUNT(*) n FROM $inv WHERE status IN ('sent','due','partially_paid','overdue') AND department_id=%d GROUP BY currency", (int) $id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out['receivable'][ $r['currency'] ] = array( 'amount' => round( (float) $r['b'], 2 ), 'count' => (int) $r['n'] );
		}
		return $out;
	}

	public static function department_card( $card, $id, $from, $to ) {
		if ( self::on() && self::fin() ) {
			$card['modules']['finance'] = self::dept_money( $id, $from, $to );
		}
		return $card;
	}

	public static function department_detail( $data, $id, $from, $to ) {
		if ( self::on() && self::fin() ) {
			$data['modules']['finance'] = self::dept_money( $id, $from, $to );
		}
		return $data;
	}

	/* ---- search, messages, Home ---- */

	public static function search( $out, $q ) {
		global $wpdb;
		if ( ! self::on() || ( ! current_user_can( 'wpflatfinance_billing' ) && ! current_user_can( 'manage_options' ) ) ) {
			return $out;
		}
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT i.id, i.invoice_number, i.title, c.name AS client_name FROM ' . Schema::table( 'invoices' ) . ' i LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=i.client_id WHERE i.invoice_number LIKE %s OR i.title LIKE %s ORDER BY i.id DESC LIMIT 6', $like, $like ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = array( 'type' => 'invoice', 'kind' => \WPFlatCore\Terminology::label( 'invoice' ), 'id' => (int) $r['id'], 'label' => $r['invoice_number'], 'sub' => trim( $r['title'] . ' · ' . $r['client_name'], ' ·' ), 'route' => '/billing/invoices/' . (int) $r['id'] );
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, type FROM ' . Schema::table( 'catalog' ) . ' WHERE name LIKE %s ORDER BY name LIMIT 5', $like ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[] = array( 'type' => 'catalog', 'kind' => \WPFlatCore\Terminology::label( 'catalog' ), 'id' => (int) $r['id'], 'label' => $r['name'], 'sub' => $r['type'], 'route' => '/catalog' );
		}
		return $out;
	}

	public static function message_contexts( $list, $client_id ) {
		global $wpdb;
		if ( ! self::on() ) {
			return $list;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, invoice_number, title FROM ' . Schema::table( 'invoices' ) . " WHERE client_id=%d AND status NOT IN ('draft','cancelled') ORDER BY id DESC LIMIT 30", (int) $client_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$list[] = array( 'type' => 'invoice', 'id' => (int) $r['id'], 'name' => $r['invoice_number'] . ( $r['title'] ? ' — ' . $r['title'] : '' ), 'group' => 'Invoices' );
		}
		return $list;
	}

	public static function message_context_name( $name, $type, $id ) {
		global $wpdb;
		if ( 'invoice' !== $type || ! self::on() ) {
			return $name;
		}
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_number FROM ' . Schema::table( 'invoices' ) . ' WHERE id=%d', $id ) ); // phpcs:ignore WordPress.DB
	}

	/** Home: money owed to the business, what to invoice, and overdue invoices to chase. */
	public static function home( $home ) {
		global $wpdb;
		if ( ! self::on() || ( ! current_user_can( 'wpflatfinance_billing' ) && ! current_user_can( 'manage_options' ) ) ) {
			return $home;
		}
		$inv    = Schema::table( 'invoices' );
		$today  = current_time( 'Y-m-d' );
		$owed   = array();
		foreach ( (array) $wpdb->get_results( "SELECT currency, SUM(amount-amount_paid) b, COUNT(*) n FROM $inv WHERE status IN ('sent','due','partially_paid','overdue') GROUP BY currency", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$owed[ $r['currency'] ] = array( 'amount' => round( (float) $r['b'], 2 ), 'count' => (int) $r['n'] );
		}
		$home['stats']['money_owed'] = $owed;
		$late = (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.invoice_number, i.due_date, i.currency, i.amount-i.amount_paid AS bal, c.name AS client_name FROM $inv i LEFT JOIN " . Schema::table( 'clients' ) . " c ON c.id=i.client_id WHERE i.status='overdue' ORDER BY i.due_date LIMIT 8" ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $late as $r ) {
			$home['todo'][] = array( 'kind' => 'invoice', 'title' => 'Chase ' . $r['invoice_number'], 'sub' => $r['client_name'] . ' owes ' . $r['currency'] . ' ' . number_format( (float) $r['bal'], 2 ), 'link' => '/billing/invoices/' . (int) $r['id'], 'days' => $r['due_date'] ? (int) floor( ( strtotime( $r['due_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS ) : 0 );
		}
		$soon = (array) $wpdb->get_results( $wpdb->prepare( "SELECT i.id, i.invoice_number, i.due_date, c.name AS client_name FROM $inv i LEFT JOIN " . Schema::table( 'clients' ) . " c ON c.id=i.client_id WHERE i.status IN ('sent','due','partially_paid') AND i.due_date >= %s ORDER BY i.due_date LIMIT 8", $today ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $soon as $r ) {
			$home['coming_up'][] = array( 'kind' => 'invoice', 'title' => $r['invoice_number'] . ' falls due', 'sub' => $r['client_name'], 'link' => '/billing/invoices/' . (int) $r['id'], 'days' => (int) floor( ( strtotime( $r['due_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS ) );
		}
		$home['setup'][] = array( 'key' => 'catalog', 'label' => 'Add what you sell to the catalog', 'to' => '/catalog', 'done' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'catalog' ) ) > 0 ); // phpcs:ignore WordPress.DB
		$home['setup'][] = array( 'key' => 'payment', 'label' => \WPFlatCore\Terminology::fmt( 'Add how {clients} can pay you' ), 'to' => '/settings?tab=finance', 'done' => count( (array) Settings::get( 'payment_methods' ) ) > 0 );
		return $home;
	}
}
