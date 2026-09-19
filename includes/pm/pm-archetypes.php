<?php
/**
 * Process archetypes, and the project types built on them.
 *
 * A project type used to be a free label with its behaviour scattered across
 * the code: a list of which labels were retainers, stage sets keyed by label, a
 * JS hint that hid the retainer fields. Renaming a type quietly changed how its
 * projects were billed, and adding one meant a deploy.
 *
 * Now there are two layers. An **archetype** is a process the code knows how to
 * run — a retainer burns an allotment per period, a fixed-scope build burns a
 * budget against milestones, time and materials bills hours at a rate, internal
 * work bills nothing. It decides the stages a type starts with, which project
 * fields apply and which are required, and how time is logged against it. There
 * are four and they live here, because each is a set of assumptions the rest of
 * the module is written against.
 *
 * A **project type** is what a person picks when creating a project: a name,
 * an archetype, and whatever the type overrides — its own stages, its own
 * defaults, a stricter rule for time. Types are data, edited under
 * PCM Settings › Projects, and stored by a stable key so renaming one never
 * orphans a project.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_PM_TYPES_OPTION = 'pcm_crm_pm_types';
const PCM_CRM_PM_TIME_OPTION  = 'pcm_crm_pm_time_settings';

/**
 * The project fields that belong to one kind of process or another. Anything
 * not named in some group applies to every project.
 */
function pcm_crm_pm_field_groups() {
	return array(
		'retainer' => array( 'retainer_hours', 'retainer_period', 'retainer_start_date', 'retainer_rollover', 'retainer_rollover_cap' ),
		'budget'   => array( 'budget_amount', 'budget_hours' ),
		'rates'    => array( 'default_bill_rate', 'default_cost_rate' ),
	);
}

/**
 * The archetypes.
 *
 * - fields.show:     field groups (see above) whose fields appear.
 * - fields.required: fields a new project of this kind must have.
 * - fields.labels:   a field read differently under this process.
 * - time:            how an entry against one of these projects is logged.
 * - tabs:            related lists in the order this process reads them.
 */
