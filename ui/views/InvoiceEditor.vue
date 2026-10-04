<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { ElMessage, ElMessageBox, RemoteSelect, sdk, StatusTag, addDays, addDaysTo, api, copyText, dept, errorMessage, fmtDate, navigate, round2, store, today } from '../core';
import { currencyOptions, defaultCurrency, fmtMoney } from '../util';
import { ArrowLeft, Plus, Delete, Link as LinkIcon, View, Promotion, Wallet, DocumentAdd } from '@element-plus/icons-vue';
import PaymentsDialog from '../components/PaymentsDialog.vue';
import SendInvoiceDialog from '../components/SendInvoiceDialog.vue';
import { catalogPrice } from '../resources';

const props = defineProps( { id: { type: String, default: 'new' }, query: { type: Object, default: () => ( {} ) } } );

const isNew = computed( () => props.id === 'new' );
const settings = store.meta.settings;
const taxLabel = computed( () => settings.tax_label || 'Tax' );

const newLine = ( kind = 'fixed' ) => ( { kind, unit_cost: 0, service_id: 0, description: '', quantity: 1, unit_price: 0, source_type: 'manual', source_id: 0, client_service_id: 0, period_start: null, period_end: null, service_name: '' } );

const inv = reactive( {
	id: 0,
	client_id: Number( store.route.query.client ) || 0,
	client_name: '',
	department_id: 0,
	currency: defaultCurrency(),
	invoice_number: '',
	title: '',
	issue_date: today(),
	due_date: addDays( Number( settings.default_due_days || 14 ) ),
	status: 'draft',
	discount_type: 'none',
	discount_value: 0,
	tax_rate: Number( settings.default_tax_rate || 0 ),
	notes: '',
	terms: settings.invoice_terms || '',
	items: [ newLine() ],
	installments: [],
	public_url: '',
	payments: [],
	schedule: [],
	amount_paid: 0,
	balance: 0,
	amount: 0,
	sent_at: null,
	viewed_at: null,
	view_count: 0,
} );
const plan = ref( 'full' );
const loading = ref( ! isNew.value );
const saving = ref( false );
const error = ref( '' );
const paying = ref( false );
const billsOpen = ref( false );
const bills = ref( [] );
const pickedBills = ref( [] );
const svcPick = ref( 0 );
const sendOpen = ref( false );
const emails = ref( [] );

/* ───────────── load ───────────── */
const load = async () => {
	if ( isNew.value ) {
		if ( inv.client_id ) {
			try {
				const c = await api.get( 'clients', inv.client_id );
				inv.currency = c.currency;
				inv.client_name = c.name;
				// Arrived from Home ("Invoice it"): start the invoice with that bill already on it.
				const want = store.route.query.bill;
				if ( want ) {
					const all = await api.getPath( `clients/${ inv.client_id }/unbilled` );
					const hit = all.find( ( b ) => `${ b.type }-${ b.ref_id }` === want && b.currency === c.currency );
					if ( hit ) {
						inv.items = [ billLine( hit ) ];
					}
				}
			} catch ( e ) {
				/* ignore */
			}
		}
		return;
	}
	loading.value = true;
	try {
		const r = await api.get( 'invoices', props.id );
		Object.assign( inv, r, {
			items: ( r.items.length ? r.items : [ newLine() ] ).map( ( l ) => ( { ...l, kind: l.kind || ( Number( l.quantity ) === 1 ? 'fixed' : 'quantity' ), unit_cost: ( l.kind === 'quantity' && Number( l.quantity ) > 0 ) ? Number( l.cost || 0 ) / Number( l.quantity ) : Number( l.cost || 0 ), service_id: Number( l.service_id || 0 ), quantity: Number( l.quantity ), unit_price: Number( l.unit_price ), source_id: Number( l.source_id ), client_service_id: Number( l.client_service_id ) } ) ),
			installments: r.has_installments ? r.schedule.map( ( s ) => ( { label: s.label, due_date: s.due_date, percent: s.percent, amount: s.amount, byPercent: s.percent !== null } ) ) : [],
			discount_value: Number( r.discount_value ),
			tax_rate: Number( r.tax_rate ),
		} );
		plan.value = r.has_installments ? 'installments' : 'full';
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		loading.value = false;
	}
};
const loadEmails = async () => {
	if ( isNew.value ) {
		return;
	}
	try {
		emails.value = await api.getPath( `invoices/${ props.id }/emails` );
	} catch ( e ) {
		emails.value = [];
	}
};
onMounted( async () => {
	await load();
	loadEmails();
	if ( ! isNew.value && store.route.query.send ) {
		sendOpen.value = true;
	}
} );
const onSent = async () => {
	await load();
	loadEmails();
};
const MAIL_TONE = { sent: 'success', test: 'neutral', failed: 'danger' };
const MAIL_LABEL = { sent: 'Sent', test: 'Test', failed: 'Failed' };

