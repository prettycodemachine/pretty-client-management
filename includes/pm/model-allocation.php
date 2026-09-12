<?php
/**
 * Resource allocation — planned hours, one row per person per project per week.
 *
 * Weekly rather than an arbitrary date range, because over-allocation is a
 * per-week question and the resourcing board buckets by week: weekly rows make
 * both a grouped SUM instead of interval arithmetic on every read. A date-range
 * form still exists and writes N rows through pcm_crm_pm_week_start().
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_allocations() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'allocation',
			pcm_crm_pm_allocations_table(),
			array_merge(
				array(
					'project_id'    => array( 'type' => 'id',    'sf' => 'PCM_Project_Id__c', 'label' => 'Project' ),
					'user_id'       => array( 'type' => 'id',    'sf' => 'PCM_User__c', 'label' => 'Person', 'options' => 'pcm_crm_owner_options' ),
					'week_start'    => array( 'type' => 'date',  'sf' => 'PCM_Week_Start__c', 'label' => 'Week Beginning' ),
					'planned_hours' => array( 'type' => 'hours', 'sf' => 'PCM_Planned_Hours__c', 'label' => 'Planned Hours', 'ui' => 'hours' ),
					'role'          => array( 'type' => 'text',  'sf' => 'PCM_Role__c', 'label' => 'Role', 'options' => 'pcm_crm_pm_roles' ),
					'description'   => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'Notes' ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'role', 'description' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * Snap a stored week to its Monday.
 *
 * Normalised on write, not on read, so the board's columns and the rows always
 * line up and no read has to agree with a writer that used a different rule. A
 * value landing between two stored weeks is the one way this table can be
 * quietly wrong.
 */
function pcm_crm_pm_normalise_week( $pcm_row, $pcm_object ) {
	if ( 'allocation' !== $pcm_object || empty( $pcm_row['week_start'] ) ) {
		return $pcm_row;
	}

	$pcm_row['week_start'] = pcm_crm_pm_week_start( $pcm_row['week_start'] );

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_normalise_week', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_pm_normalise_week', 10, 2 );
