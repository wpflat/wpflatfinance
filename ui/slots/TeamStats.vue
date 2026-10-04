<script setup>
import { Coin, Wallet } from '@element-plus/icons-vue';
import { StatCard } from '../core';
import MoneyMap from '../components/MoneyMap.vue';

defineProps( { member: { type: Object, default: null } } );
</script>

<template>
	<template v-if="member && member.owed">
		<el-col :xs="12" :md="6"><StatCard label="Owed now" :icon="Coin" :tone="Object.keys( member.owed ).length ? 'warning' : ''" sub="Earned, not paid yet"><MoneyMap :map="member.owed" empty="Nothing" /></StatCard></el-col>
		<el-col :xs="12" :md="6"><StatCard label="Paid this year" :icon="Wallet" sub="Payouts so far"><MoneyMap :map="member.paid_year" empty="Nothing yet" /></StatCard></el-col>
	</template>
</template>
