<?php
/**
 * Profiles, permission sets, and what each REST route stands for.
 *
 * Included from run.php rather than run on its own — one entry point, because
 * a second `php tests/…` command is one somebody forgets.
 *
 * The route table below is the part that earns its keep. Thirty-odd routes
 * share a single permission callback that resolves what each one guards from
 * the request, which is only safe while every route is accounted for — so the
 * last check here enumerates the registered routes and fails on any this file
 * does not name.
 */
if ( ! function_exists( 'check' ) ) { exit( "permissions.php is included from run.php\n" ); }

echo "\n--- permission areas and actions ---\n";

check( 'the four areas are registered',
	array_keys( pcm_crm_permission_areas() ), array( 'crm', 'pm', 'settings', 'media' ) );
check( 'Projects is the only module-backed area',
	pcm_crm_permission_areas()['pm']['module'], 'pm' );
// Deleting an attachment maps to delete_posts, which staff will not hold — so
// offering the action would be a checkbox that does nothing.
check( 'media offers no delete', in_array( 'delete', pcm_crm_area_actions( 'media' ), true ), false );
check( 'crm offers all four', pcm_crm_area_actions( 'crm' ), array( 'view', 'edit', 'delete', 'export' ) );

echo "\n--- grants are sanitised on the way in ---\n";

check( 'an unknown area is dropped',
	pcm_crm_sanitize_grants( array( 'wharrgarbl' => array( 'view' ) ) ), array() );
check( 'an unknown action is dropped',
	pcm_crm_sanitize_grants( array( 'crm' => array( 'view', 'fly' ) ) ), array( 'crm' => array( 'view' ) ) );
check( 'an action its area does not offer is dropped',
	pcm_crm_sanitize_grants( array( 'media' => array( 'view', 'delete' ) ) ), array( 'media' => array( 'view' ) ) );
// Applied at save time, not at read time: then what is stored is what is true,
// the editing matrix never shows a surprising blank, and pcm_crm_can() stays a
// plain array lookup on a function called once per route.
check( 'edit implies view, stored',
	pcm_crm_sanitize_grants( array( 'crm' => array( 'edit' ) ) ), array( 'crm' => array( 'edit', 'view' ) ) );
check( 'delete implies view, stored',
	pcm_crm_sanitize_grants( array( 'crm' => array( 'delete' ) ) ), array( 'crm' => array( 'delete', 'view' ) ) );
check( 'export implies view, stored',
	pcm_crm_sanitize_grants( array( 'crm' => array( 'export' ) ) ), array( 'crm' => array( 'export', 'view' ) ) );
check( 'an area granted nothing is not stored at all',
	pcm_crm_sanitize_grants( array( 'crm' => array() ) ), array() );

echo "\n--- the matrix ---\n";

// A staff user: holds the CRM capability, is not an administrator.
$GLOBALS['pcm_test_users']     = array( 7 => (object) array( 'ID' => 7, 'roles' => array( PCM_CRM_STAFF_ROLE ) ) );
$GLOBALS['pcm_test_user_caps'] = array( 7 => array( PCM_CRM_CAP => true ) );

update_option( PCM_CRM_MODULES_OPTION, array( 'pm' => 1 ) );
pcm_crm_load_modules();

update_option( PCM_CRM_PROFILES_OPTION, array(
	'crm-read'  => array( 'label' => 'CRM, read only', 'grants' => array( 'crm' => array( 'view' ) ) ),
	'crm-write' => array( 'label' => 'CRM',            'grants' => array( 'crm' => array( 'view', 'edit', 'export' ) ) ),
	'pm-only'   => array( 'label' => 'Projects only',  'grants' => array( 'pm'  => array( 'view', 'edit' ) ) ),
	'empty'     => array( 'label' => 'Nothing',        'grants' => array() ),
) );
update_option( PCM_CRM_SETS_OPTION, array(
	'pm-read'   => array( 'label' => 'Projects, read only', 'grants' => array( 'pm' => array( 'view' ) ) ),
	'exporter'  => array( 'label' => 'Exporting',           'grants' => array( 'crm' => array( 'export' ) ) ),
	'narrower'  => array( 'label' => 'Narrower than CRM',   'grants' => array( 'crm' => array( 'view' ) ) ),
) );

