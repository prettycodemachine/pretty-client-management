<?php
/**
 * CRM Access on WordPress's own user screens — Add New User (user-new.php)
 * and Edit User (user-edit.php / profile.php) — so provisioning a new staff
 * member's Profile and Permission Sets never needs a second trip to Staff
 * Access at all.
 *
 * This is deliberately not a parallel implementation: the fields it renders
 * are pcm_crm_render_access_fields() (includes/access-settings.php) — the
 * exact markup Staff Access's own form uses, posting the exact same field
 * names — and the save path here calls the exact same
 * pcm_crm_clean_user_access() and pcm_crm_assign_permissions(). Two entry
 * points into one function each is what keeps "assign it from the User
 * screen" and "assign it from Staff Access" from ever disagreeing about
 * what actually landed in the database — there is only one thing that could.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * What to show on a re-rendered Add New User form — no $pcm_user_id exists
 * yet to read a stored assignment from, so this is the only screen where
 * "what was actually chosen" has to come from the request instead.
 *
 * user-new.php re-renders in the *same* request when wp_insert_user() or its
 * own validation refuses the submission (a taken email, say), with $_POST
 * still carrying everything that was chosen. A plain function, separate from
 * the markup, so this is checkable directly rather than by scraping rendered
 * HTML — checked() itself renders nothing under the test stubs, the same way
 * every other stub in this suite defaults to the shape that costs least to
 * fake, so a radio's checked state was never going to be provable that way.
 */
function pcm_crm_new_user_access_from_post() {
	$pcm_posted_role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

	if ( PCM_CRM_STAFF_ROLE !== $pcm_posted_role ) {
		return array( 'is_staff' => false, 'profile' => '', 'sets' => array() );
	}

	$pcm_clean = pcm_crm_clean_user_access( wp_unslash( $_POST ) );

	return array( 'is_staff' => true, 'profile' => $pcm_clean['profile'], 'sets' => $pcm_clean['sets'] );
}

/**
 * The row (a live summary plus a button) and the dialog behind it. Shown on
 * both user-new.php (no $pcm_user_id yet, nothing assigned) and
 * user-edit.php/profile.php (existing assignment, if any) — the same call
 * either way, since pcm_crm_render_access_fields() already treats "no
 * profile" as a normal, valid state.
 *
 * Gated on current_user_can( 'promote_users' ): that is WordPress's own
 * condition for showing the Role field at all on these screens, and this
 * section is meaningless to someone who cannot change a role to Staff in
 * the first place.
 */
function pcm_crm_render_user_access_section( $pcm_user_id = 0 ) {
	if ( ! current_user_can( 'promote_users' ) || ! pcm_crm_can( 'settings', 'edit' ) ) {
		return;
	}

	if ( $pcm_user_id ) {
		$pcm_profile_key = pcm_crm_user_profile_key( $pcm_user_id );
		$pcm_set_keys    = pcm_crm_user_set_keys( $pcm_user_id );
		$pcm_user        = get_userdata( $pcm_user_id );
		$pcm_is_staff    = $pcm_user && in_array( PCM_CRM_STAFF_ROLE, (array) $pcm_user->roles, true );
	} else {
		$pcm_posted       = pcm_crm_new_user_access_from_post();
		$pcm_is_staff     = $pcm_posted['is_staff'];
		$pcm_profile_key  = $pcm_posted['profile'];
		$pcm_set_keys     = $pcm_posted['sets'];
	}
	?>
	<table class="form-table" role="presentation" id="pcm-crm-user-access-row" data-staff-role="<?php echo esc_attr( PCM_CRM_STAFF_ROLE ); ?>" <?php echo $pcm_is_staff ? '' : 'hidden'; ?>>
		<tr>
			<th scope="row"><?php esc_html_e( 'CRM Access', 'pcm-crm' ); ?></th>
			<td>
				<p id="pcm-crm-user-access-summary"></p>
				<button type="button" class="button" id="pcm-crm-user-access-open"><?php esc_html_e( 'Choose CRM Access', 'pcm-crm' ); ?></button>
			</td>
		</tr>
	</table>

	<dialog id="pcm-crm-user-access-dialog">
		<h2><?php esc_html_e( 'CRM Access', 'pcm-crm' ); ?></h2>
		<p class="description"><?php esc_html_e( 'What this person can reach once they sign in as staff.', 'pcm-crm' ); ?></p>
		<?php pcm_crm_render_access_fields( $pcm_profile_key, $pcm_set_keys ); ?>
		<button type="button" class="button button-primary" id="pcm-crm-user-access-done"><?php esc_html_e( 'Done', 'pcm-crm' ); ?></button>
	</dialog>
	<?php
}

