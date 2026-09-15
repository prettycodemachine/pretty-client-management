<?php
/**
 * CRM Settings › Projects: project types, time entry, picklists.
 *
 * Gated with the rest of the module's surface, so switching Projects off takes
 * this group out of CRM Settings. The types themselves, and the rules they carry, live
 * in pm-archetypes.php — this file only edits them.
 *
 * Types are edited one at a time through admin-post rather than options.php:
 * a type's stages can only be checked against the projects already using it,
 * which is a question a sanitize callback has no good way to refuse on.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_PM_PICKLISTS_OPTION = 'pcm_crm_pm_picklists';

pcm_crm_register_setup_page( 'project-types', array(
	'group'       => 'projects',
	'label'       => __( 'Project Types', 'pcm-crm' ),
	'description' => __( 'What a person picks when creating a project. Each type follows a process, which decides its stages, its fields and how time is logged.', 'pcm-crm' ),
	'render'      => 'pcm_crm_pm_render_types_page',
	'order'       => 10,
) );

pcm_crm_register_setup_page( 'time-entry', array(
	'group'       => 'projects',
	'label'       => __( 'Time Entry', 'pcm-crm' ),
	'description' => __( 'Rules that hold for every entry, whatever the project. Per-type rules are on each project type.', 'pcm-crm' ),
	'render'      => 'pcm_crm_pm_render_time_page',
	'order'       => 20,
) );

pcm_crm_register_setup_page( 'project-picklists', array(
	'group'       => 'projects',
	'label'       => __( 'Picklists', 'pcm-crm' ),
	'description' => __( 'The choices offered for project roles and RAID entries.', 'pcm-crm' ),
	'render'      => 'pcm_crm_pm_render_picklists_page',
	'order'       => 40,
) );

function pcm_crm_pm_register_settings() {
	register_setting( 'pcm_crm_pm_time_settings', PCM_CRM_PM_TIME_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_pm_sanitize_time_settings',
	) );

	register_setting( 'pcm_crm_pm_picklist_settings', PCM_CRM_PM_PICKLISTS_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_pm_sanitize_picklists',
	) );
}
add_action( 'admin_init', 'pcm_crm_pm_register_settings' );

/* Sanitising --------------------------------------------------------------- */

function pcm_crm_pm_sanitize_time_settings( $pcm_value ) {
	$pcm_value = is_array( $pcm_value ) ? $pcm_value : array();

	$pcm_max = isset( $pcm_value['max_hours'] ) ? (float) $pcm_value['max_hours'] : 24;

	return array(
		'max_hours'       => $pcm_max > 0 && $pcm_max <= 24 ? $pcm_max : 24.0,
		'increment'       => in_array( (string) ( isset( $pcm_value['increment'] ) ? $pcm_value['increment'] : '0' ), array( '0', '0.1', '0.25', '0.5' ), true ) ? (float) $pcm_value['increment'] : 0,
		'allow_future'    => empty( $pcm_value['allow_future'] ) ? 0 : 1,
		'lock_after_days' => isset( $pcm_value['lock_after_days'] ) ? absint( $pcm_value['lock_after_days'] ) : 0,
	);
}

function pcm_crm_pm_lines( $pcm_text ) {
	return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', (string) $pcm_text ) ) ) ) );
}

function pcm_crm_pm_sanitize_picklists( $pcm_value ) {
	$pcm_value = is_array( $pcm_value ) ? $pcm_value : array();
	$pcm_out   = array();

	foreach ( array( 'roles', 'raid_types' ) as $pcm_list ) {
		$pcm_lines = pcm_crm_pm_lines( isset( $pcm_value[ $pcm_list ] ) ? $pcm_value[ $pcm_list ] : '' );

		// An empty list would leave a picklist with nothing in it, so it falls
		// back to the shipped values rather than saving nothing.
		if ( $pcm_lines ) {
			$pcm_out[ $pcm_list ] = $pcm_lines;
		}
	}

	return $pcm_out;
}

/**
 * Saved picklists override the shipped ones through the filters those
 * functions already run.
 */
function pcm_crm_pm_saved_picklist( $pcm_list, $pcm_default ) {
	$pcm_saved = get_option( PCM_CRM_PM_PICKLISTS_OPTION, array() );

	return ( is_array( $pcm_saved ) && ! empty( $pcm_saved[ $pcm_list ] ) ) ? $pcm_saved[ $pcm_list ] : $pcm_default;
}

