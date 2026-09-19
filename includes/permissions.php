<?php
/**
 * Who may do what, area by area.
 *
 * Salesforce's model, because this plugin already mirrors Salesforce's data and
 * the vocabulary should not diverge from it: a user holds exactly one
 * **Profile** as their baseline, plus zero or more **Permission Sets** that can
 * only ever *add*. A set listing fewer actions than the profile takes nothing
 * away. That asymmetry is the whole point — it means "what can this person do"
 * is answerable by reading two screens, rather than by working out precedence.
 *
 * Definitions live in options and assignments in user meta, not in a table.
 * The argument for custom tables in this codebase is about data that has to be
 * filtered, grouped, summed and exported; permission assignments are none of
 * those, and a table would cost a PCM_CRM_Schema::VERSION bump and a migration
 * for a handful of rows.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PCM_CRM_PROFILES_OPTION', 'pcm_crm_profiles' );
define( 'PCM_CRM_SETS_OPTION', 'pcm_crm_permission_sets' );
define( 'PCM_CRM_PROFILE_META', 'pcm_crm_profile' );
define( 'PCM_CRM_SETS_META', 'pcm_crm_permission_sets' );

/**
 * The areas access is granted over.
 *
 * `module` is what keeps two different questions apart: "is Projects switched
 * on for this site" and "may this person see Projects". Only the first has
 * ever been answerable here. An area is listed whether or not its module is
 * on, so a grant survives the module being toggled off and on again — the same
 * storage-vs-surface split includes/modules.php already draws.
 */
function pcm_crm_permission_areas() {
	return apply_filters( 'pcm_crm_permission_areas', array(
		'crm'      => array(
			'label'       => __( 'CRM', 'pcm-crm' ),
			'description' => __( 'Accounts, Contacts, Opportunities, Activities, the pipeline and reports.', 'pcm-crm' ),
			'module'      => '',
		),
		'pm'       => array(
			'label'       => __( 'Projects', 'pcm-crm' ),
			'description' => __( 'Projects, tasks, the timesheet, time entries, the RAID log and help tickets.', 'pcm-crm' ),
			'module'      => 'pm',
		),
		'settings' => array(
			'label'       => __( 'PCM Settings', 'pcm-crm' ),
			'description' => __( 'Everything under PCM Settings — the pipeline, fields and layouts, templates, modules.', 'pcm-crm' ),
			'module'      => '',
		),
		'media'    => array(
			'label'       => __( 'Media', 'pcm-crm' ),
			'description' => __( 'The media library, for attachments and project documents.', 'pcm-crm' ),
			'module'      => '',
		),
	) );
}

/**
 * The verbs.
 *
 * Deliberately four and no more. Anything finer becomes a matrix nobody reads,
 * and the place to express "only their own records" is record scoping, not a
 * fifth column here.
 *
 * `delete` is absent from the Media area on purpose: deleting an attachment
 * maps to WordPress's delete_posts, which staff will not hold, so offering it
 * would be a checkbox that does nothing.
 */
function pcm_crm_permission_actions() {
	return apply_filters( 'pcm_crm_permission_actions', array(
		'view'   => array( 'label' => __( 'View', 'pcm-crm' ), 'description' => __( 'Read records and open screens.', 'pcm-crm' ) ),
		'edit'   => array( 'label' => __( 'Edit', 'pcm-crm' ), 'description' => __( 'Create and change records.', 'pcm-crm' ) ),
		'delete' => array( 'label' => __( 'Delete', 'pcm-crm' ), 'description' => __( 'Delete records, and empty the recycle bin.', 'pcm-crm' ) ),
		'export' => array( 'label' => __( 'Export', 'pcm-crm' ), 'description' => __( 'Download records as CSV.', 'pcm-crm' ) ),
	) );
}

/**
 * Which actions an area actually offers.
 */
function pcm_crm_area_actions( $pcm_area ) {
	$pcm_actions = array_keys( pcm_crm_permission_actions() );

	if ( 'media' === $pcm_area ) {
		$pcm_actions = array_values( array_diff( $pcm_actions, array( 'delete', 'export' ) ) );
	}

	if ( 'settings' === $pcm_area ) {
		$pcm_actions = array_values( array_diff( $pcm_actions, array( 'delete' ) ) );
	}

	return apply_filters( 'pcm_crm_area_actions', $pcm_actions, $pcm_area );
}

