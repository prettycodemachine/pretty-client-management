<?php
/**
 * How the app's scripts and styles are delivered.
 *
 * Today the CRM app runs only in wp-admin, where SiteGround Speed Optimizer
 * does nothing at all — both its minifier and its combinator bail on
 * is_admin(). The employee portal puts these same files on the front end,
 * where that pipeline is live, so the exclusions below go in ahead of the
 * move rather than after the first silent failure.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The app's own script handles, in dependency order.
 *
 * charts.js defines the chart API crm.js draws with; pm.js and
 * help-tickets.js both declare crm.js as a dependency, which is what
 * guarantees they run after window.PCM_CRM_App exists but before crm.js's
 * own DOMContentLoaded listener fires. Combining rewrites that order.
 */
function pcm_crm_script_handles() {
	return array(
		'pcm-crm-charts',
		'pcm-crm',
		'pcm-crm-pm',
		'pcm-crm-help-tickets',
		'pcm-crm-settings',
	);
}

function pcm_crm_style_handles() {
	return array( 'pcm-crm', 'pcm-crm-setup', 'pcm-crm-pm' );
}

/**
 * Keep the app's scripts out of Speed Optimizer's combined bundle.
 *
 * Two things break when they go in. The dependency chain above is flattened
 * into one file in whatever order the combiner picks; and the inline block
 * wp_localize_script() emits — <script id="pcm-crm-js-extra">, which is the
 * only place window.PCM_CRM is ever defined — can be moved relative to
 * crm.js, so the app boots before its own configuration exists.
 *
 * Deliberately not excluding pcm-crm-portal: the client portal has been on
 * the front end, and therefore inside this pipeline, since it shipped. It
 * works, and it is a standalone file with no dependants — there is nothing
 * to protect it from, and excluding it now would be a change to something
 * that is not broken.
 */
function pcm_crm_combine_exclude( $pcm_handles ) {
	return array_merge( (array) $pcm_handles, pcm_crm_script_handles() );
}
add_filter( 'sgo_javascript_combine_exclude', 'pcm_crm_combine_exclude' );

/**
 * And keep the localized config block out of it by element id.
 *
 * WordPress names an inline data block <handle>-js-extra. Excluding the
 * handle covers the file; this covers the inline script beside it, which is
 * matched separately.
 */
function pcm_crm_combine_exclude_ids( $pcm_ids ) {
	$pcm_own = array();

	foreach ( pcm_crm_script_handles() as $pcm_handle ) {
		$pcm_own[] = $pcm_handle . '-js-extra';
		$pcm_own[] = $pcm_handle . '-js-before';
	}

	return array_merge( (array) $pcm_ids, $pcm_own );
}
add_filter( 'sgo_javascript_combine_exclude_ids', 'pcm_crm_combine_exclude_ids' );

/**
 * Minification is a separate pass from combining and has to be refused
 * separately.
 *
 * Less dangerous than combining — it preserves order — but it rewrites files
 * that are already served with an mtime cache-buster and no build step, so
 * the only thing it can add here is a second copy to debug through.
 */
function pcm_crm_minify_exclude_js( $pcm_handles ) {
	return array_merge( (array) $pcm_handles, pcm_crm_script_handles() );
}
add_filter( 'sgo_js_minify_exclude', 'pcm_crm_minify_exclude_js' );

function pcm_crm_minify_exclude_css( $pcm_handles ) {
	return array_merge( (array) $pcm_handles, pcm_crm_style_handles() );
}
add_filter( 'sgo_css_minify_exclude', 'pcm_crm_minify_exclude_css' );
