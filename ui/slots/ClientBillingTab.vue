<script setup>
import { ref } from 'vue';
import Billing from '../views/Billing.vue';
import Invoices from '../views/Invoices.vue';

const props = defineProps( { client: { type: Object, default: null }, clientId: { type: Number, required: true }, tick: { type: Number, default: 0 }, refresh: { type: Function, default: () => {} } } );
const billTab = ref( 'billing' );
const fixed = { client_id: props.clientId };
</script>

<template>
	<div>
		<el-radio-group v-model="billTab" class="bd-seg-sm bd-mb" aria-label="Billing view">
			<el-radio-button value="billing">Billing</el-radio-button>
			<el-radio-button value="invoices">{{ $tp( 'invoice' ) }}</el-radio-button>
		</el-radio-group>
		<Billing v-if="billTab === 'billing'" :fixed="fixed" embedded :currency="client ? client.currency : undefined" :reload-key="tick" @changed="refresh" />
		<Invoices v-else :fixed="fixed" embedded :currency="client ? client.currency : undefined" :reload-key="tick" @changed="refresh" />
	</div>
</template>
