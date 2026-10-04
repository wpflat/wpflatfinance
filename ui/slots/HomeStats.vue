<script setup>
import { Tickets } from '@element-plus/icons-vue';
import { StatCard } from '../core';
import MoneyMap from '../components/MoneyMap.vue';

defineProps( { home: { type: Object, default: null } } );
</script>

<template>
	<el-col v-if="home && home.stats.money_owed" :xs="12" :md="6">
		<StatCard label="Waiting to be paid" :icon="Tickets" :tone="Object.keys( home.stats.money_owed ).length ? 'warning' : ''" sub="Unpaid invoices" to="/billing/invoices?unpaid=1"><MoneyMap :map="home.stats.money_owed" empty="Nothing" /></StatCard>
	</el-col>
</template>
