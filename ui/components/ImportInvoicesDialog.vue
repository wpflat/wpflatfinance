<script setup>
import { ref, computed, watch } from 'vue';
import { ElMessage, ElMessageBox, sdk, RemoteSelect, api, dept, errorMessage } from '../core';
import { fmtMoney } from '../util';

const props = defineProps( { modelValue: Boolean } );
const emit = defineEmits( [ 'update:modelValue', 'imported' ] );

const step = ref( 'upload' ); // upload | review | run | done
const busy = ref( false );
const err = ref( '' );
const pv = ref( null );
const department = ref( null );
const choice = ref( {} ); // customer key -> { mode: 'new'|'existing', id, name }
const progress = ref( 0 );
const result = ref( null );
const numberPrefix = ref( 'SI-' );
const skipMaybe = ref( {} ); // ref -> true when a possible duplicate is skipped
const fileInput = ref( null );

watch( () => props.modelValue, ( v ) => {
	if ( v ) {
		step.value = 'upload'; err.value = ''; pv.value = null; department.value = null; choice.value = {}; result.value = null; progress.value = 0; skipMaybe.value = {};
	}
} );

const sumText = ( map ) => Object.entries( map || {} ).map( ( [ c, v ] ) => fmtMoney( v, c ) ).join( ' · ' );
const importCount = computed( () => ( pv.value ? pv.value.invoices - Object.values( skipMaybe.value ).filter( Boolean ).length : 0 ) );
const newCount = computed( () => new Set( Object.values( choice.value ).filter( ( c ) => c.mode === 'new' ).map( ( c ) => c.name.trim().toLowerCase() ) ).size );

/** Two names sharing one email are usually one client: default to the name that matches the email's domain. */
function initChoices( customers ) {
	const out = {};
	const byMail = {};
	customers.forEach( ( c ) => { if ( c.email ) ( byMail[ c.email.toLowerCase() ] = byMail[ c.email.toLowerCase() ] || [] ).push( c ); } );
	customers.forEach( ( c ) => {
		let name = c.name;
		const group = c.email ? byMail[ c.email.toLowerCase() ] : null;
		if ( group && group.length > 1 ) {
			const domain = c.email.split( '@' )[ 1 ].replace( /[^a-z0-9]/gi, '' ).toLowerCase();
			const best = group.find( ( g ) => domain.includes( g.name.replace( /[^a-z0-9]/gi, '' ).toLowerCase() ) ) || group[ 0 ];
			name = best.name;
		}
		out[ c.key ] = c.match ? { mode: 'existing', id: c.match, name } : { mode: 'new', id: 0, name };
	} );
	return out;
}

async function pick( e ) {
	const file = e.target.files[ 0 ];
	if ( ! file ) return;
	busy.value = true;
	err.value = '';
	try {
		const fd = new FormData();
		fd.append( 'file', file );
		pv.value = await api.upload( 'invoices/import/preview', fd );
		choice.value = initChoices( pv.value.customers );
		skipMaybe.value = Object.fromEntries( ( pv.value.maybe_duplicates || [] ).map( ( d ) => [ d.ref, true ] ) );
		step.value = 'review';
	} catch ( x ) {
		err.value = errorMessage( x );
	} finally {
		busy.value = false;
		if ( fileInput.value ) fileInput.value.value = '';
	}
}

function setTarget( c, v ) {
	choice.value[ c.key ] = v === 'new' ? { mode: 'new', id: 0, name: choice.value[ c.key ].name } : { mode: 'existing', id: v, name: choice.value[ c.key ].name };
}

