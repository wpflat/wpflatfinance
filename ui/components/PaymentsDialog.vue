<script setup>
import { reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox, FormField, StatusTag, api, errorMessage, fmtDate, today } from '../core';
import { PAYMENT_METHODS, fmtMoney } from '../util';

const props = defineProps( { modelValue: Boolean, invoiceId: { type: Number, default: 0 } } );
const emit = defineEmits( [ 'update:modelValue', 'changed' ] );

const inv = ref( null );
const error = ref( '' );
const saving = ref( false );
const form = reactive( { amount: undefined, paid_on: today(), method: 'Bank transfer', reference: '', notes: '' } );
const fields = [
	{ name: 'amount', label: 'Amount', type: 'number', required: true },
	{ name: 'paid_on', label: 'Payment date', type: 'date' },
	{ name: 'method', label: 'Method', type: 'select', required: true, options: PAYMENT_METHODS },
	{ name: 'reference', label: 'Reference' },
	{ name: 'notes', label: 'Notes' },
];

const load = async () => {
	try {
		inv.value = await api.get( 'invoices', props.invoiceId );
		const next = ( inv.value.schedule || [] ).find( ( s ) => s.balance > 0 );
		form.amount = next ? next.balance : inv.value.balance > 0 ? inv.value.balance : undefined;
		form.paid_on = today();
		form.reference = '';
		form.method = 'Bank transfer';
		form.notes = '';
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
watch( () => [ props.modelValue, props.invoiceId ], ( [ open ] ) => {
	if ( open && props.invoiceId ) {
		inv.value = null;
		load();
	}
} );

const add = async () => {
	saving.value = true;
	try {
		await api.create( 'payments', { invoice_id: props.invoiceId, ...form } );
		ElMessage.success( 'Payment recorded' );
		await load();
		emit( 'changed' );
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		saving.value = false;
	}
};

const del = async ( p ) => {
	try {
		await ElMessageBox.confirm( 'Delete this payment?', 'Delete payment', { type: 'warning' } );
	} catch ( e ) {
		return;
	}
	try {
		await api.remove( 'payments', p.id );
		await load();
		emit( 'changed' );
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
</script>

<template>
	<el-dialog :model-value="modelValue" :title="inv ? 'Payments — ' + inv.invoice_number : 'Payments'" width="760px" append-to-body destroy-on-close @update:model-value="emit( 'update:modelValue', $event )">
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<template v-if="inv">
			<el-descriptions :column="5" border size="small" direction="vertical" class="bd-mb">
				<el-descriptions-item :label="$fmt( '{Client}' )">{{ inv.client_name }}</el-descriptions-item>
				<el-descriptions-item label="Invoice">{{ fmtMoney( inv.amount, inv.currency ) }}</el-descriptions-item>
				<el-descriptions-item label="Paid">{{ fmtMoney( inv.amount_paid, inv.currency ) }}</el-descriptions-item>
				<el-descriptions-item label="Balance"><strong>{{ fmtMoney( inv.balance, inv.currency ) }}</strong></el-descriptions-item>
				<el-descriptions-item label="Status"><StatusTag :status="inv.status" /></el-descriptions-item>
			</el-descriptions>

			<template v-if="inv.has_installments">
				<h4 class="bd-subhead">Payment schedule</h4>
				<el-table :data="inv.schedule" size="small" class="bd-mb">
					<el-table-column prop="label" label="Instalment" min-width="140" />
					<el-table-column label="Due" width="120"><template #default="{ row }">{{ fmtDate( row.due_date ) }}</template></el-table-column>
					<el-table-column label="Amount" align="right" width="110"><template #default="{ row }">{{ fmtMoney( row.amount, inv.currency ) }}</template></el-table-column>
					<el-table-column label="Remaining" align="right" width="110"><template #default="{ row }"><strong>{{ fmtMoney( row.balance, inv.currency ) }}</strong></template></el-table-column>
					<el-table-column label="" width="100" align="right"><template #default="{ row }"><span :class="[ 'bd-badge', 'bd-badge--' + ( { paid: 'success', partial: 'warning', overdue: 'danger', due: 'warning', upcoming: 'neutral' }[ row.state ] ) ]">{{ { paid: 'Paid', partial: 'Part-paid', overdue: 'Overdue', due: 'Due', upcoming: 'Upcoming' }[ row.state ] }}</span></template></el-table-column>
				</el-table>
			</template>

			<h4 class="bd-subhead">Invoice lines</h4>
			<el-table :data="inv.items" size="small" class="bd-mb">
				<template #empty>No line items (manual invoice).</template>
				<el-table-column label="Description" min-width="200"><template #default="{ row }"><div class="bd-strong">{{ row.description }}</div><div v-if="row.service_name" class="bd-muted bd-small">{{ row.service_name }}</div></template></el-table-column>
				<el-table-column label="Period" min-width="170"><template #default="{ row }"><span v-if="row.period_start" class="bd-muted">{{ fmtDate( row.period_start ) }} → {{ fmtDate( row.period_end ) }}</span><span v-else class="bd-muted">—</span></template></el-table-column>
				<el-table-column label="Amount" align="right" min-width="110"><template #default="{ row }">{{ fmtMoney( row.amount, inv.currency ) }}</template></el-table-column>
			</el-table>

			<h4 class="bd-subhead">Payments</h4>
			<el-table :data="inv.payments" size="small">
				<template #empty>No payments recorded.</template>
				<el-table-column label="Date"><template #default="{ row }">{{ fmtDate( row.paid_on ) }}</template></el-table-column>
				<el-table-column prop="reference" label="Reference" />
				<el-table-column label="Amount" align="right"><template #default="{ row }">{{ fmtMoney( row.amount, row.currency ) }}</template></el-table-column>
				<el-table-column width="70" align="right"><template #default="{ row }"><el-button size="small" text type="danger" @click="del( row )">Delete</el-button></template></el-table-column>
			</el-table>

			<template v-if="inv.status !== 'cancelled'">
				<h4 class="bd-subhead">Record a payment</h4>
				<el-form label-position="top" @submit.prevent>
					<el-row :gutter="16">
						<el-col v-for="f in fields" :key="f.name" :span="12" :xs="24">
							<el-form-item :label="f.label" :required="f.required"><FormField :field="f" :values="form" /></el-form-item>
						</el-col>
					</el-row>
				</el-form>
				<el-button type="primary" :loading="saving" @click="add">Add payment</el-button>
			</template>
		</template>
		<el-skeleton v-else :rows="4" animated />
	</el-dialog>
</template>
