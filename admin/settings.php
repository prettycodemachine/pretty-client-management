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
	register_setting( 'pcm_crm_form_settings', 'pcm_contact_recipient', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_email',
		'default'           => get_option( 'admin_email' ),
	) );
	register_setting( 'pcm_crm_form_settings', 'pcm_autoresponder_enabled', array(
		'type'              => 'string',
		'sanitize_callback' => 'pcm_crm_sanitize_checkbox',
		'default'           => '1',
	) );
	register_setting( 'pcm_crm_form_settings', 'pcm_autoresponder_subject', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => pcm_crm_autoresponder_default_subject(),
	) );
	register_setting( 'pcm_crm_form_settings', 'pcm_autoresponder_body', array(
		'type'              => 'string',
		'sanitize_callback' => 'wp_kses_post',
		'default'           => '',
	) );
	register_setting( 'pcm_crm_form_settings', 'pcm_crm_attachment_ids', array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_sanitize_ids',
		'default'           => array(),
	) );
	register_setting( 'pcm_crm_form_settings', 'pcm_crm_email_logo', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
	register_setting( 'pcm_crm_form_settings', 'pcm_crm_form_button', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
	register_setting( 'pcm_crm_form_settings', PCM_CRM_FIELDS_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_sanitize_form_fields',
		'default'           => array(),
	) );

	register_setting( 'pcm_crm_export_settings', 'pcm_crm_npsp_namespace', array(
		'type'              => 'string',
		'sanitize_callback' => 'pcm_crm_sanitize_checkbox',
		'default'           => '',
	) );

	register_setting( 'pcm_crm_pipeline_settings', 'pcm_crm_stall_days', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 30,
	) );
}
add_action( 'admin_init', 'pcm_crm_register_settings' );

/**
 * The settings tabs, in the order they matter.
 */
function pcm_crm_settings_tabs() {
	return array(
		'form'     => __( 'Contact Form', 'pcm-crm' ),
		'export'   => __( 'Data Export', 'pcm-crm' ),
		'pipeline' => __( 'Pipeline', 'pcm-crm' ),
	);
}

function pcm_crm_current_settings_tab() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
	$pcm_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'form';

	return isset( pcm_crm_settings_tabs()[ $pcm_tab ] ) ? $pcm_tab : 'form';
}

function pcm_crm_settings_url( $pcm_tab ) {
	return admin_url( 'admin.php?page=pcm-crm-settings&tab=' . $pcm_tab );
}

function pcm_crm_sanitize_checkbox( $pcm_value ) {
	return $pcm_value ? '1' : '';
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

function pcm_crm_render_settings() {
	if ( ! pcm_crm_user_can() ) {
		wp_die( esc_html__( 'You do not have access to the CRM.', 'pcm-crm' ) );
	}

	$pcm_tab = pcm_crm_current_settings_tab();
	?>
	<div class="wrap pcm-crm pcm-crm-settings">
		<div class="pcm-crm-head">
			<div>
				<h1><?php esc_html_e( 'CRM Settings', 'pcm-crm' ); ?></h1>
			</div>
		</div>

		<div class="pcm-crm-tabs" role="tablist">
			<?php foreach ( pcm_crm_settings_tabs() as $pcm_slug => $pcm_label ) : ?>
				<a class="pcm-crm-tab<?php echo $pcm_slug === $pcm_tab ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( pcm_crm_settings_url( $pcm_slug ) ); ?>"
					role="tab" aria-selected="<?php echo $pcm_slug === $pcm_tab ? 'true' : 'false'; ?>">
					<?php echo esc_html( $pcm_label ); ?>
				</a>
			<?php endforeach; ?>
		</div>

		<?php
		settings_errors();

		if ( 'export' === $pcm_tab ) {
			pcm_crm_render_export_tab();
		} elseif ( 'pipeline' === $pcm_tab ) {
			pcm_crm_render_pipeline_tab();
		} else {
			pcm_crm_render_form_tab();
		}
		?>
	</div>
	<?php
}

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

	<form method="post" action="options.php" class="pcm-crm-card">
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
		<?php
		wp_editor(
			pcm_crm_autoresponder_body(),
			'pcm_autoresponder_body',
			array( 'textarea_name' => 'pcm_autoresponder_body', 'textarea_rows' => 14, 'media_buttons' => false )
		);
		?>
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
 * wrong: a Contact cannot reference an Account that does not exist yet.
 */
function pcm_crm_exportable_objects() {
	return array(
		'accounts'      => array( 'label' => __( 'Accounts', 'pcm-crm' ), 'sf' => 'Account' ),
		'contacts'      => array( 'label' => __( 'Contacts', 'pcm-crm' ), 'sf' => 'Contact' ),
		'opportunities' => array( 'label' => __( 'Opportunities', 'pcm-crm' ), 'sf' => 'Opportunity' ),
		'activities'    => array( 'label' => __( 'Activities', 'pcm-crm' ), 'sf' => 'Task' ),
	);
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

		<form method="post" action="options.php">
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

function pcm_crm_render_pipeline_tab() {
	?>
	<form method="post" action="options.php" class="pcm-crm-card">
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
		! pcm_crm_user_can() ||
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
