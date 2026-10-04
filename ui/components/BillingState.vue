<script setup>
import { computed } from 'vue';
import { Badge } from '../core';
import { BILL_STATE } from '../util';

const props = defineProps( { state: { type: String, default: '' } } );
const def = computed( () => BILL_STATE[ props.state ] || { label: props.state, tone: 'neutral' } );
</script>

<template>
	<Badge :tone="def.tone" :label="def.label" />
</template>
