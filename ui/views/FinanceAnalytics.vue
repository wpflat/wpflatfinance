<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { AnalyticsBar, Chart, ChartCard, ElMessage, api, errorMessage, labelOf, navigate, sdk, store } from '../core';
import { downloadCsv, fmtMoney, rangeFor, symbolOf } from '../util';
import { Download } from 'lucide-vue-next';

const filters = reactive( { period: '90d', ...rangeFor( '90d' ), department_id: 0, currency: 'all' } );
const incomeBasis = ref( 'invoiced' );
const payBasis = ref( 'earned' );
const data = ref( null );
const loading = ref( true );

const active = computed( () => ( store.meta.settings.currencies || [] ).filter( ( c ) => c.active !== false ) );
const base = computed( () => store.meta.settings.base_currency || store.meta.settings.default_currency );
const currencyChoices = computed( () => ( active.value.length > 1 ? [ { value: 'all', label: 'All' }, ...active.value.map( ( c ) => ( { value: c.code, label: c.code + ' ' + c.symbol } ) ), { value: 'combined', label: 'Combined ' + symbolOf( base.value ).trim() } ] : null ) );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( 'finance/analytics', { from: filters.from, to: filters.to, department_id: filters.department_id, currency: filters.currency, income_basis: incomeBasis.value, pay_basis: payBasis.value } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => [ filters.from, filters.to, filters.department_id, filters.currency, incomeBasis.value, payBasis.value ], load );

const curs = computed( () => ( data.value ? data.value.currencies : [] ) );
const money = ( n, c ) => fmtMoney( n, c );
const many = computed( () => curs.value.length > 1 );
const combined = computed( () => data.value && data.value.view === 'combined' );
const rateLine = computed( () => {
	if ( ! combined.value ) return '';
	const r = data.value.rates || {};
	const parts = Object.keys( r ).map( ( c ) => `1 ${ c } = ${ fmtMoney( r[ c ], data.value.base ) }` );
	return parts.join( ' · ' );
} );

const sum = ( c ) => data.value.summary[ c ];
const empty = computed( () => ! data.value || ! curs.value.length );
const seriesFor = ( c, keys ) => keys.map( ( k ) => ( { label: { income: 'Income', costs: 'Costs', profit: 'Profit' }[ k ], data: data.value.series[ c ][ k ], color: { income: 'var(--bd-accent)', costs: '#c3c8d2', profit: 'var(--bd-success)' }[ k ] } ) );
const deptRows = ( c ) => ( data.value ? data.value.departments.filter( ( d ) => d.values[ c ] && d.values[ c ].income > 0 ) : [] );
const catRows = ( c ) => ( data.value ? data.value.categories.filter( ( x ) => x.values[ c ] ) : [] );
const AGE = [ [ 'current', 'Not due' ], [ 'd30', '1–30 days' ], [ 'd60', '31–60' ], [ 'd61', '60+' ] ];
const ageTotal = ( c ) => AGE.reduce( ( t, [ k ] ) => t + ( data.value.ageing[ c ] ? data.value.ageing[ c ][ k ] : 0 ), 0 );
const clients = computed( () => ( data.value ? data.value.clients : [] ) );
const projects = computed( () => ( data.value ? data.value.projects : [] ) );
const recur = ( c ) => {
	const i = ( data.value.recurring.income[ c ] || { amount: 0 } ).amount;
	const o = ( data.value.recurring.costs[ c ] || { amount: 0 } ).amount;
	return { i, o, net: Math.round( ( i - o ) * 100 ) / 100 };
};
const showMix = computed( () => data.value && data.value.view === 'all' && data.value.mix.length > 1 && data.value.mix.every( ( m ) => m.share_pct !== null ) );
const unit = computed( () => ( { day: 'day', week: 'week', month: 'month' }[ data.value ? data.value.granularity : 'month' ] ) );

