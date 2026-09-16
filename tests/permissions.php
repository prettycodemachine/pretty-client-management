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

echo "\n--- the Setup pages the editor registers ---\n";

check( 'Profiles, Permission Sets and Staff Access are all registered',
	array(
		null !== pcm_crm_setup_page( 'access-profiles' ),
		null !== pcm_crm_setup_page( 'access-permission-sets' ),
		null !== pcm_crm_setup_page( 'access-users' ),
	),
	array( true, true, true )
);
check( 'all three sit under Platform, beside Theme and Modules',
	array(
		pcm_crm_setup_page( 'access-profiles' )['group'],
		pcm_crm_setup_page( 'access-permission-sets' )['group'],
		pcm_crm_setup_page( 'access-users' )['group'],
	),
	array( 'platform', 'platform', 'platform' )
);

echo "\n--- cleaning a posted profile or permission set ---\n";

check( 'a blank name is refused',
	pcm_crm_clean_access_definition( array( 'label' => '' ) )->get_error_code(), 'pcm_crm_access_label_required' );
check( 'whitespace alone is refused too',
	pcm_crm_clean_access_definition( array( 'label' => '   ' ) )->get_error_code(), 'pcm_crm_access_label_required' );

$pcm_cleaned = pcm_crm_clean_access_definition( array(
	'label'       => 'Read Only',
	'description' => 'Sees everything, changes nothing.',
	'grants'      => array( 'crm' => array( 'view' ), 'bogus' => array( 'view' ), 'media' => array( 'view', 'delete' ) ),
) );

check( 'the label and description come through', array( $pcm_cleaned['label'], $pcm_cleaned['description'] ),
	array( 'Read Only', 'Sees everything, changes nothing.' ) );
check( 'grants run through the same sanitiser the model uses — an unknown area is dropped',
	isset( $pcm_cleaned['grants']['bogus'] ), false );
check( 'and an action its area does not offer is dropped',
	$pcm_cleaned['grants']['media'], array( 'view' ) );

echo "\n--- deleting a profile that is still somebody's baseline ---\n";

update_option( PCM_CRM_PROFILES_OPTION, array( 'sales' => array( 'label' => 'Sales', 'description' => '', 'grants' => array() ) ) );
$GLOBALS['pcm_test_users']     = array( 9 => (object) array( 'ID' => 9, 'roles' => array( PCM_CRM_STAFF_ROLE ) ) );
$GLOBALS['pcm_test_user_meta'] = array();

check( 'an unassigned profile is not in use', pcm_crm_profile_in_use( 'sales' ), false );

update_user_meta( 9, PCM_CRM_PROFILE_META, 'sales' );
check( 'an assigned profile is in use', pcm_crm_profile_in_use( 'sales' ), true );

update_user_meta( 9, PCM_CRM_PROFILE_META, 'delivery' );
check( 'and only the profile actually held, not any profile at all',
	pcm_crm_profile_in_use( 'sales' ), false );

check( 'a blank key is never "in use" — nothing to refuse deleting',
	pcm_crm_profile_in_use( '' ), false );

echo "\n--- narrowing a posted access assignment ---\n";

update_option( PCM_CRM_PROFILES_OPTION, array( 'sales' => array( 'label' => 'Sales', 'description' => '', 'grants' => array() ) ) );
update_option( PCM_CRM_SETS_OPTION, array( 'exporter' => array( 'label' => 'Exporting', 'description' => '', 'grants' => array() ) ) );

check( 'a real profile and set survive',
	pcm_crm_clean_user_access( array( 'profile' => 'sales', 'sets' => array( 'exporter' ) ) ),
	array( 'profile' => 'sales', 'sets' => array( 'exporter' ) )
);
check( 'a profile key nobody defines is dropped to none',
	pcm_crm_clean_user_access( array( 'profile' => 'ghost' ) )['profile'], '' );
check( 'a set key nobody defines is dropped from the list',
	pcm_crm_clean_user_access( array( 'sets' => array( 'exporter', 'ghost' ) ) )['sets'], array( 'exporter' ) );
check( 'nothing posted is nothing assigned',
	pcm_crm_clean_user_access( array() ), array( 'profile' => '', 'sets' => array() ) );

echo "\n--- who the Staff Access screen lists ---\n";

$GLOBALS['pcm_test_users'] = array(
	// An administrator holding the cap directly is not staff — the union is
	// what pcm_crm_user_can() asks, but this screen is for people whose
	// access a profile actually decides.
	3 => (object) array( 'ID' => 3, 'roles' => array( 'administrator' ), 'display_name' => 'Admin' ),
	9 => (object) array( 'ID' => 9, 'roles' => array( PCM_CRM_STAFF_ROLE ), 'display_name' => 'Staffer' ),
);
check( 'only the Staff role appears, not every capable user',
	wp_list_pluck( pcm_crm_staff_users(), 'ID' ), array( 9 ) );

