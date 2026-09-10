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

	$pcm_fields = pcm_crm_form_fields();

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

			<?php
			// Consecutive half-width fields share a row. Tracked while walking
			// the list rather than declared, so reordering fields in the
			// builder cannot leave a row half open.
			$pcm_open_row = false;

			foreach ( $pcm_fields as $pcm_index => $pcm_field ) {
				$pcm_half = ! empty( $pcm_field['half'] );
				$pcm_next = isset( $pcm_fields[ $pcm_index + 1 ] ) ? $pcm_fields[ $pcm_index + 1 ] : null;

				if ( $pcm_half && ! $pcm_open_row ) {
					echo '<div class="field-row">';
					$pcm_open_row = true;
				}

				pcm_crm_render_form_field( $pcm_field, $pcm_interest );

				if ( $pcm_open_row && ( ! $pcm_half || ! $pcm_next || empty( $pcm_next['half'] ) ) ) {
					echo '</div>';
					$pcm_open_row = false;
				}
			}

			if ( $pcm_open_row ) {
				echo '</div>';
			}
			?>

			<!-- Honeypot field, hidden from real users. Never `required` — that
			     would block every genuine submission. -->
			<div style="position:absolute; left:-9999px;" aria-hidden="true">
				<label for="pcm_hp">Leave this field empty</label>
				<input type="text" id="pcm_hp" name="pcm_hp" tabindex="-1" autocomplete="off">
			</div>

			<button type="submit" class="btn btn-primary btn-send"><?php echo esc_html( pcm_crm_form_button_label() ); ?></button>
		</form>

	</div>
	<?php
	return ob_get_clean();
}

/**
 * One field's markup.
 *
 * The class names are the theme's existing ones, so a form built here inherits
 * the site's styling without the theme knowing anything about the builder.
 */
function pcm_crm_render_form_field( array $pcm_field, $pcm_interest ) {
	$pcm_name     = pcm_crm_field_input_name( $pcm_field );
	$pcm_required = ! empty( $pcm_field['required'] ) ? ' required' : '';
	$pcm_auto     = ! empty( $pcm_field['autocomplete'] ) ? ' autocomplete="' . esc_attr( $pcm_field['autocomplete'] ) . '"' : '';

	echo '<div class="field">';
	printf( '<label for="%s">%s</label>', esc_attr( $pcm_name ), esc_html( $pcm_field['label'] ) );

	if ( 'select' === $pcm_field['type'] ) {
		printf( '<select id="%s" name="%s"%s>', esc_attr( $pcm_name ), esc_attr( $pcm_name ), $pcm_required ); // phpcs:ignore WordPress.Security.EscapeOutput -- literal
		echo '<option value="">' . esc_html__( 'Choose one…', 'pcm-crm' ) . '</option>';

		// The interest list is keyed by slug so a service card's ?interest=
		// link can preselect its own option; a hand-written list has no slugs
		// and simply never preselects.
		$pcm_slugs = ( ! empty( $pcm_field['source'] ) && 'interests' === $pcm_field['source'] )
			? pcm_crm_interest_options()
			: array();

		foreach ( pcm_crm_field_options( $pcm_field ) as $pcm_option ) {
			$pcm_slug = array_search( $pcm_option, $pcm_slugs, true );

			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $pcm_option ),
				( $pcm_slug && $pcm_slug === $pcm_interest ) ? ' selected' : '',
				esc_html( $pcm_option )
			);
		}

		echo '</select>';
	} elseif ( 'textarea' === $pcm_field['type'] ) {
		printf(
			'<textarea id="%s" name="%s" rows="5"%s></textarea>',
			esc_attr( $pcm_name ),
			esc_attr( $pcm_name ),
			$pcm_required
		);
	} else {
		printf(
			'<input type="%s" id="%s" name="%s"%s%s>',
			esc_attr( $pcm_field['type'] ),
			esc_attr( $pcm_name ),
			esc_attr( $pcm_name ),
			$pcm_auto,
			$pcm_required
		);
	}

	echo '</div>';
}

add_shortcode( 'pcm_contact_form', 'pcm_crm_contact_form_shortcode' );

/**
 * Handle a submission.
 *
 * Validation first, then the audit row, then the CRM intake, then the mail.
 * The notification decides the success redirect; the autoresponder follows
 * best-effort, because a courtesy reply that fails must not tell the visitor
 * their inquiry did not arrive.
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

	$pcm_fields = pcm_crm_form_fields();
	$pcm_values = array();
	$pcm_valid  = true;

	foreach ( $pcm_fields as $pcm_field ) {
		$pcm_name = pcm_crm_field_input_name( $pcm_field );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above
		$pcm_raw  = isset( $_POST[ $pcm_name ] ) ? wp_unslash( $_POST[ $pcm_name ] ) : '';

		$pcm_value = ( 'textarea' === $pcm_field['type'] )
			? sanitize_textarea_field( $pcm_raw )
			: sanitize_text_field( $pcm_raw );

		if ( 'email' === $pcm_field['type'] ) {
			$pcm_value = sanitize_email( $pcm_value );
		}

		// Every rule is re-checked here. The markup carries `required` and a
		// type, but both are trivially bypassed by anything that is not a
		// browser.
		if ( ! empty( $pcm_field['required'] ) && '' === $pcm_value ) {
			$pcm_valid = false;
		}

		if ( 'email' === $pcm_field['type'] && '' !== $pcm_value && ! is_email( $pcm_value ) ) {
			$pcm_valid = false;
		}

		// A dropdown's value goes into the notification email and onto the
		// record, so it must be one we actually offer rather than merely
		// non-empty.
		if ( 'select' === $pcm_field['type'] && '' !== $pcm_value ) {
			$pcm_options = pcm_crm_field_options( $pcm_field );

			if ( $pcm_options && ! in_array( $pcm_value, $pcm_options, true ) ) {
				$pcm_valid = false;
			}
		}

		$pcm_values[ $pcm_field['key'] ] = $pcm_value;
	}

	// Honeypot: log it as spam so the volume is visible, then report success
	// without sending or creating anything. Telling a bot it failed only
	// teaches it to try again differently.
	if ( ! empty( $_POST['pcm_hp'] ) ) {
		pcm_crm_log_submission( $pcm_values, true );
		wp_safe_redirect( add_query_arg( 'pcm_status', 'sent', $pcm_redirect ) );
		exit;
	}

	if ( ! $pcm_valid ) {
		wp_safe_redirect( add_query_arg( 'pcm_status', 'error', $pcm_redirect ) );
		exit;
	}

	$pcm_submission_id = pcm_crm_log_submission( $pcm_values );
	$pcm_records       = pcm_crm_safe_intake( $pcm_values, $pcm_submission_id );

	$pcm_sent = pcm_crm_send_notification( $pcm_values, $pcm_records['contact_id'] );
	$pcm_auto = pcm_crm_send_autoresponder( $pcm_values );

	if ( $pcm_submission_id ) {
		pcm_crm_submissions()->update( $pcm_submission_id, array(
			'notification_sent'  => $pcm_sent ? 1 : 0,
			'autoresponder_sent' => $pcm_auto ? 1 : 0,
		) );
	}

	do_action( 'pcm_crm_form_submitted', $pcm_values, $pcm_records );

	wp_safe_redirect( add_query_arg( 'pcm_status', $pcm_sent ? 'sent' : 'error', $pcm_redirect ) );
	exit;
}

add_action( 'admin_post_nopriv_pcm_contact_form', 'pcm_crm_handle_contact_form' );
add_action( 'admin_post_pcm_contact_form', 'pcm_crm_handle_contact_form' );
