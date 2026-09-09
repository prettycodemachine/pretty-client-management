<?php
/**
 * The contact form's mail: settings accessors, the branded wrapper, tokens,
 * and the autoresponder.
 *
 * Moved here from the theme's inc/contact.php. The option names are unchanged
 * on purpose — the recipient and the current reply copy are already saved
 * under them on staging and production, so reusing them means the move needs
 * no data migration and cannot lose the live copy. Function names all carry
 * the pcm_crm_ prefix, so nothing here can collide with what the theme still
 * defines.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_contact_recipient() {
	$pcm_to = sanitize_email( (string) get_option( 'pcm_contact_recipient', '' ) );

	return $pcm_to ? $pcm_to : get_option( 'admin_email' );
}

function pcm_crm_autoresponder_default_subject() {
	return 'Thanks for getting in touch — Pretty Code Machine';
}

/**
 * The shipped reply copy. Only used until the settings screen is saved once.
 *
 * Written the way the editor stores it — blank lines between paragraphs, no
 * <p> tags — because that is what comes back out of wp_editor once anyone
 * saves the screen. pcm_crm_format_body() adds the markup at send time, so the
 * shipped copy and an edited one travel exactly the same path.
 */
function pcm_crm_autoresponder_default_body() {
	$pcm_site = esc_url( set_url_scheme( home_url( '/' ), 'https' ) );

	return
		"Dear {{FIRST NAME}},\n\n" .
		"Thank you for your interest in working with Pretty Code Machine!\n\n" .
		"We&rsquo;ve attached a copy of our pricing sheet for your convenience.\n\n" .
		"We look forward to being in touch within one business day.\n\n" .
		"Talk soon,\nJason\n\n" .
		"<strong>Jason Jensen</strong>\n" .
		'Founder, <a href="' . $pcm_site . '">Pretty Code Machine</a>' . "\n" .
		'<a href="https://calendly.com/jason-eric-jensen">Schedule a Meeting</a>';
}

/**
 * Turn the stored template into email HTML.
 *
 * wp_editor hands back what the Text tab shows: paragraphs separated by blank
 * lines, with no <p> tags — WordPress adds those at render time for post
 * content, and an email that skips that step collapses into one run-on block,
 * because HTML does not care about newlines.
 *
 * wpautop() is the same function core uses on the_content, so what the visual
 * editor previews is what arrives. Its second argument defaults to true, which
 * is what turns the single newline in a signature into a <br>.
 */
function pcm_crm_format_body( $pcm_body ) {
	return wpautop( $pcm_body );
}

function pcm_crm_autoresponder_subject() {
	$pcm_subject = trim( (string) get_option( 'pcm_autoresponder_subject', '' ) );

	return '' !== $pcm_subject ? $pcm_subject : pcm_crm_autoresponder_default_subject();
}

function pcm_crm_autoresponder_body() {
	$pcm_body = trim( (string) get_option( 'pcm_autoresponder_body', '' ) );

	return '' !== $pcm_body ? $pcm_body : pcm_crm_autoresponder_default_body();
}

function pcm_crm_autoresponder_enabled() {
	return '1' === (string) get_option( 'pcm_autoresponder_enabled', '1' );
}

/**
 * The media library IDs attached to every automatic reply.
 */
function pcm_crm_attachment_ids() {
	$pcm_ids = get_option( 'pcm_crm_attachment_ids', array() );

	if ( ! is_array( $pcm_ids ) ) {
		$pcm_ids = array();
	}

	return array_values( array_filter( array_map( 'absint', $pcm_ids ) ) );
}

/**
 * Server paths of the attachments, skipping any that have gone missing.
 *
 * wp_mail() fails outright on an unreadable attachment, so one stale ID would
 * otherwise stop every reply going out. Filtered per file rather than for the
 * set, so a missing pricing sheet does not also drop the case study beside it.
 */
function pcm_crm_attachment_paths() {
	$pcm_paths = array();

	foreach ( pcm_crm_attachment_ids() as $pcm_id ) {
		$pcm_path = get_attached_file( $pcm_id );

		if ( $pcm_path && is_readable( $pcm_path ) ) {
			$pcm_paths[] = $pcm_path;
		}
	}

	return $pcm_paths;
}

