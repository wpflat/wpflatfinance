<?php
namespace BizyMoney;

use BizyCore\Lookups;

use BizyCore\Resources;
use BizyCore\Services\Activity;
use BizyMoney\Services\Billing;
use BizyMoney\Services\Charges;
use BizyMoney\Services\Invoices;
use BizyMoney\Services\Renewals;

defined( 'ABSPATH' ) || exit;

/** BizyMoney's REST resources: the catalog, subscriptions, renewals, charges, invoices, payments, expenses and pay. */
class Resources_Def {

	const CHARGE_STATUSES  = array( 'pending', 'invoiced', 'waived', 'cancelled' );
	const RENEWAL_STATUSES = array( 'upcoming', 'due', 'renewed', 'skipped', 'cancelled' );
	const INVOICE_STATUSES = array( 'draft', 'sent', 'due', 'partially_paid', 'paid', 'overdue', 'cancelled' );
	const TIMINGS          = array( 'now', 'end_of_month', 'end_of_quarter', 'next_renewal', 'custom' );

	private static function client_join() {
		return 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id';
	}

	public static function register() {
		$cs_join = 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id '
			. 'LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=t.client_service_id '
			. 'LEFT JOIN ' . Schema::table( 'catalog' ) . ' s ON s.id=cs.service_id';

		$defs = array(
			'catalog'         => array(
				'route'    => 'catalog',
				'table'    => 'bizymoney_catalog',
				'label'    => 'Service',
				'cols'     => array(
					'name' => 's', 'description' => 't', 'default_billing_period' => 's', 'default_cost' => 'd', 'default_price' => 'd',
					'default_billing_treatment' => 's', 'default_currency' => 'c', 'kind' => 's', 'type' => 's', 'pricing' => 's', 'unit_label' => 's', 'subject_label' => 's', 'department_id' => 'i', 'status' => 's',
				),
				'select'   => ', dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'filters'  => array( 'status', 'kind', 'type', 'pricing', 'department_id' ),
				'search'   => array( 'name', 'description' ),
				'order'    => 't.name ASC',
				'required' => array( 'name' ),
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d, $existing ) {
					if ( ! $existing ) {
						$d += array( 'status' => 'active' );
					}
					$type    = isset( $d['type'] ) ? $d['type'] : ( $existing ? $existing['type'] : 'service' );
					$type    = 'product' === $type ? 'product' : 'service';
					$pricing = isset( $d['pricing'] ) ? $d['pricing'] : ( $existing ? $existing['pricing'] : ( 'product' === $type ? 'unit' : 'fixed' ) );
					$pricing = 'unit' === $pricing ? 'unit' : 'fixed';
					$d['type']    = $type;
					$d['pricing'] = $pricing;
					if ( 'unit' !== $pricing ) {
						$d['unit_label'] = '';
					} elseif ( isset( $d['unit_label'] ) ) {
						$d['unit_label'] = trim( $d['unit_label'] );
					}
					$kind   = isset( $d['kind'] ) ? $d['kind'] : ( $existing ? $existing['kind'] : '' );
					$period = isset( $d['default_billing_period'] ) ? $d['default_billing_period'] : ( $existing ? $existing['default_billing_period'] : '' );
					if ( ! in_array( $kind, array( 'one_off', 'recurring' ), true ) ) {
						$kind = ( $period && 'none' !== $period ) ? 'recurring' : 'one_off';
					}
					if ( 'recurring' === $kind && ( ! $period || 'none' === $period ) ) {
						return new \WP_Error( 'bizymoney_invalid', 'A recurring service needs a billing cycle (e.g. monthly or annually).', array( 'status' => 400 ) );
					}
					$d['kind'] = $kind;
					if ( 'one_off' === $kind ) {
						$d['default_billing_period'] = 'none';
					}
					return $d;
				},
			),

			'client-services' => array(
				'route'    => 'client-services',
				'table'    => 'bizymoney_client_services',
				'label'    => 'Client service',
				'select'   => ', c.name AS client_name, s.name AS service_name, s.type AS service_type, s.pricing AS service_pricing, s.unit_label AS service_unit, s.subject_label AS service_subject_label, dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id LEFT JOIN ' . Schema::table( 'catalog' ) . ' s ON s.id=t.service_id LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array(
					'client_id' => 'i', 'service_id' => 'i', 'provider' => 's', 'subject' => 's', 'start_date' => 'date', 'status' => 's', 'cost' => 'd', 'client_price' => 'd',
					'currency'  => 'c', 'billing_treatment' => 's', 'billing_period' => 's', 'interval_days' => 'i', 'next_renewal_date' => 'date', 'notes' => 't',
					'quantity' => 'd', 'unit_price' => 'd', 'unit_cost' => 'd', 'department_id' => 'i',
				),
				'filters'  => array( 'client_id', 'service_id', 'status', 'billing_treatment', 'currency', 'billing_period', 'department_id' ),
				'extra'    => function ( $a ) {
					$sql  = array();
					$args = array();
					if ( ! empty( $a['recurring'] ) ) {
						$sql[] = "t.billing_period NOT IN ('','none')";
					}
					if ( ! empty( $a['service_type'] ) && in_array( $a['service_type'], array( 'service', 'product' ), true ) ) {
						$sql[]  = 's.type = %s';
						$args[] = $a['service_type'];
					}
					return array( implode( ' AND ', $sql ), $args );
				},
				'search'   => array( 'provider', 't.subject', 'c.name', 's.name' ),
				'order'    => 'c.name ASC, s.name ASC',
				'order_map' => array( 'client_name' => 'c.name', 'service_name' => 's.name' ),
				'required' => array( 'client_id', 'service_id' ),
				'title'    => function ( $r ) {
					return $r['service_name'] . ( ! empty( $r['subject'] ) ? ' (' . $r['subject'] . ')' : '' ) . ' — ' . $r['client_name'];
				},
				'before_save' => function ( $d, $existing, $params ) {
					global $wpdb;
					if ( ! $existing && ! empty( $d['service_id'] ) ) {
						$svc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'catalog' ) . ' WHERE id=%d', $d['service_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
						if ( $svc && empty( $params['department_id'] ) ) {
							$d['department_id'] = Services\Finance::default_department( $svc['id'], isset( $d['client_id'] ) ? $d['client_id'] : 0 ); // the catalog item's department, else the client's
						}
						if ( $svc ) {
							$map = array( 'cost' => 'default_cost', 'client_price' => 'default_price', 'billing_treatment' => 'default_billing_treatment', 'billing_period' => 'default_billing_period', 'currency' => 'default_currency' );
							foreach ( $map as $k => $from ) {
								if ( ! isset( $params[ $k ] ) || '' === $params[ $k ] ) {
									$d[ $k ] = 'cost' === $k || 'client_price' === $k ? (float) $svc[ $from ] : $svc[ $from ];
								}
							}
						}
						$d += array( 'status' => 'active' );
					}
					// Per-unit subscriptions (e.g. 12 email accounts): price and cost per cycle = quantity x unit.
					$sid = isset( $d['service_id'] ) ? (int) $d['service_id'] : ( $existing ? (int) $existing['service_id'] : 0 );
					$svc = $sid ? $wpdb->get_row( $wpdb->prepare( 'SELECT pricing, default_price, default_cost FROM ' . Schema::table( 'catalog' ) . ' WHERE id=%d', $sid ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
					if ( $svc && 'unit' === $svc['pricing'] ) {
						$touch = ! $existing || isset( $d['quantity'] ) || isset( $d['unit_price'] ) || isset( $d['unit_cost'] );
						if ( $touch ) {
							$qty  = isset( $d['quantity'] ) ? (float) $d['quantity'] : ( $existing ? (float) $existing['quantity'] : 1 );
							$qty  = $qty > 0 ? $qty : 1;
							$up   = isset( $d['unit_price'] ) && '' !== $d['unit_price'] ? (float) $d['unit_price'] : ( $existing ? (float) $existing['unit_price'] : (float) $svc['default_price'] );
							$uc   = isset( $d['unit_cost'] ) && '' !== $d['unit_cost'] ? (float) $d['unit_cost'] : ( $existing ? (float) $existing['unit_cost'] : (float) $svc['default_cost'] );
							$d['quantity']     = $qty;
							$d['unit_price']   = $up;
							$d['unit_cost']    = $uc;
							$d['client_price'] = round( $qty * $up, 2 );
							$d['cost']         = round( $qty * $uc, 2 );
						}
					}
					$cid = isset( $d['client_id'] ) ? $d['client_id'] : ( $existing ? $existing['client_id'] : 0 );
					if ( empty( $d['currency'] ) && ( ! $existing || ( isset( $d['currency'] ) && '' === $d['currency'] ) ) ) {
						$d['currency'] = Billing::client_currency( $cid );
					}
					return $d;
				},
				'after_save' => function ( $row ) {
					Renewals::sync_from_client_service( $row );
				},
				'decorate' => function ( $rows ) {
					$next = \BizyMoney\Services\Subscriptions::next_cycles( wp_list_pluck( $rows, 'id' ) );
					foreach ( $rows as &$r ) {
						$r['is_retainer'] = false;
						$r['mrr']         = 'active' === $r['status'] ? \BizyMoney\Services\Subscriptions::monthly_value( $r ) : 0;
						$r['next_cycle']  = isset( $next[ $r['id'] ] ) ? $next[ $r['id'] ] : null;
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'renewals' ) . " WHERE client_service_id=%d AND status IN ('upcoming','due') AND invoice_id=0", $row['id'] ) ); // phpcs:ignore WordPress.DB
				},
			),