/* ───────────── totals ───────────── */
const totals = computed( () => {
	const sub = round2( inv.items.reduce( ( s, l ) => s + lineAmount( l ), 0 ) );
	const v = Math.max( 0, Number( inv.discount_value ) || 0 );
	const disc = inv.discount_type === 'percent' ? round2( ( sub * Math.min( 100, v ) ) / 100 ) : inv.discount_type === 'fixed' ? round2( Math.min( v, sub ) ) : 0;
	const tax = round2( ( ( sub - disc ) * ( Number( inv.tax_rate ) || 0 ) ) / 100 );
	return { sub, disc, tax, total: round2( sub - disc + tax ) };
} );
const lineAmount = ( l ) => round2( ( l.kind === 'quantity' ? Number( l.quantity ) || 0 : 1 ) * ( Number( l.unit_price ) || 0 ) );
/** Mirrors the server's default so the placeholder shows what a blank title will become. */
const suggestedTitle = computed( () => {
	const named = inv.items.filter( ( l ) => ( l.description || '' ).trim() );
	if ( ! named.length ) {
		return '';
	}
	if ( named.length > 1 && named.every( ( l ) => l.source_type === 'renewal' ) ) {
		const d = new Date( ( inv.issue_date || today() ) + 'T12:00:00' );
		return 'Subscriptions & retainers — ' + d.toLocaleString( 'en-US', { month: 'long', year: 'numeric' } );
	}
	return named[ 0 ].description.trim() + ( named.length > 1 ? ` + ${ named.length - 1 } more` : '' );
} );
const lineCost = ( l ) => round2( ( l.kind === 'quantity' ? Number( l.quantity ) || 0 : 1 ) * ( Number( l.unit_cost ) || 0 ) );
const showCost = ref( false );
const costTotal = computed( () => round2( inv.items.reduce( ( t, l ) => t + lineCost( l ), 0 ) ) );
const margin = computed( () => round2( totals.value.sub - totals.value.disc - costTotal.value ) );
const marginPct = computed( () => ( totals.value.sub - totals.value.disc > 0 ? Math.round( ( margin.value / ( totals.value.sub - totals.value.disc ) ) * 100 ) : 0 ) );
const fixedItems = computed( () => inv.items.filter( ( l ) => l.kind !== 'quantity' ) );
const qtyItems = computed( () => inv.items.filter( ( l ) => l.kind === 'quantity' ) );
const groupTotal = ( list ) => round2( list.reduce( ( t, l ) => t + lineAmount( l ), 0 ) );
const money = ( n ) => fmtMoney( n, inv.currency );

/* ───────────── instalments ───────────── */
const PRESETS = [
	{ label: '50 / 50', parts: [ 50, 50 ] },
	{ label: '30 / 40 / 30', parts: [ 30, 40, 30 ] },
	{ label: '25 × 4', parts: [ 25, 25, 25, 25 ] },
	{ label: 'Custom amounts', parts: null },
];
const syncRemainder = () => {
	const rows = inv.installments;
	if ( rows.length < 2 || ! rows.every( ( r ) => r.byPercent ) ) {
		return;
	}
	if ( Math.abs( rows.reduce( ( s, r ) => s + ( Number( r.percent ) || 0 ), 0 ) - 100 ) < 0.05 ) {
		const diff = round2( totals.value.total - rows.reduce( ( s, r ) => s + round2( r.amount ), 0 ) );
		rows[ rows.length - 1 ].amount = round2( rows[ rows.length - 1 ].amount + diff );
	}
};
const applyPreset = ( preset ) => {
	plan.value = 'installments';
	const t = totals.value.total;
	if ( preset.parts ) {
		inv.installments = preset.parts.map( ( pct, i ) => ( {
			label: i === 0 ? 'Deposit' : i === preset.parts.length - 1 ? 'Final payment' : `Instalment ${ i + 1 }`,
			due_date: addDaysTo( inv.issue_date, i * 30 ),
			percent: pct,
			amount: round2( ( t * pct ) / 100 ),
			byPercent: true,
		} ) );
		syncRemainder();
	} else {
		inv.installments = [
			{ label: 'Deposit', due_date: inv.issue_date, percent: null, amount: round2( t / 2 ), byPercent: false },
			{ label: 'Balance', due_date: addDaysTo( inv.issue_date, 30 ), percent: null, amount: round2( t - round2( t / 2 ) ), byPercent: false },
		];
	}
};
const addInstalment = () => inv.installments.push( { label: `Instalment ${ inv.installments.length + 1 }`, due_date: addDaysTo( inv.issue_date, inv.installments.length * 30 ), percent: null, amount: Math.max( 0, unallocated.value ), byPercent: false } );
const removeInstalment = ( i ) => inv.installments.splice( i, 1 );
const onPercent = ( r ) => {
	r.byPercent = true;
	r.amount = round2( ( totals.value.total * ( Number( r.percent ) || 0 ) ) / 100 );
};
const onAmount = ( r ) => {
	r.byPercent = false;
	r.percent = totals.value.total ? round2( ( ( Number( r.amount ) || 0 ) / totals.value.total ) * 100 ) : null;
};
watch( () => totals.value.total, () => {
	inv.installments.forEach( ( r ) => {
		if ( r.byPercent ) {
			r.amount = round2( ( totals.value.total * ( Number( r.percent ) || 0 ) ) / 100 );
		} else if ( totals.value.total ) {
			r.percent = round2( ( ( Number( r.amount ) || 0 ) / totals.value.total ) * 100 );
		}
	} );
	syncRemainder();
} );
const allocated = computed( () => round2( inv.installments.reduce( ( s, r ) => s + ( Number( r.amount ) || 0 ), 0 ) ) );
const unallocated = computed( () => round2( totals.value.total - allocated.value ) );
const scheduleError = computed( () => {
	if ( plan.value !== 'installments' ) {
		return '';
	}
	if ( inv.installments.length < 2 ) {
		return 'Add at least two instalments, or switch to full payment.';
	}
	if ( inv.installments.some( ( r ) => ! r.due_date ) ) {
		return 'Every instalment needs a due date.';
	}
	return Math.abs( unallocated.value ) > 0.01 ? ( unallocated.value > 0 ? `${ money( unallocated.value ) } is still unallocated.` : `Instalments exceed the total by ${ money( -unallocated.value ) }.` ) : '';
} );

