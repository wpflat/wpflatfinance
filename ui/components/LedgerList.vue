<script setup>
import { computed } from 'vue';
import { StatusTag, fmtDate, humanize } from '../core';
import { fmtMoney } from '../util';
import { Coin, CreditCard, RefreshRight, Tickets, Wallet } from '@element-plus/icons-vue';

const props = defineProps( { events: { type: Array, default: () => [] }, empty: { type: String, default: 'Nothing to show yet.' } } );

const ICON = { renewal: RefreshRight, charge: Coin, invoice: Tickets, invoice_line: Tickets, payment: Wallet };
const KIND = { renewal: 'Recurring', charge: 'One-off', invoice: 'Invoice', invoice_line: 'Invoiced', payment: 'Payment' };

const groups = computed( () => {
	const map = new Map();
	props.events.forEach( ( e ) => {
		const d = e.date ? new Date( e.date + 'T00:00:00' ) : null;
		const key = d ? d.toLocaleDateString( undefined, { month: 'long', year: 'numeric' } ) : 'No date';
		if ( ! map.has( key ) ) {
			map.set( key, [] );
		}
		map.get( key ).push( e );
	} );
	return [ ...map.entries() ];
} );
</script>

<template>
	<div class="bd-ledger">
		<el-empty v-if="!events.length" :description="empty" :image-size="72" />
		<section v-for="[ month, rows ] in groups" :key="month" class="bd-ledger__group">
			<h4>{{ month }}</h4>
			<div v-for="e in rows" :key="e.type + e.id" class="bd-ledger__row">
				<span :class="[ 'bd-ledger__icon', 'bd-ledger__icon--' + e.type ]"><el-icon :size="16"><component :is="ICON[ e.type ] || CreditCard" /></el-icon></span>
				<div class="bd-ledger__main">
					<div class="bd-strong">{{ e.label }}</div>
					<div class="bd-muted bd-small">{{ KIND[ e.type ] }} · {{ fmtDate( e.date ) }}<template v-if="e.sub"> · {{ e.sub.length === 3 || e.sub.length < 12 ? humanize( e.sub ) : e.sub }}</template></div>
				</div>
				<div class="bd-ledger__amt">
					<div :class="[ 'bd-money', 'bd-strong', { 'bd-ledger__in': e.type === 'payment' } ]">{{ e.type === 'payment' ? '+' : '' }}{{ fmtMoney( e.amount, e.currency ) }}</div>
					<StatusTag :status="e.status" />
				</div>
			</div>
		</section>
	</div>
</template>