pcm_test_reset_caps();
pcm_crm_flush_permissions();
$GLOBALS['pcm_test_users']     = array();
$GLOBALS['pcm_test_user_caps'] = array();
$GLOBALS['pcm_test_user_meta'] = array();

echo "\n--- CRM Settings: top-level menu, not a Settings submenu ---\n";

$GLOBALS['pcm_test_menus'] = array();
pcm_crm_menu();

$pcm_settings_menu = null;
foreach ( $GLOBALS['pcm_test_menus'] as $pcm_menu ) {
	if ( 'menu' === $pcm_menu['type'] && 'pcm-crm-settings' === $pcm_menu['slug'] ) { $pcm_settings_menu = $pcm_menu; }
}
check( 'CRM Settings registers as its own top-level menu, not under Settings',
	$pcm_settings_menu && '' === $pcm_settings_menu['parent'], true );

// remove_submenu_page() is deliberately never called for these four:
// unsetting a page from $GLOBALS['submenu'] does not just hide its sidebar
// row, it is also where WordPress's own dispatch (get_admin_page_parent(),
// called from user_can_access_admin_page()) looks up which top-level menu a
// requested page belongs to — removing the entry left every one of these
// pages unable to resolve its own parent and refused with WordPress's own
// "Sorry, you are not allowed to access this page," discovered only by an
// authenticated HTTP request against staging, since neither this stub's
// admin-menu recording nor WP-CLI's eval populate $submenu/$menu the way a
// real admin page load does. Confirmed registered as ordinary submenu pages
// instead, hidden from the sidebar with CSS (pcm_crm_hide_setup_submenu_items())
// rather than by removing the registration.
$pcm_registered = array();
$pcm_removed    = array();
foreach ( $GLOBALS['pcm_test_menus'] as $pcm_menu ) {
	if ( 'submenu' === $pcm_menu['type'] && PCM_CRM_SETUP_SLUG === $pcm_menu['parent'] ) { $pcm_registered[] = $pcm_menu['slug']; }
	if ( 'removed' === $pcm_menu['type'] ) { $pcm_removed[] = $pcm_menu['slug']; }
}
$pcm_app_backed = array( 'pcm-crm-recycle-bin', 'pcm-crm-schedules', 'pcm-crm-sequences', 'pcm-crm-templates' );

check( 'each app-backed page is registered as an ordinary submenu of CRM Settings',
	array_values( array_diff( $pcm_app_backed, $pcm_registered ) ), array() );
check( 'and none of them is ever unregistered — that is what broke them',
	$pcm_removed, array() );

echo "\n--- no URL in the codebase had to change ---\n";

// The whole point of restoring the top-level menu through PCM_CRM_SETUP_SLUG
// rather than a new slug: pcm_crm_setup_url() already emitted admin.php?page=,
// which resolves a registered page regardless of what its parent is.
check( 'pcm_crm_setup_url() still emits the same shape it always did',
	pcm_crm_setup_url( 'fields' ), 'https://example.com/wp-admin/admin.php?page=pcm-crm-settings&tab=fields' );
check( 'and the recycle bin’s own slug is untouched',
	pcm_crm_setup_url( 'recycle' ), 'https://example.com/wp-admin/admin.php?page=pcm-crm-recycle-bin' );
check( 'pcm_crm_settings_url() is gone rather than kept as a second way to say the same thing',
	function_exists( 'pcm_crm_settings_url' ), false );

echo "\n--- every settings group this plugin owns can actually be saved ---\n";

update_option( PCM_CRM_MODULES_OPTION, array( 'pm' => 1, 'portal' => 1 ) );
pcm_crm_load_modules();

$GLOBALS['pcm_crm_setting_groups'] = array();
pcm_crm_register_settings();
pcm_crm_pm_register_settings();
pcm_crm_portal_register_settings();

$pcm_expected_groups = array(
	'pcm_crm_form_settings', 'pcm_crm_fields_settings', 'pcm_crm_export_settings',
	'pcm_crm_theme_settings', 'pcm_crm_modules_settings', 'pcm_crm_pipeline_settings',
	'pcm_crm_sales_process_settings', 'pcm_crm_pm_time_settings', 'pcm_crm_pm_picklist_settings',
	'pcm_crm_portal_settings',
);

