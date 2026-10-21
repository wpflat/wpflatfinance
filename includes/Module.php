<?php
namespace WPFlatFinance;

use WPFlatCore\Registry;

defined( 'ABSPATH' ) || exit;

/** WPFlat Finance's connection to WPFlat Core: it describes itself, then hooks into the shared places. */
class Module {

	/** Money resources that need the finance capability on top of billing. */
	const FINANCE_RESOURCES = array( 'expenses', 'recurring-expenses', 'pay-allocations', 'pay-items', 'payouts' );

	public static function init() {
		Migrate::init();
		Install::maybe_upgrade();
		Handover::init();
		add_action( 'wpflatcore_register', array( __CLASS__, 'register' ) );
		add_action( 'wpflatcore_register_terms', array( __CLASS__, 'terms' ) );
		add_action( 'wpflatcore_register_resources', array( Resources_Def::class, 'register' ) );
		add_action( 'wpflatcore_register_link_types', array( Integrations::class, 'link_types' ) );
		add_action( 'wpflatcore_register_notifications', array( Notify::class, 'types' ) );
		add_action( 'wpflatcore_register_portal', array( Portal_Page::class, 'register' ) );
		add_action( 'wpflatcore_register_routes', array( Routes::class, 'register' ) );
		add_filter( 'wpflatcore_lookup_defaults', array( __CLASS__, 'lookups' ) );
		add_action( 'wpflatcore_daily', array( __CLASS__, 'daily' ), 10 );
		Integrations::init();
		Notify::init();
		Legacy::init();
		Demo::init();
		if ( Registry::enabled( 'finance' ) || ! did_action( 'wpflatcore_ready' ) ) {
			Public_Invoice::init();
			Payslip::init();
		}
	}

	public static function register() {
		wpflatcore_register_module(
			array(
				'id'            => 'finance',
				'label'         => 'Finance',
				'version'       => WPFLATFINANCE_VERSION,
				'requires_core' => '0.6.0',
				'desc'          => 'The catalog, invoices, billing, payments, expenses, payroll and profit.',
				'icon'          => 'finance',
				'route'         => '/finance',
				'routes'        => array( 'finance', 'billing', 'invoices', 'catalog', 'renewals', 'charges', 'expenses', 'payroll' ),
				'cap'           => 'wpflatfinance_billing',
				'caps'          => array( 'wpflatfinance_billing', 'wpflatfinance_finance' ),
				'resources'     => array( 'catalog', 'client-services', 'renewals', 'charges', 'invoices', 'payments', 'expenses', 'recurring-expenses', 'pay-allocations', 'pay-items', 'payouts' ),
				'tables'        => array_map( function ( $t ) {
					return 'wpflatfinance_' . $t;
				}, Schema::OWN ),
				'optional_tables' => array( 'wpflatfinance_email_log' ),
				'options'       => array( 'wpflatfinance_settings', 'wpflatfinance_payout_next', 'wpflatfinance_invoice_checks' ),
				'attachment_columns' => array( 'wpflatfinance_invoices' => array( 'attachment_id' ), 'wpflatfinance_expenses' => array( 'attachment_id' ) ),
				'refs'          => array(
					'wpflatfinance_client_services'       => array( 'client_id' => 'wpflat_clients', 'service_id' => 'wpflatfinance_catalog' ),
					'wpflatfinance_renewals'              => array( 'client_service_id' => 'wpflatfinance_client_services', 'client_id' => 'wpflat_clients' ),
					'wpflatfinance_charges'               => array( 'client_id' => 'wpflat_clients' ),
					'wpflatfinance_invoices'              => array( 'client_id' => 'wpflat_clients' ),
					'wpflatfinance_invoice_items'         => array( 'invoice_id' => 'wpflatfinance_invoices' ),
					'wpflatfinance_invoice_installments'  => array( 'invoice_id' => 'wpflatfinance_invoices' ),
					'wpflatfinance_payments'              => array( 'invoice_id' => 'wpflatfinance_invoices' ),
					'wpflatfinance_client_billing'        => array( 'client_id' => 'wpflat_clients' ),
					'wpflatfinance_team_pay'              => array( 'team_member_id' => 'wpflat_team_members' ),
				),
				'file_kinds'    => array(),
				'public_paths'  => array( 'invoice' ),
				'public_params' => array( 'bd_invoice', 'bd_payslip' ),
				'roles'         => array(
					'wpflat_finance_team' => array( 'label' => 'WPFlat Finance Team', 'desc' => 'Billing, invoices, payments, expenses and payroll.', 'caps' => array( 'wpflatfinance_billing', 'wpflatfinance_finance' ) ),
				),
				'client_manager_caps' => array( 'wpflatfinance_billing' ),
				'manager_grants'      => array(
					'billing' => array( 'label' => 'Billing & invoices', 'caps' => array( 'wpflatfinance_billing' ) ),
					'finance' => array( 'label' => 'Finance (expenses, payroll, profit)', 'caps' => array( 'wpflatfinance_finance', 'wpflatfinance_billing' ) ),
				),
				'menu'          => array(
					array( 'label' => 'Overview', 'route' => '/finance', 'cap' => 'wpflatfinance_finance' ),
					array( 'label' => 'Billing', 'route' => '/billing' ),
					array( 'label' => 'Invoices', 'route' => '/billing/invoices' ),
					array( 'label' => 'Expenses', 'route' => '/finance/expenses', 'cap' => 'wpflatfinance_finance' ),
					array( 'label' => 'Payroll', 'route' => '/finance/payroll', 'cap' => 'wpflatfinance_finance' ),
					array( 'label' => 'Catalog', 'route' => '/catalog' ),
				),
				'assets'        => array( 'script' => 'wpflatfinance.js', 'style' => 'wpflatfinance.css' ),
				'assets_dir'    => WPFLATFINANCE_DIR . 'build',
				'wp'            => array( 'dashicons-money-alt', 28 ),
			)
		);
	}

