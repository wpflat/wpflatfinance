<script setup>
import { computed } from 'vue';
import { StatCard } from '../core';
import { defaultCurrency, fmtMoney } from '../util';

const props = defineProps( { project: { type: Object, default: null } } );
const m = computed( () => ( props.project && props.project.money ) || null );
const cur = computed( () => ( props.project && props.project.currency ) || defaultCurrency() );
</script>

<template>
	<template v-if="m">
		<el-col :xs="12" :md="4"><StatCard :label="$t( 'project' ) + ' price'" :sub="cur"><span class="bd-stat__text">{{ fmtMoney( m.price, cur ) }}</span></StatCard></el-col>
		<el-col :xs="12" :md="4"><StatCard label="Team budget" sub="Across phases"><span class="bd-stat__text">{{ fmtMoney( m.budget, cur ) }}</span></StatCard></el-col>
		<el-col :xs="12" :md="4"><StatCard label="Shared out" sub="Allocated to people"><span class="bd-stat__text">{{ fmtMoney( m.allocated, cur ) }}</span></StatCard></el-col>
		<el-col :xs="12" :md="4"><StatCard label="Owed to team" :tone="m.owed ? 'warning' : ''" sub="Earned, not paid"><span class="bd-stat__text">{{ fmtMoney( m.owed, cur ) }}</span></StatCard></el-col>
		<el-col :xs="12" :md="4"><StatCard label="Paid to team" sub="Payouts so far"><span class="bd-stat__text">{{ fmtMoney( m.paid, cur ) }}</span></StatCard></el-col>
		<el-col :xs="12" :md="4"><StatCard label="Profit" :tone="m.margin < 0 ? 'danger' : ''" sub="Price − team pay"><span class="bd-stat__text">{{ fmtMoney( m.margin, cur ) }}</span></StatCard></el-col>
	</template>
</template>
