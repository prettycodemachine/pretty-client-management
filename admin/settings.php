<?php
/**
 * CRM → Settings: the contact form's recipient, the reply template, and the
 * files attached to it.
 *
 * This screen replaces Settings > Contact form from the theme. Option names
 * are unchanged, so whatever is saved on the live site is already here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings, grouped per tab.
 *
 * A group per tab rather than one for the lot: options.php writes every
 * setting registered in the submitted group, so a shared group would have the
 * pipeline form wiping the autoresponder checkbox simply because that tab's
 * form did not include it.
 */
function pcm_crm_register_settings() {
	pcm_crm_register_setting( 'pcm_crm_form_settings', 'pcm_contact_recipient', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_email',
		'default'           => get_option( 'admin_email' ),
	) );
	pcm_crm_register_setting( 'pcm_crm_form_settings', 'pcm_autoresponder_enabled', array(
		'type'              => 'string',
		'sanitize_callback' => 'pcm_crm_sanitize_checkbox',
		'default'           => '1',
	) );
	pcm_crm_register_setting( 'pcm_crm_form_settings', 'pcm_autoresponder_subject', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => pcm_crm_autoresponder_default_subject(),
	) );
	pcm_crm_register_setting( 'pcm_crm_form_settings', 'pcm_autoresponder_body', array(
		'type'              => 'string',
		'sanitize_callback' => 'wp_kses_post',
		'default'           => '',
	) );
	pcm_crm_register_setting( 'pcm_crm_form_settings', 'pcm_crm_attachment_ids', array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_sanitize_ids',
		'default'           => array(),
	) );
	pcm_crm_register_setting( 'pcm_crm_form_settings', 'pcm_crm_email_logo', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
	pcm_crm_register_setting( 'pcm_crm_form_settings', 'pcm_crm_form_button', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	pcm_crm_register_setting( 'pcm_crm_form_settings', PCM_CRM_FIELDS_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_sanitize_form_fields',
		'default'           => array(),
	) );

	pcm_crm_register_setting( 'pcm_crm_fields_settings', PCM_CRM_CUSTOM_FIELDS_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_sanitize_custom_fields',
		'default'           => array(),
	) );
	pcm_crm_register_setting( 'pcm_crm_fields_settings', PCM_CRM_LAYOUTS_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_sanitize_layouts',
		'default'           => array(),
	) );

	pcm_crm_register_setting( 'pcm_crm_export_settings', 'pcm_crm_npsp_namespace', array(
		'type'              => 'string',
		'sanitize_callback' => 'pcm_crm_sanitize_checkbox',
		'default'           => '',
	) );

	pcm_crm_register_setting( 'pcm_crm_theme_settings', 'pcm_crm_theme', array(
		'type'              => 'string',
		'sanitize_callback' => 'pcm_crm_sanitize_theme',
		'default'           => 'pcm',
	) );

	pcm_crm_register_setting( 'pcm_crm_modules_settings', PCM_CRM_MODULES_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_sanitize_modules',
		'default'           => array(),
	) );

	pcm_crm_register_setting( 'pcm_crm_pipeline_settings', 'pcm_crm_stall_days', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 30,
	) );

	pcm_crm_register_setting( 'pcm_crm_sales_process_settings', 'pcm_crm_stages', array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_sanitize_stage_probabilities',
	) );
}
add_action( 'admin_init', 'pcm_crm_register_settings' );

/**
 * The pages on the main PCM Settings screen, as ?tab= key => label.
 *
 * Derived from the Setup registry (includes/setup.php) rather than listed here,
 * so a module's pages count without this file naming them.
 */
function pcm_crm_settings_tabs() {
	$pcm_tabs = array();

	foreach ( pcm_crm_setup_pages() as $pcm_key => $pcm_page ) {
		if ( PCM_CRM_SETUP_SLUG === $pcm_page['page'] ) {
			$pcm_tabs[ $pcm_key ] = $pcm_page['label'];
		}
	}

	return $pcm_tabs;
}

function pcm_crm_current_settings_tab() {
	return pcm_crm_current_setup_key();
}

function pcm_crm_sanitize_checkbox( $pcm_value ) {
	return $pcm_value ? '1' : '';
}

/**
 * Which modules are on.
 *
 * Written as an explicit 1 or 0 for every registered module rather than as a
 * list of the ticked ones, because an unchecked box posts nothing at all — and a
 * missing key would fall back to the module's default, which would make a module
 * defaulting to on impossible to switch off.
 *
 * Unregistered keys are dropped, so a module that has been removed does not
 * leave a setting behind that nothing reads.
 */
function pcm_crm_sanitize_modules( $pcm_value ) {
	$pcm_value = is_array( $pcm_value ) ? $pcm_value : array();
	$pcm_out   = array();

	foreach ( pcm_crm_modules() as $pcm_slug => $pcm_module ) {
		$pcm_out[ $pcm_slug ] = empty( $pcm_value[ $pcm_slug ] ) ? 0 : 1;
	}

	// A module submitted on cannot outlive a requirement submitted off in the
	// same save — pcm_crm_module_active() would refuse it anyway, so leaving
	// the option saying otherwise would just be a setting that lies. Generic
	// over 'requires', so a third module needing the same guarantee needs no
	// code here.
	foreach ( pcm_crm_modules() as $pcm_slug => $pcm_module ) {
		if ( empty( $pcm_out[ $pcm_slug ] ) || empty( $pcm_module['requires'] ) ) {
			continue;
		}

		foreach ( (array) $pcm_module['requires'] as $pcm_required ) {
			if ( empty( $pcm_out[ $pcm_required ] ) ) {
				$pcm_out[ $pcm_slug ] = 0;

				add_settings_error(
					PCM_CRM_MODULES_OPTION,
					'pcm_crm_module_requires',
					sprintf(
						/* translators: 1: the module that was turned off, 2: the module it needs */
						__( '“%1$s” needs “%2$s” switched on, so it was left off.', 'pcm-crm' ),
						$pcm_module['label'],
						pcm_crm_modules()[ $pcm_required ]['label']
					)
				);

				break;
			}
		}
	}

	return $pcm_out;
}

/**
 * The attachment list posts as a comma-separated string from the picker, since
 * a hidden input cannot hold an array without one field per value.
 */
function pcm_crm_sanitize_ids( $pcm_value ) {
	if ( is_string( $pcm_value ) ) {
		$pcm_value = explode( ',', $pcm_value );
	}

	if ( ! is_array( $pcm_value ) ) {
		return array();
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $pcm_value ) ) ) );
}

/**
 * The media picker needs wp.media, which is not loaded on a plugin page by
 * default.
 */