/**
 * Drop anything the code does not understand, and close the implications.
 *
 * Edit and delete both imply view, and the implication is applied *here*
 * rather than when the question is asked. Then what is stored is what is true:
 * the editing matrix never shows a surprising blank, and pcm_crm_can() stays a
 * plain array lookup on a function called once per request.
 */
function pcm_crm_sanitize_grants( $pcm_grants ) {
	$pcm_areas = pcm_crm_permission_areas();
	$pcm_out   = array();

	foreach ( (array) $pcm_grants as $pcm_area => $pcm_actions ) {
		if ( ! isset( $pcm_areas[ $pcm_area ] ) ) { continue; }

		$pcm_allowed = pcm_crm_area_actions( $pcm_area );
		$pcm_kept    = array();

		foreach ( (array) $pcm_actions as $pcm_action ) {
			if ( in_array( $pcm_action, $pcm_allowed, true ) ) { $pcm_kept[] = $pcm_action; }
		}

		if ( array_intersect( array( 'edit', 'delete', 'export' ), $pcm_kept ) ) { $pcm_kept[] = 'view'; }

		$pcm_kept = array_values( array_unique( $pcm_kept ) );

		if ( $pcm_kept ) { $pcm_out[ $pcm_area ] = $pcm_kept; }
	}

	return $pcm_out;
}

/**
 * Sanitise a whole set of definitions, as the settings screen posts them.
 *
 * Keys are stable and the label is free to change, the same contract project
 * types already have — so renaming a profile never unassigns anybody.
 */
function pcm_crm_sanitize_permission_definitions( $pcm_defs ) {
	$pcm_out = array();

	foreach ( (array) $pcm_defs as $pcm_key => $pcm_def ) {
		$pcm_key = sanitize_key( $pcm_key );

		if ( '' === $pcm_key ) { continue; }

		$pcm_out[ $pcm_key ] = array(
			'label'       => sanitize_text_field( isset( $pcm_def['label'] ) ? $pcm_def['label'] : $pcm_key ),
			'description' => sanitize_text_field( isset( $pcm_def['description'] ) ? $pcm_def['description'] : '' ),
			'grants'      => pcm_crm_sanitize_grants( isset( $pcm_def['grants'] ) ? $pcm_def['grants'] : array() ),
		);
	}

	return $pcm_out;
}

/**
 * The shipped starter profiles.
 *
 * Defaults in code rather than rows written on activation, the same shape
 * pcm_crm_stages() uses: the option is authoritative once anything is saved,
 * and until then these are what the screens show. Someone who deletes them all
 * meant to, and a later upgrade must not argue with that — which is why the
 * option is consulted with a sentinel rather than an empty-array test.
 */
function pcm_crm_default_profiles() {
	return array(
		'sales'    => array(
			'label'       => __( 'Sales', 'pcm-crm' ),
			'description' => __( 'The CRM only — accounts, contacts, deals and reports.', 'pcm-crm' ),
			'grants'      => array( 'crm' => array( 'view', 'edit', 'export' ), 'media' => array( 'view' ) ),
		),
		'delivery' => array(
			'label'       => __( 'Delivery', 'pcm-crm' ),
			'description' => __( 'Projects only — no access to the sales pipeline.', 'pcm-crm' ),
			'grants'      => array( 'pm' => array( 'view', 'edit' ), 'media' => array( 'view', 'edit' ) ),
		),
		'full'     => array(
			'label'       => __( 'Sales and Delivery', 'pcm-crm' ),
			'description' => __( 'Both apps, without PCM Settings.', 'pcm-crm' ),
			'grants'      => array(
				'crm'   => array( 'view', 'edit', 'delete', 'export' ),
				'pm'    => array( 'view', 'edit', 'delete' ),
				'media' => array( 'view', 'edit' ),
			),
		),
	);
}

function pcm_crm_profiles() {
	$pcm_stored = get_option( PCM_CRM_PROFILES_OPTION, null );

	return apply_filters( 'pcm_crm_profiles', null === $pcm_stored ? pcm_crm_default_profiles() : (array) $pcm_stored );
}

