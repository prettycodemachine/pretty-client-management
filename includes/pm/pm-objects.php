<?php
/**
 * Register the PM objects.
 *
 * A separate, gated file rather than a line at the bottom of each model, which
 * is where core objects declare themselves. The gate has to be able to withhold
 * registration while keeping the model — WP-CLI and the installer need the
 * models whatever the switch says, but a switched-off module must leave no route
 * to 404 against and nothing in the filter builder.
 *
 * Registration order is the order the REST routes try, and the order the Data
 * Export tab lists — which for an export is dependency order, since a task
 * cannot reference a project that has not been loaded yet.
 *
 * Customisable, the same as the four core objects: a cf_ column added here
 * will outlive the module being switched off, the same as any other custom
 * field's column is never dropped once the module has settled into use — see
 * includes/custom-fields.php's own docblock. Every layoutable object below
 * carries the flag; the two below that are not layoutable (retainer_periods,
 * status_reports) are bookkeeping nobody edits by hand and stay off both.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

pcm_crm_register_object( 'projects', array(
	'model'          => 'pcm_crm_projects',
	'label'          => 'Project',
	'plural'         => 'Projects',
	'reportable'     => true,
	'exportable'     => true,
	'recyclable'     => true,
	'customisable'   => true,
	// project_type is what makes a Custom Development project ask a
	// different form than a Support Retainer one, without hardcoding either
	// arrangement into the JS the way the old showWhen-only approach did.
	'layoutable'     => true,
	'layout_variant' => 'project_type',
	// No standard Salesforce counterpart. The Data Export tab labels these as a
	// local extract rather than a migration path, so the header row cannot be
	// mistaken for a mapping.
	'sf'             => 'PCM_Project__c',
	'group_options'  => array( 'stage_name', 'project_type', 'health', 'account_id', 'owner_id' ),
	'related'        => 'fetch',
	'module'         => 'pm',
	'icon'           => 'portfolio',
	'color'          => 2,
	'page'           => 'pcm-crm-projects',
) );

pcm_crm_register_object( 'project_tasks', array(
	'model'         => 'pcm_crm_project_tasks',
	'label'         => 'Task',
	'plural'        => 'Tasks',
	'reportable'    => true,
	'exportable'    => true,
	'recyclable'    => true,
	'customisable'  => true,
	'layoutable'    => true,
	'sf'            => 'PCM_Project_Task__c',
	'group_options' => array( 'status', 'assignee_user_id', 'project_id' ),
	'module'        => 'pm',
	'icon'          => 'yes-alt',
	'color'         => 8,
	'page'          => 'pcm-crm-project-tasks',
) );

pcm_crm_register_object( 'project_raid', array(
	'model'         => 'pcm_crm_project_raid',
	'label'         => 'RAID Entry',
	'plural'        => 'RAID Log',
	'reportable'    => true,
	'exportable'    => true,
	'recyclable'    => true,
	'customisable'  => true,
	'layoutable'    => true,
	'sf'            => 'PCM_Project_RAID__c',
	'group_options' => array( 'raid_type', 'status', 'impact', 'project_id' ),
	'module'        => 'pm',
	'icon'          => 'warning',
	'color'         => 4,
	'page'          => 'pcm-crm-raid',
) );

pcm_crm_register_object( 'project_milestones', array(
	'model'         => 'pcm_crm_project_milestones',
	'label'         => 'Milestone',
	'plural'        => 'Milestones',
	'reportable'    => true,
	'exportable'    => true,
	'recyclable'    => true,
	'customisable'  => true,
	'layoutable'    => true,
	'sf'            => 'PCM_Project_Milestone__c',
	'group_options' => array( 'status', 'project_id' ),
	'module'        => 'pm',
	'icon'          => 'flag',
	'color'         => 6,
	'page'          => 'pcm-crm-milestones',
) );

pcm_crm_register_object( 'project_roles', array(
	'model'         => 'pcm_crm_project_roles',
	'label'         => 'Project Role',
	'plural'        => 'Project Roles',
	'reportable'    => true,
	'exportable'    => true,
	'recyclable'    => true,
	'customisable'  => true,
	'layoutable'    => true,
	'sf'            => 'PCM_Project_Role__c',
	'group_options' => array( 'party_type', 'role', 'project_id' ),
	'module'        => 'pm',
	'icon'          => 'groups',
	'color'         => 6,
) );

pcm_crm_register_object( 'time_entries', array(
	'model'         => 'pcm_crm_time_entries',
	'label'         => 'Time Entry',
	'plural'        => 'Time',
	'reportable'    => true,
	'exportable'    => true,
	'recyclable'    => true,
	'customisable'  => true,
	'layoutable'    => true,
	'sf'            => 'PCM_Time_Entry__c',
	'group_options' => array( 'project_id', 'user_id', 'is_billable', 'task_id' ),
	'module'        => 'pm',
	'icon'          => 'clock',
	'color'         => 3,
	'page'          => 'pcm-crm-time',
) );

pcm_crm_register_object( 'allocations', array(
	'model'         => 'pcm_crm_allocations',
	'label'         => 'Allocation',
	'plural'        => 'Allocations',
	'reportable'    => true,
	'exportable'    => true,
	'recyclable'    => true,
	'customisable'  => true,
	'layoutable'    => true,
	'sf'            => 'PCM_Allocation__c',
	'group_options' => array( 'user_id', 'project_id', 'role' ),
	'module'        => 'pm',
	'icon'          => 'chart-bar',
	'color'         => 5,
) );

// Machinery rather than records anyone reports on: a period is bookkeeping behind
// the burn-down, and a sent report is a log entry. Both are still exportable,
// because a table you cannot get data out of is a liability.
pcm_crm_register_object( 'retainer_periods', array(
	'model'      => 'pcm_crm_retainer_periods',
	'label'      => 'Retainer Period',
	'plural'     => 'Retainer Periods',
	'exportable' => true,
	'sf'         => 'PCM_Retainer_Period__c',
	'module'     => 'pm',
	'icon'          => 'backup',
	'color'         => 3,
) );

pcm_crm_register_object( 'status_reports', array(
	'model'      => 'pcm_crm_status_reports',
	'label'      => 'Status Report',
	'plural'     => 'Status Reports',
	'exportable' => true,
	'sf'         => 'PCM_Status_Report__c',
	'module'     => 'pm',
	'icon'          => 'media-document',
	'color'         => 1,
) );

pcm_crm_register_object( 'help_tickets', array(
	'model'         => 'pcm_crm_help_tickets',
	'label'         => 'Help Ticket',
	'plural'        => 'Help Tickets',
	'reportable'    => true,
	'exportable'    => true,
	'recyclable'    => true,
	'customisable'  => true,
	'layoutable'    => true,
	'sf'            => 'PCM_Help_Ticket__c',
	'group_options' => array( 'status', 'project_id', 'account_id' ),
	// No related section: a ticket's only detail beyond its fields is its
	// comment thread, which renders as its own tab (registerRecordTabs in
	// assets/help-tickets.js) rather than through the generic related-list
	// mechanism — there is nothing else a ticket has children of.
	'related'       => '',
	'module'        => 'pm',
	'icon'          => 'sos',
	'color'         => 4,
	'page'          => 'pcm-crm-help-tickets',
) );