			'renewals'        => array(
				'route'    => 'renewals',
				'table'    => 'bizymoney_renewals',
				'label'    => 'Renewal',
				'select'   => ', c.name AS client_name, s.name AS service_name, inv.invoice_number, inv.status AS invoice_status, dp.name AS department_name',
				'join'     => $cs_join . ' LEFT JOIN ' . Schema::table( 'invoices' ) . ' inv ON inv.id=t.invoice_id',
				'extra'    => function ( $a ) {
					$f = ! empty( $a['bill_status'] ) ? Billing::bill_status_sql( $a['bill_status'], "'upcoming','due'" ) : '';
					return array( $f, array() );
				},
				'cols'     => array(
					'client_service_id' => 'i', 'client_id' => 'i', 'due_date' => 'date', 'frequency' => 's', 'interval_days' => 'i', 'cost' => 'd',
					'price'             => 'd', 'currency' => 'c', 'status' => 's', 'renewed_on' => 'date', 'invoice_id' => 'i', 'notes' => 't', 'subject' => 's',
				),
				'filters'  => array( 'client_id', 'client_service_id', 'status', 'frequency', 'currency' ),
				'search'   => array( 'c.name', 's.name', 't.subject' ),
				'order'    => 't.due_date ASC',
				'order_map' => array( 'client_name' => 'c.name', 'service_name' => 's.name' ),
				'required' => array( 'client_service_id', 'due_date' ),
				'title'    => function ( $r ) {
					return $r['service_name'] . ( ! empty( $r['subject'] ) ? ' (' . $r['subject'] . ')' : '' ) . ' — ' . $r['client_name'] . ' (' . $r['due_date'] . ')';
				},
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( ! $existing ) {
						$cs = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'client_services' ) . ' WHERE id=%d', isset( $d['client_service_id'] ) ? $d['client_service_id'] : 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB
						if ( ! $cs ) {
							return new \WP_Error( 'bizymoney_invalid', 'Choose a valid client service.', array( 'status' => 400 ) );
						}
						$d['client_id'] = (int) $cs['client_id'];
						$d             += array(
							'frequency' => $cs['billing_period'],
							'cost'      => (float) $cs['cost'],
							'price'     => Billing::billable_price( $cs['billing_treatment'], $cs['client_price'] ),
							'currency'  => $cs['currency'],
							'subject'   => $cs['subject'],
							'status'    => 'upcoming',
						);
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					$rows = Renewals::decorate( $rows );
					foreach ( $rows as &$r ) {
						$r['bill_status'] = Billing::bill_status( $r, array( 'upcoming', 'due' ) );
					}
					return $rows;
				},
			),

			'charges'         => array(
				'route'    => 'charges',
				'table'    => 'bizymoney_charges',
				'label'    => 'One-off bill',
				'select'   => ', c.name AS client_name, s.name AS service_name, inv.invoice_number, inv.status AS invoice_status',
				'join'     => 'LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=t.client_service_id LEFT JOIN ' . Schema::table( 'catalog' ) . ' s ON s.id=IF(t.service_id>0, t.service_id, cs.service_id) LEFT JOIN ' . Schema::table( 'invoices' ) . ' inv ON inv.id=t.invoice_id' . ' LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'extra'    => function ( $a ) {
					$f = ! empty( $a['bill_status'] ) ? Billing::bill_status_sql( $a['bill_status'], "'pending'" ) : '';
					return array( $f, array() );
				},
				'cols'     => array(
					'client_id' => 'i', 'client_service_id' => 'i', 'service_id' => 'i', 'renewal_id' => 'i', 'description' => 's', 'cost' => 'd', 'price' => 'd', 'currency' => 'c',
					'incurred_on' => 'date', 'billing_timing' => 's', 'bill_on_date' => 'date', 'status' => 's', 'invoice_id' => 'i', 'notes' => 't', 'department_id' => 'i',
				),
				'filters'  => array( 'client_id', 'client_service_id', 'service_id', 'status', 'billing_timing', 'currency', 'invoice_id', 'department_id' ),
				'search'   => array( 't.description', 'c.name' ),
				'order'    => 't.bill_on_date IS NULL, t.bill_on_date ASC, t.id DESC',
				'order_map' => array( 'client_name' => 'c.name' ),
				'required' => array( 'client_id', 'description' ),
				'title'    => function ( $r ) {
					return $r['description'] . ' — ' . $r['client_name'];
				},
				'before_save' => function ( $d, $existing, $params ) {
					global $wpdb;
					$timings = self::TIMINGS;
					if ( ! $existing && empty( $params['department_id'] ) ) {
						$svc_id = ! empty( $d['service_id'] ) ? (int) $d['service_id'] : ( ! empty( $d['client_service_id'] ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT service_id FROM ' . Schema::table( 'client_services' ) . ' WHERE id=%d', (int) $d['client_service_id'] ) ) : 0 ); // phpcs:ignore WordPress.DB
						$d['department_id'] = Services\Finance::default_department( $svc_id, isset( $d['client_id'] ) ? $d['client_id'] : 0 ); // the catalog item's department, else the client's
					}
					// Picking a catalogue service prefills price/cost (unless supplied) and finds the client's matching subscription for "next renewal" timing.
					if ( ! empty( $d['service_id'] ) ) {
						$svc = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'catalog' ) . ' WHERE id=%d', $d['service_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
						if ( $svc && ! $existing ) {
							foreach ( array( 'price' => 'default_price', 'cost' => 'default_cost' ) as $k => $from ) {
								if ( ! isset( $params[ $k ] ) || '' === $params[ $k ] ) {
									$d[ $k ] = (float) $svc[ $from ];
								}
							}
							if ( empty( $d['description'] ) ) {
								$d['description'] = $svc['name'];
							}
						}
						$cid_for = isset( $d['client_id'] ) ? $d['client_id'] : ( $existing ? $existing['client_id'] : 0 );
						if ( $cid_for && empty( $d['client_service_id'] ) && ( ! $existing || empty( $existing['client_service_id'] ) ) ) {
							$cs_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'client_services' ) . " WHERE client_id=%d AND service_id=%d AND status='active' ORDER BY id DESC LIMIT 1", $cid_for, $d['service_id'] ) ); // phpcs:ignore WordPress.DB
							if ( $cs_id ) {
								$d['client_service_id'] = $cs_id;
							}
						}
					}
					if ( isset( $d['billing_timing'] ) && ! in_array( $d['billing_timing'], $timings, true ) ) {
						return new \WP_Error( 'bizymoney_invalid', 'Unknown billing timing.', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'pending', 'billing_timing' => 'now', 'incurred_on' => Billing::today() );
						if ( empty( $d['incurred_on'] ) ) {
							$d['incurred_on'] = Billing::today();
						}
					}
					$merged = array_merge( $existing ? $existing : array(), $d );
					if ( empty( $merged['currency'] ) ) {
						$d['currency'] = $merged['currency'] = Billing::client_currency( $merged['client_id'] );
					}
					if ( ! empty( $merged['client_service_id'] ) ) {
						global $wpdb;
						$cid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM ' . Schema::table( 'client_services' ) . ' WHERE id=%d', $merged['client_service_id'] ) ); // phpcs:ignore WordPress.DB
						if ( $cid && $cid !== (int) $merged['client_id'] ) {
							return new \WP_Error( 'bizymoney_invalid', 'That service belongs to a different client.', array( 'status' => 400 ) );
						}
					}
					// Recompute the bill date when timing / incurred date changed, or on create without an explicit custom date.
					$changed = ! $existing
						|| ( isset( $d['billing_timing'] ) && $d['billing_timing'] !== $existing['billing_timing'] )
						|| ( isset( $d['incurred_on'] ) && $d['incurred_on'] !== $existing['incurred_on'] )
						|| ( isset( $d['client_service_id'] ) && (int) $d['client_service_id'] !== (int) $existing['client_service_id'] );
					if ( $changed && 'custom' !== $merged['billing_timing'] ) {
						list( $date, $rid ) = Charges::resolve_bill_date( $merged );
						$d['bill_on_date']  = $date;
						$d['renewal_id']    = $rid;
					} elseif ( 'custom' === $merged['billing_timing'] ) {
						$d['renewal_id'] = 0;
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['margin']      = round( $r['price'] - $r['cost'], 2 );
						$r['bill_status'] = Billing::bill_status( $r, array( 'pending' ) );
					}
					return $rows;
				},
			),

			'invoices'        => array(
				'route'    => 'invoices',
				'table'    => 'bizymoney_invoices',
				'label'    => 'Invoice',
				'select'   => ', c.name AS client_name, dp.name AS department_name, (SELECT COALESCE(SUM(ii.cost),0) FROM ' . Schema::table( 'invoice_items' ) . ' ii WHERE ii.invoice_id=t.id) AS cost_total',
				'join'     => self::client_join() . ' LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array(
					'client_id' => 'i', 'invoice_number' => 's', 'title' => 's', 'description' => 't', 'amount' => 'd', 'currency' => 'c', 'issue_date' => 'date', 'due_date' => 'date',
					'status'    => 's', 'invoice_url' => 'u', 'attachment_id' => 'i', 'notes' => 't', 'terms' => 't',
					'subtotal'  => 'd', 'discount_type' => 's', 'discount_value' => 'd', 'discount_amount' => 'd', 'tax_rate' => 'd', 'tax_amount' => 'd',
					'amount_paid' => 'd', 'view_count' => 'i', 'department_id' => 'i',
				),
				'filters'  => array( 'client_id', 'status', 'currency' ),
				'search'   => array( 't.invoice_number', 't.title', 't.description', 'c.name' ),
				'order'    => 't.due_date IS NULL, t.due_date DESC, t.id DESC',
				'order_map' => array( 'client_name' => 'c.name' ),
				'required' => array( 'client_id' ),
				'title'    => function ( $r ) {
					return $r['invoice_number'] . ' — ' . $r['client_name'];
				},
				'extra'    => function ( $a ) {
					$sql  = array();
					$args = array();
					if ( ! empty( $a['unpaid'] ) ) {
						$sql[] = "t.status IN ('sent','due','partially_paid','overdue')";
					}
					if ( ! empty( $a['department_id'] ) ) {
						// An invoice belongs to a department by its own setting, or — when set "by line" — through any of its lines.
						$sql[]  = '(t.department_id=%d OR (t.department_id=0 AND EXISTS (SELECT 1 FROM ' . Schema::table( 'invoice_items' ) . ' dl WHERE dl.invoice_id=t.id AND dl.department_id=%d)))';
						$args[] = (int) $a['department_id'];
						$args[] = (int) $a['department_id'];
					}
					return array( implode( ' AND ', $sql ), $args );
				},
				'before_save' => function ( $d, $existing, $params ) {
					// Server-computed / system fields can never be set from the client.
					unset( $d['amount_paid'], $d['view_count'], $d['subtotal'], $d['discount_amount'], $d['tax_amount'] );
					if ( isset( $d['status'] ) && ! in_array( $d['status'], self::INVOICE_STATUSES, true ) ) {
						return new \WP_Error( 'bizymoney_invalid', 'Unknown invoice status.', array( 'status' => 400 ) );
					}
					if ( isset( $d['discount_type'] ) && ! in_array( $d['discount_type'], array( 'none', 'percent', 'fixed' ), true ) ) {
						$d['discount_type'] = 'none';
					}
					$client_id  = isset( $d['client_id'] ) ? $d['client_id'] : ( $existing ? $existing['client_id'] : 0 );
					$invoice_id = $existing ? $existing['id'] : 0;

					if ( ! $existing ) {
						$d += array( 'status' => 'draft', 'issue_date' => Billing::today(), 'terms' => (string) Settings::get( 'invoice_terms' ) );
						if ( empty( $d['invoice_number'] ) ) {
							$d['invoice_number'] = Invoices::next_number();
						}
					}
					if ( empty( $d['currency'] ) && ( ! $existing || ( isset( $d['currency'] ) && '' === $d['currency'] ) ) ) {
						$d['currency'] = Billing::client_currency( $client_id );
					}

					$total = isset( $d['amount'] ) ? (float) $d['amount'] : ( $existing ? (float) $existing['amount'] : 0.0 );
					if ( isset( $d['title'] ) ) {
						$d['title'] = mb_substr( trim( $d['title'] ), 0, 190 );
					}
					if ( isset( $params['items'] ) && is_array( $params['items'] ) ) {
						if ( ( isset( $d['title'] ) && '' === $d['title'] ) || ( ! $existing && ! isset( $d['title'] ) ) ) {
							$d['title'] = Invoices::default_title( Invoices::normalize_lines( $params['items'] ), isset( $d['issue_date'] ) ? $d['issue_date'] : ( $existing ? $existing['issue_date'] : '' ) );
						}
						$ok = Invoices::validate_lines( $client_id, $params['items'], $invoice_id );
						if ( is_wp_error( $ok ) ) {
							return $ok;
						}
						$type = isset( $d['discount_type'] ) ? $d['discount_type'] : ( $existing ? $existing['discount_type'] : 'none' );
						$val  = isset( $d['discount_value'] ) ? $d['discount_value'] : ( $existing ? $existing['discount_value'] : 0 );
						$rate = isset( $d['tax_rate'] ) ? $d['tax_rate'] : ( $existing ? $existing['tax_rate'] : 0 );
						$tot  = Invoices::compute_totals( Invoices::normalize_lines( $params['items'] ), $type, $val, $rate );
						$d['subtotal']        = $tot['subtotal'];
						$d['discount_amount'] = $tot['discount_amount'];
						$d['tax_amount']      = $tot['tax_amount'];
						$d['amount']          = $tot['total'];
						$total                = $tot['total'];
					} elseif ( ! $existing && isset( $d['amount'] ) ) {
						$d['subtotal'] = $d['amount']; // simple manual invoice
					}

					if ( isset( $params['installments'] ) && is_array( $params['installments'] ) ) {
						$norm = Invoices::normalize_installments( $params['installments'], $total );
						if ( is_wp_error( $norm ) ) {
							return $norm;
						}
						$last = $norm ? end( $norm ) : null;
						if ( $last && ! empty( $last['due_date'] ) ) {
							$d['due_date'] = $last['due_date']; // the invoice is fully due at its last instalment
						}
					}
					return $d;
				},
				'after_save' => function ( $row, $existing, $params ) {
					global $wpdb;
					if ( isset( $params['items'] ) && is_array( $params['items'] ) ) {
						Invoices::apply_lines( $row['id'], $params['items'] );
						Invoices::restamp_payments( $row['id'] ); // the mix of lines may have changed
					} elseif ( $existing && (int) $existing['department_id'] !== (int) $row['department_id'] ) {
						Invoices::restamp_lines( $row['id'] ); // attached to another department: the lines follow
					}
					if ( isset( $params['installments'] ) && is_array( $params['installments'] ) ) {
						$norm = Invoices::normalize_installments( $params['installments'], $row['amount'] );
						Invoices::save_installments( $row['id'], is_array( $norm ) ? $norm : array() );
					}
					// "Mark paid" from the status field: record the balance as a payment so the books agree.
					if ( 'paid' === $row['status'] && ( ! $existing || 'paid' !== $existing['status'] ) ) {
						$paid    = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Schema::table( 'payments' ) . ' WHERE invoice_id=%d', $row['id'] ) ); // phpcs:ignore WordPress.DB
						$balance = round( $row['amount'] - $paid, 2 );
						if ( $balance > 0 ) {
							$now = current_time( 'mysql' );
							$wpdb->insert( Schema::table( 'payments' ), array( 'invoice_id' => $row['id'], 'client_id' => $row['client_id'], 'amount' => $balance, 'currency' => $row['currency'], 'paid_on' => Billing::today(), 'reference' => 'Marked as paid', 'department_id' => Invoices::payment_department( $row['id'] ), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
						}
					}
					if ( 'cancelled' === $row['status'] && ( ! $existing || 'cancelled' !== $existing['status'] ) ) {
						Invoices::release_sources( $row['id'] );
					}
					if ( 'sent' === $row['status'] && empty( $row['sent_at'] ) ) {
						$wpdb->update( Schema::table( 'invoices' ), array( 'sent_at' => current_time( 'mysql' ) ), array( 'id' => $row['id'] ) );
					}
					Invoices::refresh_status( $row['id'] );
				},
				'decorate' => function ( $rows, $single ) {
					global $wpdb;
					foreach ( $rows as &$r ) {
						$r['balance']        = round( $r['amount'] - $r['amount_paid'], 2 );
						$cost_total          = isset( $r['cost_total'] ) ? (float) $r['cost_total'] : 0.0;
						$r['cost_total']     = $cost_total;
						$r['margin']         = round( ( (float) $r['amount'] - (float) $r['tax_amount'] ) - $cost_total, 2 ); // before tax; staff-only
						$r['attachment_url'] = $r['attachment_id'] ? wp_get_attachment_url( $r['attachment_id'] ) : '';
						$r['public_url']     = Invoices::public_url( isset( $r['public_token'] ) ? $r['public_token'] : '' );
						unset( $r['public_token'] );
						if ( $single ) {
							$r['items']    = $wpdb->get_results( $wpdb->prepare( 'SELECT it.*, s.name AS service_name FROM ' . Schema::table( 'invoice_items' ) . ' it LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=it.client_service_id LEFT JOIN ' . Schema::table( 'catalog' ) . ' s ON s.id=cs.service_id WHERE it.invoice_id=%d ORDER BY it.id', $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
							$r['payments'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE invoice_id=%d ORDER BY paid_on, id', $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
							$r['schedule'] = Invoices::schedule( $r );
							$r['has_installments'] = ! empty( $r['schedule'] ) && empty( $r['schedule'][0]['implicit'] );
						}
					}
					return $rows;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					Invoices::release_sources( $row['id'] );
					$wpdb->delete( Schema::table( 'invoice_items' ), array( 'invoice_id' => $row['id'] ) );
					$wpdb->delete( Schema::table( 'invoice_installments' ), array( 'invoice_id' => $row['id'] ) );
					$wpdb->delete( Schema::table( 'payments' ), array( 'invoice_id' => $row['id'] ) );
				},
			),

			'payments'        => array(
				'route'    => 'payments',
				'table'    => 'bizymoney_payments',
				'label'    => 'Payment',
				'select'   => ', i.invoice_number, c.name AS client_name, dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'invoices' ) . ' i ON i.id=t.invoice_id LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'invoice_id' => 'i', 'client_id' => 'i', 'amount' => 'd', 'currency' => 'c', 'paid_on' => 'date', 'reference' => 's', 'method' => 's', 'notes' => 't', 'department_id' => 'i' ),
				'filters'  => array( 'invoice_id', 'client_id', 'department_id' ),
				'search'   => array( 't.reference', 'i.invoice_number', 'c.name' ),
				'order_map' => array( 'client_name' => 'c.name' ),
				'order'    => 't.paid_on DESC, t.id DESC',
				'required' => array( 'invoice_id', 'amount' ),
				'title'    => function ( $r ) {
					return $r['invoice_number'] . ' (' . $r['amount'] . ' ' . $r['currency'] . ')';
				},
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( isset( $d['amount'] ) && $d['amount'] <= 0 ) {
						return new \WP_Error( 'bizymoney_invalid', 'Payment amount must be greater than zero.', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT client_id, currency FROM ' . Schema::table( 'invoices' ) . ' WHERE id=%d', isset( $d['invoice_id'] ) ? $d['invoice_id'] : 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB
						if ( ! $inv ) {
							return new \WP_Error( 'bizymoney_invalid', 'Choose a valid invoice.', array( 'status' => 400 ) );
						}
						$d['client_id'] = (int) $inv['client_id'];
						$d['currency']  = $inv['currency'];
						$d['department_id'] = Invoices::payment_department( $d['invoice_id'] ); // counts under the invoice's department
						if ( empty( $d['paid_on'] ) ) {
							$d['paid_on'] = Billing::today();
						}
					}
					return $d;
				},
				'after_save' => function ( $row, $existing ) {
					Invoices::refresh_status( $row['invoice_id'] );
					do_action( 'bizymoney_payment_saved', $row, $existing );
				},
				'after_delete' => function ( $row ) {
					Invoices::refresh_status( $row['invoice_id'] );
				},
			),

			'expenses'        => array(
				'route'    => 'expenses',
				'table'    => 'bizymoney_expenses',
				'label'    => 'Expense',
				'finance'  => true,
				'select'   => ', dp.name AS department_name, c.name AS client_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id=t.client_id',
				'cols'     => array( 'spent_on' => 'date', 'amount' => 'd', 'currency' => 'c', 'category' => 's', 'vendor' => 's', 'description' => 's', 'department_id' => 'i', 'client_id' => 'i', 'project_id' => 'i', 'method' => 's', 'reference' => 's', 'attachment_id' => 'i', 'status' => 's', 'notes' => 't' ),
				'filters'  => array( 'category', 'department_id', 'client_id', 'project_id', 'status', 'currency' ),
				'extra'    => function ( $a ) {
					$sql  = array();
					$args = array();
					if ( ! empty( $a['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a['from'] ) ) {
						$sql[]  = 't.spent_on >= %s';
						$args[] = $a['from'];
					}
					if ( ! empty( $a['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a['to'] ) ) {
						$sql[]  = 't.spent_on <= %s';
						$args[] = $a['to'];
					}
					return array( implode( ' AND ', $sql ), $args );
				},
				'search'   => array( 't.vendor', 't.description', 't.reference', 't.notes' ),
				'order'    => 't.spent_on DESC, t.id DESC',
				'order_map' => array( 'department_name' => 'dp.name' ),
				'required' => array( 'amount' ),
				'title'    => function ( $r ) {
					return trim( $r['vendor'] . ' ' . $r['description'] ) . ' (' . $r['amount'] . ' ' . $r['currency'] . ')';
				},
				'before_save' => function ( $d, $existing ) {
					if ( isset( $d['amount'] ) && (float) $d['amount'] <= 0 ) {
						return new \WP_Error( 'bizymoney_invalid', 'The amount must be greater than zero.', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'paid' );
						if ( empty( $d['spent_on'] ) ) {
							$d['spent_on'] = Billing::today();
						}
						if ( empty( $d['currency'] ) ) {
							$d['currency'] = Settings::get( 'default_currency' );
						}
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['attachment_url'] = $r['attachment_id'] ? wp_get_attachment_url( $r['attachment_id'] ) : '';
						$r['is_recurring']   = (int) $r['recurring_id'] > 0;
					}
					return $rows;
				},
			),

			'recurring-expenses' => array(
				'route'    => 'recurring-expenses',
				'table'    => 'bizymoney_recurring_expenses',
				'label'    => 'Recurring expense',
				'finance'  => true,
				'select'   => ', dp.name AS department_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'name' => 's', 'vendor' => 's', 'category' => 's', 'amount' => 'd', 'currency' => 'c', 'period' => 's', 'next_date' => 'date', 'department_id' => 'i', 'status' => 's', 'notes' => 't' ),
				'filters'  => array( 'category', 'department_id', 'status' ),
				'search'   => array( 't.name', 't.vendor' ),
				'order'    => "FIELD(t.status,'active','paused'), t.next_date ASC, t.id DESC",
				'required' => array( 'name', 'amount' ),
				'title'    => function ( $r ) {
					return $r['name'];
				},
				'before_save' => function ( $d, $existing ) {
					if ( isset( $d['amount'] ) && (float) $d['amount'] <= 0 ) {
						return new \WP_Error( 'bizymoney_invalid', 'The amount must be greater than zero.', array( 'status' => 400 ) );
					}
					if ( isset( $d['period'] ) && ( in_array( $d['period'], array( '', 'none', 'custom' ), true ) || Lookups::months_for( $d['period'] ) <= 0 ) ) {
						return new \WP_Error( 'bizymoney_invalid', 'Choose how often it repeats (monthly, quarterly, annually…).', array( 'status' => 400 ) );
					}
					if ( ! $existing ) {
						$d += array( 'status' => 'active', 'period' => 'monthly' );
						if ( empty( $d['next_date'] ) ) {
							$d['next_date'] = Billing::today();
						}
						if ( empty( $d['currency'] ) ) {
							$d['currency'] = Settings::get( 'default_currency' );
						}
					}
					return $d;
				},
				'decorate' => function ( $rows ) {
					$today = Billing::today();
					foreach ( $rows as &$r ) {
						$r['monthly_value'] = \BizyMoney\Services\Expenses::monthly_value( $r['amount'], $r['period'] );
						$r['days_until']    = $r['next_date'] ? (int) round( ( strtotime( $r['next_date'] ) - strtotime( $today ) ) / DAY_IN_SECONDS ) : null;
					}
					return $rows;
				},
			),

			'pay-allocations' => array(
				'route'    => 'pay-allocations',
				'table'    => 'bizymoney_pay_allocations',
				'label'    => 'Pay split',
				'finance'  => true,
				'select'   => ', m.name AS member_name, ph.team_budget AS base_budget, ph.name AS phase_name',
				'join'     => 'LEFT JOIN ' . Schema::table( 'team_members' ) . ' m ON m.id=t.team_member_id LEFT JOIN ' . Schema::ph() . " ph ON ph.id=t.scope_id AND t.scope='phase'",
				'cols'     => array( 'scope' => 's', 'scope_id' => 'i', 'team_member_id' => 'i', 'mode' => 's', 'value' => 'd', 'note' => 's' ),
				'filters'  => array( 'scope', 'scope_id', 'team_member_id' ),
				'order'    => 't.id ASC',
				'required' => array( 'scope_id', 'team_member_id' ),
				'title'    => function ( $r ) {
					return $r['member_name'] . ' — ' . $r['phase_name'];
				},
				'before_save' => function ( $d, $existing ) {
					global $wpdb;
					if ( isset( $d['mode'] ) && ! in_array( $d['mode'], array( 'fixed', 'percent' ), true ) ) {
						return new \WP_Error( 'bizymoney_invalid', 'Choose fixed amount or percent.', array( 'status' => 400 ) );
					}
					$mode  = isset( $d['mode'] ) ? $d['mode'] : ( $existing ? $existing['mode'] : 'fixed' );
					$value = isset( $d['value'] ) ? (float) $d['value'] : ( $existing ? (float) $existing['value'] : 0 );
					if ( $value < 0 || ( 'percent' === $mode && $value > 100 ) ) {
						return new \WP_Error( 'bizymoney_invalid', 'A percentage must be between 0 and 100.', array( 'status' => 400 ) );
					}
					$d['scope'] = 'phase';
					$sid = isset( $d['scope_id'] ) ? (int) $d['scope_id'] : ( $existing ? (int) $existing['scope_id'] : 0 );
					$mid = isset( $d['team_member_id'] ) ? (int) $d['team_member_id'] : ( $existing ? (int) $existing['team_member_id'] : 0 );
					$dup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'pay_allocations' ) . " WHERE scope='phase' AND scope_id=%d AND team_member_id=%d AND id<>%d", $sid, $mid, $existing ? (int) $existing['id'] : 0 ) ); // phpcs:ignore WordPress.DB
					if ( $dup ) {
						return new \WP_Error( 'bizymoney_invalid', 'That person already has a share in this phase — edit it instead.', array( 'status' => 409 ) );
					}
					if ( ! $existing ) {
						$d += array( 'mode' => 'fixed' );
					}
					return $d;
				},
				'after_save' => function ( $row ) {
					\BizyMoney\Services\Pay::sync_phase( $row['scope_id'] );
				},
				'before_delete' => function ( $row ) {
					global $wpdb;
					$paid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'pay_items' ) . " WHERE allocation_id=%d AND status='paid'", $row['id'] ) ); // phpcs:ignore WordPress.DB
					return $paid ? new \WP_Error( 'bizymoney_blocked', 'This share has already been paid. Void the payout first.', array( 'status' => 409 ) ) : true;
				},
				'after_delete' => function ( $row ) {
					global $wpdb;
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'pay_items' ) . " SET status='void' WHERE allocation_id=%d AND status='payable'", $row['id'] ) ); // phpcs:ignore WordPress.DB
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['amount'] = \BizyMoney\Services\Pay::allocation_amount( $r, array( 'team_budget' => $r['base_budget'] ) );
					}
					return $rows;
				},
			),

			'pay-items'       => array(
				'route'    => 'pay-items',
				'table'    => 'bizymoney_pay_items',
				'label'    => 'Earning',
				'finance'  => true,
				'select'   => ', m.name AS member_name, pj.name AS project_name, ph.name AS phase_name, po.number AS payout_number, tk.title AS task_title',
				'join'     => 'LEFT JOIN ' . Schema::table( 'team_members' ) . ' m ON m.id=t.team_member_id LEFT JOIN ' . Schema::table( 'projects' ) . ' pj ON pj.id=t.project_id LEFT JOIN ' . Schema::table( 'project_phases' ) . ' ph ON ph.id=t.phase_id LEFT JOIN ' . Schema::table( 'payouts' ) . ' po ON po.id=t.payout_id LEFT JOIN ' . Schema::table( 'tasks' ) . ' tk ON tk.id=t.task_id',
				'cols'     => array( 'team_member_id' => 'i', 'kind' => 's', 'project_id' => 'i', 'phase_id' => 'i', 'task_id' => 'i', 'period' => 's', 'description' => 's', 'amount' => 'd', 'currency' => 'c', 'earned_on' => 'date', 'status' => 's', 'department_id' => 'i' ),
				'filters'  => array( 'team_member_id', 'status', 'kind', 'project_id', 'phase_id', 'task_id', 'currency', 'department_id', 'period' ),
				'extra'    => function ( $a ) {
					$sql  = array();
					$args = array();
					if ( ! empty( $a['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a['from'] ) ) {
						$sql[]  = 't.earned_on >= %s';
						$args[] = $a['from'];
					}
					if ( ! empty( $a['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $a['to'] ) ) {
						$sql[]  = 't.earned_on <= %s';
						$args[] = $a['to'];
					}
					return array( implode( ' AND ', $sql ), $args );
				},
				'search'   => array( 't.description', 'm.name', 'pj.name' ),
				'order'    => "FIELD(t.status,'payable','paid','void'), t.earned_on DESC, t.id DESC",
				'order_map' => array( 'member_name' => 'm.name' ),
				'required' => array( 'team_member_id', 'amount' ),
				'title'    => function ( $r ) {
					return $r['member_name'] . ': ' . $r['description'];
				},
				'before_save' => function ( $d, $existing, $params = array() ) {
					global $wpdb;
					$manual = array( 'payment', 'bonus', 'deduction', 'reimbursement', 'adjustment' );
					if ( $existing ) {
						$generated = in_array( $existing['kind'], array( 'phase', 'task', 'salary' ), true );
						if ( 'payable' !== $existing['status'] ) {
							return new \WP_Error( 'bizymoney_blocked', 'Paid or voided items are never edited. Void the payout and re-create it instead.', array( 'status' => 409 ) );
						}
						if ( $generated ) {
							$keep = array();
							if ( isset( $d['status'] ) && 'void' === $d['status'] ) {
								$keep['status'] = 'void';
							}
							if ( ! $keep ) {
								return new \WP_Error( 'bizymoney_blocked', 'This earning was created automatically. Change the phase or task instead, or void it.', array( 'status' => 409 ) );
							}
							return $keep;
						}
					} else {
						$d += array( 'kind' => 'bonus' );
						if ( ! in_array( $d['kind'], $manual, true ) ) {
							return new \WP_Error( 'bizymoney_invalid', 'Only a payment, bonus, deduction, reimbursement or adjustment can be added by hand.', array( 'status' => 400 ) );
						}
						$d['status'] = 'payable';
						if ( empty( $d['earned_on'] ) ) {
							$d['earned_on'] = Billing::today();
						}
						if ( empty( $d['team_member_id'] ) || ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'team_members' ) . ' WHERE id=%d', (int) $d['team_member_id'] ) ) ) { // phpcs:ignore WordPress.DB
							return new \WP_Error( 'bizymoney_invalid', 'Pick the team member this payment is for.', array( 'status' => 400 ) );
						}
						// Optionally tied to a task: the project and phase follow the task.
						if ( ! empty( $d['task_id'] ) ) {
							$task = $wpdb->get_row( $wpdb->prepare( 'SELECT id, title, project_id, phase_id FROM ' . Schema::table( 'tasks' ) . ' WHERE id=%d', (int) $d['task_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
							if ( ! $task ) {
								return new \WP_Error( 'bizymoney_invalid', 'That task no longer exists.', array( 'status' => 400 ) );
							}
							$d['project_id'] = (int) $task['project_id'];
							$d['phase_id']   = (int) $task['phase_id'];
							if ( empty( $params['confirm_duplicate'] ) ) {
								$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'pay_items' ) . " WHERE task_id=%d AND status <> 'void'", (int) $task['id'] ) ); // phpcs:ignore WordPress.DB
								if ( $n ) {
									return new \WP_Error( 'task_already_paid', sprintf( '“%s” already has %d payment%s. Add another one?', $task['title'], $n, 1 === $n ? '' : 's' ), array( 'status' => 409 ) );
								}
							}
						} else {
							unset( $d['task_id'] );
						}
						if ( ! empty( $d['phase_id'] ) && empty( $d['project_id'] ) ) {
							$d['project_id'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT project_id FROM ' . Schema::table( 'project_phases' ) . ' WHERE id=%d', (int) $d['phase_id'] ) ); // phpcs:ignore WordPress.DB
						}
						if ( empty( $d['department_id'] ) ) {
							$d['department_id'] = Services\Pay::item_department( isset( $d['phase_id'] ) ? $d['phase_id'] : 0, isset( $d['project_id'] ) ? $d['project_id'] : 0, $d['team_member_id'] );
						}
						if ( empty( $d['currency'] ) ) {
							$cur = $wpdb->get_var( $wpdb->prepare( 'SELECT currency FROM ' . Schema::table( 'team_pay' ) . ' WHERE team_member_id=%d', (int) $d['team_member_id'] ) ); // phpcs:ignore WordPress.DB
							$d['currency'] = $cur ? $cur : Settings::get( 'default_currency' );
						}
					}
					if ( isset( $d['status'] ) && ! in_array( $d['status'], array( 'payable', 'void' ), true ) ) {
						unset( $d['status'] );
					}
					$kind = isset( $d['kind'] ) ? $d['kind'] : ( $existing ? $existing['kind'] : 'bonus' );
					if ( isset( $d['amount'] ) ) {
						$d['amount'] = 'deduction' === $kind ? -abs( (float) $d['amount'] ) : ( abs( (float) $d['amount'] ) * ( 'adjustment' === $kind && (float) $d['amount'] < 0 ? -1 : 1 ) );
					}
					return $d;
				},
				'before_delete' => function () {
					return new \WP_Error( 'bizymoney_blocked', 'Earnings are never deleted — void them instead so the books stay complete.', array( 'status' => 409 ) );
				},
				'decorate' => function ( $rows ) {
					foreach ( $rows as &$r ) {
						$r['is_generated'] = in_array( $r['kind'], array( 'phase', 'task', 'salary' ), true );
					}
					return $rows;
				},
			),

			'payouts'         => array(
				'route'    => 'payouts',
				'table'    => 'bizymoney_payouts',
				'label'    => 'Payout',
				'finance'  => true,
				'readonly' => true,
				'select'   => ', dp.name AS department_name, m.name AS member_name, (SELECT COUNT(*) FROM ' . Schema::table( 'pay_items' ) . ' pi WHERE pi.payout_id=t.id) AS item_count',
				'join'     => 'LEFT JOIN ' . Schema::table( 'team_members' ) . ' m ON m.id=t.team_member_id LEFT JOIN ' . Schema::table( 'brands' ) . ' dp ON dp.id=t.department_id',
				'cols'     => array( 'number' => 's', 'team_member_id' => 'i', 'paid_on' => 'date', 'method' => 's', 'reference' => 's', 'amount' => 'd', 'currency' => 'c', 'notes' => 't', 'status' => 's', 'department_id' => 'i' ),
				'filters'  => array( 'team_member_id', 'status', 'currency', 'department_id' ),
				'search'   => array( 't.number', 't.reference', 'm.name' ),
				'order'    => 't.paid_on DESC, t.id DESC',
				'order_map' => array( 'member_name' => 'm.name' ),
				'title'    => function ( $r ) {
					return $r['number'];
				},
				'decorate' => function ( $rows, $single ) {
					global $wpdb;
					foreach ( $rows as &$r ) {
						$r['item_count'] = (int) $r['item_count'];
						if ( $single ) {
							$r['items'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id, kind, description, amount, currency, earned_on FROM ' . Schema::table( 'pay_items' ) . ' WHERE payout_id=%d ORDER BY id', $r['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
						}
					}
					return $rows;
				},
			),

		);
		foreach ( $defs as $name => $cfg ) {
			if ( in_array( $name, Module::FINANCE_RESOURCES, true ) ) {
				$cfg['cap'] = 'bizymoney_finance'; // money about people and the business itself needs the finance capability
			}
			Resources::register( $name, $cfg, 'money' );
		}
	}
}
