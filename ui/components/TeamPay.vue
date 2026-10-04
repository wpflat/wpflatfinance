<script setup>
import { computed, reactive, ref } from 'vue';
import { ResourceTable } from '../core';
import { fmtMoney } from '../util';
import PayDialog from './PayDialog.vue';
import AddPaymentDrawer from './AddPaymentDrawer.vue';
import { payItemsConfig, payoutsConfig } from '../resources';

const props = defineProps( { member: { type: Object, required: true }, reloadKey: { type: Number, default: 0 } } );
const emit = defineEmits( [ 'changed' ] );

const key = ref( 0 );
const view = ref( 'earnings' );
const pay = reactive( { open: false, items: [] } );
const add = reactive( { open: false } );
const fixed = computed( () => ( { team_member_id: props.member.id } ) );

const bump = () => {
	key.value++;
	emit( 'changed' );
};
const startPay = ( rows ) => {
	pay.items = rows;
	pay.open = true;
};
const total = ( rows ) => rows.reduce( ( t, r ) => t + Number( r.amount ), 0 );
</script>

<template>
	<div class="bd-teampay">
		<el-radio-group v-model="view" class="bd-seg-sm bd-mb" aria-label="Pay view">
			<el-radio-button value="earnings">Earnings</el-radio-button>
			<el-radio-button value="payouts">Payouts</el-radio-button>
		</el-radio-group>

		<ResourceTable v-if="view === 'earnings'" :config="payItemsConfig( { fixed } )" :fixed="fixed" embedded :reload-key="key + reloadKey" @changed="bump">
			<template #toolbar="{ selected, clear }">
				<el-button @click="add.open = true">Add payment</el-button>
				<el-button type="primary" :disabled="!selected.length" @click="startPay( selected )">{{ selected.length ? `Pay selected (${ fmtMoney( total( selected ), selected[ 0 ].currency ) })` : 'Pay selected' }}</el-button>
			</template>
		</ResourceTable>
		<ResourceTable v-else :config="payoutsConfig( { fixed } )" :fixed="fixed" embedded :reload-key="key + reloadKey" @changed="bump" />

		<PayDialog v-model="pay.open" :member="member" :items="pay.items" @paid="bump" />
		<AddPaymentDrawer v-model="add.open" :member="member" @saved="bump" />
	</div>
</template>