check( 'pcm_crm_register_setting() saw every group the settings screens register',
	array_diff( $pcm_expected_groups, array_keys( $GLOBALS['pcm_crm_setting_groups'] ) ), array() );

$pcm_missing_filter = array();
foreach ( $pcm_expected_groups as $pcm_group ) {
	if ( ! has_filter( 'option_page_capability_' . $pcm_group, 'pcm_crm_settings_option_capability' ) ) {
		$pcm_missing_filter[] = $pcm_group;
	}
}
check( 'and every one of them has options.php’s capability filter — this is the blocker made un-regressable',
	$pcm_missing_filter, array() );

echo "\n--- what options.php actually asks: is PCM_CRM_SETTINGS_CAP granted ---\n";

// pcm_crm_map_settings_cap() IS the user_has_cap filter, so it cannot call
// current_user_can()/user_can() to find its own answer without re-entering
// itself — tested here as the pure function it is, with a crafted $allcaps
// standing in for what WordPress had already computed from roles before any
// filter ran.
$pcm_admin_user = (object) array( 'ID' => 1 );
$pcm_staff_user = (object) array( 'ID' => 9 );

check( 'an administrator is granted regardless of any profile',
	pcm_crm_map_settings_cap( array( 'manage_options' => true ), array(), array(), $pcm_admin_user )[ PCM_CRM_SETTINGS_CAP ],
	true
);

update_option( PCM_CRM_PROFILES_OPTION, array( 'sales' => array( 'label' => 'Sales', 'description' => '', 'grants' => array( 'settings' => array( 'edit' ) ) ) ) );
$GLOBALS['pcm_test_users'] = array( 9 => (object) array( 'ID' => 9, 'roles' => array( PCM_CRM_STAFF_ROLE ) ) );
$GLOBALS['pcm_test_user_caps'] = array( 9 => array( PCM_CRM_CAP => true ) );
update_user_meta( 9, PCM_CRM_PROFILE_META, 'sales' );
pcm_crm_flush_permissions( 9 );

check( 'a non-administrator with Settings-edit in their profile is granted',
	pcm_crm_map_settings_cap( array(), array(), array(), $pcm_staff_user )[ PCM_CRM_SETTINGS_CAP ],
	true
);

update_option( PCM_CRM_PROFILES_OPTION, array( 'sales' => array( 'label' => 'Sales', 'description' => '', 'grants' => array( 'crm' => array( 'view' ) ) ) ) );
pcm_crm_flush_permissions( 9 );

check( 'a non-administrator without it is refused',
	pcm_crm_map_settings_cap( array(), array(), array(), $pcm_staff_user )[ PCM_CRM_SETTINGS_CAP ],
	false
);

check( 'the filter only ever adds its own key — it never touches manage_options itself',
	pcm_crm_map_settings_cap( array( 'manage_options' => true, 'edit_posts' => false ), array(), array(), $pcm_admin_user ),
	array( 'manage_options' => true, 'edit_posts' => false, PCM_CRM_SETTINGS_CAP => true )
);

pcm_crm_flush_permissions();
$GLOBALS['pcm_test_users']         = array();
$GLOBALS['pcm_test_user_caps']     = array();
$GLOBALS['pcm_test_user_meta']     = array();
$GLOBALS['pcm_crm_setting_groups'] = array();

echo "\n--- the front-end slug map, both directions ---\n";

check( 'the CRM app derives cleanly from pcm_crm_apps() items',
	pcm_crm_front_slug_map(),
	array(
		'pcm-crm' => 'home', 'pcm-crm-accounts' => 'accounts', 'pcm-crm-contacts' => 'contacts',
		'pcm-crm-opportunities' => 'opportunities', 'pcm-crm-pipeline' => 'pipeline',
		'pcm-crm-activities' => 'activities', 'pcm-crm-reports' => 'reports',
	)
);
check( 'the CRM dashboard is the one override — "home", not "dashboard"',
	pcm_crm_front_slug( 'pcm-crm' ), 'home' );
check( 'every other slug is simply its data-view key',
	pcm_crm_front_slug( 'pcm-crm-contacts' ), 'contacts' );
check( 'an object with no front route answers empty rather than guessing',
	pcm_crm_front_slug( 'pcm-crm-recycle-bin' ), '' );
check( 'the reverse map is exact — a word in a URL resolves to one admin slug',
	pcm_crm_slug_for_front( 'contacts' ), 'pcm-crm-contacts' );
check( 'and the override reverses too', pcm_crm_slug_for_front( 'home' ), 'pcm-crm' );
check( 'an unknown word resolves to nothing, not a guess',
	pcm_crm_slug_for_front( 'nonsense' ), '' );

