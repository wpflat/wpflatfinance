import { store, today } from './core';

export const currencies = () => store.meta.settings.currencies || [];
export const currencyOptions = () => currencies().filter( ( c ) => c.active !== false ).map( ( c ) => ( { value: c.code, label: `${ c.code } (${ c.symbol })` } ) );
export const defaultCurrency = () => store.meta.settings.default_currency || 'NGN';

export const symbolOf = ( code ) => {
	const c = currencies().find( ( x ) => x.code === code );
	return c ? c.symbol : code ? code + ' ' : '';
};

export const fmtMoney = ( amount, code ) => {
	const n = Number( amount ) || 0;
	const cents = Math.round( Math.abs( n ) * 100 ) % 100 !== 0; // whole amounts have no decimals; anything else always shows two
	return ( n < 0 ? '−' : '' ) + symbolOf( code ) + Math.abs( n ).toLocaleString( undefined, { minimumFractionDigits: cents ? 2 : 0, maximumFractionDigits: 2 } );
};

export const TIMINGS = {
	now: 'Bill now',
	end_of_month: 'End of month',
	end_of_quarter: 'End of quarter',
	next_renewal: 'Next renewal',
	custom: 'Custom date',
};
export const timingOptions = () => Object.entries( TIMINGS ).map( ( [ value, label ] ) => ( { value, label } ) );

export function summariseSelection( { charges = [], renewals = [] } ) {
	const all = [ ...charges, ...renewals ];
	if ( ! all.length ) {
		return { ok: false, reason: 'Select at least one item.' };
	}
	if ( new Set( all.map( ( r ) => r.client_id ) ).size > 1 ) {
		return { ok: false, reason: 'Select items for a single client.' };
	}
	if ( new Set( all.map( ( r ) => r.currency ) ).size > 1 ) {
		return { ok: false, reason: 'Select items in a single currency.' };
	}

	const priceById = new Map();
	charges.forEach( ( c ) => priceById.set( c.id, Number( c.price ) ) );
	renewals.forEach( ( r ) =>
		( r.pending_charges || [] ).forEach( ( pc ) => {
			if ( ! priceById.has( pc.id ) ) {
				priceById.set( pc.id, Number( pc.price ) );
			}
		} )
	);
	const chargeTotal = [ ...priceById.values() ].reduce( ( s, v ) => s + v, 0 );
	const renewalTotal = renewals.reduce( ( s, r ) => s + Number( r.price ), 0 );

	return {
		ok: true,
		client_id: all[ 0 ].client_id,
		client_name: all[ 0 ].client_name,
		currency: all[ 0 ].currency,
		charge_ids: [ ...priceById.keys() ],
		renewal_ids: renewals.map( ( r ) => r.id ),
		count: priceById.size + renewals.length,
		total: chargeTotal + renewalTotal,
		hasRolledIn: priceById.size > charges.length,
	};
}

export const BILL_STATE = {
	overdue: { label: 'Late to invoice', tone: 'danger' },
	ready: { label: 'Ready to invoice', tone: 'warning' },
	scheduled: { label: 'Scheduled', tone: 'neutral' },
	unscheduled: { label: 'No date set', tone: 'neutral' },
	overdue_to_bill: { label: 'Late to invoice', tone: 'danger' },
	ready_to_bill: { label: 'Ready to invoice', tone: 'warning' },
	overdue_payment: { label: 'Overdue', tone: 'danger' },
	payment_due: { label: 'Payment due', tone: 'warning' },
	awaiting: { label: 'Awaiting payment', tone: 'info' },
};

export const PAYMENT_METHODS = [ 'Bank transfer', 'Card', 'Cash', 'Cheque', 'Mobile money', 'Other' ].map( ( m ) => ( { value: m, label: m } ) );

export const BILL_STATUS = {
	not_invoiced: { label: 'To invoice', tone: 'warning' },
	invoice_draft: { label: 'Invoice draft', tone: 'neutral' },
	awaiting: { label: 'Awaiting payment', tone: 'info' },
	overdue: { label: 'Overdue', tone: 'danger' },
	paid: { label: 'Paid', tone: 'success' },
	waived: { label: 'Waived', tone: 'neutral' },
	closed: { label: 'Closed', tone: 'neutral' },
};


/* ───────────── Period presets and CSV download (the same helpers BizyCore's analytics bar uses) ───────────── */
/** Period presets used by the analytics bar. `to` is always today (or the end of a past month). */
export const PERIODS = [
	{ id: '30d', label: '30 days' },
	{ id: '90d', label: '90 days' },
	{ id: 'ytd', label: 'This year' },
	{ id: '12m', label: '12 months' },
	{ id: 'custom', label: 'Custom' },
];

const iso = ( d ) => d.toISOString().slice( 0, 10 );
const shift = ( dateStr, days ) => {
	const d = new Date( dateStr + 'T00:00:00Z' );
	d.setUTCDate( d.getUTCDate() + days );
	return iso( d );
};

/** { from, to } (inclusive, YYYY-MM-DD) for a preset id. */
export const rangeFor = ( id, custom = [] ) => {
	const to = today();
	if ( id === '30d' ) return { from: shift( to, -29 ), to };
	if ( id === '90d' ) return { from: shift( to, -89 ), to };
	if ( id === 'ytd' ) return { from: to.slice( 0, 4 ) + '-01-01', to };
	if ( id === '12m' ) {
		const d = new Date( to + 'T00:00:00Z' );
		d.setUTCMonth( d.getUTCMonth() - 11, 1 );
		return { from: iso( d ), to };
	}
	return { from: custom[ 0 ] || shift( to, -29 ), to: custom[ 1 ] || to };
};

/** Bucket size for a span so a chart has a sensible number of points. */
export const granularity = ( from, to ) => {
	const days = Math.round( ( new Date( to ) - new Date( from ) ) / 86400000 ) + 1;
	return days <= 45 ? 'day' : days <= 200 ? 'week' : 'month';
};

/** Download rows as a CSV file (headers from the first row's keys). */
export const toCsv = ( rows ) => {
	if ( ! rows.length ) return '';
	const keys = Object.keys( rows[ 0 ] );
	const esc = ( v ) => {
		const t = v === null || v === undefined ? '' : String( v );
		return /[",\n]/.test( t ) ? '"' + t.replace( /"/g, '""' ) + '"' : t;
	};
	return [ keys.join( ',' ), ...rows.map( ( r ) => keys.map( ( k ) => esc( r[ k ] ) ).join( ',' ) ) ].join( '\n' );
};
export const downloadCsv = ( name, rows ) => {
	const blob = new Blob( [ toCsv( rows ) ], { type: 'text/csv;charset=utf-8' } );
	const a = document.createElement( 'a' );
	a.href = URL.createObjectURL( blob );
	a.download = name;
	a.click();
	URL.revokeObjectURL( a.href );
};

/* ───────────── Who may see what ───────────── */
export const canBilling = () => !! ( store.caps.billing || store.caps.admin );
export const canFinance = () => !! ( store.caps.finance || store.caps.admin );
