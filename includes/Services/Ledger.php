<?php
namespace WPFlatFinance\Services;

use WPFlatFinance\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Chronological money timeline — for a whole client, or for one client service.
 * Event: { type, id, date, label, sub, amount, currency, status, invoice_id, client_service_id }.
 */
class Ledger {

	private static function sort( array $events, $limit ) {
		usort( $events, function ( $a, $b ) {
			return strcmp( (string) $b['date'], (string) $a['date'] ) ?: strcmp( $b['type'] . $b['id'], $a['type'] . $a['id'] );
		} );
		return array_slice( $events, 0, $limit );
	}

	public static function for_client( $client_id, $limit = 200 ) {
		global $wpdb;
		$client_id = (int) $client_id;
		$cs = Schema::table( 'client_services' );
		$sv = Schema::table( 'catalog' );
		$ev = array();

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT r.*, s.name AS service_name FROM ' . Schema::table( 'renewals' ) . " r LEFT JOIN $cs cs ON cs.id=r.client_service_id LEFT JOIN $sv s ON s.id=cs.service_id WHERE r.client_id=%d", $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$ev[] = array( 'type' => 'renewal', 'id' => (int) $r['id'], 'date' => $r['due_date'], 'label' => ( '' !== (string) $r['subject'] ? $r['service_name'] . ' — ' . $r['subject'] : $r['service_name'] . ' renewal' ), 'sub' => $r['frequency'], 'amount' => (float) $r['price'], 'currency' => $r['currency'], 'status' => $r['status'], 'invoice_id' => (int) $r['invoice_id'], 'client_service_id' => (int) $r['client_service_id'] );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ch.*, s.name AS service_name FROM ' . Schema::table( 'charges' ) . " ch LEFT JOIN $cs cs ON cs.id=ch.client_service_id LEFT JOIN $sv s ON s.id=IF(ch.service_id>0, ch.service_id, cs.service_id) WHERE ch.client_id=%d", $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$ev[] = array( 'type' => 'charge', 'id' => (int) $r['id'], 'date' => $r['incurred_on'], 'label' => $r['description'], 'sub' => (string) $r['service_name'], 'amount' => (float) $r['price'], 'currency' => $r['currency'], 'status' => $r['status'], 'invoice_id' => (int) $r['invoice_id'], 'client_service_id' => (int) $r['client_service_id'] );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'invoices' ) . ' WHERE client_id=%d', $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$ev[] = array( 'type' => 'invoice', 'id' => (int) $r['id'], 'date' => $r['issue_date'], 'label' => 'Invoice ' . $r['invoice_number'], 'sub' => 'due ' . $r['due_date'], 'amount' => (float) $r['amount'], 'currency' => $r['currency'], 'status' => $r['status'], 'invoice_id' => (int) $r['id'], 'client_service_id' => 0 );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT p.*, i.invoice_number FROM ' . Schema::table( 'payments' ) . ' p LEFT JOIN ' . Schema::table( 'invoices' ) . ' i ON i.id=p.invoice_id WHERE p.client_id=%d', $client_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$ev[] = array( 'type' => 'payment', 'id' => (int) $r['id'], 'date' => $r['paid_on'], 'label' => 'Payment received', 'sub' => trim( $r['invoice_number'] . ' ' . $r['reference'] ), 'amount' => (float) $r['amount'], 'currency' => $r['currency'], 'status' => 'paid', 'invoice_id' => (int) $r['invoice_id'], 'client_service_id' => 0 );
		}
		return self::sort( $ev, $limit );
	}

	public static function for_service( $client_service_id, $limit = 200 ) {
		global $wpdb;
		$id = (int) $client_service_id;
		$ev = array();

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'renewals' ) . ' WHERE client_service_id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$ev[] = array( 'type' => 'renewal', 'id' => (int) $r['id'], 'date' => $r['due_date'], 'label' => 'Renewal', 'sub' => $r['renewed_on'] ? 'renewed ' . $r['renewed_on'] : '', 'amount' => (float) $r['price'], 'currency' => $r['currency'], 'status' => $r['status'], 'invoice_id' => (int) $r['invoice_id'], 'client_service_id' => $id );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'charges' ) . ' WHERE client_service_id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$ev[] = array( 'type' => 'charge', 'id' => (int) $r['id'], 'date' => $r['incurred_on'], 'label' => $r['description'], 'sub' => '', 'amount' => (float) $r['price'], 'currency' => $r['currency'], 'status' => $r['status'], 'invoice_id' => (int) $r['invoice_id'], 'client_service_id' => $id );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT it.*, i.invoice_number, i.issue_date, i.status AS invoice_status, i.currency FROM ' . Schema::table( 'invoice_items' ) . ' it JOIN ' . Schema::table( 'invoices' ) . ' i ON i.id=it.invoice_id WHERE it.client_service_id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			$period = $r['period_start'] ? $r['period_start'] . ' → ' . $r['period_end'] : '';
			$ev[]   = array( 'type' => 'invoice_line', 'id' => (int) $r['id'], 'date' => $r['issue_date'], 'label' => 'Invoiced on ' . $r['invoice_number'], 'sub' => $period, 'amount' => (float) $r['amount'], 'currency' => $r['currency'], 'status' => $r['invoice_status'], 'invoice_id' => (int) $r['invoice_id'], 'client_service_id' => $id );
		}
		return self::sort( $ev, $limit );
	}
}
