<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Badge, ElMessage, RecordDrawer, RemoteSelect, api, dept, errorMessage, fmtDate, labelOf, navigate, store } from '../core';
import { fmtMoney, summariseSelection } from '../util';
import { Plus } from '@element-plus/icons-vue';
import BillStatus from '../components/BillStatus.vue';
import BillingState from '../components/BillingState.vue';
import FilterBar from '../components/FilterBar.vue';
import InvoiceDialog from '../components/InvoiceDialog.vue';
import MoneyMap from '../components/MoneyMap.vue';
import BulkDepartmentDialog from '../components/BulkDepartmentDialog.vue';
import SubscriptionDrawer from '../components/SubscriptionDrawer.vue';
import { oneOffConfig, recurringConfig } from '../resources';

const props = defineProps( {
	fixed: { type: Object, default: () => ( {} ) },
	embedded: Boolean,
	currency: String,
	type: { type: String, default: 'all' },
	reloadKey: { type: Number, default: 0 },
} );
const emit = defineEmits( [ 'changed' ] );

const TYPES = { retainer: 'Retainer', subscription: 'Subscription', one_off: 'One-off' };
const TYPE_TONE = { retainer: 'neutral', subscription: 'info', one_off: 'neutral' };
const STATUS_OPTIONS = [
	{ value: 'action', label: 'Needs attention' },
	{ value: 'not_invoiced', label: 'To invoice' },
	{ value: 'invoice_draft', label: 'Invoice draft' },
	{ value: 'awaiting', label: 'Awaiting payment' },
	{ value: 'overdue', label: 'Overdue' },
	{ value: 'paid', label: 'Paid' },
	{ value: 'all', label: 'Everything' },
];

const type = ref( [ 'retainer', 'subscription', 'one_off' ].includes( props.type ) ? props.type : 'all' );
const status = ref( 'action' );
const currency = ref( '' );
const department = ref( Number( store.route.query.department_id ) || 0 );
const search = ref( '' );
const page = ref( 1 );
const loading = ref( true );
const error = ref( '' );
const data = reactive( { items: [], total: 0, per_page: 25, chips: { all: 0, retainer: 0, subscription: 0, one_off: 0 }, money: { to_invoice: {}, outstanding: {} } } );
const selected = ref( [] ); // rows, kept across pages
const table = ref( null );
const dialog = ref( false );
const deptDialog = ref( false );
// Subscriptions and retainers are changed on the subscription itself, so all its future bills follow.
const bulkItems = computed( () => [ ...new Map( selected.value.map( ( r ) => [ r.kind === 'charge' ? 'charges:' + r.charge_id : 'client-services:' + r.client_service_id, { resource: r.kind === 'charge' ? 'charges' : 'client-services', id: r.kind === 'charge' ? r.charge_id : r.client_service_id } ] ) ).values() ] );
const invoiceSummary = ref( null );
let seq = 0;
let timer = null;

const chips = computed( () => [
	{ id: 'all', label: 'All', count: data.chips.all },
	{ id: 'retainer', label: 'Retainers', count: data.chips.retainer },
	{ id: 'subscription', label: 'Subscriptions', count: data.chips.subscription },
	{ id: 'one_off', label: 'One-off', count: data.chips.one_off },
] );
const currencies = computed( () => ( store.meta.settings.currencies || [] ).map( ( c ) => c.code ) );

const load = async () => {
	const id = ++seq;
	loading.value = true;
	try {
		const res = await api.getPath( 'billing/list', { type: type.value, status: status.value, currency: currency.value, department_id: department.value || '', q: search.value, client_id: props.fixed.client_id, page: page.value, per_page: 25 } );
		if ( id === seq ) {
			Object.assign( data, res );
			error.value = '';
		}
	} catch ( e ) {
		if ( id === seq ) {
			error.value = errorMessage( e );
		}
	} finally {
		if ( id === seq ) {
			loading.value = false;
		}
	}
};
onMounted( load );
watch( () => store.route.query.department_id, ( v ) => {
	department.value = Number( v ) || 0; // a link to Billing with ?department_id= while already here
} );
watch( [ type, status, currency, department ], () => {
	page.value = 1;
	load();
} );
watch( () => [ page.value, props.reloadKey ], load );
watch( search, () => {
	clearTimeout( timer );
	timer = setTimeout( () => {
		page.value = 1;
		load();
	}, 300 );
} );

