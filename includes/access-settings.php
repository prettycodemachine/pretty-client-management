<?php
/**
 * PCM Settings › Platform: Profiles, Permission Sets, and who holds them.
 *
 * The model itself — areas, actions, resolution — lives in permissions.php and
 * knows nothing about screens. This is only the editor over it: three Setup
 * pages, following the project-type pattern in pm-settings.php rather than
 * options.php, because a save here can be refused for reasons a sanitize
 * callback has no good way to express (a profile still assigned to somebody,
 * an unrecognised area or action).
 *
 * Profiles and Permission Sets share one shape — a label, a description, and a
 * grants matrix — so they share one rendering and save engine, parameterised
 * by pcm_crm_access_kind(). Users is a different shape (a person, not a
 * definition) and gets its own.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

pcm_crm_register_setup_page( 'access-profiles', array(
	'group'       => 'platform',
	'label'       => __( 'Profiles', 'pcm-crm' ),
	'description' => __( 'The baseline every staff member holds — exactly one each. What Permission Extensions add to.', 'pcm-crm' ),
	'render'      => 'pcm_crm_render_access_profiles_page',
	'order'       => 30,
) );

pcm_crm_register_setup_page( 'access-permission-sets', array(
	'group'       => 'platform',
	'label'       => __( 'Permission Extensions', 'pcm-crm' ),
	'description' => __( 'Extra access on top of a profile. A set never takes access away, only adds it.', 'pcm-crm' ),
	'render'      => 'pcm_crm_render_access_permission_sets_page',
	'order'       => 40,
) );

pcm_crm_register_setup_page( 'access-users', array(
	'group'       => 'platform',
	'label'       => __( 'Staff Access', 'pcm-crm' ),
	'description' => __( 'Which profile and permission extensions each staff member holds.', 'pcm-crm' ),
	'render'      => 'pcm_crm_render_access_users_page',
	'order'       => 50,
) );

/* ---------------------------------------------------------------------------
   Profiles and Permission Sets: one editor, two kinds
   --------------------------------------------------------------------------- */

/**
 * Everything the shared editor needs to know to tell the two kinds apart.
 *
 * A second kind should mean a second entry here, not a second copy of the
 * functions below — that duplication is exactly how the two would drift.
 */
function pcm_crm_access_kind( $pcm_kind ) {
	$pcm_kinds = array(
		'profile' => array(
			'option'    => PCM_CRM_PROFILES_OPTION,
			'reader'    => 'pcm_crm_profiles',
			'setup_key' => 'access-profiles',
			'nonce'     => 'pcm_crm_access_profile',
			'action'    => 'pcm_crm_save_profile',
			'del_action' => 'pcm_crm_delete_profile',
			'query_arg' => 'profile',
			'singular'  => __( 'Profile', 'pcm-crm' ),
			'plural'    => __( 'Profiles', 'pcm-crm' ),
			'new_cta'   => __( 'New Profile', 'pcm-crm' ),
			'blurb'     => __( 'Every staff member holds exactly one of these as their baseline. Choose the widest access most people in this profile should have — Permission Extensions are for the exceptions.', 'pcm-crm' ),
		),
		'set' => array(
			'option'    => PCM_CRM_SETS_OPTION,
			'reader'    => 'pcm_crm_permission_sets',
			'setup_key' => 'access-permission-sets',
			'nonce'     => 'pcm_crm_access_set',
			'action'    => 'pcm_crm_save_permission_set',
			'del_action' => 'pcm_crm_delete_permission_set',
			'query_arg' => 'set',
			'singular'  => __( 'Permission Extension', 'pcm-crm' ),
			'plural'    => __( 'Permission Extensions', 'pcm-crm' ),
			'new_cta'   => __( 'New Permission Extension', 'pcm-crm' ),
			'blurb'     => __( 'A named bundle of extra access. Assign one to anybody who needs more than their profile without changing the profile for everybody who holds it.', 'pcm-crm' ),
		),
	);

	return isset( $pcm_kinds[ $pcm_kind ] ) ? $pcm_kinds[ $pcm_kind ] : null;
}

function pcm_crm_render_access_profiles_page() { pcm_crm_render_access_definitions_page( 'profile' ); }
function pcm_crm_render_access_permission_sets_page() { pcm_crm_render_access_definitions_page( 'set' ); }

/**
 * Clean a posted definition, or explain why it was refused.
 *
 * The key itself is not user-editable once created — it is derived from the
 * label on first save and fixed after, the same contract project types
 * already give: a definition's key is what a user's assignment points at, and
 * a definition that could be renamed out from under its own key would orphan
 * every assignment silently.
 */
function pcm_crm_clean_access_definition( array $pcm_post ) {
	$pcm_label = isset( $pcm_post['label'] ) ? sanitize_text_field( $pcm_post['label'] ) : '';

	if ( '' === $pcm_label ) {
		return new WP_Error( 'pcm_crm_access_label_required', __( 'Give it a name.', 'pcm-crm' ) );
	}

	$pcm_grants = array();

	foreach ( array_keys( pcm_crm_permission_areas() ) as $pcm_area ) {
		if ( isset( $pcm_post['grants'][ $pcm_area ] ) ) {
			$pcm_grants[ $pcm_area ] = array_map( 'sanitize_key', (array) $pcm_post['grants'][ $pcm_area ] );
		}
	}

	return array(
		'label'       => $pcm_label,
		'description' => isset( $pcm_post['description'] ) ? sanitize_text_field( $pcm_post['description'] ) : '',
		'grants'      => pcm_crm_sanitize_grants( $pcm_grants ),
	);
}

