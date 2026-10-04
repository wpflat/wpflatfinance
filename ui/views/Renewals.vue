<script setup>
import { ref, computed } from 'vue';
import { ElMessage, ResourceTable } from '../core';
import { fmtMoney, summariseSelection } from '../util';
import InvoiceDialog from '../components/InvoiceDialog.vue';
import { renewalsConfig } from '../resources';

const props = defineProps( { fixed: { type: Object, default: () => ( {} ) }, embedded: Boolean, reloadKey: { type: Number, default: 0 }, useRouteFilters: Boolean, initialStatus: { type: String, default: 'upcoming,due' } } );
const emit = defineEmits( [ 'changed' ] );

const config = computed( () => renewalsConfig( { embedded: props.embedded, fixed: props.fixed, initialStatus: props.initialStatus } ) );
const key = ref( 0 );
const dialog = ref( false );
const summary = ref( null );
let clearFn = null;

const open = ( selected, clear ) => {
	summary.value = summariseSelection( { renewals: selected } );
	clearFn = clear;
	dialog.value = true;
};
const done = () => {
	clearFn && clearFn();
	key.value++;
	emit( 'changed' );
};
</script>

<template>
	<ResourceTable :config="config" :fixed="fixed" :embedded="embedded" :reload-key="reloadKey + key" :use-route-filters="useRouteFilters" @changed="emit( 'changed' )">
		<template #toolbar="{ selected, clear }">
			<span v-if="selected.length && !summariseSelection( { renewals: selected } ).ok" class="bd-muted">{{ summariseSelection( { renewals: selected } ).reason }}</span>
			<el-button type="primary" :disabled="!summariseSelection( { renewals: selected } ).ok" @click="open( selected, clear )">
				{{ summariseSelection( { renewals: selected } ).ok ? 'Invoice selected (' + fmtMoney( summariseSelection( { renewals: selected } ).total, summariseSelection( { renewals: selected } ).currency ) + ')' : 'Invoice selected' }}
			</el-button>
		</template>
	</ResourceTable>
	<InvoiceDialog v-model="dialog" :summary="summary" @done="done" />
</template>
