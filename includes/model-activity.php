<?php
/**
 * Activity — Salesforce's Task and Event, merged.
 *
 * Salesforce splits them, but both carry the same Who/What pair and PCM has no
 * use for the distinction, so activity_type carries it instead. what_type says
 * which table what_id points at, the job WhatId's key prefix does there.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_activities() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_fields = array_merge(
			array(
				'subject'       => array( 'type' => 'text', 'sf' => 'Subject' ),
				'activity_type' => array( 'type' => 'text', 'sf' => 'Type' ),
				'status'        => array( 'type' => 'text', 'sf' => 'Status' ),
				'priority'      => array( 'type' => 'text', 'sf' => 'Priority' ),
				'activity_date' => array( 'type' => 'datetime', 'sf' => 'ActivityDate' ),
				'due_date'      => array( 'type' => 'date', 'sf' => 'ReminderDateTime' ),
				'who_id'        => array( 'type' => 'id',   'sf' => 'WhoId' ),
				'what_id'       => array( 'type' => 'id',   'sf' => 'WhatId' ),
				'what_type'     => array( 'type' => 'text' ),
				'description'   => array( 'type' => 'longtext', 'sf' => 'Description' ),
				'is_completed'  => array( 'type' => 'bool', 'sf' => 'IsClosed' ),
			),
			PCM_CRM_Model::system_fields()
		);

		$pcm_model = new PCM_CRM_Model(
			'activity',
			PCM_CRM_Schema::activities(),
			$pcm_fields,
			array( 'subject', 'description', 'activity_type' )
		);
	}

	return $pcm_model;
}

/**
 * Keep is_completed and the Completed status agreeing with each other.
 *
 * Either can be set from the UI — a picklist in the detail drawer, a checkbox
 * in a list row — and an activity that reads Completed but is not complete
 * would make the overdue count wrong.
 *
 * The picklist wins when both arrive, because it is the more specific
 * statement: someone who moves an activity to In Progress means that, whatever
 * the checkbox beside it happened to be. When only the checkbox arrives, it
 * drives the status — and un-ticking it only rewrites a status that actually
 * said Completed, so an activity sitting in Waiting is left where it is.
 */
function pcm_crm_sync_activity_status( $pcm_row, $pcm_object, $pcm_id = 0 ) {
	if ( 'activity' !== $pcm_object ) {
		return $pcm_row;
	}

	$pcm_has_status = isset( $pcm_row['status'] );
	$pcm_has_flag   = isset( $pcm_row['is_completed'] );

	if ( $pcm_has_status ) {
		$pcm_row['is_completed'] = ( 'Completed' === $pcm_row['status'] ) ? 1 : 0;

		return $pcm_row;
	}

	if ( ! $pcm_has_flag ) {
		return $pcm_row;
	}

	if ( $pcm_row['is_completed'] ) {
		$pcm_row['status'] = 'Completed';

		return $pcm_row;
	}

	$pcm_existing = $pcm_id ? pcm_crm_activities()->get( $pcm_id ) : null;

	if ( $pcm_existing && 'Completed' === $pcm_existing['status'] ) {
		$pcm_row['status'] = 'In Progress';
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_sync_activity_status', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_sync_activity_status', 10, 3 );

/**
 * Log an activity against a contact and a parent record.
 *
 * The one entry point used by the form intake and the UI's quick-log control,
 * so a logged call and a web inquiry are shaped identically.
 */
function pcm_crm_log_activity( array $pcm_args ) {
	$pcm_args = wp_parse_args( $pcm_args, array(
		'subject'       => '',
		'activity_type' => 'Task',
		'status'        => 'Completed',
		'priority'      => 'Normal',
		'activity_date' => current_time( 'mysql' ),
		'who_id'        => 0,
		'what_id'       => 0,
		'what_type'     => '',
		'description'   => '',
	) );

	$pcm_id = pcm_crm_activities()->insert( $pcm_args );

	return is_wp_error( $pcm_id ) ? 0 : (int) $pcm_id;
}

/**
 * Everything logged against one record, newest first.
 *
 * A Contact's timeline includes activities hung off its Account only when
 * asked, since an Account related list wants its own rows, not its people's.
 */
function pcm_crm_activities_for( $pcm_what_type, $pcm_id, $pcm_limit = 50 ) {
	$pcm_filters = ( 'contact' === $pcm_what_type )
		? array( 'who_id' => absint( $pcm_id ) )
		: array( 'what_type' => $pcm_what_type, 'what_id' => absint( $pcm_id ) );

	return pcm_crm_activities()->find( array(
		'filters'  => $pcm_filters,
		'orderby'  => 'activity_date',
		'order'    => 'DESC',
		'per_page' => (int) $pcm_limit,
	) );
}
