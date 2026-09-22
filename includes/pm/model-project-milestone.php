<?php
/**
 * Project milestone.
 *
 * Its own table rather than project_tasks' is_milestone flag, which is what
 * "Next milestone" on the client portal's summary used to read — a milestone
 * is a commitment a client is told about, with its own lifecycle; a task is
 * internal work. Folding one into the other made every "what does the client
 * still owe" question a filtered task query, and let a milestone accumulate
 * task-only fields (an assignee, an estimate) that never meant anything on it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_project_milestones() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'project_milestone',
			pcm_crm_pm_milestones_table(),
			array_merge(
				array(
					'project_id'     => array( 'type' => 'id',   'sf' => 'PCM_Project_Id__c', 'label' => 'Project', 'lookup' => 'projects' ),
					'name'           => array( 'type' => 'text', 'sf' => 'PCM_Name__c', 'label' => 'Milestone' ),
					'status'         => array( 'type' => 'text', 'sf' => 'PCM_Status__c', 'label' => 'Status', 'options' => 'pcm_crm_pm_milestone_statuses' ),
					'due_date'       => array( 'type' => 'date', 'sf' => 'PCM_Due_Date__c', 'label' => 'Due Date' ),
					'completed_date' => array( 'type' => 'date', 'sf' => 'PCM_Completed_Date__c', 'label' => 'Completed Date', 'readonly' => true ),
					'description'    => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'Notes' ),
				),
				PCM_CRM_Model::system_fields(),
				pcm_crm_custom_field_map( 'project_milestones' )
			),
			array( 'name', 'description' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * The words a milestone's status is picked from — deliberately not
 * pcm_crm_pm_task_statuses(): "Blocked" is a task concept with no client-
 * facing meaning, and a milestone has no "In Progress" state worth a client
 * seeing either, only whether it has been hit yet.
 */
function pcm_crm_pm_milestone_statuses() {
	return apply_filters( 'pcm_crm_pm_milestone_statuses', array( 'Planned', 'Done' ) );
}

/**
 * Stamp the completion date from the status, and clear it again — same
 * reasoning as pcm_crm_pm_apply_task_status(): a milestone marked Done with
 * no completed date is invisible to any "what did we hit last month" answer.
 */
function pcm_crm_pm_apply_milestone( $pcm_row, $pcm_object, $pcm_id = 0 ) {
	if ( 'project_milestone' !== $pcm_object || ! isset( $pcm_row['status'] ) ) {
		return $pcm_row;
	}

	if ( 'Done' === $pcm_row['status'] ) {
		$pcm_existing = $pcm_id ? pcm_crm_project_milestones()->get( $pcm_id ) : null;

		if ( ! $pcm_existing || 'Done' !== $pcm_existing['status'] ) {
			$pcm_row['completed_date'] = current_time( 'Y-m-d' );
		}
	} else {
		$pcm_row['completed_date'] = null;
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_apply_milestone', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_pm_apply_milestone', 10, 3 );

/**
 * The next milestone not yet Done, for the project summary (both the CRM
 * admin's and the client portal's, via pcm_crm_portal_safe_summary()) — due
 * date first, so an undated milestone never jumps ahead of a dated one.
 */
function pcm_crm_pm_next_milestone( $pcm_project_id ) {
	$pcm_rows = pcm_crm_project_milestones()->find( array(
		'filters'  => array( 'project_id' => (int) $pcm_project_id, 'status' => 'Planned' ),
		'orderby'  => 'due_date',
		'order'    => 'ASC',
		'per_page' => 1,
	) );

	$pcm_rows = isset( $pcm_rows['items'] ) ? $pcm_rows['items'] : $pcm_rows;

	if ( ! $pcm_rows ) {
		return null;
	}

	$pcm_milestone = $pcm_rows[0];

	return array( 'id' => (int) $pcm_milestone['id'], 'name' => $pcm_milestone['name'], 'due_date' => $pcm_milestone['due_date'] );
}
