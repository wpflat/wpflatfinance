<script setup>
import MoneyMap from '../components/MoneyMap.vue';

defineProps( { card: { type: Object, default: () => ( {} ) } } );
</script>

<template>
	<template v-if="card.modules && card.modules.finance">
		<div><span class="bd-muted bd-small">Income</span><MoneyMap :map="card.modules.finance.income" empty="—" /></div>
		<div><span class="bd-muted bd-small">Profit</span><MoneyMap :map="card.modules.finance.profit" empty="—" /></div>
		<div><span class="bd-muted bd-small">Owed by {{ $tp( 'client' ).toLowerCase() }}</span><MoneyMap :map="card.modules.finance.receivable" empty="—" /></div>
	</template>
</template>
