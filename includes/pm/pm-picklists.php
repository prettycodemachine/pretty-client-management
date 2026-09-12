<?php
/**
 * Project types, and the stages each type moves through.
 *
 * Stages are structs rather than strings, for the reason the CRM's own stages
 * are: a consumer needs to know whether a stage means the work is over, and
 * asking it to recognise the closing ones by name is how "Churned" ends up
 * counted as active somewhere.
 *
 * They are per type because a retainer and a build do not share a lifecycle.
 * A support retainer renews; a custom build ships and stops. One combined list
 * would offer UAT on a retainer and Renewal Pending on a build, and a picklist
 * that offers the wrong answer eventually gets one.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_PM_STAGES_OPTION = 'pcm_crm_pm_stages';

function pcm_crm_pm_project_types() {
	return apply_filters( 'pcm_crm_pm_project_types', array(
		'Salesforce Support Retainer',
		'AI Enablement Retainer',
		'Custom Development',
	) );
}

/**
 * The types billed against an hour allotment rather than a fixed budget.
 *
 * Derived from a list rather than from the word "Retainer" in the name, so
 * renaming a type does not silently change how it is billed.
 */
function pcm_crm_pm_retainer_types() {
	return apply_filters( 'pcm_crm_pm_retainer_types', array(
		'Salesforce Support Retainer',
		'AI Enablement Retainer',
	) );
}

function pcm_crm_pm_is_retainer( $pcm_type ) {
	return in_array( (string) $pcm_type, pcm_crm_pm_retainer_types(), true );
}

/**
 * The shipped stage sets, keyed by project type.
 *
 * `is_active` marks the one stage per type that a healthy, running project sits
 * in — what the dashboard counts as work in flight. `is_closed` marks the ones
 * that stop the clock. Renewed is neither: it is a moment rather than a state,
 * and a project passing through it returns to Active with a fresh period.
 */
