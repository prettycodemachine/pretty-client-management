<?php
/**
 * Plugin Name: PCM CRM
 * Plugin URI:  https://prettycodemachine.com
 * Description: Accounts, Contacts, Opportunities and Activities for Pretty Code Machine — modelled on Salesforce's standard objects so the data can be migrated into a real org later. Also owns the site's contact form.
 * Version:     0.1.0
 * Author:      Jason Jensen
 * Author URI:  https://prettycodemachine.com
 * License:     GPL-2.0-or-later
 * Text Domain: pcm-crm
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PCM_CRM_VERSION', '0.1.0' );
define( 'PCM_CRM_FILE', __FILE__ );
define( 'PCM_CRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCM_CRM_URL', plugin_dir_url( __FILE__ ) );

/**
 * The capability every CRM screen and REST route is gated on.
 *
 * A named capability rather than manage_options, so CRM access can later be
 * granted to someone who should not be able to change site settings.
 */
define( 'PCM_CRM_CAP', 'pcm_crm_manage' );

/**
 * The lead source stamped on contacts created by the site's contact form.
 *
 * A constant rather than a literal because the intake writes it and the
 * picklist offers it, and the two silently diverging would leave form
 * contacts unfindable by the filter that exists to find them.
 */
define( 'PCM_CRM_FORM_SOURCE', 'Contact Form' );

require_once PCM_CRM_DIR . 'includes/capabilities.php';
require_once PCM_CRM_DIR . 'includes/picklists.php';
require_once PCM_CRM_DIR . 'includes/form-builder.php';
require_once PCM_CRM_DIR . 'includes/class-pcm-crm-schema.php';
require_once PCM_CRM_DIR . 'includes/class-pcm-crm-model.php';
require_once PCM_CRM_DIR . 'includes/model-account.php';
require_once PCM_CRM_DIR . 'includes/model-contact.php';
require_once PCM_CRM_DIR . 'includes/model-opportunity.php';
require_once PCM_CRM_DIR . 'includes/model-activity.php';
require_once PCM_CRM_DIR . 'includes/model-submission.php';
require_once PCM_CRM_DIR . 'includes/model-history.php';
require_once PCM_CRM_DIR . 'includes/reports.php';
require_once PCM_CRM_DIR . 'includes/class-pcm-crm-rest.php';
require_once PCM_CRM_DIR . 'includes/csv.php';
require_once PCM_CRM_DIR . 'includes/schedules.php';
require_once PCM_CRM_DIR . 'admin/menu.php';
require_once PCM_CRM_DIR . 'admin/settings.php';
require_once PCM_CRM_DIR . 'public/email.php';
require_once PCM_CRM_DIR . 'public/intake.php';
require_once PCM_CRM_DIR . 'public/form.php';

// Demo data. The file registers nothing unless WP-CLI is running, so it has no
// web-facing surface, and the command itself refuses to run on production.
require_once PCM_CRM_DIR . 'includes/cli-seed.php';

/**
 * Asset URL stamped with the file's mtime.
 *
 * Mirrors pcm_asset() in the theme: rsync preserves mtimes, so the deployed
 * file carries the same stamp as the local one and the query string changes
 * only when the bytes do. Nothing needs bumping by hand to ship a change.
 */
function pcm_crm_asset( $pcm_path ) {
	$pcm_file = PCM_CRM_DIR . 'assets/' . ltrim( $pcm_path, '/' );
	$pcm_ver  = file_exists( $pcm_file ) ? filemtime( $pcm_file ) : PCM_CRM_VERSION;

	return add_query_arg( 'ver', $pcm_ver, PCM_CRM_URL . 'assets/' . ltrim( $pcm_path, '/' ) );
}

/**
 * Create or upgrade the tables.
 *
 * Runs on activation and on every load where the stored schema version has
 * fallen behind the code's — a plugin updated by rsync never fires the
 * activation hook, so the version check is the only thing that catches it.
 */
function pcm_crm_maybe_upgrade() {
	if ( PCM_CRM_Schema::installed_version() === PCM_CRM_Schema::VERSION ) {
		return;
	}

	PCM_CRM_Schema::install();
}
add_action( 'plugins_loaded', 'pcm_crm_maybe_upgrade' );

function pcm_crm_activate() {
	PCM_CRM_Schema::install();
	pcm_crm_add_capabilities();

	// Carry the theme's contact form settings over on first activation, so the
	// live copy and recipient survive the move out of the theme.
	pcm_crm_migrate_theme_options();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pcm_crm_activate' );

function pcm_crm_deactivate() {
	pcm_crm_deactivate_cron();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pcm_crm_deactivate' );
