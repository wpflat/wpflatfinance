<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { ElMessage, api, dept, errorMessage, navigate, sdk, store } from '../core';
import { fmtMoney } from '../util';
import { Download } from '@element-plus/icons-vue';
import MoneyMap from '../components/MoneyMap.vue';

const period = ref( 'month' );
const custom = ref( null );
const incomeBasis = ref( 'invoiced' );
const payBasis = ref( 'earned' );
const department = ref( '' );
const departments = ref( [] );
const data = ref( null );
const loading = ref( true );
const currency = ref( '' );

const iso = ( d ) => `${ d.getFullYear() }-${ String( d.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( d.getDate() ).padStart( 2, '0' ) }`;
const range = computed( () => {
	const d = new Date();
	const y = d.getFullYear();
	const m = d.getMonth();
	if ( period.value === 'month' ) return [ iso( new Date( y, m, 1 ) ), iso( new Date( y, m + 1, 0 ) ) ];
	if ( period.value === 'last' ) return [ iso( new Date( y, m - 1, 1 ) ), iso( new Date( y, m, 0 ) ) ];
	if ( period.value === 'quarter' ) { const q = Math.floor( m / 3 ) * 3; return [ iso( new Date( y, q, 1 ) ), iso( new Date( y, q + 3, 0 ) ) ]; }
	if ( period.value === 'year' ) return [ `${ y }-01-01`, `${ y }-12-31` ];
	return custom.value && custom.value.length === 2 ? custom.value : [ iso( new Date( y, m, 1 ) ), iso( new Date( y, m + 1, 0 ) ) ];
} );
const PERIODS = [ { id: 'month', label: 'This month' }, { id: 'last', label: 'Last month' }, { id: 'quarter', label: 'This quarter' }, { id: 'year', label: 'This year' }, { id: 'custom', label: 'Custom' } ];

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( 'finance/overview', { from: range.value[ 0 ], to: range.value[ 1 ], income_basis: incomeBasis.value, pay_basis: payBasis.value, department_id: department.value } );
		const codes = Object.keys( data.value.summary );
		if ( ! codes.includes( currency.value ) ) {
			currency.value = codes.includes( store.meta.settings.default_currency ) ? store.meta.settings.default_currency : codes[ 0 ] || store.meta.settings.default_currency;
		}
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( async () => {
	try { departments.value = ( await api.list( 'departments', { per_page: 200 } ) ).items; } catch ( e ) { departments.value = []; }
	load();
} );
watch( [ period, incomeBasis, payBasis, department, custom ], () => ( period.value !== 'custom' || custom.value ) && load() );

const codes = computed( () => ( data.value ? Object.keys( data.value.summary ) : [] ) );
const s = computed( () => ( data.value && data.value.summary[ currency.value ] ) || { income: 0, direct: 0, keep: 0, team: 0, expenses: 0, profit: 0, margin_pct: 0 } );
const money = ( n ) => fmtMoney( n, currency.value );
const STEPS = computed( () => [
	{ key: 'income', label: 'Income', value: s.value.income, op: '', note: incomeBasis.value === 'paid' ? sdk.fmt( 'What {clients} paid' ) : sdk.fmt( 'What {clients} were invoiced' ) },
	{ key: 'direct', label: 'Direct costs', value: s.value.direct, op: '−', note: 'What the things we sold cost us' },
	{ key: 'keep', label: 'What we keep', value: s.value.keep, op: '=', note: 'Income minus direct costs', strong: true },
	{ key: 'team', label: 'Team pay', value: s.value.team, op: '−', note: payBasis.value === 'paid' ? 'Salaries and project pay paid out' : 'Salaries and project pay earned' },
	{ key: 'expenses', label: 'Running expenses', value: s.value.expenses, op: '−', note: 'Rent, tools, ads and other costs' },
	{ key: 'profit', label: 'Profit', value: s.value.profit, op: '=', note: s.value.income > 0 ? `${ s.value.margin_pct }% of income` : 'No income in this period', strong: true, final: true },
] );

const chart = computed( () => {
	const c = data.value && data.value.chart.currencies[ currency.value ];
	if ( ! c ) return null;
	const max = Math.max( 1, ...c.income, ...c.costs );
	return data.value.chart.labels.map( ( l, i ) => ( { label: l, income: c.income[ i ], costs: c.costs[ i ], profit: c.profit[ i ], ih: Math.round( ( c.income[ i ] / max ) * 100 ), ch: Math.round( ( c.costs[ i ] / max ) * 100 ) } ) );
} );
const bal = ( k ) => ( data.value ? data.value.balances[ k ] : {} );
const recNet = computed( () => {
	const i = ( bal( 'recurring_income' )[ currency.value ] || { amount: 0 } ).amount;
	const o = ( bal( 'recurring_costs' )[ currency.value ] || { amount: 0 } ).amount;
	return { income: i, costs: o, net: Math.round( ( i - o ) * 100 ) / 100 };
} );
const rowsFor = ( list ) => list.filter( ( d ) => d.values[ currency.value ] );
const clients = computed( () => ( data.value ? data.value.clients.filter( ( c ) => c.currency === currency.value ) : [] ) );
const projects = computed( () => ( data.value ? data.value.projects.filter( ( p ) => p.currency === currency.value ) : [] ) );

