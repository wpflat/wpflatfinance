<script setup>
import { Search } from '@element-plus/icons-vue';

defineProps( {
	modelValue: { type: String, default: '' },
	chips: { type: Array, default: () => [] },
	search: { type: String, default: '' },
	searchPlaceholder: { type: String, default: 'Search…' },
} );
const emit = defineEmits( [ 'update:modelValue', 'update:search' ] );
</script>

<template>
	<div class="bd-filterbar">
		<el-radio-group v-if="chips.length" :model-value="modelValue" class="bd-seg-sm" aria-label="Filter" @update:model-value="emit( 'update:modelValue', $event )">
			<el-radio-button v-for="c in chips" :key="c.id" :value="c.id">{{ c.label }}<span v-if="c.count !== undefined" class="bd-chipcount">{{ c.count }}</span></el-radio-button>
		</el-radio-group>
		<el-input :model-value="search" :prefix-icon="Search" :placeholder="searchPlaceholder" clearable class="bd-toolbar__search" @update:model-value="emit( 'update:search', $event )" />
		<slot />
		<div class="bd-toolbar__end"><slot name="end" /></div>
	</div>
</template>
