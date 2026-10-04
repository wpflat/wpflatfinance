<script setup>
import { computed, ref } from 'vue';
import { ResourceTable } from '../core';
import { catalogConfig } from '../resources';

const type = ref( '' );
const TABS = [ { id: '', label: 'All' }, { id: 'service', label: 'Services' }, { id: 'product', label: 'Products' } ];
const config = computed( () => catalogConfig( { type: type.value } ) );
</script>

<template>
	<ResourceTable :config="config" :params="{ type }" embedded>
		<template #toolbar>
			<el-radio-group v-model="type" class="bd-seg-sm" aria-label="Catalog type">
				<el-radio-button v-for="t in TABS" :key="t.id" :value="t.id">{{ t.label }}</el-radio-button>
			</el-radio-group>
		</template>
	</ResourceTable>
</template>
