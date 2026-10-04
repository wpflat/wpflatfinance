<script setup>
import MoneyMap from '../components/MoneyMap.vue';

defineProps( { card: { type: Object, default: () => ( {} ) } } );
</script>

<template>
	<template v-if="card.modules && card.modules.money">
		<div><span class="bd-muted bd-small">Income</span><MoneyMap :map="card.modules.money.income" empty="—" /></div>
		<div><span class="bd-muted bd-small">Profit</span><MoneyMap :map="card.modules.money.profit" empty="—" /></div>
		<div><span class="bd-muted bd-small">Owed by {{ $tp( 'client' ).toLowerCase() }}</span><MoneyMap :map="card.modules.money.receivable" empty="—" /></div>
	</template>
</template>
