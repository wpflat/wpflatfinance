<?php
namespace BizyFinance\Services;

use BizyFinance\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * "Billing": ONE list of everything we charge customers — retainers (service-type catalog items),
 * subscriptions (product-type catalog items, e.g. domain or hosting renewals) and one-off charges —
 * so any mix of them can be ticked into a single invoice.
 *
 * Rows are the existing bills (renewal cycles + charges), each with the shared bill_status
 * (to invoice / draft / awaiting payment / overdue / paid) from Billing::bill_status.
 */
class BillingList {

	const TYPES    = array( 'retainer', 'subscription', 'one_off' );
	const ACTION   = array( 'not_invoiced', 'invoice_draft', 'awaiting', 'overdue' ); // "needs attention"
	const STATUSES = array( 'not_invoiced', 'invoice_draft', 'awaiting', 'overdue', 'paid', 'waived' );

	/**
	 * @param array $f type (all|retainer|subscription|one_off), status (action|all|<bill_status>), client_id,
	 *                 currency, q, page, per_page.
	 * @return array{items:array,total:int,page:int,per_page:int,chips:array,money:array}
	 */
	public static function query( array $f = array() ) {
		global $wpdb;
		$today = Billing::today();
		$soon  = gmdate( 'Y-m-d', strtotime( "$today +" . BillingQueue::window() . ' days' ) );
		$cl    = Schema::table( 'clients' );
		$cs    = Schema::table( 'client_services' );
		$sv    = Schema::table( 'catalog' );
		$iv    = Schema::table( 'invoices' );

		$cid = ! empty( $f['client_id'] ) ? ' AND %T%.client_id=' . (int) $f['client_id'] : '';
		$dep = ! empty( $f['department_id'] ) ? (int) $f['department_id'] : 0;
		$br  = Schema::table( 'brands' );

		$rows = array();

		// Recurring cycles: retainers + subscriptions (skipped/cancelled cycles are not billing history).
		$sql = "SELECT r.id, r.client_id, r.client_service_id, r.due_date, r.price, r.quantity, r.unit_price, r.subject, r.currency, r.frequency, r.interval_days, r.status, r.invoice_id,
				c.name AS client_name, s.name AS service_name, s.type AS service_type, s.subject_label, cs.billing_period, cs.department_id AS department_id, dp.name AS department_name,
				i.invoice_number, i.status AS invoice_status
			FROM " . Schema::table( 'renewals' ) . " r
			LEFT JOIN $cl c ON c.id=r.client_id LEFT JOIN $cs cs ON cs.id=r.client_service_id LEFT JOIN $sv s ON s.id=cs.service_id LEFT JOIN $iv i ON i.id=r.invoice_id LEFT JOIN $br dp ON dp.id=cs.department_id
			WHERE r.price > 0 AND r.status NOT IN ('skipped','cancelled')" . str_replace( '%T%', 'r', $cid ) . ( $dep ? " AND cs.department_id=$dep" : '' ); // phpcs:ignore WordPress.DB
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$type = 'product' === $r['service_type'] ? 'subscription' : 'retainer';
			$name = (string) $r['service_name'];
			$rows[] = array(
				'key'               => 'renewal:' . $r['id'],
				'kind'              => 'renewal',
				'type'              => $type,
				'renewal_id'        => (int) $r['id'],
				'charge_id'         => 0,
				'client_service_id' => (int) $r['client_service_id'],
				'client_id'         => (int) $r['client_id'],
				'client_name'       => (string) $r['client_name'],
				'title'             => '' !== (string) $r['subject'] ? $name . ' — ' . $r['subject'] : $name,
				'service_name'      => $name,
				'subject'           => (string) $r['subject'],
				'period'            => $r['billing_period'] ? $r['billing_period'] : $r['frequency'],
				'date'              => $r['due_date'],
				'price'             => (float) $r['price'],
				'quantity'          => (float) $r['quantity'],
				'unit_price'        => (float) $r['unit_price'],
				'currency'          => $r['currency'],
				'bill_status'       => Billing::bill_status( $r, array( 'upcoming', 'due' ) ),
				'department_id'     => (int) $r['department_id'],
					'department_name'   => (string) $r['department_name'],
					'invoice_id'        => (int) $r['invoice_id'],
				'invoice_number'    => (string) $r['invoice_number'],
				'invoice_status'    => (string) $r['invoice_status'],
			);
		}

