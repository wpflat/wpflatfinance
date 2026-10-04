<script setup>
import { computed } from 'vue';
import { PageHeader, sdk, store } from '../core';
import { canFinance } from '../util';
import Billing from './Billing.vue';
import Invoices from './Invoices.vue';
import Expenses from './Expenses.vue';
import FinanceOverview from './FinanceOverview.vue';
import FinanceAnalytics from './FinanceAnalytics.vue';
import Payroll from './Payroll.vue';
import Catalog from './Catalog.vue';

const props = defineProps( { section: { type: String, default: '' }, sub: { type: String, default: '' }, query: { type: Object, default: () => ( {} ) } } );

// Overview, Expenses and Payroll need the finance permission; everyone else lands on Invoices.
const section = computed( () => {
	const s = props.section;
	if ( [ 'overview', 'analytics', 'expenses', 'payroll' ].includes( s ) && canFinance() ) {
		return s;
	}
	if ( s === 'catalog' ) {
		return 'catalog';
	}
	if ( s === 'billing' ) {
		return 'billing';
	}
	return canFinance() ? 'overview' : 'billing';
} );
const BILLING = () => ({ list: [ 'Billing', sdk.fmt( 'Everything you charge {clients} — retainers, subscriptions and one-off bills. Tick any mix for one {client} and create a single invoice.' ) ], invoices: [ sdk.tp( 'invoice' ), 'What you have billed, and what is still to be paid.' ] });
// Old URLs: /billing/recurring and /billing/one-off now open the one Billing list, pre-filtered.
const OLD_TYPE = { recurring: 'all', renewals: 'all', 'one-off': 'one_off', charges: 'one_off' };
const billSub = computed( () => ( props.sub === 'invoices' ? 'invoices' : 'list' ) );
const billType = computed( () => OLD_TYPE[ props.sub ] || store.route.query.type || 'all' );
const head = computed( () => ( {
	overview: [ 'Overview', 'What comes in, what goes out, and what is left.' ],
	analytics: [ 'Analytics', 'Income, costs and profit over time — by department and currency.' ],
	billing: BILLING()[ billSub.value ],
	expenses: [ 'Expenses', 'Everything the business spends money on.' ],
	payroll: [ 'Payroll', 'Salaries and project pay: who is owed, and who has been paid.' ],
	catalog: [ sdk.tp( 'catalog' ), 'What you sell: services and products, with their prices and costs.' ],
}[ section.value ] ) );
</script>

<template>
	<div class="bd-finance">
		<PageHeader eyebrow="Money" :title="head[ 0 ]" :description="head[ 1 ]" />
		<FinanceOverview v-if="section === 'overview'" />
		<FinanceAnalytics v-else-if="section === 'analytics'" />
		<template v-else-if="section === 'billing'">
			<Invoices v-if="billSub === 'invoices'" embedded use-route-filters />
			<Billing v-else :type="billType" />
		</template>
		<Expenses v-else-if="section === 'expenses'" />
		<Payroll v-else-if="section === 'payroll'" />
		<Catalog v-else />
	</div>
</template>