echo "\n--- every screen callbacks() names has a route, and vice versa ---\n";

// The two lists (admin/menu.php's pcm_crm_screen_callbacks() and
// pcm_crm_apps()'s items, which pcm_crm_front_slug_map() is built from) are
// kept separately on purpose — but "separately" only stays safe while
// nothing drifts, so this is the completeness check for that seam, the same
// shape the REST route table above already uses.
$pcm_callback_slugs = array_keys( pcm_crm_screen_callbacks() );
$pcm_routable_slugs = array_keys( pcm_crm_front_slug_map() );
sort( $pcm_callback_slugs );
sort( $pcm_routable_slugs );

check( 'every routable CRM slug has a render callback, and only those',
	$pcm_callback_slugs, $pcm_routable_slugs );

foreach ( pcm_crm_screen_callbacks() as $pcm_slug => $pcm_callback ) {
	check( "callback for $pcm_slug is a real, callable function", is_callable( $pcm_callback ), true );
}

echo "\n--- pcm_crm_screen_url(), both hosts ---\n";

check( 'admin host: unchanged shape',
	pcm_crm_screen_url( 'pcm-crm-contacts', array(), 'admin' ), 'https://example.com/wp-admin/admin.php?page=pcm-crm-contacts' );
check( 'admin host with an id: the #id= shorthand',
	pcm_crm_screen_url( 'pcm-crm-contacts', array( 'id' => 5 ), 'admin' ), 'https://example.com/wp-admin/admin.php?page=pcm-crm-contacts#id=5' );
check( 'front host: the pretty shape',
	pcm_crm_screen_url( 'pcm-crm-contacts', array(), 'front' ), 'https://example.com/staff/contacts/' );
check( 'front host with an id: a path segment, not a hash',
	pcm_crm_screen_url( 'pcm-crm-contacts', array( 'id' => 5 ), 'front' ), 'https://example.com/staff/contacts/5/' );
check( 'front host, a slug with no route yet: falls through to the admin shape',
	pcm_crm_screen_url( 'pcm-crm-settings', array(), 'front' ), 'https://example.com/wp-admin/admin.php?page=pcm-crm-settings' );

echo "\n--- who a link should point at ---\n";

$GLOBALS['pcm_test_users'] = array(
	1 => (object) array( 'ID' => 1 ),
	9 => (object) array( 'ID' => 9, 'roles' => array( PCM_CRM_STAFF_ROLE ) ),
);
$GLOBALS['pcm_test_user_caps'] = array(
	1 => array( 'manage_options' => true, PCM_CRM_CAP => true ),
	9 => array( PCM_CRM_CAP => true ),
);

check( 'an administrator always resolves to admin', pcm_crm_link_host_for_user( 1 ), 'admin' );
check( 'staff resolves to front', pcm_crm_link_host_for_user( 9 ), 'front' );
check( 'an unknown id falls back to admin — the shape that has always worked',
	pcm_crm_link_host_for_user( 999 ), 'admin' );
check( 'id 0 falls back to admin too', pcm_crm_link_host_for_user( 0 ), 'admin' );

echo "\n--- the Employee Portal address, sanitised ---\n";

check( 'a reserved word is refused, keeping the current value',
	pcm_crm_sanitize_front_base( 'wp-admin' ), pcm_crm_front_base() );
check( 'a plain word is slugified', pcm_crm_sanitize_front_base( 'Team Portal' ), 'team-portal' );
check( 'blank falls back to the default', pcm_crm_sanitize_front_base( '' ), 'staff' );

$GLOBALS['pcm_test_pages']['crew'] = true;
check( 'a base colliding with a real page is refused',
	pcm_crm_sanitize_front_base( 'crew' ), pcm_crm_front_base() );
check( 'a clean value is accepted', pcm_crm_sanitize_front_base( 'crew2' ), 'crew2' );
unset( $GLOBALS['pcm_test_pages']['crew'] );

echo "\n--- translating a wp-admin page a locked-out staff member lands on ---\n";

check( 'a routable CRM screen redirects to its front-end equivalent',
	pcm_crm_staff_redirect_target( 'pcm-crm-contacts' ), 'https://example.com/staff/contacts/' );
check( 'CRM Settings and its app-backed pages are exempt — no redirect at all',
	pcm_crm_staff_redirect_target( 'pcm-crm-settings' ), '' );
check( 'the recycle bin, an app-backed Setup page, is exempt the same way',
	pcm_crm_staff_redirect_target( 'pcm-crm-recycle-bin' ), '' );
