<script setup>
import { navigate } from '../core';

const props = defineProps( { project: { type: Object, default: null } } );
const go = () => navigate( `/billing/invoices/new?client=${ props.project.client_id }` );
</script>

<template>
	<el-button v-if="project && project.client_id" @click="go">New {{ $t( 'invoice' ).toLowerCase() }}</el-button>
</template>
