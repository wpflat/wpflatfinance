<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ElMessage, api, errorMessage, today } from '../core';
import { PAYMENT_METHODS, fmtMoney } from '../util';

const props = defineProps( { modelValue: Boolean, member: { type: Object, default: null }, items: { type: Array, default: () => [] } } );
const emit = defineEmits( [ 'update:modelValue', 'paid' ] );

const form = reactive( { paid_on: today(), method: 'Bank transfer', reference: '', notes: '' } );
const saving = ref( false );
const error = ref( '' );
watch( () => props.modelValue, ( open ) => {
	if ( open ) {
		Object.assign( form, { paid_on: today(), method: 'Bank transfer', reference: '', notes: '' } );
		error.value = '';
	}
} );

const currency = computed( () => ( props.items[ 0 ] ? props.items[ 0 ].currency : '' ) );
const mixed = computed( () => new Set( props.items.map( ( i ) => i.currency ) ).size > 1 );
const total = computed( () => props.items.reduce( ( t, i ) => t + Number( i.amount ), 0 ) );

const submit = async () => {
	saving.value = true;
	error.value = '';
	try {
		const res = await api.post( 'payouts/create', { team_member_id: props.member.id, item_ids: props.items.map( ( i ) => i.id ), ...form } );
		ElMessage.success( `${ res.number } recorded` );
		emit( 'update:modelValue', false );
		emit( 'paid', res );
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		saving.value = false;
	}
};
</script>

<template>
	<el-dialog :model-value="modelValue" title="Record a payout" width="520px" append-to-body destroy-on-close @update:model-value="emit( 'update:modelValue', $event )">
		<template v-if="member">
			<p class="bd-lead">Paying <strong>{{ member.name }}</strong> for {{ items.length }} item{{ items.length === 1 ? '' : 's' }}: <strong>{{ fmtMoney( total, currency ) }}</strong></p>
			<ul class="bd-list bd-mb">
				<li v-for="i in items" :key="i.id"><span>{{ i.description }}</span><span class="bd-money">{{ fmtMoney( i.amount, i.currency ) }}</span></li>
			</ul>
		</template>
		<el-alert v-if="mixed" type="warning" show-icon :closable="false" class="bd-mb" title="These items are in different currencies. Pay one currency at a time." />
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-form label-position="top" @submit.prevent>
			<el-row :gutter="14">
				<el-col :span="12"><el-form-item label="Date paid"><el-date-picker v-model="form.paid_on" type="date" value-format="YYYY-MM-DD" format="MMM D, YYYY" style="width: 100%" :clearable="false" /></el-form-item></el-col>
				<el-col :span="12"><el-form-item label="Paid by"><el-select v-model="form.method" style="width: 100%"><el-option v-for="m in PAYMENT_METHODS" :key="m.value" :label="m.label" :value="m.value" /></el-select></el-form-item></el-col>
				<el-col :span="24"><el-form-item label="Reference (optional)"><el-input v-model="form.reference" placeholder="Transfer reference" /></el-form-item></el-col>
				<el-col :span="24"><el-form-item label="Note (optional)"><el-input v-model="form.notes" type="textarea" :rows="2" /></el-form-item></el-col>
			</el-row>
		</el-form>
		<p class="bd-help">This records money you have already sent. It does not move any money.</p>
		<template #footer>
			<el-button @click="emit( 'update:modelValue', false )">Cancel</el-button>
			<el-button type="primary" :loading="saving" :disabled="mixed || !items.length" @click="submit">Record payout</el-button>
		</template>
	</el-dialog>
</template>
