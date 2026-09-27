<?php
/**
 * How the app looks: a style for the site, a colour mode for each person.
 *
 * A palette is nothing but a set of custom properties. Every colour in the
 * CRM's stylesheet already reads from one, so a palette overrides the tokens
 * and the whole interface follows — including the charts, which read the same
 * values at runtime rather than carrying their own.
 *
 * There are two palettes, Light and Dark, and which one draws is each
 * person's own choice (the toggle in the nav, pcm_crm_color_mode_toggle()),
 * not a site setting — one person working late should not turn the lights
 * out for everyone else. The site-wide choice is the style (below).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The palettes, keyed by the data-theme value each one is drawn under.
 *
 * 'pcm' rather than 'light' because it is the house palette and the key the
 * stylesheet has always carried; the colour mode maps onto it.
 */
function pcm_crm_themes() {
	return array(
		'pcm'  => array(
			'label' => __( 'Light', 'pcm-crm' ),
			'mode'  => 'light',
		),
		'dark' => array(
			'label' => __( 'Dark', 'pcm-crm' ),
			'mode'  => 'dark',
		),
	);
}

/**
 * The palette the current screen draws in: the viewer's own colour mode.
 */
function pcm_crm_theme() {
	return 'dark' === pcm_crm_color_mode() ? 'dark' : 'pcm';
}

/**
 * The viewer's colour mode, light unless they have switched it.
 *
 * User meta rather than browser storage, so the page is drawn in the right
 * palette on the server — no flash of light before a script can correct it —
 * and the choice follows someone from their laptop to their phone.
 */
function pcm_crm_color_mode( $pcm_user_id = 0 ) {
	$pcm_user_id = $pcm_user_id ? (int) $pcm_user_id : get_current_user_id();

	if ( ! $pcm_user_id ) {
		return 'light';
	}

	return pcm_crm_sanitize_color_mode( get_user_meta( $pcm_user_id, 'pcm_crm_color_mode', true ) );
}

function pcm_crm_sanitize_color_mode( $pcm_value ) {
	return 'dark' === $pcm_value ? 'dark' : 'light';
}

/**
 * The switch itself: a real form, so it works before (and without) the
 * script, which only makes it instant (assets/color-mode.js).
 *
 * It posts the mode to switch *to*. Both labels are rendered and CSS shows the
 * one matching the body's pcm-crm-mode-* class, so the script flips a class
 * and a hidden value and never has to rewrite the button's text.
 */
function pcm_crm_color_mode_toggle() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$pcm_mode = pcm_crm_color_mode();
	?>
	<form class="pcm-crm-mode-toggle" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pcm_crm_color_mode">
		<input type="hidden" name="mode" value="<?php echo esc_attr( 'dark' === $pcm_mode ? 'light' : 'dark' ); ?>">
		<?php wp_nonce_field( 'pcm_crm_color_mode', 'pcm_crm_color_mode_nonce', false ); ?>
		<button type="submit" class="pcm-crm-mode-button">
			<span class="pcm-crm-mode-to-dark">
				<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M16.5 12.6A7 7 0 0 1 7.4 3.5a7 7 0 1 0 9.1 9.1z" fill="currentColor"/></svg>
				<?php esc_html_e( 'Dark mode', 'pcm-crm' ); ?>
			</span>
			<span class="pcm-crm-mode-to-light">
				<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="3.6" fill="currentColor"/><path d="M10 1.5v2.2M10 16.3v2.2M1.5 10h2.2M16.3 10h2.2M4 4l1.6 1.6M14.4 14.4 16 16M4 16l1.6-1.6M14.4 5.6 16 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
				<?php esc_html_e( 'Light mode', 'pcm-crm' ); ?>
			</span>
		</button>
	</form>
	<?php
}

/**
 * The class the body carries for the current mode, which the toggle's labels
 * and the front end's own page ground read.
 */
function pcm_crm_color_mode_class() {
	return 'pcm-crm-mode-' . pcm_crm_color_mode();
}

function pcm_crm_color_mode_admin_body_class( $pcm_classes ) {
	return $pcm_classes . ' ' . pcm_crm_color_mode_class();
}
add_filter( 'admin_body_class', 'pcm_crm_color_mode_admin_body_class' );

/**
 * Save someone's colour mode.
 *
 * Their own preference only — the id is always the current user's, never a
 * posted one — so logging in is the only permission it needs. The script
 * posts with ajax=1 and gets JSON back; without the script the form posts
 * normally and returns to the page it came from.
 */
function pcm_crm_handle_color_mode() {
	if ( ! isset( $_POST['pcm_crm_color_mode_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcm_crm_color_mode_nonce'] ) ), 'pcm_crm_color_mode' ) ) {
		wp_die( esc_html__( 'This link has expired. Go back, reload the page and try again.', 'pcm-crm' ), '', array( 'response' => 403 ) );
	}

	$pcm_mode = pcm_crm_sanitize_color_mode( isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '' );
	update_user_meta( get_current_user_id(), 'pcm_crm_color_mode', $pcm_mode );

	if ( ! empty( $_POST['ajax'] ) ) {
		wp_send_json_success( array( 'mode' => $pcm_mode ) );
	}

	$pcm_back = wp_get_referer();
	wp_safe_redirect( $pcm_back ? $pcm_back : pcm_crm_front_base_url() );
	exit;
}
add_action( 'admin_post_pcm_crm_color_mode', 'pcm_crm_handle_color_mode' );

/**
 * Interface styles — the site-wide half.
 *
 * The palette decides the colours; a style decides the shape (borders,
 * shadows, type, density), and applies to everyone. Classic is the original
 * bold look: ink outlines and offset shadows. Modern is the default.
 */
function pcm_crm_styles() {
	return array(
		'modern'  => array(
			'label'       => __( 'Modern', 'pcm-crm' ),
			'description' => __( 'Hairline borders, soft shadows and a tighter layout. Lets the data lead.', 'pcm-crm' ),
		),
		'classic' => array(
			'label'       => __( 'Classic', 'pcm-crm' ),
			'description' => __( 'Bold ink outlines and offset shadows — the original Pretty Client Management look.', 'pcm-crm' ),
		),
	);
}

/**
 * The chosen style, falling back to Modern.
 */
function pcm_crm_style() {
	$pcm_style = (string) get_option( 'pcm_crm_style', 'modern' );

	return isset( pcm_crm_styles()[ $pcm_style ] ) ? $pcm_style : 'modern';
}

function pcm_crm_sanitize_style( $pcm_value ) {
	$pcm_value = sanitize_key( $pcm_value );

	return isset( pcm_crm_styles()[ $pcm_value ] ) ? $pcm_value : 'modern';
}