function pcm_crm_handle_save_access_definition( $pcm_kind ) {
	$pcm_config = pcm_crm_access_kind( $pcm_kind );

	if (
		! pcm_crm_can( 'settings', 'edit' ) ||
		! isset( $_POST[ $pcm_config['nonce'] . '_nonce' ] ) ||
		! wp_verify_nonce( sanitize_key( $_POST[ $pcm_config['nonce'] . '_nonce' ] ), $pcm_config['nonce'] )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	$pcm_post = wp_unslash( $_POST );
	$pcm_key  = isset( $pcm_post['key'] ) ? sanitize_key( $pcm_post['key'] ) : '';
	$pcm_back = pcm_crm_setup_url( $pcm_config['setup_key'] );

	// Read raw rather than through the kind's own reader (pcm_crm_profiles(),
	// pcm_crm_permission_sets()): those run apply_filters(), and writing back
	// a filtered read would bake a filter's output into storage.
	$pcm_items  = (array) get_option( $pcm_config['option'], array() );
	$pcm_is_new = '' === $pcm_key || ! isset( $pcm_items[ $pcm_key ] );

	$pcm_clean = pcm_crm_clean_access_definition( $pcm_post );

	if ( ! is_wp_error( $pcm_clean ) && $pcm_is_new ) {
		$pcm_key  = sanitize_title( $pcm_clean['label'] );
		$pcm_base = $pcm_key ? $pcm_key : 'item';
		$pcm_n    = 2;

		while ( isset( $pcm_items[ $pcm_key ] ) ) {
			$pcm_key = $pcm_base . '-' . $pcm_n++;
		}
	}

	if ( is_wp_error( $pcm_clean ) ) {
		set_transient( 'pcm_crm_access_error_' . get_current_user_id(), array(
			'message' => $pcm_clean->get_error_message(),
			'post'    => $pcm_post,
		), 60 );

		wp_safe_redirect( add_query_arg( array( $pcm_config['query_arg'] => $pcm_is_new ? 'new' : $pcm_key, 'pcm_access' => 'error' ), $pcm_back ) );
		exit;
	}

	$pcm_items[ $pcm_key ] = $pcm_clean;
	update_option( $pcm_config['option'], $pcm_items );

	wp_safe_redirect( add_query_arg( array( 'pcm_access' => 'saved' ), $pcm_back ) );
	exit;
}

function pcm_crm_handle_save_profile() { pcm_crm_handle_save_access_definition( 'profile' ); }
function pcm_crm_handle_save_permission_set() { pcm_crm_handle_save_access_definition( 'set' ); }
add_action( 'admin_post_pcm_crm_save_profile', 'pcm_crm_handle_save_profile' );
add_action( 'admin_post_pcm_crm_save_permission_set', 'pcm_crm_handle_save_permission_set' );

/**
 * Whether anybody is still using this profile as their baseline.
 *
 * A permission set is safe to delete at any time — pcm_crm_effective_permissions()
 * already skips a set key that no longer resolves, so removing one only ever
 * takes away whatever it was adding. A profile is not: pcm_crm_can() reads a
 * missing profile as no grants at all, so deleting one out from under an
 * assigned user would lock them out with nothing on screen to explain why.
 * A plain function rather than inline in the handler, so the rule is checkable
 * without going through wp_safe_redirect()'s exit.
 */
function pcm_crm_profile_in_use( $pcm_key ) {
	if ( '' === $pcm_key ) { return false; }

	return (bool) get_users( array( 'meta_key' => PCM_CRM_PROFILE_META, 'meta_value' => $pcm_key, 'fields' => 'ID' ) );
}

function pcm_crm_handle_delete_access_definition( $pcm_kind ) {
	$pcm_config = pcm_crm_access_kind( $pcm_kind );

	if (
		! pcm_crm_can( 'settings', 'edit' ) ||
		! isset( $_POST[ $pcm_config['nonce'] . '_nonce' ] ) ||
		! wp_verify_nonce( sanitize_key( $_POST[ $pcm_config['nonce'] . '_nonce' ] ), $pcm_config['nonce'] )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	$pcm_key  = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
	$pcm_back = pcm_crm_setup_url( $pcm_config['setup_key'] );

	if ( 'profile' === $pcm_kind && pcm_crm_profile_in_use( $pcm_key ) ) {
		wp_safe_redirect( add_query_arg( array( 'pcm_access' => 'in-use' ), $pcm_back ) );
		exit;
	}

	$pcm_items = (array) get_option( $pcm_config['option'], array() );
	unset( $pcm_items[ $pcm_key ] );
	update_option( $pcm_config['option'], $pcm_items );

	wp_safe_redirect( add_query_arg( array( 'pcm_access' => 'deleted' ), $pcm_back ) );
	exit;
}

function pcm_crm_handle_delete_profile() { pcm_crm_handle_delete_access_definition( 'profile' ); }
function pcm_crm_handle_delete_permission_set() { pcm_crm_handle_delete_access_definition( 'set' ); }
add_action( 'admin_post_pcm_crm_delete_profile', 'pcm_crm_handle_delete_profile' );
add_action( 'admin_post_pcm_crm_delete_permission_set', 'pcm_crm_handle_delete_permission_set' );

/* Rendering ------------------------------------------------------------------ */

function pcm_crm_render_access_definitions_page( $pcm_kind ) {
	$pcm_config = pcm_crm_access_kind( $pcm_kind );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
	$pcm_editing = isset( $_GET[ $pcm_config['query_arg'] ] ) ? sanitize_key( wp_unslash( $_GET[ $pcm_config['query_arg'] ] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	$pcm_result = isset( $_GET['pcm_access'] ) ? sanitize_key( wp_unslash( $_GET['pcm_access'] ) ) : '';

	$pcm_error = null;

	if ( 'error' === $pcm_result ) {
		$pcm_error = get_transient( 'pcm_crm_access_error_' . get_current_user_id() );
		delete_transient( 'pcm_crm_access_error_' . get_current_user_id() );
	}

	if ( $pcm_error ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $pcm_error['message'] ) );
	} elseif ( 'saved' === $pcm_result ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			/* translators: %s: "Profile" or "Permission Extension" */
			esc_html( sprintf( __( '%s saved.', 'pcm-crm' ), $pcm_config['singular'] ) ) );
	} elseif ( 'deleted' === $pcm_result ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( __( '%s deleted.', 'pcm-crm' ), $pcm_config['singular'] ) ) );
	} elseif ( 'in-use' === $pcm_result ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'That profile is still somebody’s baseline. Move them to a different profile first, under Staff Access.', 'pcm-crm' ) );
	}

	if ( $pcm_editing ) {
		pcm_crm_render_access_definition_form( $pcm_kind, $pcm_editing, $pcm_error ? (array) $pcm_error['post'] : null );
		return;
	}

	$pcm_reader = $pcm_config['reader'];
	$pcm_items  = $pcm_reader();
	$pcm_areas  = pcm_crm_permission_areas();
	?>
	<div class="pcm-crm-card">
		<p class="description"><?php echo esc_html( $pcm_config['blurb'] ); ?></p>
	</div>

	<div class="pcm-crm-card">
		<div class="pcm-setup-card-head">
			<h2><?php echo esc_html( $pcm_config['plural'] ); ?></h2>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( $pcm_config['query_arg'], 'new', pcm_crm_setup_url( $pcm_config['setup_key'] ) ) ); ?>"><?php echo esc_html( $pcm_config['new_cta'] ); ?></a>
		</div>
		<?php if ( ! $pcm_items ) : ?>
			<p class="description"><?php esc_html_e( 'None yet.', 'pcm-crm' ); ?></p>
		<?php else : ?>
			<table class="widefat striped pcm-setup-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'pcm-crm' ); ?></th>
						<?php foreach ( $pcm_areas as $pcm_area => $pcm_area_def ) : ?>
							<th><?php echo esc_html( $pcm_area_def['label'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pcm_items as $pcm_key => $pcm_item ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( add_query_arg( $pcm_config['query_arg'], $pcm_key, pcm_crm_setup_url( $pcm_config['setup_key'] ) ) ); ?>"><strong><?php echo esc_html( $pcm_item['label'] ); ?></strong></a>
								<?php if ( ! empty( $pcm_item['description'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( $pcm_item['description'] ); ?></span>
								<?php endif; ?>
							</td>
							<?php foreach ( array_keys( $pcm_areas ) as $pcm_area ) : ?>
								<td>
									<?php
									$pcm_granted = isset( $pcm_item['grants'][ $pcm_area ] ) ? (array) $pcm_item['grants'][ $pcm_area ] : array();
									echo $pcm_granted ? esc_html( implode( ', ', $pcm_granted ) ) : '—';
									?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

function pcm_crm_render_access_definition_form( $pcm_kind, $pcm_key, $pcm_post = null ) {
	$pcm_config = pcm_crm_access_kind( $pcm_kind );
	$pcm_is_new = 'new' === $pcm_key;
	$pcm_reader = $pcm_config['reader'];
	$pcm_items  = $pcm_reader();
	$pcm_item   = $pcm_is_new ? null : ( isset( $pcm_items[ $pcm_key ] ) ? $pcm_items[ $pcm_key ] : null );

	if ( ! $pcm_is_new && ! $pcm_item ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>',
			esc_html( sprintf( __( 'That %s does not exist.', 'pcm-crm' ), strtolower( $pcm_config['singular'] ) ) ) );
		return;
	}

	// A refused save comes back with what was typed, rather than wiping it.
	$pcm_values = $pcm_post ? $pcm_post : ( $pcm_item ? $pcm_item : array( 'label' => '', 'description' => '', 'grants' => array() ) );
	$pcm_grants = isset( $pcm_values['grants'] ) ? (array) $pcm_values['grants'] : array();
	?>
	<p><a href="<?php echo esc_url( pcm_crm_setup_url( $pcm_config['setup_key'] ) ); ?>">← <?php echo esc_html( $pcm_config['plural'] ); ?></a></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pcm-setup-type-form">
		<input type="hidden" name="action" value="<?php echo esc_attr( $pcm_config['action'] ); ?>">
		<input type="hidden" name="key" value="<?php echo esc_attr( $pcm_is_new ? '' : $pcm_key ); ?>">
		<?php wp_nonce_field( $pcm_config['nonce'], $pcm_config['nonce'] . '_nonce' ); ?>

		<div class="pcm-crm-card">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pcm-access-label"><?php esc_html_e( 'Name', 'pcm-crm' ); ?></label></th>
					<td><input type="text" id="pcm-access-label" name="label" class="regular-text" value="<?php echo esc_attr( $pcm_values['label'] ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="pcm-access-description"><?php esc_html_e( 'Description', 'pcm-crm' ); ?></label></th>
					<td><input type="text" id="pcm-access-description" name="description" class="regular-text" value="<?php echo esc_attr( isset( $pcm_values['description'] ) ? $pcm_values['description'] : '' ); ?>"></td>
				</tr>
			</table>
		</div>

		<div class="pcm-crm-card">
			<h2><?php esc_html_e( 'Access', 'pcm-crm' ); ?></h2>
			<table class="widefat striped pcm-setup-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Area', 'pcm-crm' ); ?></th>
						<?php foreach ( pcm_crm_permission_actions() as $pcm_action => $pcm_action_def ) : ?>
							<th><?php echo esc_html( $pcm_action_def['label'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( pcm_crm_permission_areas() as $pcm_area => $pcm_area_def ) : ?>
						<?php
						$pcm_allowed = pcm_crm_area_actions( $pcm_area );
						$pcm_checked = isset( $pcm_grants[ $pcm_area ] ) ? (array) $pcm_grants[ $pcm_area ] : array();
						$pcm_off     = $pcm_area_def['module'] && ! pcm_crm_module_active( $pcm_area_def['module'] );
						?>
						<tr>
							<th scope="row">
								<?php echo esc_html( $pcm_area_def['label'] ); ?>
								<?php if ( $pcm_off ) : ?>
									<br><span class="description"><?php esc_html_e( 'Module currently off — the grant is kept, but reaches nothing until it is on.', 'pcm-crm' ); ?></span>
								<?php endif; ?>
							</th>
							<?php foreach ( array_keys( pcm_crm_permission_actions() ) as $pcm_action ) : ?>
								<td>
									<?php if ( in_array( $pcm_action, $pcm_allowed, true ) ) : ?>
										<label>
											<input type="checkbox"
												name="grants[<?php echo esc_attr( $pcm_area ); ?>][]"
												value="<?php echo esc_attr( $pcm_action ); ?>"
												<?php checked( in_array( $pcm_action, $pcm_checked, true ) ); ?>>
											<span class="screen-reader-text"><?php echo esc_html( pcm_crm_permission_actions()[ $pcm_action ]['label'] ); ?></span>
										</label>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Edit and Delete each carry View along with them when saved — there is no such thing as changing a record you cannot see.', 'pcm-crm' ); ?></p>
		</div>

		<?php submit_button( $pcm_is_new ? $pcm_config['new_cta'] : __( 'Save', 'pcm-crm' ) ); ?>
	</form>

	<?php if ( ! $pcm_is_new ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( sprintf( __( 'Delete this %s?', 'pcm-crm' ), strtolower( $pcm_config['singular'] ) ) ); ?>');">
			<input type="hidden" name="action" value="<?php echo esc_attr( $pcm_config['del_action'] ); ?>">
			<input type="hidden" name="key" value="<?php echo esc_attr( $pcm_key ); ?>">
			<?php wp_nonce_field( $pcm_config['nonce'], $pcm_config['nonce'] . '_nonce' ); ?>
			<button type="submit" class="button button-link-delete"><?php echo esc_html( sprintf( __( 'Delete this %s', 'pcm-crm' ), strtolower( $pcm_config['singular'] ) ) ); ?></button>
		</form>
	<?php endif; ?>
	<?php
}

/* ---------------------------------------------------------------------------
   Staff Access: who holds which profile and sets
   --------------------------------------------------------------------------- */

/**
 * Everyone the Access screen is for.
 *
 * Deliberately the staff role, not everyone holding PCM_CRM_CAP —
 * administrators hold the cap too, but pcm_crm_effective_permissions() short-
 * circuits them before a profile is ever consulted, so assigning one would be
 * a control that visibly does nothing.
 */
function pcm_crm_staff_users() {
	return get_users( array( 'role' => PCM_CRM_STAFF_ROLE, 'orderby' => 'display_name' ) );
}

/**
 * A posted profile and set list, narrowed to what actually exists.
 *
 * Validated against the current definitions, not merely sanitised for shape —
 * a stale key left over from a deleted profile or set must not be re-stored by
 * a save that never looked at whether it still means anything. A plain
 * function so the narrowing is checkable without the handler's exit.
 */
function pcm_crm_clean_user_access( array $pcm_post ) {
	$pcm_profile = isset( $pcm_post['profile'] ) ? sanitize_key( $pcm_post['profile'] ) : '';

	if ( $pcm_profile && ! pcm_crm_profile( $pcm_profile ) ) { $pcm_profile = ''; }

	$pcm_known_sets = array_keys( pcm_crm_permission_sets() );
	$pcm_sets       = isset( $pcm_post['sets'] ) ? array_map( 'sanitize_key', (array) $pcm_post['sets'] ) : array();
	$pcm_sets       = array_values( array_intersect( $pcm_sets, $pcm_known_sets ) );

	return array( 'profile' => $pcm_profile, 'sets' => $pcm_sets );
}

/**
 * Create or reuse a WP user for a new staff member, and email them a way in.
 *
 * The twin of pcm_crm_portal_invite_contact() (includes/portal/portal-admin.php)
 * for the other side of the front end — same shape (create-or-reuse, refuse an
 * email belonging to a different kind of account, a password-reset link, a
 * courtesy email) with two real differences: there is no Contact to link to,
 * and the assignment this whole screen exists for (pcm_crm_assign_permissions())
 * happens in the same call rather than as a second step, so an invited user is
 * never left holding the Staff role with no profile at all — the state
 * pcm_crm_render_access_user_form() already treats as "no CRM access."
 *
 * Reuses an existing Staff user for the same email — inviting someone a
 * second time (a lost email, a name filled in later) resends rather than
 * colliding on wp_insert_user(), the same reasoning the portal invite uses.
 * Reset keys are single-use and expire after 24 hours; a second invite is the
 * only recovery this plugin offers, deliberately, rather than a "resend"
 * mechanism that has to reason about an outstanding key's state.
 */
function pcm_crm_invite_staff( $pcm_email, $pcm_first, $pcm_last, $pcm_profile_key, array $pcm_set_keys = array() ) {
	if ( ! is_email( $pcm_email ) ) {
		return new WP_Error( 'pcm_crm_staff_bad_email', __( 'Enter a valid email address.', 'pcm-crm' ) );
	}

	if ( $pcm_profile_key && ! pcm_crm_profile( $pcm_profile_key ) ) {
		return new WP_Error( 'pcm_crm_staff_bad_profile', __( 'That profile no longer exists.', 'pcm-crm' ) );
	}

	pcm_crm_ensure_staff_role();

	$pcm_user = get_user_by( 'email', $pcm_email );

	if ( $pcm_user && ! in_array( PCM_CRM_STAFF_ROLE, (array) $pcm_user->roles, true ) ) {
		return new WP_Error( 'pcm_crm_staff_email_taken', __( 'That email already belongs to a different kind of account on this site.', 'pcm-crm' ) );
	}

	if ( ! $pcm_user ) {
		$pcm_user_id = wp_insert_user( array(
			'user_login' => sanitize_user( $pcm_email, true ),
			'user_email' => $pcm_email,
			'user_pass'  => wp_generate_password( 32 ),
			'first_name' => $pcm_first,
			'last_name'  => $pcm_last,
			'role'       => PCM_CRM_STAFF_ROLE,
		) );

		if ( is_wp_error( $pcm_user_id ) ) {
			return $pcm_user_id;
		}

		$pcm_user = get_user_by( 'id', $pcm_user_id );
	} elseif ( $pcm_first || $pcm_last ) {
		// A resend can also be the first time a name gets filled in —
		// pcm_crm_user_label() falls back to the login (the email address)
		// until one is set, so an owner who reads as an email address on
		// every record they touch is otherwise stuck that way.
		wp_update_user( array( 'ID' => $pcm_user->ID, 'first_name' => $pcm_first, 'last_name' => $pcm_last ) );
	}

	pcm_crm_assign_permissions( $pcm_user->ID, $pcm_profile_key, $pcm_set_keys );

	$pcm_key = get_password_reset_key( $pcm_user );

	if ( is_wp_error( $pcm_key ) ) {
		return $pcm_key;
	}

	$pcm_url = add_query_arg( array(
		'action'      => 'rp',
		'key'         => $pcm_key,
		'login'       => rawurlencode( $pcm_user->user_login ),
		'redirect_to' => rawurlencode( pcm_crm_front_base_url() ),
	), wp_login_url() );

	$pcm_body = pcm_crm_email_wrapper( pcm_crm_format_body( sprintf(
		/* translators: 1: the invited person's first name or email, 2: a set-password link, 3: how to sign in */
		__( "Hi %1\$s,\n\nYou now have access to the employee portal.\n\nSet your password to get started: %2\$s\n\n%3\$s", 'pcm-crm' ),
		$pcm_first ? $pcm_first : $pcm_email,
		esc_url_raw( $pcm_url ),
		pcm_crm_invite_sign_in_line( $pcm_user )
	) ), pcm_crm_front_logo_url() );

	add_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );
	$pcm_sent = wp_mail( $pcm_email, __( 'You’re invited to the employee portal', 'pcm-crm' ), $pcm_body );
	remove_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );

	if ( ! $pcm_sent ) {
		return new WP_Error( 'pcm_crm_mail_failed', __( 'The account was created, but the invitation email could not be sent.', 'pcm-crm' ) );
	}

	return array( 'invited' => true, 'user_id' => (int) $pcm_user->ID );
}

/**
 * A WP_Error's code, narrowed to the short set pcm_crm_invite_result_notice()
 * knows how to word — the same "canned result code in the query string, not
 * the raw message" shape pcm_crm_handle_test_email() (admin/settings.php)
 * already uses, so a message never has to survive being round-tripped through
 * a URL.
 */
function pcm_crm_invite_result_code( $pcm_error_code ) {
	$pcm_known = array( 'pcm_crm_staff_bad_email', 'pcm_crm_staff_bad_profile', 'pcm_crm_staff_email_taken', 'pcm_crm_mail_failed' );

	return in_array( $pcm_error_code, $pcm_known, true ) ? $pcm_error_code : 'pcm_crm_staff_failed';
}

function pcm_crm_handle_invite_staff() {
	if (
		! pcm_crm_can( 'settings', 'edit' ) ||
		! isset( $_POST['pcm_crm_invite_staff_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_POST['pcm_crm_invite_staff_nonce'] ), 'pcm_crm_invite_staff' )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	$pcm_back  = pcm_crm_setup_url( 'access-users' );
	$pcm_email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$pcm_first = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
	$pcm_last  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
	$pcm_clean = pcm_crm_clean_user_access( wp_unslash( $_POST ) );

	$pcm_result = pcm_crm_invite_staff( $pcm_email, $pcm_first, $pcm_last, $pcm_clean['profile'], $pcm_clean['sets'] );

	if ( is_wp_error( $pcm_result ) ) {
		wp_safe_redirect( add_query_arg( 'pcm_crm_invite', pcm_crm_invite_result_code( $pcm_result->get_error_code() ), $pcm_back ) );
		exit;
	}

	wp_safe_redirect( add_query_arg( array( 'pcm_crm_invite' => 'sent', 'user' => $pcm_result['user_id'] ), $pcm_back ) );
	exit;
}
add_action( 'admin_post_pcm_crm_invite_staff', 'pcm_crm_handle_invite_staff' );

/**
 * Resend, from a row on the Staff Access list — the existing user's own
 * email, name and current assignment, unchanged. Kept separate from
 * pcm_crm_handle_invite_staff() rather than reusing its form: this one has no
 * fields to post beyond which user, and posting a resend through the same
 * form would mean either re-rendering it pre-filled (a second render path for
 * one action) or trusting whatever profile/sets happened to be in the main
 * form's own fields at the moment "Resend" was clicked, which may not be this
 * user's.
 */
function pcm_crm_handle_resend_staff_invite() {
	if (
		! pcm_crm_can( 'settings', 'edit' ) ||
		! isset( $_POST['pcm_crm_resend_staff_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_POST['pcm_crm_resend_staff_nonce'] ), 'pcm_crm_resend_staff' )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	$pcm_back    = pcm_crm_setup_url( 'access-users' );
	$pcm_user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
	$pcm_user    = $pcm_user_id ? get_userdata( $pcm_user_id ) : null;

	if ( ! $pcm_user || ! in_array( PCM_CRM_STAFF_ROLE, (array) $pcm_user->roles, true ) ) {
		wp_die( esc_html__( 'That user does not hold the Staff role.', 'pcm-crm' ), 404 );
	}

	$pcm_result = pcm_crm_invite_staff(
		$pcm_user->user_email,
		$pcm_user->first_name,
		$pcm_user->last_name,
		pcm_crm_user_profile_key( $pcm_user_id ),
		pcm_crm_user_set_keys( $pcm_user_id )
	);

	if ( is_wp_error( $pcm_result ) ) {
		wp_safe_redirect( add_query_arg( 'pcm_crm_invite', pcm_crm_invite_result_code( $pcm_result->get_error_code() ), $pcm_back ) );
		exit;
	}

	wp_safe_redirect( add_query_arg( array( 'pcm_crm_invite' => 'sent', 'user' => $pcm_user_id ), $pcm_back ) );
	exit;
}
add_action( 'admin_post_pcm_crm_resend_staff_invite', 'pcm_crm_handle_resend_staff_invite' );

function pcm_crm_handle_save_user_access() {
	if (
		! pcm_crm_can( 'settings', 'edit' ) ||
		! isset( $_POST['pcm_crm_user_access_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_POST['pcm_crm_user_access_nonce'] ), 'pcm_crm_user_access' )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	$pcm_user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
	$pcm_user    = $pcm_user_id ? get_userdata( $pcm_user_id ) : null;
	$pcm_back    = pcm_crm_setup_url( 'access-users' );

	// Refused rather than silently no-opped: writing meta onto a user who
	// cannot use it would look like it worked and do nothing.
	if ( ! $pcm_user || ! in_array( PCM_CRM_STAFF_ROLE, (array) $pcm_user->roles, true ) ) {
		wp_die( esc_html__( 'That user does not hold the Staff role.', 'pcm-crm' ), 404 );
	}

	$pcm_clean = pcm_crm_clean_user_access( wp_unslash( $_POST ) );

	pcm_crm_assign_permissions( $pcm_user_id, $pcm_clean['profile'], $pcm_clean['sets'] );

	wp_safe_redirect( add_query_arg( array( 'pcm_access' => 'saved' ), $pcm_back ) );
	exit;
}
add_action( 'admin_post_pcm_crm_save_user_access', 'pcm_crm_handle_save_user_access' );

/**
 * Word the result of an invite or resend — the canned messages behind the
 * short codes pcm_crm_invite_result_code() narrows a WP_Error to.
 */
function pcm_crm_invite_result_message( $pcm_result ) {
	$pcm_messages = array(
		'sent'                       => array( 'success', __( 'Invitation sent.', 'pcm-crm' ) ),
		'pcm_crm_staff_bad_email'    => array( 'error', __( 'Enter a valid email address.', 'pcm-crm' ) ),
		'pcm_crm_staff_bad_profile'  => array( 'error', __( 'That profile no longer exists.', 'pcm-crm' ) ),
		'pcm_crm_staff_email_taken'  => array( 'error', __( 'That email already belongs to a different kind of account on this site.', 'pcm-crm' ) ),
		'pcm_crm_mail_failed'        => array( 'error', __( 'The account was created, but the invitation email could not be sent.', 'pcm-crm' ) ),
		'pcm_crm_staff_failed'       => array( 'error', __( 'Something went wrong and the invitation was not sent.', 'pcm-crm' ) ),
	);

	return isset( $pcm_messages[ $pcm_result ] ) ? $pcm_messages[ $pcm_result ] : null;
}

function pcm_crm_render_access_users_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
	$pcm_editing_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	$pcm_result = isset( $_GET['pcm_access'] ) ? sanitize_key( wp_unslash( $_GET['pcm_access'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	$pcm_invite_result = isset( $_GET['pcm_crm_invite'] ) ? sanitize_key( wp_unslash( $_GET['pcm_crm_invite'] ) ) : '';

	if ( 'saved' === $pcm_result ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Access updated.', 'pcm-crm' ) );
	}

	if ( $pcm_invite_result ) {
		$pcm_invite_notice = pcm_crm_invite_result_message( $pcm_invite_result );

		if ( $pcm_invite_notice ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $pcm_invite_notice[0] ), esc_html( $pcm_invite_notice[1] ) );
		}
	}

	if ( $pcm_editing_id ) {
		pcm_crm_render_access_user_form( $pcm_editing_id );
		return;
	}

	$pcm_users = pcm_crm_staff_users();
	?>
	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Invite a staff member', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Creates their account if it does not exist yet, and emails them a link to set their own password. Inviting the same address again resends the link — useful if it expired (24 hours) or never arrived.', 'pcm-crm' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pcm-setup-type-form">
			<input type="hidden" name="action" value="pcm_crm_invite_staff">
			<?php wp_nonce_field( 'pcm_crm_invite_staff', 'pcm_crm_invite_staff_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pcm-crm-invite-email"><?php esc_html_e( 'Email', 'pcm-crm' ); ?></label></th>
					<td><input type="email" class="regular-text" id="pcm-crm-invite-email" name="email" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="pcm-crm-invite-first"><?php esc_html_e( 'First name', 'pcm-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" id="pcm-crm-invite-first" name="first_name"></td>
				</tr>
				<tr>
					<th scope="row"><label for="pcm-crm-invite-last"><?php esc_html_e( 'Last name', 'pcm-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" id="pcm-crm-invite-last" name="last_name"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Profile', 'pcm-crm' ); ?></th>
					<td>
						<label>
							<input type="radio" name="profile" value="" checked>
							<?php esc_html_e( 'None — no CRM access', 'pcm-crm' ); ?>
						</label><br>
						<?php foreach ( pcm_crm_profiles() as $pcm_key => $pcm_profile ) : ?>
							<label>
								<input type="radio" name="profile" value="<?php echo esc_attr( $pcm_key ); ?>">
								<strong><?php echo esc_html( $pcm_profile['label'] ); ?></strong>
								<?php if ( $pcm_profile['description'] ) : ?> — <span class="description"><?php echo esc_html( $pcm_profile['description'] ); ?></span><?php endif; ?>
							</label><br>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php $pcm_invite_sets = pcm_crm_permission_sets(); ?>
				<?php if ( $pcm_invite_sets ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Permission Extensions', 'pcm-crm' ); ?></th>
						<td>
							<?php foreach ( $pcm_invite_sets as $pcm_key => $pcm_set ) : ?>
								<label>
									<input type="checkbox" name="sets[]" value="<?php echo esc_attr( $pcm_key ); ?>">
									<strong><?php echo esc_html( $pcm_set['label'] ); ?></strong>
									<?php if ( $pcm_set['description'] ) : ?> — <span class="description"><?php echo esc_html( $pcm_set['description'] ); ?></span><?php endif; ?>
								</label><br>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Send Invitation', 'pcm-crm' ) ); ?>
		</form>
	</div>

	<div class="pcm-crm-card">
		<p class="description">
			<?php esc_html_e( 'Everyone holding the Staff role — invited here, or added from Users → Add New in the WordPress admin menu and given the Staff role by hand.', 'pcm-crm' ); ?>
		</p>
	</div>

	<div class="pcm-crm-card">
		<?php if ( ! $pcm_users ) : ?>
			<p class="description"><?php esc_html_e( 'No one holds the Staff role yet.', 'pcm-crm' ); ?></p>
		<?php else : ?>
			<table class="widefat striped pcm-setup-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'pcm-crm' ); ?></th>
						<th><?php esc_html_e( 'Profile', 'pcm-crm' ); ?></th>
						<th><?php esc_html_e( 'Permission Extensions', 'pcm-crm' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pcm_users as $pcm_user ) : ?>
						<?php
						$pcm_profile_key = pcm_crm_user_profile_key( $pcm_user->ID );
						$pcm_profile     = $pcm_profile_key ? pcm_crm_profile( $pcm_profile_key ) : null;
						$pcm_set_keys    = pcm_crm_user_set_keys( $pcm_user->ID );
						$pcm_sets        = pcm_crm_permission_sets();
						$pcm_set_labels  = array();

						foreach ( $pcm_set_keys as $pcm_set_key ) {
							if ( isset( $pcm_sets[ $pcm_set_key ] ) ) { $pcm_set_labels[] = $pcm_sets[ $pcm_set_key ]['label']; }
						}
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( pcm_crm_user_label( $pcm_user ) ); ?></strong>
								<br><span class="description"><?php echo esc_html( $pcm_user->user_email ); ?></span>
							</td>
							<td><?php echo $pcm_profile ? esc_html( $pcm_profile['label'] ) : esc_html__( 'None — no CRM access', 'pcm-crm' ); ?></td>
							<td><?php echo $pcm_set_labels ? esc_html( implode( ', ', $pcm_set_labels ) ) : '—'; ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( 'user', $pcm_user->ID, pcm_crm_setup_url( 'access-users' ) ) ); ?>"><?php esc_html_e( 'Edit access', 'pcm-crm' ); ?></a>
								·
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="pcm_crm_resend_staff_invite">
									<input type="hidden" name="user_id" value="<?php echo esc_attr( $pcm_user->ID ); ?>">
									<?php wp_nonce_field( 'pcm_crm_resend_staff', 'pcm_crm_resend_staff_nonce' ); ?>
									<button type="submit" class="button-link"><?php esc_html_e( 'Resend invite', 'pcm-crm' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The Profile radios and Permission Set checkboxes — the one place this
 * markup exists, so the Staff Access screen and the native WordPress user
 * screens (includes/user-access.php) can never drift apart from each other:
 * both call this rather than each carrying their own copy, and both post
 * the same field names (`profile`, `sets[]`) into the same
 * pcm_crm_clean_user_access() this file already validates them with.
 */
function pcm_crm_render_access_fields( $pcm_profile_key, array $pcm_set_keys ) {
	?>
	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Profile', 'pcm-crm' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Exactly one — the baseline this person’s access starts from.', 'pcm-crm' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<td>
					<label>
						<input type="radio" name="profile" value="" <?php checked( '', $pcm_profile_key ); ?>>
						<?php esc_html_e( 'None — no CRM access', 'pcm-crm' ); ?>
					</label><br>
					<?php foreach ( pcm_crm_profiles() as $pcm_key => $pcm_profile ) : ?>
						<label>
							<input type="radio" name="profile" value="<?php echo esc_attr( $pcm_key ); ?>" <?php checked( $pcm_key, $pcm_profile_key ); ?>>
							<strong><?php echo esc_html( $pcm_profile['label'] ); ?></strong>
							<?php if ( $pcm_profile['description'] ) : ?> — <span class="description"><?php echo esc_html( $pcm_profile['description'] ); ?></span><?php endif; ?>
						</label><br>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
	</div>

	<?php $pcm_sets = pcm_crm_permission_sets(); ?>
	<?php if ( $pcm_sets ) : ?>
		<div class="pcm-crm-card">
			<h2><?php esc_html_e( 'Permission Extensions', 'pcm-crm' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Any number, each adding to whatever the profile above already gives.', 'pcm-crm' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<td>
						<?php foreach ( $pcm_sets as $pcm_key => $pcm_set ) : ?>
							<label>
								<input type="checkbox" name="sets[]" value="<?php echo esc_attr( $pcm_key ); ?>" <?php checked( in_array( $pcm_key, $pcm_set_keys, true ) ); ?>>
								<strong><?php echo esc_html( $pcm_set['label'] ); ?></strong>
								<?php if ( $pcm_set['description'] ) : ?> — <span class="description"><?php echo esc_html( $pcm_set['description'] ); ?></span><?php endif; ?>
							</label><br>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
		</div>
	<?php endif; ?>
	<?php
}

function pcm_crm_render_access_user_form( $pcm_user_id ) {
	$pcm_user = get_userdata( $pcm_user_id );

	if ( ! $pcm_user || ! in_array( PCM_CRM_STAFF_ROLE, (array) $pcm_user->roles, true ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'That user does not hold the Staff role.', 'pcm-crm' ) );
		return;
	}

	$pcm_profile_key = pcm_crm_user_profile_key( $pcm_user_id );
	$pcm_set_keys    = pcm_crm_user_set_keys( $pcm_user_id );
	?>
	<p><a href="<?php echo esc_url( pcm_crm_setup_url( 'access-users' ) ); ?>">← <?php esc_html_e( 'Staff Access', 'pcm-crm' ); ?></a></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pcm-setup-type-form">
		<input type="hidden" name="action" value="pcm_crm_save_user_access">
		<input type="hidden" name="user_id" value="<?php echo esc_attr( $pcm_user_id ); ?>">
		<?php wp_nonce_field( 'pcm_crm_user_access', 'pcm_crm_user_access_nonce' ); ?>

		<div class="pcm-crm-card">
			<h2><?php echo esc_html( pcm_crm_user_label( $pcm_user ) ); ?></h2>
			<p class="description"><?php echo esc_html( $pcm_user->user_email ); ?></p>
		</div>

		<?php pcm_crm_render_access_fields( $pcm_profile_key, $pcm_set_keys ); ?>

		<?php submit_button( __( 'Save Access', 'pcm-crm' ) ); ?>
	</form>
	<?php
}
