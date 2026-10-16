<?php
namespace BizyFinance;

use BizyCore\Services\Demo as Core_Demo;

defined( 'ABSPATH' ) || exit;

/** BizyFinance's part of the sample data: catalog, prices on the sample project, invoices with payments, and expenses. */
class Demo {

	public static function init() {
		add_action( 'bizycore_demo_load', array( __CLASS__, 'load' ), 20 );
	}

	public static function load( $ctx ) {
		global $wpdb;
		if ( ! \BizyCore\Registry::enabled( 'finance' ) ) {
			return;
		}
		$web = $ctx['web'];
		$mkt = $ctx['marketing'];
		$c   = $ctx['clients'];
		foreach ( $c as $id ) {
			Core_Demo::add( 'bizyfinance_client_billing', array( 'client_id' => $id, 'currency' => 'NGN' ), 'client_id' );
		}
		foreach ( $ctx['team'] as $i => $id ) {
			Core_Demo::add( 'bizyfinance_team_pay', array( 'team_member_id' => $id, 'salary_amount' => $i ? 280000 : 250000, 'salary_period' => 'monthly', 'currency' => 'NGN' ), 'team_member_id' );
		}
		Core_Demo::add( 'bizyfinance_catalog', array( 'name' => 'Website design (Sample)', 'type' => 'service', 'kind' => 'one_off', 'pricing' => 'fixed', 'default_price' => 650000, 'default_cost' => 250000, 'default_currency' => 'NGN', 'department_id' => $web ) );
		Core_Demo::add( 'bizyfinance_catalog', array( 'name' => 'Website hosting (Sample)', 'type' => 'service', 'kind' => 'recurring', 'default_billing_period' => 'annual', 'pricing' => 'fixed', 'default_price' => 85000, 'default_cost' => 30000, 'default_currency' => 'NGN', 'department_id' => $web ) );
		Core_Demo::add( 'bizyfinance_catalog', array( 'name' => 'Branded mug (Sample)', 'type' => 'product', 'kind' => 'one_off', 'pricing' => 'unit', 'unit_label' => 'mug', 'default_price' => 3500, 'default_cost' => 1800, 'default_currency' => 'NGN', 'department_id' => $mkt ) );
		$invs = array(
			array( $c[0], 'SAMPLE-0001', 'Website design — deposit', 325000, 325000, -30, -16, $web ),
			array( $c[1], 'SAMPLE-0002', 'Monthly retainer', 150000, 60000, -12, -2, $mkt ),
			array( $c[2], 'SAMPLE-0003', 'Website hosting (annual)', 85000, 0, -3, 11, $web ),
			array( $c[0], 'SAMPLE-0004', 'Website design — balance', 325000, 0, 0, 14, $web ),
		);
		foreach ( $invs as $v ) {
			$id = Core_Demo::add( 'bizyfinance_invoices', array( 'client_id' => $v[0], 'invoice_number' => $v[1], 'title' => $v[2], 'amount' => $v[3], 'subtotal' => $v[3], 'currency' => 'NGN', 'issue_date' => Core_Demo::day( $v[5] ), 'due_date' => Core_Demo::day( $v[6] ), 'status' => 'sent', 'sent_at' => current_time( 'mysql' ), 'discount_type' => 'none', 'department_id' => $v[7], 'notes' => 'Sample data' ) );
			Core_Demo::add( 'bizyfinance_invoice_items', array( 'invoice_id' => $id, 'source_type' => 'manual', 'kind' => 'fixed', 'description' => $v[2], 'quantity' => 1, 'unit_price' => $v[3], 'amount' => $v[3], 'department_id' => $v[7] ) );
			if ( $v[4] > 0 ) {
				Core_Demo::add( 'bizyfinance_payments', array( 'invoice_id' => $id, 'client_id' => $v[0], 'amount' => $v[4], 'currency' => 'NGN', 'paid_on' => Core_Demo::day( $v[5] + 3 ), 'reference' => 'Sample payment', 'department_id' => $v[7] ) );
			}
			Services\Invoices::refresh_status( $id );
		}
		foreach ( array( array( 'Hosting for staging servers', 'hosting', 42000, -9 ), array( 'Design software subscription', 'software', 28000, -4 ), array( 'Facebook ads', 'marketing', 60000, -2 ) ) as $e ) {
			Core_Demo::add( 'bizyfinance_expenses', array( 'spent_on' => Core_Demo::day( $e[3] ), 'amount' => $e[2], 'currency' => 'NGN', 'category' => $e[1], 'description' => $e[0], 'status' => 'paid' ) );
		}
		// Prices on the sample project and its phases (BizyTasks), when it is on.
		$ph = $wpdb->prefix . 'bizytasks_projects';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$ph'" ) === $ph ) { // phpcs:ignore WordPress.DB
			$pid = (int) $wpdb->get_var( "SELECT id FROM $ph WHERE name='Website redesign' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB
			if ( $pid ) {
				Core_Demo::add( 'bizyfinance_project_money', array( 'project_id' => $pid, 'price' => 650000, 'currency' => 'NGN' ), 'project_id' );
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM ' . $wpdb->prefix . 'bizytasks_phases WHERE project_id=%d', $pid ), ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB
					Core_Demo::add( 'bizyfinance_phase_money', array( 'phase_id' => $p['id'], 'price' => 'Design' === $p['name'] ? 250000 : 400000, 'team_budget' => 'Design' === $p['name'] ? 80000 : 120000 ), 'phase_id' );
				}
			}
		}
	}
}
