<?php
/**
 * The stages each project type moves through, and the module's other picklists.
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

// Where stage sets were saved, keyed by type label, before types had keys. Read
// once by pcm_crm_pm_migrate_types() and then removed.
const PCM_CRM_PM_STAGES_OPTION = 'pcm_crm_pm_stages';

/**
 * Every project type's key, active or not.
 *
 * Inactive types still count: a type retired from the New Project chooser still
 * has projects on it, and those must keep saving. The types themselves live in
 * pm-archetypes.php.
 */
function pcm_crm_pm_project_types() {
	return apply_filters( 'pcm_crm_pm_project_types', array_keys( pcm_crm_pm_types() ) );
}

/**
 * The project type picklist, as value/label pairs.
 */
function pcm_crm_pm_project_type_options() {
	$pcm_out = array();

	foreach ( pcm_crm_pm_types() as $pcm_key => $pcm_type ) {
		$pcm_out[] = array( 'value' => $pcm_key, 'label' => $pcm_type['label'] );
	}

	return $pcm_out;
}

/**
 * The types billed against an hour allotment rather than a fixed budget.
 *
 * Derived from each type's archetype rather than from the word "Retainer" in
 * its name, so renaming a type does not silently change how it is billed.
 */
function pcm_crm_pm_retainer_types() {
	$pcm_out = array();

	foreach ( pcm_crm_pm_types() as $pcm_key => $pcm_type ) {
		if ( 'retainer' === $pcm_type['archetype'] ) {
			$pcm_out[] = $pcm_key;
		}
	}

	return apply_filters( 'pcm_crm_pm_retainer_types', $pcm_out );
}

function pcm_crm_pm_is_retainer( $pcm_type ) {
	return 'retainer' === pcm_crm_pm_archetype_for( $pcm_type );
}

/**
 * The stage set for a type — by key, or by the label an older row stores.
 *
 * `is_active` marks the one stage per type that a healthy, running project sits
 * in — what the dashboard counts as work in flight. `is_closed` marks the ones
 * that stop the clock. Renewed is neither: it is a moment rather than a state,
 * and a project passing through it returns to Active with a fresh period.
 *
 * An unrecognised type falls back to the first set rather than to nothing: an
 * empty picklist makes a project unsaveable, and a type arriving from an old row
 * or a filter is not the project's fault.
 */
function pcm_crm_pm_stages( $pcm_type = '' ) {
	$pcm_sets = array();

	foreach ( pcm_crm_pm_types() as $pcm_key => $pcm_def ) {
		$pcm_sets[ $pcm_key ] = $pcm_def['stages'];
	}

	$pcm_sets = apply_filters( 'pcm_crm_pm_stages', $pcm_sets );

	if ( '' === $pcm_type ) {
		return $pcm_sets;
	}

	$pcm_key = pcm_crm_pm_type_key( $pcm_type );

	if ( '' !== $pcm_key && isset( $pcm_sets[ $pcm_key ] ) ) {
		return $pcm_sets[ $pcm_key ];
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
		// Client first: it is the party that gives a contact portal access,
		// and the one most roles are added for.
		'client'   => 'Client',
		'internal' => 'Internal',
		'partner'  => 'Partner',
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
