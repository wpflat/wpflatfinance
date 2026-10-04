<script setup>
import { computed } from 'vue';
import { ElMessage, ElMessageBox, RecordDrawer, api, today } from '../core';
import { payItemFields } from '../resources';

/** "Add payment": pick the person, the amount and what it is for — optionally the task it belongs to. */
const props = defineProps( { modelValue: Boolean, member: { type: Object, default: null } } );
const emit = defineEmits( [ 'update:modelValue', 'saved' ] );

const fields = computed( () => payItemFields( { member: props.member } ) );
const record = computed( () => ( { kind: 'payment', earned_on: today(), ...( props.member ? { team_member_id: props.member.id, currency: props.member.currency } : {} ) } ) );

const KEYS = [ 'team_member_id', 'kind', 'description', 'amount', 'currency', 'earned_on', 'task_id', 'project_id', 'phase_id' ];
const save = async ( values ) => {
	const payload = {};
	KEYS.forEach( ( k ) => {
		if ( k in values && values[ k ] !== '' && values[ k ] !== null ) {
			payload[ k ] = values[ k ];
		}
	} );
	if ( props.member ) {
		payload.team_member_id = props.member.id;
	}
	try {
		return await api.create( 'pay-items', payload );
	} catch ( e ) {
		if ( e.body && e.body.code === 'task_already_paid' ) {
			await ElMessageBox.confirm( e.message, 'Add another payment?', { type: 'warning', confirmButtonText: 'Add another' } ); // cancel rejects → the drawer stays open
			return api.create( 'pay-items', { ...payload, confirm_duplicate: 1 } );
		}
		throw e;
	}
};
const saved = ( r ) => {
	ElMessage.success( 'Payment added — it shows under what is owed' );
	emit( 'saved', r );
};
</script>

<template>
	<RecordDrawer :model-value="modelValue" title="Add payment" :fields="fields" :record="record" is-new :save="save" size="520px" @update:model-value="emit( 'update:modelValue', $event )" @saved="saved" />
</template>
