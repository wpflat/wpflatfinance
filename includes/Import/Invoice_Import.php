<?php
namespace BizyMoney\Import;

use BizyMoney\Schema;
use BizyCore\Services\Activity;
use BizyMoney\Services\Invoices;
use BizyMoney\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Brings invoices exported from another platform into Bizy. Today: Invoice.ng (.xlsx or .csv).
 *
 * The export has totals only (no line items, no payment dates), so each invoice becomes one line, and an invoice
 * that was paid gets one payment dated the issue date. Original invoice numbers are kept and the numbering counter
 * is not touched. Every import is recorded, so it can be undone as a whole.
 */
class Invoice_Import {

	const OPTION = 'bizymoney_imports';

	const INVOICE_NG = array( 'Invoice_No', 'Customer_Name', 'Customer_Email', 'Total_Amount', 'Amount_Paid', 'Balance', 'Tax_Amount', 'Currency', 'Payment_Status', 'Issue_Date', 'Due_Date', 'Added_On' );

	/* ───────────────────────── Reading the file ───────────────────────── */

	/** @return array|\WP_Error list of associative rows keyed by header */
	public static function read_table( $path, $name ) {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'csv' === $ext ) {
			return self::read_csv( $path );
		}
		if ( 'xlsx' === $ext ) {
			return self::read_xlsx( $path );
		}
		return new \WP_Error( 'bd_import_type', 'Upload the .xlsx or .csv file exported from Invoice.ng.', array( 'status' => 400 ) );
	}

	private static function read_csv( $path ) {
		$h = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $h ) {
			return new \WP_Error( 'bd_import_io', 'Could not read the file.', array( 'status' => 400 ) );
		}
		$head = null;
		$rows = array();
		while ( false !== ( $r = fgetcsv( $h ) ) ) {
			if ( null === $head ) {
				$r[0]  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $r[0] );
				$head = array_map( 'trim', $r );
				continue;
			}
			if ( ! array_filter( $r, 'strlen' ) ) {
				continue;
			}
			$rows[] = array_combine( $head, array_pad( array_slice( $r, 0, count( $head ) ), count( $head ), '' ) );
		}
		fclose( $h );
		return $rows;
	}

	private static function read_xlsx( $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'bd_import_zip', 'This server cannot read .xlsx files (PHP zip is missing). Save the sheet as CSV and upload that.', array( 'status' => 400 ) );
		}
		$z = new \ZipArchive();
		if ( true !== $z->open( $path ) ) {
			return new \WP_Error( 'bd_import_io', 'That file is not a valid Excel workbook.', array( 'status' => 400 ) );
		}
		$ss = array();
		$raw = $z->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $raw ) {
			$x = simplexml_load_string( $raw );
			foreach ( $x->si as $si ) {
				$t = '';
				foreach ( $si->xpath( './/*[local-name()="t"]' ) as $n ) {
					$t .= (string) $n;
				}
				$ss[] = $t;
			}
		}
		$sheet = $z->getFromName( 'xl/worksheets/sheet1.xml' );
		$z->close();
		if ( false === $sheet ) {
			return new \WP_Error( 'bd_import_io', 'The workbook has no data sheet.', array( 'status' => 400 ) );
		}
		$x    = simplexml_load_string( $sheet );
		$head = null;
		$out  = array();
		foreach ( $x->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				$col = preg_replace( '/\d+/', '', (string) $c['r'] );
				$t   = (string) $c['t'];
				if ( 's' === $t ) {
					$v = $ss[ (int) $c->v ] ?? '';
				} elseif ( 'inlineStr' === $t ) {
					$v = (string) $c->is->t;
				} else {
					$v = isset( $c->v ) ? (string) $c->v : '';
				}
				$cells[ $col ] = $v;
			}
			if ( null === $head ) {
				$head = array_map( 'trim', $cells );
				continue;
			}
			if ( ! array_filter( $cells, 'strlen' ) ) {
				continue;
			}
			$assoc = array();
			foreach ( $head as $col => $h ) {
				$assoc[ $h ] = $cells[ $col ] ?? '';
			}
			$out[] = $assoc;
		}
		return $out;
	}

	private static function date( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		if ( preg_match( '/^\d{4,6}(\.\d+)?$/', $v ) && (float) $v > 20000 && (float) $v < 80000 ) { // Excel serial date
			return gmdate( 'Y-m-d', (int) round( ( (float) $v - 25569 ) * 86400 ) );
		}
		$t = strtotime( $v );
		return $t ? gmdate( 'Y-m-d', $t ) : '';
	}

	/** Strip a trailing currency tag such as “(USD)” from a customer name. */
	public static function clean_name( $n ) {
		$n = trim( preg_replace( '/\s+/', ' ', (string) $n ) );
		$c = trim( preg_replace( '/\s*\((?:USD|NGN|GBP|EUR|CAD|GHS|ZAR|KES)\)\s*$/i', '', $n ) );
		return '' === $c ? $n : $c;
	}

	private static function norm( $n ) {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '', self::clean_name( $n ) ) );
	}

	/** Detect the platform from the column headings and turn the file into normal rows. @return array|\WP_Error */
	public static function parse( $path, $name ) {
		$table = self::read_table( $path, $name );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		if ( ! $table ) {
			return new \WP_Error( 'bd_import_empty', 'The file has no invoice rows.', array( 'status' => 400 ) );
		}
		$h = array_keys( $table[0] );
		if ( in_array( 'Invoice_No', $h, true ) ) {
			return self::parse_invoice_ng( $table );
		}
		if ( count( array_intersect( array( 'Invoice', 'Status', 'Dates', 'Paid', 'Client' ), $h ) ) === 5 ) {
			return self::parse_sprout( $table );
		}
		return new \WP_Error( 'bd_import_format', 'This file was not recognised. Supported: Invoice.ng exports (.xlsx or .csv) and Sprout Invoices exports (.csv).', array( 'status' => 400 ) );
	}

	private static function money( $v ) {
		return round( (float) str_replace( ',', '', (string) $v ), 2 );
	}

	private static function parse_invoice_ng( array $table ) {
		$missing = array_diff( array( 'Invoice_No', 'Customer_Name', 'Total_Amount', 'Currency', 'Issue_Date' ), array_keys( $table[0] ) );
		if ( $missing ) {
			return new \WP_Error( 'bd_import_format', 'This does not look like an Invoice.ng export (missing column: ' . implode( ', ', $missing ) . ').', array( 'status' => 400 ) );
		}
		$rows   = array();
		$issues = array();
		foreach ( $table as $i => $r ) {
			$no    = trim( (string) $r['Invoice_No'] );
			$total = self::money( $r['Total_Amount'] );
			$issue = self::date( $r['Issue_Date'] );
			if ( '' === $no || '' === trim( (string) $r['Customer_Name'] ) || $total <= 0 || '' === $issue ) {
				$issues[] = 'Row ' . ( $i + 2 ) . ( '' !== $no ? ' (' . $no . ')' : '' ) . ' is missing a number, customer, amount or issue date and was skipped.';
				continue;
			}
			$paid   = self::money( $r['Amount_Paid'] ?? 0 );
			$rows[] = array(
				'ref'      => $no,
				'number'   => $no,
				'sid'      => '',
				'title'    => '',
				'name'     => self::clean_name( $r['Customer_Name'] ),
				'raw_name' => trim( (string) $r['Customer_Name'] ),
				'email'    => sanitize_email( (string) ( $r['Customer_Email'] ?? '' ) ),
				'site'     => '',
				'total'    => $total,
				'paid'     => min( max( $paid, 0 ), $total ),
				'tax'      => max( self::money( $r['Tax_Amount'] ?? 0 ), 0 ),
				'currency' => strtoupper( substr( trim( (string) $r['Currency'] ), 0, 3 ) ),
				'status'   => 'sent',
				'issue'    => $issue,
				'due'      => self::date( $r['Due_Date'] ?? '' ) ?: $issue,
				'added'    => self::date( $r['Added_On'] ?? '' ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $r['Added_On'] ) ) : $issue . ' 00:00:00',
			);
		}
		return array( 'source' => 'Invoice.ng', 'numbered' => true, 'rows' => $rows, 'issues' => $issues );
	}

	/** Sprout Invoices' list export: every cell is HTML, there is no invoice number, and the title is the description. */
	private static function parse_sprout( array $table ) {
		$sym    = array( '₦' => 'NGN', '$' => 'USD', '£' => 'GBP', '€' => 'EUR' );
		$status = array( 'Temp' => 'draft', 'Written Off' => 'cancelled' );
		$rows   = array();
		$issues = array();
		foreach ( $table as $i => $r ) {
			$cell = function ( $k ) use ( $r ) {
				return isset( $r[ $k ] ) ? (string) $r[ $k ] : '';
			};
			$sid   = preg_match( '/id="status_(\d+)"/', $cell( 'Status' ), $m ) ? $m[1] : '';
			$st    = preg_match( '/class="si_status[^"]*"[^>]*><span>([^<]*)/', $cell( 'Status' ), $m ) ? trim( html_entity_decode( $m[1] ) ) : '';
			$iss   = preg_match( '/Issued:\s*<b>([^<]*)/', $cell( 'Dates' ), $m ) ? self::date( $m[1] ) : '';
			$due   = preg_match( '/Due:\s*<b>([^<]*)/', $cell( 'Dates' ), $m ) ? self::date( $m[1] ) : '';
			$money = preg_match( '/([^\d\s]*)\s*([\d,]+\.\d+)\s*<span class="description">\(of\s*[^\d\s]*\s*([\d,]+\.\d+)/u', $cell( 'Paid' ), $m ) ? $m : null;
			if ( preg_match( '/<b><a [^>]*>([^<]*)<\/a><\/b>(?:<br\/><em>([^<]*))?/', $cell( 'Client' ), $c ) ) {
				$raw  = trim( html_entity_decode( $c[1], ENT_QUOTES, 'UTF-8' ) );
				$site = isset( $c[2] ) ? trim( html_entity_decode( $c[2] ) ) : '';
			} else {
				$raw  = '(No client)';
				$site = '';
			}
			$title = trim( html_entity_decode( wp_strip_all_tags( $cell( 'Invoice' ) ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $sid || ! $money || '' === $iss ) {
				$issues[] = 'Row ' . ( $i + 2 ) . ( '' !== $title ? ' (' . mb_substr( $title, 0, 40 ) . ')' : '' ) . ' could not be read and was skipped.';
				continue;
			}
			$total = self::money( $money[3] );
			$paid  = self::money( $money[2] );
			if ( $total <= 0 ) {
				$issues[] = 'Invoice ' . $sid . ' has no amount and was skipped.';
				continue;
			}
			// The currency sign on the screen is one global symbol; a "(USD)" tag in the client name is the real signal.
			$cur = preg_match( '/\((USD|NGN|GBP|EUR|CAD|GHS|ZAR|KES)\)\s*$/i', $raw, $t ) ? strtoupper( $t[1] ) : ( $sym[ $money[1] ] ?? 'NGN' );
			$s   = $status[ $st ] ?? 'sent';
			if ( 'Paid' === $st && $paid < $total - 0.004 ) {
				$issues[] = 'Invoice ' . $sid . ' is marked Paid in Sprout, but only ' . number_format( $paid, 2 ) . ' of ' . number_format( $total, 2 ) . ' was recorded. It is imported with the amount recorded, so it shows as partly paid.';
			}
			$rows[] = array(
				'ref' => 'SI:' . $sid, 'number' => '', 'sid' => $sid, 'title' => $title, 'name' => self::clean_name( $raw ), 'raw_name' => $raw, 'email' => '', 'site' => $site,
				'total' => $total, 'paid' => min( $paid, $total ), 'tax' => 0, 'currency' => $cur, 'status' => $s, 'issue' => $iss, 'due' => $due ?: $iss, 'added' => $iss . ' 00:00:00',
			);
		}
		return array( 'source' => 'Sprout Invoices', 'numbered' => false, 'rows' => $rows, 'issues' => $issues );
	}

	/* ───────────────────────── Preview ───────────────────────── */

	public static function preview( array $parsed ) {
		global $wpdb;
		$rows   = $parsed['rows'];
		$issues = $parsed['issues'];
		$cl  = Schema::table( 'clients' );
		$inv = Schema::table( 'invoices' );
		$existing = (array) $wpdb->get_results( "SELECT id, name, email FROM $cl ORDER BY name", ARRAY_A ); // phpcs:ignore WordPress.DB
		$by_norm  = array();
		$by_mail  = array();
		foreach ( $existing as $c ) {
			$by_norm[ self::norm( $c['name'] ) ] = (int) $c['id'];
			if ( '' !== $c['email'] ) {
				$by_mail[ strtolower( $c['email'] ) ] = (int) $c['id'];
			}
		}
		$have = array_flip( (array) $wpdb->get_col( "SELECT invoice_number FROM $inv" ) ); // phpcs:ignore WordPress.DB
		// Invoices already in Bizy, by client and amount, to spot the same invoice arriving from a second platform.
		$cand = array();
		foreach ( (array) $wpdb->get_results( "SELECT i.id, i.invoice_number, i.amount, i.issue_date, c.name FROM $inv i JOIN $cl c ON c.id = i.client_id", ARRAY_A ) as $e ) { // phpcs:ignore WordPress.DB
			$cand[ self::norm( $e['name'] ) . '|' . number_format( (float) $e['amount'], 2, '.', '' ) ][] = $e;
		}
		$used  = array();
		$cust  = array();
		$dupes = array();
		$maybe = array();
		$sums  = array();
		$kinds = array( 'sent' => 0, 'draft' => 0, 'cancelled' => 0 );
		foreach ( $rows as $r ) {
			if ( '' !== $r['number'] && isset( $have[ $r['number'] ] ) ) {
				$dupes[] = $r['number'];
				continue;
			}
			$k = self::norm( $r['name'] );
			$key = $k . '|' . number_format( $r['total'], 2, '.', '' );
			foreach ( $cand[ $key ] ?? array() as $e ) {
				$diff = abs( ( strtotime( $r['issue'] ) - strtotime( (string) $e['issue_date'] ) ) / DAY_IN_SECONDS );
				if ( $diff <= 7 && ! isset( $used[ $e['id'] ] ) ) {
					$used[ $e['id'] ] = true;
					$maybe[] = array( 'ref' => $r['ref'], 'label' => ( '' !== $r['number'] ? $r['number'] : ( $r['title'] ?: $r['ref'] ) ), 'client' => $r['name'], 'amount' => $r['total'], 'currency' => $r['currency'], 'issue' => $r['issue'], 'existing_number' => $e['invoice_number'], 'existing_date' => $e['issue_date'] );
					break;
				}
			}
			$kinds[ $r['status'] ] = ( $kinds[ $r['status'] ] ?? 0 ) + 1;
			if ( ! isset( $cust[ $k ] ) ) {
				$cust[ $k ] = array( 'key' => $k, 'name' => $r['name'], 'raw_names' => array(), 'email' => '', 'site' => '', 'invoices' => 0, 'currencies' => array(), 'total' => array() );
			}
			$c = &$cust[ $k ];
			$c['raw_names'][ $r['raw_name'] ] = true;
			$c['email'] = $c['email'] ? $c['email'] : $r['email'];
			$c['site']  = $c['site'] ? $c['site'] : $r['site'];
			$c['invoices']++;
			$c['currencies'][ $r['currency'] ] = ( $c['currencies'][ $r['currency'] ] ?? 0 ) + 1;
			$c['total'][ $r['currency'] ]      = round( ( $c['total'][ $r['currency'] ] ?? 0 ) + $r['total'], 2 );
			$sums[ $r['currency'] ]            = round( ( $sums[ $r['currency'] ] ?? 0 ) + $r['total'], 2 );
			unset( $c );
		}
		$mails = array();
		foreach ( $cust as $c ) {
			if ( $c['email'] ) {
				$mails[ strtolower( $c['email'] ) ][] = $c['name'];
			}
		}
		foreach ( $cust as &$c ) {
			arsort( $c['currencies'] );
			$c['currency']  = (string) key( $c['currencies'] );
			$c['raw_names'] = array_keys( $c['raw_names'] );
			$match = $by_norm[ $c['key'] ] ?? ( $c['email'] ? ( $by_mail[ strtolower( $c['email'] ) ] ?? 0 ) : 0 );
			$c['match']        = (int) $match;
			$c['shared_email'] = ( $c['email'] && count( $mails[ strtolower( $c['email'] ) ] ) > 1 ) ? array_values( array_diff( $mails[ strtolower( $c['email'] ) ], array( $c['name'] ) ) ) : array();
			if ( '(No client)' === $c['name'] ) {
				$c['name']  = 'Unassigned (from ' . $parsed['source'] . ')';
				$c['match'] = 0;
			}
		}
		unset( $c );
		$existing_list = array_map( function ( $c ) {
			return array( 'id' => (int) $c['id'], 'name' => $c['name'] );
		}, $existing );
		return array(
			'source'     => $parsed['source'],
			'numbered'   => $parsed['numbered'],
			'invoices'   => count( $rows ) - count( $dupes ),
			'duplicates' => $dupes,
			'maybe_duplicates' => $maybe,
			'kinds'      => $kinds,
			'issues'     => $issues,
			'customers'  => array_values( $cust ),
			'totals'     => $sums,
			'clients'    => $existing_list,
		);
	}

	/* ───────────────────────── Run ───────────────────────── */

	/** Create clients and invoices for one batch of rows. @return array|\WP_Error */
	public static function run( array $rows, array $plan, $import_id = '' ) {
		global $wpdb;
		$cl   = Schema::table( 'clients' );
		$inv  = Schema::table( 'invoices' );
		$it   = Schema::table( 'invoice_items' );
		$pay  = Schema::table( 'payments' );
		$cb   = Schema::table( 'client_brands' );
		$dept = (int) ( $plan['department_id'] ?? 0 );
		$now  = current_time( 'mysql' );
		$uid  = get_current_user_id();
		$imports = get_option( self::OPTION, array() );
		$imports = is_array( $imports ) ? $imports : array();
		if ( '' === $import_id || ! isset( $imports[ $import_id ] ) ) {
			$import_id = bin2hex( random_bytes( 6 ) );
			$imports[ $import_id ] = array( 'id' => $import_id, 'source' => (string) ( $plan['source'] ?? 'Invoice.ng' ), 'created_at' => $now, 'clients' => array(), 'invoices' => array(), 'new_by_name' => array(), 'skipped' => 0 );
		}
		$rec = $imports[ $import_id ];
		$targets = (array) ( $plan['targets'] ?? array() );
		$source  = (string) ( $plan['source'] ?? 'Invoice.ng' );
		$prefix  = (string) ( $plan['number_prefix'] ?? 'SI-' );
		$made = 0;
		$skipped = 0;
		$errors = array();
		foreach ( $rows as $r ) {
			$r['number'] = '' !== $r['number'] ? $r['number'] : $prefix . $r['sid'];
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $inv WHERE invoice_number=%s", $r['number'] ) ) ) { // phpcs:ignore WordPress.DB
				$skipped++;
				continue;
			}
			$key = self::norm( $r['name'] );
			$t   = $targets[ $key ] ?? array( 'mode' => 'new', 'name' => $r['name'], 'email' => $r['email'] );
			if ( 'existing' === ( $t['mode'] ?? '' ) && (int) $t['id'] > 0 ) {
				$client_id = (int) $t['id'];
			} else {
				$cname = trim( (string) ( $t['name'] ?? $r['name'] ) );
				$ck    = strtolower( $cname );
				if ( isset( $rec['new_by_name'][ $ck ] ) ) {
					$client_id = (int) $rec['new_by_name'][ $ck ];
				} else {
					$wpdb->insert( $cl, array( 'name' => $cname, 'email' => (string) ( $t['email'] ?? $r['email'] ), 'currency' => $r['currency'], 'status' => 'active', 'notes' => 'Imported from ' . $source . ( ! empty( $t['site'] ) ? "\nWebsite: " . $t['site'] : '' ), 'created_by' => $uid, 'created_at' => $now, 'updated_at' => $now ) );
					$client_id = (int) $wpdb->insert_id;
					if ( ! $client_id ) {
						$errors[] = 'Could not create client ' . $cname;
						continue;
					}
					if ( $dept ) {
						$wpdb->insert( $cb, array( 'client_id' => $client_id, 'department_id' => $dept, 'is_primary' => 1 ) );
					}
					$rec['new_by_name'][ $ck ] = $client_id;
					$rec['clients'][]          = $client_id;
				}
			}
			$sub  = round( $r['total'] - $r['tax'], 2 );
			$rate = $sub > 0 ? round( $r['tax'] / $sub * 100, 2 ) : 0;
			$desc = mb_substr( '' !== trim( (string) ( $r['title'] ?? '' ) ) ? $r['title'] : 'Invoice ' . $r['number'] . ' — ' . ( $t['name'] ?? $r['name'] ), 0, 190 );
			$kind = $r['status'] ?? 'sent';
			$ok   = $wpdb->insert( $inv, array(
				'client_id' => $client_id, 'invoice_number' => $r['number'], 'title' => $desc, 'amount' => $r['total'], 'currency' => $r['currency'],
				'issue_date' => $r['issue'], 'due_date' => $r['due'], 'status' => 'sent', 'amount_paid' => 0, 'subtotal' => $sub, 'discount_type' => 'none',
				'tax_rate' => $rate, 'tax_amount' => $r['tax'], 'notes' => 'Imported from ' . $source, 'created_by' => $uid, 'created_at' => $r['added'], 'updated_at' => $now, 'department_id' => $dept,
			) );
			if ( false === $ok ) {
				$errors[] = 'Could not save ' . $r['number'] . ': ' . $wpdb->last_error;
				continue;
			}
			$id = (int) $wpdb->insert_id;
			$wpdb->insert( $it, array( 'invoice_id' => $id, 'source_type' => 'manual', 'kind' => 'fixed', 'description' => $desc, 'quantity' => 1, 'unit_price' => $sub, 'amount' => $sub, 'department_id' => $dept ) );
			Invoices::ensure_token( $id );
			if ( $r['paid'] > 0 && 'sent' === $kind ) {
				$wpdb->insert( $pay, array( 'invoice_id' => $id, 'client_id' => $client_id, 'amount' => $r['paid'], 'currency' => $r['currency'], 'paid_on' => $r['issue'], 'reference' => 'Imported from ' . $source, 'method' => '', 'department_id' => $dept, 'created_by' => $uid, 'created_at' => $r['issue'] . ' 00:00:00', 'updated_at' => $now ) );
			}
			if ( 'draft' === $kind || 'cancelled' === $kind ) {
				$wpdb->update( $inv, array( 'status' => $kind ), array( 'id' => $id ) );
			} else {
				Invoices::refresh_status( $id );
			}
			$rec['invoices'][] = $id;
			$made++;
		}
		$rec['skipped'] += $skipped;
		$imports[ $import_id ] = $rec;
		update_option( self::OPTION, $imports, false );
		return array( 'import_id' => $import_id, 'created' => $made, 'skipped' => $skipped, 'errors' => $errors, 'total_invoices' => count( $rec['invoices'] ), 'total_clients' => count( $rec['clients'] ) );
	}

	/** Called once after the last batch. */
	public static function finish( $import_id ) {
		$imports = get_option( self::OPTION, array() );
		if ( isset( $imports[ $import_id ] ) ) {
			$r = $imports[ $import_id ];
			Activity::log( 'invoices', 0, 0, 'import', sprintf( 'Imported %d invoices and %d new clients from %s', count( $r['invoices'] ), count( $r['clients'] ), $r['source'] ) );
		}
	}

	public static function history() {
		$out = array();
		foreach ( (array) get_option( self::OPTION, array() ) as $r ) {
			$out[] = array( 'id' => $r['id'], 'source' => $r['source'], 'created_at' => $r['created_at'], 'invoices' => count( $r['invoices'] ), 'clients' => count( $r['clients'] ), 'skipped' => (int) $r['skipped'] );
		}
		return array_reverse( $out );
	}

	/** Remove exactly what one import created. */
	public static function undo( $import_id ) {
		global $wpdb;
		$imports = get_option( self::OPTION, array() );
		if ( ! isset( $imports[ $import_id ] ) ) {
			return new \WP_Error( 'bd_import_missing', 'That import was not found.', array( 'status' => 404 ) );
		}
		$r   = $imports[ $import_id ];
		$ids = array_map( 'intval', $r['invoices'] );
		if ( $ids ) {
			$in = implode( ',', $ids );
			foreach ( array( 'payments', 'invoice_items', 'invoice_installments' ) as $t ) {
				$wpdb->query( 'DELETE FROM ' . Schema::table( $t ) . " WHERE invoice_id IN ($in)" ); // phpcs:ignore WordPress.DB
			}
			$wpdb->query( 'DELETE FROM ' . Schema::table( 'invoices' ) . " WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB
		}
		$removed = 0;
		foreach ( array_map( 'intval', $r['clients'] ) as $cid ) {
			$used = 0;
			foreach ( array( 'invoices', 'client_services', 'charges', 'projects', 'contracts' ) as $t ) {
				$used += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( $t ) . ' WHERE client_id=%d', $cid ) ); // phpcs:ignore WordPress.DB
			}
			if ( 0 === $used ) {
				$wpdb->delete( Schema::table( 'client_brands' ), array( 'client_id' => $cid ) );
				$wpdb->delete( Schema::table( 'client_contacts' ), array( 'client_id' => $cid ) );
				$wpdb->delete( Schema::table( 'clients' ), array( 'id' => $cid ) );
				$removed++;
			}
		}
		unset( $imports[ $import_id ] );
		update_option( self::OPTION, $imports, false );
		Activity::log( 'invoices', 0, 0, 'import_undo', sprintf( 'Undid an import: removed %d invoices and %d clients', count( $ids ), $removed ) );
		return array( 'invoices' => count( $ids ), 'clients' => $removed );
	}
}
