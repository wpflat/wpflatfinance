<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { api, errorMessage } from '../core';
import LedgerList from '../components/LedgerList.vue';

const props = defineProps( { clientId: { type: [ Number, String ], required: true }, reloadKey: { type: Number, default: 0 } } );

const events = ref( [] );
const error = ref( '' );
const loading = ref( true );
const kind = ref( 'money' );

const KINDS = [
	{ id: 'money', label: 'Invoices & payments' },
	{ id: 'all', label: 'Everything' },
	{ id: 'renewal', label: 'Recurring' },
	{ id: 'charge', label: 'One-off' },
	{ id: 'invoice', label: 'Invoices' },
	{ id: 'payment', label: 'Payments' },
];

const load = async () => {
	loading.value = true;
	try {
		events.value = await api.getPath( `clients/${ props.clientId }/ledger` );
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => props.reloadKey, load );

const shown = computed( () => ( kind.value === 'all' ? events.value : kind.value === 'money' ? events.value.filter( ( e ) => [ 'invoice', 'payment' ].includes( e.type ) ) : events.value.filter( ( e ) => e.type === kind.value ) ) );
</script>

<template>
	<div>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<div class="bd-toolbar">
			<el-radio-group v-model="kind" class="bd-seg-sm">
				<el-radio-button v-for="k in KINDS" :key="k.id" :value="k.id">{{ k.label }}</el-radio-button>
			</el-radio-group>
		</div>
		<el-card shadow="never" v-loading="loading">
			<LedgerList :events="shown" empty="No billing activity for this client yet." />
		</el-card>
	</div>
</template>
