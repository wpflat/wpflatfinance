<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Trash2 } from 'lucide-vue-next';
import { api, errorMessage, ElMessage, RemoteSelect } from '../core';
import { defaultCurrency, fmtMoney } from '../util';

/** What BizyFinance adds to BizyTasks' "Edit phase" pop-up: the phase price, the team budget and who is paid what. */
const props = defineProps( { phase: { type: Object, required: true }, project: { type: Object, default: null }, reload: { type: Function, default: () => {} } } );
const cur = computed( () => ( props.project && props.project.currency ) || defaultCurrency() );
const fin = computed( () => 'team_budget' in props.phase );
const form = reactive( { price: Number( props.phase.price ) || 0, team_budget: Number( props.phase.team_budget ) || 0 } );
const saving = ref( false );
watch( () => props.phase, ( p ) => {
	form.price = Number( p.price ) || 0;
	form.team_budget = Number( p.team_budget ) || 0;
} );

const wrap = async ( fn ) => {
	try {
		await fn();
		await props.reload();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const saveBudget = async () => {
	saving.value = true;
	await wrap( async () => {
		await api.update( 'project-phases', props.phase.id, { price: Number( form.price ) || 0, team_budget: Number( form.team_budget ) || 0 } );
		ElMessage.success( 'Budget saved' );
	} );
	saving.value = false;
};
const pct = computed( () => ( Number( props.phase.team_budget ) > 0 ? Math.min( 100, Math.round( ( props.phase.allocated / props.phase.team_budget ) * 100 ) ) : 0 ) );

const share = reactive( { team_member_id: 0, mode: 'percent', value: null } );
const addShare = () => {
	if ( ! share.team_member_id || share.value === null || share.value === '' ) return;
	wrap( async () => {
		await api.create( 'pay-allocations', { scope: 'phase', scope_id: props.phase.id, team_member_id: share.team_member_id, mode: share.mode, value: share.value } );
		share.team_member_id = 0;
		share.value = null;
	} );
};
const saveShare = ( a, patch ) => wrap( () => api.update( 'pay-allocations', a.id, patch ) );
const removeShare = ( a ) => wrap( () => api.remove( 'pay-allocations', a.id ) );
</script>

<template>
	<div v-if="fin" class="bd-phase-money">
		<el-form label-position="top" @submit.prevent>
			<el-row :gutter="16">
				<el-col :span="12" :xs="24"><el-form-item :label="`Phase price (${ cur })`"><el-input-number v-model="form.price" :min="0" :precision="2" :controls="false" style="width: 100%" /></el-form-item></el-col>
				<el-col :span="12" :xs="24"><el-form-item :label="`Team budget (${ cur })`"><el-input-number v-model="form.team_budget" :min="0" :precision="2" :controls="false" style="width: 100%" /></el-form-item></el-col>
			</el-row>
		</el-form>
		<div class="bd-right bd-mb"><el-button :loading="saving" @click="saveBudget">Save budget</el-button></div>

		<h4 class="bd-subhead">Team pay</h4>
		<div class="bd-prog">
			<div class="bd-prog__bar"><span :class="{ 'is-over': phase.over_budget }" :style="{ width: pct + '%' }" /></div>
			<div :class="phase.over_budget ? 'bd-late bd-small' : 'bd-muted bd-small'">Shared out {{ fmtMoney( phase.allocated, cur ) }} of {{ fmtMoney( phase.team_budget, cur ) }}<template v-if="phase.over_budget"> — more than the budget</template></div>
		</div>
		<div class="bd-split">
			<div v-for="a in phase.allocations" :key="a.id" class="bd-split__row">
				<span class="bd-strong">{{ a.member_name }}</span>
				<el-radio-group :model-value="a.mode" size="small" @update:model-value="saveShare( a, { mode: $event } )"><el-radio-button value="percent">%</el-radio-button><el-radio-button value="fixed">{{ cur }}</el-radio-button></el-radio-group>
				<el-input-number :model-value="a.value" :min="0" :precision="2" :controls="false" size="small" @change="saveShare( a, { value: $event } )" />
				<span class="bd-money bd-strong">{{ fmtMoney( a.amount, cur ) }}</span>
				<el-button text circle aria-label="Remove share" @click="removeShare( a )"><Trash2 :size="14" /></el-button>
			</div>
			<p v-if="!phase.allocations.length" class="bd-muted bd-small">Nobody is paid from this phase yet.</p>
			<div class="bd-split__row bd-split__row--new">
				<RemoteSelect :model-value="share.team_member_id" resource="team" :label="( t ) => t.name" :params="{ status: 'active' }" placeholder="Add a person…" @update:model-value="share.team_member_id = $event" />
				<el-radio-group v-model="share.mode" size="small"><el-radio-button value="percent">%</el-radio-button><el-radio-button value="fixed">{{ cur }}</el-radio-button></el-radio-group>
				<el-input-number v-model="share.value" :min="0" :precision="2" :controls="false" size="small" placeholder="Share" />
				<span />
				<el-button size="small" type="primary" :disabled="!share.team_member_id || share.value === null" @click="addShare">Add</el-button>
			</div>
			<p class="bd-help">A percentage is of the team budget. Money is owed to each person when the phase is marked done.</p>
		</div>
	</div>
</template>
