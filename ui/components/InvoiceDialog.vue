<script setup>
import { reactive, ref, watch } from 'vue';
import { ElMessage, sdk, FormField, addDays, api, errorMessage, navigate, store, today } from '../core';
import { fmtMoney } from '../util';

const props = defineProps( { modelValue: Boolean, summary: { type: Object, default: null } } );
const emit = defineEmits( [ 'update:modelValue', 'done' ] );

const fields = [
	{ name: 'title', label: 'Invoice title', wide: true, placeholder: 'What is this invoice for? (optional — we’ll suggest one)' },
	{ name: 'issue_date', label: 'Issue date', type: 'date' },
	{ name: 'due_date', label: 'Due date', type: 'date' },
	{ name: 'notes', label: sdk.fmt( 'Note to {client} (optional)' ), type: 'textarea', wide: true, help: 'Shown on the invoice. You can add instalments, discounts, tax and payment details next.' },
];

const values = reactive( {} );
const saving = ref( false );
const error = ref( '' );

watch(
	() => props.modelValue,
	( open ) => {
		if ( open ) {
			const days = Number( store.meta.settings.default_due_days || 14 );
			Object.assign( values, { title: '', status: 'draft', due_date: addDays( days ), issue_date: today(), notes: '' } );
			error.value = '';
		}
	}
);

const submit = async () => {
	saving.value = true;
	error.value = '';
	try {
		const inv = await api.post( 'invoices/from-sources', {
			client_id: props.summary.client_id,
			charge_ids: props.summary.charge_ids,
			renewal_ids: props.summary.renewal_ids,
			...values,
		} );
		ElMessage.success( `Draft ${ inv.invoice_number } created — review it, then send` );
		emit( 'update:modelValue', false );
		emit( 'done', inv );
		navigate( `/billing/invoices/${ inv.id }` );
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		saving.value = false;
	}
};
</script>

<template>
	<el-dialog :model-value="modelValue" title="Create invoice" width="520px" append-to-body destroy-on-close @update:model-value="emit( 'update:modelValue', $event )">
		<template v-if="summary">
			<p class="bd-lead">
				<strong>{{ summary.client_name }}</strong> · {{ summary.count }} item(s) · total <strong>{{ fmtMoney( summary.total, summary.currency ) }}</strong>
			</p>
			<p v-if="summary.hasRolledIn" class="bd-muted">Includes pending charges that fall due with the selected renewal(s).</p>
		</template>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-form label-position="top" @submit.prevent>
			<el-row :gutter="16">
				<el-col v-for="f in fields" :key="f.name" :span="f.wide ? 24 : 12" :xs="24">
					<el-form-item :label="f.label">
						<FormField :field="f" :values="values" />
						<div v-if="f.help" class="bd-help">{{ f.help }}</div>
					</el-form-item>
				</el-col>
			</el-row>
		</el-form>
		<template #footer>
			<el-button @click="emit( 'update:modelValue', false )">Cancel</el-button>
			<el-button type="primary" :loading="saving" @click="submit">Create draft &amp; review</el-button>
		</template>
	</el-dialog>
</template>
