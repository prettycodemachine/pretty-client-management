<?php
/**
 * Plugin Name: Pretty Client Management
 * Plugin URI:  https://prettycodemachine.com
 * Description: Pretty Client Management (PCM) is a customizable CRM featuring a powerful Project Management module designed for your growing agency. Includes a pretty client portal your clients will love!
 * Version:     1.2.20
 * Author:      Pretty Code Machine
 * Author URI:  https://prettycodemachine.com
 * License:     GPL-2.0-or-later
 * Text Domain: pcm-crm
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PCM_CRM_VERSION', '1.2.20' );
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
 * The staff role, and the stamp that reconciles its capabilities.
 *
 * Bump PCM_CRM_ROLE_VERSION on any change to pcm_crm_staff_capabilities() —
 * add_role() is a no-op once the role exists, so without the bump a new
 * capability never reaches an install that already has the role.
 */
define( 'PCM_CRM_STAFF_ROLE', 'pcm_crm_staff' );
define( 'PCM_CRM_ROLE_VERSION', 2 );

/**
 * The capability options.php asks about before it will save a settings form.
 *
 * options.php hard-requires manage_options unless an
 * option_page_capability_{$group} filter answers a different capability for
 * that group — see pcm_crm_register_setting() in access-settings.php, which
 * registers that filter for every group this plugin owns, and
 * pcm_crm_map_settings_cap() in capabilities.php, which answers it from the
 * permission matrix rather than from a second, parallel capability grant.
 */
define( 'PCM_CRM_SETTINGS_CAP', 'pcm_crm_settings' );

/**
 * The lead source stamped on contacts created by the site's contact form.
 *
 * A constant rather than a literal because the intake writes it and the
 * picklist offers it, and the two silently diverging would leave form
 * contacts unfindable by the filter that exists to find them.
 */
define( 'PCM_CRM_FORM_SOURCE', 'Contact Form' );

require_once PCM_CRM_DIR . 'includes/capabilities.php';
require_once PCM_CRM_DIR . 'includes/assets.php';
require_once PCM_CRM_DIR . 'includes/themes.php';
require_once PCM_CRM_DIR . 'includes/setup.php';
require_once PCM_CRM_DIR . 'includes/picklists.php';
require_once PCM_CRM_DIR . 'includes/form-builder.php';
require_once PCM_CRM_DIR . 'includes/class-pcm-crm-schema.php';
require_once PCM_CRM_DIR . 'includes/custom-fields.php';
require_once PCM_CRM_DIR . 'includes/duration.php';
require_once PCM_CRM_DIR . 'includes/objects.php';
require_once PCM_CRM_DIR . 'includes/modules.php';
// After objects and modules, both of which it resolves areas from, and before
// anything that gates on the answer.
require_once PCM_CRM_DIR . 'includes/permissions.php';
// The PCM Settings pages over that model. After setup.php (register function),
// permissions.php (the model) and capabilities.php (the staff role constant).
require_once PCM_CRM_DIR . 'includes/access-settings.php';
// CRM Access on WordPress's own Add New User / Edit User screens — after
// access-settings.php, whose pcm_crm_render_access_fields() and
// pcm_crm_clean_user_access() this reuses rather than duplicates.
require_once PCM_CRM_DIR . 'includes/user-access.php';
// Where a screen lives in each host, and the Employee Portal address setting.
require_once PCM_CRM_DIR . 'includes/urls.php';
// The staff role's wp-admin lockout. After urls.php, which it redirects through.
require_once PCM_CRM_DIR . 'includes/roles.php';
require_once PCM_CRM_DIR . 'includes/class-pcm-crm-model.php';
require_once PCM_CRM_DIR . 'includes/model-account.php';
require_once PCM_CRM_DIR . 'includes/model-contact.php';
require_once PCM_CRM_DIR . 'includes/model-opportunity.php';
require_once PCM_CRM_DIR . 'includes/model-activity.php';
require_once PCM_CRM_DIR . 'includes/model-submission.php';
require_once PCM_CRM_DIR . 'includes/model-history.php';
require_once PCM_CRM_DIR . 'includes/reports.php';
require_once PCM_CRM_DIR . 'includes/class-pcm-crm-rest.php';
require_once PCM_CRM_DIR . 'includes/layouts.php';
require_once PCM_CRM_DIR . 'includes/csv.php';
require_once PCM_CRM_DIR . 'includes/schedules.php';
require_once PCM_CRM_DIR . 'includes/email-sequences.php';
require_once PCM_CRM_DIR . 'includes/related.php';
require_once PCM_CRM_DIR . 'admin/menu.php';
require_once PCM_CRM_DIR . 'admin/settings.php';
require_once PCM_CRM_DIR . 'public/email.php';
require_once PCM_CRM_DIR . 'public/intake.php';
require_once PCM_CRM_DIR . 'public/form.php';
// Unconditional, like the files above — it has to answer /staff/… whatever
// the module switches say, the same reason includes/permissions.php declares
// every area whether or not its module is active.
require_once PCM_CRM_DIR . 'public/staff.php';
require_once PCM_CRM_DIR . 'public/staff-profile.php';

// Last, so a module can register against everything above it — objects, related
// lists, merge prefixes, settings tabs — without the core files knowing it
// exists.
pcm_crm_load_modules();

// Demo data. The file registers nothing unless WP-CLI is running, so it has no
// web-facing surface, and the command itself refuses to run on production.
require_once PCM_CRM_DIR . 'includes/sample-content.php';
require_once PCM_CRM_DIR . 'includes/sample-data.php';
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

	// A plugin updated by rsync never fires the activation hook, so the samples
	// arrive on the same version check the tables do.
	pcm_crm_maybe_install_sample_content();
}
add_action( 'plugins_loaded', 'pcm_crm_maybe_upgrade' );

function pcm_crm_activate() {
	PCM_CRM_Schema::install();
	pcm_crm_add_capabilities();

	// Carry the theme's contact form settings over on first activation, so the
	// live copy and recipient survive the move out of the theme.
	pcm_crm_migrate_theme_options();

	// An empty Templates screen is a feature nobody tries.
	pcm_crm_maybe_install_sample_content();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pcm_crm_activate' );

function pcm_crm_deactivate() {
	pcm_crm_deactivate_cron();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pcm_crm_deactivate' );
