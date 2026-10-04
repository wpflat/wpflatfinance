<script setup>
import TeamPay from '../components/TeamPay.vue';

defineProps( { member: { type: Object, default: null }, tick: { type: Number, default: 0 }, refresh: { type: Function, default: () => {} } } );
</script>

<template>
	<TeamPay v-if="member" :member="member" :reload-key="tick" @changed="refresh" />
</template>
