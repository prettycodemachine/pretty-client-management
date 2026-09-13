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
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url() { return 'https://example.com/plugin/'; }
function add_query_arg( $k, $v = null, $u = null ) { return is_array($k) ? $u : $u . '?' . $k . '=' . $v; }
function get_role() { return null; } function get_users() { return array(); }
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
} function current_user_can() { return true; }
function is_admin() { return false; } function get_theme_mod() { return 0; }
function wp_get_attachment_image_src() { return false; }
function get_current_screen() { return null; }
function wp_enqueue_style() {} function wp_enqueue_script() {} function wp_localize_script() {}
function wp_enqueue_media() {} function wp_create_nonce() { return 'nonce'; }
function rest_url( $n ) { return 'https://example.com/wp-json/' . $n; }
function add_menu_page() {} function add_submenu_page() {} function wp_nonce_url( $u ) { return $u; }
function checked() {} function selected() {} function submit_button() {} function settings_fields() {}
function settings_errors() {} function wp_editor() {} function wp_get_current_user() { return (object) array( 'user_email' => 'a@b.c' ); }
function esc_attr_e( $s ) { echo $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_get_attachment_image() { return ''; } function antispambot( $s ) { return $s; }
function wp_die() {} function nocache_headers() {} function flush_rewrite_rules() {}
function wp_mail() { return true; } function remove_filter() {}
function wp_verify_nonce() { return true; } function wp_safe_redirect() {}
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
function register_rest_route() {} function rest_ensure_response( $v ) { return $v; }
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
