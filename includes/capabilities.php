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
 * Grant the cap to any administrator who predates the plugin.
 *
 * Roles are stored in the database, so an admin created before activation —
 * or on a site where activation ran before this cap existed — would otherwise
 * never receive it. Cheap enough to check on every admin load.
 */
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
		'read'      => true,
		PCM_CRM_CAP => true,
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