		// One-off charges.
		$sql = "SELECT ch.id, ch.client_id, ch.client_service_id, ch.description, ch.bill_on_date, ch.incurred_on, ch.price, ch.currency, ch.status, ch.invoice_id,
				c.name AS client_name, s.name AS service_name, ch.department_id AS department_id, dp.name AS department_name, i.invoice_number, i.status AS invoice_status
			FROM " . Schema::table( 'charges' ) . " ch
			LEFT JOIN $cl c ON c.id=ch.client_id LEFT JOIN $cs cs ON cs.id=ch.client_service_id LEFT JOIN $sv s ON s.id=IF(ch.service_id>0, ch.service_id, cs.service_id) LEFT JOIN $iv i ON i.id=ch.invoice_id LEFT JOIN $br dp ON dp.id=ch.department_id
			WHERE 1=1" . str_replace( '%T%', 'ch', $cid ) . ( $dep ? " AND ch.department_id=$dep" : '' ); // phpcs:ignore WordPress.DB
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$rows[] = array(
				'key'               => 'charge:' . $r['id'],
				'kind'              => 'charge',
				'type'              => 'one_off',
				'renewal_id'        => 0,
				'charge_id'         => (int) $r['id'],
				'client_service_id' => (int) $r['client_service_id'],
				'client_id'         => (int) $r['client_id'],
				'client_name'       => (string) $r['client_name'],
				'title'             => (string) $r['description'],
				'service_name'      => (string) $r['service_name'],
				'subject'           => '',
				'period'            => '',
				'date'              => $r['bill_on_date'] ? $r['bill_on_date'] : $r['incurred_on'],
				'price'             => (float) $r['price'],
				'quantity'          => 1.0,
				'unit_price'        => 0.0,
				'currency'          => $r['currency'],
				'bill_status'       => Billing::bill_status( $r, array( 'pending' ) ),
				'department_id'     => (int) $r['department_id'],
					'department_name'   => (string) $r['department_name'],
					'invoice_id'        => (int) $r['invoice_id'],
				'invoice_number'    => (string) $r['invoice_number'],
				'invoice_status'    => (string) $r['invoice_status'],
			);
		}

		// Filters that do not change the chip counts.
		$cur = ! empty( $f['currency'] ) ? strtoupper( (string) $f['currency'] ) : '';
		$q   = ! empty( $f['q'] ) ? strtolower( (string) $f['q'] ) : '';
		$rows = array_values(
			array_filter(
				$rows,
				function ( $r ) use ( $cur, $q ) {
					if ( 'closed' === $r['bill_status'] ) {
						return false; // billed-and-renewed history lives on the invoice itself
					}
					if ( $cur && $r['currency'] !== $cur ) {
						return false;
					}
					return ! $q || false !== strpos( strtolower( $r['client_name'] . ' ' . $r['title'] . ' ' . $r['service_name'] ), $q );
				}
			)
		);

		$status = isset( $f['status'] ) && '' !== $f['status'] ? (string) $f['status'] : 'action';
		$in_status = function ( $r ) use ( $status ) {
			if ( 'all' === $status ) {
				return true;
			}
			if ( 'action' === $status ) {
				return in_array( $r['bill_status'], self::ACTION, true );
			}
			return $r['bill_status'] === $status;
		};
		$rows = array_values( array_filter( $rows, $in_status ) );

		// Chip counts (respect everything but the type chip).
		$chips = array( 'all' => count( $rows ), 'retainer' => 0, 'subscription' => 0, 'one_off' => 0 );
		foreach ( $rows as $r ) {
			++$chips[ $r['type'] ];
		}
		$type = isset( $f['type'] ) ? (string) $f['type'] : 'all';
		if ( in_array( $type, self::TYPES, true ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $type ) {
				return $r['type'] === $type;
			} ) );
		}

		// Derived fields + ordering: most urgent first, then by date; finished items last (newest first).
		$rank = array( 'overdue' => 0, 'ready' => 1, 'scheduled' => 2, 'unscheduled' => 3 );
		foreach ( $rows as &$r ) {
			$open        = 'not_invoiced' === $r['bill_status'];
			$r['state']  = $open ? BillingQueue::state_for( $r['date'], $today, $soon ) : '';
			$r['days']   = $r['date'] ? (int) round( ( strtotime( $r['date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS ) : null;
			$r['can_select'] = $open;
			$r['sort']   = $open ? $rank[ $r['state'] ] : ( in_array( $r['bill_status'], array( 'overdue', 'awaiting', 'invoice_draft' ), true ) ? 4 : 9 );
		}
		unset( $r );
		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['sort'] !== $b['sort'] ) {
					return $a['sort'] <=> $b['sort'];
				}
				$c = 9 === $a['sort'] ? strcmp( (string) $b['date'], (string) $a['date'] ) : strcmp( (string) $a['date'], (string) $b['date'] );
				return $c ? $c : strcmp( $a['key'], $b['key'] );
			}
		);

		// Money still to bill / collect, per currency (for the header line).
		$money = array( 'to_invoice' => array(), 'outstanding' => array() );
		foreach ( $rows as $r ) {
			$bucket = 'not_invoiced' === $r['bill_status'] ? 'to_invoice' : ( in_array( $r['bill_status'], array( 'awaiting', 'overdue' ), true ) ? 'outstanding' : '' );
			if ( $bucket ) {
				$cc = $r['currency'];
				$money[ $bucket ][ $cc ] = array( 'amount' => round( ( isset( $money[ $bucket ][ $cc ] ) ? $money[ $bucket ][ $cc ]['amount'] : 0 ) + $r['price'], 2 ), 'count' => ( isset( $money[ $bucket ][ $cc ] ) ? $money[ $bucket ][ $cc ]['count'] : 0 ) + 1 );
			}
		}

		$per   = max( 1, min( 100, isset( $f['per_page'] ) ? (int) $f['per_page'] : 25 ) );
		$page  = max( 1, isset( $f['page'] ) ? (int) $f['page'] : 1 );
		$total = count( $rows );
		return array(
			'items'    => array_slice( $rows, ( $page - 1 ) * $per, $per ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
			'chips'    => $chips,
			'money'    => $money,
		);
	}
}
