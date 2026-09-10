<?php
/**
 * CRM → Settings: the contact form's recipient, the reply template, and the
 * files attached to it.
 *
 * This screen replaces Settings > Contact form from the theme. Option names
 * are unchanged, so whatever is saved on the live site is already here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_register_settings() {
	register_setting( 'pcm_crm_settings', 'pcm_contact_recipient', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_email',
		'default'           => get_option( 'admin_email' ),
	) );
	register_setting( 'pcm_crm_settings', 'pcm_autoresponder_enabled', array(
		'type'              => 'string',
		'sanitize_callback' => 'pcm_crm_sanitize_checkbox',
		'default'           => '1',
	) );
	register_setting( 'pcm_crm_settings', 'pcm_autoresponder_subject', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => pcm_crm_autoresponder_default_subject(),
	) );
	register_setting( 'pcm_crm_settings', 'pcm_autoresponder_body', array(
		'type'              => 'string',
		'sanitize_callback' => 'wp_kses_post',
		'default'           => '',
	) );
	register_setting( 'pcm_crm_settings', 'pcm_crm_stall_days', array(
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 30,
	) );
	register_setting( 'pcm_crm_settings', 'pcm_crm_attachment_ids', array(
		'type'              => 'array',
		'sanitize_callback' => 'pcm_crm_sanitize_ids',
		'default'           => array(),
	) );
}
add_action( 'admin_init', 'pcm_crm_register_settings' );

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

	$pcm_missing = pcm_crm_missing_attachment_ids();
	?>
	<div class="wrap pcm-crm pcm-crm-settings">
		<div class="pcm-crm-head">
			<div>
				<h1><?php esc_html_e( 'CRM Settings', 'pcm-crm' ); ?></h1>
				<p class="pcm-crm-sub"><?php esc_html_e( 'The contact form, the reply it sends, and what rides along with it.', 'pcm-crm' ); ?></p>
			</div>
		</div>

		<?php settings_errors(); ?>

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

		<form method="post" action="options.php" class="pcm-crm-card">
			<?php settings_fields( 'pcm_crm_settings' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="pcm_contact_recipient"><?php esc_html_e( 'Send submissions to', 'pcm-crm' ); ?></label>
					</th>
					<td>
						<input type="email" class="regular-text" id="pcm_contact_recipient"
							name="pcm_contact_recipient"
							value="<?php echo esc_attr( pcm_crm_contact_recipient() ); ?>">
						<p class="description">
							<?php esc_html_e( 'Every contact form submission is emailed here, and recorded in the CRM either way.', 'pcm-crm' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Autoresponder', 'pcm-crm' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="pcm_autoresponder_enabled" value="1"
								<?php checked( pcm_crm_autoresponder_enabled() ); ?>>
							<?php esc_html_e( 'Send an automatic reply to the person who submitted the form', 'pcm-crm' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="pcm_autoresponder_subject"><?php esc_html_e( 'Reply subject', 'pcm-crm' ); ?></label>
					</th>
					<td>
						<input type="text" class="large-text" id="pcm_autoresponder_subject"
							name="pcm_autoresponder_subject"
							value="<?php echo esc_attr( pcm_crm_autoresponder_subject() ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Attachments', 'pcm-crm' ); ?></th>
					<td>
						<div class="pcm-crm-attachments" data-role="attachments">
							<ul class="pcm-crm-attachment-list" data-role="attachment-list">
								<?php foreach ( pcm_crm_attachment_ids() as $pcm_id ) : ?>
									<?php
									$pcm_path    = get_attached_file( $pcm_id );
									$pcm_label   = $pcm_path ? basename( $pcm_path ) : '';
									$pcm_broken  = ! $pcm_path || ! is_readable( $pcm_path );
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

							<button type="button" class="button" data-role="attachment-add">
								<?php esc_html_e( 'Add attachment', 'pcm-crm' ); ?>
							</button>
						</div>
						<p class="description">
							<?php esc_html_e( 'Attached to every automatic reply, in this order. A file that has been deleted from the media library is skipped rather than breaking the send.', 'pcm-crm' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Reply message', 'pcm-crm' ); ?></h2>
			<p class="description" style="margin-bottom:10px">
				<?php esc_html_e( 'Placeholders, replaced when the email is sent:', 'pcm-crm' ); ?>
				<?php foreach ( pcm_crm_tokens() as $pcm_token ) : ?>
					<code><?php echo esc_html( $pcm_token ); ?></code>
				<?php endforeach; ?>
				<br>
				<?php esc_html_e( 'The message is wrapped in the site’s branded email layout automatically — no need to add a logo or signature styling here.', 'pcm-crm' ); ?>
			</p>
			<?php
			wp_editor(
				pcm_crm_autoresponder_body(),
				'pcm_autoresponder_body',
				array(
					'textarea_name' => 'pcm_autoresponder_body',
					'textarea_rows' => 14,
					'media_buttons' => false,
				)
			);
			?>

			<?php submit_button(); ?>
		</form>

		<div class="pcm-crm-card">
			<h2><?php esc_html_e( 'Pipeline', 'pcm-crm' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'pcm_crm_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="pcm_crm_stall_days"><?php esc_html_e( 'Stalled after', 'pcm-crm' ); ?></label>
						</th>
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
				<?php submit_button( __( 'Save pipeline settings', 'pcm-crm' ), 'secondary' ); ?>
			</form>
		</div>

		<div class="pcm-crm-card">
			<h2><?php esc_html_e( 'Send a test', 'pcm-crm' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Sends the saved reply — attachments and all — using placeholder values, so you can see what a visitor receives. Save your changes first.', 'pcm-crm' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pcm_crm_test_email">
				<?php wp_nonce_field( 'pcm_crm_test_email', 'pcm_crm_test_nonce' ); ?>
				<input type="email" class="regular-text" name="pcm_crm_test_to"
					value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
				<?php submit_button( __( 'Send test email', 'pcm-crm' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
	</div>
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