async function run() {
	step.value = 'run';
	err.value = '';
	const rows = pv.value.rows.filter( ( r ) => ! pv.value.duplicates.includes( r.number ) && ! skipMaybe.value[ r.ref ] );
	const targets = {};
	pv.value.customers.forEach( ( c ) => {
		const ch = choice.value[ c.key ];
		targets[ c.key ] = ch.mode === 'existing' ? { mode: 'existing', id: ch.id } : { mode: 'new', name: ch.name.trim() || c.name, email: c.email, site: c.site || '' };
	} );
	const plan = { department_id: department.value || 0, targets, source: pv.value.source, number_prefix: numberPrefix.value || 'SI-' };
	const SIZE = 40;
	let id = '';
	const tot = { created: 0, skipped: 0, errors: [] };
	try {
		for ( let i = 0; i < rows.length; i += SIZE ) {
			const r = await api.post( 'invoices/import/run', { rows: rows.slice( i, i + SIZE ), plan, import_id: id, last: i + SIZE >= rows.length } );
			id = r.import_id;
			tot.created += r.created; tot.skipped += r.skipped; tot.errors.push( ...r.errors );
			tot.import_id = id; tot.clients = r.total_clients;
			progress.value = Math.min( 100, Math.round( ( ( i + SIZE ) / rows.length ) * 100 ) );
		}
		result.value = tot;
		step.value = 'done';
		emit( 'imported' );
	} catch ( x ) {
		err.value = errorMessage( x ) + ( id ? ' Part of the file may already be imported; you can undo it from the summary or re-run (already imported invoices are skipped).' : '' );
		result.value = tot.import_id ? tot : null;
		step.value = 'done';
		if ( tot.import_id ) emit( 'imported' );
	}
}

async function undo() {
	try {
		await ElMessageBox.confirm( sdk.fmt( 'Remove the invoices, payments and new {clients} created by this import?' ), 'Undo import', { type: 'warning', confirmButtonText: 'Undo import' } );
	} catch ( e ) { return; }
	try {
		const r = await api.post( `invoices/import/${ result.value.import_id }/undo` );
		ElMessage.success( `Removed ${ r.invoices } invoices and ${ r.clients } clients.` );
		emit( 'imported' );
		emit( 'update:modelValue', false );
	} catch ( x ) {
		ElMessage.error( errorMessage( x ) );
	}
}

const close = () => { if ( step.value !== 'run' ) emit( 'update:modelValue', false ); };
</script>

