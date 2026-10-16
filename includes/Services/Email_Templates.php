<?php
namespace BizyFinance\Services;

use BizyFinance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * HTML + plain-text email layouts. Table-based with inline styles so they render in Gmail, Outlook and Apple Mail.
 * Palette follows the Bizy UI: deep ink #222530, neutral greys, status colours only for state.
 */
class Email_Templates {

	const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

	public static function money( $amount, $currency ) {
		$sym = $currency;
		foreach ( (array) Settings::get( 'currencies' ) as $c ) {
			if ( $c['code'] === $currency ) {
				$sym = $c['symbol'];
				break;
			}
		}
		return $sym . number_format( (float) $amount, 2 );
	}

	public static function date( $d ) {
		return $d ? wp_date( get_option( 'date_format' ), strtotime( $d . ' 12:00:00' ) ) : '—';
	}

	/** Plain text → email-safe HTML paragraphs. */
	private static function paragraphs( $text ) {
		$out = '';
		foreach ( preg_split( "/\n{2,}/", trim( (string) $text ) ) as $p ) {
			if ( '' === trim( $p ) ) {
				continue;
			}
			$out .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#525866;">' . nl2br( esc_html( trim( $p ) ) ) . '</p>';
		}
		return $out;
	}

	private static function pill( $label, $tone ) {
		$map = array(
			'ok'   => array( '#e0faec', '#0b4627' ),
			'warn' => array( '#fffaeb', '#624c18' ),
			'bad'  => array( '#ffebec', '#681219' ),
			'info' => array( '#ebf1ff', '#122368' ),
			'mute' => array( '#f2f5f8', '#222530' ),
		);
		list( $bg, $fg ) = isset( $map[ $tone ] ) ? $map[ $tone ] : $map['mute'];
		return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:' . $bg . ';color:' . $fg . ';font-size:12px;font-weight:600;line-height:16px;">' . esc_html( $label ) . '</span>';
	}

