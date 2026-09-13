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
					'pm/pm-picklists.php',
					'pm/pm-weeks.php',
					'pm/schema-pm.php',
					'pm/model-project.php',
					'pm/model-project-task.php',
					'pm/model-project-raid.php',
					'pm/model-project-role.php',
					'pm/model-time-entry.php',
					'pm/model-allocation.php',
					'pm/model-retainer-period.php',
					'pm/model-status-report.php',
				),
				'files'       => array(
					'pm/pm-objects.php',
					'pm/pm-rest.php',
					'pm/pm-menu.php',
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