/**
 * Assign, forget the memo, and ask. The table below is the feature — written
 * as data rather than as sixty hand-written assertions.
 */
$pcm_assign = function ( $pcm_profile, array $pcm_sets = array() ) {
	pcm_crm_assign_permissions( 7, $pcm_profile, $pcm_sets );
};

$pcm_matrix = array(
	// profile,    sets,             area,       action,   expected
	array( 'crm-read',  array(),            'crm',      'view',   true ),
	array( 'crm-read',  array(),            'crm',      'edit',   false ),
	array( 'crm-read',  array(),            'crm',      'delete', false ),
	array( 'crm-read',  array(),            'crm',      'export', false ),
	array( 'crm-read',  array(),            'pm',       'view',   false ),
	array( 'crm-read',  array(),            'settings', 'view',   false ),
	array( 'crm-write', array(),            'crm',      'edit',   true ),
	array( 'crm-write', array(),            'crm',      'export', true ),
	array( 'crm-write', array(),            'crm',      'delete', false ),
	array( 'pm-only',   array(),            'pm',       'edit',   true ),
	array( 'pm-only',   array(),            'crm',      'view',   false ),
	array( 'empty',     array(),            'crm',      'view',   false ),
	array( '',          array(),            'crm',      'view',   false ),
	// A set alone is enough — there need not be a profile behind it.
	array( '',          array( 'pm-read' ), 'pm',       'view',   true ),
	array( '',          array( 'pm-read' ), 'pm',       'edit',   false ),
	// And a set adds to a profile without touching what it already had.
	array( 'crm-read',  array( 'pm-read' ), 'crm',      'view',   true ),
	array( 'crm-read',  array( 'pm-read' ), 'pm',       'view',   true ),
	array( 'crm-read',  array( 'exporter' ), 'crm',     'export', true ),
	array( 'crm-read',  array( 'exporter' ), 'crm',     'edit',   false ),
);

foreach ( $pcm_matrix as $pcm_row ) {
	list( $pcm_profile, $pcm_sets, $pcm_area, $pcm_action, $pcm_want ) = $pcm_row;

	$pcm_assign( $pcm_profile, $pcm_sets );

	check(
		sprintf( 'profile "%s"%s: %s %s', $pcm_profile ? $pcm_profile : '(none)',
			$pcm_sets ? ' + ' . implode( '+', $pcm_sets ) : '', $pcm_area, $pcm_action ),
		pcm_crm_can( $pcm_area, $pcm_action, 7 ),
		$pcm_want
	);
}

echo "\n--- a permission set only ever adds ---\n";

// The rule most likely to be broken by a well-meaning refactor: a set that
// lists fewer actions than the profile must take nothing away, or the answer
// starts depending on the order sets were assigned in.
$pcm_assign( 'crm-write', array( 'narrower' ) );
check( 'a narrower set does not remove edit', pcm_crm_can( 'crm', 'edit', 7 ), true );
check( 'nor export', pcm_crm_can( 'crm', 'export', 7 ), true );

$pcm_assign( 'crm-write', array( 'narrower', 'pm-read' ) );
check( 'order does not matter either', pcm_crm_can( 'crm', 'export', 7 ), true );
check( 'an unknown set key is ignored rather than fatal',
	( $pcm_assign( 'crm-read', array( 'ghost' ) ) === null ) && pcm_crm_can( 'crm', 'view', 7 ), true );

echo "\n--- a module beats a grant ---\n";

// "Is Projects switched on for this site" and "may this person see Projects"
// are different questions, and only the first was ever answerable here. A
// grant survives the module being toggled off and on again.
$pcm_assign( 'pm-only', array() );
check( 'granted and the module on', pcm_crm_can( 'pm', 'view', 7 ), true );

update_option( PCM_CRM_MODULES_OPTION, array( 'pm' => 0 ) );
pcm_crm_flush_permissions();
check( 'granted but the module off', pcm_crm_can( 'pm', 'view', 7 ), false );
check( 'and off for an administrator too', pcm_crm_can( 'pm', 'view' ), false );

update_option( PCM_CRM_MODULES_OPTION, array( 'pm' => 1 ) );
pcm_crm_flush_permissions();
check( 'the grant survived the round trip', pcm_crm_can( 'pm', 'view', 7 ), true );

