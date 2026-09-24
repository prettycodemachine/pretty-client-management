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
	return sprintf( 'Thanks for getting in touch — %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
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
	$pcm_name = esc_html( get_bloginfo( 'name' ) );

	// Signed by the site, not a person: this is what every install sends
	// until someone writes their own, so it must not speak for anyone.
	return
		"Dear {{FIRST NAME}},\n\n" .
		"Thank you for getting in touch with {$pcm_name}. We&rsquo;ve received your message and will be in touch soon.\n\n" .
		"Best regards,\n" .
		'<a href="' . $pcm_site . '">' . $pcm_name . '</a>';
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

/**
 * The name mail from this plugin is sent under: the site's own.
 *
 * It was hardcoded as "Pretty Code Machine", which is right on one site and
 * wrong on every other the plugin is installed on.
 */
function pcm_crm_email_from_name() {
	$pcm_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	// A From display name cannot carry these unquoted; a site name rarely
	// has them, and dropping them beats a malformed header.
	$pcm_name = trim( str_replace( array( '"', '<', '>', "\r", "\n" ), '', $pcm_name ) );

	return '' !== $pcm_name ? $pcm_name : wp_parse_url( home_url(), PHP_URL_HOST );
}

/**
 * The address mail from this plugin is sent from: wordpress@ the site's own
 * domain, the same one WordPress core uses for its own mail.
 *
 * It used to be the contact-form recipient, which is usually a mailbox on
 * some other domain — a Gmail or Workspace address this server is not
 * authorised to send for, so the mail failed SPF and was filtered. No mailbox
 * needs to exist here: sending only needs the domain's SPF to allow this
 * server, and replies go to the recipient through Reply-To instead. An SMTP
 * plugin that forces its own From still overrides this.
 */
function pcm_crm_email_from_address() {
	$pcm_host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );

	if ( 0 === strpos( $pcm_host, 'www.' ) ) {
		$pcm_host = substr( $pcm_host, 4 );
	}

	return apply_filters( 'wp_mail_from', 'wordpress@' . $pcm_host );
}

/**
 * The From header value: the site's name at the site's own address.
 */
function pcm_crm_email_from() {
	return pcm_crm_email_from_name() . ' <' . pcm_crm_email_from_address() . '>';
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
	// The footer names the site the mail came from, and its link text is that
	// same site's host. It once read "prettycodemachine.com" over a link to
	// whichever site sent it — link text naming one domain and an href going
	// to another, in a set-your-password email, is exactly what mail filters
	// quarantine as phishing, and invites from other installs went missing.
	$pcm_site = get_bloginfo( 'name' );
	$pcm_host = wp_parse_url( home_url(), PHP_URL_HOST );
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
								<img src="<?php echo esc_url( $pcm_logo ); ?>" width="150" alt="<?php echo esc_attr( $pcm_site ); ?>"
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
							<?php echo esc_html( $pcm_site ); ?><br>
							<a href="<?php echo esc_url( $pcm_home ); ?>" style="color:#7a7a80;"><?php echo esc_html( $pcm_host ); ?></a>
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
	// An uploaded logo wins: it is the one someone chose here, on the screen
	// that shows what the email looks like.
	$pcm_id = absint( get_option( 'pcm_crm_email_logo', 0 ) );

	if ( $pcm_id ) {
		$pcm_src = wp_get_attachment_image_src( $pcm_id, 'medium' );

		if ( $pcm_src ) {
			return set_url_scheme( $pcm_src[0], 'https' );
		}
	}

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
 * Whether a wp-login.php visit is plainly headed for one of this plugin's own
 * destinations — the Client Portal or the employee portal — rather than the
 * ordinary wp-admin login screen, so a set-password link from an invite email
 * shows this brand's own logo and colours instead of the site's default admin
 * login page. Shared rather than answered per destination: $pcm_prefix is
 * whichever URL a caller's own invite flow set as redirect_to (the Client
 * Portal page, or the employee portal's front base), and matching a request's
 * own redirect_to against it is the whole question either destination needs
 * answered.
 */
function pcm_crm_is_branded_login_visit( $pcm_prefix ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, decides styling only
	$pcm_redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';

	return $pcm_prefix && $pcm_redirect && 0 === strpos( $pcm_redirect, $pcm_prefix );
}

/**
 * The actual paint job for a branded wp-login.php visit — the logo swap and
 * the CRM's own colours on the primary button and links. Has nothing
 * destination-specific about it, unlike pcm_crm_is_branded_login_visit()
 * above, which is why it is a caller's job to decide whether to call this at
 * all and this function's job only to draw once that is decided.
 */
function pcm_crm_branded_login_style() {
	$pcm_logo = pcm_crm_email_logo_url();
	?>
	<style>
		#login h1 a, .login h1 a {
			<?php if ( $pcm_logo ) : ?>
			background-image: url('<?php echo esc_url( $pcm_logo ); ?>');
			background-size: contain;
			<?php endif; ?>
			width: 100%;
			height: 84px;
		}
		.login #backtoblog a, .login #nav a { color: #3f6b96; }
		.wp-core-ui .button-primary { background: #c94040; border-color: #c94040; }
		.wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus { background: #af3232; border-color: #af3232; }
	</style>
	<?php
}

/**
 * Replace the {{TOKEN}} placeholders.
 *
 * Tokens are derived from the form's own fields, so one cannot name a field
 * that does not exist. The legacy spellings are kept because they are what any
 * reply template saved before the form became configurable still contains, and
 * silently blanking someone's saved copy would be the worst way to introduce a
 * form builder.
 *
 * Values are escaped before substitution, so a submitted name cannot inject
 * markup into the email.
 */
function pcm_crm_fill_tokens( $pcm_text, array $pcm_values ) {
	$pcm_map = array();

	foreach ( pcm_crm_form_fields() as $pcm_field ) {
		$pcm_value = isset( $pcm_values[ $pcm_field['key'] ] ) ? $pcm_values[ $pcm_field['key'] ] : '';

		$pcm_map[ pcm_crm_field_token( $pcm_field ) ] = $pcm_value;

		// Underscored spelling too: the screen documents the spaced form but
		// the underscored one is the reflex.
		$pcm_map[ '{{' . strtoupper( $pcm_field['key'] ) . '}}' ] = $pcm_value;
	}

	$pcm_first = pcm_crm_field_with_map( 'contact.first_name' );
	$pcm_last  = pcm_crm_field_with_map( 'contact.last_name' );

	$pcm_first_value = $pcm_first && isset( $pcm_values[ $pcm_first['key'] ] ) ? $pcm_values[ $pcm_first['key'] ] : '';
	$pcm_last_value  = $pcm_last && isset( $pcm_values[ $pcm_last['key'] ] ) ? $pcm_values[ $pcm_last['key'] ] : '';

	$pcm_map['{{FULL NAME}}'] = trim( $pcm_first_value . ' ' . $pcm_last_value );
	$pcm_map['{{FULL_NAME}}'] = $pcm_map['{{FULL NAME}}'];

	// Templates written against the original fixed form.
	$pcm_legacy = array(
		'{{FIRST NAME}}'   => 'contact.first_name',
		'{{FIRST_NAME}}'   => 'contact.first_name',
		'{{LAST NAME}}'    => 'contact.last_name',
		'{{LAST_NAME}}'    => 'contact.last_name',
		'{{ORGANIZATION}}' => 'account.name',
		'{{EMAIL}}'        => 'contact.email',
		'{{INTEREST}}'     => 'contact.service_interest',
	);

	foreach ( $pcm_legacy as $pcm_token => $pcm_target ) {
		if ( isset( $pcm_map[ $pcm_token ] ) ) {
			continue;
		}

		$pcm_field = pcm_crm_field_with_map( $pcm_target );

		$pcm_map[ $pcm_token ] = ( $pcm_field && isset( $pcm_values[ $pcm_field['key'] ] ) ) ? $pcm_values[ $pcm_field['key'] ] : '';
	}

	return strtr( $pcm_text, array_map( 'esc_html', $pcm_map ) );
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
		'From: ' . pcm_crm_email_from(),
		'Reply-To: ' . $pcm_from,
	);

	add_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );
	$pcm_sent = wp_mail( $pcm_fields['email'], $pcm_subject, $pcm_html, $pcm_headers, pcm_crm_attachment_paths() );
	remove_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );

	return $pcm_sent;
}