const download = async ( type ) => {
	try {
		const r = await api.getPath( 'finance/export', { type, from: filters.from, to: filters.to, department_id: filters.department_id || '', currency: [ 'all', 'combined' ].includes( filters.currency ) ? '' : filters.currency } );
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
const summaryCsv = () => {
	const rows = curs.value.map( ( c ) => ( { currency: c, income: sum( c ).income, direct_costs: sum( c ).direct, team_pay: sum( c ).team, expenses: sum( c ).expenses, profit: sum( c ).profit, margin_pct: sum( c ).margin_pct } ) );
	downloadCsv( `finance-summary-${ filters.from }-to-${ filters.to }.csv`, rows );
};
</script>

<template>
	<div v-loading="loading" class="bd-fan">
		<AnalyticsBar :model-value="filters" :currencies="currencyChoices" @update:model-value="Object.assign( filters, $event )">
			<el-radio-group v-model="incomeBasis" class="bd-seg-sm" aria-label="Income basis"><el-radio-button value="invoiced">Invoiced</el-radio-button><el-radio-button value="paid">Paid</el-radio-button></el-radio-group>
			<el-radio-group v-model="payBasis" class="bd-seg-sm" aria-label="Team pay basis"><el-radio-button value="earned">Pay earned</el-radio-button><el-radio-button value="paid">Pay paid</el-radio-button></el-radio-group>
		</AnalyticsBar>

		<el-alert v-if="combined" type="info" show-icon :closable="false" class="bd-mb" title="Combined figures are an estimate">
			<template #default>Converted to {{ data.base }} at the rates set in <a href="#" @click.prevent="navigate( '/settings?tab=money' )">Settings → Currencies</a><template v-if="rateLine"> ({{ rateLine }})</template>.</template>
		</el-alert>
		<el-alert v-if="data && data.missing.length" type="warning" show-icon :closable="false" class="bd-mb" :title="`No exchange rate for ${ data.missing.join( ', ' ) } — left out of the combined figures`">
			<template #default><a href="#" @click.prevent="navigate( '/settings?tab=money' )">Add the rate in Settings → Currencies</a></template>
		</el-alert>

		<p v-if="empty && !loading" class="bd-muted">No income, costs or expenses in this period. Try a longer period.</p>

		<template v-if="!empty">
			<div class="bd-sumtable bd-mb">
				<div class="bd-sumtable__row bd-sumtable__head">
					<span>{{ many ? 'Currency' : '' }}</span><span>Income</span><span>Direct costs</span><span>Team pay</span><span>Expenses</span><span>Profit</span><span>Margin</span>
				</div>
				<div v-for="c in curs" :key="c" class="bd-sumtable__row">
					<span class="bd-strong">{{ c }}</span>
					<span class="bd-num">{{ money( sum( c ).income, c ) }}</span>
					<span class="bd-num">{{ money( sum( c ).direct, c ) }}</span>
					<span class="bd-num">{{ money( sum( c ).team, c ) }}</span>
					<span class="bd-num">{{ money( sum( c ).expenses, c ) }}</span>
					<span :class="[ 'bd-num bd-strong', sum( c ).profit < 0 && 'bd-late' ]">{{ money( sum( c ).profit, c ) }}</span>
					<span class="bd-muted">{{ sum( c ).income > 0 ? sum( c ).margin_pct + '%' : '—' }}</span>
				</div>
			</div>

			<div class="bd-angrid">
				<ChartCard v-for="c in curs.filter( ( x ) => data.series[ x ] )" :key="'t' + c" :title="'Income and costs' + ( many ? ' — ' + c : '' )" :sub="'Per ' + unit">
					<Chart type="bar" :labels="data.buckets" :series="seriesFor( c, [ 'income', 'costs' ] )" :currency="c" />
				</ChartCard>
				<ChartCard v-for="c in curs.filter( ( x ) => data.series[ x ] )" :key="'p' + c" :title="'Profit' + ( many ? ' — ' + c : '' )" :sub="'Per ' + unit">
					<Chart type="line" :labels="data.buckets" :series="seriesFor( c, [ 'profit' ] )" :currency="c" />
				</ChartCard>
			</div>

			<div class="bd-angrid bd-mt">
				<ChartCard v-for="c in curs.filter( ( x ) => deptRows( x ).length )" :key="'d' + c" :title="'Income by department' + ( many ? ' — ' + c : '' )" sub="All departments, side by side">
					<Chart type="doughnut" :labels="deptRows( c ).map( ( d ) => d.name )" :series="[ { label: 'Income', data: deptRows( c ).map( ( d ) => d.values[ c ].income ) } ]" :currency="c" :height="230" />
					<table class="bd-mini"><tbody>
						<tr v-for="d in deptRows( c )" :key="d.id"><td>{{ d.name }}</td><td class="bd-num">{{ money( d.values[ c ].income, c ) }}</td><td :class="[ 'bd-num', d.values[ c ].profit < 0 && 'bd-late' ]">{{ money( d.values[ c ].profit, c ) }} profit</td></tr>
					</tbody></table>
				</ChartCard>
				<ChartCard v-for="c in curs.filter( ( x ) => catRows( x ).length )" :key="'e' + c" :title="'Expenses by category' + ( many ? ' — ' + c : '' )">
					<Chart type="doughnut" :labels="catRows( c ).map( ( x ) => labelOf( 'expense_category', x.category ) )" :series="[ { label: 'Spent', data: catRows( c ).map( ( x ) => x.values[ c ] ) } ]" :currency="c" :height="230" />
				</ChartCard>
				<ChartCard v-for="c in curs.filter( ( x ) => data.ageing[ x ] )" :key="'a' + c" :title="'Receivables ageing' + ( many ? ' — ' + c : '' )" :sub="'Unpaid invoices, ' + money( ageTotal( c ), c ) + ' in total'">
					<Chart type="bar" :labels="AGE.map( ( a ) => a[ 1 ] )" :series="[ { label: sdk.fmt( 'Owed by {clients}' ), data: AGE.map( ( [ k ] ) => data.ageing[ c ][ k ] ), color: 'var(--bd-accent)' } ]" :currency="c" :height="230" />
				</ChartCard>
				<ChartCard v-if="showMix" title="Currency mix" sub="Share of income, at current rates">
					<Chart type="doughnut" :labels="data.mix.map( ( m ) => m.currency )" :series="[ { label: 'Income', data: data.mix.map( ( m ) => m.share_pct ) } ]" :height="230" />
					<table class="bd-mini"><tbody><tr v-for="m in data.mix" :key="m.currency"><td>{{ m.currency }}</td><td class="bd-num">{{ money( m.amount, m.currency ) }}</td><td class="bd-num">{{ m.share_pct }}%</td></tr></tbody></table>
				</ChartCard>
				<ChartCard title="Every month, before anything new happens" sub="Recurring income vs salaries and recurring expenses">
					<table class="bd-mini"><tbody>
						<template v-for="c in curs" :key="c">
							<tr><td class="bd-strong">{{ many ? c + ' — ' : '' }}Recurring income</td><td class="bd-num">{{ money( recur( c ).i, c ) }}</td></tr>
							<tr><td>Salaries + recurring expenses</td><td class="bd-num">−{{ money( recur( c ).o, c ) }}</td></tr>
							<tr><td :class="[ 'bd-strong', recur( c ).net < 0 && 'bd-late' ]">{{ recur( c ).net >= 0 ? 'Left over' : 'Short by' }}</td><td :class="[ 'bd-num bd-strong', recur( c ).net < 0 && 'bd-late' ]">{{ money( Math.abs( recur( c ).net ), c ) }}</td></tr>
						</template>
					</tbody></table>
				</ChartCard>
			</div>

			<div class="bd-angrid bd-mt">
				<ChartCard :title="$fmt( 'Best {clients}' )" sub="By what we keep">
					<el-table :data="clients" size="small" empty-text="No invoices in this period.">
						<el-table-column :label="$t( 'client' )" min-width="160"><template #default="{ row }"><a class="bd-link" :href="'#/clients/' + row.id">{{ row.name }}</a></template></el-table-column>
						<el-table-column label="Income" align="right" min-width="110"><template #default="{ row }">{{ money( row.income, row.currency ) }}</template></el-table-column>
						<el-table-column label="We keep" align="right" min-width="110"><template #default="{ row }"><strong>{{ money( row.keep, row.currency ) }}</strong></template></el-table-column>
					</el-table>
				</ChartCard>
				<ChartCard title="Projects" sub="Price minus team pay">
					<el-table :data="projects" size="small" empty-text="No priced projects match.">
						<el-table-column label="Project" min-width="170"><template #default="{ row }"><a class="bd-link" :href="'#/projects/' + row.id">{{ row.name }}</a></template></el-table-column>
						<el-table-column label="Price" align="right" min-width="110"><template #default="{ row }">{{ money( row.price, row.currency ) }}</template></el-table-column>
						<el-table-column label="Profit" align="right" min-width="110"><template #default="{ row }"><strong :class="row.margin < 0 && 'bd-late'">{{ money( row.margin, row.currency ) }}</strong></template></el-table-column>
					</el-table>
				</ChartCard>
			</div>
		</template>

		<div class="bd-exports bd-mt">
			<span class="bd-muted">Download for your accountant (this period, department and currency):</span>
			<el-button size="small" @click="summaryCsv"><Download :size="14" /> Summary</el-button>
			<el-button size="small" @click="download( 'income' )"><Download :size="14" /> Income lines</el-button>
			<el-button size="small" @click="download( 'expenses' )"><Download :size="14" /> Expenses</el-button>
			<el-button size="small" @click="download( 'team' )"><Download :size="14" /> Team pay</el-button>
		</div>
	</div>
</template>
