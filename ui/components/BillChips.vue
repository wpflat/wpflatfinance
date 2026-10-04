<script setup>
import { computed } from 'vue';
import MoneyMap from './MoneyMap.vue';

const props = defineProps( { modelValue: { type: String, default: '' }, summary: { type: Object, default: null } } );
const emit = defineEmits( [ 'update:modelValue' ] );

const CHIPS = [
	{ id: '', label: 'All', keys: [ 'not_invoiced', 'awaiting', 'overdue', 'paid' ] },
	{ id: 'not_invoiced', label: 'To invoice', keys: [ 'not_invoiced' ], tone: 'warning' },
	{ id: 'awaiting', label: 'Awaiting payment', keys: [ 'awaiting' ] },
	{ id: 'overdue', label: 'Overdue', keys: [ 'overdue' ], tone: 'danger' },
	{ id: 'paid', label: 'Paid', keys: [ 'paid' ] },
];

const totals = ( keys ) => {
	const out = {};
	keys.forEach( ( k ) =>
		Object.entries( ( props.summary && props.summary[ k ] ) || {} ).forEach( ( [ cur, v ] ) => {
			out[ cur ] = out[ cur ] || { amount: 0, count: 0 };
			out[ cur ].amount += v.amount;
			out[ cur ].count += v.count;
		} )
	);
	return out;
};
const chips = computed( () => CHIPS.map( ( c ) => ( { ...c, map: totals( c.keys ), count: Object.values( totals( c.keys ) ).reduce( ( n, v ) => n + v.count, 0 ) } ) ) );
</script>

<template>
	<div class="bd-pipeline">
		<button v-for="c in chips" :key="c.id" type="button" :class="[ 'bd-pipe', { 'is-active': modelValue === c.id }, c.tone && 'bd-pipe--' + c.tone ]" @click="emit( 'update:modelValue', c.id )">
			<span class="bd-pipe__label">{{ c.label }} <span class="bd-pipe__count">{{ c.count }}</span></span>
			<span class="bd-pipe__value"><MoneyMap :map="c.map" empty="—" /></span>
		</button>
	</div>
</template>