/* ── Selection: one client, one currency, only items still to invoice ── */
const selSummary = computed( () => {
	const rows = selected.value.map( ( r ) => ( { id: r.kind === 'charge' ? r.charge_id : r.renewal_id, client_id: r.client_id, client_name: r.client_name, currency: r.currency, price: r.price, kind: r.kind } ) );
	return summariseSelection( { charges: rows.filter( ( r ) => r.kind === 'charge' ), renewals: rows.filter( ( r ) => r.kind === 'renewal' ) } );
} );
const canPick = ( row ) => {
	if ( ! row.can_select ) {
		return false;
	}
	const first = selected.value[ 0 ];
	return ! first || ( first.client_id === row.client_id && first.currency === row.currency );
};
const clear = () => {
	selected.value = [];
	table.value && table.value.clearSelection();
};
const onSelect = ( rows ) => {
	// Rows ticked on other pages stay; this page's rows are replaced by the new ticks.
	const here = new Set( data.items.map( ( r ) => r.key ) );
	selected.value = [ ...selected.value.filter( ( r ) => ! here.has( r.key ) ), ...rows ];
};
const startInvoice = ( rows = null ) => {
	if ( rows ) {
		selected.value = rows;
	}
	if ( selSummary.value.ok ) {
		invoiceSummary.value = selSummary.value;
		dialog.value = true;
	}
};
const invoiceDone = () => {
	clear();
	changed();
};
const changed = () => {
	load();
	emit( 'changed' );
};

/* ── Opening and creating ── */
const cfgOne = computed( () => oneOffConfig( { hideClient: !!props.fixed.client_id, onInvoice: () => {} } ) );
const cfgRec = computed( () => recurringConfig( { hideClient: !!props.fixed.client_id, currency: props.currency, onCycles: () => {} } ) );
const drawer = reactive( { open: false, isNew: true, id: 0, kind: 'one_off', title: '', record: {}, fields: [] } );
const sub = reactive( { open: false, row: null } );

