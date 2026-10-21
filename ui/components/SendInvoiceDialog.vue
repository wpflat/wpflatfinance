<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ElMessage, sdk, api, errorMessage, store } from '../core';
import { Promotion, Message } from '@element-plus/icons-vue';

const props = defineProps( { modelValue: Boolean, invoiceId: { type: Number, default: 0 }, mode: { type: String, default: 'invoice' } } );
const isReminder = computed( () => props.mode === 'reminder' );
const emit = defineEmits( [ 'update:modelValue', 'sent' ] );

const PH_EXAMPLE = '{' + '{contact_name}} and {' + '{amount_due}}';
const loading = ref( false );
const sending = ref( false );
const testing = ref( false );
const error = ref( '' );
const info = ref( '' );
const defaults = ref( null );
const form = reactive( { to: [], cc: [], bcc: [], subject: '', message: '', bcc_self: false, from_email: '' } );
const testTo = ref( '' );
const previewHtml = ref( '' );
let timer = null;

/** Every address we know about, for the pickers (typed addresses are allowed too). */
const people = computed( () => {
	const d = defaults.value;
	if ( ! d ) {
		return [];
	}
	const map = new Map();
	[ ...d.contacts, ...d.cc, ...d.team ].forEach( ( p ) => {
		if ( p.email && ! map.has( p.email.toLowerCase() ) ) {
			map.set( p.email.toLowerCase(), { value: p.email, name: p.name, label: p.name ? `${ p.name } <${ p.email }>` : p.email, hint: p.job_title || '' } );
		}
	} );
	return [ ...map.values() ];
} );
const nameOf = ( email ) => {
	const p = people.value.find( ( x ) => x.value.toLowerCase() === String( email ).toLowerCase() );
	return p ? p.name : '';
};
const objs = ( list ) => list.map( ( e ) => ( { name: nameOf( e ), email: e } ) );

const load = async () => {
	loading.value = true;
	error.value = '';
	info.value = '';
	try {
		const d = await api.getPath( `invoices/${ props.invoiceId }/${ isReminder.value ? 'reminder-defaults' : 'email-defaults' }` );
		defaults.value = d;
		form.to = d.to.map( ( p ) => p.email );
		form.cc = d.cc.map( ( p ) => p.email );
		form.bcc = [];
		form.subject = d.subject;
		form.message = d.message;
		form.bcc_self = d.bcc_self;
		form.from_email = '';
		try {
			const me = ( d.from_options || [] ).find( ( o ) => o.key === 'me' );
			if ( me && window.localStorage.getItem( 'bd-send-as' ) === 'me' ) form.from_email = me.email;
		} catch ( e ) { /* remembering the choice is optional */ }
		testTo.value = d.user_email || store.userEmail || '';
		await refreshPreview();
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		loading.value = false;
	}
};

const refreshPreview = async () => {
	try {
		const r = await api.post( `invoices/${ props.invoiceId }/email-preview`, { to: objs( form.to ), subject: form.subject, message: form.message } );
		previewHtml.value = r.html;
	} catch ( e ) {
		previewHtml.value = '';
	}
};

watch( () => props.modelValue, ( open ) => {
	if ( open && props.invoiceId ) {
		defaults.value = null;
		previewHtml.value = '';
		load();
	}
} );
watch( () => [ form.subject, form.message, form.to.join( ',' ) ], () => {
	if ( ! props.modelValue || ! defaults.value ) {
		return;
	}
	clearTimeout( timer );
	timer = setTimeout( refreshPreview, 450 );
} );

const payload = () => ( { to: objs( form.to ), cc: objs( form.cc ), bcc: objs( form.bcc ), subject: form.subject, message: form.message, bcc_self: form.bcc_self, from_email: form.from_email } );

const send = async () => {
	sending.value = true;
	error.value = '';
	try {
		const r = await api.post( `invoices/${ props.invoiceId }/${ isReminder.value ? 'remind' : 'send' }`, payload() );
		try { window.localStorage.setItem( 'bd-send-as', form.from_email ? 'me' : 'default' ); } catch ( e ) { /* optional */ }
		ElMessage.success( `${ isReminder.value ? 'Reminder' : 'Invoice' } emailed to ${ r.to.join( ', ' ) }${ r.cc && r.cc.length ? ` (cc ${ r.cc.length })` : '' }` );
		emit( 'update:modelValue', false );
		emit( 'sent', r );
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		sending.value = false;
	}
};

const sendTest = async () => {
	testing.value = true;
	error.value = '';
	info.value = '';
	try {
		const r = await api.post( `invoices/${ props.invoiceId }/send`, { ...payload(), test_to: testTo.value } );
		info.value = `Test email sent to ${ r.to[ 0 ] }. Check your inbox (and spam folder) — nothing was sent to the ${ sdk.t( 'client' ).toLowerCase() }.`;
	} catch ( e ) {
		error.value = `Test email failed: ${ errorMessage( e ) }`;
	} finally {
		testing.value = false;
	}
};