echo "\n--- administrators need no profile ---\n";

// This is what makes every gate behave on day one exactly as it did before
// profiles existed.
pcm_crm_flush_permissions();
check( 'an administrator may edit the CRM', pcm_crm_can( 'crm', 'edit' ), true );
check( 'and delete in Projects', pcm_crm_can( 'pm', 'delete' ), true );
check( 'and reach CRM Settings', pcm_crm_can( 'settings', 'edit' ), true );
check( 'with no profile assigned', pcm_crm_user_profile_key( get_current_user_id() ), '' );

echo "\n--- the union gate ---\n";

$pcm_assign( 'pm-only', array() );
$GLOBALS['pcm_crm_permission_cache'] = array();
pcm_test_set_caps( array( PCM_CRM_CAP => true ) );   // not an administrator
update_user_meta( get_current_user_id(), PCM_CRM_PROFILE_META, 'pm-only' );
pcm_crm_flush_permissions();
check( 'Projects alone still reaches the app', pcm_crm_user_can(), true );

update_user_meta( get_current_user_id(), PCM_CRM_PROFILE_META, 'empty' );
pcm_crm_flush_permissions();
check( 'nothing granted reaches nothing', pcm_crm_user_can(), false );

// Media alone is not app access — it is a library, not a screen.
update_option( PCM_CRM_PROFILES_OPTION, array_merge( pcm_crm_profiles(),
	array( 'media-only' => array( 'label' => 'Media', 'grants' => array( 'media' => array( 'view' ) ) ) ) ) );
update_user_meta( get_current_user_id(), PCM_CRM_PROFILE_META, 'media-only' );
pcm_crm_flush_permissions();
check( 'media alone is not app access', pcm_crm_user_can(), false );

echo "\n--- objects resolve to areas ---\n";

check( 'a core object is CRM', pcm_crm_object_area( 'accounts' ), 'crm' );
check( 'a module object takes its module', pcm_crm_object_area( 'projects' ), 'pm' );
check( 'an unknown object is CRM rather than open', pcm_crm_object_area( 'nope' ), 'crm' );
check( 'the browser is told each object’s area',
	PCM_CRM_REST::bootstrap( new WP_REST_Request() )['objects']['projects']['area'], 'pm' );
// Emptying the whole bin crosses every object there is, so it is nobody's
// single decision.
check( 'the bin spans both areas', pcm_crm_recyclable_areas(), array( 'crm', 'pm' ) );

echo "\n--- every route resolves to an area and an action ---\n";

/**
 * Route path => the pair it must resolve to, per method.
 *
 * This replaces what would otherwise be a permission declaration repeated at
 * thirty registrations. The completeness check below is what stops a route
 * added later from quietly defaulting to CRM + view.
 */
$pcm_routes = array(
	'/(?P<pcm_object>[^/]+)'                    => array( 'GET' => 'crm/view', 'POST' => 'crm/edit' ),
	'/(?P<pcm_object>[^/]+)/(?P<pcm_id>[\d]+)'  => array( 'GET' => 'crm/view', 'PUT' => 'crm/edit', 'PATCH' => 'crm/edit', 'DELETE' => 'crm/delete' ),
	'/(?P<pcm_object>[^/]+)/(?P<pcm_id>[\d]+)/purge'   => array( 'POST' => 'crm/delete' ),
	'/(?P<pcm_object>[^/]+)/(?P<pcm_id>[\d]+)/restore' => array( 'POST' => 'crm/edit' ),
	'/(?P<pcm_object>[^/]+)/empty-bin'          => array( 'POST' => 'crm/delete' ),
	'/related/(?P<pcm_object>[^/]+)/(?P<pcm_id>[\d]+)' => array( 'GET' => 'crm/view' ),
	'/dashboard'                                => array( 'GET' => 'crm/view' ),
	'/pipeline'                                 => array( 'GET' => 'crm/view' ),
	'/report'                                   => array( 'GET' => 'crm/view' ),
	'/recycle-bin'                              => array( 'GET' => 'crm/view', 'POST' => 'crm/edit' ),
	'/bootstrap'                                => array( 'GET' => 'crm/view' ),
	'/schema'                                   => array( 'GET' => 'crm/view' ),
	'/contacts/(?P<pcm_id>[\d]+)/email'         => array( 'POST' => 'crm/edit' ),
	'/contacts/(?P<pcm_id>[\d]+)/enroll'        => array( 'POST' => 'crm/edit' ),
	'/contacts/(?P<pcm_id>[\d]+)/invite-portal' => array( 'POST' => 'crm/edit' ),
	'/enrollments/(?P<pcm_id>[\d]+)/stop'       => array( 'POST' => 'crm/edit' ),
	'/schedules/(?P<pcm_id>[\d]+)/send'         => array( 'POST' => 'crm/edit' ),
	'/pm/time-context'                          => array( 'GET' => 'pm/view' ),
	'/pm/projects/(?P<pcm_id>[\d]+)/summary'    => array( 'GET' => 'pm/view' ),
	'/pm/projects/(?P<pcm_id>[\d]+)/documents'  => array( 'POST' => 'pm/edit' ),
	'/pm/documents/(?P<pcm_id>[\d]+)'           => array( 'DELETE' => 'pm/delete' ),
	'/pm/documents/(?P<pcm_id>[\d]+)/download'  => array( 'GET' => 'pm/view' ),
	'/pm/tickets/(?P<pcm_id>[\d]+)/comments'    => array( 'GET' => 'pm/view', 'POST' => 'pm/edit' ),
	'/pm/tickets/(?P<pcm_id>[\d]+)/attachments' => array( 'GET' => 'pm/view', 'POST' => 'pm/edit' ),
);

