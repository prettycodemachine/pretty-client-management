<?php
/**
 * Project task, and milestone.
 *
 * One table with an is_milestone flag: a milestone is a task with no duration.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_project_tasks() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'project_task',
			pcm_crm_pm_tasks_table(),
			array_merge(
				array(
					'project_id'       => array( 'type' => 'id',   'sf' => 'PCM_Project_Id__c', 'label' => 'Project', 'lookup' => 'projects' ),
					// Shipped, with no UI behind it. Sub-tasks are a real want
					// and a column is free; a tree editor is not.
					'parent_id'        => array( 'type' => 'id',   'sf' => 'PCM_Parent_Id__c', 'label' => 'Parent Task', 'internal' => true ),
					'name'             => array( 'type' => 'text', 'sf' => 'PCM_Name__c', 'label' => 'Task' ),
					'status'           => array( 'type' => 'text', 'sf' => 'PCM_Status__c', 'label' => 'Status', 'options' => 'pcm_crm_pm_task_statuses' ),
					'is_milestone'     => array( 'type' => 'bool', 'sf' => 'PCM_Is_Milestone__c', 'label' => 'Milestone' ),
					'assignee_user_id' => array( 'type' => 'id',   'sf' => 'PCM_Assignee__c', 'label' => 'Assigned To', 'options' => 'pcm_crm_owner_options' ),
					'start_date'       => array( 'type' => 'date', 'sf' => 'PCM_Start_Date__c', 'label' => 'Start Date' ),
					'due_date'         => array( 'type' => 'date', 'sf' => 'PCM_Due_Date__c', 'label' => 'Due Date' ),
					'completed_date'   => array( 'type' => 'date', 'sf' => 'PCM_Completed_Date__c', 'label' => 'Completed Date', 'readonly' => true ),
					'estimated_hours'  => array( 'type' => 'hours', 'sf' => 'PCM_Estimated_Hours__c', 'label' => 'Estimated Hours' ),
					'sort_order'       => array( 'type' => 'int',  'label' => 'Order', 'internal' => true ),
					'description'      => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'Notes' ),
				),
				PCM_CRM_Model::system_fields()
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
 * Stamp the completion date from the status, and clear it again.
 *
 * Derived rather than asked for, because a task marked Done with no completion
 * date is invisible to every "what shipped last month" question, and nobody
 * fills in a date they were not prompted for.
 */
function pcm_crm_pm_apply_task_status( $pcm_row, $pcm_object, $pcm_id = 0 ) {
	if ( 'project_task' !== $pcm_object || ! isset( $pcm_row['status'] ) ) {
		return $pcm_row;
	}

	if ( 'Done' === $pcm_row['status'] ) {
		$pcm_existing = $pcm_id ? pcm_crm_project_tasks()->get( $pcm_id ) : null;

		// Only on the way in, so re-saving a finished task does not keep moving
		// the day it finished.
		if ( ! $pcm_existing || 'Done' !== $pcm_existing['status'] ) {
			$pcm_row['completed_date'] = current_time( 'Y-m-d' );
		}
	} else {
		$pcm_row['completed_date'] = null;
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_apply_task_status', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_pm_apply_task_status', 10, 3 );
