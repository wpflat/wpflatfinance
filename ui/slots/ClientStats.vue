<script setup>
import { computed, onMounted, reactive, watch } from 'vue';
import { Coin, Tickets, TrendCharts } from '@element-plus/icons-vue';
import { api, StatCard } from '../core';
import MoneyMap from '../components/MoneyMap.vue';

const props = defineProps( { clientId: { type: Number, required: true }, tick: { type: Number, default: 0 } } );
const d = reactive( { outstanding: {}, open: 0, toInvoice: [], mrr: {}, subs: 0 } );

const group = ( rows, field ) => {
	const out = {};
	rows.forEach( ( r ) => {
		out[ r.currency ] = out[ r.currency ] || { amount: 0, count: 0 };
		out[ r.currency ].amount += Number( field( r ) );
		out[ r.currency ].count += 1;
	} );
	return out;
};
const load = async () => {
	try {
		const [ inv, unb, subs ] = await Promise.all( [
			api.list( 'invoices', { client_id: props.clientId, unpaid: 1, per_page: 200 } ),
			api.getPath( `clients/${ props.clientId }/unbilled` ),
			api.list( 'client-services', { client_id: props.clientId, recurring: 1, status: 'active', per_page: 200 } ),
		] );
		d.outstanding = group( inv.items, ( r ) => r.balance );
		d.open = inv.items.length;
		d.toInvoice = unb || [];
		d.mrr = group( subs.items, ( r ) => r.mrr || 0 );
		d.subs = subs.items.length;
	} catch ( e ) { /* the cards stay empty */ }
};
onMounted( load );
watch( () => props.tick, load );
const unbilled = computed( () => group( d.toInvoice, ( r ) => r.price ) );
</script>

<template>
	<el-col :xs="12" :md="6"><StatCard label="Waiting to be paid" :icon="Tickets" :tone="Object.keys( d.outstanding ).length ? 'warning' : ''" :sub="d.open + ' unpaid ' + ( d.open === 1 ? $t( 'invoice' ) : $tp( 'invoice' ) ).toLowerCase()"><MoneyMap :map="d.outstanding" empty="Nothing" /></StatCard></el-col>
	<el-col :xs="12" :md="6"><StatCard label="Ready to invoice" :icon="Coin" :sub="d.toInvoice.length + ' bill' + ( d.toInvoice.length === 1 ? '' : 's' )"><MoneyMap :map="unbilled" empty="Nothing" /></StatCard></el-col>
	<el-col :xs="12" :md="6"><StatCard label="Monthly income" :icon="TrendCharts" :sub="d.subs + ' subscription' + ( d.subs === 1 ? '' : 's' ) + ' & retainers'"><MoneyMap :map="d.mrr" empty="—" /></StatCard></el-col>
</template>
