<?php
/**
 * Opportunity — Salesforce's Opportunity.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_opportunities() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_fields = array_merge(
			array(
				'account_id'         => array( 'type' => 'id',   'sf' => 'PCM_Account_Id__c', 'label' => 'Account' ),
				'primary_contact_id' => array( 'type' => 'id',   'sf' => 'PCM_Contact_Id__c', 'label' => 'Primary Contact' ),
				'name'               => array( 'type' => 'text', 'sf' => 'Name', 'label' => 'Opportunity Name' ),
				'stage_name'         => array( 'type' => 'text', 'sf' => 'StageName', 'label' => 'Stage', 'options' => 'pcm_crm_stage_names' ),
				'amount'             => array( 'type' => 'decimal', 'sf' => 'Amount', 'label' => 'Amount' ),
				'probability'        => array( 'type' => 'int',  'sf' => 'Probability', 'label' => 'Probability' ),
				'close_date'         => array( 'type' => 'date', 'sf' => 'CloseDate', 'label' => 'Close Date' ),
				'type'               => array( 'type' => 'text', 'sf' => 'Type', 'label' => 'Type', 'options' => 'pcm_crm_opportunity_types' ),
				'lead_source'        => array( 'type' => 'text', 'sf' => 'LeadSource', 'label' => 'Lead Source', 'options' => 'pcm_crm_lead_sources' ),
				'next_step'          => array( 'type' => 'text', 'sf' => 'NextStep', 'label' => 'Next Step' ),
				'service_interest'   => array( 'type' => 'text', 'sf' => 'Service_Interest__c', 'label' => 'Interested In', 'options' => 'pcm_crm_interest_labels' ),
				'closed_lost_reason' => array( 'type' => 'text', 'sf' => 'Closed_Lost_Reason__c', 'label' => 'Closed Lost Reason' ),
				// Maintained by the history recorder, never posted: the moment
				// the current stage was entered, and the moment the deal
				// actually closed — as against close_date, which is a forecast.
				'stage_entered_date' => array( 'type' => 'datetime', 'sf' => 'Stage_Entered_Date__c', 'label' => 'Stage Entered Date', 'readonly' => true ),
				'closed_date'        => array( 'type' => 'datetime', 'sf' => 'Closed_Date__c', 'label' => 'Closed Date', 'readonly' => true ),
				'forecast_category'  => array( 'type' => 'text', 'sf' => 'ForecastCategoryName', 'label' => 'Forecast Category' ),
				'is_closed'          => array( 'type' => 'bool', 'sf' => 'IsClosed', 'label' => 'Closed', 'readonly' => true ),
				'is_won'             => array( 'type' => 'bool', 'sf' => 'IsWon', 'label' => 'Won', 'readonly' => true ),
				'description'        => array( 'type' => 'longtext', 'sf' => 'Description', 'label' => 'Notes' ),
			),
			PCM_CRM_Model::system_fields()
		);

		$pcm_model = new PCM_CRM_Model(
			'opportunity',
			PCM_CRM_Schema::opportunities(),
			$pcm_fields,
			array( 'name', 'next_step', 'description' ),
			array(
				'account' => array( 'column' => 'account_id', 'model' => 'pcm_crm_accounts', 'label' => 'Account' ),
				'contact' => array( 'column' => 'primary_contact_id', 'model' => 'pcm_crm_contacts', 'label' => 'Primary Contact' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * Derive the flags a stage implies.
 *
 * is_closed, is_won, probability and forecast_category are all functions of
 * the stage in Salesforce, and are read-only to the sanitiser here for the
 * same reason: the pipeline board moves a card by setting one field, and every
 * consumer of "is this deal still live" has to agree with it.
 *
 * Probability is only overwritten when the stage actually changes, so a
 * hand-tuned 40% on a Proposal survives an unrelated edit.
 */
function pcm_crm_apply_stage( $pcm_row, $pcm_object, $pcm_id = 0 ) {
	if ( 'opportunity' !== $pcm_object || ! isset( $pcm_row['stage_name'] ) ) {
		return $pcm_row;
	}

	$pcm_stage = pcm_crm_stage( $pcm_row['stage_name'] );

	if ( ! $pcm_stage ) {
		return $pcm_row;
	}

	$pcm_row['is_closed']         = (int) $pcm_stage['is_closed'];
	$pcm_row['is_won']            = (int) $pcm_stage['is_won'];
	$pcm_row['forecast_category'] = $pcm_stage['forecast_category'];

	$pcm_changed = true;

	if ( $pcm_id ) {
		$pcm_existing = pcm_crm_opportunities()->get( $pcm_id );
		$pcm_changed  = ! $pcm_existing || $pcm_existing['stage_name'] !== $pcm_row['stage_name'];
	}

	if ( $pcm_changed ) {
		$pcm_row['probability'] = (int) $pcm_stage['probability'];
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_apply_stage', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_apply_stage', 10, 3 );

/**
 * Is this stage a loss?
 *
 * Derived from the stage's own flags rather than matched on the name, so a
 * renamed or added losing stage still counts as one.
 */
function pcm_crm_stage_is_lost( $pcm_stage_name ) {
	$pcm_stage = pcm_crm_stage( $pcm_stage_name );

	return $pcm_stage && ! empty( $pcm_stage['is_closed'] ) && empty( $pcm_stage['is_won'] );
}

/**
 * A deal cannot be lost without saying why.
 *
 * The one thing a pipeline is for is learning from what did not close, and a
 * column of losses with no reasons answers nothing. Enforced on the server so
 * the rule holds for the pipeline board's drag as well as the form — the board
 * changes the stage with a bare PATCH and would otherwise slip past it.
 *
 * Validated against the merged record, so dragging a card whose reason is
 * already recorded does not demand it again.
 */
function pcm_crm_validate_opportunity( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( is_wp_error( $pcm_error ) || 'opportunity' !== $pcm_object ) {
		return $pcm_error;
	}

	$pcm_existing = $pcm_id ? pcm_crm_opportunities()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( (array) $pcm_existing, $pcm_row );

	if ( empty( $pcm_merged['stage_name'] ) || ! pcm_crm_stage_is_lost( $pcm_merged['stage_name'] ) ) {
		return $pcm_error;
	}

	if ( '' === trim( (string) ( isset( $pcm_merged['closed_lost_reason'] ) ? $pcm_merged['closed_lost_reason'] : '' ) ) ) {
		return new WP_Error(
			'pcm_crm_lost_reason_required',
			__( 'Give a reason before closing this as lost — a column of losses with no reasons teaches nothing.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_validate_opportunity', 10, 4 );

/**
 * Reopening a deal drops the reason it was lost for, so a live opportunity
 * cannot carry an explanation for a loss that was undone.
 */
function pcm_crm_clear_lost_reason( $pcm_row, $pcm_object ) {
	if ( 'opportunity' === $pcm_object && isset( $pcm_row['stage_name'] ) && ! pcm_crm_stage_is_lost( $pcm_row['stage_name'] ) ) {
		$pcm_row['closed_lost_reason'] = '';
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_clear_lost_reason', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_clear_lost_reason', 10, 2 );