	public static function terms() {
		\WPFlatCore\Terminology::register( 'invoice', 'Invoice', 'Invoices' );
		\WPFlatCore\Terminology::register( 'catalog', 'Catalog item', 'Catalog' );
	}

	/** Lists WPFlat Finance keeps in WPFlat Core's lists table (billing periods, how it's billed, expense categories). */
	public static function lookups( $d ) {
		$d['billing_period']    = array(
			array( 'none', 'One-time', 0, '' ),
			array( 'monthly', 'Monthly', 1, '' ),
			array( 'quarterly', 'Quarterly', 3, '' ),
			array( 'semiannual', 'Semi-annually', 6, '' ),
			array( 'annual', 'Annually', 12, '' ),
			array( 'custom', 'Custom', 0, '' ),
		);
		$d['billing_treatment'] = array(
			array( 'included_in_retainer', 'Included in retainer', 0, '#2563eb' ),
			array( 'separately_billed', 'Billed separately', 0, '#16a34a' ),
			array( 'cost_only', 'Not billed (our cost)', 0, '#d97706' ),
			array( 'no_charge', 'Not billed (free)', 0, '#6b7280' ),
			array( 'custom', 'Custom', 0, '#7c3aed' ),
		);
		$d['expense_category']  = array(
			array( 'software', 'Software & tools', 0, '#2563eb' ),
			array( 'hosting', 'Hosting & domains', 0, '#0891b2' ),
			array( 'rent', 'Rent & utilities', 0, '#d97706' ),
			array( 'marketing', 'Marketing & ads', 0, '#db2777' ),
			array( 'equipment', 'Equipment', 0, '#7c3aed' ),
			array( 'professional', 'Professional fees', 0, '#16a34a' ),
			array( 'travel', 'Travel & transport', 0, '#6b7280' ),
			array( 'bank', 'Bank & payment fees', 0, '#6b7280' ),
			array( 'office', 'Office & supplies', 0, '#6b7280' ),
			array( 'training', 'Training', 0, '#6b7280' ),
			array( 'other', 'Other', 0, '#6b7280' ),
		);
		return $d;
	}

	/** Roll invoice statuses forward, post due recurring expenses, and flag renewals that are coming up. */
	public static function daily() {
		global $wpdb;
		if ( ! Registry::enabled( 'finance' ) || \WPFlatCore\Backup\Importer::locked() ) {
			return;
		}
		$inv = Schema::table( 'invoices' );
		foreach ( (array) $wpdb->get_col( "SELECT id FROM $inv WHERE status IN ('sent','due','partially_paid','overdue')" ) as $id ) { // phpcs:ignore WordPress.DB
			Services\Invoices::refresh_status( (int) $id );
		}
		Services\Expenses::post_due();
		$ren  = Schema::table( 'renewals' );
		$days = (int) Settings::get( 'due_soon_days' );
		$soon = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " +$days days" ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $ren SET status='due' WHERE status='upcoming' AND due_date IS NOT NULL AND due_date <= %s", $soon ) ); // phpcs:ignore WordPress.DB
	}
}