add_filter( 'pcm_crm_pm_roles', function ( $pcm_default ) { return pcm_crm_pm_saved_picklist( 'roles', $pcm_default ); }, 5 );
add_filter( 'pcm_crm_pm_raid_types', function ( $pcm_default ) { return pcm_crm_pm_saved_picklist( 'raid_types', $pcm_default ); }, 5 );

/**
 * Clean one posted type.
 *
 * @return array|WP_Error The type as stored, or why it cannot be.
 */
function pcm_crm_pm_clean_type( array $pcm_post, $pcm_key ) {
	$pcm_label = isset( $pcm_post['label'] ) ? trim( sanitize_text_field( $pcm_post['label'] ) ) : '';

	if ( '' === $pcm_label ) {
		return new WP_Error( 'pcm_crm_pm_type_label', __( 'A project type needs a name.', 'pcm-crm' ) );
	}

	$pcm_archetype = isset( $pcm_post['archetype'] ) ? sanitize_key( $pcm_post['archetype'] ) : '';

	if ( ! pcm_crm_pm_archetype( $pcm_archetype ) ) {
		return new WP_Error( 'pcm_crm_pm_type_archetype', __( 'Choose the process this type follows.', 'pcm-crm' ) );
	}

	$pcm_stages = array();
	$pcm_seen   = array();
	$pcm_order  = 10;

	foreach ( (array) ( isset( $pcm_post['stages'] ) ? $pcm_post['stages'] : array() ) as $pcm_stage ) {
		$pcm_name = isset( $pcm_stage['name'] ) ? trim( sanitize_text_field( $pcm_stage['name'] ) ) : '';

		if ( '' === $pcm_name || isset( $pcm_seen[ strtolower( $pcm_name ) ] ) ) {
			continue;
		}

		$pcm_seen[ strtolower( $pcm_name ) ] = true;

		$pcm_stages[] = array(
			'name'       => $pcm_name,
			'order'      => $pcm_order,
			'is_active'  => empty( $pcm_stage['is_active'] ) ? 0 : 1,
			'is_closed'  => empty( $pcm_stage['is_closed'] ) ? 0 : 1,
			'is_renewal' => empty( $pcm_stage['is_renewal'] ) ? 0 : 1,
		);

		$pcm_order += 10;
	}

	$pcm_archetype_def = pcm_crm_pm_archetype( $pcm_archetype );

	// Stages identical to the process's own are not stored, so the type keeps
	// following the process — and a reset stores nothing for the same reason.
	$pcm_strip = function ( array $pcm_set ) {
		return array_map( function ( $pcm_stage ) {
			return array( $pcm_stage['name'], (int) $pcm_stage['is_active'], (int) $pcm_stage['is_closed'], (int) $pcm_stage['is_renewal'] );
		}, array_values( $pcm_set ) );
	};

	if ( ! empty( $pcm_post['reset_stages'] ) || $pcm_strip( $pcm_stages ) === $pcm_strip( $pcm_archetype_def['stages'] ) ) {
		$pcm_stages = array();
	}

	if ( $pcm_stages && ! array_filter( wp_list_pluck( $pcm_stages, 'is_closed' ) ) ) {
		return new WP_Error( 'pcm_crm_pm_type_closing', __( 'At least one stage has to close a project, or nothing would ever finish.', 'pcm-crm' ) );
	}

	$pcm_time = array();

	// Only a form that showed the rules posts them: an unticked box posts
	// nothing, so a form without the section would otherwise read as every
	// rule switched off.
	if ( empty( $pcm_post['reset_time'] ) && ! empty( $pcm_post['time_posted'] ) ) {
		foreach ( array_keys( $pcm_archetype_def['time'] ) as $pcm_rule ) {
			$pcm_value = empty( $pcm_post['time'][ $pcm_rule ] ) ? 0 : 1;

			// Only what differs from the archetype is stored, so a correction to
			// the archetype still reaches every type that never changed it.
			if ( (int) $pcm_archetype_def['time'][ $pcm_rule ] !== $pcm_value ) {
				$pcm_time[ $pcm_rule ] = $pcm_value;
			}
		}
	}

	$pcm_defaults = array();

	foreach ( array( 'default_bill_rate', 'default_cost_rate', 'retainer_hours' ) as $pcm_default ) {
		if ( isset( $pcm_post['defaults'][ $pcm_default ] ) && '' !== trim( (string) $pcm_post['defaults'][ $pcm_default ] ) ) {
			$pcm_defaults[ $pcm_default ] = round( (float) $pcm_post['defaults'][ $pcm_default ], 2 );
		}
	}

	if ( isset( $pcm_post['defaults']['retainer_period'] ) && isset( pcm_crm_pm_periods()[ $pcm_post['defaults']['retainer_period'] ] ) ) {
		$pcm_defaults['retainer_period'] = sanitize_key( $pcm_post['defaults']['retainer_period'] );
	}

	$pcm_type = array(
		'label'       => $pcm_label,
		'archetype'   => $pcm_archetype,
		'description' => isset( $pcm_post['description'] ) ? sanitize_textarea_field( $pcm_post['description'] ) : '',
		'active'      => empty( $pcm_post['active'] ) ? 0 : 1,
	);

	if ( $pcm_stages ) { $pcm_type['stages'] = $pcm_stages; }
	if ( $pcm_time ) { $pcm_type['time'] = $pcm_time; }
	if ( $pcm_defaults ) { $pcm_type['defaults'] = $pcm_defaults; }

	return $pcm_type;
}

