<?php
/**
 * Who can use the CRM.
 *
 * One capability, granted to administrators on activation. Kept separate from
 * manage_options so CRM access can later go to someone who should not be able
 * to change site settings — the grant is then a single add_cap call.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_add_capabilities() {
	$pcm_admin = get_role( 'administrator' );

	if ( $pcm_admin ) {
		$pcm_admin->add_cap( PCM_CRM_CAP );
	}
}

/**
 * The staff role.
 *
 * One role, not one per profile. The role answers only "is this person staff" —
 * the question WordPress itself has to answer, for menu capability strings, for
 * get_users(), and for the admin bar. Everything about *what* they may do comes
 * from their profile and permission sets, and a second role would be a parallel
 * way of saying the same thing that would drift from the first.
 *
 * PCM_CRM_CAP is load-bearing here beyond gating: pcm_crm_owner_choices()
 * defines assignable record owners as everyone holding it. Without the cap on
 * this role, staff quietly fail to appear in every Owner picker in the app,
 * which looks like a data bug rather than a permissions one.
 *
 * Deliberately absent: edit_posts, edit_pages, manage_options,
 * edit_theme_options. The SEO plugin and effectively every other plugin gate on
 * one of those, so leaving them out *is* the "no Posts, Pages, theme settings
 * or SEO" requirement — there is no deny-list to maintain.
 */
function pcm_crm_staff_capabilities() {
	return apply_filters( 'pcm_crm_staff_capabilities', array(
		'read'         => true,
		PCM_CRM_CAP    => true,
		// wp.media's browse and upload views both gate on this — without it
		// the Media area (includes/permissions.php) can grant view/edit all
		// it likes and the front-end Media page (public/staff-media.php)
		// would still show an empty, un-uploadable library. It does not grant
		// delete_posts, which is what deleting an attachment actually needs —
		// exactly why the Media area offers no delete action of its own.
		'upload_files' => true,
	) );
}

/**
 * Create the role, and reconcile its capabilities when they change.
 *
 * On init rather than activation, because an rsync deploy never fires the
 * activation hook and roles live in the database. add_role() is a no-op once
 * the role exists, so a capability added in a later version would never reach
 * an existing install — hence the version stamp, the same shape
 * PCM_CRM_Schema::VERSION already uses for tables. Bump it on any change to
 * the list above.
 *
 * Never remove_role(): that unassigns every user who holds it.
 */
function pcm_crm_ensure_staff_role() {
	if ( (int) get_option( 'pcm_crm_role_version', 0 ) === PCM_CRM_ROLE_VERSION ) {
		return;
	}

	$pcm_caps = pcm_crm_staff_capabilities();
	$pcm_role = get_role( PCM_CRM_STAFF_ROLE );

	if ( ! $pcm_role ) {
		add_role( PCM_CRM_STAFF_ROLE, __( 'Staff', 'pcm-crm' ), $pcm_caps );
	} else {
		foreach ( $pcm_caps as $pcm_cap => $pcm_granted ) {
			$pcm_role->add_cap( $pcm_cap );
		}
	}

	update_option( 'pcm_crm_role_version', PCM_CRM_ROLE_VERSION );
}
add_action( 'init', 'pcm_crm_ensure_staff_role' );

/**
 * Grant the cap to any administrator who predates the plugin.
 *
 * Roles are stored in the database, so an admin created before activation —
 * or on a site where activation ran before this cap existed — would otherwise
 * never receive it. Cheap enough to check on every admin load. Stays admin-only
 * (is_admin()) even now that there is a front-end host, because it only ever
 * grants to administrators, who are the one audience still expected to reach
 * wp-admin.
 */
function pcm_crm_ensure_capabilities() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! current_user_can( PCM_CRM_CAP ) ) {
		pcm_crm_add_capabilities();
	}
}
add_action( 'admin_init', 'pcm_crm_ensure_capabilities' );

/**
 * May this person reach the app at all.
 *
 * Deliberately the *union* rather than a specific question. Every caller that
 * once meant "may they use the CRM" now asks pcm_crm_can() for the area and
 * action it actually cares about; what is left for this to answer is whether
 * there is any point showing them a menu, a shell or a bootstrap payload.
 *
 * Someone granted Projects alone still passes here and is refused by every
 * CRM route individually, which is what stops a PM-only user landing on a
 * Projects screen whose /bootstrap call was rejected.
 */
function pcm_crm_user_can() {
	foreach ( array_keys( pcm_crm_permission_areas() ) as $pcm_area ) {
		if ( 'media' === $pcm_area ) { continue; }

		if ( pcm_crm_can( $pcm_area, 'view' ) ) { return true; }
	}

	return false;
}

/**
 * Answers PCM_CRM_SETTINGS_CAP from the permission matrix, for options.php.
 *
 * options.php hard-requires manage_options to save a settings form unless an
 * option_page_capability_{$group} filter names a different capability — but
 * that filter can only name a capability *string*, not call pcm_crm_can()
 * directly. This is the one place that translates between the two: every
 * option_page_capability_* filter this plugin registers (see
 * pcm_crm_register_setting() in access-settings.php) answers with
 * PCM_CRM_SETTINGS_CAP, and this is what decides whether the current user
 * actually holds it.
 *
 * This filter *is* user_has_cap, which current_user_can() and user_can() both
 * run through for every capability check, not only this one — so calling
 * either of those back out from inside it re-enters the same filter. The
 * administrator case is answered straight from $pcm_allcaps, which is the
 * array WordPress already computed from roles before any filter ran, so that
 * branch never recurses at all. The non-administrator case does still call
 * pcm_crm_can(), which asks pcm_crm_is_administrator() the same "are they an
 * administrator" question a second time — but keyed and reentrancy-guarded
 * per user id there (includes/permissions.php), so that nested ask answers
 * false immediately instead of asking current_user_can() again. The recursion
 * is bounded by that guard, not by anything here; do not remove it from
 * permissions.php without keeping this safe another way.
 *
 * Getting this wrong is a stack overflow on every admin page — a white-
 * screened site — and there is no local PHP stack to catch it before staging.
 */
function pcm_crm_map_settings_cap( $pcm_allcaps, $pcm_caps, $pcm_args, $pcm_user ) {
	if ( ! empty( $pcm_allcaps['manage_options'] ) ) {
		$pcm_allcaps[ PCM_CRM_SETTINGS_CAP ] = true;
		return $pcm_allcaps;
	}

	$pcm_allcaps[ PCM_CRM_SETTINGS_CAP ] = pcm_crm_can( 'settings', 'edit', $pcm_user->ID );

	return $pcm_allcaps;
}
add_filter( 'user_has_cap', 'pcm_crm_map_settings_cap', 10, 4 );
