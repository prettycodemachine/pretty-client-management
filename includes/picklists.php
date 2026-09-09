<?php
/**
 * Picklist values.
 *
 * Defaults mirror Salesforce's shipped values so exported data lands in a real
 * org without a mapping step. Each list runs through a filter, and the stages
 * are also editable in CRM Settings, so how PCM actually sells can drift from
 * Salesforce's defaults without a code change.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Opportunity stages, in pipeline order.
 *
 * Each carries the probability Salesforce assigns it and whether it closes the
 * deal — dropping a card into a closed stage has to set is_closed/is_won, and
 * the weighted pipeline needs the probability, so both live with the stage
 * rather than being hardcoded at the two call sites.
 */
function pcm_crm_default_stages() {
	return array(
		array( 'name' => 'Qualification', 'probability' => 10, 'is_closed' => 0, 'is_won' => 0, 'forecast_category' => 'Pipeline' ),
		array( 'name' => 'Discovery',     'probability' => 25, 'is_closed' => 0, 'is_won' => 0, 'forecast_category' => 'Pipeline' ),
		array( 'name' => 'Proposal',      'probability' => 50, 'is_closed' => 0, 'is_won' => 0, 'forecast_category' => 'Best Case' ),
		array( 'name' => 'Negotiation',   'probability' => 75, 'is_closed' => 0, 'is_won' => 0, 'forecast_category' => 'Commit' ),
		array( 'name' => 'Closed Won',    'probability' => 100, 'is_closed' => 1, 'is_won' => 1, 'forecast_category' => 'Closed' ),
		array( 'name' => 'Closed Lost',   'probability' => 0,  'is_closed' => 1, 'is_won' => 0, 'forecast_category' => 'Omitted' ),
	);
}

function pcm_crm_stages() {
	$pcm_saved = get_option( 'pcm_crm_stages', array() );

	$pcm_stages = ( is_array( $pcm_saved ) && $pcm_saved ) ? $pcm_saved : pcm_crm_default_stages();

	return apply_filters( 'pcm_crm_stages', $pcm_stages );
}

/**
 * A single stage's definition, or null if the name is not one of ours.
 */
function pcm_crm_stage( $pcm_name ) {
	foreach ( pcm_crm_stages() as $pcm_stage ) {
		if ( $pcm_stage['name'] === $pcm_name ) {
			return $pcm_stage;
		}
	}

	return null;
}

function pcm_crm_stage_names() {
	return wp_list_pluck( pcm_crm_stages(), 'name' );
}

/**
 * Just the stages a deal can still move through — the pipeline's columns.
 */
function pcm_crm_open_stages() {
	$pcm_open = array();

	foreach ( pcm_crm_stages() as $pcm_stage ) {
		if ( empty( $pcm_stage['is_closed'] ) ) {
			$pcm_open[] = $pcm_stage;
		}
	}

	return $pcm_open;
}

function pcm_crm_account_types() {
	return apply_filters( 'pcm_crm_account_types', array(
		'Prospect', 'Customer', 'Partner', 'Nonprofit', 'Former Customer', 'Other',
	) );
}

function pcm_crm_industries() {
	return apply_filters( 'pcm_crm_industries', array(
		'Nonprofit', 'Education', 'Healthcare', 'Government', 'Technology',
		'Professional Services', 'Retail', 'Manufacturing', 'Finance', 'Other',
	) );
}

function pcm_crm_opportunity_types() {
	return apply_filters( 'pcm_crm_opportunity_types', array(
		'New Business', 'Existing Business', 'Renewal',
	) );
}

/**
 * Salesforce's LeadSource, plus the values PCM actually sees.
 */
function pcm_crm_lead_sources() {
	return apply_filters( 'pcm_crm_lead_sources', array(
		'Web', 'Referral', 'Partner', 'Event', 'Outbound', 'Other',
	) );
}

function pcm_crm_activity_types() {
	return apply_filters( 'pcm_crm_activity_types', array(
		'Call', 'Email', 'Meeting', 'Task', 'Note', 'Web Form',
	) );
}

function pcm_crm_activity_statuses() {
	return apply_filters( 'pcm_crm_activity_statuses', array(
		'Not Started', 'In Progress', 'Waiting', 'Completed', 'Deferred',
	) );
}

function pcm_crm_priorities() {
	return apply_filters( 'pcm_crm_priorities', array( 'Low', 'Normal', 'High' ) );
}

/**
 * The contact form's interest options.
 *
 * The offerings live in the theme (inc/offerings.php), where they also drive
 * the services cards — they are site content, not CRM data. The plugin reads
 * them when the theme is active and falls back to its own list when it is not,
 * so the form never renders an empty dropdown.
 */
function pcm_crm_interest_options() {
	if ( function_exists( 'pcm_interest_options' ) ) {
		$pcm_options = pcm_interest_options();
	} else {
		$pcm_options = array(
			'salesforce-managed-support' => 'Salesforce Managed Support',
			'ai-enablement'              => 'AI Enablement',
			'custom-development'         => 'Custom Development',
			'something-else'             => 'Something else',
		);
	}

	return apply_filters( 'pcm_crm_interest_options', $pcm_options );
}