const download = async ( type ) => {
	try {
		const r = await api.getPath( 'finance/export', { type, from: range.value[ 0 ], to: range.value[ 1 ] } );
		const url = URL.createObjectURL( new Blob( [ r.csv ], { type: 'text/csv;charset=utf-8' } ) );
		const a = document.createElement( 'a' );
		a.href = url;
		a.download = r.filename;
		a.click();
		URL.revokeObjectURL( url );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
</script>

<template>
	<div v-loading="loading" class="bd-fin">
		<div class="bd-toolbar bd-mb">
			<el-radio-group v-model="period" class="bd-seg-sm" aria-label="Period"><el-radio-button v-for="p in PERIODS" :key="p.id" :value="p.id">{{ p.label }}</el-radio-button></el-radio-group>
			<el-date-picker v-if="period === 'custom'" v-model="custom" type="daterange" value-format="YYYY-MM-DD" range-separator="to" start-placeholder="From" end-placeholder="To" />
			<span class="bd-toolbar__spacer" />
			<el-select v-model="department" clearable :placeholder="'All ' + dept().lowerMany" class="bd-toolbar__filter" aria-label="Department">
				<el-option v-for="b in departments" :key="b.id" :label="b.name" :value="b.id" />
				<el-option label="General (no department)" value="none" />
			</el-select>
			<el-radio-group v-if="codes.length > 1" v-model="currency" class="bd-seg-sm" aria-label="Currency"><el-radio-button v-for="c in codes" :key="c" :value="c">{{ c }}</el-radio-button></el-radio-group>
		</div>

		<div class="bd-basis bd-mb">
			<span class="bd-muted">Income counted when it is</span>
			<el-radio-group v-model="incomeBasis" size="small"><el-radio-button value="invoiced">Invoiced</el-radio-button><el-radio-button value="paid">Paid</el-radio-button></el-radio-group>
			<span class="bd-muted">Team pay counted when it is</span>
			<el-radio-group v-model="payBasis" size="small"><el-radio-button value="earned">Earned</el-radio-button><el-radio-button value="paid">Paid</el-radio-button></el-radio-group>
		</div>

		<div class="bd-steps6">
			<template v-for="( st, i ) in STEPS" :key="st.key">
				<span v-if="st.op" class="bd-steps6__op" aria-hidden="true">{{ st.op }}</span>
				<div :class="[ 'bd-step', { 'is-strong': st.strong, 'is-final': st.final, 'is-neg': st.final && st.value < 0 } ]">
					<div class="bd-step__label">{{ st.label }}</div>
					<div class="bd-step__value">{{ money( st.value ) }}</div>
					<div class="bd-step__note">{{ st.note }}</div>
				</div>
			</template>
		</div>

		<el-row :gutter="20" class="bd-mb">
			<el-col :xs="24" :lg="14">
				<el-card shadow="never" class="bd-widget">
					<template #header><div class="bd-widget__head"><span>Last 6 months</span><span class="bd-legend"><span><i style="background: var(--bd-accent)" />Income</span><span><i style="background: #d0d5dd" />Costs</span></span></div></template>
					<div v-if="chart" class="bd-bars">
						<div v-for="c in chart" :key="c.label" class="bd-bars__col">
							<div class="bd-bars__pair"><span class="bd-bars__bar is-in" :style="{ height: c.ih + '%' }" :title="'Income ' + money( c.income )" /><span class="bd-bars__bar is-out" :style="{ height: c.ch + '%' }" :title="'Costs ' + money( c.costs )" /></div>
							<div class="bd-bars__label">{{ c.label }}</div>
							<div :class="[ 'bd-bars__profit', c.profit < 0 ? 'bd-late' : '' ]">{{ money( c.profit ) }}</div>
						</div>
					</div>
					<el-empty v-else description="No income or costs yet." :image-size="72" />
				</el-card>
			</el-col>
			<el-col :xs="24" :lg="10">
				<el-card shadow="never" class="bd-widget">
					<template #header><div class="bd-widget__head"><span>Money owed</span></div></template>
					<ul class="bd-list">
						<li><div><div class="bd-strong">{{ $fmt( 'Owed by {clients}' ) }}</div><div class="bd-muted bd-small">Unpaid invoices</div></div><MoneyMap :map="bal( 'receivable' )" empty="Nothing" /></li>
						<li><div><div class="bd-strong">Owed to the team</div><div class="bd-muted bd-small">Earned, not paid yet</div></div><a class="bd-link" href="#" @click.prevent="navigate( '/money/payroll' )"><MoneyMap :map="bal( 'payable' )" empty="Nothing" /></a></li>
					</ul>
					<div class="bd-costbox">
						<div class="bd-muted bd-small">Every month, before anything new happens</div>
						<div class="row"><span>Recurring income</span><span class="bd-money">{{ money( recNet.income ) }}</span></div>
						<div class="row"><span>Salaries + recurring expenses</span><span class="bd-money">−{{ money( recNet.costs ) }}</span></div>
						<div :class="[ 'row bd-strong', recNet.net < 0 ? 'bd-late' : '' ]"><span>{{ recNet.net >= 0 ? 'Left over' : 'Short by' }}</span><span class="bd-money">{{ money( Math.abs( recNet.net ) ) }}</span></div>
					</div>
				</el-card>
			</el-col>
		</el-row>

		<el-row :gutter="20" class="bd-mb">
			<el-col :xs="24" :lg="12">
				<el-card shadow="never" class="bd-widget">
					<template #header><div class="bd-widget__head"><span>By {{ dept().lower }}</span></div></template>
					<el-table :data="data ? rowsFor( data.departments ) : []" size="small" empty-text="No figures in this period.">
						<el-table-column label="Name" min-width="150"><template #default="{ row }"><span class="bd-strong">{{ row.name }}</span></template></el-table-column>
						<el-table-column label="Income" align="right" min-width="110"><template #default="{ row }">{{ money( row.values[ currency ].income ) }}</template></el-table-column>
						<el-table-column label="We keep" align="right" min-width="110"><template #default="{ row }">{{ money( row.values[ currency ].keep ) }}</template></el-table-column>
						<el-table-column label="Profit" align="right" min-width="110"><template #default="{ row }"><strong :class="row.values[ currency ].profit < 0 ? 'bd-late' : ''">{{ money( row.values[ currency ].profit ) }}</strong></template></el-table-column>
					</el-table>
				</el-card>
			</el-col>
			<el-col :xs="24" :lg="12">
				<el-card shadow="never" class="bd-widget">
					<template #header><div class="bd-widget__head"><span>{{ $fmt( 'Best {clients}' ) }}</span><span class="bd-muted bd-small">By what we keep</span></div></template>
					<el-table :data="clients" size="small" empty-text="No invoices in this period.">
						<el-table-column :label="$t( 'client' )" min-width="160"><template #default="{ row }"><a class="bd-link" :href="'#/clients/' + row.id">{{ row.name }}</a></template></el-table-column>
						<el-table-column label="Income" align="right" min-width="110"><template #default="{ row }">{{ money( row.income ) }}</template></el-table-column>
						<el-table-column label="We keep" align="right" min-width="110"><template #default="{ row }"><strong>{{ money( row.keep ) }}</strong></template></el-table-column>
					</el-table>
				</el-card>
			</el-col>
		</el-row>

		<el-card shadow="never" class="bd-widget bd-mb">
			<template #header><div class="bd-widget__head"><span>Projects</span><span class="bd-muted bd-small">Price minus team pay</span></div></template>
			<el-table :data="projects" size="small" empty-text="No priced projects yet — add a price on a project.">
				<el-table-column label="Project" min-width="200"><template #default="{ row }"><a class="bd-link bd-strong" :href="'#/projects/' + row.id">{{ row.name }}</a><div class="bd-muted bd-small">{{ row.client_name || 'Internal' }}</div></template></el-table-column>
				<el-table-column label="Price" align="right" min-width="120"><template #default="{ row }">{{ money( row.price ) }}</template></el-table-column>
				<el-table-column label="Team pay" align="right" min-width="120"><template #default="{ row }">{{ money( row.team_cost ) }}</template></el-table-column>
				<el-table-column label="Profit" align="right" min-width="120"><template #default="{ row }"><strong :class="row.margin < 0 ? 'bd-late' : ''">{{ money( row.margin ) }}</strong></template></el-table-column>
			</el-table>
		</el-card>

		<div class="bd-exports">
			<span class="bd-muted">Download for your accountant (this period):</span>
			<el-button :icon="Download" size="small" @click="download( 'income' )">Income lines</el-button>
			<el-button :icon="Download" size="small" @click="download( 'expenses' )">Expenses</el-button>
			<el-button :icon="Download" size="small" @click="download( 'team' )">Team pay</el-button>
		</div>
	</div>
</template>
