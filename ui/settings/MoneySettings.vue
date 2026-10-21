<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { ArrowDown, ArrowUp, Delete, Plus } from '@element-plus/icons-vue';
import { api, errorMessage, store, ElMessage } from '../core';

/** The Billing tab of Settings: numbering, tax, payment methods, email wording and currencies. The business profile (name, logo, address) is WPFlat Core's. */
const form = reactive( { currencies: [], rates: {}, payment_methods: [] } );
const loaded = ref( false );
const saving = ref( false );
const usage = ref( {} );
const section = ref( 'general' );
const PLACEHOLDERS = [ 'contact_name', 'client_name', 'invoice_number', 'invoice_title', 'amount_due', 'total', 'due_date', 'issue_date', 'business_name' ].map( ( k ) => '{' + '{' + k + '}' + '}' ).join( ' ' );

const load = async () => {
	try {
		Object.assign( form, await api.getPath( 'finance/settings' ) );
		form.rates = form.rates && ! Array.isArray( form.rates ) ? form.rates : {};
		( form.currencies || [] ).forEach( ( c ) => { if ( c.active === undefined ) c.active = true; } );
		loaded.value = true;
		usage.value = await api.getPath( 'currencies/usage' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
onMounted( load );

const save = async () => {
	saving.value = true;
	try {
		Object.assign( form, await api.post( 'finance/settings', { ...form } ) );
		form.rates = form.rates && ! Array.isArray( form.rates ) ? form.rates : {};
		store.meta = await api.getPath( 'meta' ); // pickers, prices and invoices pick up the new settings right away
		ElMessage.success( 'Billing settings saved' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
};

const codeOptions = computed( () => ( form.currencies || [] ).filter( ( c ) => c.code && c.code.length === 3 ) );
const inUse = ( c ) => usage.value[ c.code ] || 0;
const addCurrency = () => form.currencies.push( { code: '', symbol: '', name: '', active: true } );
const removeCurrency = ( i ) => form.currencies.splice( i, 1 );
const upper = ( c ) => ( c.code = c.code.toUpperCase().slice( 0, 3 ) );

const METHOD_TYPES = { bank: 'Bank account', link: 'Payment link', other: 'Other' };
const newMethod = ( type ) => ( { id: '', type, title: '', currency: '', enabled: true, bank_name: '', account_name: '', account_number: '', extra_label: '', extra_value: '', url: '', button_label: '', note: '' } );
const addMethod = ( type ) => form.payment_methods.push( newMethod( type ) );
const removeMethod = ( i ) => form.payment_methods.splice( i, 1 );
const moveMethod = ( i, d ) => {
	const list = form.payment_methods;
	const j = i + d;
	if ( j < 0 || j >= list.length ) return;
	[ list[ i ], list[ j ] ] = [ list[ j ], list[ i ] ];
};
const methodTitle = ( m ) => m.title || ( m.type === 'bank' ? m.bank_name : '' ) || 'Untitled';
</script>

<template>
	<div v-loading="!loaded" class="bd-moneysettings">
		<el-radio-group v-model="section" class="bd-seg-sm bd-mb" aria-label="Billing settings">
			<el-radio-button value="general">Numbering &amp; tax</el-radio-button>
			<el-radio-button value="payments">Payment methods</el-radio-button>
			<el-radio-button value="email">Invoice email</el-radio-button>
			<el-radio-button value="currencies">Currencies</el-radio-button>
		</el-radio-group>

		<el-card v-show="section === 'general'" shadow="never">
			<p class="bd-muted bd-mb">What appears on every {{ $t( 'invoice' ).toLowerCase() }}. The business name, logo and address come from the General settings.</p>
			<el-form label-position="top" style="max-width: 820px" @submit.prevent>
				<el-row :gutter="16">
					<el-col :xs="24" :sm="12">
						<el-form-item label="Default currency">
							<el-select v-model="form.default_currency" style="width: 100%"><el-option v-for="c in codeOptions" :key="c.code" :value="c.code" :label="c.code + ' (' + c.symbol + ')'" /></el-select>
							<div class="bd-help">{{ $fmt( 'Used for new {clients}. Each {client} can bill in its own currency.' ) }}</div>
						</el-form-item>
					</el-col>
					<el-col :xs="24" :sm="12"><el-form-item label="“Due soon” window (days)"><el-input-number v-model="form.due_soon_days" :min="0" controls-position="right" style="width: 100%" /></el-form-item></el-col>
					<el-col :xs="24" :sm="12"><el-form-item :label="$t( 'invoice' ) + ' number prefix'"><el-input v-model="form.invoice_prefix" /></el-form-item></el-col>
					<el-col :xs="24" :sm="12"><el-form-item :label="'Next ' + $t( 'invoice' ).toLowerCase() + ' number'"><el-input-number v-model="form.invoice_next" :min="1" controls-position="right" style="width: 100%" /></el-form-item></el-col>
					<el-col :xs="24" :sm="12">
						<el-form-item :label="$t( 'invoice' ) + ' link word'">
							<el-input v-model="form.invoice_slug_base" placeholder="invoice"><template #prepend>/</template><template #append>/abc123</template></el-input>
							<div class="bd-help">{{ $fmt( '{Client} links look like' ) }} yoursite.com/{{ form.invoice_slug_base || 'invoice' }}/abc123. Changing it breaks links you already shared.</div>
						</el-form-item>
					</el-col>
					<el-col :xs="24" :sm="12"><el-form-item label="Default payment terms (days)"><el-input-number v-model="form.default_due_days" :min="0" controls-position="right" style="width: 100%" /></el-form-item></el-col>
					<el-col :xs="24" :sm="8"><el-form-item label="Tax label"><el-input v-model="form.tax_label" placeholder="VAT" /></el-form-item></el-col>
					<el-col :xs="24" :sm="8"><el-form-item label="Tax / VAT number"><el-input v-model="form.tax_id" /></el-form-item></el-col>
					<el-col :xs="24" :sm="8"><el-form-item label="Default tax rate (%)"><el-input-number v-model="form.default_tax_rate" :min="0" :max="100" :precision="2" controls-position="right" style="width: 100%" /></el-form-item></el-col>
					<el-col :span="24"><el-form-item label="Extra payment note"><el-input v-model="form.payment_instructions" type="textarea" :rows="2" placeholder="e.g. Please send proof of payment to billing@…" /><div class="bd-help">Optional line shown under your payment methods on unpaid invoices.</div></el-form-item></el-col>
					<el-col :span="24"><el-form-item label="Default terms"><el-input v-model="form.invoice_terms" type="textarea" :rows="3" /><div class="bd-help">Copied onto new invoices; editable per invoice.</div></el-form-item></el-col>
					<el-col :span="24"><el-form-item label="Footer line"><el-input v-model="form.invoice_footer" /></el-form-item></el-col>
				</el-row>
			</el-form>
			<el-button type="primary" :loading="saving" @click="save">Save settings</el-button>
		</el-card>

		<el-card v-show="section === 'payments'" shadow="never">
			<p class="bd-muted bd-mb">{{ $fmt( 'How {clients} can pay you.' ) }} These appear on the invoice page and in invoice emails — link methods become a “Pay now” button, bank accounts get copy buttons. Limit a method to one currency and it only shows on invoices in that currency.</p>
			<div v-if="!form.payment_methods.length" class="bd-pm-empty">No payment methods yet. Add your first bank account or payment link.</div>
			<div class="bd-pm-list">
				<div v-for="( m, i ) in form.payment_methods" :key="i" class="bd-pm" :class="{ 'is-off': !m.enabled }">
					<div class="bd-pm__head">
						<div class="bd-pm__title"><el-tag size="small" effect="plain" round>{{ METHOD_TYPES[ m.type ] }}</el-tag><strong>{{ methodTitle( m ) }}</strong><el-tag v-if="m.currency" size="small" round type="info">{{ m.currency }}</el-tag></div>
						<div class="bd-pm__tools">
							<el-switch v-model="m.enabled" size="small" aria-label="Show this method" />
							<el-button text circle :icon="ArrowUp" :disabled="i === 0" aria-label="Move up" @click="moveMethod( i, -1 )" />
							<el-button text circle :icon="ArrowDown" :disabled="i === form.payment_methods.length - 1" aria-label="Move down" @click="moveMethod( i, 1 )" />
							<el-button text type="danger" circle :icon="Delete" aria-label="Remove" @click="removeMethod( i )" />
						</div>
					</div>
					<el-form label-position="top" @submit.prevent><el-row :gutter="14">
						<el-col :xs="24" :sm="16"><el-form-item label="Title"><el-input v-model="m.title" :placeholder="m.type === 'bank' ? 'e.g. GTBank — Naira account' : m.type === 'link' ? 'e.g. Pay by card (Stripe)' : 'e.g. Cash / cheque'" /></el-form-item></el-col>
						<el-col :xs="24" :sm="8">
							<el-form-item label="Show on invoices in">
								<el-select v-model="m.currency" clearable placeholder="All currencies" style="width: 100%"><el-option v-for="c in codeOptions" :key="c.code" :value="c.code" :label="c.code" /></el-select>
							</el-form-item>
						</el-col>
						<template v-if="m.type === 'bank'">
							<el-col :xs="24" :sm="8"><el-form-item label="Bank"><el-input v-model="m.bank_name" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Account name"><el-input v-model="m.account_name" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Account number"><el-input v-model="m.account_number" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Extra detail label"><el-input v-model="m.extra_label" placeholder="SWIFT / Sort code / IBAN" /></el-form-item></el-col>
							<el-col :xs="24" :sm="16"><el-form-item label="Extra detail"><el-input v-model="m.extra_value" /></el-form-item></el-col>
						</template>
						<template v-else-if="m.type === 'link'">
							<el-col :xs="24" :sm="16"><el-form-item label="Payment URL"><el-input v-model="m.url" placeholder="https://buy.stripe.com/…" /><div class="bd-help">You can use {invoice_number}, {amount} and {currency} in the link.</div></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Button text"><el-input v-model="m.button_label" placeholder="Pay now" /></el-form-item></el-col>
						</template>
						<el-col :span="24"><el-form-item :label="m.type === 'other' ? 'Instructions' : 'Note (optional)'"><el-input v-model="m.note" type="textarea" :rows="2" /></el-form-item></el-col>
					</el-row></el-form>
				</div>
			</div>
			<div class="bd-mt">
				<el-button :icon="Plus" @click="addMethod( 'bank' )">Add bank account</el-button>
				<el-button :icon="Plus" @click="addMethod( 'link' )">Add payment link</el-button>
				<el-button :icon="Plus" @click="addMethod( 'other' )">Add other</el-button>
				<el-button type="primary" :loading="saving" @click="save">Save payment methods</el-button>
			</div>
		</el-card>

		<el-card v-show="section === 'email'" shadow="never">
			<el-form label-position="top" style="max-width: 820px" @submit.prevent>
				<h4 class="bd-subhead">{{ $t( 'invoice' ) }} email wording</h4>
				<el-form-item label="Subject"><el-input v-model="form.invoice_subject" /></el-form-item>
				<el-form-item label="Message">
					<el-input v-model="form.invoice_message" type="textarea" :rows="7" />
					<div class="bd-help">Placeholders: {{ PLACEHOLDERS }}. You can still edit each email before sending. How emails are sent (SMTP) is set in the Email tab.</div>
				</el-form-item>
				<el-form-item><el-switch v-model="form.invoice_bcc_self" active-text="Send me a copy (Bcc) of every invoice email by default" /></el-form-item>
			</el-form>
			<el-button type="primary" :loading="saving" @click="save">Save settings</el-button>
		</el-card>

		<el-card v-show="section === 'currencies'" shadow="never">
			<p class="bd-muted">Switch on the currencies your business deals with. Totals are always shown per currency; analytics can also show one combined figure using the rates below (an estimate — one current rate per currency).</p>
			<el-form label-position="top" class="bd-mt" @submit.prevent>
				<el-form-item label="Base currency (for combined totals)" style="max-width: 320px">
					<el-select v-model="form.base_currency" style="width: 100%"><el-option v-for="c in codeOptions" :key="c.code" :value="c.code" :label="c.code + ' (' + c.symbol + ')'" /></el-select>
				</el-form-item>
			</el-form>
			<el-table :data="form.currencies" size="small" style="max-width: 920px">
				<el-table-column label="On" width="70"><template #default="{ row }"><el-switch v-model="row.active" size="small" :aria-label="row.code + ' active'" /></template></el-table-column>
				<el-table-column label="Code" width="100"><template #default="{ row }"><el-input v-model="row.code" maxlength="3" @input="upper( row )" /></template></el-table-column>
				<el-table-column label="Symbol" width="90"><template #default="{ row }"><el-input v-model="row.symbol" /></template></el-table-column>
				<el-table-column label="Name" min-width="150"><template #default="{ row }"><el-input v-model="row.name" /></template></el-table-column>
				<el-table-column :label="'Rate: 1 unit = ? ' + form.base_currency" width="190">
					<template #default="{ row }"><span v-if="row.code === form.base_currency" class="bd-muted bd-small">Base currency</span><el-input-number v-else v-model="form.rates[ row.code ]" :min="0" :precision="4" :controls="false" size="small" placeholder="e.g. 1500" style="width: 100%" /></template>
				</el-table-column>
				<el-table-column label="Used by" width="110"><template #default="{ row }"><span class="bd-muted bd-small">{{ inUse( row ) ? inUse( row ) + ' record' + ( inUse( row ) === 1 ? '' : 's' ) : '—' }}</span></template></el-table-column>
				<el-table-column width="60" align="right"><template #default="{ $index, row }"><el-button text type="danger" :icon="Delete" circle :disabled="form.currencies.length < 2 || inUse( row ) > 0 || row.code === form.base_currency" :title="inUse( row ) ? 'In use — switch it off instead' : 'Remove'" @click="removeCurrency( $index )" /></template></el-table-column>
			</el-table>
			<p class="bd-help">A currency that records use can be switched off (hidden from pickers and analytics switchers) but not removed. Old data keeps its currency and still counts in totals.</p>
			<div class="bd-mt">
				<el-button :icon="Plus" @click="addCurrency">Add currency</el-button>
				<el-button type="primary" :loading="saving" @click="save">Save settings</el-button>
			</div>
		</el-card>
	</div>
</template>
