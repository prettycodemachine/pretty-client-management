<?php
/**
 * What the browser needs to draw the project screens.
 *
 * Gated, so a switched-off module puts nothing in the bootstrap payload and
 * leaves no project tokens in the template picker.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The module's picklists, added to the one bootstrap call the app already makes.
 *
 * On the existing payload rather than a second request: the app fetches
 * /bootstrap and /schema once at boot and a third round trip to learn three
 * lists would be the only thing standing between the page and its first paint.
 */
function pcm_crm_pm_bootstrap( $pcm_boot ) {
	$pcm_boot['projectTypes']    = pcm_crm_pm_project_types();
	$pcm_boot['projectStages']   = pcm_crm_pm_all_stage_names();
	$pcm_boot['projectHealth']   = pcm_crm_pm_health_options();
	$pcm_boot['raidTypes']       = pcm_crm_pm_raid_types();
	$pcm_boot['raidStatuses']    = pcm_crm_pm_raid_statuses();
	$pcm_boot['raidLevels']      = pcm_crm_pm_raid_levels();
	$pcm_boot['taskStatuses']    = pcm_crm_pm_task_statuses();
	$pcm_boot['partyTypes']      = pcm_crm_pm_party_types();
	$pcm_boot['projectRoles']    = pcm_crm_pm_roles();
	$pcm_boot['retainerPeriods'] = pcm_crm_pm_periods();

	// Which stages belong to which type, so the record form can narrow the stage
	// picklist once a type is chosen. Sent as a map rather than fetched per type,
	// because it is three short lists and a request per keystroke is not.
	$pcm_boot['projectStageSets'] = pcm_crm_pm_stages();

	$pcm_boot['retainerTypes'] = pcm_crm_pm_retainer_types();

	return $pcm_boot;
}
add_filter( 'pcm_crm_bootstrap', 'pcm_crm_pm_bootstrap' );

/**
 * Projects hang off an account and off the opportunity they came from.
 *
 * Registered as an extra provider on each rather than by editing the function
 * that builds their other lists — which is the whole reason the related registry
 * exists.
 */
function pcm_crm_pm_related_projects_for_account( $pcm_id ) {
	return array(
		'projects' => PCM_CRM_REST::expand( 'project', pcm_crm_projects()->find( array(
			'filters'  => array( 'account_id' => $pcm_id ),
			'orderby'  => 'start_date',
			'order'    => 'DESC',
			'per_page' => 100,
		) ) ),
	);
}

function pcm_crm_pm_related_projects_for_opportunity( $pcm_id ) {
	return array(
		'projects' => PCM_CRM_REST::expand( 'project', pcm_crm_projects()->find( array(
			'filters'  => array( 'opportunity_id' => $pcm_id ),
			'orderby'  => 'start_date',
			'order'    => 'DESC',
			'per_page' => 100,
		) ) ),
	);
}

/**
 * Everything hanging off a project.
 */
function pcm_crm_pm_related_project( $pcm_id ) {
	return array(
		'tasks'      => PCM_CRM_REST::expand( 'project_task', pcm_crm_project_tasks()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			'orderby'  => 'due_date',
			'order'    => 'ASC',
			'per_page' => 200,
		) ) ),
		'raid'       => PCM_CRM_REST::expand( 'project_raid', pcm_crm_project_raid()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			// Worst first: a RAID log read top-down should open on what matters.
			'orderby'  => 'severity',
			'order'    => 'DESC',
			'per_page' => 200,
		) ) ),
		'roles'      => PCM_CRM_REST::expand( 'project_role', pcm_crm_project_roles()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			'orderby'  => 'party_type',
			'order'    => 'ASC',
			'per_page' => 100,
		) ) ),
		'time'       => PCM_CRM_REST::expand( 'time_entry', pcm_crm_time_entries()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			'orderby'  => 'entry_date',
			'order'    => 'DESC',
			'per_page' => 100,
		) ) ),
		'activities' => PCM_CRM_REST::expand( 'activity', pcm_crm_activities_for( 'project', $pcm_id ) ),
	);
}

pcm_crm_register_related( 'accounts', 'pcm_crm_pm_related_projects_for_account' );
pcm_crm_register_related( 'opportunities', 'pcm_crm_pm_related_projects_for_opportunity' );
pcm_crm_register_related( 'projects', 'pcm_crm_pm_related_project' );

// Activities already reach a project without any registration: what_type is a
// free-text column with no whitelist behind it, and the composite index is
// (what_type,what_id), so 'project' needs nothing added here. What it does still
// need is a way to *pick* a project in the Related To control, which is in the
// browser rather than here.

/**
 * {{project.*}} in an email template.
 */
function pcm_crm_pm_merge_prefix( $pcm_prefixes ) {
	$pcm_prefixes['project'] = 'projects';

	return $pcm_prefixes;
}
add_filter( 'pcm_crm_merge_prefixes', 'pcm_crm_pm_merge_prefix' );
