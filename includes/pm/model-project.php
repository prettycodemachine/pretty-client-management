<?php
/**
 * Project.
 *
 * Salesforce has no standard Project, so the sf names are all PCM_*__c and the
 * CSV export is a local extract rather than a migration path — the Data Export
 * tab says so beside it, because a header row that looks like a mapping invites
 * being used as one.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_projects() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_fields = array_merge(
			array(
				'account_id'            => array( 'type' => 'id',   'sf' => 'PCM_Account_Id__c', 'label' => 'Account' ),
				// Optional on purpose: internal work has no account and no
				// opportunity behind it, and refusing to record it would just
				// move it somewhere this cannot see.
				'opportunity_id'        => array( 'type' => 'id',   'sf' => 'PCM_Opportunity_Id__c', 'label' => 'Opportunity' ),
				'name'                  => array( 'type' => 'text', 'sf' => 'PCM_Name__c', 'label' => 'Project Name' ),
				'project_code'          => array( 'type' => 'text', 'sf' => 'PCM_Code__c', 'label' => 'Project Code' ),
				'project_type'          => array( 'type' => 'text', 'sf' => 'PCM_Type__c', 'label' => 'Project Type', 'options' => 'pcm_crm_pm_project_types' ),
				'stage_name'            => array( 'type' => 'text', 'sf' => 'PCM_Stage__c', 'label' => 'Stage', 'options' => 'pcm_crm_pm_all_stage_names' ),
				// Hand-settable with a computed default. Someone overrides it
				// knowingly — "amber, but the client knows" — so the numbers
				// cannot own this column. The computed verdict travels beside it
				// as _health_computed, which is what lets the record say "you
				// have this at Green; the numbers say Amber".
				'health'                => array( 'type' => 'text', 'sf' => 'PCM_Health__c', 'label' => 'Health', 'options' => 'pcm_crm_pm_health_options' ),
				'health_note'           => array( 'type' => 'text', 'sf' => 'PCM_Health_Note__c', 'label' => 'Health Note' ),
				'start_date'            => array( 'type' => 'date', 'sf' => 'PCM_Start_Date__c', 'label' => 'Start Date' ),
				'end_date'              => array( 'type' => 'date', 'sf' => 'PCM_End_Date__c', 'label' => 'Planned End Date' ),
				'actual_end_date'       => array( 'type' => 'date', 'sf' => 'PCM_Actual_End_Date__c', 'label' => 'Actual End Date' ),
				'budget_amount'         => array( 'type' => 'decimal', 'sf' => 'PCM_Budget__c', 'label' => 'Budget', 'ui' => 'currency' ),
				'budget_hours'          => array( 'type' => 'hours', 'sf' => 'PCM_Budget_Hours__c', 'label' => 'Budget Hours' ),
				'retainer_hours'        => array( 'type' => 'hours', 'sf' => 'PCM_Retainer_Hours__c', 'label' => 'Hours per Period' ),
				'retainer_period'       => array( 'type' => 'text', 'sf' => 'PCM_Retainer_Period__c', 'label' => 'Retainer Period', 'options' => 'pcm_crm_pm_period_options' ),
				'retainer_rollover'     => array( 'type' => 'bool', 'sf' => 'PCM_Rollover__c', 'label' => 'Unused Hours Roll Over' ),
				'retainer_rollover_cap' => array( 'type' => 'hours', 'sf' => 'PCM_Rollover_Cap__c', 'label' => 'Rollover Cap' ),
				'retainer_start_date'   => array( 'type' => 'date', 'sf' => 'PCM_Retainer_Start__c', 'label' => 'Retainer Start Date' ),
				'default_bill_rate'     => array( 'type' => 'decimal', 'sf' => 'PCM_Bill_Rate__c', 'label' => 'Default Bill Rate', 'ui' => 'currency' ),
				'default_cost_rate'     => array( 'type' => 'decimal', 'sf' => 'PCM_Cost_Rate__c', 'label' => 'Default Cost Rate', 'ui' => 'currency' ),
				// Maintained from the stage struct, never posted.
				'stage_entered_date'    => array( 'type' => 'datetime', 'sf' => 'PCM_Stage_Entered__c', 'label' => 'Stage Entered Date', 'readonly' => true ),
				'closed_date'           => array( 'type' => 'datetime', 'sf' => 'PCM_Closed_Date__c', 'label' => 'Closed Date', 'readonly' => true ),
				'is_closed'             => array( 'type' => 'bool', 'sf' => 'PCM_Is_Closed__c', 'label' => 'Closed', 'readonly' => true ),
				'description'           => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'Notes' ),
			),
			PCM_CRM_Model::system_fields()
		);

		$pcm_model = new PCM_CRM_Model(
			'project',
			pcm_crm_pm_projects_table(),
			$pcm_fields,
			array( 'name', 'project_code', 'description' ),
			array(
				'account'     => array( 'column' => 'account_id', 'model' => 'pcm_crm_accounts', 'label' => 'Account' ),
				'opportunity' => array( 'column' => 'opportunity_id', 'model' => 'pcm_crm_opportunities', 'label' => 'Opportunity' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * The retainer period choices, as the field map wants them.
 *
 * A wrapper because the picklist is a slug => label map and a text field's
 * options want the values a person picks between.
 */
