<?php
/**
 * The contact form: markup, and the handler behind it.
 *
 * Moved out of the theme so submissions can reach the CRM. The action name and
 * nonce are unchanged from the theme's version on purpose — SiteGround caches
 * rendered HTML, so a page served from cache after the deploy still posts to a
 * handler that exists.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_form_assets() {
	wp_enqueue_style( 'pcm-crm-form', pcm_crm_asset( 'form.css' ), array(), null );
}

/**
 * [pcm_contact_form]
 *
 * The markup matches what the theme rendered, so the theme's existing
 * .contact-form styles keep applying and the form does not change appearance
 * on the day it moves. form.css only fills the gap if the theme is not the one
 * providing them.
 */
function pcm_crm_contact_form_shortcode( $pcm_atts = array() ) {
	pcm_crm_form_assets();

	// A service card's CTA arrives as ?interest=<slug>; anything unrecognised
	// falls through to the empty default rather than preselecting nonsense.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preselect
	$pcm_interest = isset( $_GET['interest'] ) ? sanitize_key( wp_unslash( $_GET['interest'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status message
	$pcm_status = isset( $_GET['pcm_status'] ) ? sanitize_text_field( wp_unslash( $_GET['pcm_status'] ) ) : '';

	$pcm_options = pcm_crm_interest_options();

	ob_start();
	?>
	<div class="contact-form-wrap">

		<?php if ( 'sent' === $pcm_status ) : ?>
			<p class="pcm-form-notice pcm-form-sent"><strong>Thanks &mdash; your message is in.</strong> Expect a reply within one business day.</p>
		<?php elseif ( 'error' === $pcm_status ) : ?>
			<p class="pcm-form-notice pcm-form-error"><strong>Something didn&rsquo;t go through.</strong> Please try again, or email <?php echo esc_html( pcm_crm_contact_recipient() ); ?> directly.</p>
		<?php endif; ?>

		<form class="contact-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pcm_contact_form">
			<?php wp_nonce_field( 'pcm_contact_form', 'pcm_contact_nonce' ); ?>

			<div class="field-row">
				<div class="field">
					<label for="pcm_first_name">First name</label>
					<input type="text" id="pcm_first_name" name="pcm_first_name" autocomplete="given-name" required>
				</div>
				<div class="field">
					<label for="pcm_last_name">Last name</label>
					<input type="text" id="pcm_last_name" name="pcm_last_name" autocomplete="family-name" required>
				</div>
			</div>
			<div class="field">
				<label for="pcm_org">Organization</label>
				<input type="text" id="pcm_org" name="pcm_org" autocomplete="organization" required>
			</div>
			<div class="field">
				<label for="pcm_interest">What are you interested in?</label>
				<select id="pcm_interest" name="pcm_interest" required>
					<option value="">Choose one&hellip;</option>
					<?php foreach ( $pcm_options as $pcm_slug => $pcm_label ) : ?>
						<option value="<?php echo esc_attr( $pcm_label ); ?>" <?php selected( $pcm_interest, $pcm_slug ); ?>>
							<?php echo esc_html( $pcm_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="field">
				<label for="pcm_email">Email</label>
				<input type="email" id="pcm_email" name="pcm_email" autocomplete="email" required>
			</div>
			<div class="field">
				<label for="pcm_message">What are you looking for help with?</label>
				<textarea id="pcm_message" name="pcm_message" rows="5" required></textarea>
			</div>

			<!-- Honeypot field, hidden from real users. Never `required` — that
			     would block every genuine submission. -->
			<div style="position:absolute; left:-9999px;" aria-hidden="true">
				<label for="pcm_hp">Leave this field empty</label>
				<input type="text" id="pcm_hp" name="pcm_hp" tabindex="-1" autocomplete="off">
			</div>

			<button type="submit" class="btn btn-primary btn-send">Send message</button>
		</form>

	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'pcm_contact_form', 'pcm_crm_contact_form_shortcode' );

/**
 * Handle a submission.
 *
 * Validation first, then the audit row, then the CRM intake, then the mail.
 * The notification decides the success redirect; the autoresponder follows
 * best-effort, because a courtesy reply that fails must not tell the visitor
 * their enquiry did not arrive.
 */
function pcm_crm_handle_contact_form() {
	$pcm_redirect = wp_get_referer() ? wp_get_referer() : home_url( '/contact/' );

	if (
		! isset( $_POST['pcm_contact_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_POST['pcm_contact_nonce'] ), 'pcm_contact_form' )
	) {
		wp_safe_redirect( add_query_arg( 'pcm_status', 'error', $pcm_redirect ) );
		exit;
	}

	$pcm_fields = array(
		'first'    => isset( $_POST['pcm_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['pcm_first_name'] ) ) : '',
		'last'     => isset( $_POST['pcm_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['pcm_last_name'] ) ) : '',
		'org'      => isset( $_POST['pcm_org'] ) ? sanitize_text_field( wp_unslash( $_POST['pcm_org'] ) ) : '',
		'interest' => isset( $_POST['pcm_interest'] ) ? sanitize_text_field( wp_unslash( $_POST['pcm_interest'] ) ) : '',
		'email'    => isset( $_POST['pcm_email'] ) ? sanitize_email( wp_unslash( $_POST['pcm_email'] ) ) : '',
		'message'  => isset( $_POST['pcm_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pcm_message'] ) ) : '',
	);

	// Honeypot: log it as spam so the volume is visible, then report success
	// without sending or creating anything. Telling a bot it failed only
	// teaches it to try again differently.
	if ( ! empty( $_POST['pcm_hp'] ) ) {
		pcm_crm_log_submission( $pcm_fields, true );
		wp_safe_redirect( add_query_arg( 'pcm_status', 'sent', $pcm_redirect ) );
		exit;
	}

	// Every field is required. The markup says so too, but the browser's
	// required attribute is trivially bypassed, so it is enforced here. The
	// interest must be one we actually offer, not merely non-empty — the value
	// is posted as a label and goes straight into the notification email.
	if (
		'' === $pcm_fields['first'] || '' === $pcm_fields['last'] || '' === $pcm_fields['org'] ||
		'' === $pcm_fields['message'] || ! is_email( $pcm_fields['email'] ) ||
		! in_array( $pcm_fields['interest'], pcm_crm_interest_options(), true )
	) {
		wp_safe_redirect( add_query_arg( 'pcm_status', 'error', $pcm_redirect ) );
		exit;
	}

	$pcm_submission_id = pcm_crm_log_submission( $pcm_fields );
	$pcm_records       = pcm_crm_safe_intake( $pcm_fields, $pcm_submission_id );

	$pcm_sent = pcm_crm_send_notification( $pcm_fields, $pcm_records['contact_id'] );
	$pcm_auto = pcm_crm_send_autoresponder( $pcm_fields );

	if ( $pcm_submission_id ) {
		pcm_crm_submissions()->update( $pcm_submission_id, array(
			'notification_sent'  => $pcm_sent ? 1 : 0,
			'autoresponder_sent' => $pcm_auto ? 1 : 0,
		) );
	}

	do_action( 'pcm_crm_form_submitted', $pcm_fields, $pcm_records );

	wp_safe_redirect( add_query_arg( 'pcm_status', $pcm_sent ? 'sent' : 'error', $pcm_redirect ) );
	exit;
}
add_action( 'admin_post_nopriv_pcm_contact_form', 'pcm_crm_handle_contact_form' );
add_action( 'admin_post_pcm_contact_form', 'pcm_crm_handle_contact_form' );
