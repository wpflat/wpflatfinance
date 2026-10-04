<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ResourceTable, StatCard, api, avatarStyle, errorMessage, initials, navigate, today } from '../core';
import { PAYMENT_METHODS, fmtMoney } from '../util';
import { Coin, Wallet, Tickets } from '@element-plus/icons-vue';
import MoneyMap from '../components/MoneyMap.vue';
import AddPaymentDrawer from '../components/AddPaymentDrawer.vue';
import PayDialog from '../components/PayDialog.vue';
import { payoutsConfig } from '../resources';

const period = ref( today().slice( 0, 7 ) );
const data = ref( null );
const loading = ref( true );
const running = ref( false );
const key = ref( 0 );
const addOpen = ref( false );
const selection = ref( [] );
const single = reactive( { open: false, member: null, items: [] } );
const bulk = reactive( { open: false, paid_on: today(), method: 'Bank transfer', reference: '', saving: false, error: '' } );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( 'payroll', { period: period.value } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( period, load );

const rows = computed( () => ( data.value ? data.value.members : [] ) );
const salaryMap = computed( () => ( data.value ? data.value.totals.salaries : {} ) );
const missing = computed( () => ( data.value ? data.value.salaries_missing : 0 ) );
const monthLabel = computed( () => ( data.value ? data.value.label : '' ) );

const run = async () => {
	running.value = true;
	try {
		const r = await api.post( 'payroll/run', { period: period.value } );
		ElMessage.success( r.created ? `${ r.created } salar${ r.created === 1 ? 'y' : 'ies' } added for ${ monthLabel.value }` : 'Salaries for this month are already created' );
		await load();
		key.value++;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		running.value = false;
	}
};
const payOne = ( r ) => {
	single.member = { id: r.id, name: r.name, currency: r.currency };
	single.items = r.items;
	single.open = true;
};
const paid = async () => {
	await load();
	key.value++;
};
const totalText = ( map ) => Object.entries( map || {} ).map( ( [ c, a ] ) => fmtMoney( a, c ) ).join( ' + ' ) || '—';
const bulkPay = async () => {
	bulk.saving = true;
	bulk.error = '';
	try {
		const res = await api.post( 'payroll/pay', { member_ids: selection.value.map( ( r ) => r.id ), paid_on: bulk.paid_on, method: bulk.method, reference: bulk.reference } );
		const bad = res.filter( ( x ) => x.error );
		if ( bad.length ) bulk.error = bad.map( ( b ) => b.error ).join( ' ' );
		const ok = res.filter( ( x ) => x.number );
		if ( ok.length ) ElMessage.success( `${ ok.length } payout${ ok.length === 1 ? '' : 's' } recorded` );
		if ( ! bad.length ) bulk.open = false;
		await paid();
	} catch ( e ) {
		bulk.error = errorMessage( e );
	} finally {
		bulk.saving = false;
	}
};
const selectedTotal = computed( () => {
	const m = {};
	selection.value.forEach( ( r ) => Object.entries( r.owed ).forEach( ( [ c, a ] ) => ( m[ c ] = ( m[ c ] || 0 ) + a ) ) );
	return m;
} );
</script>

<template>
	<div class="bd-payroll">
		<div class="bd-toolbar bd-mb">
			<el-date-picker v-model="period" type="month" value-format="YYYY-MM" format="MMMM YYYY" :clearable="false" aria-label="Month" />
			<span class="bd-toolbar__spacer" />
			<el-button @click="addOpen = true">Add payment</el-button>
			<el-button type="primary" :loading="running" :disabled="!missing" @click="run">{{ missing ? `Run payroll for ${ monthLabel } (${ missing })` : `Salaries for ${ monthLabel } are created` }}</el-button>
		</div>

		<el-row :gutter="16" class="bd-mb">
			<el-col :xs="24" :md="8"><StatCard :label="'Salaries — ' + monthLabel" :icon="Tickets" sub="Created for this month"><MoneyMap :map="salaryMap" empty="None yet" /></StatCard></el-col>
			<el-col :xs="24" :md="8"><StatCard label="Owed to the team" :icon="Coin" :tone="data && Object.keys( data.totals.owed ).length ? 'warning' : ''" sub="Salaries, projects and extras not yet paid"><MoneyMap :map="data ? data.totals.owed : {}" empty="Nothing owed" /></StatCard></el-col>
			<el-col :xs="24" :md="8"><StatCard label="Paid this month" :icon="Wallet" sub="Payouts recorded"><MoneyMap :map="data ? data.totals.paid_in_month : {}" empty="Nothing yet" /></StatCard></el-col>
		</el-row>

		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>Who is owed</span>
				<el-button type="primary" :disabled="!selection.length" @click="bulk.open = true">{{ selection.length ? `Pay ${ selection.length } ${ selection.length === 1 ? 'person' : 'people' } (${ totalText( selectedTotal ) })` : 'Pay selected people' }}</el-button>
			</div></template>
			<el-table v-loading="loading" :data="rows" row-key="id" empty-text="Nobody is owed anything right now." @selection-change="selection = $event">
				<el-table-column type="selection" width="48" :selectable="( r ) => Object.keys( r.owed ).length > 0" />
				<el-table-column label="Person" min-width="220">
					<template #default="{ row }">
						<div class="bd-cell-client"><span class="bd-ava" :style="avatarStyle( row.name )">{{ initials( row.name ) }}</span><div><a class="bd-strong bd-link" href="#" @click.prevent="navigate( '/team/' + row.id )">{{ row.name }}</a><div class="bd-muted bd-small">{{ [ row.role, row.department_name ].filter( Boolean ).join( ' · ' ) }}</div></div></div>
					</template>
				</el-table-column>
				<el-table-column label="Salary" min-width="170">
					<template #default="{ row }">
						<template v-if="row.salary_due || row.salary">
							<div class="bd-money">{{ fmtMoney( row.salary ? row.salary.amount : row.salary_amount, row.currency ) }}</div>
							<span v-if="!row.salary" class="bd-badge bd-badge--neutral bd-badge--plain">Not created yet</span>
							<span v-else-if="row.salary.status === 'paid'" class="bd-badge bd-badge--success bd-badge--plain">Paid {{ row.salary.payout_number }}</span>
							<span v-else class="bd-badge bd-badge--warning bd-badge--plain">Owed</span>
						</template>
						<span v-else class="bd-muted">No salary</span>
					</template>
				</el-table-column>
				<el-table-column label="Projects & extras" min-width="150" align="right">
					<template #default="{ row }"><MoneyMap :map="Object.fromEntries( Object.entries( row.other_owed ).map( ( [ c, a ] ) => [ c, { amount: a, count: 1 } ] ) )" empty="—" /></template>
				</el-table-column>
				<el-table-column label="Total owed" min-width="150" align="right">
					<template #default="{ row }"><strong class="bd-money">{{ totalText( row.owed ) }}</strong></template>
				</el-table-column>
				<el-table-column width="110" align="right">
					<template #default="{ row }"><el-button size="small" :disabled="!row.items.length" @click="payOne( row )">Pay</el-button></template>
				</el-table-column>
				<el-table-column type="expand">
					<template #default="{ row }">
						<ul class="bd-list bd-pad"><li v-for="i in row.items" :key="i.id"><div><div>{{ i.description }}</div><div class="bd-muted bd-small">{{ [ i.project_name, i.phase_name ].filter( Boolean ).join( ' · ' ) || i.kind }}</div></div><span class="bd-money">{{ fmtMoney( i.amount, i.currency ) }}</span></li></ul>
					</template>
				</el-table-column>
			</el-table>
		</el-card>

		<h3 class="bd-subhead">Payouts</h3>
		<ResourceTable :config="payoutsConfig()" embedded :reload-key="key" @changed="paid" />

		<AddPaymentDrawer v-model="addOpen" @saved="load" />
		<PayDialog v-model="single.open" :member="single.member" :items="single.items" @paid="paid" />
		<el-dialog v-model="bulk.open" title="Pay selected people" width="480px" append-to-body destroy-on-close>
			<p class="bd-lead">Everything each person is owed will be paid in one payout per person: <strong>{{ totalText( selectedTotal ) }}</strong> in total.</p>
			<el-alert v-if="bulk.error" :title="bulk.error" type="error" show-icon :closable="false" class="bd-mb" />
			<el-form label-position="top" @submit.prevent>
				<el-row :gutter="14">
					<el-col :span="12"><el-form-item label="Date paid"><el-date-picker v-model="bulk.paid_on" type="date" value-format="YYYY-MM-DD" format="MMM D, YYYY" style="width: 100%" :clearable="false" /></el-form-item></el-col>
					<el-col :span="12"><el-form-item label="Paid by"><el-select v-model="bulk.method" style="width: 100%"><el-option v-for="m in PAYMENT_METHODS" :key="m.value" :label="m.label" :value="m.value" /></el-select></el-form-item></el-col>
					<el-col :span="24"><el-form-item label="Reference (optional)"><el-input v-model="bulk.reference" placeholder="e.g. October payroll batch" /></el-form-item></el-col>
				</el-row>
			</el-form>
			<p class="bd-help">This records money you have already sent. It does not move any money.</p>
			<template #footer><el-button @click="bulk.open = false">Cancel</el-button><el-button type="primary" :loading="bulk.saving" @click="bulkPay">Record payouts</el-button></template>
		</el-dialog>
	</div>
</template>