function pcm_crm_pm_period_options() {
	return array_keys( pcm_crm_pm_periods() );
}

/**
 * Keep the derived stage facts in step with the stage.
 *
 * Mirrors pcm_crm_apply_stage() for opportunities: is_closed, closed_date and
 * stage_entered_date are all consequences of the stage rather than things anyone
 * should have to set, and a record whose stage says Ended while is_closed says 0
 * reads as a bug in every report downstream.
 */
function pcm_crm_pm_apply_stage( $pcm_row, $pcm_object, $pcm_id = 0 ) {
	if ( 'project' !== $pcm_object || ! isset( $pcm_row['stage_name'] ) ) {
		return $pcm_row;
	}

	// The type decides which stage set applies, and an update may not have
	// posted it — so fall back to the stored row rather than to the first set.
	$pcm_type = isset( $pcm_row['project_type'] ) ? $pcm_row['project_type'] : '';

	if ( '' === $pcm_type && $pcm_id ) {
		$pcm_existing = pcm_crm_projects()->get( $pcm_id );
		$pcm_type     = $pcm_existing ? $pcm_existing['project_type'] : '';
	}

	$pcm_stage = pcm_crm_pm_stage( $pcm_type, $pcm_row['stage_name'] );

	if ( ! $pcm_stage ) {
		return $pcm_row;
	}

	$pcm_row['is_closed'] = ! empty( $pcm_stage['is_closed'] ) ? 1 : 0;

	$pcm_previous = $pcm_id ? pcm_crm_projects()->get( $pcm_id ) : null;
	$pcm_moved    = ! $pcm_previous || $pcm_previous['stage_name'] !== $pcm_row['stage_name'];

	if ( $pcm_moved ) {
		$pcm_row['stage_entered_date'] = current_time( 'mysql' );
	}

	if ( $pcm_row['is_closed'] ) {
		// Only stamped on the way in, so re-saving a closed project does not
		// keep moving the date it closed on.
		if ( ! $pcm_previous || ! $pcm_previous['is_closed'] ) {
			$pcm_row['closed_date'] = current_time( 'mysql' );
		}
	} else {
		$pcm_row['closed_date'] = '0000-00-00 00:00:00';
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_apply_stage', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_pm_apply_stage', 10, 3 );

/**
 * Refuse a project that is not coherent.
 *
 * Validated against the merged record rather than the posted one, the way the
 * CRM's own validators are, so an edit that omits a field does not fail on it.
 */
function pcm_crm_pm_validate_project( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( 'project' !== $pcm_object || is_wp_error( $pcm_error ) ) {
		return $pcm_error;
	}

	$pcm_existing = $pcm_id ? pcm_crm_projects()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( is_array( $pcm_existing ) ? $pcm_existing : array(), $pcm_row );

	if ( '' === trim( (string) $pcm_merged['name'] ) ) {
		return new WP_Error( 'pcm_crm_name_required', __( 'A project needs a name.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	$pcm_type = (string) $pcm_merged['project_type'];

	if ( '' !== $pcm_type && ! in_array( $pcm_type, pcm_crm_pm_project_types(), true ) ) {
		return new WP_Error(
			'pcm_crm_pm_unknown_type',
			/* translators: %s: the project type that was submitted */
			sprintf( __( '“%s” is not one of the project types.', 'pcm-crm' ), $pcm_type ),
			array( 'status' => 400 )
		);
	}

	// A stage belonging to another type is the mistake worth catching: it saves
	// cleanly, then reads as closed-or-not according to a set it was never in.
	$pcm_stage = (string) $pcm_merged['stage_name'];

	if ( '' !== $pcm_stage && '' !== $pcm_type && ! pcm_crm_pm_stage( $pcm_type, $pcm_stage ) ) {
		return new WP_Error(
			'pcm_crm_pm_wrong_stage',
			/* translators: 1: stage name, 2: project type */
			sprintf( __( '“%1$s” is not a stage a %2$s goes through.', 'pcm-crm' ), $pcm_stage, $pcm_type ),
			array( 'status' => 400 )
		);
	}

	if ( $pcm_merged['start_date'] && $pcm_merged['end_date'] && $pcm_merged['end_date'] < $pcm_merged['start_date'] ) {
		return new WP_Error( 'pcm_crm_pm_bad_window', __( 'The end date falls before the start date.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_pm_validate_project', 10, 4 );
