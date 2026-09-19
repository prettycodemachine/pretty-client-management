<?php
/**
 * The staff-facing half of the Client Portal: the pcm_client role, inviting a
 * contact, keeping a client out of wp-admin, and branding wp-login.php.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A role with no capabilities at all — a client's only surface is the portal
 * page and its REST routes, both of which check pcm_crm_portal_context()
 * directly rather than any WordPress capability. Roles are stored in the
 * database, so a site that switched the module on after this file last ran
 * would otherwise never get the role created; checked cheaply on admin_init
 * the same way pcm_crm_ensure_capabilities() re-grants PCM_CRM_CAP.
 */
function pcm_crm_portal_ensure_role() {
	if ( ! get_role( 'pcm_client' ) ) {
		add_role( 'pcm_client', __( 'Client (Portal)', 'pcm-crm' ), array() );
	}
}
add_action( 'init', 'pcm_crm_portal_ensure_role' );

/**
 * A client has nothing to do in wp-admin — their whole app is the portal
 * page — so they never see it. AJAX and the REST API are untouched: the
 * portal itself is REST, and this only ever fires for wp-admin page loads.
 */
function pcm_crm_portal_redirect_from_admin() {
	if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	if ( ! is_user_logged_in() || ! in_array( 'pcm_client', (array) wp_get_current_user()->roles, true ) ) {
		return;
	}

	wp_safe_redirect( home_url( '/' ) );
	exit;
}
add_action( 'admin_init', 'pcm_crm_portal_redirect_from_admin' );

/**
 * Give wp-login.php the CRM's own mark, but only when it is plainly a portal
 * visit — a redirect_to pointing at the portal page — so the staff admin
 * login screen is untouched the rest of the time.
 */
function pcm_crm_portal_is_login_visit() {
	return pcm_crm_is_branded_login_visit( pcm_crm_portal_url() );
}

function pcm_crm_portal_login_logo_url() {
	if ( pcm_crm_portal_is_login_visit() ) {
		return home_url( '/' );
	}
}
add_filter( 'login_headerurl', 'pcm_crm_portal_login_logo_url' );

function pcm_crm_portal_login_logo_text() {
	if ( pcm_crm_portal_is_login_visit() ) {
		return get_bloginfo( 'name' );
	}
}
add_filter( 'login_headertext', 'pcm_crm_portal_login_logo_text' );

function pcm_crm_portal_login_style() {
	if ( ! pcm_crm_portal_is_login_visit() ) {
		return;
	}

	pcm_crm_branded_login_style();
}
add_action( 'login_enqueue_scripts', 'pcm_crm_portal_login_style' );

/**
 * Create or reuse a WP user for a Contact, and email them a way in.
 *
 * Reuses an existing pcm_client user for the same email — a staff member
 * clicking Invite a second time (after a lost invite, say) resends rather
 * than colliding on wp_insert_user(). An email that already belongs to a
 * different kind of WordPress user is refused outright: this flow only ever
 * creates or touches a client login, never repurposes someone else's account.
 */
function pcm_crm_portal_invite_contact( $pcm_contact_id ) {
	if ( ! pcm_crm_module_active( 'portal' ) ) {
		return new WP_Error( 'pcm_crm_portal_off', __( 'The Client Portal module is switched off.', 'pcm-crm' ) );
	}

	$pcm_contact = pcm_crm_contacts()->get( $pcm_contact_id );

	if ( ! $pcm_contact ) {
		return new WP_Error( 'pcm_crm_no_contact', __( 'That contact no longer exists.', 'pcm-crm' ) );
	}

	if ( ! is_email( $pcm_contact['email'] ) ) {
		return new WP_Error( 'pcm_crm_portal_no_email', __( 'This contact has no email address to invite.', 'pcm-crm' ) );
	}

	if ( ! pcm_crm_portal_url() ) {
		return new WP_Error( 'pcm_crm_portal_no_page', __( 'Choose the portal page under PCM Settings › Client Portal first.', 'pcm-crm' ) );
	}

	pcm_crm_portal_ensure_role();

	$pcm_user = get_user_by( 'email', $pcm_contact['email'] );

	if ( $pcm_user && ! in_array( 'pcm_client', (array) $pcm_user->roles, true ) ) {
		return new WP_Error( 'pcm_crm_portal_email_taken', __( 'That email already belongs to a different kind of account on this site.', 'pcm-crm' ) );
	}

	if ( ! $pcm_user ) {
		$pcm_username = sanitize_user( $pcm_contact['email'], true );

		$pcm_user_id = wp_insert_user( array(
			'user_login' => $pcm_username,
			'user_email' => $pcm_contact['email'],
			'user_pass'  => wp_generate_password( 32 ),
			'first_name' => $pcm_contact['first_name'],
			'last_name'  => $pcm_contact['last_name'],
			'role'       => 'pcm_client',
		) );

		if ( is_wp_error( $pcm_user_id ) ) {
			return $pcm_user_id;
		}

		$pcm_user = get_user_by( 'id', $pcm_user_id );
	}

	update_user_meta( $pcm_user->ID, 'pcm_crm_contact_id', $pcm_contact_id );

	// Written directly rather than through pcm_crm_contacts()->update(): the
	// column is readonly on the field map precisely so nothing else can set it
	// by hand, the same reason stage history writes past the model in
	// model-history.php.
	global $wpdb;
	$wpdb->update( PCM_CRM_Schema::contacts(), array( 'portal_user_id' => $pcm_user->ID ), array( 'id' => $pcm_contact_id ) );

	$pcm_key = get_password_reset_key( $pcm_user );

	if ( is_wp_error( $pcm_key ) ) {
		return $pcm_key;
	}

	$pcm_url = add_query_arg( array(
		'action'      => 'rp',
		'key'         => $pcm_key,
		'login'       => rawurlencode( $pcm_user->user_login ),
		'redirect_to' => rawurlencode( pcm_crm_portal_url() ),
	), wp_login_url() );

	$pcm_body = pcm_crm_email_wrapper( pcm_crm_format_body( sprintf(
		/* translators: 1: contact's first name, 2: a set-password link */
		__( "Hi %1\$s,\n\nYou now have access to your client portal, where you can see your project's time, RAID log and documents, and raise Help Tickets.\n\nSet your password to get started: %2\$s", 'pcm-crm' ),
		$pcm_contact['first_name'] ? $pcm_contact['first_name'] : $pcm_contact['email'],
		esc_url_raw( $pcm_url )
	) ) );

	add_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );
	$pcm_sent = wp_mail( $pcm_contact['email'], __( 'You’re invited to your client portal', 'pcm-crm' ), $pcm_body );
	remove_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );

	if ( ! $pcm_sent ) {
		return new WP_Error( 'pcm_crm_mail_failed', __( 'The account was created, but the invitation email could not be sent.', 'pcm-crm' ) );
	}

	return array( 'invited' => true, 'portal_user_id' => (int) $pcm_user->ID );
}