<template>
	<el-dialog :model-value="modelValue" title="Import invoices" width="860px" append-to-body :close-on-click-modal="false" :show-close="step !== 'run'" class="bd-import" @update:model-value="close">
		<div v-if="step === 'upload'">
			<p>Upload an invoice export from another platform: <strong>Invoice.ng</strong> (.xlsx or .csv) or <strong>Sprout Invoices</strong> (.csv). WPFlat recognises which one it is, and you see exactly what will be created before anything is saved.</p>
			<p class="bd-muted bd-small">These exports have totals rather than line items, so each invoice becomes one line, and a paid invoice gets one payment dated the day it was issued. Original invoice numbers are kept and your own numbering is not changed. Invoices that look like ones you already have are flagged so you can skip them.</p>
			<input ref="fileInput" type="file" accept=".xlsx,.csv" hidden @change="pick" />
			<el-button type="primary" :loading="busy" @click="fileInput.click()">Choose file…</el-button>
			<el-alert v-if="err" :title="err" type="error" show-icon :closable="false" class="bd-mt" />
		</div>

		<div v-else-if="step === 'review' && pv">
			<el-alert type="info" show-icon :closable="false" class="bd-mb" :title="`${ pv.source }: ${ pv.invoices } invoices from ${ pv.customers.length } customer names — ${ sumText( pv.totals ) }`">
				<span v-if="pv.kinds.draft || pv.kinds.cancelled" class="bd-small">{{ pv.kinds.draft }} draft · {{ pv.kinds.cancelled }} written off (imported as cancelled)</span>
			</el-alert>
			<div v-if="!pv.numbered" class="bd-mb bd-small">This export has no invoice numbers, so each invoice is numbered with its original ID. Number prefix: <el-input v-model="numberPrefix" size="small" style="width: 110px; margin-left: 6px" /> <span class="bd-muted">e.g. {{ numberPrefix }}4009</span></div>
			<div v-if="pv.maybe_duplicates && pv.maybe_duplicates.length" class="bd-mb">
				<el-alert type="warning" show-icon :closable="false" :title="`${ pv.maybe_duplicates.length } look like invoices you already have (same client and amount, issued within a week). They are skipped unless you untick them.`" />
				<el-table :data="pv.maybe_duplicates" size="small" max-height="200" class="bd-mt-xs">
					<el-table-column label="Skip" width="60"><template #default="{ row }"><el-checkbox v-model="skipMaybe[ row.ref ]" :aria-label="'Skip ' + row.label" /></template></el-table-column>
					<el-table-column label="In the file" min-width="200"><template #default="{ row }">{{ row.label }} · {{ row.client }}<div class="bd-muted bd-small">{{ fmtMoney( row.amount, row.currency ) }} · {{ row.issue }}</div></template></el-table-column>
					<el-table-column label="Already in WPFlat" min-width="160"><template #default="{ row }">{{ row.existing_number }}<div class="bd-muted bd-small">{{ row.existing_date }}</div></template></el-table-column>
				</el-table>
			</div>
			<el-alert v-if="pv.duplicates.length" type="warning" show-icon :closable="false" class="bd-mb" :title="`${ pv.duplicates.length } invoice number${ pv.duplicates.length === 1 ? ' is' : 's are' } already in WPFlat and will be skipped: ${ pv.duplicates.slice( 0, 6 ).join( ', ' ) }${ pv.duplicates.length > 6 ? '…' : '' }`" />
			<el-alert v-for="( i, n ) in pv.issues" :key="n" type="warning" show-icon :closable="false" :title="i" class="bd-mb" />
			<div class="bd-mb"><span class="bd-strong">{{ dept().one }} for everything imported</span>
				<RemoteSelect v-model="department" resource="departments" :label="( b ) => b.name" :params="{ status: 'active' }" :placeholder="'No ' + dept().lower" clearable style="width: 260px; margin-left: 10px" />
			</div>
			<p class="bd-muted bd-small bd-mb">{{ $fmt( 'Choose which {client} each name belongs to. Names that become the same new {client} are merged into one.' ) }} Nothing is created until you press Import.</p>
			<el-table :data="pv.customers" size="small" max-height="380">
				<el-table-column label="In the file" min-width="200"><template #default="{ row }"><div class="bd-strong">{{ row.raw_names.join( ' / ' ) }}</div><div class="bd-muted bd-small">{{ row.invoices }} invoice{{ row.invoices === 1 ? '' : 's' }} · {{ sumText( row.total ) }}<span v-if="row.email"> · {{ row.email }}</span><span v-if="row.site"> · {{ row.site }}</span></div><div v-if="row.shared_email.length" class="bd-small bd-warn">Same email as {{ row.shared_email.join( ', ' ) }}</div></template></el-table-column>
				<el-table-column label="Goes to" min-width="260">
					<template #default="{ row }">
						<el-select :model-value="choice[ row.key ].mode === 'new' ? 'new' : choice[ row.key ].id" filterable size="small" style="width: 100%" @update:model-value="( v ) => setTarget( row, v )">
							<el-option value="new" :label="$fmt( '＋ Create a new {client}' )" />
							<el-option v-for="c in pv.clients" :key="c.id" :value="c.id" :label="c.name" />
						</el-select>
						<el-input v-if="choice[ row.key ].mode === 'new'" v-model="choice[ row.key ].name" size="small" class="bd-mt-xs" :placeholder="$fmt( '{Client} name' )" />
					</template>
				</el-table-column>
			</el-table>
			<p class="bd-small bd-mt">This will create <strong>{{ importCount }}</strong> invoices and <strong>{{ newCount }}</strong> new client{{ newCount === 1 ? '' : 's' }}.</p>
		</div>

		<div v-else-if="step === 'run'">
			<el-progress :percentage="progress" :stroke-width="10" />
			<p class="bd-mt">Importing… keep this page open.</p>
		</div>

		<div v-else-if="step === 'done'">
			<el-alert v-if="err" :title="err" type="error" show-icon :closable="false" class="bd-mb" />
			<el-alert v-if="result" type="success" show-icon :closable="false" :title="`Imported ${ result.created } invoices${ result.clients ? ' and ' + result.clients + ' new clients' : '' }.`" />
			<p v-if="result && result.skipped" class="bd-muted bd-small bd-mt">{{ result.skipped }} skipped (already in WPFlat).</p>
			<el-alert v-for="( e, i ) in ( result ? result.errors : [] )" :key="i" :title="e" type="warning" show-icon :closable="false" class="bd-mt" />
		</div>

		<template #footer>
			<template v-if="step === 'upload'"><el-button @click="close">Cancel</el-button></template>
			<template v-else-if="step === 'review'"><el-button @click="step = 'upload'">Back</el-button><el-button type="primary" :disabled="!importCount" @click="run">Import {{ importCount }} invoices</el-button></template>
			<template v-else-if="step === 'done'"><el-button v-if="result && result.import_id" @click="undo">Undo this import</el-button><el-button type="primary" @click="close">Done</el-button></template>
		</template>
	</el-dialog>
</template>

<style scoped>
.bd-warn { color: var(--bd-badge-warning-text); }
.bd-mt-xs { margin-top: 4px; }
</style>