/**
 * Attachment ids that no longer resolve to a readable file, so the settings
 * screen can say so rather than failing silently at send time.
 */
function pcm_crm_missing_attachment_ids() {
	$pcm_missing = array();

	foreach ( pcm_crm_attachment_ids() as $pcm_id ) {
		$pcm_path = get_attached_file( $pcm_id );

		if ( ! $pcm_path || ! is_readable( $pcm_path ) ) {
			$pcm_missing[] = $pcm_id;
		}
	}

	return $pcm_missing;
}

/**
 * Seed the plugin's settings from whatever the theme already had.
 *
 * The scalar options share their names, so only the attachment needs moving:
 * the theme stored one media ID, and the plugin stores a list.
 */
function pcm_crm_migrate_theme_options() {
	if ( get_option( 'pcm_crm_attachment_ids', null ) !== null ) {
		return;
	}

	$pcm_legacy = absint( get_option( 'pcm_autoresponder_attachment', 0 ) );

	add_option( 'pcm_crm_attachment_ids', $pcm_legacy ? array( $pcm_legacy ) : array() );
}

function pcm_crm_html_content_type() {
	return 'text/html';
}

/**
 * Wrap message HTML in the site's email layout.
 *
 * Table-based with inline styles and literal hex: mail clients routinely drop
 * <style> blocks and support neither custom properties nor webfonts, so the
 * palette is repeated here and will not follow a change to the theme's
 * style.css.
 */
function pcm_crm_email_wrapper( $pcm_content ) {
	// siteurl and home are stored as http:// and rewritten to https at runtime
	// by SiteGround, so anything generated outside a web request emits http://.
	// Mail clients block insecure images rather than follow the redirect, so
	// the scheme is forced regardless of how the mail was triggered.
	$pcm_logo = pcm_crm_email_logo_url();
	$pcm_home = esc_url( set_url_scheme( home_url( '/' ), 'https' ) );
	$pcm_ink  = '#1a1a1d';
	$pcm_body = '#46464a';
	$pcm_acc  = '#c94040';
	$pcm_tint = '#fbf4f4';
	$pcm_font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";

	ob_start();
	?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $pcm_tint ); ?>;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
		style="background:<?php echo esc_attr( $pcm_tint ); ?>;padding:28px 12px;">
		<tr>
			<td align="center">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
					style="width:600px;max-width:100%;background:#ffffff;border:2px solid <?php echo esc_attr( $pcm_ink ); ?>;border-radius:14px;">
					<?php if ( $pcm_logo ) : ?>
					<tr>
						<td align="center" style="padding:26px 32px 6px;">
							<a href="<?php echo esc_url( $pcm_home ); ?>">
								<img src="<?php echo esc_url( $pcm_logo ); ?>" width="150" alt="Pretty Code Machine"
									style="display:block;width:150px;height:auto;border:0;">
							</a>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<td style="padding:6px 32px 4px;">
							<div style="height:3px;background:<?php echo esc_attr( $pcm_acc ); ?>;border-radius:2px;"></div>
						</td>
					</tr>
					<tr>
						<td style="padding:18px 32px 30px;font-family:<?php echo esc_attr( $pcm_font ); ?>;font-size:16px;line-height:1.65;color:<?php echo esc_attr( $pcm_body ); ?>;">
							<?php echo wp_kses_post( $pcm_content ); ?>
						</td>
					</tr>
				</table>
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;">
					<tr>
						<td align="center" style="padding:16px 20px;font-family:<?php echo esc_attr( $pcm_font ); ?>;font-size:12px;line-height:1.6;color:#7a7a80;">
							Pretty Code Machine &middot; Crafted in Vermont, USA<br>
							<a href="<?php echo esc_url( $pcm_home ); ?>" style="color:#7a7a80;">prettycodemachine.com</a>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
	<?php
	return ob_get_clean();
}

/**
 * The logo for the email header.
 *
 * The plugin cannot assume the theme is the one that ships it, so it prefers
 * the theme's asset when that helper exists and falls back to the site's
 * custom logo. Returning '' simply drops the header row rather than emitting
 * a broken image.
 */
