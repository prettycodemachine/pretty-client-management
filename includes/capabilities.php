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
 * The gate used by every screen and REST route.
 */
function pcm_crm_user_can() {
	return current_user_can( PCM_CRM_CAP ) || current_user_can( 'manage_options' );
}
