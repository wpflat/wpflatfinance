<script setup>
import { computed } from 'vue';
import { ChartCard, navigate } from '../core';

const props = defineProps( { department: { type: Object, default: () => ( {} ) }, q: { type: Function, default: ( x ) => x } } );
const m = computed( () => ( props.department.modules && props.department.modules.finance ) || null );
</script>

<template>
	<div v-if="m" class="bd-angrid bd-mt">
		<ChartCard title="Money" sub="In the period shown">
			<template #tools><a class="bd-link bd-small" :href="'#' + q( '/billing/invoices' )" @click.prevent="navigate( q( '/billing/invoices' ) )">{{ $tp( 'invoice' ) }}</a> <a class="bd-link bd-small bd-ml" :href="'#' + q( '/finance/expenses' )" @click.prevent="navigate( q( '/finance/expenses' ) )">Expenses</a></template>
			<p class="bd-muted bd-small">Open the overview for the full picture.</p>
		</ChartCard>
	</div>
</template>