function pcm_crm_pm_archetypes() {
	$pcm_retainer_stages = array(
		array( 'name' => 'Onboarding',      'order' => 10, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
		array( 'name' => 'Active',          'order' => 20, 'is_active' => 1, 'is_closed' => 0, 'is_renewal' => 0 ),
		array( 'name' => 'At Risk',         'order' => 30, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
		array( 'name' => 'Renewal Pending', 'order' => 40, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
		array( 'name' => 'Renewed',         'order' => 50, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 1 ),
		array( 'name' => 'Ended',           'order' => 60, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
		array( 'name' => 'Churned',         'order' => 70, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
	);

	$pcm_time = array(
		'task_required'        => 0,
		'billable_default'     => 1,
		'billable_locked'      => 0,
		'rate_required'        => 0,
		'description_required' => 0,
		'resolves_period'      => 0,
	);

	return apply_filters( 'pcm_crm_pm_archetypes', array(
		'retainer' => array(
			'label'       => __( 'Retainer', 'pcm-crm' ),
			'description' => __( 'An allotment of hours each month or quarter, burned down as time is logged, with unused hours rolling over if you allow it.', 'pcm-crm' ),
			'icon'        => 'backup',
			'stages'      => $pcm_retainer_stages,
			'fields'      => array(
				'show'     => array( 'retainer', 'rates' ),
				'required' => array( 'retainer_hours', 'retainer_period' ),
				'labels'   => array(),
			),
			'time'        => array_merge( $pcm_time, array( 'resolves_period' => 1 ) ),
			'tabs'        => array( 'time', 'tasks', 'raid', 'milestones', 'roles', 'activities' ),
		),
		'fixed' => array(
			'label'       => __( 'Fixed scope', 'pcm-crm' ),
			'description' => __( 'A budget and a set of milestones, delivered in phases. Time is logged against a task, so estimates can be compared with what it took.', 'pcm-crm' ),
			'icon'        => 'flag',
			'stages'      => array(
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
			'fields'      => array(
				'show'     => array( 'budget', 'rates' ),
				'required' => array( 'budget_amount', 'end_date' ),
				'labels'   => array(),
			),
			'time'        => array_merge( $pcm_time, array( 'task_required' => 1 ) ),
			'tabs'        => array( 'tasks', 'raid', 'milestones', 'time', 'roles', 'activities' ),
		),
		'tm' => array(
			'label'       => __( 'Time & materials', 'pcm-crm' ),
			'description' => __( 'Hours billed at a rate as the work happens, with an optional cap. Every billable entry carries a rate.', 'pcm-crm' ),
			'icon'        => 'money-alt',
			'stages'      => array(
				array( 'name' => 'Initiation', 'order' => 10, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
				array( 'name' => 'Active',     'order' => 20, 'is_active' => 1, 'is_closed' => 0, 'is_renewal' => 0 ),
				array( 'name' => 'Wrap-up',    'order' => 30, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
				array( 'name' => 'Closed',     'order' => 40, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
				array( 'name' => 'Cancelled',  'order' => 50, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
			),
			'fields'      => array(
				'show'     => array( 'budget', 'rates' ),
				'required' => array( 'default_bill_rate' ),
				'labels'   => array( 'budget_amount' => __( 'Not-to-exceed Cap', 'pcm-crm' ) ),
			),
			'time'        => array_merge( $pcm_time, array( 'rate_required' => 1 ) ),
			'tabs'        => array( 'time', 'tasks', 'roles', 'raid', 'milestones', 'activities' ),
		),
		'internal' => array(
			'label'       => __( 'Internal', 'pcm-crm' ),
			'description' => __( 'Work for the business itself. No client, no budget and no rates; time is logged but never billed.', 'pcm-crm' ),
			'icon'        => 'admin-home',
			'stages'      => array(
				array( 'name' => 'Planned',     'order' => 10, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ),
				array( 'name' => 'In Progress', 'order' => 20, 'is_active' => 1, 'is_closed' => 0, 'is_renewal' => 0 ),
				array( 'name' => 'Done',        'order' => 30, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
				array( 'name' => 'Cancelled',   'order' => 40, 'is_active' => 0, 'is_closed' => 1, 'is_renewal' => 0 ),
			),
			'fields'      => array(
				'show'     => array(),
				'required' => array(),
				'labels'   => array(),
			),
			'time'        => array_merge( $pcm_time, array( 'billable_default' => 0, 'billable_locked' => 1, 'description_required' => 1 ) ),
			'tabs'        => array( 'tasks', 'time', 'roles', 'raid', 'milestones', 'activities' ),
		),
	) );
}

function pcm_crm_pm_archetype( $pcm_key ) {
	$pcm_archetypes = pcm_crm_pm_archetypes();

	return isset( $pcm_archetypes[ $pcm_key ] ) ? $pcm_archetypes[ $pcm_key ] : null;
}

/**
 * The types a fresh install starts with — the three this business sells.
 */
function pcm_crm_pm_default_types() {
	return array(
		'salesforce-support-retainer' => array(
			'label'       => 'Salesforce Support Retainer',
			'archetype'   => 'retainer',
			'description' => __( 'Ongoing admin and support hours for a Salesforce org.', 'pcm-crm' ),
		),
		'ai-enablement-retainer' => array(
			'label'       => 'AI Enablement Retainer',
			'archetype'   => 'retainer',
			'description' => __( 'A standing allotment for AI rollout, advice and tuning.', 'pcm-crm' ),
		),
		'custom-development' => array(
			'label'       => 'Custom Development',
			'archetype'   => 'fixed',
			'description' => __( 'A scoped build with a budget and milestones.', 'pcm-crm' ),
		),
	);
}

/**
 * One type filled out: its own settings over its archetype's.
 */
function pcm_crm_pm_complete_type( $pcm_key, array $pcm_type ) {
	$pcm_archetype = pcm_crm_pm_archetype( isset( $pcm_type['archetype'] ) ? $pcm_type['archetype'] : '' );

	if ( ! $pcm_archetype ) {
		$pcm_type['archetype'] = 'fixed';
		$pcm_archetype         = pcm_crm_pm_archetype( 'fixed' );
	}

	$pcm_time = isset( $pcm_type['time'] ) && is_array( $pcm_type['time'] ) ? $pcm_type['time'] : array();

	return array(
		'key'         => $pcm_key,
		'label'       => isset( $pcm_type['label'] ) && '' !== $pcm_type['label'] ? (string) $pcm_type['label'] : $pcm_key,
		'archetype'   => $pcm_type['archetype'],
		'description' => isset( $pcm_type['description'] ) ? (string) $pcm_type['description'] : '',
		'active'      => isset( $pcm_type['active'] ) ? (int) (bool) $pcm_type['active'] : 1,
		// A type that has never had its stages edited follows its archetype, so
		// a correction to an archetype's stages reaches every type still on it.
		'stages'      => ! empty( $pcm_type['stages'] ) ? array_values( $pcm_type['stages'] ) : $pcm_archetype['stages'],
		'custom_stages' => ! empty( $pcm_type['stages'] ) ? 1 : 0,
		'time'        => array_merge( $pcm_archetype['time'], array_intersect_key( $pcm_time, $pcm_archetype['time'] ) ),
		'defaults'    => isset( $pcm_type['defaults'] ) && is_array( $pcm_type['defaults'] ) ? $pcm_type['defaults'] : array(),
	);
}

/**
 * Every type, key => completed type, in the order they were saved.
 */
function pcm_crm_pm_types( $pcm_include_inactive = true ) {
	$pcm_saved = get_option( PCM_CRM_PM_TYPES_OPTION, array() );
	$pcm_raw   = ( is_array( $pcm_saved ) && $pcm_saved ) ? $pcm_saved : pcm_crm_pm_default_types();
	$pcm_out   = array();

	foreach ( $pcm_raw as $pcm_key => $pcm_type ) {
		$pcm_type = pcm_crm_pm_complete_type( (string) $pcm_key, (array) $pcm_type );

		if ( $pcm_include_inactive || $pcm_type['active'] ) {
			$pcm_out[ (string) $pcm_key ] = $pcm_type;
		}
	}

	return apply_filters( 'pcm_crm_pm_types', $pcm_out );
}

/**
 * A type by its key — or by its label, which is what every project stored
 * before types had keys and what an old cached form may still post.
 */
function pcm_crm_pm_type( $pcm_key_or_label ) {
	$pcm_needle = (string) $pcm_key_or_label;

	if ( '' === $pcm_needle ) {
		return null;
	}

	$pcm_types = pcm_crm_pm_types();

	if ( isset( $pcm_types[ $pcm_needle ] ) ) {
		return $pcm_types[ $pcm_needle ];
	}

	foreach ( $pcm_types as $pcm_type ) {
		if ( $pcm_type['label'] === $pcm_needle ) {
			return $pcm_type;
		}
	}

	return null;
}

function pcm_crm_pm_type_key( $pcm_key_or_label ) {
	$pcm_type = pcm_crm_pm_type( $pcm_key_or_label );

	return $pcm_type ? $pcm_type['key'] : '';
}

function pcm_crm_pm_type_label( $pcm_key ) {
	$pcm_type = pcm_crm_pm_type( $pcm_key );

	return $pcm_type ? $pcm_type['label'] : (string) $pcm_key;
}

function pcm_crm_pm_archetype_for( $pcm_key ) {
	$pcm_type = pcm_crm_pm_type( $pcm_key );

	return $pcm_type ? $pcm_type['archetype'] : '';
}

/**
 * Which project fields a type shows, requires, and relabels.
 */
function pcm_crm_pm_type_fields( $pcm_key ) {
	$pcm_type      = pcm_crm_pm_type( $pcm_key );
	$pcm_archetype = $pcm_type ? pcm_crm_pm_archetype( $pcm_type['archetype'] ) : null;
	$pcm_groups    = pcm_crm_pm_field_groups();
	$pcm_hidden    = array();

	foreach ( $pcm_groups as $pcm_group => $pcm_fields ) {
		if ( ! $pcm_archetype || ! in_array( $pcm_group, $pcm_archetype['fields']['show'], true ) ) {
			$pcm_hidden = array_merge( $pcm_hidden, $pcm_fields );
		}
	}

	return array(
		'hidden'   => $pcm_archetype ? $pcm_hidden : array(),
		'required' => $pcm_archetype ? $pcm_archetype['fields']['required'] : array(),
		'labels'   => $pcm_archetype ? $pcm_archetype['fields']['labels'] : array(),
	);
}

/**
 * The type definitions the browser needs: everything a form or record reads.
 */
function pcm_crm_pm_type_defs() {
	$pcm_out = array();

	foreach ( pcm_crm_pm_types() as $pcm_key => $pcm_type ) {
		$pcm_archetype = pcm_crm_pm_archetype( $pcm_type['archetype'] );

		$pcm_out[ $pcm_key ] = array(
			'label'       => $pcm_type['label'],
			'archetype'   => $pcm_type['archetype'],
			'description' => $pcm_type['description'],
			'active'      => $pcm_type['active'],
			'icon'        => $pcm_archetype['icon'],
			'stages'      => $pcm_type['stages'],
			'time'        => $pcm_type['time'],
			'defaults'    => $pcm_type['defaults'],
			'fields'      => pcm_crm_pm_type_fields( $pcm_key ),
			'tabs'        => $pcm_archetype['tabs'],
		);
	}

	return $pcm_out;
}

/**
 * The archetypes, as the browser and the settings screen describe them.
 */
function pcm_crm_pm_archetype_defs() {
	$pcm_out = array();

	foreach ( pcm_crm_pm_archetypes() as $pcm_key => $pcm_archetype ) {
		$pcm_out[ $pcm_key ] = array(
			'label'       => $pcm_archetype['label'],
			'description' => $pcm_archetype['description'],
			'icon'        => $pcm_archetype['icon'],
		);
	}

	return $pcm_out;
}

/* Global time-entry settings ----------------------------------------------- */

function pcm_crm_pm_time_settings() {
	$pcm_saved = get_option( PCM_CRM_PM_TIME_OPTION, array() );

	return array_merge( array(
		'max_hours'      => 24,
		'increment'      => 0,
		'allow_future'   => 1,
		'lock_after_days' => 0,
	), is_array( $pcm_saved ) ? $pcm_saved : array() );
}

/* Migration ---------------------------------------------------------------- */

/**
 * Move projects from type labels to type keys, once.
 *
 * Seeds the type option from the shipped types if nothing is saved, folds any
 * stage sets saved under the old label-keyed option into their types, and
 * rewrites each project's type from its label to its key. Every step is a no-op
 * the second time, so it can hang off every install.
 */
function pcm_crm_pm_migrate_types() {
	global $wpdb;

	$pcm_saved = get_option( PCM_CRM_PM_TYPES_OPTION, array() );
	$pcm_types = ( is_array( $pcm_saved ) && $pcm_saved ) ? $pcm_saved : pcm_crm_pm_default_types();

	$pcm_old_stages = get_option( PCM_CRM_PM_STAGES_OPTION, array() );

	foreach ( $pcm_types as $pcm_key => $pcm_type ) {
		if ( empty( $pcm_type['stages'] ) && is_array( $pcm_old_stages ) && ! empty( $pcm_old_stages[ $pcm_type['label'] ] ) ) {
			$pcm_types[ $pcm_key ]['stages'] = $pcm_old_stages[ $pcm_type['label'] ];
		}
	}

	update_option( PCM_CRM_PM_TYPES_OPTION, $pcm_types );

	if ( $pcm_old_stages ) {
		delete_option( PCM_CRM_PM_STAGES_OPTION );
	}

	$pcm_table = pcm_crm_pm_projects_table();

	foreach ( $pcm_types as $pcm_key => $pcm_type ) {
		if ( (string) $pcm_type['label'] === (string) $pcm_key ) {
			continue;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$pcm_table} SET project_type = %s WHERE project_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL -- table name from our own prefix
			$pcm_key,
			$pcm_type['label']
		) );
	}
}
add_action( 'pcm_crm_after_install', 'pcm_crm_pm_migrate_types' );
