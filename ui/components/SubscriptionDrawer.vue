<script setup>
import { computed, ref, watch } from 'vue';
import { api, errorMessage, fmtDate, labelOf } from '../core';
import { fmtMoney } from '../util';
import LedgerList from './LedgerList.vue';
import Renewals from '../views/Renewals.vue';

const props = defineProps( { modelValue: Boolean, subscription: { type: Object, default: null } } );
const emit = defineEmits( [ 'update:modelValue', 'changed' ] );

const tab = ref( 'cycles' );
const events = ref( [] );
const error = ref( '' );
const key = ref( 0 );

const loadLedger = async () => {
	if ( ! props.subscription ) {
		return;
	}
	try {
		events.value = await api.getPath( `client-services/${ props.subscription.id }/ledger` );
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
watch( () => [ props.modelValue, props.subscription && props.subscription.id, key.value ], ( [ open ] ) => {
	if ( open ) {
		loadLedger();
	}
} );
watch( () => props.modelValue, ( open ) => {
	if ( open ) {
		tab.value = 'cycles';
	}
} );

const fixed = computed( () => ( props.subscription ? { client_service_id: props.subscription.id } : {} ) );
const changed = () => {
	key.value++;
	emit( 'changed' );
};
</script>

<template>
	<el-drawer :model-value="modelValue" size="780px" append-to-body destroy-on-close @update:model-value="emit( 'update:modelValue', $event )">
		<template #header>
			<div v-if="subscription">
				<div class="bd-eyebrow">{{ subscription.client_name }}</div>
				<h3 class="bd-drawer-title">{{ subscription.subject || subscription.service_name }}</h3>
				<div class="bd-muted bd-small"><template v-if="subscription.subject">{{ subscription.service_name }} · </template>{{ fmtMoney( subscription.client_price, subscription.currency ) }} · {{ labelOf( 'billing_period', subscription.billing_period ) }}<template v-if="subscription.is_retainer"> · Retainer</template></div>
			</div>
		</template>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-tabs v-if="subscription" v-model="tab" class="bd-tabs">
			<el-tab-pane label="Bills" name="cycles" lazy>
				<Renewals :fixed="fixed" embedded initial-status="" :reload-key="key" @changed="changed" />
			</el-tab-pane>
			<el-tab-pane label="Money history" name="ledger" lazy>
				<el-card shadow="never"><LedgerList :events="events" empty="No billing history for this subscription yet." /></el-card>
			</el-tab-pane>
		</el-tabs>
	</el-drawer>
</template>