/* ───────────── lines ───────────── */
const addLine = ( kind = 'fixed' ) => inv.items.push( newLine( kind ) );
const removeLine = ( line ) => {
	const i = inv.items.indexOf( line );
	if ( i >= 0 ) {
		inv.items.splice( i, 1 );
	}
	if ( ! inv.items.length ) {
		inv.items.push( newLine() );
	}
};
const isBlankLine = ( l ) => ! l.description && ! Number( l.unit_price );
const addService = ( s ) => {
	if ( ! s ) {
		return;
	}
	const perUnit = s.pricing === 'unit';
	const unit = ( s.unit_label || '' ).trim();
	const description = perUnit && unit && ! /\bper\b|\(/i.test( s.name ) ? `${ s.name } (per ${ unit })` : s.name;
	const line = { ...newLine( perUnit ? 'quantity' : 'fixed' ), description, unit_price: Number( s.default_price ) || 0, unit_cost: Number( s.default_cost ) || 0, service_id: s.id };
	inv.items = [ ...inv.items.filter( ( l ) => ! isBlankLine( l ) ), line ];
	svcPick.value = 0;
};
const onClientPick = ( c ) => {
	if ( c ) {
		inv.currency = c.currency;
		inv.client_name = c.name;
		inv.items = inv.items.filter( ( l ) => l.source_type === 'manual' );
		if ( ! inv.items.length ) {
			inv.items.push( newLine() );
		}
	}
};

const openBills = async () => {
	if ( ! inv.client_id ) {
		ElMessage.warning( sdk.fmt( 'Choose a {client} first.' ) );
		return;
	}
	try {
		const all = await api.getPath( `clients/${ inv.client_id }/unbilled` );
		const used = new Set( inv.items.map( ( l ) => `${ l.source_type }-${ l.source_id }` ) );
		bills.value = all.filter( ( b ) => b.currency === inv.currency && ! used.has( `${ b.type }-${ b.ref_id }` ) );
		pickedBills.value = [];
		billsOpen.value = true;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const billLine = ( b ) => ( {
		description: b.label + ( b.type === 'charge' && b.sub ? ` — ${ b.sub }` : '' ),
		kind: Number( b.unit_price ) > 0 ? 'quantity' : 'fixed',
		unit_cost: Number( b.unit_price ) > 0 && Number( b.quantity ) > 0 ? Number( b.cost || 0 ) / Number( b.quantity ) : Number( b.cost || 0 ),
		quantity: Number( b.unit_price ) > 0 ? Number( b.quantity ) : 1,
		unit_price: Number( b.unit_price ) > 0 ? Number( b.unit_price ) : b.price,
		source_type: b.type,
		source_id: b.ref_id,
		client_service_id: b.client_service_id,
		period_start: b.period_start,
		period_end: b.period_end,
		service_name: '',
} );
const addBills = () => {
	const blank = inv.items.length === 1 && ! inv.items[ 0 ].description && ! Number( inv.items[ 0 ].unit_price );
	const lines = pickedBills.value.map( billLine );
	inv.items = blank ? lines : [ ...inv.items, ...lines ];
	billsOpen.value = false;
};

/* ───────────── save / share ───────────── */
const payload = () => ( {
	client_id: inv.client_id,
	department_id: inv.department_id || 0,
	invoice_number: inv.invoice_number,
	title: inv.title,
	currency: inv.currency,
	issue_date: inv.issue_date,
	due_date: inv.due_date,
	discount_type: inv.discount_type,
	discount_value: Number( inv.discount_value ) || 0,
	tax_rate: Number( inv.tax_rate ) || 0,
	notes: inv.notes,
	terms: inv.terms,
	items: inv.items.filter( ( l ) => l.description || Number( l.unit_price ) ).map( ( l ) => ( { description: l.description, cost: lineCost( l ), service_id: l.service_id || 0, kind: l.kind === 'quantity' ? 'quantity' : 'fixed', quantity: l.kind === 'quantity' ? Number( l.quantity ) || 0 : 1, unit_price: Number( l.unit_price ) || 0, source_type: l.source_type, source_id: l.source_id, client_service_id: l.client_service_id, period_start: l.period_start, period_end: l.period_end } ) ),
	installments: plan.value === 'installments' ? inv.installments.map( ( r ) => ( { label: r.label, due_date: r.due_date, percent: r.percent, amount: r.amount } ) ) : [],
} );

const save = async ( thenSend = false ) => {
	error.value = '';
	if ( ! inv.client_id ) {
		error.value = sdk.fmt( 'Choose a {client} for this invoice.' );
		return;
	}
	if ( scheduleError.value ) {
		error.value = scheduleError.value;
		return;
	}
	if ( ! totals.value.total ) {
		error.value = 'Add at least one line with an amount.';
		return;
	}
	saving.value = true;
	try {
		const saved = isNew.value ? await api.create( 'invoices', payload() ) : await api.update( 'invoices', inv.id, payload() );
		ElMessage.success( 'Invoice saved' );
		if ( isNew.value ) {
			navigate( `/billing/invoices/${ saved.id }${ thenSend ? '?send=1' : '' }` );
		} else {
			await load();
			if ( thenSend ) {
				sendOpen.value = true;
			}
		}
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		saving.value = false;
	}
};

const link = async ( regenerate = false ) => {
	const { url } = await api.post( `invoices/${ inv.id }/share`, { regenerate } );
	inv.public_url = url;
	return url;
};
const copyLink = async () => {
	try {
		const url = inv.public_url || ( await link() );
		const ok = await copyText( url );
		ElMessage[ ok ? 'success' : 'warning' ]( ok ? 'Invoice link copied' : url );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const preview = async () => {
	try {
		window.open( inv.public_url || ( await link() ), '_blank', 'noopener' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const editSlug = async () => {
	const current = ( inv.public_url || '' ).split( '/' ).filter( Boolean ).pop() || '';
	let value;
	try {
		( { value } = await ElMessageBox.prompt( 'Letters, numbers and dashes (at least 4). The previous link stops working.', 'Change invoice link', { inputValue: current, confirmButtonText: 'Save link', inputPattern: /^[A-Za-z0-9-]{4,60}$/, inputErrorMessage: 'Use 4–60 letters, numbers or dashes' } ) );
	} catch ( e ) {
		return;
	}
	try {
		const r = await api.post( `invoices/${ inv.id }/share`, { slug: value } );
		inv.public_url = r.url;
		ElMessage.success( 'Link updated' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const regenerate = async () => {
	try {
		await ElMessageBox.confirm( 'The old link will stop working. Anyone you sent it to will need the new one.', 'Replace the invoice link?', { type: 'warning', confirmButtonText: 'Replace link' } );
	} catch ( e ) {
		return;
	}
	await link( true );
	ElMessage.success( 'New link generated' );
};
const markSent = async () => {
	try {
		const r = await api.post( `invoices/${ inv.id }/mark-sent` );
		inv.public_url = r.url;
		await copyText( r.url );
		ElMessage.success( 'Marked as sent — link copied' );
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};

const STATE_TONE = { paid: 'success', partial: 'warning', overdue: 'danger', due: 'warning', upcoming: 'neutral' };
const STATE_LABEL = { paid: 'Paid', partial: 'Part-paid', overdue: 'Overdue', due: 'Due today', upcoming: 'Upcoming' };
const nextDue = computed( () => inv.schedule.find( ( s ) => s.balance > 0 ) );
const locked = computed( () => [ 'cancelled' ].includes( inv.status ) );
</script>

<template>
	<div class="bd-ie">
		<div class="bd-ie__top">
			<a class="bd-ie__back" href="#/billing/invoices"><el-icon><ArrowLeft /></el-icon> Invoices</a>
		</div>

		<div class="bd-pagehead">
			<div>
				<span class="bd-eyebrow">{{ isNew ? 'New invoice' : 'Invoice' }}</span>
				<h1>{{ isNew ? 'Create invoice' : inv.invoice_number }}<span v-if="!isNew && inv.title" class="bd-pagehead__sub"> — {{ inv.title }}</span></h1>
				<p v-if="!isNew" class="bd-muted">{{ inv.client_name }} · <StatusTag :status="inv.status" /><span v-if="inv.viewed_at"> · Viewed by client {{ inv.view_count }}×</span></p>
				<p v-else class="bd-muted">Lines, discount, tax and an optional payment schedule. Branding comes from Settings → Business &amp; invoices.</p>
			</div>
			<div class="bd-pagehead__actions">
				<template v-if="!isNew">
					<el-button :icon="View" @click="preview">Preview</el-button>
					<el-button :icon="LinkIcon" @click="copyLink">Copy link</el-button>
					<el-button :icon="Wallet" @click="paying = true">Record payment</el-button>
				</template>
				<el-button :loading="saving" :disabled="locked" @click="save( false )">Save</el-button>
				<el-button v-if="!locked" type="primary" :icon="Promotion" :loading="saving" @click="save( true )">Save &amp; send</el-button>
			</div>
		</div>

		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-skeleton v-if="loading" :rows="8" animated />

		<div v-else class="bd-ie__grid">
			<div class="bd-ie__main">
				<el-card shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Details</span></div></template>
					<el-row :gutter="16">
						<el-col :span="24" class="bd-mb">
							<label class="bd-lab">Invoice title</label>
							<el-input v-model="inv.title" maxlength="190" :placeholder="suggestedTitle || 'What is this invoice for? e.g. Website redesign project'" />
							<div class="bd-help">{{ $fmt( 'Shown to the {client} at the top of the invoice and in the email.' ) }} Leave blank to use the suggestion.</div>
						</el-col>
						<el-col :xs="24" :md="12">
							<label class="bd-lab">{{ $t( 'client' ) }}</label>
							<RemoteSelect v-model="inv.client_id" resource="clients" :label="( c ) => c.name" :params="{}" :placeholder="$fmt( 'Choose a {client}' )" :disabled="!isNew && inv.items.some( ( l ) => l.source_type !== 'manual' )" @pick="onClientPick" />
						</el-col>
						<el-col :xs="12" :md="6"><label class="bd-lab">{{ $t( 'invoice' ) }} number</label><el-input v-model="inv.invoice_number" placeholder="Auto" /></el-col>
						<el-col :xs="12" :md="6">
							<label class="bd-lab">Currency</label>
							<el-select v-model="inv.currency" style="width: 100%"><el-option v-for="c in currencyOptions()" :key="c.value" :value="c.value" :label="c.label" /></el-select>
						</el-col>
						<el-col :xs="12" :md="6" class="bd-mt"><label class="bd-lab">{{ dept().one }}</label><RemoteSelect v-model="inv.department_id" resource="departments" :label="( b ) => b.name" :params="{ status: 'active' }" placeholder="By line item" /></el-col>
						<el-col :xs="12" :md="6" class="bd-mt"><label class="bd-lab">Issue date</label><el-date-picker v-model="inv.issue_date" type="date" value-format="YYYY-MM-DD" format="MMM D, YYYY" style="width: 100%" :clearable="false" /></el-col>
						<el-col :xs="12" :md="6" class="bd-mt">
							<label class="bd-lab">{{ plan === 'installments' ? 'Final due date' : 'Due date' }}</label>
							<el-date-picker v-model="inv.due_date" type="date" value-format="YYYY-MM-DD" format="MMM D, YYYY" style="width: 100%" :disabled="plan === 'installments'" :clearable="false" />
						</el-col>
					</el-row>
				</el-card>

				<el-card shadow="never" class="bd-mb">
					<template #header>
						<div class="bd-widget__head">
							<span>Items</span>
							<span class="bd-ie__tools">
								<el-switch v-model="showCost" size="small" active-text="Cost &amp; margin" class="bd-mr" />
								<el-button size="small" :icon="DocumentAdd" @click="openBills">From bills</el-button>
							</span>
						</div>
					</template>

					<div class="bd-ie__addsvc bd-ie__addsvc--top">
						<RemoteSelect v-model="svcPick" resource="catalog" :label="( s ) => s.name + ' — ' + catalogPrice( s, inv.currency ) + ( s.type === 'product' ? ' · Product' : '' )" :params="{ status: 'active' }" placeholder="Add from your catalog — services and products land in the right section automatically…" @pick="addService" />
					</div>

					<div class="bd-sect">
						<div class="bd-sect__head">
							<div><strong>Fixed-price lines</strong><span class="bd-muted bd-small"> · one price, no quantity (e.g. web development, retainers, hosting)</span></div>
							<el-button size="small" :icon="Plus" @click="addLine( 'fixed' )">Add fixed-price line</el-button>
						</div>
						<div :class="[ 'bd-lines', 'bd-lines--fixed', { 'has-cost': showCost } ]">
							<div class="bd-lines__head"><span>Description</span><span class="r">Amount</span><span v-if="showCost" class="r">Our cost</span><span /></div>
							<div v-for="( l, i ) in fixedItems" :key="'f' + i" class="bd-lines__row">
								<div>
									<el-input v-model="l.description" placeholder="What are you billing for?" />
									<div v-if="l.source_type !== 'manual' || l.period_start" class="bd-lines__meta">
										<span v-if="l.source_type !== 'manual'" class="bd-badge bd-badge--neutral bd-badge--plain">{{ l.source_type === 'renewal' ? 'Recurring' : 'One-off' }}</span>
										<span v-if="l.period_start" class="bd-muted bd-small">{{ fmtDate( l.period_start ) }} – {{ fmtDate( l.period_end ) }}</span>
									</div>
								</div>
								<el-input-number v-model="l.unit_price" :min="0" :precision="2" :controls="false" class="r-in" />
								<el-input-number v-if="showCost" v-model="l.unit_cost" :min="0" :precision="2" :controls="false" class="r-in" aria-label="Our cost" />
								<el-button text circle :icon="Delete" aria-label="Remove line" @click="removeLine( l )" />
							</div>
						</div>
						<div class="bd-sect__sum"><span>Fixed-price subtotal</span><strong class="bd-money">{{ money( groupTotal( fixedItems ) ) }}</strong></div>
					</div>

					<div class="bd-sect">
						<div class="bd-sect__head">
							<div><strong>Quantity lines</strong><span class="bd-muted bd-small"> · priced per unit (e.g. email accounts, copywriting pages, hours)</span></div>
							<el-button size="small" :icon="Plus" @click="addLine( 'quantity' )">Add quantity line</el-button>
						</div>
						<div v-if="qtyItems.length" :class="[ 'bd-lines', { 'has-cost': showCost } ]">
							<div class="bd-lines__head"><span>Description</span><span class="r">Qty</span><span class="r">Unit price</span><span class="r">Amount</span><span v-if="showCost" class="r">Unit cost</span><span /></div>
							<div v-for="( l, i ) in qtyItems" :key="'q' + i" class="bd-lines__row">
								<el-input v-model="l.description" placeholder="e.g. Business email accounts" />
								<el-input-number v-model="l.quantity" :min="0" :precision="2" :controls="false" class="r-in" />
								<el-input-number v-model="l.unit_price" :min="0" :precision="2" :controls="false" class="r-in" />
								<div class="r bd-strong bd-money">{{ money( lineAmount( l ) ) }}</div>
								<el-input-number v-if="showCost" v-model="l.unit_cost" :min="0" :precision="2" :controls="false" class="r-in" aria-label="Unit cost" />
								<el-button text circle :icon="Delete" aria-label="Remove line" @click="removeLine( l )" />
							</div>
						</div>
						<div v-else class="bd-sect__empty">Nothing priced per unit on this invoice. Add a quantity line if you need quantities.</div>
						<div v-if="qtyItems.length" class="bd-sect__sum"><span>Quantity subtotal</span><strong class="bd-money">{{ money( groupTotal( qtyItems ) ) }}</strong></div>
					</div>
				</el-card>

				<el-card shadow="never" class="bd-mb">
					<template #header>
						<div class="bd-widget__head">
							<span>Payment schedule</span>
							<el-radio-group v-model="plan" class="bd-seg-sm" @change="( v ) => v === 'installments' && !inv.installments.length && applyPreset( PRESETS[ 0 ] )">
								<el-radio-button value="full">Pay in full</el-radio-button>
								<el-radio-button value="installments">Instalments</el-radio-button>
							</el-radio-group>
						</div>
					</template>
					<p v-if="plan === 'full'" class="bd-muted">{{ $fmt( 'The {client} pays' ) }} {{ money( totals.total ) }} by <strong>{{ fmtDate( inv.due_date ) }}</strong>. Switch to instalments for deposits and milestones.</p>
					<template v-else>
						<div class="bd-ie__presets">
							<span class="bd-muted">Quick split</span>
							<el-button v-for="p in PRESETS" :key="p.label" size="small" @click="applyPreset( p )">{{ p.label }}</el-button>
						</div>
						<div class="bd-inst">
							<div class="bd-inst__head"><span>Instalment</span><span>Due</span><span class="r">%</span><span class="r">Amount</span><span /></div>
							<div v-for="( r, i ) in inv.installments" :key="i" class="bd-inst__row">
								<el-input v-model="r.label" placeholder="Label" />
								<el-date-picker v-model="r.due_date" type="date" value-format="YYYY-MM-DD" format="MMM D, YYYY" :clearable="false" style="width: 100%" />
								<el-input-number v-model="r.percent" :min="0" :max="100" :precision="2" :controls="false" class="r-in" @change="onPercent( r )" />
								<el-input-number v-model="r.amount" :min="0" :precision="2" :controls="false" class="r-in" @change="onAmount( r )" />
								<el-button text circle :icon="Delete" aria-label="Remove instalment" @click="removeInstalment( i )" />
							</div>
						</div>
						<div class="bd-inst__foot">
							<el-button size="small" :icon="Plus" @click="addInstalment">Add instalment</el-button>
							<span :class="[ 'bd-inst__sum', scheduleError ? 'is-bad' : 'is-ok' ]">{{ scheduleError || 'Instalments add up to the invoice total ✓' }}</span>
						</div>
					</template>
				</el-card>

				<el-card shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Notes &amp; terms</span></div></template>
					<el-row :gutter="16">
						<el-col :xs="24" :md="12"><label class="bd-lab">{{ $fmt( 'Note to {client}' ) }}</label><el-input v-model="inv.notes" type="textarea" :rows="4" placeholder="Shown on the invoice" /></el-col>
						<el-col :xs="24" :md="12"><label class="bd-lab">Terms</label><el-input v-model="inv.terms" type="textarea" :rows="4" placeholder="Payment terms, late fees…" /></el-col>
					</el-row>
				</el-card>
			</div>

			<aside class="bd-ie__side">
				<el-card shadow="never" class="bd-ie__sum">
					<div class="bd-sumrow"><span>Subtotal</span><span class="bd-money">{{ money( totals.sub ) }}</span></div>
					<div class="bd-sumrow bd-sumrow--in">
						<span>Discount</span>
						<span class="bd-sumrow__ctl">
							<el-select v-model="inv.discount_type" size="small" style="width: 92px"><el-option value="none" label="None" /><el-option value="percent" label="%" /><el-option value="fixed" label="Fixed" /></el-select>
							<el-input-number v-if="inv.discount_type !== 'none'" v-model="inv.discount_value" size="small" :min="0" :precision="2" :controls="false" style="width: 90px" />
						</span>
					</div>
					<div v-if="totals.disc" class="bd-sumrow bd-muted"><span>Discount applied</span><span class="bd-money">−{{ money( totals.disc ) }}</span></div>
					<div class="bd-sumrow bd-sumrow--in">
						<span>{{ taxLabel }} rate</span>
						<span class="bd-sumrow__ctl"><el-input-number v-model="inv.tax_rate" size="small" :min="0" :max="100" :precision="2" :controls="false" style="width: 90px" /> %</span>
					</div>
					<div v-if="totals.tax" class="bd-sumrow bd-muted"><span>{{ taxLabel }}</span><span class="bd-money">{{ money( totals.tax ) }}</span></div>
					<div class="bd-sumrow bd-sumrow--total"><span>Total</span><span class="bd-money">{{ money( totals.total ) }}</span></div>
					<template v-if="!isNew">
						<div class="bd-sumrow"><span>Paid</span><span class="bd-money">−{{ money( inv.amount_paid ) }}</span></div>
						<div class="bd-sumrow bd-sumrow--due"><span>Balance due</span><span class="bd-money">{{ money( Math.max( 0, round2( totals.total - inv.amount_paid ) ) ) }}</span></div>
					</template>
					<div v-if="showCost" class="bd-costbox">
						<div class="bd-muted bd-small">Only you can see this — {{ $fmt( 'never shown to the {client}.' ) }}</div>
						<div class="row"><span>Our cost</span><span class="bd-money">{{ money( costTotal ) }}</span></div>
						<div class="row bd-strong"><span>What we keep</span><span class="bd-money">{{ money( margin ) }} <span class="bd-muted">({{ marginPct }}%)</span></span></div>
					</div>
				</el-card>

				<el-card v-if="!isNew && inv.schedule.length" shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>{{ inv.has_installments ? 'Instalment status' : 'Payment status' }}</span></div></template>
					<ul class="bd-list">
						<li v-for="( s, i ) in inv.schedule" :key="i">
							<div><div class="bd-strong">{{ s.label }}</div><div class="bd-muted bd-small">Due {{ fmtDate( s.due_date ) }} · {{ money( s.amount ) }}</div></div>
							<div class="bd-attn__right">
								<span :class="[ 'bd-badge', 'bd-badge--' + STATE_TONE[ s.state ] ]">{{ STATE_LABEL[ s.state ] }}</span>
								<span v-if="s.balance > 0 && s.paid > 0" class="bd-muted bd-small">{{ money( s.balance ) }} left</span>
							</div>
						</li>
					</ul>
					<p v-if="nextDue" class="bd-muted bd-small bd-mt">Next payment: <strong>{{ money( nextDue.balance ) }}</strong> by {{ fmtDate( nextDue.due_date ) }}</p>
				</el-card>

				<el-card v-if="!isNew" shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Share</span></div></template>
					<p class="bd-muted bd-small">{{ $fmt( 'A private, branded page your {client} can open, print or save as PDF.' ) }}</p>
					<div class="bd-ie__link">
						<el-input :model-value="inv.public_url || 'Link is created when you copy it'" readonly size="small" />
						<el-button size="small" :icon="LinkIcon" @click="copyLink">Copy</el-button>
					</div>
					<el-button text size="small" class="bd-mt" @click="editSlug">Edit link</el-button>
					<el-button text size="small" class="bd-mt" @click="regenerate">New random link</el-button>
					<el-button v-if="inv.status === 'draft'" text size="small" class="bd-mt" @click="markSent">Mark as sent (without emailing)</el-button>
				</el-card>

				<el-card v-if="!isNew" shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Emails</span><a href="#" @click.prevent="sendOpen = true">Send</a></div></template>
					<ul v-if="emails.length" class="bd-list">
						<li v-for="m in emails" :key="m.id">
							<div>
								<div class="bd-strong">{{ m.subject }}</div>
								<div class="bd-muted bd-small">To {{ m.to_emails }}<template v-if="m.cc_emails"> · Cc {{ m.cc_emails }}</template></div>
								<div v-if="m.error" class="bd-late bd-small">{{ m.error }}</div>
							</div>
							<div class="bd-attn__right"><span :class="[ 'bd-badge', 'bd-badge--' + MAIL_TONE[ m.status ] ]">{{ MAIL_LABEL[ m.status ] }}</span><span class="bd-muted bd-small">{{ fmtDate( m.created_at ) }}</span></div>
						</li>
					</ul>
					<p v-else class="bd-muted">Not emailed yet. Use <strong>Save &amp; send</strong> — you can send yourself a test first.</p>
				</el-card>

				<el-card v-if="!isNew && inv.payments.length" shadow="never">
					<template #header><div class="bd-widget__head"><span>Payments</span></div></template>
					<ul class="bd-list">
						<li v-for="p in inv.payments" :key="p.id"><div><div class="bd-strong">{{ money( p.amount ) }}</div><div class="bd-muted bd-small">{{ fmtDate( p.paid_on ) }} · {{ [ p.method, p.reference ].filter( Boolean ).join( ' · ' ) || '—' }}</div></div></li>
					</ul>
				</el-card>
			</aside>
		</div>

		<el-dialog v-model="billsOpen" title="Add from bills" width="680px" append-to-body destroy-on-close>
			<p class="bd-muted bd-mb">Un-invoiced one-off bills and recurring cycles for {{ inv.client_name || 'this client' }} in {{ inv.currency }}.</p>
			<el-table :data="bills" size="small" @selection-change="( rows ) => ( pickedBills = rows )">
				<template #empty>Nothing waiting to be billed.</template>
				<el-table-column type="selection" width="44" />
				<el-table-column label="Bill" min-width="220"><template #default="{ row }"><span class="bd-badge bd-badge--neutral bd-badge--plain bd-mr">{{ row.type === 'renewal' ? 'Recurring' : 'One-off' }}</span><span class="bd-strong">{{ row.label }}</span></template></el-table-column>
				<el-table-column label="Due" width="120"><template #default="{ row }">{{ fmtDate( row.date ) }}</template></el-table-column>
				<el-table-column label="Amount" align="right" width="120"><template #default="{ row }">{{ fmtMoney( row.price, row.currency ) }}</template></el-table-column>
			</el-table>
			<template #footer>
				<el-button @click="billsOpen = false">Cancel</el-button>
				<el-button type="primary" :disabled="!pickedBills.length" @click="addBills">Add {{ pickedBills.length || '' }} to invoice</el-button>
			</template>
		</el-dialog>

		<SendInvoiceDialog v-model="sendOpen" :invoice-id="Number( inv.id )" @sent="onSent" />
		<PaymentsDialog v-model="paying" :invoice-id="Number( inv.id )" @changed="load" />
	</div>
</template>