check( 'no page at all (the bare wp-admin dashboard) goes to the front-end home',
	pcm_crm_staff_redirect_target( '' ), 'https://example.com/staff/' );
check( 'a page nobody registered also falls back to the front-end home',
	pcm_crm_staff_redirect_target( 'some-other-plugins-page' ), 'https://example.com/staff/' );

echo "\n--- which admin hook needs which assets — the enqueue regression ---\n";

// CRM Settings became a top-level menu, which changed every one of these
// hook suffixes: the app-backed Setup pages are now submenus of
// pcm-crm-settings, so a hook-contains-that-string test (what this used to
// be) would now also match them and wrongly skip loading crm.js. Exact
// suffixes WordPress's own get_plugin_page_hookname() produces for a
// top-level menu ("toplevel_page_<slug>") and a submenu
// ("<parent-slug>_page_<own-slug>", parent already sanitised — pcm-crm-
// settings needs no further sanitising since it is already a bare slug).
check( 'the bare Settings home has the skin but no app to mount',
	array(
		pcm_crm_is_setup_screen( 'toplevel_page_pcm-crm-settings' ),
		pcm_crm_hook_is_page( 'toplevel_page_pcm-crm-settings', PCM_CRM_SETUP_SLUG ),
	),
	array( true, true )
);

foreach ( array( 'pcm-crm-templates', 'pcm-crm-sequences', 'pcm-crm-schedules', 'pcm-crm-recycle-bin' ) as $pcm_slug ) {
	$pcm_hook = 'pcm-crm-settings_page_' . $pcm_slug;

	check( "$pcm_slug has the Setup skin AND the app — this is the regression",
		array( pcm_crm_is_setup_screen( $pcm_hook ), pcm_crm_hook_is_page( $pcm_hook, PCM_CRM_SETUP_SLUG ) ),
		array( true, false )
	);
}

check( 'an ordinary CRM work screen has neither',
	array(
		pcm_crm_is_setup_screen( 'pcm-crm_page_pcm-crm-accounts' ),
		pcm_crm_hook_is_page( 'pcm-crm_page_pcm-crm-accounts', PCM_CRM_SETUP_SLUG ),
	),
	array( false, false )
);

echo "\n--- pcm_crm_screen()'s host inference and shell class ---\n";

check( 'wants_wrap is the whole admin/front difference — front drops it',
	array( pcm_crm_wants_wrap( 'admin' ), pcm_crm_wants_wrap( 'front' ) ), array( true, false ) );
check( 'the embedded (Setup-frame) shell never carries "wrap" either way',
	pcm_crm_shell_class( 'admin', 'recycle' ), 'pcm-crm pcm-crm-embedded' );
check( 'an ordinary admin screen keeps "wrap"', pcm_crm_shell_class( 'admin', '' ), 'wrap pcm-crm' );
check( 'the front-end shell never gets it', pcm_crm_shell_class( 'front', '' ), 'pcm-crm pcm-crm-front' );

echo "\n--- the config the front-end host actually localizes ---\n";

$GLOBALS['pcm_test_is_admin'] = false;
pcm_test_set_query_vars( array( 'pcm_crm_screen' => 'contacts', 'pcm_crm_id' => 5 ) );
unset( $GLOBALS['pcm_test_localized'] );
pcm_crm_front_assets();

$pcm_front_cfg = isset( $GLOBALS['pcm_test_localized']['pcm-crm']['PCM_CRM'] ) ? $GLOBALS['pcm_test_localized']['pcm-crm']['PCM_CRM'] : array();

check( 'host is front', isset( $pcm_front_cfg['host'] ) ? $pcm_front_cfg['host'] : null, 'front' );
check( 'the record id in the URL reaches the config, for init() to seed state with',
	isset( $pcm_front_cfg['recordId'] ) ? $pcm_front_cfg['recordId'] : null, 5 );
check( 'the slug map rides along too, so screenUrl() needs no separate request',
	isset( $pcm_front_cfg['screens'] ) ? $pcm_front_cfg['screens']['pcm-crm-contacts'] : null, 'contacts' );

pcm_test_set_query_vars( array( 'pcm_crm_screen' => 'settings' ) );
unset( $GLOBALS['pcm_test_localized'] );
pcm_crm_front_assets();
check( 'the settings route enqueues no app at all — there is nothing there to mount it against',
	isset( $GLOBALS['pcm_test_localized']['pcm-crm'] ), false );

$GLOBALS['pcm_test_is_admin']  = true;
$GLOBALS['pcm_test_users']     = array();
$GLOBALS['pcm_test_user_caps'] = array();
pcm_test_set_query_vars( array() );
