<script setup>
import { computed } from 'vue';
import { StatCard } from '../core';
import MoneyMap from '../components/MoneyMap.vue';

const props = defineProps( { department: { type: Object, default: () => ( {} ) } } );
const m = computed( () => ( props.department.modules && props.department.modules.finance ) || null );
const profitNeg = computed( () => !! m.value && Object.values( m.value.profit || {} ).some( ( v ) => v.amount < 0 ) );
</script>

<template>
	<template v-if="m">
		<el-col :xs="12" :md="6"><StatCard label="Income" sub="Invoiced in the period"><MoneyMap :map="m.income" empty="—" /></StatCard></el-col>
		<el-col :xs="12" :md="6"><StatCard label="Profit" :tone="profitNeg ? 'danger' : ''" sub="After direct costs, team pay and expenses"><MoneyMap :map="m.profit" empty="—" /></StatCard></el-col>
		<el-col :xs="12" :md="6"><StatCard :label="'Owed by ' + $tp( 'client' ).toLowerCase()" sub="Unpaid invoices"><MoneyMap :map="m.receivable" empty="—" /></StatCard></el-col>
	</template>
</template>