/** Reach the protected resolvers without making them public for one caller. */
class PCM_CRM_Route_Probe extends PCM_CRM_REST {
	public static function pair( $pcm_route, $pcm_method, $pcm_object = '' ) {
		$pcm_url = $pcm_object ? array( 'pcm_object' => $pcm_object ) : array();
		$pcm_req = new WP_REST_Request( $pcm_url, array(), $pcm_route, $pcm_method );

		return self::route_area( $pcm_req, $pcm_route ) . '/' . self::route_action( $pcm_route, $pcm_method );
	}
}

foreach ( $pcm_routes as $pcm_route => $pcm_methods ) {
	// The object captures are stand-ins; a route that names one is tested with
	// a CRM object here and with a Projects object below.
	$pcm_path = '/pcm-crm/v1' . str_replace(
		array( '(?P<pcm_object>[^/]+)', '(?P<pcm_id>[\d]+)' ), array( 'accounts', '5' ), $pcm_route );

	foreach ( $pcm_methods as $pcm_method => $pcm_want ) {
		$pcm_object = false !== strpos( $pcm_route, 'pcm_object' ) ? 'accounts' : '';

		check( sprintf( '%-6s %s', $pcm_method, $pcm_route ),
			PCM_CRM_Route_Probe::pair( $pcm_path, $pcm_method, $pcm_object ), $pcm_want );
	}
}

// The same generic routes, over a Projects object, must land in Projects —
// this is the whole reason the area is read off the object rather than the
// path.
check( 'the generic list route follows its object into Projects',
	PCM_CRM_Route_Probe::pair( '/pcm-crm/v1/projects', 'GET', 'projects' ), 'pm/view' );
check( 'and so does purging one',
	PCM_CRM_Route_Probe::pair( '/pcm-crm/v1/projects/5/purge', 'POST', 'projects' ), 'pm/delete' );

// A report names the object it runs over in its query, not in its path, so a
// CRM-only user cannot report over Projects data.
$pcm_report = new WP_REST_Request( array(), array( 'object' => 'projects' ), '/pcm-crm/v1/report', 'GET' );
check( 'a report takes its area from its own object parameter',
	PCM_CRM_Route_Probe::pair( '/pcm-crm/v1/report', 'GET' ), 'crm/view' );
check( 'and follows that object into Projects',
	PCM_CRM_REST::permission( $pcm_report ) === pcm_crm_can( 'pm', 'view' ), true );

echo "\n--- the special cases, end to end ---\n";

$pcm_req = function ( $pcm_route, $pcm_method, $pcm_object = '' ) {
	return new WP_REST_Request(
		$pcm_object ? array( 'pcm_object' => $pcm_object ) : array(), array(),
		'/pcm-crm/v1' . $pcm_route, $pcm_method );
};