/**
 * How many live projects are on a type, stage by stage: stage => count.
 */
function pcm_crm_pm_type_usage( $pcm_key ) {
	$pcm_out = array();

	foreach ( pcm_crm_projects()->group_by( 'stage_name', array( 'filters' => array( 'project_type' => $pcm_key ) ) ) as $pcm_row ) {
		$pcm_out[ $pcm_row['value'] ] = $pcm_row['count'];
	}

	return $pcm_out;
}

/* Saving a type ------------------------------------------------------------ */

function pcm_crm_pm_handle_save_type() {
	if (
		! pcm_crm_user_can() ||
		! isset( $_POST['pcm_crm_pm_type_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_POST['pcm_crm_pm_type_nonce'] ), 'pcm_crm_pm_type' )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	$pcm_post  = wp_unslash( $_POST );
	$pcm_key   = isset( $pcm_post['key'] ) ? sanitize_title( $pcm_post['key'] ) : '';
	$pcm_back  = pcm_crm_setup_url( 'project-types' );
	$pcm_types = get_option( PCM_CRM_PM_TYPES_OPTION, array() );
	$pcm_types = ( is_array( $pcm_types ) && $pcm_types ) ? $pcm_types : pcm_crm_pm_default_types();
	$pcm_is_new = '' === $pcm_key || ! isset( $pcm_types[ $pcm_key ] );

	$pcm_clean = pcm_crm_pm_clean_type( (array) $pcm_post, $pcm_key );

	if ( ! is_wp_error( $pcm_clean ) && $pcm_is_new ) {
		$pcm_key = sanitize_title( $pcm_clean['label'] );
		$pcm_base = $pcm_key;
		$pcm_n    = 2;

		while ( isset( $pcm_types[ $pcm_key ] ) ) {
			$pcm_key = $pcm_base . '-' . $pcm_n++;
		}
	}

	if ( ! is_wp_error( $pcm_clean ) && ! $pcm_is_new ) {
		$pcm_usage = pcm_crm_pm_type_usage( $pcm_key );

		// The process a type follows cannot change under projects already on
		// it: their stages, their required fields and their time would all be
		// judged by rules they were never made under.
		if ( $pcm_usage && $pcm_types[ $pcm_key ]['archetype'] !== $pcm_clean['archetype'] ) {
			$pcm_clean = new WP_Error( 'pcm_crm_pm_type_locked', __( 'Projects already follow this type, so its process cannot change. Create a new type instead.', 'pcm-crm' ) );
		}

		// A stage projects are sitting in cannot be removed out from under them.
		if ( ! is_wp_error( $pcm_clean ) ) {
			$pcm_next   = pcm_crm_pm_complete_type( $pcm_key, $pcm_clean );
			$pcm_names  = wp_list_pluck( $pcm_next['stages'], 'name' );
			$pcm_orphan = array_diff( array_keys( array_filter( $pcm_usage ) ), $pcm_names, array( '' ) );

			if ( $pcm_orphan ) {
				$pcm_clean = new WP_Error(
					'pcm_crm_pm_type_stage_in_use',
					/* translators: %s: comma-separated stage names */
					sprintf( __( 'Projects are still in %s. Move them to another stage before removing it.', 'pcm-crm' ), implode( ', ', $pcm_orphan ) )
				);
			}
		}
	}

	if ( is_wp_error( $pcm_clean ) ) {
		set_transient( 'pcm_crm_pm_type_error_' . get_current_user_id(), array(
			'message' => $pcm_clean->get_error_message(),
			'post'    => $pcm_post,
		), 60 );

		wp_safe_redirect( add_query_arg( array( 'type' => $pcm_is_new ? 'new' : $pcm_key, 'pcm_type' => 'error' ), $pcm_back ) );
		exit;
	}

	$pcm_types[ $pcm_key ] = $pcm_clean;
	update_option( PCM_CRM_PM_TYPES_OPTION, $pcm_types );

	wp_safe_redirect( add_query_arg( array( 'pcm_type' => 'saved' ), $pcm_back ) );
	exit;
}
add_action( 'admin_post_pcm_crm_pm_save_type', 'pcm_crm_pm_handle_save_type' );

/* Rendering ---------------------------------------------------------------- */

function pcm_crm_pm_render_types_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
	$pcm_editing = isset( $_GET['type'] ) ? sanitize_title( wp_unslash( $_GET['type'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	$pcm_result = isset( $_GET['pcm_type'] ) ? sanitize_key( wp_unslash( $_GET['pcm_type'] ) ) : '';

	$pcm_error = null;

	if ( 'error' === $pcm_result && function_exists( 'get_transient' ) ) {
		$pcm_error = get_transient( 'pcm_crm_pm_type_error_' . get_current_user_id() );
		delete_transient( 'pcm_crm_pm_type_error_' . get_current_user_id() );
	}

	if ( $pcm_error ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $pcm_error['message'] ) );
	} elseif ( 'saved' === $pcm_result ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Project type saved.', 'pcm-crm' ) );
	}

	if ( $pcm_editing ) {
		pcm_crm_pm_render_type_form( $pcm_editing, $pcm_error ? (array) $pcm_error['post'] : null );
		return;
	}

	$pcm_archetypes = pcm_crm_pm_archetypes();
	?>
	<div class="pcm-crm-card">
		<div class="pcm-setup-card-head">
			<h2><?php esc_html_e( 'Types', 'pcm-crm' ); ?></h2>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'type', 'new', pcm_crm_setup_url( 'project-types' ) ) ); ?>"><?php esc_html_e( 'New Project Type', 'pcm-crm' ); ?></a>
		</div>
		<table class="widefat striped pcm-setup-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Process', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Stages', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Projects', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Status', 'pcm-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( pcm_crm_pm_types() as $pcm_key => $pcm_type ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( add_query_arg( 'type', $pcm_key, pcm_crm_setup_url( 'project-types' ) ) ); ?>"><strong><?php echo esc_html( $pcm_type['label'] ); ?></strong></a>
							<?php if ( $pcm_type['description'] ) : ?>
								<br><span class="description"><?php echo esc_html( $pcm_type['description'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span class="dashicons dashicons-<?php echo esc_attr( $pcm_archetypes[ $pcm_type['archetype'] ]['icon'] ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $pcm_archetypes[ $pcm_type['archetype'] ]['label'] ); ?>
						</td>
						<td><?php echo esc_html( implode( ' → ', wp_list_pluck( $pcm_type['stages'], 'name' ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( array_sum( pcm_crm_pm_type_usage( $pcm_key ) ) ) ); ?></td>
						<td><?php echo $pcm_type['active'] ? esc_html__( 'Active', 'pcm-crm' ) : esc_html__( 'Retired', 'pcm-crm' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'The processes', 'pcm-crm' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Every type follows one of these. The process is what the rest of Projects is built around, so it is chosen when a type is created and fixed once projects use it.', 'pcm-crm' ); ?></p>
		<div class="pcm-setup-archetypes">
			<?php foreach ( $pcm_archetypes as $pcm_archetype ) : ?>
				<div class="pcm-setup-archetype">
					<h3><span class="dashicons dashicons-<?php echo esc_attr( $pcm_archetype['icon'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $pcm_archetype['label'] ); ?></h3>
					<p><?php echo esc_html( $pcm_archetype['description'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * The labels for each time rule, as a person reads them.
 */
function pcm_crm_pm_time_rule_labels() {
	return array(
		'task_required'        => array( __( 'Every entry names a task', 'pcm-crm' ), __( 'So estimates can be compared with what the work took.', 'pcm-crm' ) ),
		'billable_default'     => array( __( 'New entries start billable', 'pcm-crm' ), '' ),
		'billable_locked'      => array( __( 'Billable cannot be changed on an entry', 'pcm-crm' ), __( 'Entries always take the default above.', 'pcm-crm' ) ),
		'rate_required'        => array( __( 'Billable entries need a bill rate', 'pcm-crm' ), __( 'Taken from the project’s default rate when the entry has none.', 'pcm-crm' ) ),
		'description_required' => array( __( 'Every entry says what it was for', 'pcm-crm' ), '' ),
		'resolves_period'      => array( __( 'File entries under the retainer period', 'pcm-crm' ), __( 'So each period’s burn-down counts the right hours.', 'pcm-crm' ) ),
	);
}

function pcm_crm_pm_render_type_form( $pcm_key, $pcm_post = null ) {
	$pcm_is_new = 'new' === $pcm_key;
	$pcm_type   = $pcm_is_new ? null : pcm_crm_pm_type( $pcm_key );

	if ( ! $pcm_is_new && ! $pcm_type ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'That project type does not exist.', 'pcm-crm' ) );
		return;
	}

	$pcm_archetypes = pcm_crm_pm_archetypes();
	$pcm_usage      = $pcm_is_new ? array() : pcm_crm_pm_type_usage( $pcm_key );
	$pcm_locked     = (bool) array_sum( $pcm_usage );

	// A refused save comes back with what was typed, rather than wiping it.
	$pcm_values = $pcm_post ? $pcm_post : ( $pcm_type ? $pcm_type : array(
		'label'       => '',
		'archetype'   => 'fixed',
		'description' => '',
		'active'      => 1,
	) );

	$pcm_archetype_key = isset( $pcm_values['archetype'] ) && isset( $pcm_archetypes[ $pcm_values['archetype'] ] ) ? $pcm_values['archetype'] : 'fixed';
	$pcm_stages        = $pcm_type ? $pcm_type['stages'] : $pcm_archetypes[ $pcm_archetype_key ]['stages'];
	$pcm_time          = $pcm_type ? $pcm_type['time'] : $pcm_archetypes[ $pcm_archetype_key ]['time'];
	$pcm_defaults      = $pcm_type ? $pcm_type['defaults'] : array();
	?>
	<p><a href="<?php echo esc_url( pcm_crm_setup_url( 'project-types' ) ); ?>">← <?php esc_html_e( 'All project types', 'pcm-crm' ); ?></a></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pcm-setup-type-form">
		<input type="hidden" name="action" value="pcm_crm_pm_save_type">
		<input type="hidden" name="key" value="<?php echo esc_attr( $pcm_is_new ? '' : $pcm_key ); ?>">
		<?php wp_nonce_field( 'pcm_crm_pm_type', 'pcm_crm_pm_type_nonce' ); ?>

		<div class="pcm-crm-card">
			<h2><?php echo $pcm_is_new ? esc_html__( 'New project type', 'pcm-crm' ) : esc_html( $pcm_type['label'] ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pcm-type-label"><?php esc_html_e( 'Name', 'pcm-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" id="pcm-type-label" name="label" required value="<?php echo esc_attr( $pcm_values['label'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="pcm-type-description"><?php esc_html_e( 'Description', 'pcm-crm' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="2" id="pcm-type-description" name="description"><?php echo esc_textarea( isset( $pcm_values['description'] ) ? $pcm_values['description'] : '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Shown when someone picks a type for a new project.', 'pcm-crm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Process', 'pcm-crm' ); ?></th>
					<td>
						<fieldset class="pcm-setup-archetype-choice">
							<?php foreach ( $pcm_archetypes as $pcm_archetype_slug => $pcm_archetype ) : ?>
								<label class="pcm-setup-archetype<?php echo $pcm_archetype_slug === $pcm_archetype_key ? ' is-chosen' : ''; ?>">
									<input type="radio" name="archetype" value="<?php echo esc_attr( $pcm_archetype_slug ); ?>"
										<?php checked( $pcm_archetype_slug, $pcm_archetype_key ); ?>
										<?php disabled( $pcm_locked && $pcm_archetype_slug !== $pcm_archetype_key ); ?>>
									<strong><span class="dashicons dashicons-<?php echo esc_attr( $pcm_archetype['icon'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $pcm_archetype['label'] ); ?></strong>
									<span><?php echo esc_html( $pcm_archetype['description'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<?php if ( $pcm_locked ) : ?>
							<p class="description"><?php esc_html_e( 'Projects already follow this type, so its process is fixed. Create a new type to use a different one.', 'pcm-crm' ); ?></p>
						<?php elseif ( $pcm_is_new ) : ?>
							<p class="description"><?php esc_html_e( 'The stages and time rules below start from the process chosen, and can be changed after saving.', 'pcm-crm' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'pcm-crm' ); ?></th>
					<td>
						<label><input type="checkbox" name="active" value="1" <?php checked( ! empty( $pcm_values['active'] ) ); ?>> <?php esc_html_e( 'Offered for new projects', 'pcm-crm' ); ?></label>
						<p class="description"><?php esc_html_e( 'A retired type keeps its projects; it is only left out of the New Project chooser.', 'pcm-crm' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php if ( ! $pcm_is_new ) : ?>
			<div class="pcm-crm-card">
				<h2><?php esc_html_e( 'Stages', 'pcm-crm' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'In order. Active marks where a healthy, running project sits; Closes stops the clock; Renewal is a moment a retainer passes through.', 'pcm-crm' ); ?>
					<?php if ( ! $pcm_type['custom_stages'] ) : ?>
						<?php esc_html_e( 'These are the process’s own stages until you change them.', 'pcm-crm' ); ?>
					<?php endif; ?>
				</p>
				<table class="widefat pcm-setup-stages" data-role="stages">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Stage', 'pcm-crm' ); ?></th>
							<th><?php esc_html_e( 'Active', 'pcm-crm' ); ?></th>
							<th><?php esc_html_e( 'Closes', 'pcm-crm' ); ?></th>
							<th><?php esc_html_e( 'Renewal', 'pcm-crm' ); ?></th>
							<th><?php esc_html_e( 'Projects', 'pcm-crm' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Order', 'pcm-crm' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pcm_stages as $pcm_i => $pcm_stage ) : ?>
							<?php pcm_crm_pm_render_stage_row( $pcm_i, $pcm_stage, isset( $pcm_usage[ $pcm_stage['name'] ] ) ? $pcm_usage[ $pcm_stage['name'] ] : 0 ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<template data-role="stage-template">
					<?php pcm_crm_pm_render_stage_row( '__i__', array( 'name' => '', 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 ), 0 ); ?>
				</template>
				<p>
					<button type="button" class="button" data-role="add-stage"><?php esc_html_e( 'Add stage', 'pcm-crm' ); ?></button>
					<?php if ( $pcm_type['custom_stages'] ) : ?>
						<label class="pcm-setup-reset"><input type="checkbox" name="reset_stages" value="1"> <?php esc_html_e( 'Reset to the process’s stages on save', 'pcm-crm' ); ?></label>
					<?php endif; ?>
				</p>
			</div>

			<div class="pcm-crm-card">
				<h2><?php esc_html_e( 'Time entry', 'pcm-crm' ); ?></h2>
				<p class="description"><?php esc_html_e( 'How time is logged against projects of this type. Each starts from the process, and is applied when an entry is saved.', 'pcm-crm' ); ?></p>
				<input type="hidden" name="time_posted" value="1">
				<table class="form-table" role="presentation">
					<?php foreach ( pcm_crm_pm_time_rule_labels() as $pcm_rule => $pcm_label ) : ?>
						<?php if ( ! isset( $pcm_time[ $pcm_rule ] ) ) { continue; } ?>
						<tr>
							<th scope="row"><?php echo esc_html( $pcm_label[0] ); ?></th>
							<td>
								<label><input type="checkbox" name="time[<?php echo esc_attr( $pcm_rule ); ?>]" value="1" <?php checked( ! empty( $pcm_time[ $pcm_rule ] ) ); ?>> <?php esc_html_e( 'Yes', 'pcm-crm' ); ?></label>
								<?php if ( $pcm_label[1] ) : ?>
									<p class="description"><?php echo esc_html( $pcm_label[1] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<label class="pcm-setup-reset"><input type="checkbox" name="reset_time" value="1"> <?php esc_html_e( 'Reset to the process’s rules on save', 'pcm-crm' ); ?></label>
			</div>

			<div class="pcm-crm-card">
				<h2><?php esc_html_e( 'Defaults for new projects', 'pcm-crm' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$pcm_hidden = pcm_crm_pm_type_fields( $pcm_key )['hidden'];
					$pcm_inputs = array(
						'default_bill_rate' => __( 'Bill rate', 'pcm-crm' ),
						'default_cost_rate' => __( 'Cost rate', 'pcm-crm' ),
						'retainer_hours'    => __( 'Hours per period', 'pcm-crm' ),
					);
					foreach ( $pcm_inputs as $pcm_name => $pcm_label ) :
						if ( in_array( $pcm_name, $pcm_hidden, true ) ) { continue; }
						?>
						<tr>
							<th scope="row"><label for="pcm-default-<?php echo esc_attr( $pcm_name ); ?>"><?php echo esc_html( $pcm_label ); ?></label></th>
							<td><input type="number" step="0.01" min="0" class="small-text" id="pcm-default-<?php echo esc_attr( $pcm_name ); ?>"
								name="defaults[<?php echo esc_attr( $pcm_name ); ?>]" value="<?php echo esc_attr( isset( $pcm_defaults[ $pcm_name ] ) ? $pcm_defaults[ $pcm_name ] : '' ); ?>"></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! in_array( 'retainer_period', $pcm_hidden, true ) ) : ?>
						<tr>
							<th scope="row"><label for="pcm-default-period"><?php esc_html_e( 'Retainer period', 'pcm-crm' ); ?></label></th>
							<td>
								<select id="pcm-default-period" name="defaults[retainer_period]">
									<option value=""><?php esc_html_e( '—', 'pcm-crm' ); ?></option>
									<?php foreach ( pcm_crm_pm_periods() as $pcm_period => $pcm_period_label ) : ?>
										<option value="<?php echo esc_attr( $pcm_period ); ?>" <?php selected( isset( $pcm_defaults['retainer_period'] ) ? $pcm_defaults['retainer_period'] : '', $pcm_period ); ?>><?php echo esc_html( $pcm_period_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</div>
		<?php endif; ?>

		<?php submit_button( $pcm_is_new ? __( 'Create Project Type', 'pcm-crm' ) : __( 'Save Project Type', 'pcm-crm' ) ); ?>
	</form>

	<script>
	( function () {
		var table = document.querySelector( '[data-role="stages"] tbody' );
		var template = document.querySelector( '[data-role="stage-template"]' );
		var add = document.querySelector( '[data-role="add-stage"]' );
		if ( ! table || ! template || ! add ) { return; }

		// Row inputs are named stages[n][...]; renumbered after every change so
		// the posted order is the order on screen.
		function renumber() {
			Array.prototype.forEach.call( table.rows, function ( row, i ) {
				row.querySelectorAll( '[name]' ).forEach( function ( input ) {
					input.name = input.name.replace( /stages\[[^\]]*\]/, 'stages[' + i + ']' );
				} );
			} );
		}

		add.addEventListener( 'click', function () {
			table.insertAdjacentHTML( 'beforeend', template.innerHTML );
			renumber();
			table.rows[ table.rows.length - 1 ].querySelector( 'input[type="text"]' ).focus();
		} );

		table.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button[data-move]' );
			if ( ! button ) { return; }

			var row = button.closest( 'tr' );
			var move = button.getAttribute( 'data-move' );

			if ( move === 'up' && row.previousElementSibling ) { table.insertBefore( row, row.previousElementSibling ); }
			if ( move === 'down' && row.nextElementSibling ) { table.insertBefore( row.nextElementSibling, row ); }
			if ( move === 'remove' ) { row.remove(); }

			renumber();
			button.focus();
		} );
	} )();
	</script>
	<?php
}

function pcm_crm_pm_render_stage_row( $pcm_i, array $pcm_stage, $pcm_count ) {
	$pcm_base = 'stages[' . $pcm_i . ']';
	?>
	<tr>
		<td><input type="text" class="regular-text" aria-label="<?php esc_attr_e( 'Stage name', 'pcm-crm' ); ?>" name="<?php echo esc_attr( $pcm_base ); ?>[name]" value="<?php echo esc_attr( $pcm_stage['name'] ); ?>"></td>
		<td><input type="checkbox" aria-label="<?php esc_attr_e( 'Active', 'pcm-crm' ); ?>" name="<?php echo esc_attr( $pcm_base ); ?>[is_active]" value="1" <?php checked( ! empty( $pcm_stage['is_active'] ) ); ?>></td>
		<td><input type="checkbox" aria-label="<?php esc_attr_e( 'Closes', 'pcm-crm' ); ?>" name="<?php echo esc_attr( $pcm_base ); ?>[is_closed]" value="1" <?php checked( ! empty( $pcm_stage['is_closed'] ) ); ?>></td>
		<td><input type="checkbox" aria-label="<?php esc_attr_e( 'Renewal', 'pcm-crm' ); ?>" name="<?php echo esc_attr( $pcm_base ); ?>[is_renewal]" value="1" <?php checked( ! empty( $pcm_stage['is_renewal'] ) ); ?>></td>
		<td><?php echo esc_html( number_format_i18n( (int) $pcm_count ) ); ?></td>
		<td class="pcm-setup-stage-move">
			<button type="button" class="button-link" data-move="up" aria-label="<?php esc_attr_e( 'Move up', 'pcm-crm' ); ?>">↑</button>
			<button type="button" class="button-link" data-move="down" aria-label="<?php esc_attr_e( 'Move down', 'pcm-crm' ); ?>">↓</button>
			<?php if ( ! $pcm_count ) : ?>
				<button type="button" class="button-link button-link-delete" data-move="remove" aria-label="<?php esc_attr_e( 'Remove stage', 'pcm-crm' ); ?>">×</button>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

function pcm_crm_pm_render_time_page() {
	$pcm_settings = pcm_crm_pm_time_settings();
	$pcm_name     = PCM_CRM_PM_TIME_OPTION;
	?>
	<form method="post" action="options.php" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_pm_time_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pcm-time-max"><?php esc_html_e( 'Most hours in one entry', 'pcm-crm' ); ?></label></th>
				<td>
					<input type="number" min="1" max="24" step="0.5" class="small-text" id="pcm-time-max" name="<?php echo esc_attr( $pcm_name ); ?>[max_hours]" value="<?php echo esc_attr( $pcm_settings['max_hours'] ); ?>">
					<p class="description"><?php esc_html_e( 'An entry above this is refused as a likely typo — usually a date in the hours box.', 'pcm-crm' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pcm-time-increment"><?php esc_html_e( 'Round entries to', 'pcm-crm' ); ?></label></th>
				<td>
					<select id="pcm-time-increment" name="<?php echo esc_attr( $pcm_name ); ?>[increment]">
						<?php foreach ( array( '0' => __( 'No rounding', 'pcm-crm' ), '0.1' => __( 'Six minutes (0.1h)', 'pcm-crm' ), '0.25' => __( 'Quarter hours', 'pcm-crm' ), '0.5' => __( 'Half hours', 'pcm-crm' ) ) as $pcm_value => $pcm_label ) : ?>
							<option value="<?php echo esc_attr( $pcm_value ); ?>" <?php selected( (string) (float) $pcm_settings['increment'], (string) (float) $pcm_value ); ?>><?php echo esc_html( $pcm_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Applied as hours are typed, rounding up.', 'pcm-crm' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Future dates', 'pcm-crm' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $pcm_name ); ?>[allow_future]" value="1" <?php checked( ! empty( $pcm_settings['allow_future'] ) ); ?>> <?php esc_html_e( 'Allow time to be logged ahead of the day', 'pcm-crm' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="pcm-time-lock"><?php esc_html_e( 'Lock entries after', 'pcm-crm' ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" class="small-text" id="pcm-time-lock" name="<?php echo esc_attr( $pcm_name ); ?>[lock_after_days]" value="<?php echo esc_attr( $pcm_settings['lock_after_days'] ); ?>">
					<?php esc_html_e( 'days', 'pcm-crm' ); ?>
					<p class="description"><?php esc_html_e( 'Older entries cannot be added or changed, since they may already be invoiced. 0 never locks.', 'pcm-crm' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Weeks start on', 'pcm-crm' ); ?></th>
				<td>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to WordPress's General Settings */
							esc_html__( 'The timesheet and the resourcing board follow the site’s own setting, under %s.', 'pcm-crm' ),
							'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings › General', 'pcm-crm' ) . '</a>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}

function pcm_crm_pm_render_picklists_page() {
	$pcm_name = PCM_CRM_PM_PICKLISTS_OPTION;
	?>
	<form method="post" action="options.php" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_pm_picklist_settings' ); ?>
		<p class="description"><?php esc_html_e( 'One choice per line. Removing a choice does not change records that already use it.', 'pcm-crm' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pcm-pick-roles"><?php esc_html_e( 'Project roles', 'pcm-crm' ); ?></label></th>
				<td><textarea class="large-text" rows="8" id="pcm-pick-roles" name="<?php echo esc_attr( $pcm_name ); ?>[roles]"><?php echo esc_textarea( implode( "\n", pcm_crm_pm_roles() ) ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="pcm-pick-raid"><?php esc_html_e( 'RAID kinds', 'pcm-crm' ); ?></label></th>
				<td><textarea class="large-text" rows="5" id="pcm-pick-raid" name="<?php echo esc_attr( $pcm_name ); ?>[raid_types]"><?php echo esc_textarea( implode( "\n", pcm_crm_pm_raid_types() ) ); ?></textarea></td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Fixed lists', 'pcm-crm' ); ?></h2>
		<p class="description"><?php esc_html_e( 'These carry meaning the software acts on — Done stamps a task’s completion date, Mitigated, Closed and Accepted close a RAID entry, and health is compared with the numbers — so they are not editable here.', 'pcm-crm' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Task statuses', 'pcm-crm' ); ?></th><td><?php echo esc_html( implode( ', ', pcm_crm_pm_task_statuses() ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'RAID statuses', 'pcm-crm' ); ?></th><td><?php echo esc_html( implode( ', ', pcm_crm_pm_raid_statuses() ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Health', 'pcm-crm' ); ?></th><td><?php echo esc_html( implode( ', ', pcm_crm_pm_health_options() ) ); ?></td></tr>
		</table>
	</div>
	<?php
}
