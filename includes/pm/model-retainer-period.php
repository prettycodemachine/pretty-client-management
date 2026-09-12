<?php
/**
 * Retainer period — one allotment of hours, and what carried into it.
 *
 * A table rather than periods computed from a start date and a monthly figure,
 * because rollover is path-dependent: this period's carry-in is last period's
 * closing balance. Recomputing from a rule would rewrite what last quarter's
 * burn-down said at the time, which is exactly what a client was shown.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_retainer_periods() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'retainer_period',
			pcm_crm_pm_periods_table(),
			array_merge(
				array(
					'project_id'         => array( 'type' => 'id',    'sf' => 'PCM_Project_Id__c', 'label' => 'Project' ),
					'period_start'       => array( 'type' => 'date',  'sf' => 'PCM_Period_Start__c', 'label' => 'Period Start' ),
					'period_end'         => array( 'type' => 'date',  'sf' => 'PCM_Period_End__c', 'label' => 'Period End' ),
					'allotted_hours'     => array( 'type' => 'hours', 'sf' => 'PCM_Allotted_Hours__c', 'label' => 'Allotted Hours', 'ui' => 'hours' ),
					// Written by the closer, from the previous period's balance.
					'carried_in_hours'   => array( 'type' => 'hours', 'sf' => 'PCM_Carried_In__c', 'label' => 'Carried In', 'readonly' => true ),
					'rollover_cap_hours' => array( 'type' => 'hours', 'sf' => 'PCM_Rollover_Cap__c', 'label' => 'Rollover Cap' ),
					'is_closed'          => array( 'type' => 'bool',  'sf' => 'PCM_Is_Closed__c', 'label' => 'Closed', 'readonly' => true ),
					'closed_date'        => array( 'type' => 'datetime', 'sf' => 'PCM_Closed_Date__c', 'label' => 'Closed On', 'readonly' => true ),
					'description'        => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'Notes' ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'description' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
			)
		);
	}

	return $pcm_model;
}