update_user_meta( get_current_user_id(), PCM_CRM_PROFILE_META, 'pm-only' );
pcm_crm_flush_permissions();

// Gating these on one area would leave someone granted Projects alone looking
// at an empty Projects screen, because /bootstrap answered "CRM" and refused.
check( 'a Projects-only user may still bootstrap',
	PCM_CRM_REST::permission( $pcm_req( '/bootstrap', 'GET' ) ), true );
check( 'and read the schema',
	PCM_CRM_REST::permission( $pcm_req( '/schema', 'GET' ) ), true );
check( 'but not list accounts',
	PCM_CRM_REST::permission( $pcm_req( '/accounts', 'GET', 'accounts' ) ), false );
check( 'and may list projects',
	PCM_CRM_REST::permission( $pcm_req( '/projects', 'GET', 'projects' ) ), true );

// Emptying the whole bin reaches every object there is. Anything less than
// delete everywhere something recyclable lives would let a CRM user purge
// Projects records.
update_option( PCM_CRM_PROFILES_OPTION, array_merge( pcm_crm_profiles(), array(
	'crm-delete' => array( 'label' => 'CRM with delete',
		'grants' => array( 'crm' => array( 'view', 'edit', 'delete' ) ) ),
	'both-delete' => array( 'label' => 'Both with delete',
		'grants' => array( 'crm' => array( 'view', 'delete' ), 'pm' => array( 'view', 'delete' ) ) ),
) ) );

update_user_meta( get_current_user_id(), PCM_CRM_PROFILE_META, 'crm-delete' );
pcm_crm_flush_permissions();
check( 'delete in the CRM alone cannot empty the whole bin',
	PCM_CRM_REST::permission( $pcm_req( '/recycle-bin', 'POST' ) ), false );

update_user_meta( get_current_user_id(), PCM_CRM_PROFILE_META, 'both-delete' );
pcm_crm_flush_permissions();
check( 'delete in both areas can',
	PCM_CRM_REST::permission( $pcm_req( '/recycle-bin', 'POST' ) ), true );

// Called with no request at all — a direct call, as a test or an old caller
// would make — still answers the question it always answered.
check( 'a direct call still answers the union',
	PCM_CRM_REST::permission(), pcm_crm_user_can() );

echo "\n--- no route goes unaccounted for ---\n";

$GLOBALS['pcm_test_routes'] = array();
PCM_CRM_REST::register_routes();
foreach ( ( isset( $GLOBALS['filters']['rest_api_init'] ) ? $GLOBALS['filters']['rest_api_init'] : array() ) as $pcm_hooked ) {
	call_user_func( $pcm_hooked[0] );
}

/**
 * Both captures reduced to a placeholder.
 *
 * The object alternation is built at runtime from whichever models are
 * registered, and the id pattern is spelled differently in different files, so
 * the comparison has to be on the shape rather than on the literal regex.
 */
$pcm_shape = function ( $pcm_route ) {
	$pcm_route = preg_replace( '#\(\?P<pcm_object>[^)]*\)#', '{object}', $pcm_route );

	return preg_replace( '#\(\?P<pcm_id>[^)]*\)#', '{id}', $pcm_route );
};

$pcm_mapped = array();

foreach ( array_keys( $pcm_routes ) as $pcm_key ) { $pcm_mapped[ $pcm_shape( $pcm_key ) ] = true; }

$pcm_unmapped = array();

foreach ( $GLOBALS['pcm_test_routes'] as $pcm_registered ) {
	$pcm_route = $pcm_registered['route'];

	// The client portal resolves its own, record-scoped permissions and must
	// never fall through to the staff gate — portal-rest.php says so too.
	if ( 0 === strpos( $pcm_route, '/portal/' ) ) { continue; }

	$pcm_normal = $pcm_shape( $pcm_route );

	// register_routes() is called directly here and again through the
	// rest_api_init listeners, so the same route arrives twice.
	if ( ! isset( $pcm_mapped[ $pcm_normal ] ) ) { $pcm_unmapped[ $pcm_normal ] = true; }
}

check( 'every registered route is named in the table above', array_keys( $pcm_unmapped ), array() );

pcm_test_reset_caps();
pcm_crm_flush_permissions();
$GLOBALS['pcm_test_users']     = array();
$GLOBALS['pcm_test_user_caps'] = array();
