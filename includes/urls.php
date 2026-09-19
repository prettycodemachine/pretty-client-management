<?php
/**
 * Where a CRM screen lives, for whichever host is asking.
 *
 * Every link into the app — the app bar, an email footer, a JS drill-down —
 * goes through pcm_crm_screen_url() (PHP) or screenUrl() (JS, which reads the
 * slug map this file serves through the localized config), so "wp-admin or
 * the front end" is decided in exactly two places rather than at every call
 * site. The admin shape is kept verbatim, admin.php?page=<slug>, because a
 * notification email sent months ago links to it and must keep working; the
 * front end is the pretty shape under the configured base. The admin page
 * slug is a screen's identity in both hosts, for the same reason CRM
 * Settings' own slugs did not change when it moved between menus.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_FRONT_BASE_OPTION = 'pcm_crm_front_base';

/**
 * The front-end path segment staff work under: example.com/<base>/.
 *
 * A stored empty string is treated the same as unset — the sanitiser refuses
 * to store one, but an option that predates the sanitiser, or one cleared by
 * hand in the database, must not silently take the base out of the URL.
 */
function pcm_crm_front_base() {
	$pcm_base = (string) get_option( PCM_CRM_FRONT_BASE_OPTION, 'staff' );

	return '' !== $pcm_base ? $pcm_base : 'staff';
}

function pcm_crm_front_base_url() {
	return trailingslashit( home_url( '/' . pcm_crm_front_base() . '/' ) );
}

/**
 * Segments the base must never collide with.
 *
 * wp-admin/wp-content/wp-includes are WordPress's own; the rest are what core
 * itself registers rewrite rules for (a permalink structure, an author or
 * category archive, the comment feed) — a base matching one of those would
 * not 404, it would silently lose to whichever rule WordPress tries first.
 */
function pcm_crm_front_base_reserved() {
	return array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'page', 'feed', 'author', 'category', 'tag', 'comments', 'embed' );
}

/**
 * Refuse a base that would collide with something else already answering
 * that path, rather than accept it and let requests 404 or resolve to the
 * wrong thing.
 */
function pcm_crm_sanitize_front_base( $pcm_value ) {
	$pcm_value = sanitize_title( (string) $pcm_value );

	if ( '' === $pcm_value ) { $pcm_value = 'staff'; }

	if ( in_array( $pcm_value, pcm_crm_front_base_reserved(), true ) ) {
		add_settings_error( PCM_CRM_FRONT_BASE_OPTION, 'pcm_crm_front_base_reserved',
			__( 'That path is reserved by WordPress itself. Choose another.', 'pcm-crm' ) );

		return pcm_crm_front_base();
	}

	if ( get_page_by_path( $pcm_value ) ) {
		add_settings_error( PCM_CRM_FRONT_BASE_OPTION, 'pcm_crm_front_base_page',
			__( 'A page already exists at that path. Choose another, or change the page’s slug.', 'pcm-crm' ) );

		return pcm_crm_front_base();
	}

	if ( function_exists( 'pcm_crm_portal_url' ) ) {
		$pcm_portal_page = (int) get_option( 'pcm_crm_portal_page_id', 0 );
		$pcm_portal_post = $pcm_portal_page ? get_post( $pcm_portal_page ) : null;

		if ( $pcm_portal_post && $pcm_portal_post->post_name === $pcm_value ) {
			add_settings_error( PCM_CRM_FRONT_BASE_OPTION, 'pcm_crm_front_base_portal',
				__( 'That path is the Client Portal’s own page. Choose another.', 'pcm-crm' ) );

			return pcm_crm_front_base();
		}
	}

	return $pcm_value;
}

pcm_crm_register_setup_page( 'employee-portal', array(
	'group'       => 'platform',
	'label'       => __( 'Employee Portal', 'pcm-crm' ),
	'description' => __( 'The front-end address staff work under.', 'pcm-crm' ),
	'render'      => 'pcm_crm_render_employee_portal_tab',
	'order'       => 60,
) );

