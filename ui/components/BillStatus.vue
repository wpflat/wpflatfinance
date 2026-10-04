<script setup>
import { computed } from 'vue';
import { Badge } from '../core';
import { BILL_STATUS } from '../util';

const props = defineProps( { status: { type: String, default: '' } } );
const def = computed( () => BILL_STATUS[ props.status ] || { label: props.status, tone: 'neutral' } );
</script>

<template>
	<Badge :tone="def.tone" :label="def.label" />
</template>
