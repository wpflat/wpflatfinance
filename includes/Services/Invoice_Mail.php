<?php
namespace WPFlatFinance\Services;

use WPFlatCore\Services\Activity;
use WPFlatCore\Services\Contacts;
use WPFlatCore\Services\Mailer;

use WPFlatFinance\Schema;
use WPFlatCore\Mail_Settings;
use WPFlatCore\Resources;
use WPFlatFinance\Settings;

defined( 'ABSPATH' ) || exit;

/** Composes, previews, tests and sends invoice emails; logs every attempt. */
class Invoice_Mail {

	const MAX_RECIPIENTS = 25;

	/* ───────────────────────── Text helpers ───────────────────────── */

	public static function human_join( array $names ) {
		$names = array_values( array_filter( array_map( 'trim', $names ) ) );
		$n     = count( $names );
		if ( 0 === $n ) {
			return 'there';
		}
		if ( 1 === $n ) {
			return $names[0];
		}
		return implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . $names[ $n - 1 ];
	}

	/** First names only, so "Ada Obi" greets as "Ada". */
	private static function first_names( array $names ) {
		return array_map( function ( $n ) {
			$parts = preg_split( '/\s+/', trim( $n ) );
			return $parts ? $parts[0] : '';
		}, $names );
	}

	public static function tokens( array $inv, ?array $client = null, array $to_names = array() ) {
		$s       = Settings::get();
		$balance = max( 0, round( (float) $inv['amount'] - (float) $inv['amount_paid'], 2 ) );
		return array(
			'client_name'    => $client ? $client['name'] : '',
			'contact_name'   => self::human_join( self::first_names( $to_names ) ),
			'invoice_number' => $inv['invoice_number'],
			'invoice_title'  => isset( $inv['title'] ) ? (string) $inv['title'] : '',
			'amount_due'     => Email_Templates::money( $balance > 0 ? $balance : $inv['amount'], $inv['currency'] ),
			'total'          => Email_Templates::money( $inv['amount'], $inv['currency'] ),
			'due_date'       => Email_Templates::date( $inv['due_date'] ),
			'issue_date'     => Email_Templates::date( $inv['issue_date'] ),
			'business_name'  => $s['business_name'],
			'invoice_link'   => Invoices::public_url( Invoices::ensure_token( $inv['id'] ) ),
		);
	}

	public static function fill( $tpl, array $tokens ) {
		return preg_replace_callback( '/\{\{\s*(\w+)\s*\}\}/', function ( $m ) use ( $tokens ) {
			return isset( $tokens[ $m[1] ] ) ? $tokens[ $m[1] ] : $m[0];
		}, (string) $tpl );
	}

	/* ───────────────────────── Data ───────────────────────── */

	private static function load( $invoice_id ) {
		global $wpdb;
		$inv = Resources::repo( 'invoices' )->find( (int) $invoice_id );
		if ( ! $inv ) {
			return null;
		}
		$client = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'clients' ) . ' WHERE id=%d', $inv['client_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items  = $wpdb->get_results( $wpdb->prepare( 'SELECT it.*, s.name AS service_name FROM ' . Schema::table( 'invoice_items' ) . ' it LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=it.client_service_id LEFT JOIN ' . Schema::table( 'catalog' ) . ' s ON s.id=cs.service_id WHERE it.invoice_id=%d ORDER BY it.id', $inv['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array( 'inv' => $inv, 'client' => $client, 'items' => (array) $items, 'schedule' => Invoices::schedule( $inv ) );
	}