function pcm_crm_pm_default_stages() {
	$pcm_retainer = array(
		array( 'name' => 'Onboarding',      'order' => 10, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
		array( 'name' => 'Active',          'order' => 20, 'is_active' => 1, 'is_closed' => 0, 'is_renewal' => 0 ),
		array( 'name' => 'At Risk',         'order' => 30, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
		array( 'name' => 'Renewal Pending', 'order' => 40, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
		array( 'name' => 'Renewed',         'order' => 50, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 1 ),
		array( 'name' => 'Ended',           'order' => 60, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
		array( 'name' => 'Churned',         'order' => 70, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
	);

	return array(
		'Salesforce Support Retainer' => $pcm_retainer,
		'AI Enablement Retainer'      => $pcm_retainer,
		'Custom Development'          => array(
			array( 'name' => 'Initiation', 'order' => 10, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
			array( 'name' => 'Discovery',  'order' => 20, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
			array( 'name' => 'Design',     'order' => 30, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
			array( 'name' => 'Build',      'order' => 40, 'is_active' => 1, 'is_closed' => 0, 'is_renewal' => 0 ),
			array( 'name' => 'UAT',        'order' => 50, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
			array( 'name' => 'Launch',     'order' => 60, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
			array( 'name' => 'Hypercare',  'order' => 70, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
			array( 'name' => 'Closed',     'order' => 80, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
			array( 'name' => 'Cancelled',  'order' => 90, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
		),
	);
}

/**
 * The stage set for a type.
 *
 * An unrecognised type falls back to the first registered set rather than to
 * nothing: an empty picklist makes a project unsaveable, and a type arriving
 * from an old row or a filter is not the project's fault.
 */
function pcm_crm_pm_stages( $pcm_type = '' ) {
	$pcm_saved = get_option( PCM_CRM_PM_STAGES_OPTION, array() );
	$pcm_sets  = ( is_array( $pcm_saved ) && $pcm_saved ) ? $pcm_saved : pcm_crm_pm_default_stages();

	$pcm_sets = apply_filters( 'pcm_crm_pm_stages', $pcm_sets );

	if ( '' === $pcm_type ) {
		return $pcm_sets;
	}

	if ( isset( $pcm_sets[ $pcm_type ] ) ) {
		return $pcm_sets[ $pcm_type ];
	}

	return $pcm_sets ? reset( $pcm_sets ) : array();
}

/**
 * One stage's struct, or null when the type does not have that stage.
 *
 * Returning null for a stage belonging to another type is the point: it is what
 * lets the validator refuse UAT on a retainer.
 */
function pcm_crm_pm_stage( $pcm_type, $pcm_name ) {
	foreach ( pcm_crm_pm_stages( $pcm_type ) as $pcm_stage ) {
		if ( $pcm_stage['name'] === $pcm_name ) {
			return $pcm_stage;
		}
	}

	return null;
}

function pcm_crm_pm_stage_names( $pcm_type = '' ) {
	return wp_list_pluck( pcm_crm_pm_stages( $pcm_type ), 'name' );
}

/**
 * Every stage name any type can be in.
 *
 * The board and the filter builder need the union, because they are shown before
 * a type has been picked.
 */
function pcm_crm_pm_all_stage_names() {
	$pcm_names = array();

	foreach ( pcm_crm_pm_stages() as $pcm_set ) {
		foreach ( $pcm_set as $pcm_stage ) {
			if ( ! in_array( $pcm_stage['name'], $pcm_names, true ) ) {
				$pcm_names[] = $pcm_stage['name'];
			}
		}
	}

	return $pcm_names;
}

function pcm_crm_pm_open_stage_names( $pcm_type = '' ) {
	$pcm_names = array();

	foreach ( pcm_crm_pm_stages( $pcm_type ) as $pcm_stage ) {
		if ( empty( $pcm_stage['is_closed'] ) ) {
			$pcm_names[] = $pcm_stage['name'];
		}
	}

	return $pcm_names;
}

/* Everything else a project picks from ------------------------------------- */

function pcm_crm_pm_health_options() {
	return apply_filters( 'pcm_crm_pm_health_options', array( 'Green', 'Amber', 'Red' ) );
}

function pcm_crm_pm_raid_types() {
	return apply_filters( 'pcm_crm_pm_raid_types', array( 'Risk', 'Assumption', 'Issue', 'Dependency' ) );
}

function pcm_crm_pm_raid_statuses() {
	return apply_filters( 'pcm_crm_pm_raid_statuses', array( 'Open', 'Monitoring', 'Mitigated', 'Closed', 'Accepted' ) );
}

/**
 * Probability and impact, as words with a weight behind them.
 *
 * Severity is their product, so the scale has to be ordinal and shared. Kept as
 * a map rather than two lists because the weights are the whole reason the
 * words exist.
 */
function pcm_crm_pm_raid_scale() {
	return apply_filters( 'pcm_crm_pm_raid_scale', array(
		'Low'    => 1,
		'Medium' => 2,
		'High'   => 3,
	) );
}

function pcm_crm_pm_task_statuses() {
	return apply_filters( 'pcm_crm_pm_task_statuses', array( 'Not Started', 'In Progress', 'Blocked', 'Done' ) );
}

/**
 * Who a person on a project is to it.
 *
 * Stored rather than derived from the account they belong to: an account's type
 * changes, and a derived classification would retroactively rewrite who was
 * internal to an engagement that finished last year.
 */
function pcm_crm_pm_party_types() {
	return apply_filters( 'pcm_crm_pm_party_types', array(
		'internal' => 'Internal',
		'partner'  => 'Partner',
		'client'   => 'Client',
	) );
}

function pcm_crm_pm_roles() {
	return apply_filters( 'pcm_crm_pm_roles', array(
		'Engagement Lead', 'Project Manager', 'Solution Architect', 'Developer',
		'Consultant', 'Admin', 'QA', 'Executive Sponsor', 'Business Owner',
		'Subject Matter Expert', 'Other',
	) );
}

function pcm_crm_pm_periods() {
	return apply_filters( 'pcm_crm_pm_periods', array(
		'monthly'   => 'Monthly',
		'quarterly' => 'Quarterly',
	) );
}
