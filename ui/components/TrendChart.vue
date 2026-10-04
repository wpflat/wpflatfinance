<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { fmtMoney } from '../util';
import { Chart, LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Filler } from 'chart.js';

Chart.register( LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Filler );

const props = defineProps( { labels: Array, billed: Array, paid: Array, currency: String, dark: Boolean } );
const canvas = ref( null );
let chart = null;

const token = ( name, fallback ) => getComputedStyle( document.getElementById( 'bizycore-root' ) || document.body ).getPropertyValue( name ).trim() || fallback;

const rgba = ( hex, a ) => {
	const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec( hex );
	return m ? `rgba(${ parseInt( m[ 1 ], 16 ) },${ parseInt( m[ 2 ], 16 ) },${ parseInt( m[ 3 ], 16 ) },${ a })` : hex;
};

const draw = () => {
	if ( ! canvas.value ) {
		return;
	}
	const ctx = canvas.value.getContext( '2d' );
	const accent = token( '--bd-accent', '#635bff' );
	const green = token( '--bd-success', '#15a46e' );
	const muted = token( '--bd-muted', '#8392a5' );
	const ring = token( '--bd-ring', 'rgba(10,37,64,.07)' );
	const surface = token( '--bd-surface', '#fff' );
	const grad = ( color ) => {
		const g = ctx.createLinearGradient( 0, 0, 0, canvas.value.clientHeight || 280 );
		g.addColorStop( 0, rgba( color, 0.08 ) );
		g.addColorStop( 1, rgba( color, 0 ) );
		return g;
	};
	const line = ( label, data, color ) => ( {
		label,
		data,
		borderColor: color,
		backgroundColor: grad( color ),
		fill: true,
		tension: 0.25,
		borderWidth: 2,
		pointRadius: 0,
		pointHoverRadius: 4,
		pointHoverBackgroundColor: surface,
		pointHoverBorderColor: color,
		pointHoverBorderWidth: 2,
	} );
	const options = {
		responsive: true,
		maintainAspectRatio: false,
		interaction: { mode: 'index', intersect: false },
		plugins: {
			legend: { display: false },
			tooltip: {
				backgroundColor: token( '--bd-ink', '#0a2540' ),
				titleColor: '#fff',
				bodyColor: '#e6ebf4',
				padding: 12,
				cornerRadius: 8,
				boxPadding: 6,
				usePointStyle: true,
				callbacks: { label: ( c ) => ` ${ c.dataset.label }  ${ fmtMoney( c.parsed.y, props.currency ) }` },
			},
		},
		scales: {
			x: { ticks: { color: muted, padding: 8, font: { family: 'Inter', size: 12 } }, grid: { display: false }, border: { display: false } },
			y: { beginAtZero: true, ticks: { color: muted, padding: 8, maxTicksLimit: 5, font: { family: 'Inter', size: 12 }, callback: ( v ) => fmtMoney( v, props.currency ) }, grid: { color: ring }, border: { display: false } },
		},
	};
	if ( chart ) {
		chart.destroy();
	}
	chart = new Chart( canvas.value, { type: 'line', data: { labels: props.labels, datasets: [ line( 'Billed', props.billed, accent ), line( 'Paid', props.paid, green ) ] }, options } );
};

onMounted( draw );
onBeforeUnmount( () => chart && chart.destroy() );
watch( () => [ props.labels, props.billed, props.paid, props.currency, props.dark ], () => setTimeout( draw, 0 ), { deep: true } );
</script>

<template>
	<div class="bd-chart"><canvas ref="canvas" /></div>
</template>