function pcm_crm_settings_assets( $pcm_hook ) {
	if ( false === strpos( $pcm_hook, 'pcm-crm-settings' ) ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_script( 'pcm-crm-settings', pcm_crm_asset( 'settings.js' ), array( 'jquery' ), null, true );
}
add_action( 'admin_enqueue_scripts', 'pcm_crm_settings_assets' );

/**
 * The front-end twin of pcm_crm_settings_assets() — the employee portal's
 * /staff/settings/ route needs the same media picker and settings.js that
 * every wp-admin PCM Settings tab already gets, gated on the query var the
 * front-end router sets rather than a hook suffix that does not exist here.
 */
function pcm_crm_settings_front_assets() {
	if ( 'settings' !== get_query_var( 'pcm_crm_screen', '' ) ) {
		return;
	}

	wp_enqueue_style( 'buttons' );
	wp_enqueue_media();
	wp_enqueue_script( 'pcm-crm-settings', pcm_crm_asset( 'settings.js' ), array( 'jquery' ), null, true );
}
add_action( 'wp_enqueue_scripts', 'pcm_crm_settings_front_assets' );

// pcm_crm_render_settings() lives in includes/setup.php, with the frame it draws.

/* ---------------------------------------------------------------------------
   Contact form tab
   --------------------------------------------------------------------------- */

function pcm_crm_render_form_tab() {
	$pcm_missing = pcm_crm_missing_attachment_ids();
	$pcm_logo    = absint( get_option( 'pcm_crm_email_logo', 0 ) );
	?>
	<?php if ( $pcm_missing ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated list of media IDs */
					esc_html__( 'These attachments no longer resolve to a file and will be skipped: %s', 'pcm-crm' ),
					esc_html( implode( ', ', $pcm_missing ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Put the form on a page', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Paste this shortcode into any page or post. The Contact page template already includes it.', 'pcm-crm' ); ?>
		</p>
		<div class="pcm-crm-embed">
			<code>[pcm_contact_form]</code>
			<button type="button" class="button" data-role="copy-shortcode" data-shortcode="[pcm_contact_form]">
				<?php esc_html_e( 'Copy', 'pcm-crm' ); ?>
			</button>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_form_settings' ); ?>

		<h2><?php esc_html_e( 'Form fields', 'pcm-crm' ); ?></h2>
		<p class="description" style="margin-bottom:14px">
			<?php esc_html_e( 'What the form asks for, in order. “Stored as” decides which CRM record the answer lands on — a field stored nowhere is still recorded with the submission and still available as a merge field.', 'pcm-crm' ); ?>
		</p>

		<?php pcm_crm_render_field_builder(); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pcm_crm_form_button"><?php esc_html_e( 'Button label', 'pcm-crm' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="pcm_crm_form_button" name="pcm_crm_form_button"
						value="<?php echo esc_attr( get_option( 'pcm_crm_form_button', '' ) ); ?>"
						placeholder="<?php esc_attr_e( 'Send message', 'pcm-crm' ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pcm_contact_recipient"><?php esc_html_e( 'Send submissions to', 'pcm-crm' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="pcm_contact_recipient" name="pcm_contact_recipient"
						value="<?php echo esc_attr( pcm_crm_contact_recipient() ); ?>">
					<p class="description"><?php esc_html_e( 'Every submission is emailed here, and recorded in the CRM either way.', 'pcm-crm' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'The reply it sends', 'pcm-crm' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Autoresponder', 'pcm-crm' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="pcm_autoresponder_enabled" value="1" <?php checked( pcm_crm_autoresponder_enabled() ); ?>>
						<?php esc_html_e( 'Send an automatic reply to the person who submitted the form', 'pcm-crm' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pcm_autoresponder_subject"><?php esc_html_e( 'Reply subject', 'pcm-crm' ); ?></label></th>
				<td>
					<input type="text" class="large-text" id="pcm_autoresponder_subject" name="pcm_autoresponder_subject"
						value="<?php echo esc_attr( pcm_crm_autoresponder_subject() ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Email logo', 'pcm-crm' ); ?></th>
				<td>
					<div class="pcm-crm-logo" data-role="logo">
						<div class="pcm-crm-logo-preview" data-role="logo-preview">
							<?php if ( $pcm_logo ) : ?>
								<?php echo wp_get_attachment_image( $pcm_logo, 'medium' ); ?>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'No logo uploaded — the theme’s logo is used.', 'pcm-crm' ); ?></span>
							<?php endif; ?>
						</div>
						<input type="hidden" name="pcm_crm_email_logo" data-role="logo-id" value="<?php echo esc_attr( $pcm_logo ); ?>">
						<p>
							<button type="button" class="button" data-role="logo-choose"><?php esc_html_e( 'Choose logo', 'pcm-crm' ); ?></button>
							<button type="button" class="button-link" data-role="logo-remove"<?php echo $pcm_logo ? '' : ' hidden'; ?>>
								<?php esc_html_e( 'Remove', 'pcm-crm' ); ?>
							</button>
						</p>
					</div>
					<p class="description">
						<?php esc_html_e( 'Centred along the top of every email the CRM sends. Around 300px wide is plenty — it is displayed at 150px, and twice that keeps it sharp on a retina screen.', 'pcm-crm' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Attachments', 'pcm-crm' ); ?></th>
				<td>
					<div class="pcm-crm-attachments" data-role="attachments">
						<ul class="pcm-crm-attachment-list" data-role="attachment-list">
							<?php foreach ( pcm_crm_attachment_ids() as $pcm_id ) : ?>
								<?php
								$pcm_path   = get_attached_file( $pcm_id );
								$pcm_label  = $pcm_path ? basename( $pcm_path ) : '';
								$pcm_broken = ! $pcm_path || ! is_readable( $pcm_path );
								?>
								<li class="pcm-crm-attachment<?php echo $pcm_broken ? ' is-broken' : ''; ?>" data-id="<?php echo esc_attr( $pcm_id ); ?>">
									<span class="pcm-crm-attachment-name">
										<?php echo esc_html( $pcm_label ? $pcm_label : sprintf( /* translators: %d: media ID */ __( 'Missing file (ID %d)', 'pcm-crm' ), $pcm_id ) ); ?>
									</span>
									<button type="button" class="button-link pcm-crm-attachment-remove" aria-label="<?php esc_attr_e( 'Remove attachment', 'pcm-crm' ); ?>">&times;</button>
								</li>
							<?php endforeach; ?>
						</ul>
						<input type="hidden" name="pcm_crm_attachment_ids" data-role="attachment-ids"
							value="<?php echo esc_attr( implode( ',', pcm_crm_attachment_ids() ) ); ?>">
						<button type="button" class="button" data-role="attachment-add"><?php esc_html_e( 'Add attachment', 'pcm-crm' ); ?></button>
					</div>
					<p class="description">
						<?php esc_html_e( 'Attached to every automatic reply, in this order. A file deleted from the media library is skipped rather than breaking the send.', 'pcm-crm' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Reply message', 'pcm-crm' ); ?></h3>
		<p class="description" style="margin-bottom:8px">
			<?php esc_html_e( 'Click a merge field to insert it where the cursor is. They are built from the form’s own fields, so one can never name a question you removed.', 'pcm-crm' ); ?>
		</p>
		<div class="pcm-crm-tokens" data-role="tokens">
			<?php foreach ( pcm_crm_tokens() as $pcm_token ) : ?>
				<button type="button" class="pcm-crm-token" data-token="<?php echo esc_attr( $pcm_token ); ?>">
					<?php echo esc_html( $pcm_token ); ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php if ( pcm_crm_is_front_request() ) : ?>
			<?php
			// A plain textarea on this host, not a compromise: wp_editor()
			// needs TinyMCE plus wp-admin's own CSS, the heaviest single
			// dependency this screen could pull in, and pcm_crm_format_body()
			// already treats a textarea's contents identically to what
			// wp_editor()'s own Text tab stores — paragraphs separated by
			// blank lines, no <p> tags. Same option, edited two ways, runs
			// the identical path.
			?>
			<textarea id="pcm_autoresponder_body" name="pcm_autoresponder_body" rows="14"
				class="large-text"><?php echo esc_textarea( pcm_crm_autoresponder_body() ); ?></textarea>
		<?php else : ?>
			<?php
			wp_editor(
				pcm_crm_autoresponder_body(),
				'pcm_autoresponder_body',
				array( 'textarea_name' => 'pcm_autoresponder_body', 'textarea_rows' => 14, 'media_buttons' => false )
			);
			?>
		<?php endif; ?>
		<p class="description" style="margin-top:8px">
			<?php esc_html_e( 'The message is wrapped in the branded email layout automatically — no need to add a logo or signature styling here.', 'pcm-crm' ); ?>
		</p>

		<?php submit_button(); ?>
	</form>

	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Send a test', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Sends the saved reply — attachments and all — using placeholder values, so you can see what a visitor receives. Save your changes first.', 'pcm-crm' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pcm_crm_test_email">
			<?php wp_nonce_field( 'pcm_crm_test_email', 'pcm_crm_test_nonce' ); ?>
			<input type="email" class="regular-text" name="pcm_crm_test_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
			<?php submit_button( __( 'Send test email', 'pcm-crm' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

/**
 * The field list.
 *
 * Rows are plain markup with hidden ordering inputs rather than a drag-and-drop
 * canvas: reordering with buttons is unambiguous, works by keyboard, and does
 * not need a library the rest of this plugin manages without.
 */
function pcm_crm_render_field_builder() {
	$pcm_fields = pcm_crm_form_fields();
	?>
	<div class="pcm-crm-builder-fields-list" data-role="form-fields">
		<?php foreach ( $pcm_fields as $pcm_index => $pcm_field ) : ?>
			<?php pcm_crm_render_field_row( $pcm_index, $pcm_field ); ?>
		<?php endforeach; ?>
	</div>

	<p>
		<button type="button" class="button" data-role="add-field"><?php esc_html_e( 'Add field', 'pcm-crm' ); ?></button>
	</p>

	<script type="text/html" id="tmpl-pcm-crm-field-row">
		<?php pcm_crm_render_field_row( '__index__', array( 'key' => '', 'label' => '', 'type' => 'text', 'required' => 0, 'map' => '' ) ); ?>
	</script>
	<?php
}

function pcm_crm_render_field_row( $pcm_index, array $pcm_field ) {
	$pcm_name = PCM_CRM_FIELDS_OPTION . '[' . $pcm_index . ']';
	$pcm_type = isset( $pcm_field['type'] ) ? $pcm_field['type'] : 'text';
	$pcm_options = ( ! empty( $pcm_field['source'] ) && 'interests' === $pcm_field['source'] )
		? ''
		: implode( "\n", pcm_crm_field_options( $pcm_field ) );
	?>
	<div class="pcm-crm-field-row" data-role="field-row">
		<div class="pcm-crm-field-row-head">
			<span class="pcm-crm-field-handle" aria-hidden="true">☰</span>
			<input type="text" class="pcm-crm-field-label" name="<?php echo esc_attr( $pcm_name ); ?>[label]"
				value="<?php echo esc_attr( isset( $pcm_field['label'] ) ? $pcm_field['label'] : '' ); ?>"
				placeholder="<?php esc_attr_e( 'Field label', 'pcm-crm' ); ?>">

			<select name="<?php echo esc_attr( $pcm_name ); ?>[type]" data-role="field-type">
				<?php foreach ( pcm_crm_form_field_types() as $pcm_value => $pcm_label ) : ?>
					<option value="<?php echo esc_attr( $pcm_value ); ?>" <?php selected( $pcm_type, $pcm_value ); ?>>
						<?php echo esc_html( $pcm_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="<?php echo esc_attr( $pcm_name ); ?>[map]">
				<?php foreach ( pcm_crm_form_field_targets() as $pcm_value => $pcm_label ) : ?>
					<option value="<?php echo esc_attr( $pcm_value ); ?>" <?php selected( isset( $pcm_field['map'] ) ? $pcm_field['map'] : '', $pcm_value ); ?>>
						<?php echo esc_html( $pcm_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="pcm-crm-field-toggle">
				<input type="checkbox" name="<?php echo esc_attr( $pcm_name ); ?>[required]" value="1" <?php checked( ! empty( $pcm_field['required'] ) ); ?>>
				<?php esc_html_e( 'Required', 'pcm-crm' ); ?>
			</label>

			<label class="pcm-crm-field-toggle">
				<input type="checkbox" name="<?php echo esc_attr( $pcm_name ); ?>[half]" value="1" <?php checked( ! empty( $pcm_field['half'] ) ); ?>>
				<?php esc_html_e( 'Half width', 'pcm-crm' ); ?>
			</label>

			<span class="pcm-crm-field-move">
				<button type="button" class="button-link" data-role="move-up" aria-label="<?php esc_attr_e( 'Move up', 'pcm-crm' ); ?>">↑</button>
				<button type="button" class="button-link" data-role="move-down" aria-label="<?php esc_attr_e( 'Move down', 'pcm-crm' ); ?>">↓</button>
				<button type="button" class="button-link pcm-crm-field-remove" data-role="remove-field" aria-label="<?php esc_attr_e( 'Remove field', 'pcm-crm' ); ?>">&times;</button>
			</span>
		</div>

		<div class="pcm-crm-field-row-body">
			<input type="hidden" name="<?php echo esc_attr( $pcm_name ); ?>[key]" value="<?php echo esc_attr( isset( $pcm_field['key'] ) ? $pcm_field['key'] : '' ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $pcm_name ); ?>[autocomplete]" value="<?php echo esc_attr( isset( $pcm_field['autocomplete'] ) ? $pcm_field['autocomplete'] : '' ); ?>">

			<?php if ( ! empty( $pcm_field['source'] ) && 'interests' === $pcm_field['source'] ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $pcm_name ); ?>[source]" value="interests">
				<p class="description">
					<?php esc_html_e( 'Choices come from the site’s service offerings, so the form and the services pages cannot disagree about what is on offer.', 'pcm-crm' ); ?>
				</p>
			<?php else : ?>
				<label class="pcm-crm-field-options" data-role="field-options"<?php echo 'select' === $pcm_type ? '' : ' hidden'; ?>>
					<span class="description"><?php esc_html_e( 'Dropdown choices, one per line', 'pcm-crm' ); ?></span>
					<textarea name="<?php echo esc_attr( $pcm_name ); ?>[options]" rows="3"><?php echo esc_textarea( $pcm_options ); ?></textarea>
				</label>
			<?php endif; ?>

			<?php if ( ! empty( $pcm_field['key'] ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'Merge field:', 'pcm-crm' ); ?>
					<code><?php echo esc_html( pcm_crm_field_token( $pcm_field ) ); ?></code>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
   Data export tab
   --------------------------------------------------------------------------- */

/**
 * The objects worth exporting, in the order Salesforce needs them loaded.
 *
 * Order matters and is the single most common way a Data Loader run goes
 * wrong: a Contact cannot reference an Account that does not exist yet. It is
 * the object registry's registration order, which is why objects register in
 * dependency order rather than alphabetically.
 */
function pcm_crm_exportable_objects() {
	$pcm_out = array();

	foreach ( pcm_crm_objects_where( 'exportable' ) as $pcm_slug => $pcm_object ) {
		$pcm_out[ $pcm_slug ] = array(
			'label' => $pcm_object['plural'],
			'sf'    => $pcm_object['sf'],
		);
	}

	return $pcm_out;
}

/**
 * Is a header a Salesforce standard field, or one this CRM invented?
 *
 * Custom fields end in __c by Salesforce's own convention, so the test is the
 * convention rather than a list that would need maintaining beside the maps.
 */
function pcm_crm_is_custom_field( $pcm_api_name ) {
	return (bool) preg_match( '/__c$/', $pcm_api_name );
}

function pcm_crm_render_export_tab() {
	?>
	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Two ways out', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Which one you want depends on the org you are loading into, not on the data. A Sales Cloud org takes one file per object, loaded in order. An org running the Nonprofit Success Pack is better served by NPSP’s own Data Import, which takes a person and their organization on one row and does the matching itself.', 'pcm-crm' ); ?>
		</p>
	</div>

	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Sales Cloud — Data Loader', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'One file per object. Column headers use Salesforce API names wherever the value can be loaded as it stands, so Data Loader maps most of them itself.', 'pcm-crm' ); ?>
		</p>

		<table class="pcm-crm-table pcm-crm-export-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Object', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Loads into', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Records', 'pcm-crm' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( pcm_crm_exportable_objects() as $pcm_slug => $pcm_meta ) : ?>
					<?php $pcm_model = PCM_CRM_REST::model( $pcm_slug ); ?>
					<tr>
						<td class="pcm-crm-strong"><?php echo esc_html( $pcm_meta['label'] ); ?></td>
						<td><code><?php echo esc_html( $pcm_meta['sf'] ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $pcm_model ? $pcm_model->count() : 0 ) ); ?></td>
						<td>
							<a class="button button-primary" href="<?php echo esc_url( pcm_crm_export_url( $pcm_slug ) ); ?>">
								<?php esc_html_e( 'Download CSV', 'pcm-crm' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<ol class="pcm-crm-steps">
			<li>
				<strong><?php esc_html_e( 'Create the custom fields first.', 'pcm-crm' ); ?></strong>
				<?php esc_html_e( 'Every header ending in __c is a field this CRM invented and a new org does not have. Add them under Setup → Object Manager → Fields & Relationships, or Data Loader will have nowhere to put those columns.', 'pcm-crm' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Load in the order above.', 'pcm-crm' ); ?></strong>
				<?php esc_html_e( 'Accounts, then Contacts, then Opportunities, then Activities. A Contact cannot reference an Account that does not exist yet, which is the usual way one of these runs fails.', 'pcm-crm' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Translate the id columns as you go.', 'pcm-crm' ); ?></strong>
				<?php esc_html_e( 'PCM_Account_Id__c and PCM_Contact_Id__c hold this CRM’s ids, not Salesforce ones — which is why they are named that way rather than AccountId, where Data Loader would map them automatically and reject every row. Keep each success file, then look the real Salesforce Id up against PCM_Id__c before loading the next object.', 'pcm-crm' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Keep PCM_Id__c as an External ID.', 'pcm-crm' ); ?></strong>
				<?php esc_html_e( 'Tick External ID when you create the field, and every later load becomes an upsert rather than a fresh set of duplicates. It also makes a failed migration safe to re-run.', 'pcm-crm' ); ?>
			</li>
		</ol>

		<p class="description">
			<?php esc_html_e( 'CreatedDate and LastModifiedDate are read-only unless the org has Set Audit Fields enabled — leave them unmapped otherwise. The owner and audit columns hold WordPress user ids, so they are only useful as a record of who did what here.', 'pcm-crm' ); ?>
		</p>
	</div>

	<div class="pcm-crm-card pcm-crm-card-accent">
		<h2><?php esc_html_e( 'NPSP — Data Import', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'One file, loaded into the NPSP Data Import object, then processed from NPSP Settings → Bulk Data Processes → Data Import. Each row carries a person and their organization together, and NPSP creates the household or organization account, matches existing contacts, and links them — so there are no ids to translate between files.', 'pcm-crm' ); ?>
		</p>

		<p>
			<a class="button button-primary" href="<?php echo esc_url( pcm_crm_npsp_export_url( false ) ); ?>">
				<?php esc_html_e( 'Download contacts and organizations', 'pcm-crm' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( pcm_crm_npsp_export_url( true ) ); ?>">
				<?php esc_html_e( 'Download with won deals as donations', 'pcm-crm' ); ?>
			</a>
		</p>

		<ol class="pcm-crm-steps">
			<li>
				<strong><?php esc_html_e( 'Check the field names against your org.', 'pcm-crm' ); ?></strong>
				<?php esc_html_e( 'NPSP’s Data Import fields are named differently depending on how NPSP was installed. Open the Data Import object in Object Manager and see whether its fields start with npsp__ — then set the box below to match. Getting it wrong means every column fails to map, which is obvious immediately and harmless.', 'pcm-crm' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Load the file into Data Import.', 'pcm-crm' ); ?></strong>
				<?php esc_html_e( 'Use Data Loader — Insert, object DataImport__c — or the NPSP Data Importer app if your org has it. Nothing is created in the CRM yet at this point; these are staging rows.', 'pcm-crm' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Dry run before you process.', 'pcm-crm' ); ?></strong>
				<?php esc_html_e( 'NPSP Settings → Bulk Data Processes → Data Import has a dry run that reports what it would match and what it would create, without writing anything. It is the whole reason to prefer this route: you can see the duplicate handling before it happens.', 'pcm-crm' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Process, then check the failures.', 'pcm-crm' ); ?></strong>
				<?php esc_html_e( 'Rows that fail stay in Data Import with the reason on them, so they can be corrected and re-processed rather than re-loaded from scratch.', 'pcm-crm' ); ?>
			</li>
		</ol>

		<p class="description">
			<?php esc_html_e( 'Only closed-won deals go in the donations file. NPSP creates a received donation per row and has no pipeline stage to carry, so an open deal loaded this way would post as income you have not had.', 'pcm-crm' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'pcm_crm_export_settings' ); ?>
			<p>
				<label>
					<input type="checkbox" name="pcm_crm_npsp_namespace" value="1" <?php checked( (bool) get_option( 'pcm_crm_npsp_namespace', '' ) ); ?>>
					<?php esc_html_e( 'This org’s Data Import fields are prefixed with npsp__', 'pcm-crm' ); ?>
				</label>
			</p>
			<?php submit_button( __( 'Save export settings', 'pcm-crm' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>

	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Column headers', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'What each CRM field is called in the Sales Cloud files. Grey names are Salesforce standard fields and map themselves. Names ending in __c are custom and have to exist in the org first — including the id columns, which deliberately do not use Salesforce’s own names because they hold this CRM’s ids rather than Salesforce ones.', 'pcm-crm' ); ?>
		</p>

		<?php foreach ( pcm_crm_exportable_objects() as $pcm_slug => $pcm_meta ) : ?>
			<?php $pcm_model = PCM_CRM_REST::model( $pcm_slug ); ?>
			<?php if ( ! $pcm_model ) { continue; } ?>

			<h3><?php echo esc_html( $pcm_meta['label'] ); ?> &rarr; <code><?php echo esc_html( $pcm_meta['sf'] ); ?></code></h3>
			<table class="pcm-crm-table pcm-crm-mapping-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'CRM field', 'pcm-crm' ); ?></th>
						<th><?php esc_html_e( 'Column header', 'pcm-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Record id', 'pcm-crm' ); ?></td>
						<td><code class="pcm-crm-api-custom">PCM_Id__c</code></td>
					</tr>
					<?php $pcm_defs = $pcm_model->fields(); ?>
					<?php foreach ( $pcm_model->salesforce_map() as $pcm_field => $pcm_api ) : ?>
						<tr>
							<td><?php echo esc_html( ! empty( $pcm_defs[ $pcm_field ]['label'] ) ? $pcm_defs[ $pcm_field ]['label'] : $pcm_field ); ?></td>
							<td>
								<code class="<?php echo pcm_crm_is_custom_field( $pcm_api ) ? 'pcm-crm-api-custom' : 'pcm-crm-api-standard'; ?>">
									<?php echo esc_html( $pcm_api ); ?>
								</code>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
   Pipeline tab
   --------------------------------------------------------------------------- */

/**
 * The Modules tab.
 *
 * Switching a module off takes it out of the navigation and off the REST surface.
 * It does not remove anything: the tables stay, which is why switching it back on
 * is immediate and why nothing here warns about data loss — there is none.
 */
function pcm_crm_render_modules_tab() {
	$pcm_modules = pcm_crm_modules();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_modules_settings' ); ?>

		<p class="description">
			<?php esc_html_e( 'Extra toolsets that share this CRM’s data. Switching one off hides its screens and routes; nothing is deleted, and switching it back on restores it as it was.', 'pcm-crm' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<?php foreach ( $pcm_modules as $pcm_slug => $pcm_module ) : ?>
				<?php
				$pcm_missing = array();

				foreach ( (array) ( isset( $pcm_module['requires'] ) ? $pcm_module['requires'] : array() ) as $pcm_required ) {
					if ( ! pcm_crm_module_active( $pcm_required ) ) {
						$pcm_missing[] = $pcm_modules[ $pcm_required ]['label'];
					}
				}
				?>
				<tr>
					<th scope="row"><?php echo esc_html( $pcm_module['label'] ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="<?php echo esc_attr( PCM_CRM_MODULES_OPTION ); ?>[<?php echo esc_attr( $pcm_slug ); ?>]"
								value="1"
								<?php checked( pcm_crm_module_active( $pcm_slug ) ); ?>
								<?php disabled( (bool) $pcm_missing ); ?> />
							<?php esc_html_e( 'Enabled', 'pcm-crm' ); ?>
						</label>
						<p class="description"><?php echo esc_html( $pcm_module['description'] ); ?></p>
						<?php if ( $pcm_missing ) : ?>
							<?php /* A disabled-but-checked box is shown truthfully rather than
							         hidden, so an admin who switched a dependency off after this
							         one was on can see why it stopped working. */ ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: comma-separated module names */
									esc_html__( 'Needs %s switched on first.', 'pcm-crm' ),
									esc_html( implode( ', ', $pcm_missing ) )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<?php submit_button( __( 'Save Modules', 'pcm-crm' ) ); ?>
	</form>
	<?php
}

/**
 * Only probability is editable here — is_closed, is_won and the forecast
 * category are load-bearing everywhere from the pipeline board to the loss
 * rules, so this screen (unlike Salesforce's own Sales Process) leaves them
 * fixed rather than opening them to a value that could break both.
 */
function pcm_crm_sanitize_stage_probabilities( $pcm_value ) {
	$pcm_value = is_array( $pcm_value ) ? $pcm_value : array();
	$pcm_out   = array();

	foreach ( pcm_crm_default_stages() as $pcm_stage ) {
		if ( isset( $pcm_value[ $pcm_stage['name'] ] ) ) {
			$pcm_stage['probability'] = max( 0, min( 100, (int) $pcm_value[ $pcm_stage['name'] ] ) );
		}

		$pcm_out[] = $pcm_stage;
	}

	return $pcm_out;
}

function pcm_crm_render_sales_process_tab() {
	$pcm_stages = pcm_crm_stages();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_sales_process_settings' ); ?>
		<p class="description"><?php esc_html_e( 'Every deal’s probability follows its stage automatically — a hand-tuned figure on one deal still survives until that deal’s stage changes. These are the shipped defaults; change any of them to match how you actually sell.', 'pcm-crm' ); ?></p>
		<table class="widefat striped pcm-setup-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Stage', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Probability', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Forecast Category', 'pcm-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pcm_stages as $pcm_stage ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $pcm_stage['name'] ); ?></strong>
							<?php if ( ! empty( $pcm_stage['is_closed'] ) ) : ?>
								<span class="description">— <?php echo $pcm_stage['is_won'] ? esc_html__( 'won', 'pcm-crm' ) : esc_html__( 'lost', 'pcm-crm' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<input type="number" min="0" max="100" step="1" class="small-text"
								name="pcm_crm_stages[<?php echo esc_attr( $pcm_stage['name'] ); ?>]"
								value="<?php echo esc_attr( $pcm_stage['probability'] ); ?>"> %
						</td>
						<td><?php echo esc_html( $pcm_stage['forecast_category'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}

function pcm_crm_render_pipeline_tab() {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_pipeline_settings' ); ?>
		<h2><?php esc_html_e( 'Stalled deals', 'pcm-crm' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pcm_crm_stall_days"><?php esc_html_e( 'Stalled after', 'pcm-crm' ); ?></label></th>
				<td>
					<input type="number" min="1" step="1" class="small-text" id="pcm_crm_stall_days"
						name="pcm_crm_stall_days" value="<?php echo esc_attr( pcm_crm_stall_days() ); ?>">
					<?php esc_html_e( 'days in the same stage', 'pcm-crm' ); ?>
					<p class="description">
						<?php esc_html_e( 'How long an open deal may sit in one stage before the board flags it. A judgement about how you sell, not a fact about the software — a long enterprise cycle wants a higher number than a quick retainer.', 'pcm-crm' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}

/**
 * Send the saved reply to an address, with obviously-fake token values.
 */
function pcm_crm_handle_test_email() {
	if (
		! pcm_crm_can( 'settings', 'edit' ) ||
		! isset( $_POST['pcm_crm_test_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_POST['pcm_crm_test_nonce'] ), 'pcm_crm_test_email' )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	$pcm_to = isset( $_POST['pcm_crm_test_to'] ) ? sanitize_email( wp_unslash( $_POST['pcm_crm_test_to'] ) ) : '';
	$pcm_back = admin_url( 'admin.php?page=pcm-crm-settings' );

	if ( ! is_email( $pcm_to ) ) {
		wp_safe_redirect( add_query_arg( 'pcm_crm_test', 'invalid', $pcm_back ) );
		exit;
	}

	$pcm_sent = pcm_crm_send_autoresponder( array(
		'first'    => 'Sample',
		'last'     => 'Visitor',
		'org'      => 'Sample Organization',
		'email'    => $pcm_to,
		'interest' => 'Salesforce Managed Support',
		'message'  => 'This is a test.',
	) );

	wp_safe_redirect( add_query_arg( 'pcm_crm_test', $pcm_sent ? 'sent' : 'failed', $pcm_back ) );
	exit;
}
add_action( 'admin_post_pcm_crm_test_email', 'pcm_crm_handle_test_email' );

/**
 * Report the test result on the settings screen.
 *
 * A disabled autoresponder is called out separately: pcm_crm_send_autoresponder()
 * returns false for it, which would otherwise read as a mail failure.
 */
function pcm_crm_test_email_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	$pcm_result = isset( $_GET['pcm_crm_test'] ) ? sanitize_key( wp_unslash( $_GET['pcm_crm_test'] ) ) : '';

	if ( ! $pcm_result || ! pcm_crm_is_crm_screen() ) {
		return;
	}

	if ( 'sent' === $pcm_result ) {
		$pcm_class   = 'notice-success';
		$pcm_message = __( 'Test email sent.', 'pcm-crm' );
	} elseif ( 'invalid' === $pcm_result ) {
		$pcm_class   = 'notice-error';
		$pcm_message = __( 'That is not a valid email address.', 'pcm-crm' );
	} elseif ( ! pcm_crm_autoresponder_enabled() ) {
		$pcm_class   = 'notice-warning';
		$pcm_message = __( 'Nothing was sent — the autoresponder is switched off.', 'pcm-crm' );
	} else {
		$pcm_class   = 'notice-error';
		$pcm_message = __( 'The test email could not be sent. Check the site’s mail configuration.', 'pcm-crm' );
	}

	printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $pcm_class ), esc_html( $pcm_message ) );
}
add_action( 'admin_notices', 'pcm_crm_test_email_notice' );

/* ---------------------------------------------------------------------------
   Fields and layouts tab
   --------------------------------------------------------------------------- */

/**
 * Which object this screen is editing.
 *
 * One object at a time rather than all four on one page: a layout editor is
 * already a busy screen, and four of them would be unusable.
 */
function pcm_crm_current_fields_object() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
	$pcm_object = isset( $_GET['object'] ) ? sanitize_key( wp_unslash( $_GET['object'] ) ) : 'contacts';

	return isset( pcm_crm_customisable_objects()[ $pcm_object ] ) ? $pcm_object : 'contacts';
}

function pcm_crm_render_fields_tab() {
	$pcm_object = pcm_crm_current_fields_object();
	$pcm_model  = PCM_CRM_REST::model( $pcm_object );
	?>
	<div class="pcm-crm-object-switch">
		<?php foreach ( pcm_crm_customisable_objects() as $pcm_slug => $pcm_label ) : ?>
			<a class="pcm-crm-object-pill<?php echo $pcm_slug === $pcm_object ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( 'object', $pcm_slug, pcm_crm_setup_url( 'fields' ) ) ); ?>">
				<?php echo esc_html( $pcm_label ); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pcm-crm-fields-form" data-object="<?php echo esc_attr( $pcm_object ); ?>">
		<?php settings_fields( 'pcm_crm_fields_settings' ); ?>

		<div class="pcm-crm-card">
			<h2><?php esc_html_e( 'Custom fields', 'pcm-crm' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Each becomes a real column, so a custom field can be filtered, sorted, grouped and exported exactly like a built-in one. A field’s name is fixed once created — it is the column — but its label can change freely.', 'pcm-crm' ); ?>
			</p>

			<div class="pcm-crm-custom-fields" data-role="custom-fields">
				<?php foreach ( pcm_crm_custom_fields( $pcm_object ) as $pcm_index => $pcm_field ) : ?>
					<?php pcm_crm_render_custom_field_row( $pcm_object, $pcm_index, $pcm_field ); ?>
				<?php endforeach; ?>
			</div>

			<p>
				<button type="button" class="button" data-role="add-custom-field"><?php esc_html_e( 'Add custom field', 'pcm-crm' ); ?></button>
			</p>

			<script type="text/html" id="tmpl-pcm-crm-custom-field">
				<?php pcm_crm_render_custom_field_row( $pcm_object, '__index__', array( 'key' => '', 'label' => '', 'type' => 'text' ) ); ?>
			</script>
		</div>

		<div class="pcm-crm-card">
			<h2><?php esc_html_e( 'Page layout', 'pcm-crm' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Drag a field to move it, within a section or between them. Sections become the headed blocks on the record. Anything left in Available is simply not on the form — the data is still there, and still exported.', 'pcm-crm' ); ?>
			</p>

			<div class="pcm-crm-layout-editor" data-role="layout" data-object="<?php echo esc_attr( $pcm_object ); ?>">
				<div class="pcm-crm-layout-sections" data-role="sections">
					<?php foreach ( pcm_crm_layout( $pcm_object ) as $pcm_i => $pcm_section ) : ?>
						<?php pcm_crm_render_layout_section( $pcm_object, $pcm_model, $pcm_i, $pcm_section ); ?>
					<?php endforeach; ?>
				</div>

				<div class="pcm-crm-layout-available">
					<h3><?php esc_html_e( 'Available fields', 'pcm-crm' ); ?></h3>
					<div class="pcm-crm-layout-list" data-role="available">
						<?php foreach ( pcm_crm_layout_available_fields( $pcm_object ) as $pcm_name ) : ?>
							<?php pcm_crm_render_layout_chip( $pcm_object, $pcm_model, $pcm_name, false ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<p>
				<button type="button" class="button" data-role="add-section"><?php esc_html_e( 'Add section', 'pcm-crm' ); ?></button>
			</p>
		</div>

		<?php submit_button( __( 'Save fields and layout', 'pcm-crm' ) ); ?>
	</form>
	<?php
}

function pcm_crm_render_custom_field_row( $pcm_object, $pcm_index, array $pcm_field ) {
	$pcm_name = PCM_CRM_CUSTOM_FIELDS_OPTION . '[' . $pcm_object . '][' . $pcm_index . ']';
	$pcm_type = isset( $pcm_field['type'] ) ? $pcm_field['type'] : 'text';
	$pcm_existing = ! empty( $pcm_field['key'] );
	?>
	<div class="pcm-crm-field-row" data-role="custom-field-row">
		<div class="pcm-crm-field-row-head">
			<input type="text" class="pcm-crm-field-label" name="<?php echo esc_attr( $pcm_name ); ?>[label]"
				value="<?php echo esc_attr( isset( $pcm_field['label'] ) ? $pcm_field['label'] : '' ); ?>"
				placeholder="<?php esc_attr_e( 'Field label', 'pcm-crm' ); ?>">

			<select name="<?php echo esc_attr( $pcm_name ); ?>[type]" data-role="custom-type"
				<?php echo $pcm_existing ? 'disabled' : ''; ?>>
				<?php foreach ( pcm_crm_custom_field_types() as $pcm_value => $pcm_meta ) : ?>
					<option value="<?php echo esc_attr( $pcm_value ); ?>" <?php selected( $pcm_type, $pcm_value ); ?>>
						<?php echo esc_html( $pcm_meta['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php if ( $pcm_existing ) : ?>
				<?php // A disabled select posts nothing, and the type must survive the save. ?>
				<input type="hidden" name="<?php echo esc_attr( $pcm_name ); ?>[type]" value="<?php echo esc_attr( $pcm_type ); ?>">
				<code class="pcm-crm-field-key"><?php echo esc_html( pcm_crm_custom_column( $pcm_field['key'] ) ); ?></code>
			<?php endif; ?>

			<span class="pcm-crm-field-move">
				<button type="button" class="button-link pcm-crm-field-remove" data-role="remove-custom-field"
					aria-label="<?php esc_attr_e( 'Remove field', 'pcm-crm' ); ?>">&times;</button>
			</span>
		</div>

		<div class="pcm-crm-field-row-body">
			<input type="hidden" name="<?php echo esc_attr( $pcm_name ); ?>[key]" value="<?php echo esc_attr( isset( $pcm_field['key'] ) ? $pcm_field['key'] : '' ); ?>">

			<label class="pcm-crm-field-options" data-role="custom-options"<?php echo 'picklist' === $pcm_type ? '' : ' hidden'; ?>>
				<span class="description"><?php esc_html_e( 'Picklist values, one per line', 'pcm-crm' ); ?></span>
				<textarea name="<?php echo esc_attr( $pcm_name ); ?>[options]" rows="3"><?php echo esc_textarea( implode( "\n", isset( $pcm_field['options'] ) ? (array) $pcm_field['options'] : array() ) ); ?></textarea>
			</label>

			<label class="pcm-crm-field-related" data-role="custom-related"<?php echo 'relationship' === $pcm_type ? '' : ' hidden'; ?>>
				<span class="description"><?php esc_html_e( 'Points at', 'pcm-crm' ); ?></span>
				<select name="<?php echo esc_attr( $pcm_name ); ?>[related]">
					<?php foreach ( pcm_crm_customisable_objects() as $pcm_slug => $pcm_label ) : ?>
						<option value="<?php echo esc_attr( $pcm_slug ); ?>" <?php selected( isset( $pcm_field['related'] ) ? $pcm_field['related'] : '', $pcm_slug ); ?>>
							<?php echo esc_html( $pcm_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<?php if ( $pcm_existing ) : ?>
				<p class="description">
					<?php esc_html_e( 'Exports as', 'pcm-crm' ); ?>
					<code><?php echo esc_html( pcm_crm_custom_api_name( $pcm_field ) ); ?></code>
					&middot;
					<?php esc_html_e( 'Removing this field hides it, but keeps the column and its data.', 'pcm-crm' ); ?>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

function pcm_crm_render_layout_section( $pcm_object, $pcm_model, $pcm_index, array $pcm_section ) {
	$pcm_name = PCM_CRM_LAYOUTS_OPTION . '[' . $pcm_object . '][' . $pcm_index . ']';
	?>
	<div class="pcm-crm-layout-section" data-role="section">
		<div class="pcm-crm-layout-section-head">
			<input type="text" name="<?php echo esc_attr( $pcm_name ); ?>[title]"
				value="<?php echo esc_attr( isset( $pcm_section['title'] ) ? $pcm_section['title'] : '' ); ?>"
				placeholder="<?php esc_attr_e( 'Section heading (optional)', 'pcm-crm' ); ?>">
			<button type="button" class="button-link pcm-crm-field-remove" data-role="remove-section"
				aria-label="<?php esc_attr_e( 'Remove section', 'pcm-crm' ); ?>">&times;</button>
		</div>

		<div class="pcm-crm-layout-list" data-role="section-fields" data-name="<?php echo esc_attr( $pcm_name ); ?>[fields][]">
			<?php foreach ( (array) $pcm_section['fields'] as $pcm_field ) : ?>
				<?php pcm_crm_render_layout_chip( $pcm_object, $pcm_model, $pcm_field, true, $pcm_name . '[fields][]' ); ?>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * One draggable field.
 *
 * A chip in a section carries a hidden input; one in the Available column does
 * not, which is how "not on the layout" is expressed — moving a chip between
 * the two lists is the whole interaction, and the JS only has to add or remove
 * that input.
 */
function pcm_crm_render_layout_chip( $pcm_object, $pcm_model, $pcm_name, $pcm_placed, $pcm_input_name = '' ) {
	$pcm_defs  = $pcm_model ? $pcm_model->fields() : array();
	$pcm_def   = isset( $pcm_defs[ $pcm_name ] ) ? $pcm_defs[ $pcm_name ] : array();
	$pcm_label = ! empty( $pcm_def['label'] ) ? $pcm_def['label'] : $pcm_name;
	?>
	<div class="pcm-crm-layout-chip<?php echo ! empty( $pcm_def['custom'] ) ? ' is-custom' : ''; ?>"
		draggable="true" data-field="<?php echo esc_attr( $pcm_name ); ?>" data-role="chip" tabindex="0">
		<span class="pcm-crm-layout-chip-label"><?php echo esc_html( $pcm_label ); ?></span>
		<?php if ( ! empty( $pcm_def['custom'] ) ) : ?>
			<span class="pcm-crm-layout-chip-tag"><?php esc_html_e( 'custom', 'pcm-crm' ); ?></span>
		<?php endif; ?>
		<?php if ( $pcm_placed ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $pcm_input_name ); ?>" value="<?php echo esc_attr( $pcm_name ); ?>">
		<?php endif; ?>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
   Sample data tab
   --------------------------------------------------------------------------- */

function pcm_crm_render_samples_tab() {
	$pcm_counts  = pcm_crm_sample_data_counts();
	$pcm_allowed = pcm_crm_seed_allowed();
	// The four core objects and stage history aren't all in the object registry
	// (history has no page of its own to browse), so they keep an explicit label
	// here; everything else — the Projects module's tables — reads its plural
	// label straight from pcm_crm_register_object() rather than duplicating it.
	$pcm_labels  = array(
		'accounts'      => __( 'Accounts', 'pcm-crm' ),
		'contacts'      => __( 'Contacts', 'pcm-crm' ),
		'opportunities' => __( 'Opportunities', 'pcm-crm' ),
		'activities'    => __( 'Activities', 'pcm-crm' ),
		'history'       => __( 'Stage history', 'pcm-crm' ),
	);
	foreach ( array_keys( $pcm_counts ) as $pcm_key ) {
		if ( isset( $pcm_labels[ $pcm_key ] ) ) {
			continue;
		}

		$pcm_object = pcm_crm_object( $pcm_key );

		if ( $pcm_object && ! empty( $pcm_object['plural'] ) ) {
			$pcm_labels[ $pcm_key ] = $pcm_object['plural'];
		}
	}
	?>
	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'What is in the database', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Every sample record carries a flag of its own, so the two sets never have to be told apart by eye. You can filter on “Test Data” anywhere the filter builder appears.', 'pcm-crm' ); ?>
		</p>

		<table class="pcm-crm-table pcm-crm-export-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Object', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Live', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'Sample', 'pcm-crm' ); ?></th>
					<th><?php esc_html_e( 'In the bin', 'pcm-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pcm_counts as $pcm_key => $pcm_count ) : ?>
					<tr>
						<td class="pcm-crm-strong"><?php echo esc_html( isset( $pcm_labels[ $pcm_key ] ) ? $pcm_labels[ $pcm_key ] : $pcm_key ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $pcm_count['real'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $pcm_count['test'] ) ); ?></td>
						<td class="<?php echo $pcm_count['deleted'] ? '' : 'pcm-crm-muted'; ?>">
							<?php if ( $pcm_count['deleted'] ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=pcm-crm-recycle-bin' ) ); ?>">
									<?php echo esc_html( number_format_i18n( $pcm_count['deleted'] ) ); ?>
								</a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description">
			<?php
			printf(
				/* translators: %s: link to the Recycle Bin screen */
				esc_html__( 'Deleting a record marks it deleted rather than removing the row. Those are counted separately because they are not live data — and they can be restored or removed for good in the %s.', 'pcm-crm' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=pcm-crm-recycle-bin' ) ) . '">' . esc_html__( 'Recycle Bin', 'pcm-crm' ) . '</a>'
			);
			?>
		</p>
	</div>

	<?php if ( ! empty( $pcm_counts['history']['orphans'] ) ) : ?>
		<div class="pcm-crm-card pcm-crm-card-accent">
			<h2><?php esc_html_e( 'Orphaned stage history', 'pcm-crm' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of rows */
					esc_html__( 'There are %s stage history rows whose opportunity no longer exists — left behind by a sample set removed before this was cleaned up automatically. They are not harmless: the conversion figures count deals per stage straight out of that table, so these inflate every rate they appear in.', 'pcm-crm' ),
					esc_html( number_format_i18n( $pcm_counts['history']['orphans'] ) )
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pcm_crm_samples">
				<input type="hidden" name="task" value="orphans">
				<?php wp_nonce_field( 'pcm_crm_samples', 'pcm_crm_samples_nonce' ); ?>
				<?php submit_button( __( 'Remove orphaned history', 'pcm-crm' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
	<?php endif; ?>

	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Create sample data', 'pcm-crm' ); ?></h2>

		<?php if ( $pcm_allowed ) : ?>
			<p class="description">
				<?php esc_html_e( 'Around 28 accounts, 70 contacts, 80 opportunities with real stage histories, and several hundred activities — enough for the filters, the pipeline board and the dashboard to have something to show. Dates are relative to today, so the charts fill either way.', 'pcm-crm' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pcm_crm_samples">
				<input type="hidden" name="task" value="create">
				<?php wp_nonce_field( 'pcm_crm_samples', 'pcm_crm_samples_nonce' ); ?>
				<?php submit_button( __( 'Create sample data', 'pcm-crm' ), 'primary', 'submit', false ); ?>
				<?php if ( pcm_crm_has_sample_data() ) : ?>
					<span class="description" style="margin-left:10px">
						<?php esc_html_e( 'Sample data already exists — this would add a second set. Remove the first below.', 'pcm-crm' ); ?>
					</span>
				<?php endif; ?>
			</form>
		<?php else : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the site's host name */
					esc_html__( 'This looks like production (%s), so sample data cannot be created here. Removing it is always allowed. Define PCM_CRM_ALLOW_SEED in wp-config.php if this really is a test site.', 'pcm-crm' ),
					esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<div class="pcm-crm-card pcm-crm-card-accent">
		<h2><?php esc_html_e( 'Remove sample data', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Deletes every record flagged as a sample, permanently and without the recycle bin. Scoped entirely by that flag, so a real record entered alongside them is never in range however much it resembles one.', 'pcm-crm' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete every sample record? Real records are not touched.', 'pcm-crm' ) ); ?>');">
			<input type="hidden" name="action" value="pcm_crm_samples">
			<input type="hidden" name="task" value="delete">
			<?php wp_nonce_field( 'pcm_crm_samples', 'pcm_crm_samples_nonce' ); ?>
			<?php submit_button( __( 'Delete sample data', 'pcm-crm' ), 'delete', 'submit', false ); ?>
		</form>
	</div>

	<div class="pcm-crm-card">
		<h2><?php esc_html_e( 'Shipped templates and sequence', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The plugin comes with a handful of outreach templates and a three-step sequence for a new inbound lead. They are ordinary records — edit or delete them freely. Reinstalling only creates what is missing, matched by name, so anything you have renamed or rewritten is left alone.', 'pcm-crm' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pcm_crm_samples">
			<input type="hidden" name="task" value="content">
			<?php wp_nonce_field( 'pcm_crm_samples', 'pcm_crm_samples_nonce' ); ?>
			<?php submit_button( __( 'Install anything missing', 'pcm-crm' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

/**
 * Create, remove, or install the shipped content.
 */
function pcm_crm_handle_samples() {
	if (
		! pcm_crm_can( 'settings', 'edit' ) ||
		! isset( $_POST['pcm_crm_samples_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_POST['pcm_crm_samples_nonce'] ), 'pcm_crm_samples' )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	$pcm_task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
	$pcm_back = pcm_crm_setup_url( 'samples' );

	if ( 'delete' === $pcm_task ) {
		$pcm_removed = pcm_crm_delete_sample_data();

		wp_safe_redirect( add_query_arg( array( 'pcm_samples' => 'deleted', 'count' => $pcm_removed ), $pcm_back ) );
		exit;
	}

	if ( 'orphans' === $pcm_task ) {
		$pcm_removed = pcm_crm_delete_orphaned_history();

		wp_safe_redirect( add_query_arg( array( 'pcm_samples' => 'orphans', 'count' => $pcm_removed ), $pcm_back ) );
		exit;
	}

	if ( 'content' === $pcm_task ) {
		$pcm_created = pcm_crm_install_sample_content();

		wp_safe_redirect( add_query_arg( array(
			'pcm_samples' => 'content',
			'count'       => $pcm_created['templates'] + $pcm_created['sequences'],
		), $pcm_back ) );
		exit;
	}

	if ( ! pcm_crm_seed_allowed() ) {
		wp_safe_redirect( add_query_arg( 'pcm_samples', 'refused', $pcm_back ) );
		exit;
	}

	$pcm_counts = pcm_crm_create_sample_data();

	wp_safe_redirect( add_query_arg( array(
		'pcm_samples' => 'created',
		'count'       => array_sum( $pcm_counts ),
	), $pcm_back ) );
	exit;
}
add_action( 'admin_post_pcm_crm_samples', 'pcm_crm_handle_samples' );

function pcm_crm_samples_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	$pcm_result = isset( $_GET['pcm_samples'] ) ? sanitize_key( wp_unslash( $_GET['pcm_samples'] ) ) : '';

	if ( ! $pcm_result || ! pcm_crm_is_crm_screen() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	$pcm_count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;

	$pcm_messages = array(
		'created' => array( 'notice-success', sprintf( /* translators: %d: record count */ __( 'Created %d sample records.', 'pcm-crm' ), $pcm_count ) ),
		'deleted' => array( 'notice-success', sprintf( /* translators: %d: record count */ __( 'Removed %d sample records.', 'pcm-crm' ), $pcm_count ) ),
		'content' => array( 'notice-success', sprintf( /* translators: %d: record count */ __( 'Installed %d templates and sequences. Anything already present was left alone.', 'pcm-crm' ), $pcm_count ) ),
		'orphans' => array( 'notice-success', sprintf( /* translators: %d: row count */ __( 'Removed %d orphaned stage history rows. The conversion figures will read correctly now.', 'pcm-crm' ), $pcm_count ) ),
		'refused' => array( 'notice-error', __( 'Sample data cannot be created on this site.', 'pcm-crm' ) ),
	);

	if ( ! isset( $pcm_messages[ $pcm_result ] ) ) {
		return;
	}

	printf(
		'<div class="notice %s is-dismissible"><p>%s</p></div>',
		esc_attr( $pcm_messages[ $pcm_result ][0] ),
		esc_html( $pcm_messages[ $pcm_result ][1] )
	);
}
add_action( 'admin_notices', 'pcm_crm_samples_notice' );

/* ---------------------------------------------------------------------------
   Theme tab
   --------------------------------------------------------------------------- */

function pcm_crm_render_theme_tab() {
	$pcm_current = pcm_crm_theme();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_theme_settings' ); ?>

		<h2><?php esc_html_e( 'Theme', 'pcm-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Applies to every CRM screen, for everyone using the site. Each one is a palette rather than a different layout, so nothing moves — the charts follow along, and a printed page is always on white paper whichever you pick.', 'pcm-crm' ); ?>
		</p>

		<div class="pcm-crm-theme-grid">
			<?php foreach ( pcm_crm_themes() as $pcm_slug => $pcm_theme ) : ?>
				<label class="pcm-crm-theme<?php echo $pcm_slug === $pcm_current ? ' is-chosen' : ''; ?>">
					<div class="pcm-crm-theme-head">
						<input type="radio" name="pcm_crm_theme" value="<?php echo esc_attr( $pcm_slug ); ?>"
							<?php checked( $pcm_slug, $pcm_current ); ?>>
						<span class="pcm-crm-theme-name"><?php echo esc_html( $pcm_theme['label'] ); ?></span>
					</div>

					<div class="pcm-crm-theme-swatch">
						<?php foreach ( $pcm_theme['swatch'] as $pcm_color ) : ?>
							<span style="background: <?php echo esc_attr( $pcm_color ); ?>"></span>
						<?php endforeach; ?>
					</div>

					<div class="pcm-crm-theme-note"><?php echo esc_html( $pcm_theme['description'] ); ?></div>
				</label>
			<?php endforeach; ?>
		</div>

		<?php submit_button( __( 'Save theme', 'pcm-crm' ) ); ?>
	</form>
	<?php
}
