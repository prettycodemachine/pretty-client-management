<?php
/**
 * My Profile — the front-end host's answer to /wp-admin/profile.php, which
 * staff can never reach (the lockout deliberately does not exempt it, see
 * includes/roles.php). Their own name and password have to be editable from
 * somewhere, or pcm_crm_user_label() falls back to their login — the email
 * address — on every record they touch.
 *
 * Deliberately NOT a registered Setup page (pcm_crm_register_setup_page()):
 * every one of those is gated on pcm_crm_can( 'settings', 'view' ) and draws
 * inside the full CRM Settings frame, its nav included — right for
 * configuration, wrong for a page that has nothing to do with the Settings
 * permission and that a Sales-only staff member with no Settings access at
 * all still needs. It gets its own route (/profile/, not /settings/profile/)
 * and its own small frame instead, so nobody without Settings access sees a
 * full Settings navigation they cannot use any part of.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * No dedicated rewrite rule needed: the generic ^base/([^/]+)/?$ rule
 * (pcm_crm_add_front_rewrites(), public/staff.php) already turns /profile/
 * into pcm_crm_screen=profile the same way it does for any other single-
 * segment slug — pcm_crm_front_route() (public/staff-template.php) is what
 * recognises that value and dispatches here.
 */
function pcm_crm_render_my_profile() {
	$pcm_user = wp_get_current_user();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	$pcm_result = isset( $_GET['pcm_crm_profile'] ) ? sanitize_key( wp_unslash( $_GET['pcm_crm_profile'] ) ) : '';

	$pcm_messages = array(
		'saved'              => array( 'success', __( 'Your profile was updated.', 'pcm-crm' ) ),
		'email-invalid'      => array( 'error', __( 'Enter a valid email address.', 'pcm-crm' ) ),
		'email-taken'        => array( 'error', __( 'That email address already belongs to another account.', 'pcm-crm' ) ),
		'password-mismatch'  => array( 'error', __( 'The two passwords did not match. Nothing was changed.', 'pcm-crm' ) ),
		'password-short'     => array( 'error', __( 'Choose a password at least 12 characters long.', 'pcm-crm' ) ),
	);
	?>
	<div class="pcm-crm pcm-crm-front pcm-crm-my-profile" data-theme="<?php echo esc_attr( pcm_crm_theme() ); ?>">
		<div class="pcm-crm-head">
			<div><h1><?php esc_html_e( 'My Profile', 'pcm-crm' ); ?></h1></div>
		</div>

		<?php if ( $pcm_result && isset( $pcm_messages[ $pcm_result ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $pcm_messages[ $pcm_result ][0] ); ?> is-dismissible">
				<p><?php echo esc_html( $pcm_messages[ $pcm_result ][1] ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pcm-crm-card">
			<input type="hidden" name="action" value="pcm_crm_save_my_profile">
			<?php wp_nonce_field( 'pcm_crm_my_profile', 'pcm_crm_my_profile_nonce' ); ?>

			<h2><?php esc_html_e( 'Name and email', 'pcm-crm' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pcm-crm-profile-first"><?php esc_html_e( 'First name', 'pcm-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" id="pcm-crm-profile-first" name="first_name" value="<?php echo esc_attr( $pcm_user->first_name ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="pcm-crm-profile-last"><?php esc_html_e( 'Last name', 'pcm-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" id="pcm-crm-profile-last" name="last_name" value="<?php echo esc_attr( $pcm_user->last_name ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="pcm-crm-profile-email"><?php esc_html_e( 'Email', 'pcm-crm' ); ?></label></th>
					<td><input type="email" class="regular-text" id="pcm-crm-profile-email" name="email" value="<?php echo esc_attr( $pcm_user->user_email ); ?>" required></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Change password', 'pcm-crm' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Leave both fields blank to keep your current password.', 'pcm-crm' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pcm-crm-profile-pass1"><?php esc_html_e( 'New password', 'pcm-crm' ); ?></label></th>
					<td><input type="password" class="regular-text" id="pcm-crm-profile-pass1" name="password" autocomplete="new-password"></td>
				</tr>
				<tr>
					<th scope="row"><label for="pcm-crm-profile-pass2"><?php esc_html_e( 'Confirm new password', 'pcm-crm' ); ?></label></th>
					<td><input type="password" class="regular-text" id="pcm-crm-profile-pass2" name="password_confirm" autocomplete="new-password"></td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Changes', 'pcm-crm' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * What is wrong with a submitted change, if anything — a plain function
 * separate from the handler so it is checkable without going through
 * wp_safe_redirect()'s exit, the same shape pcm_crm_clean_user_access()
 * (includes/access-settings.php) and pcm_crm_staff_redirect_target()
 * (includes/roles.php) already use.
 *
 * No password-strength meter here — that is wp-admin's own user-profile.js,
 * which brings a chain of admin-only dependencies (nonce-based AJAX
 * strength scoring, wp.updates) for a single field. A plain minimum length
 * is the honest trade against that weight, not an oversight.
 */
function pcm_crm_my_profile_validation_error( $pcm_user_id, $pcm_email, $pcm_pass1, $pcm_pass2 ) {
	if ( ! is_email( $pcm_email ) ) {
		return 'email-invalid';
	}

	$pcm_existing = get_user_by( 'email', $pcm_email );

	if ( $pcm_existing && (int) $pcm_existing->ID !== (int) $pcm_user_id ) {
		return 'email-taken';
	}

	if ( '' !== $pcm_pass1 || '' !== $pcm_pass2 ) {
		if ( $pcm_pass1 !== $pcm_pass2 ) {
			return 'password-mismatch';
		}

		if ( strlen( $pcm_pass1 ) < 12 ) {
			return 'password-short';
		}
	}

	return '';
}

function pcm_crm_handle_save_my_profile() {
	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'You must be logged in.', 'pcm-crm' ), 403 );
	}

	if (
		! isset( $_POST['pcm_crm_my_profile_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_POST['pcm_crm_my_profile_nonce'] ), 'pcm_crm_my_profile' )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	// The account being edited is always the one logged in — never a posted
	// id, or one staff member could edit another's by changing a hidden
	// field. There is no "whose profile" question here at all.
	$pcm_user_id = get_current_user_id();
	$pcm_back    = pcm_crm_front_base_url() . 'profile/';

	$pcm_first = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
	$pcm_last  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
	$pcm_email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$pcm_pass1 = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
	$pcm_pass2 = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';

	$pcm_error = pcm_crm_my_profile_validation_error( $pcm_user_id, $pcm_email, $pcm_pass1, $pcm_pass2 );

	if ( $pcm_error ) {
		wp_safe_redirect( add_query_arg( 'pcm_crm_profile', $pcm_error, $pcm_back ) );
		exit;
	}

	$pcm_update = array(
		'ID'         => $pcm_user_id,
		'first_name' => $pcm_first,
		'last_name'  => $pcm_last,
		'user_email' => $pcm_email,
	);

	if ( '' !== $pcm_pass1 ) {
		$pcm_update['user_pass'] = $pcm_pass1;
	}

	wp_update_user( $pcm_update );

	wp_safe_redirect( add_query_arg( 'pcm_crm_profile', 'saved', $pcm_back ) );
	exit;
}
add_action( 'admin_post_pcm_crm_save_my_profile', 'pcm_crm_handle_save_my_profile' );