function pcm_crm_portal_register_invite_route() {
	register_rest_route( PCM_CRM_REST::NS, '/contacts/(?P<pcm_id>\d+)/invite-portal', array(
		'methods'             => 'POST',
		'callback'            => 'pcm_crm_portal_rest_invite',
		'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
	) );
}
add_action( 'rest_api_init', 'pcm_crm_portal_register_invite_route' );

function pcm_crm_portal_rest_invite( WP_REST_Request $pcm_request ) {
	$pcm_result = pcm_crm_portal_invite_contact( absint( $pcm_request->get_url_params()['pcm_id'] ) );

	if ( is_wp_error( $pcm_result ) ) {
		$pcm_result->add_data( array( 'status' => 400 ) );
		return $pcm_result;
	}

	return rest_ensure_response( $pcm_result );
}

/**
 * The Invite to Portal button on a Contact's record page — a module adding a
 * record action without editing crm.js, via registerRecordActions().
 */
function pcm_crm_portal_admin_assets( $pcm_hook ) {
	if ( ! pcm_crm_is_crm_screen( $pcm_hook ) ) {
		return;
	}

	if ( false !== strpos( $pcm_hook, PCM_CRM_SETUP_SLUG ) ) {
		return;
	}

	wp_enqueue_script( 'pcm-crm-portal-admin', pcm_crm_asset( 'portal-admin.js' ), array( 'pcm-crm' ), null, true );
}
add_action( 'admin_enqueue_scripts', 'pcm_crm_portal_admin_assets' );

/* PCM Settings: which page carries the portal -------------------------------- */

function pcm_crm_portal_setup_group( $pcm_groups ) {
	$pcm_groups['portal'] = array(
		'label'       => __( 'Client Portal', 'pcm-crm' ),
		'description' => __( 'Which page holds the portal, and inviting clients in.', 'pcm-crm' ),
		'module'      => 'portal',
	);

	return $pcm_groups;
}
add_filter( 'pcm_crm_setup_groups', 'pcm_crm_portal_setup_group' );

pcm_crm_register_setup_page( 'portal-page', array(
	'group'       => 'portal',
	'label'       => __( 'Portal Page', 'pcm-crm' ),
	'description' => __( 'The page carrying [pcm_client_portal]. A client is sent here after setting their password, and every Invite link points at it.', 'pcm-crm' ),
	'render'      => 'pcm_crm_portal_render_setup_page',
	'order'       => 10,
) );

function pcm_crm_portal_register_settings() {
	pcm_crm_register_setting( 'pcm_crm_portal_settings', 'pcm_crm_portal_page_id', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
}
add_action( 'admin_init', 'pcm_crm_portal_register_settings' );

/**
 * The portal page's URL, or '' if none is chosen yet — everything that needs
 * to point a client somewhere (an invite email, a login redirect, the
 * wp-login branding check) goes through this rather than assuming a slug.
 */
function pcm_crm_portal_url() {
	$pcm_page_id = (int) get_option( 'pcm_crm_portal_page_id', 0 );
	$pcm_url     = $pcm_page_id ? get_permalink( $pcm_page_id ) : '';

	return $pcm_url ? $pcm_url : '';
}

function pcm_crm_portal_render_setup_page() {
	$pcm_page_id = (int) get_option( 'pcm_crm_portal_page_id', 0 );
	$pcm_pages   = get_pages( array( 'sort_column' => 'post_title' ) );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_portal_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pcm-portal-page"><?php esc_html_e( 'Portal page', 'pcm-crm' ); ?></label></th>
				<td>
					<select id="pcm-portal-page" name="pcm_crm_portal_page_id">
						<option value="0"><?php esc_html_e( '— Choose a page —', 'pcm-crm' ); ?></option>
						<?php foreach ( $pcm_pages as $pcm_page ) : ?>
							<option value="<?php echo esc_attr( $pcm_page->ID ); ?>" <?php selected( $pcm_page_id, $pcm_page->ID ); ?>>
								<?php echo esc_html( $pcm_page->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Add [pcm_client_portal] to this page’s content. A blank page with just that shortcode is the usual choice.', 'pcm-crm' ); ?>
					</p>
					<?php if ( ! pcm_crm_portal_url() ) : ?>
						<p class="description">
							<strong><?php esc_html_e( 'No portal page is set yet — invitations cannot be sent until one is.', 'pcm-crm' ); ?></strong>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}