function pcm_crm_permission_sets() {
	return apply_filters( 'pcm_crm_permission_sets', (array) get_option( PCM_CRM_SETS_OPTION, array() ) );
}

function pcm_crm_profile( $pcm_key ) {
	$pcm_profiles = pcm_crm_profiles();

	return isset( $pcm_profiles[ $pcm_key ] ) ? $pcm_profiles[ $pcm_key ] : null;
}

/**
 * The profile and sets assigned to somebody.
 */
function pcm_crm_user_profile_key( $pcm_user_id ) {
	return (string) get_user_meta( (int) $pcm_user_id, PCM_CRM_PROFILE_META, true );
}

function pcm_crm_user_set_keys( $pcm_user_id ) {
	$pcm_keys = get_user_meta( (int) $pcm_user_id, PCM_CRM_SETS_META, true );

	return is_array( $pcm_keys ) ? array_values( array_filter( array_map( 'sanitize_key', $pcm_keys ) ) ) : array();
}

function pcm_crm_assign_permissions( $pcm_user_id, $pcm_profile_key, array $pcm_set_keys = array() ) {
	$pcm_user_id = (int) $pcm_user_id;

	update_user_meta( $pcm_user_id, PCM_CRM_PROFILE_META, sanitize_key( $pcm_profile_key ) );
	update_user_meta( $pcm_user_id, PCM_CRM_SETS_META, array_values( array_filter( array_map( 'sanitize_key', $pcm_set_keys ) ) ) );

	pcm_crm_flush_permissions( $pcm_user_id );
}

/**
 * Is this person an administrator, asked in a way that cannot recurse.
 *
 * The settings capability is answered from this matrix through the
 * user_has_cap filter, and user_can() fires that same filter — so asking "is
 * this an administrator" with user_can() from inside the matrix would call the
 * matrix again, and again. The guard makes the re-entrant answer no, which is
 * the correct answer: the only question that can re-enter is one this matrix
 * is already resolving, and a real administrator short-circuits before ever
 * reaching it.
 *
 * Getting this wrong is a stack overflow on every admin page — a white-screened
 * site — so it lives in one function rather than at each call site.
 */
function pcm_crm_is_administrator( $pcm_user_id = 0 ) {
	static $pcm_asking = array();

	$pcm_user_id = $pcm_user_id ? (int) $pcm_user_id : (int) get_current_user_id();

	if ( isset( $pcm_asking[ $pcm_user_id ] ) ) { return false; }

	$pcm_asking[ $pcm_user_id ] = true;

	$pcm_is = ( $pcm_user_id === (int) get_current_user_id() )
		? current_user_can( 'manage_options' )
		: user_can( $pcm_user_id, 'manage_options' );

	unset( $pcm_asking[ $pcm_user_id ] );

	return (bool) $pcm_is;
}

/**
 * Everything this person may do, resolved.
 *
 * Memoised per user id. The client portal's own context is deliberately *not*
 * memoised (includes/portal/permissions.php) because a bare static returned
 * the first test's answer to every later one in a suite run — but that was a
 * keying problem, not a caching one. Keyed by user id it is safe, and this is
 * asked once per route rather than once per request.
 */
