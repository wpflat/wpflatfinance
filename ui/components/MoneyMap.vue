<script setup>
import { computed } from 'vue';
import { fmtMoney } from '../util';

const props = defineProps( { map: { type: Object, default: () => ( {} ) }, empty: { type: String, default: '—' } } );
const entries = computed( () => Object.entries( props.map || {} ).filter( ( [ , v ] ) => v.amount || v.count ) );
</script>

<template>
	<span v-if="!entries.length" class="bd-muted">{{ empty }}</span>
	<div v-for="[ code, v ] in entries" v-else :key="code" class="bd-money-line">{{ fmtMoney( v.amount, code ) }}</div>
</template>