function pcm_crm_email_logo_url() {
	if ( function_exists( 'pcm_asset' ) ) {
		return set_url_scheme( pcm_asset( 'images/logo.png' ), 'https' );
	}

	$pcm_logo_id = (int) get_theme_mod( 'custom_logo' );

	if ( $pcm_logo_id ) {
		$pcm_src = wp_get_attachment_image_src( $pcm_logo_id, 'medium' );

		if ( $pcm_src ) {
			return set_url_scheme( $pcm_src[0], 'https' );
		}
	}

	return '';
}

/**
 * Replace the {{TOKEN}} placeholders.
 *
 * Both spaced and underscored spellings are accepted, because the settings
 * screen documents the spaced form but the underscored one is the reflex.
 * Values are escaped before substitution, so a submitted name cannot inject
 * markup into the email.
 */
function pcm_crm_fill_tokens( $pcm_text, array $pcm_fields ) {
	$pcm_first = isset( $pcm_fields['first'] ) ? $pcm_fields['first'] : '';
	$pcm_last  = isset( $pcm_fields['last'] ) ? $pcm_fields['last'] : '';

	$pcm_map = array(
		'{{FIRST NAME}}'   => $pcm_first,
		'{{FIRST_NAME}}'   => $pcm_first,
		'{{LAST NAME}}'    => $pcm_last,
		'{{LAST_NAME}}'    => $pcm_last,
		'{{FULL NAME}}'    => trim( $pcm_first . ' ' . $pcm_last ),
		'{{FULL_NAME}}'    => trim( $pcm_first . ' ' . $pcm_last ),
		'{{ORGANIZATION}}' => isset( $pcm_fields['org'] ) ? $pcm_fields['org'] : '',
		'{{EMAIL}}'        => isset( $pcm_fields['email'] ) ? $pcm_fields['email'] : '',
		'{{INTEREST}}'     => isset( $pcm_fields['interest'] ) ? $pcm_fields['interest'] : '',
	);

	return strtr( $pcm_text, array_map( 'esc_html', $pcm_map ) );
}

function pcm_crm_tokens() {
	return array( '{{FIRST NAME}}', '{{LAST NAME}}', '{{FULL NAME}}', '{{ORGANIZATION}}', '{{EMAIL}}', '{{INTEREST}}' );
}

/**
 * The courtesy reply to the person who submitted the form.
 */
function pcm_crm_send_autoresponder( array $pcm_fields ) {
	if ( ! pcm_crm_autoresponder_enabled() ) {
		return false;
	}

	$pcm_subject = pcm_crm_fill_tokens( pcm_crm_autoresponder_subject(), $pcm_fields );
	$pcm_html    = pcm_crm_email_wrapper( pcm_crm_format_body( pcm_crm_fill_tokens( pcm_crm_autoresponder_body(), $pcm_fields ) ) );
	$pcm_from    = pcm_crm_contact_recipient();
	$pcm_headers = array(
		'From: Pretty Code Machine <' . $pcm_from . '>',
		'Reply-To: ' . $pcm_from,
	);

	add_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );
	$pcm_sent = wp_mail( $pcm_fields['email'], $pcm_subject, $pcm_html, $pcm_headers, pcm_crm_attachment_paths() );
	remove_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );

	return $pcm_sent;
}

/**
 * The internal notification, with a link straight to the new CRM record.
 */
function pcm_crm_send_notification( array $pcm_fields, $pcm_contact_id = 0 ) {
	$pcm_name = trim( $pcm_fields['first'] . ' ' . $pcm_fields['last'] );

	$pcm_message  = "Interested in: {$pcm_fields['interest']}\n\n";
	$pcm_message .= "Name: {$pcm_name}\nOrganization: {$pcm_fields['org']}\nEmail: {$pcm_fields['email']}\n\n";
	$pcm_message .= "Message:\n{$pcm_fields['message']}\n";

	if ( $pcm_contact_id ) {
		$pcm_message .= "\nOpen in the CRM: " . set_url_scheme(
			admin_url( 'admin.php?page=pcm-crm-contacts#id=' . $pcm_contact_id ),
			'https'
		) . "\n";
	}

	return wp_mail(
		pcm_crm_contact_recipient(),
		'New inquiry — ' . $pcm_fields['interest'] . ' — ' . $pcm_name,
		$pcm_message,
		array( 'Reply-To: ' . $pcm_fields['email'] )
	);
}
