<?php
/**
 * Optional modules.
 *
 * A module is a body of work that shares this plugin's tables, REST namespace,
 * JS app and settings screen, and can be switched off — at which point it
 * leaves the navigation entirely rather than sitting there greyed out.
 *
 * The split that matters is between a module's *storage* and its *surface*.
 * Its tables, models and arithmetic load whatever the switch says; its routes,
 * menus, assets and merge tokens do not. Two reasons:
 *
 * 1. install() is keyed on one version option compared against one
 *    PCM_CRM_Schema::VERSION. A table withheld at install time would leave the
 *    version stamped and the table absent, and a later switch-on would find the
 *    versions equal and create nothing — the module would come up broken until
 *    some unrelated future bump happened to run install() again. Avoiding that
 *    means a version option per module, which is machinery bought to save a
 *    handful of empty tables.
 * 2. WP-CLI and the installer need the models regardless, and a memoised
 *    accessor is a function definition plus one get_option — the cost only
 *    lands if something calls it.
 *
 * The corollary is a feature rather than a concession: switching a module off
 * never loses a record, and switching it back on is immediate.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_MODULES_OPTION = 'pcm_crm_modules';

/**
 * The registered modules.
 *
 * 'schema' is required whatever the switch says; 'files' only when it is on.
 * Paths are relative to includes/.
 */
function pcm_crm_modules() {
	static $pcm_modules = null;

	if ( null === $pcm_modules ) {
		$pcm_modules = apply_filters( 'pcm_crm_modules', array(
			'pm' => array(
				'label'       => __( 'Project Management', 'pcm-crm' ),
				'description' => __( 'Projects, time, a RAID log and resourcing, hanging off the accounts and opportunities already here.', 'pcm-crm' ),
				'default'     => 0,
				'schema'      => array(
					'pm/pm-archetypes.php',
					'pm/pm-picklists.php',
					'pm/pm-weeks.php',
					'pm/schema-pm.php',
					'pm/schema-portal-data.php',
					'pm/model-project.php',
					'pm/model-project-task.php',
					'pm/model-project-raid.php',
					'pm/model-project-milestone.php',
					'pm/model-project-role.php',
					'pm/model-time-entry.php',
					'pm/model-allocation.php',
					'pm/model-retainer-period.php',
					'pm/model-status-report.php',
					'pm/model-help-ticket.php',
					'pm/model-ticket-comment.php',
					'pm/model-project-document.php',
				),
				'files'       => array(
					'pm/pm-objects.php',
					'pm/pm-rest.php',
					'pm/pm-tickets-rest.php',
					'pm/pm-menu.php',
					'pm/pm-settings.php',
					'pm/pm-sample-data.php',
				),
			),
			// Requires 'pm': a client has nothing to see or file a ticket
			// against without a project. Enforced in the Modules tab
			// (pcm_crm_sanitize_modules() and pcm_crm_render_modules_tab() in
			// admin/settings.php) via the generic 'requires' key below, not by
			// anything special-cased to this module — a third module needing
			// the same guarantee needs no further plumbing.
			'portal' => array(
				'label'       => __( 'Client Portal', 'pcm-crm' ),
				'description' => __( 'A login for your clients: their project\'s time, RAID log and documents, and Help Tickets they can raise and comment on themselves.', 'pcm-crm' ),
				'default'     => 0,
				'requires'    => array( 'pm' ),
				'schema'      => array(),
				'files'       => array(
					'portal/permissions.php',
					'portal/portal-rest.php',
					'portal/portal-admin.php',
					'portal/portal-front.php',
				),
			),
		) );
	}

	return $pcm_modules;
}

/**
 * Whether a module is switched on.
 *
 * An unregistered slug is off rather than an error, so a check for a module
 * that has been removed does not take a page down with it.
 */
function pcm_crm_module_active( $pcm_slug ) {
	$pcm_modules = pcm_crm_modules();

	if ( ! isset( $pcm_modules[ $pcm_slug ] ) ) {
		return false;
	}

	$pcm_saved = get_option( PCM_CRM_MODULES_OPTION, array() );

	$pcm_on = isset( $pcm_saved[ $pcm_slug ] )
		? (bool) $pcm_saved[ $pcm_slug ]
		: (bool) $pcm_modules[ $pcm_slug ]['default'];

	// Checked here, not only where the setting is saved: a module whose
	// dependency has since gone off (however that happened) should behave as
	// off itself, not merely warn about it on a settings screen nobody is
	// looking at.
	if ( $pcm_on && ! empty( $pcm_modules[ $pcm_slug ]['requires'] ) ) {
		foreach ( (array) $pcm_modules[ $pcm_slug ]['requires'] as $pcm_required ) {
			if ( ! pcm_crm_module_active( $pcm_required ) ) {
				$pcm_on = false;
				break;
			}
		}
	}

	return (bool) apply_filters( 'pcm_crm_module_active', $pcm_on, $pcm_slug );
}

/**
 * Load every module's storage, and the surface of the ones switched on.
 */
function pcm_crm_load_modules() {
	foreach ( pcm_crm_modules() as $pcm_slug => $pcm_module ) {
		foreach ( (array) $pcm_module['schema'] as $pcm_file ) {
			require_once PCM_CRM_DIR . 'includes/' . $pcm_file;
		}

		if ( ! pcm_crm_module_active( $pcm_slug ) ) {
			continue;
		}

		foreach ( (array) $pcm_module['files'] as $pcm_file ) {
			require_once PCM_CRM_DIR . 'includes/' . $pcm_file;
		}
	}
}
