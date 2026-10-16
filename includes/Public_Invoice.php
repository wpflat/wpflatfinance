<?php
namespace BizyFinance;

use BizyCore\Assets;

use BizyFinance\Schema;
use BizyCore\Services\Activity;
use BizyFinance\Services\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Public, shareable invoice page: https://site/invoice/<slug> (legacy ?bd_invoice=<token> still works)
 * A standalone branded document (no theme) that prints cleanly to PDF.
 */
class Public_Invoice {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	public static function maybe_render() {
		$token = '';
		if ( ! empty( $_GET['bd_invoice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$token = sanitize_text_field( wp_unslash( $_GET['bd_invoice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$rel  = trim( substr( (string) $path, strlen( $home ) ), '/' );
			$base = Invoices::slug_base();
			if ( 0 === strpos( $rel, $base . '/' ) && preg_match( '#^' . preg_quote( $base, '#' ) . '/([A-Za-z0-9_-]{4,64})$#', $rel, $m ) ) {
				$token = $m[1];
			}
		}
		if ( '' === $token ) {
			return;
		}
		global $wpdb;
		$tbl   = Schema::table( 'invoices' );
		$inv   = ( strlen( $token ) >= 4 ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE public_token=%s", $token ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Rocket, W3TC, WP Super Cache, etc.
		}
		do_action( 'litespeed_control_set_nocache', 'bizydash invoice' );
		header( 'X-LiteSpeed-Cache-Control: no-cache, no-store' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );

		$is_admin = current_user_can( 'manage_bizycore' );
		if ( ! $inv || ( ! $is_admin && in_array( $inv['status'], array( 'draft', 'cancelled' ), true ) ) ) {
			status_header( 404 );
			echo self::shell( 'Invoice unavailable', '<main class="bd-wrap"><div class="bd-doc bd-doc--empty"><h1>This invoice isn’t available</h1><p>The link may have expired or been replaced. Please contact the sender for a new link.</p></div></main>', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		if ( ! $is_admin ) {
			$first = empty( $inv['viewed_at'] );
			$wpdb->query( $wpdb->prepare( "UPDATE $tbl SET view_count = view_count + 1, viewed_at = COALESCE(viewed_at, %s) WHERE id=%d", current_time( 'mysql' ), $inv['id'] ) ); // phpcs:ignore WordPress.DB
			if ( $first ) {
				Activity::log( 'invoices', $inv['id'], $inv['client_id'], 'viewed', sprintf( 'Invoice %s was opened by the client', $inv['invoice_number'] ) );
			}
		}

		status_header( 200 );
		echo self::render( $inv, $is_admin ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ───────────────────────── Formatting ───────────────────────── */

	public static function money( $amount, $currency ) {
		$sym = $currency;
		foreach ( (array) Settings::get( 'currencies' ) as $c ) {
			if ( $c['code'] === $currency ) {
				$sym = $c['symbol'];
				break;
			}
		}
		return ( (float) $amount < 0 ? '−' : '' ) . $sym . number_format( abs( (float) $amount ), 2 );
	}

	private static function date( $d ) {
		return $d ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $d . ' 12:00:00' ) ) ) : '—';
	}

	private static function nl( $text ) {
		return nl2br( esc_html( (string) $text ) );
	}

	private static function status_label( $status ) {
		$map = array(
			'paid'           => array( 'Paid', 'ok' ),
			'partially_paid' => array( 'Partially paid', 'warn' ),
			'overdue'        => array( 'Overdue', 'bad' ),
			'due'            => array( 'Due', 'warn' ),
			'sent'           => array( 'Awaiting payment', 'info' ),
			'draft'          => array( 'Draft', 'muted' ),
			'cancelled'      => array( 'Cancelled', 'muted' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : array( ucfirst( $status ), 'muted' );
	}

	/* ───────────────────────── Payment methods ───────────────────────── */

	/** Shared by the public page: one card per bank account / payment link / other method. */
	private static function methods_html( array $methods, array $inv, $balance ) {
		$cur = $inv['currency'];
		ob_start();
		foreach ( $methods as $m ) {
			$title = '' !== $m['title'] ? $m['title'] : ( 'link' === $m['type'] ? 'Pay online' : ( 'bank' === $m['type'] ? 'Bank transfer' : 'Other' ) );
			if ( 'link' === $m['type'] ) :
				$label = '' !== $m['button_label'] ? $m['button_label'] : 'Pay ' . self::money( $balance, $cur );
				?>
				<a class="bd-m bd-m--link" href="<?php echo esc_url( $m['url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<span class="bd-m__t"><?php echo esc_html( $title ); ?></span>
					<?php if ( $m['note'] ) : ?><span class="bd-m__s"><?php echo esc_html( $m['note'] ); ?></span><?php endif; ?>
					<span class="bd-m__go"><?php echo esc_html( $label ); ?> <i aria-hidden="true">→</i></span>
				</a>
				<?php
			elseif ( 'bank' === $m['type'] ) :
				$rows = array_filter(
					array(
						'Bank'           => $m['bank_name'],
						'Account name'   => $m['account_name'],
						'Account number' => $m['account_number'],
						( $m['extra_label'] ? $m['extra_label'] : 'Reference' ) => $m['extra_value'],
					),
					'strlen'
				);
				?>
				<div class="bd-m">
					<div class="bd-m__h"><span class="bd-m__t"><?php echo esc_html( $title ); ?></span><?php if ( $m['currency'] ) : ?><span class="bd-chip"><?php echo esc_html( $m['currency'] ); ?></span><?php endif; ?></div>
					<dl class="bd-kv">
						<?php foreach ( $rows as $k => $v ) : ?>
							<div><dt><?php echo esc_html( $k ); ?></dt><dd><span><?php echo esc_html( $v ); ?></span><?php if ( 'Account number' === $k || $k === $m['extra_label'] ) : ?><button type="button" class="bd-copy no-print" data-copy="<?php echo esc_attr( $v ); ?>">Copy</button><?php endif; ?></dd></div>
						<?php endforeach; ?>
					</dl>
					<?php if ( $m['note'] ) : ?><div class="bd-m__s"><?php echo self::nl( $m['note'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
				</div>
				<?php
			else :
				?>
				<div class="bd-m">
					<div class="bd-m__h"><span class="bd-m__t"><?php echo esc_html( $title ); ?></span></div>
					<div class="bd-m__s bd-m__s--body"><?php echo self::nl( $m['note'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				</div>
				<?php
			endif;
		}
		return ob_get_clean();
	}

	/* ───────────────────────── Document ───────────────────────── */

	public static function render( array $inv, $is_admin = false ) {
		global $wpdb;
		$s        = Settings::get();
		$cur      = $inv['currency'];
		$client   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'clients' ) . ' WHERE id=%d', $inv['client_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items    = $wpdb->get_results( $wpdb->prepare( 'SELECT it.*, s.name AS service_name FROM ' . Schema::table( 'invoice_items' ) . ' it LEFT JOIN ' . Schema::table( 'client_services' ) . ' cs ON cs.id=it.client_service_id LEFT JOIN ' . Schema::table( 'catalog' ) . ' s ON s.id=cs.service_id WHERE it.invoice_id=%d ORDER BY it.id', $inv['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$payments = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE invoice_id=%d ORDER BY paid_on, id', $inv['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$schedule = Invoices::schedule( $inv );
		$has_plan = ! empty( $schedule ) && empty( $schedule[0]['implicit'] );
		$balance  = max( 0, round( (float) $inv['amount'] - (float) $inv['amount_paid'], 2 ) );
		list( $st_label, $st_tone ) = self::status_label( $inv['status'] );
		$payable  = $balance > 0 && ! in_array( $inv['status'], array( 'paid', 'cancelled', 'draft' ), true );
		$methods  = $payable ? Settings::payment_methods( $cur, array( 'invoice_number' => $inv['invoice_number'], 'amount' => number_format( $balance, 2, '.', '' ), 'currency' => $cur ) ) : array();
		$has_link = false;
		foreach ( $methods as $m ) {
			$has_link = $has_link || 'link' === $m['type'];
		}
		$next = null;
		if ( $has_plan ) {
			foreach ( $schedule as $row ) {
				if ( in_array( $row['state'], array( 'overdue', 'due', 'upcoming', 'partial' ), true ) ) {
					$next = $row;
					break;
				}
			}
		}

		ob_start();
		?>
<div class="bd-bar no-print">
	<div class="bd-bar__in">
		<span class="bd-bar__who"><?php if ( $s['business_logo_url'] ) : ?><img src="<?php echo esc_url( $s['business_logo_url'] ); ?>" alt=""><?php endif; ?><b><?php echo esc_html( $s['business_name'] ); ?></b><span class="bd-hide-s">Invoice <?php echo esc_html( $inv['invoice_number'] ); ?></span></span>
		<span class="bd-bar__act">
			<?php if ( $is_admin && in_array( $inv['status'], array( 'draft', 'cancelled' ), true ) ) : ?><em>Preview — not visible to clients yet</em><?php endif; ?>
			<button type="button" class="ghost" onclick="window.print()">Print / PDF</button>
			<?php if ( $payable && ( $methods ) ) : ?><a class="solid" href="#pay"><?php echo $has_link ? 'Pay now' : 'How to pay'; ?></a><?php endif; ?>
		</span>
	</div>
</div>

<main class="bd-wrap">
<table class="bd-paper" role="presentation"><thead><tr><td><div class="bd-pg-sp"></div></td></tr></thead><tbody><tr><td>
<article class="bd-doc <?php echo 'paid' === $inv['status'] ? 'is-paid' : ''; ?>">
	<?php if ( 'paid' === $inv['status'] ) : ?><div class="bd-stamp">PAID</div><?php endif; ?>

	<header class="bd-head">
		<div class="bd-brand">
			<div>
				<?php if ( $s['business_logo_url'] ) : ?><img class="bd-logo" src="<?php echo esc_url( $s['business_logo_url'] ); ?>" alt="<?php echo esc_attr( $s['business_name'] ); ?>"><?php else : ?><div class="bd-biz"><?php echo esc_html( $s['business_name'] ); ?></div><?php endif; ?>
				<?php if ( $s['business_address'] ) : ?><div class="bd-muted bd-mt"><?php echo self::nl( $s['business_address'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
				<div class="bd-muted"><?php echo esc_html( implode( ' · ', array_filter( array( $s['business_email'], $s['business_phone'] ) ) ) ); ?></div>
				<?php if ( $s['business_website'] ) : ?><div class="bd-muted"><?php echo esc_html( preg_replace( '#^https?://#', '', $s['business_website'] ) ); ?></div><?php endif; ?>
				<?php if ( $s['tax_id'] ) : ?><div class="bd-muted"><?php echo esc_html( $s['tax_label'] . ': ' . $s['tax_id'] ); ?></div><?php endif; ?>
			</div>
		</div>
		<div class="bd-title">
			<div class="bd-eyebrow">Invoice</div>
			<div class="bd-num"><?php echo esc_html( $inv['invoice_number'] ); ?></div>
			<span class="bd-pill bd-pill--<?php echo esc_attr( $st_tone ); ?>"><?php echo esc_html( $st_label ); ?></span>
		</div>
	</header>

	<?php if ( ! empty( $inv['title'] ) ) : ?>
	<section class="bd-for"><div class="bd-eyebrow">Invoice for</div><h1><?php echo esc_html( $inv['title'] ); ?></h1></section>
	<?php endif; ?>

	<section class="bd-sum">
		<div class="bd-sum__who">
			<div class="bd-k">Billed to</div>
			<div class="bd-strong bd-lg"><?php echo esc_html( $client ? $client['name'] : '' ); ?></div>
			<?php if ( $client ) : ?>
				<?php if ( $client['contact_person'] ) : ?><div class="bd-muted"><?php echo esc_html( $client['contact_person'] ); ?></div><?php endif; ?>
				<?php if ( $client['address'] ) : ?><div class="bd-muted"><?php echo self::nl( $client['address'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
				<?php if ( $client['email'] ) : ?><div class="bd-muted"><?php echo esc_html( $client['email'] ); ?></div><?php endif; ?>
			<?php endif; ?>
		</div>
		<div class="bd-sum__dates">
			<div><div class="bd-k">Issued</div><div class="bd-strong"><?php echo self::date( $inv['issue_date'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div>
			<div><div class="bd-k">Due</div><div class="bd-strong <?php echo 'overdue' === $inv['status'] ? 'bd-late' : ''; ?>"><?php echo self::date( $inv['due_date'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div>
		</div>
		<div class="bd-sum__due">
			<div class="bd-k"><?php echo $balance > 0 ? 'Amount due' : 'Total paid'; ?></div>
			<div class="bd-big"><?php echo esc_html( self::money( $balance > 0 ? $balance : $inv['amount'], $cur ) ); ?></div>
			<?php if ( $balance > 0 && (float) $inv['amount_paid'] > 0 ) : ?>
				<div class="bd-sub"><?php echo esc_html( self::money( $inv['amount_paid'], $cur ) ); ?> paid of <?php echo esc_html( self::money( $inv['amount'], $cur ) ); ?></div>
			<?php elseif ( $next && $balance > 0 ) : ?>
				<div class="bd-sub">Next: <?php echo esc_html( self::money( $next['balance'], $cur ) ); ?> by <?php echo wp_strip_all_tags( self::date( $next['due_date'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<?php endif; ?>
		</div>
	</section>

	<?php
	$fixed = array();
	$qtys  = array();
	foreach ( $items as $it ) {
		if ( isset( $it['kind'] ) && 'quantity' === $it['kind'] ) {
			$qtys[] = $it;
		} else {
			$fixed[] = $it;
		}
	}
	$groups = array();
	if ( $fixed ) {
		$groups[] = array( 'fixed', 'Fixed price', $fixed, 'One price, no quantity' );
	}
	if ( $qtys ) {
		$groups[] = array( 'quantity', 'By quantity', $qtys, 'Quantity × unit price' );
	}
	$split = count( $groups ) > 1;
	foreach ( $groups as $g ) :
		list( $gkind, $glabel, $gitems, $gnote ) = $g;
		$gsum = 0;
		?>
	<div class="bd-grp<?php echo $split ? ' bd-grp--card' : ''; ?>">
		<?php if ( $split ) : ?><h2 class="bd-grp__h"><span><?php echo esc_html( $glabel ); ?></span><em><?php echo esc_html( $gnote ); ?> · <?php echo (int) count( $gitems ); ?> <?php echo 1 === count( $gitems ) ? 'line' : 'lines'; ?></em></h2><?php endif; ?>
		<table class="bd-lines bd-lines--<?php echo esc_attr( $gkind ); ?>">
			<thead><tr><th>Description</th><?php if ( 'quantity' === $gkind ) : ?><th class="r bd-hide-s">Qty</th><th class="r bd-hide-s">Unit price</th><?php endif; ?><th class="r">Amount</th></tr></thead>
			<tbody>
			<?php
			foreach ( $gitems as $it ) :
				$gsum += (float) $it['amount'];
				?>
				<tr>
					<td>
						<div class="bd-strong"><?php echo esc_html( $it['description'] ); ?></div>
						<?php
						$sub = array();
						if ( ! empty( $it['service_name'] ) && 0 !== strcasecmp( trim( $it['service_name'] ), trim( $it['description'] ) ) ) {
							$sub[] = $it['service_name'];
						}
						if ( ! empty( $it['period_start'] ) ) {
							$sub[] = wp_strip_all_tags( self::date( $it['period_start'] ) ) . ' – ' . wp_strip_all_tags( self::date( $it['period_end'] ) );
						}
						if ( 'quantity' === $gkind ) {
							$sub[] = rtrim( rtrim( number_format( (float) $it['quantity'], 2 ), '0' ), '.' ) . ' × ' . self::money( $it['unit_price'], $cur );
						}
						if ( $sub ) :
							?>
							<div class="bd-muted bd-sm<?php echo 'quantity' === $gkind ? ' bd-show-s' : ''; ?>"><?php echo esc_html( implode( ' · ', $sub ) ); ?></div>
						<?php endif; ?>
					</td>
					<?php if ( 'quantity' === $gkind ) : ?>
						<td class="r bd-hide-s"><?php echo esc_html( rtrim( rtrim( number_format( (float) $it['quantity'], 2 ), '0' ), '.' ) ); ?></td>
						<td class="r bd-hide-s"><?php echo esc_html( self::money( $it['unit_price'], $cur ) ); ?></td>
					<?php endif; ?>
					<td class="r bd-strong"><?php echo esc_html( self::money( $it['amount'], $cur ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $split ) : ?><div class="bd-grp__sum"><span><?php echo esc_html( $glabel ); ?> subtotal</span><span><?php echo esc_html( self::money( $gsum, $cur ) ); ?></span></div><?php endif; ?>
	</div>
	<?php endforeach; ?>
	<?php if ( ! $items ) : ?>
	<table class="bd-lines"><thead><tr><th>Description</th><th class="r">Amount</th></tr></thead><tbody><tr><td><div class="bd-strong"><?php echo esc_html( $inv['description'] ? $inv['description'] : 'Services' ); ?></div></td><td class="r bd-strong"><?php echo esc_html( self::money( $inv['amount'], $cur ) ); ?></td></tr></tbody></table>
	<?php endif; ?>

	<section class="bd-totals">
		<div class="bd-totals__box">
			<div class="row"><span>Subtotal</span><span><?php echo esc_html( self::money( $inv['subtotal'] > 0 ? $inv['subtotal'] : $inv['amount'], $cur ) ); ?></span></div>
			<?php if ( (float) $inv['discount_amount'] > 0 ) : ?>
				<div class="row"><span>Discount<?php echo 'percent' === $inv['discount_type'] ? ' (' . esc_html( (float) $inv['discount_value'] ) . '%)' : ''; ?></span><span>−<?php echo esc_html( self::money( $inv['discount_amount'], $cur ) ); ?></span></div>
			<?php endif; ?>
			<?php if ( (float) $inv['tax_amount'] > 0 ) : ?>
				<div class="row"><span><?php echo esc_html( $s['tax_label'] ); ?> (<?php echo esc_html( (float) $inv['tax_rate'] ); ?>%)</span><span><?php echo esc_html( self::money( $inv['tax_amount'], $cur ) ); ?></span></div>
			<?php endif; ?>
			<div class="row total"><span>Total</span><span><?php echo esc_html( self::money( $inv['amount'], $cur ) ); ?></span></div>
			<?php if ( (float) $inv['amount_paid'] > 0 ) : ?>
				<div class="row"><span>Paid</span><span>−<?php echo esc_html( self::money( $inv['amount_paid'], $cur ) ); ?></span></div>
				<div class="row due"><span>Balance due</span><span><?php echo esc_html( self::money( $balance, $cur ) ); ?></span></div>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $has_plan ) : ?>
	<?php
	$sch_paid = 0;
	$sch_left = 0;
	foreach ( $schedule as $row ) {
		$sch_paid += (float) $row['paid'];
		$sch_left += (float) $row['balance'];
	}
	?>
	<section class="bd-grp bd-grp--card bd-block">
		<h2 class="bd-grp__h"><span>Payment schedule</span><em><?php echo (int) count( $schedule ); ?> instalments</em></h2>
		<div class="bd-scroll"><table class="bd-sched">
			<thead><tr><th>Instalment</th><th>Due</th><th class="r">Amount</th><th class="r">Paid</th><th class="r">Remaining</th><th></th></tr></thead>
			<tbody>
			<?php
			foreach ( $schedule as $row ) :
				$tone  = array( 'paid' => 'ok', 'partial' => 'warn', 'overdue' => 'bad', 'due' => 'warn', 'upcoming' => 'muted' );
				$label = array( 'paid' => 'Paid', 'partial' => 'Part-paid', 'overdue' => 'Overdue', 'due' => 'Due today', 'upcoming' => 'Upcoming' );
				?>
				<tr>
					<td class="bd-strong"><?php echo esc_html( $row['label'] ); ?><?php echo null !== $row['percent'] ? ' <span class="bd-muted bd-sm">(' . esc_html( (float) $row['percent'] ) . '%)</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td><?php echo self::date( $row['due_date'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="r"><?php echo esc_html( self::money( $row['amount'], $cur ) ); ?></td>
					<td class="r"><?php echo esc_html( self::money( $row['paid'], $cur ) ); ?></td>
					<td class="r bd-strong"><?php echo esc_html( self::money( $row['balance'], $cur ) ); ?></td>
					<td class="r"><span class="bd-pill bd-pill--<?php echo esc_attr( $tone[ $row['state'] ] ); ?>"><?php echo esc_html( $label[ $row['state'] ] ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<div class="bd-grp__sum"><span>Paid <?php echo esc_html( self::money( $sch_paid, $cur ) ); ?></span><span>Remaining <?php echo esc_html( self::money( $sch_left, $cur ) ); ?></span></div>
	</section>
	<?php endif; ?>

	<?php if ( $payments ) : ?>
	<?php
	$rec_total = 0;
	foreach ( $payments as $p ) {
		$rec_total += (float) $p['amount'];
	}
	?>
	<section class="bd-grp bd-grp--card bd-block">
		<h2 class="bd-grp__h"><span>Payments received</span><em><?php echo (int) count( $payments ); ?> <?php echo 1 === count( $payments ) ? 'payment' : 'payments'; ?></em></h2>
		<table class="bd-sched bd-sched--rec">
			<tbody>
			<?php foreach ( $payments as $p ) : ?>
				<tr><td><?php echo self::date( $p['paid_on'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td class="bd-muted"><?php echo esc_html( trim( $p['method'] . ' ' . $p['reference'] ) ); ?></td><td class="r bd-strong"><?php echo esc_html( self::money( $p['amount'], $cur ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="bd-grp__sum"><span>Total received</span><span><?php echo esc_html( self::money( $rec_total, $cur ) ); ?></span></div>
	</section>
	<?php endif; ?>

	<?php if ( $payable && ( $methods || $s['payment_instructions'] ) ) : ?>
	<section class="bd-pay" id="pay">
		<div class="bd-pay__h">
			<h2>How to pay</h2>
			<p>Please use <strong><?php echo esc_html( $inv['invoice_number'] ); ?></strong> as your payment reference<?php echo $methods ? '. Choose whichever option suits you.' : '.'; ?></p>
		</div>
		<?php if ( $methods ) : ?><div class="bd-methods"><?php echo self::methods_html( $methods, $inv, $balance ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
		<?php if ( $s['payment_instructions'] ) : ?><div class="bd-pay__note"><?php echo self::nl( $s['payment_instructions'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
	</section>
	<?php endif; ?>

	<?php if ( $inv['notes'] || $inv['terms'] ) : ?>
	<section class="bd-cols">
		<?php if ( $inv['notes'] ) : ?><div><h2>Notes</h2><div><?php echo self::nl( $inv['notes'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div><?php endif; ?>
		<?php if ( $inv['terms'] ) : ?><div><h2>Terms</h2><div class="bd-muted"><?php echo self::nl( $inv['terms'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div><?php endif; ?>
	</section>
	<?php endif; ?>

	<footer class="bd-foot"><?php echo esc_html( $s['invoice_footer'] ); ?></footer>
</article>
</td></tr></tbody><tfoot><tr><td><div class="bd-pg-sp"></div></td></tr></tfoot></table>
</main>
<script>
document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('[data-copy]');if(!b)return;var t=b.getAttribute('data-copy'),d=function(){var o=b.textContent;b.textContent='Copied';b.classList.add('is-done');setTimeout(function(){b.textContent=o;b.classList.remove('is-done')},1400)};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(d,function(){})}else{var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();try{document.execCommand('copy');d()}catch(x){}document.body.removeChild(a)}});
</script>
		<?php
		$body = ob_get_clean();
		return self::shell( 'Invoice ' . $inv['invoice_number'] . ( ! empty( $inv['title'] ) ? ' — ' . $inv['title'] : '' ) . ' — ' . $s['business_name'], $body, $s['business_name'] );
	}

	public static function shell( $title, $body, $biz ) {
		return \BizyCore\Public_Page::shell( $title, $body, $biz );
	}
}
