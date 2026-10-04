<script setup>
import { computed } from 'vue';
import { fmtMoney } from '../util';

const props = defineProps( { member: { type: Object, default: null }, goTab: { type: Function, default: () => {} } } );
const salary = computed( () => ( props.member && Number( props.member.salary_amount ) > 0 ? `${ fmtMoney( props.member.salary_amount, props.member.currency ) } ${ props.member.salary_period === 'monthly' || ! props.member.salary_period ? 'per month' : 'per ' + props.member.salary_period }` : props.member && props.member.kind === 'contractor' ? 'Paid per project' : 'No salary set' ) );
</script>

<template>
	<el-col v-if="member && 'salary_amount' in member" :xs="24" :md="12">
		<el-card shadow="never" class="bd-widget bd-mb">
			<template #header><div class="bd-widget__head"><span>Pay</span><a href="#" @click.prevent="goTab( 'pay' )">Earnings and payouts</a></div></template>
			<p class="bd-strong">{{ salary }}</p>
			<p v-if="member.bank_name" class="bd-muted bd-small">{{ [ member.bank_name, member.account_name, member.account_number ].filter( Boolean ).join( ' · ' ) }}</p>
		</el-card>
	</el-col>
</template>