/** Scripts are blocked by the sandbox (no allow-scripts); links open in a new tab. */
const previewDoc = computed( () => {
	const html = previewHtml.value;
	if ( ! html ) return '';
	const base = '<base target="_blank">';
	return html.includes( '<head>' ) ? html.replace( '<head>', '<head>' + base ) : base + html;
} );
const noRecipient = computed( () => defaults.value && ! form.to.length );
</script>

<template>
	<el-drawer :model-value="modelValue" size="1040px" append-to-body destroy-on-close :close-on-click-modal="false" @update:model-value="emit( 'update:modelValue', $event )">
		<template #header>
			<div>
				<div class="bd-eyebrow">{{ isReminder ? 'Send payment reminder' : 'Email invoice' }}</div>
				<h3 class="bd-drawer-title">{{ defaults ? defaults.invoice_number : 'Invoice' }}<span v-if="defaults" class="bd-muted"> · {{ defaults.client_name }}</span></h3>
			</div>
		</template>

		<el-skeleton v-if="loading && !defaults" :rows="8" animated />
		<div v-else-if="defaults" class="bd-send">
			<div class="bd-send__form">
				<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
				<el-alert v-if="info" :title="info" type="success" show-icon :closable="false" class="bd-mb" />
				<el-alert v-if="noRecipient" type="warning" show-icon :closable="false" class="bd-mb" title="No recipient yet" :description="$fmt( 'This {client} has no primary contact with an email. Add one under {Clients} → Contacts, or type an address below.' )" />

				<div v-if="!defaults.from_options || defaults.from_options.length < 2" class="bd-send__from bd-muted bd-small">
					From <strong>{{ defaults.from.name }} &lt;{{ defaults.from.email }}&gt;</strong> · via {{ defaults.method === 'smtp' ? 'WPFlat SMTP' : 'the site mailer' }}
					<div v-if="defaults.from_note" class="bd-help">{{ defaults.from_note }}</div>
				</div>
				<template v-else>
					<label class="bd-lab">From <span class="bd-muted">— via {{ defaults.method === 'smtp' ? 'WPFlat SMTP' : 'the site mailer' }}</span></label>
					<el-select v-model="form.from_email" style="width: 100%" aria-label="Send from">
						<el-option v-for="o in defaults.from_options" :key="o.key" :value="o.key === 'default' ? '' : o.email" :label="o.label" />
					</el-select>
					<div class="bd-help">Pick your own address to send as yourself; replies then come to you. It is offered because it is on the same domain as the email settings.</div>
				</template>

				<label class="bd-lab">To <span class="bd-muted">— primary contacts</span></label>
				<el-select v-model="form.to" multiple filterable allow-create default-first-option :reserve-keyword="false" placeholder="Add recipients" style="width: 100%">
					<el-option v-for="p in people" :key="p.value" :label="p.label" :value="p.value"><span>{{ p.label }}</span><span v-if="p.hint" class="bd-muted bd-small"> · {{ p.hint }}</span></el-option>
				</el-select>

				<label class="bd-lab bd-mt">Cc <span class="bd-muted">— secondary contacts &amp; your team</span></label>
				<el-select v-model="form.cc" multiple filterable allow-create default-first-option :reserve-keyword="false" placeholder="Add people to copy" style="width: 100%">
					<el-option v-for="p in people" :key="p.value" :label="p.label" :value="p.value"><span>{{ p.label }}</span><span v-if="p.hint" class="bd-muted bd-small"> · {{ p.hint }}</span></el-option>
				</el-select>

				<label class="bd-lab bd-mt">Subject</label>
				<el-input v-model="form.subject" />

				<label class="bd-lab bd-mt">Message</label>
				<el-input v-model="form.message" type="textarea" :rows="9" />
				<div v-if="isReminder" class="bd-help">The payment details and a button to open the invoice are added under your message. Edit the default wording in Settings → Billing → Reminders.</div>
				<div v-else class="bd-help">Placeholders such as {{ PH_EXAMPLE }} are filled in for you. Edit the default wording in Settings → Email.</div>

				<el-checkbox v-if="!isReminder" v-model="form.bcc_self" class="bd-mt">Send me a copy (Bcc)</el-checkbox>
			</div>

			<div class="bd-send__preview">
				<div class="bd-send__prevhead"><span class="bd-eyebrow">Preview</span></div>
				<iframe class="bd-mailprev" title="Email preview" :srcdoc="previewDoc" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" />
			</div>
		</div>

		<template #footer>
			<div class="bd-send__foot">
				<div v-if="!isReminder" class="bd-send__test">
					<el-input v-model="testTo" placeholder="you@yourcompany.com" size="default" style="width: 240px" aria-label="Send a test to" />
					<el-button :icon="Message" :loading="testing" :disabled="!testTo" @click="sendTest">Send test</el-button>
				</div>
				<div>
					<el-button @click="emit( 'update:modelValue', false )">Cancel</el-button>
					<el-button type="primary" :icon="Promotion" :loading="sending" :disabled="!form.to.length" @click="send">{{ isReminder ? 'Send reminder' : 'Send invoice' }}</el-button>
				</div>
			</div>
		</template>
	</el-drawer>
</template>
