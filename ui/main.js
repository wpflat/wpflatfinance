import { h } from 'vue';
import { Wallet } from 'lucide-vue-next';
import { sdk } from './core';
import { canBilling, canFinance, currencyOptions, defaultCurrency, fmtMoney } from './util';
import Finance from './views/Finance.vue';
import InvoiceEditor from './views/InvoiceEditor.vue';
import ClientBillingTab from './slots/ClientBillingTab.vue';
import ClientHistory from './slots/ClientHistory.vue';
import ClientStats from './slots/ClientStats.vue';
import ClientOverview from './slots/ClientOverview.vue';
import TeamPayTab from './slots/TeamPayTab.vue';
import TeamStats from './slots/TeamStats.vue';
import TeamOverview from './slots/TeamOverview.vue';
import DepartmentStats from './slots/DepartmentStats.vue';
import DepartmentSections from './slots/DepartmentSections.vue';
import DepartmentCardFigures from './slots/DepartmentCardFigures.vue';
import HomeStats from './slots/HomeStats.vue';
import ProjectStats from './slots/ProjectStats.vue';
import ProjectActions from './slots/ProjectActions.vue';
import PhaseBadges from './slots/PhaseBadges.vue';
import PhaseDialog from './slots/PhaseDialog.vue';
import MoneySettings from './settings/MoneySettings.vue';

/** WPFlat Finance registers its screens and what it adds to WPFlat Core's and WPFlat Tasks' pages. Nothing runs unless the module is on. */
const M = 'finance';
sdk.registerIcon( 'finance', Wallet );
sdk.registerFormatter( 'money', ( amount, currency ) => fmtMoney( amount, currency ) );

/** A route screen that shows one section of the money pages. */
const money = ( section, sub = '' ) => ( { props: [ 'sub', 'section', 'query' ], render() { return h( Finance, { section: this.section || section, sub: this.sub || sub, query: this.query } ); } } );

sdk.registerQuickAdd?.( M, [ { label: 'New invoice', route: '/billing/invoices/new' } ] );
sdk.registerRoute( { path: '/finance', module: M, component: money( '' ) } );
sdk.registerRoute( { path: '/finance/:section', module: M, component: money( '' ) } ); // overview, analytics, expenses, payroll
sdk.registerRoute( { path: '/billing', module: M, component: money( 'billing' ) } );
sdk.registerRoute( { path: '/billing/:sub', module: M, component: money( 'billing' ) } ); // invoices, recurring, one-off
sdk.registerRoute( { path: '/billing/invoices/:id', module: M, component: InvoiceEditor } ); // "new" opens a blank one
sdk.registerRoute( { path: '/invoices', module: M, component: money( 'billing', 'invoices' ) } );
sdk.registerRoute( { path: '/renewals', module: M, component: money( 'billing', 'recurring' ) } );
sdk.registerRoute( { path: '/charges', module: M, component: money( 'billing', 'one-off' ) } );
sdk.registerRoute( { path: '/catalog', module: M, component: money( 'catalog' ) } );

// Billing people see billing; finance people also see the figures, expenses and pay.
sdk.registerSlot( 'client.tabs', { id: 'billing', module: M, order: 20, label: 'Billing', component: ClientBillingTab, when: canBilling } );
sdk.registerSlot( 'client.stats', { id: 'finance', module: M, order: 10, component: ClientStats, when: canBilling } );
sdk.registerSlot( 'client.overview', { id: 'finance', module: M, order: 10, component: ClientOverview, when: canBilling } );
sdk.registerSlot( 'client.history', { id: 'finance', module: M, order: 10, component: ClientHistory, when: canBilling } );
sdk.registerSlot( 'team.tabs', { id: 'pay', module: M, order: 20, label: 'Pay', component: TeamPayTab, when: canFinance } );
sdk.registerSlot( 'team.stats', { id: 'finance', module: M, order: 10, component: TeamStats, when: canFinance } );
sdk.registerSlot( 'team.overview', { id: 'finance', module: M, order: 10, component: TeamOverview, when: canFinance } );
sdk.registerSlot( 'department.stats', { id: 'finance', module: M, order: 10, component: DepartmentStats, when: canFinance } );
sdk.registerSlot( 'department.sections', { id: 'finance', module: M, order: 10, component: DepartmentSections, when: canFinance } );
sdk.registerSlot( 'department.card.figures', { id: 'finance', module: M, order: 10, component: DepartmentCardFigures, when: canFinance } );
sdk.registerSlot( 'home.stats', { id: 'finance', module: M, order: 10, component: HomeStats, when: canBilling } );
sdk.registerSlot( 'project.stats', { id: 'finance', module: M, order: 10, component: ProjectStats, when: canFinance } );
sdk.registerSlot( 'project.actions', { id: 'finance', module: M, order: 10, component: ProjectActions, when: canBilling } );
sdk.registerSlot( 'phase.badges', { id: 'finance', module: M, order: 10, component: PhaseBadges, when: canFinance } );
sdk.registerSlot( 'phase.dialog', { id: 'finance', module: M, order: 10, component: PhaseDialog, when: canFinance } );
sdk.registerSlot( 'settings.tabs', { id: 'finance', module: M, order: 30, label: 'Billing', component: MoneySettings, when: canFinance } );

