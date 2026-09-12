<?php
/**
 * Time entry.
 *
 * hours is the 'hours' field type rather than a decimal, so "1:30" arrives as
 * 1.5 instead of 130 — see includes/duration.php.
 *
 * bill_rate and cost_rate are snapshotted onto the row when it is written, not
 * looked up at report time, so changing a rate today cannot rewrite what last
 * quarter was worth. rate_source records which rung of the ladder answered,
 * which is what lets an unrated entry be named rather than silently counted.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_time_entries() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'time_entry',
			pcm_crm_pm_time_table(),
			array_merge(
				array(
					'project_id'         => array( 'type' => 'id',    'sf' => 'PCM_Project_Id__c', 'label' => 'Project' ),
					'task_id'            => array( 'type' => 'id',    'sf' => 'PCM_Task_Id__c', 'label' => 'Task' ),
					// Resolved when the entry is written, so burn-down is an
					// indexed sum on one column rather than a date-range scan.
					'retainer_period_id' => array( 'type' => 'id',    'sf' => 'PCM_Period_Id__c', 'label' => 'Retainer Period', 'readonly' => true ),
					'user_id'            => array( 'type' => 'id',    'sf' => 'PCM_User__c', 'label' => 'Person', 'options' => 'pcm_crm_owner_options' ),
					'entry_date'         => array( 'type' => 'date',  'sf' => 'PCM_Date__c', 'label' => 'Date' ),
					'hours'              => array( 'type' => 'hours', 'sf' => 'PCM_Hours__c', 'label' => 'Hours', 'ui' => 'hours' ),
					'is_billable'        => array( 'type' => 'bool',  'sf' => 'PCM_Billable__c', 'label' => 'Billable' ),
					'bill_rate'          => array( 'type' => 'decimal', 'sf' => 'PCM_Bill_Rate__c', 'label' => 'Bill Rate', 'ui' => 'currency' ),
					'cost_rate'          => array( 'type' => 'decimal', 'sf' => 'PCM_Cost_Rate__c', 'label' => 'Cost Rate', 'ui' => 'currency' ),
					'rate_source'        => array( 'type' => 'text',  'sf' => 'PCM_Rate_Source__c', 'label' => 'Rate From', 'readonly' => true ),
					'invoice_ref'        => array( 'type' => 'text',  'sf' => 'PCM_Invoice_Ref__c', 'label' => 'Invoice Reference' ),
					'description'        => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'What you did' ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'description', 'invoice_ref' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * Fill in the two things a time entry should not have to be told.
 *
 * The person is whoever is logging unless someone said otherwise, and the date
 * is today. Both are the overwhelmingly common case, and an entry that lands on
 * the wrong day because a field was blank is worse than one that has to be
 * corrected.
 */
function pcm_crm_pm_default_time_entry( $pcm_row, $pcm_object ) {
	if ( 'time_entry' !== $pcm_object ) {
		return $pcm_row;
	}

	if ( empty( $pcm_row['user_id'] ) ) {
		$pcm_row['user_id'] = get_current_user_id();
	}

	if ( empty( $pcm_row['entry_date'] ) ) {
		$pcm_row['entry_date'] = current_time( 'Y-m-d' );
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_default_time_entry', 10, 2 );

/**
 * Refuse an entry that cannot be counted.
 */
function pcm_crm_pm_validate_time_entry( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( 'time_entry' !== $pcm_object || is_wp_error( $pcm_error ) ) {
		return $pcm_error;
	}

	$pcm_existing = $pcm_id ? pcm_crm_time_entries()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( is_array( $pcm_existing ) ? $pcm_existing : array(), $pcm_row );

	if ( empty( $pcm_merged['project_id'] ) ) {
		return new WP_Error( 'pcm_crm_pm_no_project', __( 'Time has to be logged against a project.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	// Null rather than zero is what the parser returns for something it could not
	// read, so this catches "1h3O" with a letter O in it as well as a blank.
	if ( ! isset( $pcm_merged['hours'] ) || null === $pcm_merged['hours'] ) {
		return new WP_Error(
			'pcm_crm_pm_no_hours',
			__( 'How long did it take? Decimal hours (1.5) or h:mm (1:30) both work.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	if ( (float) $pcm_merged['hours'] <= 0 ) {
		return new WP_Error( 'pcm_crm_pm_no_hours', __( 'An entry of no time is not worth recording.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	// Not a hard limit anywhere else, but a day longer than a day is always a
	// typo — usually a date typed into the hours box.
	if ( (float) $pcm_merged['hours'] > 24 ) {
		return new WP_Error(
			'pcm_crm_pm_too_many_hours',
			__( 'That is more than a day in one entry. Split it across dates, or check the value.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_pm_validate_time_entry', 10, 4 );
