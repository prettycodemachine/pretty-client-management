<?php
/**
 * Just enough WordPress to load the plugin outside WordPress.
 *
 * There is no local PHP/WordPress stack for this site — development is edit
 * and deploy — so without this the first time any of this code runs is on
 * staging. The stubs are deliberately shallow: they exist so the pure logic
 * (name matching, tokens, stage rules, sanitising, WHERE building) can be
 * exercised in a second, not to simulate WordPress.
 *
 * Run with:  php tests/run.php
 *
 * What this cannot tell you: anything involving the real database, dbDelta,
 * wp_mail, the REST layer or the browser. That still needs staging.
 */
define( 'ABSPATH', '/tmp/' );

define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'OBJECT', 'OBJECT' );

$GLOBALS['options'] = array();
$GLOBALS['filters'] = array();

function add_action( $h = '', $cb = null, $p = 10, $a = 1 ) { $GLOBALS['filters'][$h][] = array($cb,$a); }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['filters'][$h][] = array($cb,$a); }
function has_filter( $h, $cb = false ) {
	if ( empty( $GLOBALS['filters'][$h] ) ) { return false; }
	if ( false === $cb ) { return true; }
	foreach ( $GLOBALS['filters'][$h] as $hooked ) { if ( $hooked[0] === $cb ) { return true; } }
	return false;
}
/**
 * A pass-through, except for hooks a test has explicitly opted in.
 *
 * The plugin registers filters at load time, so honouring all of them here
 * would quietly change what every other assertion in the suite is measuring.
 * A test that needs to prove a seam actually reaches its listeners opts that
 * one hook in with pcm_test_add_filter() and takes it out again afterwards.
 */
function apply_filters( $h, $v ) {
	if ( empty( $GLOBALS['pcm_test_filters'][$h] ) ) { return $v; }

	$args = array_slice( func_get_args(), 2 );

	foreach ( $GLOBALS['pcm_test_filters'][$h] as $cb ) {
		$v = call_user_func_array( $cb, array_merge( array( $v ), $args ) );
	}

	return $v;
}

$GLOBALS['pcm_test_filters'] = array();

function get_bloginfo( $s = '' ) { return 'Pretty Code Machine'; }