	/** What the send dialog should pre-fill. */
	public static function defaults( $invoice_id ) {
		$d = self::load( $invoice_id );
		if ( ! $d ) {
			return null;
		}
		$rec  = Contacts::recipients( $d['inv']['client_id'] );
		$team = Contacts::team();
		$to_emails = array_map( 'strtolower', wp_list_pluck( $rec['to'], 'email' ) );

		$cc = array();
		foreach ( $rec['cc'] as $c ) {
			$cc[] = $c + array( 'source' => 'contact' );
		}
		foreach ( $team as $t ) {
			if ( (int) $t['cc_documents'] && ! in_array( strtolower( $t['email'] ), $to_emails, true ) ) {
				$cc[] = array( 'name' => $t['name'], 'email' => $t['email'], 'job_title' => $t['role'], 'source' => 'team' );
			}
		}
		// De-dupe cc by email.
		$seen = array();
		$cc   = array_values( array_filter( $cc, function ( $c ) use ( &$seen ) {
			$k = strtolower( $c['email'] );
			if ( isset( $seen[ $k ] ) ) {
				return false;
			}
			$seen[ $k ] = true;
			return true;
		} ) );

		$m      = array_merge( Mail_Settings::get_full(), array_intersect_key( \WPFlatFinance\Settings::get(), array_flip( array( "invoice_subject", "invoice_message" ) ) ) );
		$tokens = self::tokens( $d['inv'], $d['client'], wp_list_pluck( $rec['to'], 'name' ) );
		$user   = wp_get_current_user();
		return array(
			'invoice_number' => $d['inv']['invoice_number'],
			'status'         => $d['inv']['status'],
			'client_name'    => $d['client'] ? $d['client']['name'] : '',
			'to'             => $rec['to'],
			'cc'             => $cc,
			'contacts'       => array_merge( $rec['to'], array_map( function ( $c ) {
				return array( 'name' => $c['name'], 'email' => $c['email'], 'job_title' => $c['job_title'] );
			}, Contacts::for_client( $d['inv']['client_id'] ) ) ),
			'team'           => array_map( function ( $t ) {
				return array( 'name' => $t['name'], 'email' => $t['email'], 'job_title' => $t['role'] );
			}, $team ),
			'subject'        => self::fill( $m['invoice_subject'], $tokens ),
			'message'        => self::fill( $m['invoice_message'], $tokens ),
			'from'           => Mail_Settings::from(),
			'method'         => $m['method'],
			'bcc_self'       => (bool) $m['bcc_self'],
			'user_email'     => $user ? $user->user_email : '',
			'link'           => $tokens['invoice_link'],
		);
	}

	/** Render the email exactly as it will be sent. */
	public static function render( $invoice_id, $subject, $message, array $to_names = array(), $test_note = '' ) {
		$d = self::load( $invoice_id );
		if ( ! $d ) {
			return null;
		}
		$tokens = self::tokens( $d['inv'], $d['client'], $to_names );
		$out    = Email_Templates::invoice(
			array(
				'inv'       => $d['inv'],
				'client'    => $d['client'],
				'items'     => $d['items'],
				'schedule'  => $d['schedule'],
				'message'   => self::fill( $message, $tokens ),
				'link'      => $tokens['invoice_link'],
				'test_note' => $test_note,
				'business'  => Settings::get(),
			)
		);
		$out['subject'] = ( $test_note ? '[Test] ' : '' ) . self::fill( $subject, $tokens );
		return $out;
	}

	/* ───────────────────────── Sending ───────────────────────── */

