<?php
namespace WPFlatFinance;

use WPFlatCore\Resources;

use WPFlatCore\Rest\Resource_Controller;
use WPFlatCore\Rest\Routes as Core_Routes;
use WPFlatCore\Services\Activity;
use WPFlatFinance\Services\Billing;
use WPFlatFinance\Services\BillingList;
use WPFlatFinance\Services\BillingQueue;
use WPFlatFinance\Services\BillSummary;
use WPFlatFinance\Services\Invoice_Mail;
use WPFlatFinance\Services\Invoices;
use WPFlatFinance\Services\Ledger;
use WPFlatFinance\Services\Renewals;
use WPFlatFinance\Import\Invoice_Import;

defined( 'ABSPATH' ) || exit;

/** WPFlat Finance's REST routes (in WPFlat Core's wpflatcore/v1 namespace, gone when the module is off). */
class Routes {

	/** Registers a route with WPFlat Core, which drops it when WPFlat Finance is switched off. */
	private static function reg( $route, $args ) {
		Core_Routes::add( 'finance', $route, $args );
	}

	public static function register() {
		$perm = array( Resource_Controller::class, 'can' );
		$fin  = function () {
			return current_user_can( 'manage_options' ) || ( current_user_can( 'manage_wpflatcore' ) && current_user_can( 'wpflatfinance_finance' ) );
		};
		self::reg( '/invoices/(?P<id>\d+)/share', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invoice_share' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/mark-sent', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invoice_mark_sent' ), 'permission_callback' => $perm ) );
		self::reg( '/clients/(?P<id>\d+)/unbilled', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'client_unbilled' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/email-defaults', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'email_defaults' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/email-preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'email_preview' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/send', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'email_send' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/remind', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'email_remind' ), 'permission_callback' => $perm ) );
		self::reg( '/invoices/(?P<id>\d+)/emails', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'email_history' ), 'permission_callback' => $perm ) );
		self::reg( '/expenses/summary', array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( \WPFlatFinance\Services\Expenses::summary() ); }, 'permission_callback' => $fin ) );
		self::reg( '/expenses/post-due', array( 'methods' => 'POST', 'callback' => function () { return rest_ensure_response( array( 'posted' => \WPFlatFinance\Services\Expenses::post_due() ) ); }, 'permission_callback' => $fin ) );
		self::reg( '/payroll', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \WPFlatFinance\Services\Payroll::overview( (string) $req->get_param( 'period' ) ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/payroll/run', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $req ) {
			$p = (array) $req->get_json_params();
			return rest_ensure_response( \WPFlatFinance\Services\Payroll::run( isset( $p['period'] ) ? $p['period'] : '' ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/payroll/pay', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $req ) {
			$p = (array) $req->get_json_params();
			return rest_ensure_response( \WPFlatFinance\Services\Payroll::pay_members( isset( $p['member_ids'] ) ? (array) $p['member_ids'] : array(), isset( $p['paid_on'] ) ? $p['paid_on'] : '', isset( $p['method'] ) ? $p['method'] : '', isset( $p['reference'] ) ? $p['reference'] : '' ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/finance/overview', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \WPFlatFinance\Services\Profit::overview( $req->get_param( 'from' ), $req->get_param( 'to' ), (string) $req->get_param( 'income_basis' ), (string) $req->get_param( 'pay_basis' ), $req->get_param( 'department_id' ) ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/finance/analytics', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			return rest_ensure_response( \WPFlatFinance\Services\FinanceAnalytics::query( array(
				'from'          => (string) $req->get_param( 'from' ),
				'to'            => (string) $req->get_param( 'to' ),
				'department_id' => (string) $req->get_param( 'department_id' ),
				'currency'      => strtoupper( sanitize_text_field( (string) $req->get_param( 'currency' ) ) ) ?: 'all',
				'income_basis'  => (string) $req->get_param( 'income_basis' ),
				'pay_basis'     => (string) $req->get_param( 'pay_basis' ),
			) ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/finance/export', array( 'methods' => 'GET', 'callback' => function ( \WP_REST_Request $req ) {
			$type = in_array( $req->get_param( 'type' ), array( 'income', 'expenses', 'team' ), true ) ? $req->get_param( 'type' ) : 'income';
			$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param( 'from' ) ) ? $req->get_param( 'from' ) : gmdate( 'Y-01-01' );
			$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param( 'to' ) ) ? $req->get_param( 'to' ) : gmdate( 'Y-12-31' );
			$dep = (string) $req->get_param( 'department_id' );
			$cur = strtoupper( sanitize_text_field( (string) $req->get_param( 'currency' ) ) );
			return rest_ensure_response( \WPFlatFinance\Services\Profit::export( $type, $from, $to, 'none' === $dep ? 0 : absint( $dep ), $cur ) );
		}, 'permission_callback' => $fin ) );
		self::reg( '/payouts/create', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $req ) {
			$p   = (array) $req->get_json_params();
			$res = \WPFlatFinance\Services\Pay::create_payout( isset( $p['team_member_id'] ) ? $p['team_member_id'] : 0, isset( $p['item_ids'] ) ? (array) $p['item_ids'] : array(), isset( $p['paid_on'] ) ? $p['paid_on'] : '', isset( $p['method'] ) ? $p['method'] : '', isset( $p['reference'] ) ? $p['reference'] : '', isset( $p['notes'] ) ? $p['notes'] : '' );
			return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
		}, 'permission_callback' => $fin ) );
		self::reg( '/payouts/(?P<id>\d+)/void', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $req ) {
			$res = \WPFlatFinance\Services\Pay::void_payout( (int) $req['id'] );
			return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
		}, 'permission_callback' => $fin ) );
		self::reg( '/billing/summary', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'billing_summary' ), 'permission_callback' => $perm ) );
		self::reg( '/billing/queue', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'billing_queue' ), 'permission_callback' => $perm ) );
		self::reg( '/clients/(?P<id>\d+)/ledger', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'client_ledger' ), 'permission_callback' => $perm ) );
		self::reg( '/client-services/(?P<id>\d+)/ledger', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'service_ledger' ), 'permission_callback' => $perm ) );
		self::reg( '/billing/list', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'billing_list' ), 'permission_callback' => $perm ) );
		self::reg( '/currencies/usage', array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( \WPFlatFinance\Services\Currency::usage() ); }, 'permission_callback' => $perm ) );
		self::reg( '/invoices/import/preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_preview' ), 'permission_callback' => $fin ) );
		self::reg( '/invoices/import/run', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_run' ), 'permission_callback' => $fin ) );
		self::reg( '/invoices/import/history', array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( \WPFlatFinance\Import\Invoice_Import::history() ); }, 'permission_callback' => $fin ) );
		self::reg( '/invoices/import/(?P<id>[a-f0-9]{6,32})/undo', array( 'methods' => 'POST', 'callback' => function ( \WP_REST_Request $req ) { $r = \WPFlatFinance\Import\Invoice_Import::undo( (string) $req['id'] ); return is_wp_error( $r ) ? $r : rest_ensure_response( $r ); }, 'permission_callback' => $fin ) );
		self::reg( '/renewals/(?P<id>\d+)/mark-renewed', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'mark_renewed' ), 'permission_callback' => $perm ) );
		self::reg( '/charges/(?P<id>\d+)/waive', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'waive_charge' ), 'permission_callback' => $perm ) );
		self::reg( '/finance/settings', array(
			array( 'methods' => 'GET', 'callback' => function () { return rest_ensure_response( array_intersect_key( \WPFlatFinance\Settings::get(), \WPFlatFinance\Settings::defaults() ) ); }, 'permission_callback' => $perm ),
			array( 'methods' => 'POST,PUT', 'callback' => function ( \WP_REST_Request $req ) {
				$out = \WPFlatFinance\Settings::update( (array) $req->get_json_params() );
				Activity::log( 'settings', 0, 0, 'updated', 'Billing settings updated' );
				return rest_ensure_response( array_intersect_key( $out, \WPFlatFinance\Settings::defaults() ) );
			}, 'permission_callback' => $fin ),
		) );
		self::reg( '/invoices/from-sources', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invoice_from_sources' ), 'permission_callback' => $perm ) );
	}

	/** Billing: everything we charge (retainers, subscriptions, one-off) in one list. */
	public static function billing_list( \WP_REST_Request $req ) {
		return rest_ensure_response(
			\WPFlatFinance\Services\BillingList::query(
				array(
					'type'      => sanitize_key( (string) $req->get_param( 'type' ) ),
					'status'    => sanitize_key( (string) $req->get_param( 'status' ) ),
					'client_id' => absint( $req->get_param( 'client_id' ) ),
					'department_id' => absint( $req->get_param( 'department_id' ) ),
					'currency'  => sanitize_text_field( (string) $req->get_param( 'currency' ) ),
					'q'         => sanitize_text_field( (string) $req->get_param( 'q' ) ),
					'page'      => absint( $req->get_param( 'page' ) ),
					'per_page'  => absint( $req->get_param( 'per_page' ) ),
				)
			)
		);
	}

	public static function billing_queue( \WP_REST_Request $req ) {
		$base = array(
			'client_id' => absint( $req->get_param( 'client_id' ) ),
			'type'      => sanitize_key( (string) $req->get_param( 'type' ) ),
			'currency'  => sanitize_text_field( (string) $req->get_param( 'currency' ) ),
			'search'    => sanitize_text_field( (string) $req->get_param( 'search' ) ),
		);
		$all     = BillingQueue::items( $base );
		$summary = BillingQueue::summarise( $all );

		$states = array_filter( array_map( 'trim', explode( ',', (string) $req->get_param( 'state' ) ) ) );
		$rows   = $states ? array_values( array_filter( $all, function ( $i ) use ( $states ) {
			return in_array( $i['state'], $states, true );
		} ) ) : $all;

		$per   = max( 1, min( 200, (int) ( $req->get_param( 'per_page' ) ? $req->get_param( 'per_page' ) : 25 ) ) );
		$page  = max( 1, (int) ( $req->get_param( 'page' ) ? $req->get_param( 'page' ) : 1 ) );
		$total = count( $rows );

		return rest_ensure_response(
			array(
				'items'   => array_slice( $rows, ( $page - 1 ) * $per, $per ),
				'total'   => $total,
				'pages'   => (int) ceil( $total / $per ),
				'summary' => $summary,
			)
		);
	}

	public static function billing_summary( \WP_REST_Request $req ) {
		$kind = 'recurring' === $req->get_param( 'kind' ) ? 'recurring' : 'one_off';
		$cid  = absint( $req->get_param( 'client_id' ) );
		$out  = array( 'kind' => $kind, 'statuses' => BillSummary::for_kind( $kind, $cid ) );
		if ( 'recurring' === $kind ) {
			$out['mrr'] = Subscriptions::mrr_totals( null, $cid );
		}
		return rest_ensure_response( $out );
	}

	public static function client_ledger( \WP_REST_Request $req ) {
		return rest_ensure_response( Ledger::for_client( (int) $req['id'] ) );
	}

	public static function client_unbilled( \WP_REST_Request $req ) {
		return rest_ensure_response( BillingQueue::items( array( 'client_id' => (int) $req['id'] ) ) );
	}

	public static function email_defaults( \WP_REST_Request $req ) {
		$d = Invoice_Mail::defaults( (int) $req['id'] );
		return $d ? rest_ensure_response( $d ) : new \WP_Error( 'wpflatfinance_not_found', 'Not found.', array( 'status' => 404 ) );
	}

	public static function email_history( \WP_REST_Request $req ) {
		return rest_ensure_response( Invoice_Mail::history( (int) $req['id'] ) );
	}

	public static function email_preview( \WP_REST_Request $req ) {
		$p     = (array) $req->get_json_params();
		$to    = Invoice_Mail::clean_list( isset( $p['to'] ) ? $p['to'] : array() );
		$names = is_wp_error( $to ) ? array() : wp_list_pluck( $to, 'name' );
		$r     = Invoice_Mail::render( (int) $req['id'], isset( $p['subject'] ) ? (string) $p['subject'] : '', isset( $p['message'] ) ? (string) $p['message'] : '', $names );
		return $r ? rest_ensure_response( array( 'html' => $r['html'], 'subject' => $r['subject'] ) ) : new \WP_Error( 'wpflatfinance_not_found', 'Not found.', array( 'status' => 404 ) );
	}

	public static function email_remind( \WP_REST_Request $req ) {
		$res = Invoice_Mail::send_reminder( (int) $req['id'], (array) $req->get_json_params() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error( 'wpflatfinance_mail_failed', $res['error'], array( 'status' => 502 ) );
		}
		return rest_ensure_response( $res );
	}

	public static function email_send( \WP_REST_Request $req ) {
		$p   = (array) $req->get_json_params();
		$res = Invoice_Mail::send( (int) $req['id'], $p, isset( $p['test_to'] ) ? sanitize_text_field( (string) $p['test_to'] ) : '' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error( 'wpflatfinance_mail_failed', $res['error'], array( 'status' => 502 ) );
		}
		return rest_ensure_response( $res );
	}

	public static function import_preview( \WP_REST_Request $req ) {
		$f = $req->get_file_params();
		if ( empty( $f['file']['tmp_name'] ) ) {
			return new \WP_Error( 'bd_import_file', 'Choose the file exported from Invoice.ng.', array( 'status' => 400 ) );
		}
		$p = \WPFlatFinance\Import\Invoice_Import::parse( $f['file']['tmp_name'], (string) $f['file']['name'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$out         = \WPFlatFinance\Import\Invoice_Import::preview( $p );
		$out['rows'] = $p['rows'];
		return rest_ensure_response( $out );
	}

	public static function import_run( \WP_REST_Request $req ) {
		$p = (array) $req->get_json_params();
		$r = \WPFlatFinance\Import\Invoice_Import::run( (array) ( $p['rows'] ?? array() ), (array) ( $p['plan'] ?? array() ), (string) ( $p['import_id'] ?? '' ) );
		if ( ! is_wp_error( $r ) && ! empty( $p['last'] ) ) {
			\WPFlatFinance\Import\Invoice_Import::finish( $r['import_id'] );
		}
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public static function invoice_from_sources( \WP_REST_Request $req ) {
		$p   = (array) $req->get_json_params();
		$inv = Invoices::create_from_sources(
			isset( $p['client_id'] ) ? (int) $p['client_id'] : 0,
			isset( $p['charge_ids'] ) ? (array) $p['charge_ids'] : array(),
			isset( $p['renewal_ids'] ) ? (array) $p['renewal_ids'] : array(),
			$p
		);
		if ( is_wp_error( $inv ) ) {
			return $inv;
		}
		$resp = rest_ensure_response( Resources::repo( 'invoices' )->find( (int) $inv['id'] ) );
		$resp->set_status( 201 );
		return $resp;
	}

	public static function invoice_mark_sent( \WP_REST_Request $req ) {
		$row = Invoices::mark_sent( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'wpflatfinance_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$rows = Resources::repo( 'invoices' )->find( (int) $req['id'] );
		return rest_ensure_response( array( 'status' => $rows['status'], 'url' => Invoices::public_url( Invoices::ensure_token( (int) $req['id'] ) ) ) );
	}

	public static function invoice_share( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! Resources::repo( 'invoices' )->find( $id ) ) {
			return new \WP_Error( 'wpflatfinance_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		$p     = (array) $req->get_json_params();
		if ( isset( $p['slug'] ) && '' !== trim( (string) $p['slug'] ) ) {
			$set = Invoices::set_slug( $id, (string) $p['slug'] );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
			Activity::log( 'invoices', $id, 0, 'link', 'Invoice link changed to ' . $set );
			return rest_ensure_response( array( 'url' => Invoices::public_url( $set ), 'slug' => $set ) );
		}
		$token = Invoices::ensure_token( $id, ! empty( $p['regenerate'] ) );
		if ( ! empty( $p['regenerate'] ) ) {
			Activity::log( 'invoices', $id, 0, 'link', 'Invoice share link regenerated' );
		}
		return rest_ensure_response( array( 'url' => Invoices::public_url( $token ), 'slug' => $token ) );
	}

	public static function mark_renewed( \WP_REST_Request $req ) {
		$repo = Resources::repo( 'renewals' );
		$r    = $repo->find( (int) $req['id'] );
		if ( ! $r ) {
			return new \WP_Error( 'wpflatfinance_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $r['status'], array( 'upcoming', 'due' ), true ) ) {
			return new \WP_Error( 'wpflatfinance_invalid', 'Only open renewals can be marked renewed.', array( 'status' => 400 ) );
		}
		$next = Renewals::mark_renewed( $r );
		Activity::log( 'renewals', $r['id'], $r['client_id'], 'renewed', sprintf( 'Renewal marked renewed: %s — %s (%s)', $r['service_name'], $r['client_name'], $r['due_date'] ) . ( $next ? ' · next due ' . $next['due_date'] : '' ) );

		$rows = Renewals::decorate( array( $repo->find( $r['id'] ) ) );
		return rest_ensure_response( array( 'renewal' => $rows[0], 'next' => $next ) );
	}

	public static function service_ledger( \WP_REST_Request $req ) {
		return rest_ensure_response( Ledger::for_service( (int) $req['id'] ) );
	}

	public static function waive_charge( \WP_REST_Request $req ) {
		$repo = Resources::repo( 'charges' );
		$c    = $repo->find( (int) $req['id'] );
		if ( ! $c ) {
			return new \WP_Error( 'wpflatfinance_not_found', 'Not found.', array( 'status' => 404 ) );
		}
		if ( 'pending' !== $c['status'] ) {
			return new \WP_Error( 'wpflatfinance_invalid', 'Only pending charges can be waived.', array( 'status' => 400 ) );
		}
		$repo->update( $c['id'], array( 'status' => 'waived' ) );
		Activity::log( 'charges', $c['id'], $c['client_id'], 'waived', sprintf( 'Charge waived: %s — %s', $c['description'], $c['client_name'] ) );
		return rest_ensure_response( $repo->find( $c['id'] ) );
	}
}
