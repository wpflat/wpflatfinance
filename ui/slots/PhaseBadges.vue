<script setup>
import { computed } from 'vue';
import { defaultCurrency, fmtMoney } from '../util';

const props = defineProps( { phase: { type: Object, required: true }, project: { type: Object, default: null } } );
const cur = computed( () => ( props.project && props.project.currency ) || defaultCurrency() );
const show = computed( () => 'team_budget' in props.phase && Number( props.phase.team_budget ) > 0 );
</script>

<template>
	<span v-if="show" :class="[ 'bd-small', phase.over_budget ? 'bd-late' : 'bd-muted' ]">{{ fmtMoney( phase.allocated, cur ) }} of {{ fmtMoney( phase.team_budget, cur ) }} shared</span>
</template>