function pcm_test_add_filter( $h, $cb ) { $GLOBALS['pcm_test_filters'][$h][] = $cb; }
function pcm_test_reset_filters( $h ) { unset( $GLOBALS['pcm_test_filters'][$h] ); }
function do_action() {} function add_shortcode() {} function register_activation_hook() {}
function register_deactivation_hook() {} function register_setting() {}
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][$k] ) ? $GLOBALS['options'][$k] : $d; }
function update_option( $k, $v ) { $GLOBALS['options'][$k] = $v; return true; }
function add_option( $k, $v ) { $GLOBALS['options'][$k] = $v; return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return $s; } function esc_url_raw( $s ) { return $s; }
function esc_html__( $s ) { return $s; } function esc_html_e( $s ) { echo $s; }
function __( $s ) { return $s; } function _e( $s ) { echo $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( (string) $s, FILTER_SANITIZE_EMAIL ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function wp_list_pluck( $rows, $key ) { return array_map( function( $r ) use ( $key ) { return isset($r[$key]) ? $r[$key] : null; }, $rows ); }
function current_time( $t ) { return 'timestamp' === $t ? time() : date( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 1; }
function home_url( $p = '/' ) { return 'https://' . ( isset( $GLOBALS['pcm_test_host'] ) ? $GLOBALS['pcm_test_host'] : 'example.com' ) . $p; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function set_url_scheme( $u, $s ) { return preg_replace( '#^https?://#', $s . '://', $u ); }
function wp_json_encode( $v ) { return json_encode( $v ); }

/**
 * A deliberately simplified wpautop: blank lines become paragraphs, single
 * newlines become breaks. Core's does more (it leaves existing block-level
 * elements alone), but this covers the contract the email body relies on.
 */
function wpautop( $pee, $br = true ) {
	$blocks = preg_split( '/\n\s*\n/', trim( $pee ) );
	$out = '';
	foreach ( $blocks as $block ) {
		$block = trim( $block );
		if ( '' === $block ) { continue; }
		if ( $br ) { $block = str_replace( "\n", "<br />\n", $block ); }
		$out .= '<p>' . $block . "</p>\n";
	}
	return $out;
}
function get_attached_file() { return ''; } function wp_kses_post( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url() { return 'https://example.com/plugin/'; }
function add_query_arg( $k, $v = null, $u = null ) { return is_array($k) ? $u : $u . '?' . $k . '=' . $v; }
/**
 * Roles, backed by an array so add_cap() can be asserted.
 *
 * Returning null — as this did — meant pcm_crm_add_capabilities() saw no role
 * and quietly did nothing, so nothing about capability granting was covered.
 */
class PCM_Test_Role {
	public $name; public $capabilities = array();
	function __construct( $n, $c = array() ) { $this->name = $n; $this->capabilities = $c; }
	function add_cap( $c ) { $this->capabilities[ $c ] = true; }
	function remove_cap( $c ) { unset( $this->capabilities[ $c ] ); }
	function has_cap( $c ) { return ! empty( $this->capabilities[ $c ] ); }
}

$GLOBALS['pcm_test_roles'] = array( 'administrator' => new PCM_Test_Role( 'administrator' ) );

function get_role( $pcm_role = '' ) {
	return isset( $GLOBALS['pcm_test_roles'][ $pcm_role ] ) ? $GLOBALS['pcm_test_roles'][ $pcm_role ] : null;
}

function add_role( $pcm_role, $pcm_label = '', $pcm_caps = array() ) {
	// WordPress's add_role() is a no-op once the role exists, and code that
	// reconciles capabilities depends on exactly that.
	if ( isset( $GLOBALS['pcm_test_roles'][ $pcm_role ] ) ) { return null; }

	$GLOBALS['pcm_test_roles'][ $pcm_role ] = new PCM_Test_Role( $pcm_role, (array) $pcm_caps );

	return $GLOBALS['pcm_test_roles'][ $pcm_role ];
}

/**
 * Seeded users, filtered the two ways this plugin actually asks.
 *
 * pcm_crm_owner_choices() queries by capability and falls back to role, so
 * both have to work or "staff are not assignable as owners" passes silently.
 */
function get_users( $pcm_args = array() ) {
	$pcm_users = isset( $GLOBALS['pcm_test_users'] ) ? (array) $GLOBALS['pcm_test_users'] : array();
	$pcm_out   = array();

	foreach ( $pcm_users as $pcm_id => $pcm_user ) {
		if ( ! empty( $pcm_args['capability'] ) && ! user_can( $pcm_id, $pcm_args['capability'] ) ) { continue; }

		if ( ! empty( $pcm_args['role'] ) ) {
			$pcm_roles = isset( $pcm_user->roles ) ? (array) $pcm_user->roles : array();
			if ( ! in_array( $pcm_args['role'], $pcm_roles, true ) ) { continue; }
		}

		$pcm_out[] = $pcm_user;
	}

	return $pcm_out;
}
function get_user_by( $field, $value ) {
	foreach ( (array) ( isset( $GLOBALS['pcm_test_users'] ) ? $GLOBALS['pcm_test_users'] : array() ) as $id => $user ) {
		if ( 'login' === $field && isset( $user->user_login ) && $user->user_login === $value ) { return $user; }
	}
	return null;
}
function wp_delete_file( $f ) { @unlink( $f ); }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function date_i18n( $f ) { return date( $f ); }
function number_format_i18n( $n ) { return number_format( $n ); }
function ucfirst_stub() {}
function get_userdata( $id ) {
	return isset( $GLOBALS['pcm_test_users'][ $id ] ) ? $GLOBALS['pcm_test_users'][ $id ] : null;
}

/**
 * Capabilities, settable but open by default.
 *
 * Defaulting to "everything true" is not laziness — it is what keeps every
 * assertion written before capabilities were testable measuring the same
 * thing. pcm_crm_screen() and pcm_crm_render_settings() both sail straight
 * through this today, and a stub that started denying would change what those
 * tests prove without changing a line of them. A test that cares about a gate
 * narrows the set with pcm_test_set_caps() and puts it back afterwards, the
 * same shape pcm_test_add_filter() already uses for hooks.
 */
function pcm_test_set_caps( array $pcm_caps ) { $GLOBALS['pcm_test_caps'] = $pcm_caps; }
function pcm_test_reset_caps() { unset( $GLOBALS['pcm_test_caps'] ); }

function current_user_can( $pcm_cap = '' ) {
	if ( ! isset( $GLOBALS['pcm_test_caps'] ) ) { return true; }

	return ! empty( $GLOBALS['pcm_test_caps'][ $pcm_cap ] );
}

/**
 * The same question asked about somebody else.
 *
 * Reads the seeded user's own caps, falling back to the current set — a test
 * that only cares about "is this gate open" should not have to seed a user.
 */
function user_can( $pcm_user, $pcm_cap = '' ) {
	$pcm_id = is_object( $pcm_user ) ? (int) $pcm_user->ID : (int) $pcm_user;

	if ( isset( $GLOBALS['pcm_test_user_caps'][ $pcm_id ] ) ) {
		return ! empty( $GLOBALS['pcm_test_user_caps'][ $pcm_id ][ $pcm_cap ] );
	}

	return current_user_can( $pcm_cap );
}
function is_admin() { return false; } function get_theme_mod() { return 0; }
function wp_get_attachment_image_src() { return false; }
function get_current_screen() { return null; }
function wp_enqueue_style() {} function wp_enqueue_script() {} function wp_localize_script() {}
function wp_enqueue_media() {} function wp_create_nonce() { return 'nonce'; }
function rest_url( $n ) { return 'https://example.com/wp-json/' . $n; }
function wp_nonce_url( $u ) { return $u; }

/**
 * Menu, rewrite and route registrations, recorded rather than discarded.
 *
 * Each of these is a registration whose *shape* is the thing worth asserting —
 * which parent a page hangs off, that a rewrite rule was added before the
 * generic one that would otherwise swallow it, that no REST route slipped in
 * without a permission mapping. None of that is observable while the stub is
 * an empty function body.
 */
$GLOBALS['pcm_test_menus'] = array();
$GLOBALS['pcm_test_rewrites'] = array();
$GLOBALS['pcm_test_routes'] = array();
$GLOBALS['pcm_test_redirects'] = array();
$GLOBALS['pcm_test_query_vars'] = array();

function add_menu_page( $pt = '', $mt = '', $cap = '', $slug = '', $cb = null, $icon = '', $pos = null ) {
	$GLOBALS['pcm_test_menus'][] = array( 'type' => 'menu', 'slug' => $slug, 'cap' => $cap, 'parent' => '', 'position' => $pos );
}
function add_submenu_page( $parent = '', $pt = '', $mt = '', $cap = '', $slug = '', $cb = null ) {
	$GLOBALS['pcm_test_menus'][] = array( 'type' => 'submenu', 'slug' => $slug, 'cap' => $cap, 'parent' => $parent, 'position' => null );
}
function add_options_page( $pt = '', $mt = '', $cap = '', $slug = '', $cb = null ) {
	$GLOBALS['pcm_test_menus'][] = array( 'type' => 'submenu', 'slug' => $slug, 'cap' => $cap, 'parent' => 'options-general.php', 'position' => null );
}
function remove_submenu_page( $parent = '', $slug = '' ) {
	$GLOBALS['pcm_test_menus'][] = array( 'type' => 'removed', 'slug' => $slug, 'cap' => '', 'parent' => $parent, 'position' => null );
}

function add_rewrite_rule( $pcm_regex, $pcm_query, $pcm_after = 'bottom' ) {
	$GLOBALS['pcm_test_rewrites'][] = array( 'regex' => $pcm_regex, 'query' => $pcm_query, 'after' => $pcm_after );
}
function add_rewrite_tag() {}
function get_query_var( $pcm_var, $pcm_default = '' ) {
	return isset( $GLOBALS['pcm_test_query_vars'][ $pcm_var ] ) ? $GLOBALS['pcm_test_query_vars'][ $pcm_var ] : $pcm_default;
}
function pcm_test_set_query_vars( array $pcm_vars ) { $GLOBALS['pcm_test_query_vars'] = $pcm_vars; }
function checked() {} function selected() {} function submit_button() {} function settings_fields() {}
function add_settings_error() {}
function get_pages() { return array(); }
function settings_errors() {} function wp_editor() {}
function wp_get_current_user() {
	return isset( $GLOBALS['pcm_test_current_user'] )
		? $GLOBALS['pcm_test_current_user']
		: (object) array( 'ID' => 0, 'user_email' => 'a@b.c', 'roles' => array() );
}
function is_user_logged_in() { return ! empty( $GLOBALS['pcm_test_current_user'] ) && ! empty( $GLOBALS['pcm_test_current_user']->ID ); }
function get_user_meta( $id, $key = '', $single = false ) {
	return isset( $GLOBALS['pcm_test_user_meta'][ $id ][ $key ] ) ? $GLOBALS['pcm_test_user_meta'][ $id ][ $key ] : '';
}
function update_user_meta( $id, $key, $value ) { $GLOBALS['pcm_test_user_meta'][ $id ][ $key ] = $value; return true; }
function get_post( $id ) { return in_array( (int) $id, isset( $GLOBALS['pcm_test_attachments'] ) ? $GLOBALS['pcm_test_attachments'] : array(), true ) ? (object) array( 'ID' => (int) $id ) : null; }
function esc_attr_e( $s ) { echo $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_get_attachment_image() { return ''; } function antispambot( $s ) { return $s; }
function wp_die() {} function nocache_headers() {}
function flush_rewrite_rules() { $GLOBALS['pcm_test_flushes'] = isset( $GLOBALS['pcm_test_flushes'] ) ? $GLOBALS['pcm_test_flushes'] + 1 : 1; }
function wp_mail() { return true; } function remove_filter() {}
function wp_verify_nonce() { return true; }

/**
 * Redirects are recorded, not thrown.
 *
 * A redirect in WordPress is followed by exit, so the honest stub would stop
 * execution — but several paths here redirect during an ordinary load, and a
 * stub that threw would turn those into failures that are about the stub
 * rather than the code. Recording the target answers the only question a test
 * actually has: where would this have sent them.
 */
function wp_safe_redirect( $pcm_location = '', $pcm_status = 302 ) {
	$GLOBALS['pcm_test_redirects'][] = array( 'location' => $pcm_location, 'status' => $pcm_status );
	return true;
}
function wp_redirect( $pcm_location = '', $pcm_status = 302 ) { return wp_safe_redirect( $pcm_location, $pcm_status ); }
function wp_login_url( $pcm_redirect = '' ) { return 'https://example.com/wp-login.php'; }
function wp_get_referer() { return ''; } function wp_nonce_field( $a = -1, $n = "_wpnonce" ) { echo '<input type="hidden" name="' . $n . '" value="nonce">'; }
function shortcode_exists() { return true; } function do_shortcode( $s ) { return $s; }
function wp_unslash( $v ) { return $v; }

class WP_Error {
	public $code; public $msg;
	function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; }
	function get_error_code() { return $this->code; }
	function get_error_message() { return $this->msg; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }
/**
 * Recorded so a completeness check can enumerate the routes.
 *
 * Every route shares one permission callback, which resolves what it guards
 * from the request. That only stays true if a route added later cannot go
 * unnoticed — so the registration has to be observable.
 */
function register_rest_route( $pcm_ns = '', $pcm_route = '', $pcm_args = array() ) {
	$GLOBALS['pcm_test_routes'][] = array( 'ns' => $pcm_ns, 'route' => $pcm_route, 'args' => $pcm_args );
	return true;
}
function rest_ensure_response( $v ) { return $v; }
/**
 * Enough of WP_REST_Request to call a controller directly.
 *
 * The parameter precedence matters and is copied from WordPress: JSON body
 * first, then URL. A record with a field named like a route capture therefore
 * shadows it here exactly as it does in production — which is how the schedule
 * bug got past a stub that consulted URL parameters first.
 */
class WP_REST_Request implements ArrayAccess {
	private $url = array();
	private $params = array();
	function __construct( $url = array(), $params = array() ) { $this->url = $url; $this->params = $params; }
	function get_url_params() { return $this->url; }
	function get_json_params() { return $this->params; }
	function get_param( $k ) {
		if ( isset( $this->params[ $k ] ) ) { return $this->params[ $k ]; }
		return isset( $this->url[ $k ] ) ? $this->url[ $k ] : null;
	}
	#[\ReturnTypeWillChange] function offsetExists( $o ) { return null !== $this->get_param( $o ); }
	#[\ReturnTypeWillChange] function offsetGet( $o ) { return $this->get_param( $o ); }
	#[\ReturnTypeWillChange] function offsetSet( $o, $v ) { $this->url[$o] = $v; }
	#[\ReturnTypeWillChange] function offsetUnset( $o ) { unset( $this->url[$o] ); }
}

// Defined so includes/cli-seed.php loads and its production guard can be
// tested. The command class is registered against this stub and never run.
define( 'WP_CLI', true );
class WP_CLI {
	static $commands = array();
	static function add_command( $name, $class ) { self::$commands[ $name ] = $class; }
	static function log( $m ) {} static function warning( $m ) {}
	static function success( $m ) {} static function error( $m ) { throw new Exception( $m ); }
}
function set_transient( $k, $v ) { $GLOBALS['options'][ '_t_' . $k ] = $v; return true; }
function get_transient( $k ) { return isset( $GLOBALS['options'][ '_t_' . $k ] ) ? $GLOBALS['options'][ '_t_' . $k ] : false; }
function delete_transient( $k ) { unset( $GLOBALS['options'][ '_t_' . $k ] ); return true; }
function disabled() {}
function delete_option( $k ) { unset( $GLOBALS['options'][$k] ); return true; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

class FakeWPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $insert_id = 1;
	function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
	function prepare( $q, ...$a ) {
		// Enough of wpdb::prepare to prove the escaping happens, not to match it.
		$q = str_replace( array( '%s', '%d', '%f' ), '%s', $q );
		return vsprintf( $q, array_map( function( $v ) { return is_numeric( $v ) ? $v : "'" . addslashes( $v ) . "'"; }, $a ) );
	}
	function get_var() { return 0; } function get_row() { return null; }
	function get_results() { return array(); }
	function insert() { return 1; } function update() { return 1; }
	function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
}
$GLOBALS['wpdb'] = new FakeWPDB();

require dirname( __DIR__ ) . '/pcm-crm.php';
