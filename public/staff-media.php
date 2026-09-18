<?php
/**
 * The Media area's front-end door: /staff/media/, a full-page wp.media browse
 * frame. Front-end only, per the plan this feature came from — an
 * administrator already has the real Media Library in wp-admin, and staff
 * are meant to reach it only here. No dedicated rewrite rule needed, the same
 * reasoning as My Profile (public/staff-profile.php): the generic
 * ^base/([^/]+)/?$ pattern already turns /media/ into pcm_crm_screen=media.
 *
 * Unlike My Profile, this screen IS a real permission area (media,
 * includes/permissions.php) — pcm_crm_front_route() (public/staff-template.php)
 * gates it on pcm_crm_can( 'media', 'view' ) the same way it gates every
 * ordinary CRM screen, denying with pcm_crm_front_deny() rather than showing
 * a live-looking door that refuses on arrival.
 *
 * One real gap, stated rather than hidden: WordPress's own upload_files
 * capability is what actually controls whether wp.media's frame offers an
 * Upload tab, and capabilities are granted per role, not per CRM permission
 * area — so a staff member holding only media/view (not edit) still
 * technically has upload_files, the same baseline every staff member gets
 * (includes/capabilities.php), and can upload through this frame regardless
 * of what the matrix says. Building a real distinction would mean mapping a
 * second WordPress capability through user_has_cap the way
 * PCM_CRM_SETTINGS_CAP already does — the same recursion risk documented
 * there — for one field's worth of extra precision. Not done this round.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_render_media_library() {
	// Same shape as every other screen's gate (pcm_crm_screen(), admin/menu.php):
	// a denial here is pcm_crm_front_deny(), never wp_die() — this host has no
	// wp-admin fallback to die into.
	if ( ! pcm_crm_can( 'media', 'view' ) ) {
		pcm_crm_front_deny( __( 'You do not have access to the Media Library.', 'pcm-crm' ) );
		return;
	}

	$pcm_can_edit = pcm_crm_can( 'media', 'edit' );
	?>
	<div class="pcm-crm pcm-crm-front pcm-crm-media" data-theme="<?php echo esc_attr( pcm_crm_theme() ); ?>">
		<div class="pcm-crm-head">
			<div>
				<h1><?php esc_html_e( 'Media Library', 'pcm-crm' ); ?></h1>
				<p class="pcm-crm-sub">
					<?php
					echo $pcm_can_edit
						? esc_html__( 'Every file uploaded across the site. Browse, search, and add new ones.', 'pcm-crm' )
						: esc_html__( 'Every file uploaded across the site.', 'pcm-crm' );
					?>
				</p>
			</div>
			<div class="pcm-crm-head-actions">
				<button type="button" class="pcm-btn pcm-btn-primary" data-role="media-open">
					<?php echo $pcm_can_edit ? esc_html__( 'Open Media Library', 'pcm-crm' ) : esc_html__( 'Browse Media Library', 'pcm-crm' ); ?>
				</button>
			</div>
		</div>
		<div class="pcm-crm-body pcm-crm-media-placeholder" data-role="media-placeholder">
			<span class="dashicons dashicons-admin-media" aria-hidden="true"></span>
			<p><?php esc_html_e( 'Opens in a panel above this page.', 'pcm-crm' ); ?></p>
		</div>
	</div>
	<?php
}
