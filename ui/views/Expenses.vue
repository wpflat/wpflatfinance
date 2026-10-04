<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { ElMessage, ResourceTable, StatCard, api, dept, errorMessage, labelOf, options, today } from '../core';
import { Wallet, TrendCharts, Coin, Collection } from '@element-plus/icons-vue';
import MoneyMap from '../components/MoneyMap.vue';
import { expensesConfig, recurringExpensesConfig } from '../resources';

const view = ref( 'expenses' );
const period = ref( 'month' );
const key = ref( 0 );
const summary = ref( null );
const adding = ref( false );
const quick = ref( { description: '', amount: null, category: ( options( 'expense_category' )[ 0 ] || {} ).value || '', spent_on: today() } );

const PERIODS = [ { id: 'month', label: 'This month' }, { id: 'last', label: 'Last month' }, { id: 'year', label: 'This year' }, { id: 'all', label: 'All time' } ];
const range = computed( () => {
	const d = new Date();
	const y = d.getFullYear();
	const m = d.getMonth();
	const iso = ( dt ) => `${ dt.getFullYear() }-${ String( dt.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( dt.getDate() ).padStart( 2, '0' ) }`;
	if ( period.value === 'month' ) return { from: iso( new Date( y, m, 1 ) ), to: iso( new Date( y, m + 1, 0 ) ) };
	if ( period.value === 'last' ) return { from: iso( new Date( y, m - 1, 1 ) ), to: iso( new Date( y, m, 0 ) ) };
	if ( period.value === 'year' ) return { from: `${ y }-01-01`, to: `${ y }-12-31` };
	return {};
} );

const load = async () => {
	try {
		summary.value = await api.getPath( 'expenses/summary' );
	} catch ( e ) {
		summary.value = null;
	}
};
onMounted( load );
watch( key, load );

const biggest = computed( () => {
	const cats = summary.value ? Object.entries( summary.value.by_category || {} ) : [];
	if ( ! cats.length ) return null;
	const total = ( m ) => Object.values( m ).reduce( ( n, v ) => n + v.amount, 0 );
	cats.sort( ( a, b ) => total( b[ 1 ] ) - total( a[ 1 ] ) );
	return { label: labelOf( 'expense_category', cats[ 0 ][ 0 ] ), map: cats[ 0 ][ 1 ] };
} );

const addQuick = async () => {
	if ( ! quick.value.amount || adding.value ) return;
	adding.value = true;
	try {
		await api.create( 'expenses', { ...quick.value } );
		quick.value.description = '';
		quick.value.amount = null;
		key.value++;
		ElMessage.success( 'Expense added' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		adding.value = false;
	}
};
const postDue = async () => {
	try {
		const r = await api.post( 'expenses/post-due' );
		ElMessage.success( r.posted ? `${ r.posted } recurring expense${ r.posted === 1 ? '' : 's' } posted` : 'Nothing is due yet' );
		key.value++;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
</script>

<template>
	<div class="bd-expenses">
		<el-row :gutter="16" class="bd-mb">
			<el-col :xs="12" :md="6"><StatCard label="Spent this month" :icon="Wallet" sub="All expenses"><MoneyMap :map="summary && summary.this_month" empty="Nothing yet" /></StatCard></el-col>
			<el-col :xs="12" :md="6"><StatCard label="Last month" :icon="Collection" sub="For comparison"><MoneyMap :map="summary && summary.last_month" empty="Nothing" /></StatCard></el-col>
			<el-col :xs="12" :md="6"><StatCard label="Recurring per month" :icon="TrendCharts" sub="Rent, software, subscriptions"><MoneyMap :map="summary && summary.recurring_monthly" empty="Nothing" /></StatCard></el-col>
			<el-col :xs="12" :md="6"><StatCard label="Biggest this month" :icon="Coin" :sub="biggest ? biggest.label : 'No expenses yet'"><MoneyMap :map="biggest ? biggest.map : {}" empty="—" /></StatCard></el-col>
		</el-row>

		<el-radio-group v-model="view" class="bd-seg-sm bd-mb" aria-label="Expenses view">
			<el-radio-button value="expenses">Expenses</el-radio-button>
			<el-radio-button value="recurring">Recurring</el-radio-button>
		</el-radio-group>

		<template v-if="view === 'expenses'">
			<div class="bd-quickadd bd-quickadd--expense">
				<el-input v-model="quick.description" size="large" placeholder="What was it for? e.g. Figma plan" aria-label="What was it for" @keyup.enter="addQuick" />
				<el-select v-model="quick.category" size="large" style="width: 200px" aria-label="Category"><el-option v-for="o in options( 'expense_category' )" :key="o.value" :label="o.label" :value="o.value" /></el-select>
				<el-input-number v-model="quick.amount" size="large" :min="0" :precision="2" :controls="false" placeholder="Amount" style="width: 150px" aria-label="Amount" @keyup.enter="addQuick" />
				<el-button type="primary" size="large" :loading="adding" :disabled="!quick.amount" @click="addQuick">Add</el-button>
			</div>
			<ResourceTable :config="expensesConfig()" :params="range" embedded :reload-key="key" @changed="key++">
				<template #toolbar>
					<el-radio-group v-model="period" class="bd-seg-sm" aria-label="Period"><el-radio-button v-for="p in PERIODS" :key="p.id" :value="p.id">{{ p.label }}</el-radio-button></el-radio-group>
				</template>
			</ResourceTable>
		</template>
		<ResourceTable v-else :config="recurringExpensesConfig()" embedded :reload-key="key" @changed="key++">
			<template #toolbar><el-button @click="postDue">Post due now</el-button></template>
		</ResourceTable>
	</div>
</template>