	/**
	 * @param array $d inv (row), client (row|null), items[], schedule[], message (plain), link, test_note (string|''), business (settings array)
	 * @return array{html: string, text: string, preheader: string}
	 */
	public static function invoice( array $d ) {
		$inv      = $d['inv'];
		$s        = $d['business'];
		$cur      = $inv['currency'];
		$balance  = max( 0, round( (float) $inv['amount'] - (float) $inv['amount_paid'], 2 ) );
		$due_amt  = $balance > 0 ? $balance : (float) $inv['amount'];
		$has_plan = ! empty( $d['schedule'] ) && empty( $d['schedule'][0]['implicit'] );
		$ink      = '#222530';
		$line     = '#e8ebf0';
		$font     = self::FONT;

		$status_map = array(
			'paid'           => array( 'Paid', 'ok' ),
			'partially_paid' => array( 'Partially paid', 'warn' ),
			'overdue'        => array( 'Overdue', 'bad' ),
			'due'            => array( 'Due today', 'warn' ),
		);
		$st = isset( $status_map[ $inv['status'] ] ) ? $status_map[ $inv['status'] ] : array( 'Awaiting payment', 'info' );

		$preheader = sprintf( '%s due %s — invoice %s from %s', self::money( $due_amt, $cur ), self::date( $inv['due_date'] ), $inv['invoice_number'], $s['business_name'] );

		// ── header ──
		$brand = ! empty( $s['business_logo_email_url'] )
			? '<img src="' . esc_url( $s['business_logo_email_url'] ) . '" alt="' . esc_attr( $s['business_name'] ) . '" height="36" style="display:block;border:0;max-height:36px;max-width:180px;height:auto;">'
			: '<span style="font-size:18px;font-weight:700;letter-spacing:-0.02em;color:' . $ink . ';">' . esc_html( $s['business_name'] ) . '</span>';

		// ── line items (grouped: fixed-price services, then quantity items) ──
		$rows  = '';
		$all   = array_values( array_filter( (array) $d['items'], 'is_array' ) );
		$fixed = array_values( array_filter( $all, function ( $i ) {
			return 'quantity' !== ( isset( $i['kind'] ) ? $i['kind'] : 'fixed' );
		} ) );
		$qtys  = array_values( array_filter( $all, function ( $i ) {
			return 'quantity' === ( isset( $i['kind'] ) ? $i['kind'] : 'fixed' );
		} ) );
		$items = array_slice( array_merge( $fixed, $qtys ), 0, 15 );
		$both  = $fixed && $qtys;
		$prev  = '';
		foreach ( $items as $it ) {
			$kind = 'quantity' === ( isset( $it['kind'] ) ? $it['kind'] : 'fixed' ) ? 'quantity' : 'fixed';
			if ( $both && $kind !== $prev ) {
				$rows .= '<tr><td colspan="2" style="padding:' . ( $prev ? '14px 0 0' : '0' ) . ';"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:#eceff4;border-radius:8px;padding:9px 12px;font-family:' . $font . ';font-size:11.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:' . $ink . ';">' . ( 'fixed' === $kind ? 'Fixed price' : 'By quantity' ) . ' <span style="font-weight:500;letter-spacing:0;text-transform:none;color:#525866;">· ' . ( 'fixed' === $kind ? 'one price, no quantity' : 'quantity × unit price' ) . '</span></td></tr></table></td></tr>';
			}
			$prev = $kind;
			$sub  = array();
			if ( ! empty( $it['service_name'] ) && 0 !== strcasecmp( trim( $it['service_name'] ), trim( $it['description'] ) ) ) {
				$sub[] = $it['service_name'];
			}
			if ( 'quantity' === $kind ) {
				$sub[] = rtrim( rtrim( number_format( (float) $it['quantity'], 2 ), '0' ), '.' ) . ' × ' . self::money( $it['unit_price'], $cur );
			}
			if ( ! empty( $it['period_start'] ) ) {
				$sub[] = self::date( $it['period_start'] ) . ' – ' . self::date( $it['period_end'] );
			}
			$rows .= '<tr><td style="padding:12px 0;border-bottom:1px solid ' . $line . ';font-family:' . $font . ';font-size:14px;color:' . $ink . ';"><div style="font-weight:600;">' . esc_html( $it['description'] ) . '</div>'
				. ( $sub ? '<div style="font-size:12px;color:#868e9d;margin-top:2px;">' . esc_html( implode( ' · ', $sub ) ) . '</div>' : '' )
				. '</td><td align="right" style="padding:12px 0 12px 16px;border-bottom:1px solid ' . $line . ';font-family:' . $font . ';font-size:14px;font-weight:600;color:' . $ink . ';white-space:nowrap;vertical-align:top;">' . esc_html( self::money( $it['amount'], $cur ) ) . '</td></tr>';
		}
		if ( ! $items ) {
			$rows = '<tr><td style="padding:12px 0;border-bottom:1px solid ' . $line . ';font-family:' . $font . ';font-size:14px;font-weight:600;color:' . $ink . ';">' . esc_html( $inv['description'] ? $inv['description'] : 'Services' ) . '</td><td align="right" style="padding:12px 0;border-bottom:1px solid ' . $line . ';font-family:' . $font . ';font-size:14px;font-weight:600;color:' . $ink . ';">' . esc_html( self::money( $inv['amount'], $cur ) ) . '</td></tr>';
		}
		$more = count( $all ) - count( $items );
		if ( $more > 0 ) {
			$rows .= '<tr><td colspan="2" style="padding:10px 0;font-family:' . $font . ';font-size:12px;color:#868e9d;">+ ' . (int) $more . ' more item' . ( 1 === $more ? '' : 's' ) . ' on the full invoice</td></tr>';
		}

		// ── totals ──
		$tot = function ( $label, $value, $strong = false ) use ( $font, $ink ) {
			return '<tr><td style="padding:5px 0;font-family:' . $font . ';font-size:' . ( $strong ? '15' : '13' ) . 'px;color:' . ( $strong ? $ink : '#525866' ) . ';font-weight:' . ( $strong ? '700' : '400' ) . ';">' . esc_html( $label ) . '</td><td align="right" style="padding:5px 0;font-family:' . $font . ';font-size:' . ( $strong ? '15' : '13' ) . 'px;color:' . ( $strong ? $ink : '#525866' ) . ';font-weight:' . ( $strong ? '700' : '500' ) . ';">' . esc_html( $value ) . '</td></tr>';
		};
		$totals = $tot( 'Subtotal', self::money( $inv['subtotal'] > 0 ? $inv['subtotal'] : $inv['amount'], $cur ) );
		if ( (float) $inv['discount_amount'] > 0 ) {
			$totals .= $tot( 'Discount', '−' . self::money( $inv['discount_amount'], $cur ) );
		}
		if ( (float) $inv['tax_amount'] > 0 ) {
			$totals .= $tot( $s['tax_label'] . ' (' . (float) $inv['tax_rate'] . '%)', self::money( $inv['tax_amount'], $cur ) );
		}
		$totals .= $tot( 'Total', self::money( $inv['amount'], $cur ), true );
		if ( (float) $inv['amount_paid'] > 0 ) {
			$totals .= $tot( 'Paid', '−' . self::money( $inv['amount_paid'], $cur ) ) . $tot( 'Balance due', self::money( $balance, $cur ), true );
		}

		// ── instalment schedule ──
		$sched = '';
		if ( $has_plan ) {
			$state_tone  = array( 'paid' => 'ok', 'partial' => 'warn', 'overdue' => 'bad', 'due' => 'warn', 'upcoming' => 'mute' );
			$state_label = array( 'paid' => 'Paid', 'partial' => 'Part-paid', 'overdue' => 'Overdue', 'due' => 'Due today', 'upcoming' => 'Upcoming' );
			$sched      .= '<tr><td style="padding:28px 0 8px;font-family:' . $font . ';font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#868e9d;">Payment schedule</td></tr><tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
			foreach ( $d['schedule'] as $r ) {
				$sched .= '<tr><td style="padding:10px 0;border-bottom:1px solid ' . $line . ';font-family:' . $font . ';font-size:13px;color:' . $ink . ';"><div style="font-weight:600;">' . esc_html( $r['label'] ) . '</div><div style="font-size:12px;color:#868e9d;">Due ' . esc_html( self::date( $r['due_date'] ) ) . '</div></td>'
					. '<td align="right" style="padding:10px 0;border-bottom:1px solid ' . $line . ';font-family:' . $font . ';font-size:13px;color:' . $ink . ';"><div style="font-weight:600;">' . esc_html( self::money( $r['balance'] > 0 ? $r['balance'] : $r['amount'], $cur ) ) . '</div><div style="margin-top:3px;">' . self::pill( $state_label[ $r['state'] ], $state_tone[ $r['state'] ] ) . '</div></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			$sched .= '</table></td></tr>';
		}

		// ── how to pay ──
		$pay     = '';
		$methods = array();
		if ( 'paid' !== $inv['status'] && 'cancelled' !== $inv['status'] ) {
			$methods = Settings::payment_methods( $cur, array( 'invoice_number' => $inv['invoice_number'], 'amount' => number_format( $balance, 2, '.', '' ), 'currency' => $cur ) );
			$cells   = '';
			foreach ( $methods as $m ) {
				$title = '' !== $m['title'] ? $m['title'] : ( 'bank' === $m['type'] ? 'Bank transfer' : 'Pay online' );
				if ( 'link' === $m['type'] ) {
					$cells .= '<tr><td style="padding:0 0 10px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;"><tr><td style="padding:14px 16px;font-family:' . $font . ';font-size:13px;color:' . $ink . ';"><div style="font-weight:650;font-size:14px;">' . esc_html( $title ) . '</div>' . ( $m['note'] ? '<div style="color:#525866;margin-top:2px;">' . esc_html( $m['note'] ) . '</div>' : '' ) . '<div style="margin-top:10px;"><a href="' . esc_url( $m['url'] ) . '" style="display:inline-block;background:#222530;color:#ffffff;text-decoration:none;font-weight:600;font-size:13px;padding:9px 16px;border-radius:8px;">' . esc_html( $m['button_label'] ? $m['button_label'] : 'Pay ' . self::money( $balance, $cur ) ) . ' →</a></div></td></tr></table></td></tr>';
				} elseif ( 'bank' === $m['type'] ) {
					$bank_rows = array_filter(
						array(
							'Bank'           => $m['bank_name'],
							'Account name'   => $m['account_name'],
							'Account number' => $m['account_number'],
							( $m['extra_label'] ? $m['extra_label'] : 'Reference' ) => $m['extra_value'],
						),
						'strlen'
					);
					$kv = '';
					foreach ( $bank_rows as $k => $v ) {
						$kv .= '<tr><td style="padding:4px 0;font-family:' . $font . ';font-size:12.5px;color:#868e9d;">' . esc_html( $k ) . '</td><td align="right" style="padding:4px 0;font-family:' . $font . ';font-size:13px;font-weight:600;color:' . $ink . ';">' . esc_html( $v ) . '</td></tr>';
					}
					$cells .= '<tr><td style="padding:0 0 10px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;"><tr><td style="padding:14px 16px;font-family:' . $font . ';"><div style="font-weight:650;font-size:14px;color:' . $ink . ';margin-bottom:6px;">' . esc_html( $title ) . ( $m['currency'] ? ' <span style="font-size:11px;font-weight:700;color:#525866;background:#f5f7fa;padding:2px 7px;border-radius:99px;">' . esc_html( $m['currency'] ) . '</span>' : '' ) . '</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $kv . '</table></td></tr></table></td></tr>';
				} else {
					$cells .= '<tr><td style="padding:0 0 10px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;"><tr><td style="padding:14px 16px;font-family:' . $font . ';font-size:13px;line-height:1.6;color:#525866;"><div style="font-weight:650;font-size:14px;color:' . $ink . ';">' . esc_html( $title ) . '</div>' . nl2br( esc_html( $m['note'] ) ) . '</td></tr></table></td></tr>';
				}
			}
			if ( $cells || $s['payment_instructions'] ) {
				$note = $s['payment_instructions'] ? '<tr><td style="padding:2px 2px 0;font-family:' . $font . ';font-size:13px;line-height:1.6;color:#525866;">' . nl2br( esc_html( $s['payment_instructions'] ) ) . '</td></tr>' : '';
				$pay  = '<tr><td style="padding:28px 0 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;border-radius:12px;"><tr><td style="padding:18px 18px 8px;"><div style="font-family:' . $font . ';font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#868e9d;">How to pay</div><div style="font-family:' . $font . ';font-size:13px;color:#525866;margin:4px 0 12px;">Please use <strong>' . esc_html( $inv['invoice_number'] ) . '</strong> as your payment reference.</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $cells . $note . '</table></td></tr><tr><td style="height:8px;line-height:8px;font-size:0;">&nbsp;</td></tr></table></td></tr>';
			}
		}

		$test = $d['test_note']
			? '<tr><td style="padding:0 0 14px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffaeb;border-radius:10px;"><tr><td style="padding:12px 16px;font-family:' . $font . ';font-size:13px;line-height:1.5;color:#624c18;"><strong>Test email.</strong> ' . esc_html( $d['test_note'] ) . '</td></tr></table></td></tr>'
			: '';

		$billed_to = $d['client'] ? $d['client']['name'] : '';
		$cta_label = $balance > 0 ? 'View & pay invoice' : 'View invoice';

		$footer_lines = array_filter( array( $s['business_name'], str_replace( "\n", ', ', (string) $s['business_address'] ), implode( ' · ', array_filter( array( $s['business_email'], $s['business_phone'] ) ) ), $s['business_website'] ? preg_replace( '#^https?://#', '', $s['business_website'] ) : '' ) );

		ob_start();
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<title><?php echo esc_html( 'Invoice ' . $inv['invoice_number'] . ( ! empty( $inv['title'] ) ? ' — ' . $inv['title'] : '' ) ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f7fa;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;"><?php echo esc_html( $preheader ); ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#f5f7fa" style="background:#f5f7fa;">
<tr><td align="center" style="padding:32px 14px;">
	<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;">
		<?php echo $test; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<tr><td style="padding:0 4px 18px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
				<td align="left"><?php echo $brand; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				<td align="right" style="font-family:<?php echo $font; ?>;font-size:13px;font-weight:600;color:#868e9d;"><?php echo esc_html( $inv['invoice_number'] ); ?></td>
			</tr></table>
		</td></tr>
		<tr><td style="background:#ffffff;border-radius:16px;border:1px solid <?php echo $line; ?>;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
				<tr><td style="padding:34px 36px 0;">
					<?php if ( ! empty( $inv['title'] ) ) : ?><div style="font-family:<?php echo $font; ?>;font-size:17px;line-height:1.35;font-weight:650;letter-spacing:-0.01em;color:<?php echo $ink; ?>;margin-bottom:18px;"><?php echo esc_html( $inv['title'] ); ?></div><?php endif; ?>
					<div style="font-family:<?php echo $font; ?>;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#868e9d;"><?php echo $balance > 0 ? 'Amount due' : 'Invoice total'; ?></div>
					<div style="font-family:<?php echo $font; ?>;font-size:38px;line-height:1.15;font-weight:700;letter-spacing:-0.03em;color:<?php echo $ink; ?>;margin-top:6px;"><?php echo esc_html( self::money( $due_amt, $cur ) ); ?></div>
					<div style="font-family:<?php echo $font; ?>;font-size:14px;color:#525866;margin-top:8px;">Due <?php echo esc_html( self::date( $inv['due_date'] ) ); ?> &nbsp; <?php echo self::pill( $st[0], $st[1] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				</td></tr>
				<tr><td style="padding:26px 36px 0;font-family:<?php echo $font; ?>;"><?php echo self::paragraphs( $d['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><td align="left" style="padding:6px 36px 6px;">
					<table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="<?php echo $ink; ?>" style="border-radius:10px;background:<?php echo $ink; ?>;">
						<a href="<?php echo esc_url( $d['link'] ); ?>" style="display:inline-block;padding:14px 26px;font-family:<?php echo $font; ?>;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;"><?php echo esc_html( $cta_label ); ?> &rarr;</a>
					</td></tr></table>
					<div style="font-family:<?php echo $font; ?>;font-size:12px;color:#868e9d;margin-top:12px;">Print it or save it as a PDF from the invoice page.</div>
				</td></tr>
				<tr><td style="padding:26px 36px 34px;">
					<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid <?php echo $line; ?>;">
						<tr><td style="padding:22px 0 6px;">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
								<td valign="top" width="34%" style="font-family:<?php echo $font; ?>;"><div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#868e9d;">Billed to</div><div style="font-size:14px;font-weight:600;color:<?php echo $ink; ?>;margin-top:4px;"><?php echo esc_html( $billed_to ); ?></div></td>
								<td valign="top" width="33%" style="font-family:<?php echo $font; ?>;"><div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#868e9d;">Issued</div><div style="font-size:14px;font-weight:600;color:<?php echo $ink; ?>;margin-top:4px;"><?php echo esc_html( self::date( $inv['issue_date'] ) ); ?></div></td>
								<td valign="top" width="33%" style="font-family:<?php echo $font; ?>;"><div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#868e9d;">Invoice</div><div style="font-size:14px;font-weight:600;color:<?php echo $ink; ?>;margin-top:4px;"><?php echo esc_html( $inv['invoice_number'] ); ?></div></td>
							</tr></table>
						</td></tr>
						<tr><td style="padding-top:14px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput ?></table></td></tr>
						<tr><td style="padding-top:10px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><?php echo $totals; // phpcs:ignore WordPress.Security.EscapeOutput ?></table></td></tr>
						<?php echo $sched; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php echo $pay; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</table>
				</td></tr>
			</table>
		</td></tr>
		<tr><td align="center" style="padding:22px 20px 0;font-family:<?php echo $font; ?>;font-size:12px;line-height:1.7;color:#868e9d;">
			<?php echo esc_html( implode( ' · ', $footer_lines ) ); ?><br>
			<?php echo esc_html( $s['invoice_footer'] ); ?>
		</td></tr>
	</table>
</td></tr>
</table>
</body>
</html>
		<?php
		$html = ob_get_clean();

		// ── plain text ──
		$text  = ( $d['test_note'] ? '[TEST EMAIL] ' . $d['test_note'] . "\n\n" : '' );
		$text .= $d['message'] . "\n\n";
		if ( ! empty( $inv['title'] ) ) {
			$text .= 'Invoice for: ' . $inv['title'] . "\n";
		}
		$text .= sprintf( "%s: %s (due %s)\n", $balance > 0 ? 'Amount due' : 'Total', self::money( $due_amt, $cur ), self::date( $inv['due_date'] ) );
		$text .= 'View the invoice: ' . $d['link'] . "\n\n";
		foreach ( (array) $d['items'] as $it ) {
			$text .= sprintf( "- %s: %s\n", $it['description'], self::money( $it['amount'], $cur ) );
		}
		if ( ! empty( $methods ) ) {
			$text .= "\nHow to pay (reference " . $inv['invoice_number'] . "):\n";
			foreach ( $methods as $m ) {
				$bits  = 'link' === $m['type'] ? array( $m['url'] ) : array_filter( array( $m['bank_name'], $m['account_name'], $m['account_number'], $m['extra_value'], $m['note'] ), 'strlen' );
				$text .= '- ' . ( $m['title'] ? $m['title'] . ': ' : '' ) . implode( ', ', $bits ) . "\n";
			}
		}
		$text .= "\n" . implode( ' | ', $footer_lines ) . "\n";

		return array( 'html' => $html, 'text' => $text, 'preheader' => $preheader );
	}

	/** Simple "it works" message for the SMTP test. */
	public static function smtp_test( array $business, $via ) {
		$font = self::FONT;
		$name = esc_html( $business['business_name'] );
		$html = '<!doctype html><html><body style="margin:0;padding:32px 14px;background:#f5f7fa;"><table role="presentation" width="520" cellpadding="0" cellspacing="0" align="center" style="max-width:100%;background:#fff;border-radius:16px;border:1px solid #e8ebf0;"><tr><td style="padding:34px;font-family:' . $font . ';color:#525866;font-size:15px;line-height:1.65;"><div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#868e9d;">Bizy</div><div style="font-size:24px;font-weight:700;letter-spacing:-0.02em;color:#222530;margin:6px 0 12px;">Email is working ✓</div>This is a test message from ' . $name . '. Sent via <strong>' . esc_html( $via ) . '</strong>. If you can read this, invoice emails will reach your clients.</td></tr></table></body></html>';
		return array( 'html' => $html, 'text' => 'Email is working. This is a test message from ' . $business['business_name'] . ' sent via ' . $via . '.' );
	}
}