function pcm_crm_effective_permissions( $pcm_user_id = 0 ) {
	$pcm_user_id = $pcm_user_id ? (int) $pcm_user_id : (int) get_current_user_id();

	if ( isset( $GLOBALS['pcm_crm_permission_cache'][ $pcm_user_id ] ) ) {
		return $GLOBALS['pcm_crm_permission_cache'][ $pcm_user_id ];
	}

	$pcm_grants = array();

	if ( pcm_crm_is_administrator( $pcm_user_id ) ) {
		// Not "a profile that happens to hold everything" — an administrator
		// has no profile at all, and must not need one assigned before the
		// CRM works. This is what keeps every gate behaving exactly as it did
		// before profiles existed.
		foreach ( pcm_crm_permission_areas() as $pcm_area => $pcm_def ) {
			$pcm_grants[ $pcm_area ] = pcm_crm_area_actions( $pcm_area );
		}
	} elseif ( $pcm_user_id ) {
		$pcm_profile = pcm_crm_profile( pcm_crm_user_profile_key( $pcm_user_id ) );
		$pcm_grants  = $pcm_profile ? pcm_crm_sanitize_grants( $pcm_profile['grants'] ) : array();

		$pcm_sets = pcm_crm_permission_sets();

		// Additive, always. A set that lists fewer actions than the profile
		// takes nothing away — subtraction would make the effective answer
		// depend on the order sets were assigned in.
		foreach ( pcm_crm_user_set_keys( $pcm_user_id ) as $pcm_key ) {
			if ( ! isset( $pcm_sets[ $pcm_key ] ) ) { continue; }

			foreach ( pcm_crm_sanitize_grants( $pcm_sets[ $pcm_key ]['grants'] ) as $pcm_area => $pcm_actions ) {
				$pcm_existing = isset( $pcm_grants[ $pcm_area ] ) ? $pcm_grants[ $pcm_area ] : array();

				$pcm_grants[ $pcm_area ] = array_values( array_unique( array_merge( $pcm_existing, $pcm_actions ) ) );
			}
		}
	}

	$GLOBALS['pcm_crm_permission_cache'][ $pcm_user_id ] = apply_filters( 'pcm_crm_effective_permissions', $pcm_grants, $pcm_user_id );

	return $GLOBALS['pcm_crm_permission_cache'][ $pcm_user_id ];
}

/**
 * Forget a resolved answer.
 *
 * Called from the assignment save path, and from tests between users. A
 * global rather than a function static purely so it can be cleared from
 * outside the function that fills it.
 */
function pcm_crm_flush_permissions( $pcm_user_id = 0 ) {
	if ( $pcm_user_id ) {
		unset( $GLOBALS['pcm_crm_permission_cache'][ (int) $pcm_user_id ] );
		return;
	}

	$GLOBALS['pcm_crm_permission_cache'] = array();
}

/**
 * May this person do this, here.
 *
 * The module check comes before the administrator short-circuit deliberately:
 * a switched-off module has no screens and no routes, so "may I" is not a
 * question anybody gets a yes to, administrator included.
 */
function pcm_crm_can( $pcm_area, $pcm_action = 'view', $pcm_user_id = 0 ) {
	$pcm_areas = pcm_crm_permission_areas();

	if ( ! isset( $pcm_areas[ $pcm_area ] ) ) { return false; }

	$pcm_module = $pcm_areas[ $pcm_area ]['module'];

	if ( $pcm_module && ! pcm_crm_module_active( $pcm_module ) ) { return false; }

	$pcm_grants = pcm_crm_effective_permissions( $pcm_user_id );
	$pcm_can    = isset( $pcm_grants[ $pcm_area ] ) && in_array( $pcm_action, (array) $pcm_grants[ $pcm_area ], true );

	return (bool) apply_filters( 'pcm_crm_can', $pcm_can, $pcm_area, $pcm_action, $pcm_user_id );
}

/**
 * Which area an object belongs to.
 *
 * Read off the module the object already declares rather than a second key on
 * the registry: 'pm' is both the module slug and the area name on purpose, so
 * a Projects object needs no new declaration and a module added later gets an
 * area for free. An object with no module is CRM.
 */
function pcm_crm_object_area( $pcm_slug ) {
	$pcm_object = pcm_crm_object( $pcm_slug );
	$pcm_module = $pcm_object && ! empty( $pcm_object['module'] ) ? $pcm_object['module'] : '';
	$pcm_area   = $pcm_module ? $pcm_module : 'crm';

	$pcm_areas = pcm_crm_permission_areas();

	// A module whose slug is not an area of its own answers to the CRM, which
	// is the conservative reading: better a gate that exists than one that
	// silently defaults open.
	if ( ! isset( $pcm_areas[ $pcm_area ] ) ) { $pcm_area = 'crm'; }

	return apply_filters( 'pcm_crm_object_area', $pcm_area, $pcm_slug );
}

/**
 * The areas that own something the recycle bin can hold.
 *
 * Emptying the whole bin crosses every object there is, so it is not one
 * area's decision to make.
 */
function pcm_crm_recyclable_areas() {
	$pcm_areas = array();

	foreach ( array_keys( pcm_crm_objects_where( 'recyclable' ) ) as $pcm_slug ) {
		$pcm_areas[ pcm_crm_object_area( $pcm_slug ) ] = true;
	}

	return array_keys( $pcm_areas );
}