const fieldsFor = ( kind, preset ) => {
	const base = ( kind === 'one_off' ? cfgOne.value : cfgRec.value ).fields.filter( ( f ) => ! ( f.name in props.fixed ) );
	if ( kind === 'one_off' || ! preset ) {
		return base;
	}
	// Narrow the catalog picker to services (retainers) or products (subscriptions).
	return base.map( ( f ) => ( f.name === 'service_id' ? { ...f, remote: { ...f.remote, params: () => ( { status: 'active', kind: 'recurring', type: preset } ) } } : f ) );
};
const openNew = ( kind ) => {
	const preset = kind === 'retainer' ? 'service' : kind === 'subscription' ? 'product' : '';
	const one = kind === 'one_off';
	Object.assign( drawer, {
		open: true, isNew: true, id: 0, kind: one ? 'one_off' : 'recurring',
		title: kind === 'retainer' ? 'New retainer' : kind === 'subscription' ? 'New subscription' : 'New one-off bill',
		record: { ...( one ? cfgOne.value.defaults : cfgRec.value.defaults ), ...props.fixed },
		fields: fieldsFor( one ? 'one_off' : 'recurring', preset ),
	} );
};
const openRow = async ( row, column ) => {
	if ( column && column.type === 'selection' ) {
		return;
	}
	try {
		if ( row.kind === 'charge' ) {
			const rec = await api.get( 'charges', row.charge_id );
			Object.assign( drawer, { open: true, isNew: false, id: row.charge_id, kind: 'one_off', title: 'Edit one-off bill', record: rec, fields: fieldsFor( 'one_off' ) } );
		} else {
			sub.row = await api.get( 'client-services', row.client_service_id );
			sub.open = true;
		}
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const saveRecord = async ( values ) => {
	const cfg = drawer.kind === 'one_off' ? cfgOne.value : cfgRec.value;
	const payload = { ...props.fixed };
	cfg.fields.forEach( ( f ) => {
		if ( f.type !== 'static' && f.name in values ) {
			payload[ f.name ] = values[ f.name ];
		}
	} );
	const res = drawer.isNew ? await api.create( cfg.resource, payload ) : await api.update( cfg.resource, drawer.id, payload );
	ElMessage.success( 'Saved' );
	return res;
};

const dateText = ( r ) => ( r.date ? fmtDate( r.date ) : 'No date' );
const periodText = ( r ) => ( r.period && r.period !== 'none' ? labelOf( 'billing_period', r.period ) : '' );
</script>

<template>
	<div class="bd-billing">
		<div v-if="!embedded" class="bd-billsum">
			<span>To invoice <strong v-if="Object.keys( data.money.to_invoice ).length"><MoneyMap :map="data.money.to_invoice" /></strong><strong v-else>—</strong></span>
			<span>Awaiting payment <strong v-if="Object.keys( data.money.outstanding ).length"><MoneyMap :map="data.money.outstanding" /></strong><strong v-else>—</strong></span>
		</div>

		<FilterBar v-model="type" v-model:search="search" :chips="chips" search-placeholder="Search bills…">
			<el-select v-model="status" class="bd-toolbar__filter" aria-label="Status">
				<el-option v-for="o in STATUS_OPTIONS" :key="o.value" :label="o.label" :value="o.value" />
			</el-select>
			<RemoteSelect v-if="!fixed.client_id" v-model="department" resource="departments" :label="( b ) => b.name" :params="{ status: 'active' }" :placeholder="'All ' + dept().lowerMany" class="bd-toolbar__filter" aria-label="Department" />
			<el-select v-if="currencies.length > 1" v-model="currency" clearable placeholder="Currency" class="bd-toolbar__filter" style="width: 120px" aria-label="Currency">
				<el-option v-for="c in currencies" :key="c" :label="c" :value="c" />
			</el-select>
			<template #end>
				<el-dropdown trigger="click" placement="bottom-end">
					<el-button type="primary" :icon="Plus">New</el-button>
					<template #dropdown>
						<div class="bd-more__menu">
							<button type="button" class="bd-menuitem" @click="openNew( 'retainer' )">New retainer</button>
							<button type="button" class="bd-menuitem" @click="openNew( 'subscription' )">New subscription</button>
							<button type="button" class="bd-menuitem" @click="openNew( 'one_off' )">New one-off bill</button>
						</div>
					</template>
				</el-dropdown>
			</template>
		</FilterBar>

		<div v-if="selected.length" class="bd-selbar">
			<strong>{{ selected.length }} selected</strong>
			<span v-if="selSummary.ok" class="bd-muted">{{ selSummary.client_name }} · {{ fmtMoney( selSummary.total, selSummary.currency ) }}</span>
			<span v-else class="bd-muted">{{ selSummary.reason }}</span>
			<el-button @click="deptDialog = true">Set {{ dept().lower }}</el-button>
			<el-button text @click="clear">Clear</el-button>
			<el-button type="primary" :disabled="!selSummary.ok" @click="startInvoice()">{{ $fmt( 'Create {invoice}' ) }}</el-button>
		</div>

		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />

		<div class="bd-tablecard">
			<el-table ref="table" v-loading="loading" :data="data.items" row-key="key" @selection-change="onSelect" @row-click="openRow">
				<template #empty><el-empty description="Nothing to bill here." :image-size="72" /></template>
				<el-table-column type="selection" width="44" :selectable="canPick" />
				<el-table-column label="What" :min-width="260">
					<template #default="{ row }">
						<div class="bd-strong">{{ row.title }}</div>
						<div v-if="row.kind === 'renewal' && ( periodText( row ) || row.department_name )" class="bd-muted bd-small">{{ [ periodText( row ), row.department_name ].filter( Boolean ).join( ' · ' ) }}</div>
						<div v-else-if="row.kind === 'charge' && ( row.service_name || row.department_name )" class="bd-muted bd-small">{{ [ row.service_name, row.department_name ].filter( Boolean ).join( ' · ' ) }}</div>
					</template>
				</el-table-column>
				<el-table-column v-if="!fixed.client_id" :label="$t( 'client' )" prop="client_name" :min-width="170" />
				<el-table-column label="Type" :width="128"><template #default="{ row }"><Badge :tone="TYPE_TONE[ row.type ]" :label="TYPES[ row.type ]" /></template></el-table-column>
				<el-table-column label="Amount" align="right" :min-width="130">
					<template #default="{ row }">
						<div class="bd-money bd-strong">{{ fmtMoney( row.price, row.currency ) }}</div>
						<div v-if="row.unit_price > 0" class="bd-muted bd-small bd-num">{{ row.quantity }} × {{ fmtMoney( row.unit_price, row.currency ) }}</div>
					</template>
				</el-table-column>
				<el-table-column label="Bill date" :min-width="130">
					<template #default="{ row }">
						<div>{{ dateText( row ) }}</div>
						<div v-if="row.can_select && row.date" :class="[ row.days < 0 ? 'bd-late' : 'bd-muted', 'bd-small' ]">{{ row.days < 0 ? Math.abs( row.days ) + ' days late' : row.days === 0 ? 'today' : 'in ' + row.days + ' days' }}</div>
					</template>
				</el-table-column>
				<el-table-column label="Status" :min-width="150">
					<template #default="{ row }">
						<BillStatus :status="row.bill_status" />
						<a v-if="row.invoice_number" class="bd-link bd-small bd-block" :href="`#/billing/invoices/${ row.invoice_id }`" @click.stop>{{ row.invoice_number }}</a>
					</template>
				</el-table-column>
				<el-table-column label="" :width="110" align="right">
					<template #default="{ row }">
						<el-button v-if="row.can_select" size="small" type="primary" plain @click.stop="startInvoice( [ row ] )">Invoice</el-button>
						<el-button v-else-if="row.invoice_id" size="small" @click.stop="navigate( `/billing/invoices/${ row.invoice_id }` )">View</el-button>
					</template>
				</el-table-column>
			</el-table>
			<el-pagination v-if="data.total > data.per_page" v-model:current-page="page" class="bd-pager" layout="prev, pager, next" :page-size="data.per_page" :total="data.total" />
		</div>

		<RecordDrawer v-model="drawer.open" :title="drawer.title" :fields="drawer.fields" :record="drawer.record" :is-new="drawer.isNew" :save="saveRecord" @saved="changed" />
		<SubscriptionDrawer v-model="sub.open" :subscription="sub.row" @changed="changed" />
		<BulkDepartmentDialog v-model="deptDialog" :items="bulkItems" noun="bill" note="For subscriptions and retainers this changes the subscription itself, so its future bills follow." @done="changed" />
		<InvoiceDialog v-model="dialog" :summary="invoiceSummary" @done="invoiceDone" />
	</div>
</template>
