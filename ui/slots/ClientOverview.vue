<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { api, fmtDate, relDays } from '../core';
import { fmtMoney } from '../util';

const props = defineProps( { clientId: { type: Number, required: true }, tick: { type: Number, default: 0 }, goTab: { type: Function, default: () => {} } } );
const items = ref( [] );
const load = async () => {
	try {
		items.value = await api.getPath( `clients/${ props.clientId }/unbilled` );
	} catch ( e ) { items.value = []; }
};
onMounted( load );
watch( () => props.tick, load );
const next = computed( () => [ ...items.value ].sort( ( a, b ) => ( a.days ?? 9999 ) - ( b.days ?? 9999 ) ).slice( 0, 6 ) );
</script>

<template>
	<el-card shadow="never" class="bd-widget">
		<template #header><div class="bd-widget__head"><span>Next bills</span><a href="#" @click.prevent="goTab( 'billing' )">Open billing</a></div></template>
		<ul class="bd-list">
			<li v-for="i in next" :key="i.key">
				<div><div class="bd-strong">{{ i.label }}</div><div class="bd-muted bd-small">{{ i.date ? fmtDate( i.date ) : 'No date set' }} · {{ relDays( i.days ) }}</div></div>
				<span class="bd-money bd-strong">{{ fmtMoney( i.price, i.currency ) }}</span>
			</li>
			<li v-if="!next.length" class="bd-muted">Nothing to bill right now.</li>
		</ul>
	</el-card>
</template>
