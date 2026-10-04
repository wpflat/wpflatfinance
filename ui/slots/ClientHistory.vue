<script setup>
import ClientLedger from '../views/ClientLedger.vue';

defineProps( { clientId: { type: Number, required: true }, tick: { type: Number, default: 0 } } );
</script>

<template>
	<ClientLedger :client-id="clientId" :reload-key="tick" />
</template>