function pcm_crm_register_front_settings() {
	pcm_crm_register_setting( 'pcm_crm_front_settings', PCM_CRM_FRONT_BASE_OPTION, array(
		'type'              => 'string',
		'sanitize_callback' => 'pcm_crm_sanitize_front_base',
		'default'           => 'staff',
	) );
}
add_action( 'admin_init', 'pcm_crm_register_front_settings' );

function pcm_crm_render_employee_portal_tab() {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="pcm-crm-card">
		<?php settings_fields( 'pcm_crm_front_settings' ); ?>
		<p class="description">
			<?php esc_html_e( 'Staff sign in and work from this address rather than wp-admin. Changing it takes effect on the next page load.', 'pcm-crm' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="pcm-crm-front-base"><?php esc_html_e( 'Address', 'pcm-crm' ); ?></label></th>
				<td>
					<code><?php echo esc_html( home_url( '/' ) ); ?></code>
					<input type="text" id="pcm-crm-front-base" name="<?php echo esc_attr( PCM_CRM_FRONT_BASE_OPTION ); ?>"
						value="<?php echo esc_attr( pcm_crm_front_base() ); ?>" class="regular-text" style="width:120px">
					<code>/</code>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}

/**
 * Every registered app's screens, admin slug → the front-end word for it.
 *
 * Derived from pcm_crm_apps() rather than hand-written, so a screen a module
 * adds is routable on the front end the moment it registers — the same
 * reasoning pcm_crm_object_directory() already applies to record pages.
 * Each app's item tuple already carries the data-view key as its own
 * front-end-friendly slug ('accounts', 'contacts', …), so there is nothing to
 * invent here beyond the handful that do not derive cleanly.
 */
function pcm_crm_front_overrides() {
	return apply_filters( 'pcm_crm_front_overrides', array(
		// The CRM app's own top-level slug reads as "home" on the front end,
		// where there is no separate top-level menu to land on first.
		'pcm-crm' => 'home',
		// The four app-backed Setup pages are not part of any pcm_crm_apps()
		// item tuple — they are their own top-level admin pages — so they need
		// an entry here rather than deriving one. Each word matches the key it
		// is already registered under (pcm_crm_register_setup_page(),
		// includes/setup.php), which is what the Setup nav already calls it.
		'pcm-crm-templates'   => 'templates',
		'pcm-crm-sequences'   => 'sequences',
		'pcm-crm-schedules'   => 'schedules',
		'pcm-crm-recycle-bin' => 'recycle-bin',
	) );
}

function pcm_crm_front_slug_map() {
	$pcm_map = pcm_crm_front_overrides();

	foreach ( pcm_crm_apps() as $pcm_app ) {
		foreach ( (array) $pcm_app['items'] as $pcm_admin_slug => $pcm_item ) {
			if ( isset( $pcm_map[ $pcm_admin_slug ] ) ) { continue; }

			$pcm_map[ $pcm_admin_slug ] = $pcm_item[1];
		}
	}

	return apply_filters( 'pcm_crm_front_slug_map', $pcm_map );
}

function pcm_crm_front_slug( $pcm_admin_slug ) {
	$pcm_map = pcm_crm_front_slug_map();

	return isset( $pcm_map[ $pcm_admin_slug ] ) ? $pcm_map[ $pcm_admin_slug ] : '';
}

/**
 * The reverse of the map above — what the router needs, given the word in
 * the URL, to know which admin page (and so which render callback) it names.
 */
function pcm_crm_slug_for_front( $pcm_front_slug ) {
	foreach ( pcm_crm_front_slug_map() as $pcm_admin_slug => $pcm_mapped ) {
		if ( $pcm_mapped === $pcm_front_slug ) { return $pcm_admin_slug; }
	}

	return '';
}

/**
 * Where a screen lives, for whichever host is asking.
 *
 * $pcm_args may carry 'id' (record id, becomes a path segment on the front
 * end and a #id= fragment in the admin shape) and 'fragment' (a raw hash,
 * used only when there is no id). $pcm_host defaults to whichever host the
 * current user belongs to.
 */
function pcm_crm_screen_url( $pcm_slug, array $pcm_args = array(), $pcm_host = '' ) {
	$pcm_host     = $pcm_host ? $pcm_host : pcm_crm_link_host();
	$pcm_id       = isset( $pcm_args['id'] ) ? (int) $pcm_args['id'] : 0;
	$pcm_fragment = isset( $pcm_args['fragment'] ) ? $pcm_args['fragment'] : '';

	if ( 'front' === $pcm_host ) {
		$pcm_front_slug = pcm_crm_front_slug( $pcm_slug );

		if ( $pcm_front_slug ) {
			$pcm_url = pcm_crm_front_base_url() . $pcm_front_slug . '/';

			if ( $pcm_id ) { $pcm_url .= $pcm_id . '/'; }

			return $pcm_url . $pcm_fragment;
		}

		// No front-end route for this screen yet — PCM Settings and its
		// app-backed pages, until a later phase wires them. Falling through
		// to the admin shape is what keeps the link working rather than
		// pointing nowhere; pcm_crm_redirect_from_admin() (includes/roles.php)
		// is what then lets a front-end user actually follow it there.
	}

	$pcm_url = admin_url( 'admin.php?page=' . $pcm_slug );

	if ( $pcm_id ) { return $pcm_url . '#id=' . $pcm_id; }

	return $pcm_url . $pcm_fragment;
}

/**
 * Which host a link should point at, resolved from a specific user id.
 *
 * Cron has no "current user" — a scheduled report's email footer is built
 * outside any request, for a recipient who is never the one running the
 * code — so host resolution has to take a user id rather than assume one.
 * An administrator always resolves to 'admin', even if they also hold the
 * staff role, because they are never locked out of wp-admin; unknown or
 * unresolvable falls back to 'admin' too, the shape that has always worked.
 */
function pcm_crm_link_host_for_user( $pcm_user_id ) {
	$pcm_user_id = (int) $pcm_user_id;

	if ( ! $pcm_user_id ) { return 'admin'; }

	if ( user_can( $pcm_user_id, 'manage_options' ) ) { return 'admin'; }

	if ( user_can( $pcm_user_id, PCM_CRM_CAP ) ) { return 'front'; }

	return 'admin';
}

function pcm_crm_link_host() {
	return pcm_crm_link_host_for_user( get_current_user_id() );
}

/**
 * The same question, for a caller that only has an email address — the
 * contact-form notification (public/email.php) goes to whatever address is
 * configured as the recipient, which is not necessarily tied to any request
 * or "current user" and is not even guaranteed to belong to a WordPress user
 * at all (a shared inbox, an address with no account on this site). Resolved
 * via the one WP_User it might belong to, falling through to 'admin' — the
 * shape that has always worked — exactly like an unresolvable user id does
 * above.
 */
function pcm_crm_link_host_for_email( $pcm_email ) {
	$pcm_user = $pcm_email ? get_user_by( 'email', $pcm_email ) : null;

	return $pcm_user ? pcm_crm_link_host_for_user( $pcm_user->ID ) : 'admin';
}

/**
 * Whether the request being served right now is on the front end.
 *
 * A different question from pcm_crm_link_host_for_user(): that one is "which
 * host should a link for this user point at," asked when building a URL for
 * someone who may not be the one making the request (a cron email footer).
 * This is "which host is rendering this exact page," and it is what
 * pcm_crm_screen() (admin/menu.php) uses to pick its own shell without every
 * render callback having to say so. is_admin() is true for every wp-admin
 * screen and false for everything else, and the front-end router
 * (public/staff-template.php) is the only other place pcm_crm_screen() is
 * ever called from — so the two together are the whole answer.
 */
function pcm_crm_is_front_request() {
	return ! is_admin();
}