// Fields WPFlat Finance adds to other plugins' forms (the server only sends and saves them for people with the finance permission).
sdk.extend( 'client.fields', {
	id: 'finance-currency',
	module: M,
	order: 40,
	when: canBilling,
	default: () => defaultCurrency(),
	field: { name: 'currency', label: 'Billing currency', type: 'select', required: true, options: currencyOptions, help: 'Charges and invoices default to this currency.' },
} );
const pay = ( id, order, field ) => sdk.extend( 'team.fields', { id: 'finance-' + id, module: M, order, when: canFinance, field } );
pay( 'salary', 40, { name: 'salary_amount', label: 'Salary', type: 'number', help: 'Leave 0 for people who are only paid per project or task.' } );
pay( 'period', 41, { name: 'salary_period', label: 'Salary is paid', type: 'select', options: [ { value: 'monthly', label: 'Monthly' } ] } );
pay( 'currency', 42, { name: 'currency', label: 'Pay currency', type: 'select', options: currencyOptions } );
pay( 'bank', 43, { name: 'bank_name', label: 'Bank' } );
pay( 'account', 44, { name: 'account_name', label: 'Account name' } );
pay( 'number', 45, { name: 'account_number', label: 'Account number' } );
sdk.extend( 'team.details', {
	id: 'finance-pay',
	module: M,
	order: 40,
	when: canFinance,
	label: 'Pay',
	value: ( m ) => ( Number( m.salary_amount ) > 0 ? `${ fmtMoney( m.salary_amount, m.currency ) } per month` : m.kind === 'contractor' ? 'Paid per project' : 'No salary set' ),
} );
sdk.extend( 'team.details', {
	id: 'finance-bank',
	module: M,
	order: 41,
	when: ( ctx ) => canFinance() && !! ( ctx.member && ctx.member.bank_name ),
	label: 'Bank',
	value: ( m ) => [ m.bank_name, m.account_name, m.account_number ].filter( Boolean ).join( ' · ' ),
} );
sdk.extend( 'project.fields', { id: 'finance-price', module: M, order: 60, when: canFinance, field: { name: 'price', label: 'Price', type: 'number', help: 'What the client pays for the whole project.' } } );
sdk.extend( 'project.fields', { id: 'finance-currency', module: M, order: 61, when: canFinance, field: { name: 'currency', label: 'Currency', type: 'select', options: currencyOptions, help: 'Leave empty to use the default currency.' } } );
sdk.extend( 'project.columns', {
	id: 'finance-price',
	module: M,
	order: 60,
	when: canFinance,
	column: { label: 'Price', align: 'right', minWidth: 120, render: ( r ) => ( Number( r.price ) > 0 ? h( 'span', { class: 'bd-money' }, fmtMoney( r.price, r.currency || defaultCurrency() ) ) : h( 'span', { class: 'bd-muted' }, '—' ) ) },
} );
sdk.extend( 'task.fields', { id: 'finance-pay', module: M, order: 60, when: canFinance, field: { name: 'pay_amount', label: 'Pay for this task', type: 'number', help: 'Owed to the person it is assigned to when the task is marked done. Leave 0 for no pay.' } } );

// Lists the owner can edit under Settings → Lists & options.
sdk.extend( 'lookup.types', { id: 'billing_period', module: M, order: 60, label: 'Billing periods' } );
sdk.extend( 'lookup.types', { id: 'billing_treatment', module: M, order: 61, label: 'How it’s billed' } );
sdk.extend( 'lookup.types', { id: 'expense_category', module: M, order: 62, label: 'Expense categories' } );
sdk.extend( 'lookup.fields', {
	id: 'finance-months',
	module: M,
	order: 60,
	when: ( ctx ) => ctx.type === 'billing_period',
	field: { name: 'months', label: 'Months between bills', type: 'number', step: '1', help: '0 for one-time or custom.' },
} );

// What shows in the Activity list.
[ [ 'invoices', 'invoice' ], [ 'payments', null, 'Payments' ], [ 'charges', null, 'One-off bills' ], [ 'renewals', null, 'Recurring bills' ], [ 'expenses', null, 'Expenses' ], [ 'payouts', null, 'Payouts' ], [ 'pay_items', null, 'Team earnings' ], [ 'catalog', 'catalog' ] ].forEach( ( [ value, term, label ], i ) => {
	sdk.extend( 'activity.types', { id: 'finance-' + value, module: M, order: 60 + i, type: { value, label: term ? sdk.tp( term ) : label } } );
} );