	/** @return array|\WP_Error Clean list of { name, email }. */
	public static function clean_list( $list ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $list as $item ) {
			$email = sanitize_email( is_array( $item ) ? ( isset( $item['email'] ) ? $item['email'] : '' ) : (string) $item );
			$name  = is_array( $item ) && isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
			$raw   = is_array( $item ) ? ( isset( $item['email'] ) ? $item['email'] : '' ) : (string) $item;
			if ( '' === $email || ! is_email( $email ) ) {
				return new \WP_Error( 'wpflatfinance_invalid', sprintf( '“%s” isn’t a valid email address.', sanitize_text_field( $raw ) ), array( 'status' => 400 ) );
			}
			$k = strtolower( $email );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = array( 'name' => $name, 'email' => $email );
		}
		return $out;
	}

	private static function addr( array $list ) {
		return wp_list_pluck( $list, 'email' );
	}

	private static function log( $inv, $type, array $to, array $cc, array $bcc, $subject, array $res ) {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'email_log' ),
			array(
				'invoice_id' => (int) $inv['id'],
				'client_id'  => (int) $inv['client_id'],
				'type'       => $type,
				'to_emails'  => implode( ', ', self::addr( $to ) ),
				'cc_emails'  => implode( ', ', self::addr( $cc ) ),
				'bcc_emails' => implode( ', ', self::addr( $bcc ) ),
				'subject'    => mb_substr( $subject, 0, 255 ),
				'status'     => $res['ok'] ? ( 'test' === $type ? 'test' : 'sent' ) : 'failed',
				'error'      => $res['ok'] ? '' : $res['error'],
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Send the invoice (or, with $test_to, a test copy to that single address).
	 *
	 * @param array $p to[], cc[], bcc[], subject, message, bcc_self.
	 * @return array|\WP_Error
	 */
	public static function send( $invoice_id, array $p, $test_to = '' ) {
		$d = self::load( $invoice_id );
		if ( ! $d ) {
			return new \WP_Error( 'wpflatfinance_not_found', 'Invoice not found.', array( 'status' => 404 ) );
		}
		$inv = $d['inv'];
		if ( 'cancelled' === $inv['status'] && ! $test_to ) {
			return new \WP_Error( 'wpflatfinance_invalid', 'A cancelled invoice can’t be sent.', array( 'status' => 400 ) );
		}

		$to  = self::clean_list( isset( $p['to'] ) ? $p['to'] : array() );
		$cc  = self::clean_list( isset( $p['cc'] ) ? $p['cc'] : array() );
		$bcc = self::clean_list( isset( $p['bcc'] ) ? $p['bcc'] : array() );
		foreach ( array( $to, $cc, $bcc ) as $l ) {
			if ( is_wp_error( $l ) ) {
				return $l;
			}
		}
		$subject = isset( $p['subject'] ) ? (string) $p['subject'] : '';
		$message = isset( $p['message'] ) ? (string) $p['message'] : '';
		if ( '' === trim( $subject ) ) {
			return new \WP_Error( 'wpflatfinance_invalid', 'Add a subject line.', array( 'status' => 400 ) );
		}

		if ( $test_to ) {
			$real_to   = $to;
			$test      = self::clean_list( array( $test_to ) );
			if ( is_wp_error( $test ) ) {
				return $test;
			}
			$note = sprintf( 'Nothing was sent to %s. This is how the invoice will look.', $real_to ? implode( ', ', self::addr( $real_to ) ) : 'your client' );
			$r    = self::render( $invoice_id, $subject, $message, wp_list_pluck( $real_to, 'name' ), $note );
			$res  = Mailer::send( array( 'to' => self::addr( $test ), 'subject' => $r['subject'], 'html' => $r['html'], 'text' => $r['text'] ) );
			self::log( $inv, 'test', $test, array(), array(), $r['subject'], $res );
			if ( $res['ok'] ) {
				Activity::log( 'invoices', $inv['id'], $inv['client_id'], 'test_email', sprintf( 'Test email for invoice %s sent to %s', $inv['invoice_number'], $test[0]['email'] ) );
			}
			return $res + array( 'to' => self::addr( $test ), 'test' => true );
		}

		if ( ! $to ) {
			return new \WP_Error( 'wpflatfinance_invalid', 'Add at least one recipient in “To”. Give the client a primary contact with an email address.', array( 'status' => 400 ) );
		}
		if ( count( $to ) + count( $cc ) + count( $bcc ) > self::MAX_RECIPIENTS ) {
			return new \WP_Error( 'wpflatfinance_invalid', 'Too many recipients (max ' . self::MAX_RECIPIENTS . ').', array( 'status' => 400 ) );
		}
		if ( ! empty( $p['bcc_self'] ) ) {
			$me = wp_get_current_user();
			if ( $me && is_email( $me->user_email ) ) {
				$bcc[] = array( 'name' => $me->display_name, 'email' => $me->user_email );
			}
		}
		// A recipient listed in To must not also appear in Cc/Bcc.
		$in_to = array_map( 'strtolower', self::addr( $to ) );
		$cc    = array_values( array_filter( $cc, function ( $c ) use ( $in_to ) {
			return ! in_array( strtolower( $c['email'] ), $in_to, true );
		} ) );
		$bcc   = array_values( array_filter( $bcc, function ( $c ) use ( $in_to ) {
			return ! in_array( strtolower( $c['email'] ), $in_to, true );
		} ) );

		$r   = self::render( $invoice_id, $subject, $message, wp_list_pluck( $to, 'name' ) );
		$res = Mailer::send( array( 'to' => self::addr( $to ), 'cc' => self::addr( $cc ), 'bcc' => self::addr( $bcc ), 'subject' => $r['subject'], 'html' => $r['html'], 'text' => $r['text'] ) );
		self::log( $inv, 'invoice', $to, $cc, $bcc, $r['subject'], $res );

		if ( $res['ok'] ) {
			Invoices::mark_sent( $inv['id'] );
			Activity::log( 'invoices', $inv['id'], $inv['client_id'], 'emailed', sprintf( 'Invoice %s emailed to %s%s', $inv['invoice_number'], implode( ', ', self::addr( $to ) ), $cc ? ' (cc ' . implode( ', ', self::addr( $cc ) ) . ')' : '' ) );
		} else {
			Activity::log( 'invoices', $inv['id'], $inv['client_id'], 'email_failed', sprintf( 'Emailing invoice %s failed: %s', $inv['invoice_number'], $res['error'] ) );
		}
		return $res + array( 'to' => self::addr( $to ), 'cc' => self::addr( $cc ) );
	}

	/** The next date money is expected (the first unpaid instalment, else the invoice due date) and what is owed by then. */
	public static function next_due( array $inv ) {
		foreach ( Invoices::schedule( $inv ) as $row ) {
			if ( $row['balance'] > 0.004 && ! empty( $row['due_date'] ) ) {
				return array( 'date' => $row['due_date'], 'amount' => $row['balance'], 'label' => $row['implicit'] ? '' : $row['label'] );
			}
		}
		return array( 'date' => $inv['due_date'], 'amount' => max( 0, (float) $inv['amount'] - (float) $inv['amount_paid'] ), 'label' => '' );
	}

	/**
	 * Email a payment reminder for an unpaid invoice to the same people the invoice goes to (main contact in To, other
	 * contacts and flagged team members in Cc). The wording comes from Settings (reminder_subject / reminder_message).
	 *
	 * @param array $over Optional to[], cc[] to use instead of the client's contacts.
	 * @return array|\WP_Error
	 */
	public static function send_reminder( $invoice_id, array $over = array() ) {
		$d = self::load( $invoice_id );
		if ( ! $d ) {
			return new \WP_Error( 'wpflatfinance_not_found', 'Invoice not found.', array( 'status' => 404 ) );
		}
		$inv = $d['inv'];
		if ( (float) $inv['amount'] - (float) $inv['amount_paid'] <= 0.004 || in_array( $inv['status'], array( 'draft', 'cancelled', 'paid' ), true ) ) {
			return new \WP_Error( 'wpflatfinance_invalid', 'Only a sent invoice with a balance still to pay can be reminded.', array( 'status' => 400 ) );
		}
		$def = self::defaults( $invoice_id );
		$to  = self::clean_list( isset( $over['to'] ) ? $over['to'] : $def['to'] );
		$cc  = self::clean_list( isset( $over['cc'] ) ? $over['cc'] : $def['cc'] );
		foreach ( array( $to, $cc ) as $l ) {
			if ( is_wp_error( $l ) ) {
				return $l;
			}
		}
		if ( ! $to ) {
			return new \WP_Error( 'wpflatfinance_invalid', 'This client has no contact with an email address to remind.', array( 'status' => 400 ) );
		}
		$in_to = array_map( 'strtolower', self::addr( $to ) );
		$cc    = array_values( array_filter( $cc, function ( $c ) use ( $in_to ) {
			return ! in_array( strtolower( $c['email'] ), $in_to, true );
		} ) );

		$s      = Settings::get();
		$next   = self::next_due( $inv );
		$ymd    = current_time( 'Y-m-d' );
		$late   = $next['date'] && $next['date'] < $ymd;
		$phrase = $late ? 'was due on ' . Email_Templates::date( $next['date'] ) : ( $next['date'] === $ymd ? 'is due today' : 'is due on ' . Email_Templates::date( $next['date'] ) );
		$names  = wp_list_pluck( $to, 'name' );
		$tokens = array( 'due_phrase' => $phrase, 'amount_due' => Email_Templates::money( $next['amount'], $inv['currency'] ), 'due_date' => Email_Templates::date( $next['date'] ) ) + self::tokens( $inv, $d['client'], $names );
		$r      = Email_Templates::invoice(
			array(
				'inv'       => $inv,
				'client'    => $d['client'],
				'items'     => $d['items'],
				'schedule'  => $d['schedule'],
				'message'   => self::fill( $s['reminder_message'], $tokens ),
				'link'      => $tokens['invoice_link'],
				'test_note' => '',
				'business'  => $s,
			)
		);
		$subject = self::fill( $s['reminder_subject'], $tokens );
		$res     = Mailer::send( array( 'to' => self::addr( $to ), 'cc' => self::addr( $cc ), 'subject' => $subject, 'html' => $r['html'], 'text' => $r['text'] ) );
		self::log( $inv, 'reminder', $to, $cc, array(), $subject, $res );
		if ( $res['ok'] ) {
			Activity::log( 'invoices', $inv['id'], $inv['client_id'], 'reminded', sprintf( 'Reminder for invoice %s emailed to %s%s', $inv['invoice_number'], implode( ', ', self::addr( $to ) ), $cc ? ' (cc ' . implode( ', ', self::addr( $cc ) ) . ')' : '' ) );
		}
		return $res + array( 'to' => self::addr( $to ), 'cc' => self::addr( $cc ), 'subject' => $subject );
	}

	public static function history( $invoice_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'email_log' ) . ' WHERE invoice_id=%d ORDER BY id DESC LIMIT 50', $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as &$r ) {
			$u              = $r['user_id'] ? get_userdata( $r['user_id'] ) : null;
			$r['user_name'] = $u ? $u->display_name : '';
			$r['id']        = (int) $r['id'];
		}
		return (array) $rows;
	}
}
