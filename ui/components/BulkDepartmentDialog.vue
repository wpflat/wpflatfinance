<script setup>
import { computed, ref, watch } from 'vue';
import { ElMessage, RemoteSelect, api, dept, errorMessage } from '../core';

/** Put the selected records in one department, or take them out of any. items: [{ resource, id }]. */
const props = defineProps( { modelValue: Boolean, items: { type: Array, default: () => [] }, noun: { type: String, default: 'selected' }, note: { type: String, default: '' }, multi: Boolean } ); // multi: the records can belong to several departments (clients)
const emit = defineEmits( [ 'update:modelValue', 'done' ] );

const mode = ref( 'set' );
const department = ref( 0 );
const saving = ref( false );
const error = ref( '' );

watch( () => props.modelValue, ( open ) => {
	if ( open ) {
		mode.value = props.multi ? 'add' : 'set';
		department.value = 0;
		error.value = '';
	}
} );
const count = computed( () => props.items.length );
const canApply = computed( () => count.value > 0 && ( mode.value === 'clear' || department.value > 0 ) );
const MODES = computed( () => ( props.multi
	? [ { v: 'add', l: 'Add to a ' + dept().lower }, { v: 'remove', l: 'Remove from a ' + dept().lower }, { v: 'set', l: 'Only this one' }, { v: 'clear', l: 'Remove from all' } ]
	: [ { v: 'set', l: 'Move to a ' + dept().lower }, { v: 'clear', l: 'Take out of any' } ] ) );
const HINT = { add: 'They keep the departments they have and join this one.', remove: 'They leave this department and keep any others.', set: 'They belong to this department only.', clear: 'They will no longer count under any department.' };

const apply = async () => {
	saving.value = true;
	error.value = '';
	try {
		const r = await api.post( 'bulk/department', { department_id: mode.value === 'clear' ? 0 : department.value, mode: mode.value, items: props.items } );
		if ( r.failed && r.failed.length ) {
			ElMessage.warning( `${ r.updated } updated, ${ r.failed.length } could not be changed: ${ r.failed[ 0 ].error }` );
		} else {
			ElMessage.success( `${ r.updated } updated` );
		}
		emit( 'update:modelValue', false );
		emit( 'done', r );
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		saving.value = false;
	}
};
</script>

<template>
	<el-dialog :model-value="modelValue" :title="'Set ' + dept().lower" width="460px" append-to-body destroy-on-close @update:model-value="emit( 'update:modelValue', $event )">
		<p class="bd-lead">{{ count }} {{ noun }} {{ count === 1 ? 'item' : 'items' }}</p>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-radio-group v-model="mode" class="bd-seg-sm bd-mb" aria-label="What to do">
			<el-radio-button v-for="m in MODES" :key="m.v" :value="m.v">{{ m.l }}</el-radio-button>
		</el-radio-group>
		<RemoteSelect v-if="mode !== 'clear'" v-model="department" resource="departments" :label="( b ) => b.name" :params="{ status: 'active' }" :placeholder="'Choose a ' + dept().lower" />
		<p v-if="multi" class="bd-muted bd-mt">{{ HINT[ mode ] }}</p>
		<p v-else-if="mode === 'clear'" class="bd-muted">{{ HINT.clear }}</p>
		<p v-if="note" class="bd-help">{{ note }}</p>
		<template #footer>
			<el-button @click="emit( 'update:modelValue', false )">Cancel</el-button>
			<el-button type="primary" :loading="saving" :disabled="!canApply" @click="apply">Apply</el-button>
		</template>
	</el-dialog>
</template>