/**
 * The internal notification, with a link straight to the new CRM record.
 *
 * Every field is listed rather than a fixed handful, so a field added in the
 * builder appears in the email without anyone remembering to add it here.
 */
function pcm_crm_send_notification( array $pcm_values, $pcm_contact_id = 0 ) {
	$pcm_mapped = pcm_crm_map_submission( $pcm_values );

	$pcm_name = trim(
		( isset( $pcm_mapped['contact']['first_name'] ) ? $pcm_mapped['contact']['first_name'] : '' ) . ' ' .
		( isset( $pcm_mapped['contact']['last_name'] ) ? $pcm_mapped['contact']['last_name'] : '' )
	);

	$pcm_interest = isset( $pcm_mapped['contact']['service_interest'] ) ? $pcm_mapped['contact']['service_interest'] : '';
	$pcm_email    = isset( $pcm_mapped['contact']['email'] ) ? $pcm_mapped['contact']['email'] : '';

	$pcm_subject = 'New inquiry';
	if ( $pcm_interest ) { $pcm_subject .= ' — ' . $pcm_interest; }
	if ( $pcm_name ) { $pcm_subject .= ' — ' . $pcm_name; }

	$pcm_message = pcm_crm_submission_summary( $pcm_values ) . "\n";

	if ( $pcm_contact_id ) {
		// The configured recipient (pcm_crm_contact_recipient()) is not
		// necessarily the person triggering this send, and is not even
		// guaranteed to be a WordPress user at all — pcm_crm_link_host_for_email()
		// resolves the right shape when it is, and falls back to the admin
		// link (the shape that has always worked) otherwise.
		$pcm_message .= "\nOpen in the CRM: " . esc_url_raw( set_url_scheme(
			pcm_crm_screen_url( 'pcm-crm-contacts', array( 'id' => $pcm_contact_id ), pcm_crm_link_host_for_email( pcm_crm_contact_recipient() ) ),
			'https'
		) ) . "\n";
	}

	$pcm_headers = $pcm_email ? array( 'Reply-To: ' . $pcm_email ) : array();

	return wp_mail( pcm_crm_contact_recipient(), $pcm_subject, $pcm_message, $pcm_headers );
}
