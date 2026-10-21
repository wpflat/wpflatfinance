<?php
namespace WPFlatFinance;

use WPFlatFinance\Schema;

defined( 'ABSPATH' ) || exit;

/** Printable payslip for a payout: /?bd_payslip=<payout id> (finance users only). Prints like the invoice page. */
class Payslip {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
	}

	public static function maybe_render() {
		if ( empty( $_GET['bd_payslip'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		if ( ! current_user_can( 'wpflatfinance_finance' ) ) {
			status_header( 404 );
			wp_die( 'Not found.', 'Not found', array( 'response' => 404 ) );
		}
		global $wpdb;
		$id  = absint( wp_unslash( $_GET['bd_payslip'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$po  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'payouts' ) . ' WHERE id=%d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $po ) {
			status_header( 404 );
			wp_die( 'Payout not found.', 'Not found', array( 'response' => 404 ) );
		}
		echo self::render( $po ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function render( array $po ) {
		global $wpdb;
		$s   = Settings::get();
		$m   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::tm() . ' x WHERE x.id=%d', $po['team_member_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$its = $wpdb->get_results( $wpdb->prepare( 'SELECT i.*, pj.name AS project_name, ph.name AS phase_name FROM ' . Schema::table( 'pay_items' ) . ' i LEFT JOIN ' . Schema::table( 'projects' ) . ' pj ON pj.id=i.project_id LEFT JOIN ' . Schema::table( 'project_phases' ) . ' ph ON ph.id=i.phase_id WHERE i.payout_id=%d ORDER BY i.earned_on, i.id', $po['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$cur = $po['currency'];
		$m_  = function ( $a ) use ( $cur ) {
			return Public_Invoice::money( $a, $cur );
		};
		$earn = 0.0;
		$ded  = 0.0;
		foreach ( $its as $i ) {
			if ( (float) $i['amount'] >= 0 ) {
				$earn += (float) $i['amount'];
			} else {
				$ded += (float) $i['amount'];
			}
		}
		$acct = $m && $m['account_number'] ? ( $m['bank_name'] ? $m['bank_name'] . ' · ' : '' ) . '••••' . substr( $m['account_number'], -4 ) : '';
		$void = 'void' === $po['status'];

		ob_start();
		?>
<div class="bd-bar no-print"><div class="bd-bar__in"><span class="bd-bar__who"><b><?php echo esc_html( $s['business_name'] ); ?></b><span>Payslip <?php echo esc_html( $po['number'] ); ?></span></span><span class="bd-bar__act"><button type="button" class="ghost" onclick="window.print()">Print / PDF</button></span></div></div>
<main class="bd-wrap">
<table class="bd-paper" role="presentation"><thead><tr><td><div class="bd-pg-sp"></div></td></tr></thead><tbody><tr><td>
<article class="bd-doc">
	<?php if ( $void ) : ?><div class="bd-stamp" style="border-color:#d92d20;color:#d92d20">VOID</div><?php endif; ?>
	<header class="bd-head">
		<div class="bd-brand"><div>
			<?php if ( $s['business_logo_url'] ) : ?><img class="bd-logo" src="<?php echo esc_url( $s['business_logo_url'] ); ?>" alt="<?php echo esc_attr( $s['business_name'] ); ?>"><?php else : ?><div class="bd-biz"><?php echo esc_html( $s['business_name'] ); ?></div><?php endif; ?>
			<?php if ( $s['business_address'] ) : ?><div class="bd-muted bd-mt"><?php echo nl2br( esc_html( $s['business_address'] ) ); ?></div><?php endif; ?>
		</div></div>
		<div class="bd-title"><div class="bd-eyebrow">Payslip</div><div class="bd-num"><?php echo esc_html( $po['number'] ); ?></div><span class="bd-pill bd-pill--<?php echo $void ? 'bad' : 'ok'; ?>"><?php echo $void ? 'Voided' : 'Paid'; ?></span></div>
	</header>
	<section class="bd-sum">
		<div class="bd-sum__who"><div class="bd-k">Paid to</div><div class="bd-strong bd-lg"><?php echo esc_html( $m ? $m['name'] : '' ); ?></div><?php if ( $m && $m['role'] ) : ?><div class="bd-muted"><?php echo esc_html( $m['role'] ); ?></div><?php endif; ?><?php if ( $acct ) : ?><div class="bd-muted"><?php echo esc_html( $acct ); ?></div><?php endif; ?></div>
		<div class="bd-sum__dates"><div><div class="bd-k">Date paid</div><div class="bd-strong"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $po['paid_on'] . ' 12:00:00' ) ) ); ?></div></div><div><div class="bd-k">Paid by</div><div class="bd-strong"><?php echo esc_html( $po['method'] ? $po['method'] : '—' ); ?></div></div></div>
		<div class="bd-sum__due"><div class="bd-k">Net paid</div><div class="bd-big"><?php echo esc_html( $m_( $po['amount'] ) ); ?></div><?php if ( $po['reference'] ) : ?><div class="bd-sub">Ref <?php echo esc_html( $po['reference'] ); ?></div><?php endif; ?></div>
	</section>
	<div class="bd-grp bd-grp--card">
		<h2 class="bd-grp__h"><span>What this covers</span><em><?php echo (int) count( $its ); ?> line<?php echo 1 === count( $its ) ? '' : 's'; ?></em></h2>
		<table class="bd-lines"><thead><tr><th>Description</th><th class="r">Amount</th></tr></thead><tbody>
		<?php foreach ( $its as $i ) : ?>
			<tr><td><div class="bd-strong"><?php echo esc_html( $i['description'] ); ?></div><div class="bd-muted bd-sm"><?php echo esc_html( implode( ' · ', array_filter( array( ucfirst( $i['kind'] ), $i['project_name'], wp_date( get_option( 'date_format' ), strtotime( $i['earned_on'] . ' 12:00:00' ) ) ) ) ) ); ?></div></td><td class="r bd-strong"><?php echo esc_html( $m_( $i['amount'] ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<div class="bd-grp__sum"><span>Net paid</span><span><?php echo esc_html( $m_( $po['amount'] ) ); ?></span></div>
	</div>
	<section class="bd-totals"><div class="bd-totals__box">
		<div class="row"><span>Earnings</span><span><?php echo esc_html( $m_( $earn ) ); ?></span></div>
		<?php if ( $ded < 0 ) : ?><div class="row"><span>Deductions</span><span>−<?php echo esc_html( $m_( abs( $ded ) ) ); ?></span></div><?php endif; ?>
		<div class="row total"><span>Net paid</span><span><?php echo esc_html( $m_( $po['amount'] ) ); ?></span></div>
	</div></section>
	<?php if ( $po['notes'] ) : ?><section class="bd-cols"><div><h2>Note</h2><div><?php echo nl2br( esc_html( $po['notes'] ) ); ?></div></div></section><?php endif; ?>
	<footer class="bd-foot">This payslip records a payment already made. Keep it for your records.</footer>
</article>
</td></tr></tbody><tfoot><tr><td><div class="bd-pg-sp"></div></td></tr></tfoot></table>
</main>
		<?php
		return Public_Invoice::shell( 'Payslip ' . $po['number'] . ' — ' . $s['business_name'], ob_get_clean(), $s['business_name'] );
	}
}