/**
 * user_new_form fires for both 'add-new-user' and, on multisite,
 * 'add-existing-user' (inviting an existing account to the site rather than
 * creating one) — only the former actually posts through wp_insert_user(),
 * so only that one gets this section.
 */
function pcm_crm_render_user_access_for_new_user( $pcm_type ) {
	if ( 'add-new-user' !== $pcm_type ) {
		return;
	}

	pcm_crm_render_user_access_section();
}
add_action( 'user_new_form', 'pcm_crm_render_user_access_for_new_user' );

function pcm_crm_render_user_access_for_existing_user( $pcm_user ) {
	pcm_crm_render_user_access_section( $pcm_user->ID );
}
add_action( 'show_user_profile', 'pcm_crm_render_user_access_for_existing_user' );
add_action( 'edit_user_profile', 'pcm_crm_render_user_access_for_existing_user' );

/**
 * Save the fields alongside whichever native form posted them.
 *
 * user_register (a brand-new user, fired from inside wp_insert_user()) and
 * edit_user_profile_update()/personal_options_update() (an existing one,
 * fired from wp-admin/user-edit.php) do not agree on timing: wp_insert_user()
 * applies the posted role before firing user_register, but user-edit.php
 * fires its two hooks *before* calling edit_user() — the role is not yet
 * written when this runs there. Reading get_userdata( $pcm_user_id )->roles
 * would therefore see the *previous* role on an edit, not the one just
 * chosen. $_POST['role'] is the one thing both paths agree on — it is what
 * the native Role field itself posts, on both screens, at the moment this
 * fires — so it is read directly instead of trusting the database to
 * already reflect this same request.
 */
function pcm_crm_save_user_access_from_native_screen( $pcm_user_id ) {
	if ( ! current_user_can( 'promote_users' ) || ! pcm_crm_can( 'settings', 'edit' ) ) {
		return;
	}

	$pcm_role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

	if ( PCM_CRM_STAFF_ROLE !== $pcm_role ) {
		return;
	}

	$pcm_clean = pcm_crm_clean_user_access( wp_unslash( $_POST ) );

	pcm_crm_assign_permissions( $pcm_user_id, $pcm_clean['profile'], $pcm_clean['sets'] );
}
add_action( 'user_register', 'pcm_crm_save_user_access_from_native_screen' );
add_action( 'personal_options_update', 'pcm_crm_save_user_access_from_native_screen' );
add_action( 'edit_user_profile_update', 'pcm_crm_save_user_access_from_native_screen' );

/**
 * The dialog and the role-change wiring only matter on the three screens
 * that can render pcm_crm_render_user_access_section() — everywhere else
 * this would be dead weight on every other wp-admin page.
 */
function pcm_crm_user_access_assets( $pcm_hook ) {
	if ( ! in_array( $pcm_hook, array( 'user-new.php', 'user-edit.php', 'profile.php' ), true ) ) {
		return;
	}

	if ( ! current_user_can( 'promote_users' ) || ! pcm_crm_can( 'settings', 'edit' ) ) {
		return;
	}

	wp_enqueue_style( 'pcm-crm-user-access', pcm_crm_asset( 'user-access.css' ), array(), null );
	wp_enqueue_script( 'pcm-crm-user-access', pcm_crm_asset( 'user-access.js' ), array(), null, true );
}
add_action( 'admin_enqueue_scripts', 'pcm_crm_user_access_assets' );
