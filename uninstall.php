<?php
/**
 * Runs when the plugin is deleted from the Plugins screen — not on deactivate.
 *
 * Deletes nothing unless PCM Settings › Data & Uninstall was ticked on that
 * site (includes/data-settings.php). None of the plugin's own files are
 * loaded here, so everything below names its tables, options and roles by
 * prefix rather than through the plugin's functions.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

function pcm_crm_uninstall_site() {
	global $wpdb;

	if ( ! get_option( 'pcm_crm_delete_data_on_uninstall', false ) ) {
		return;
	}

	// Every table this plugin creates, CRM and module alike, shares one prefix.
	$pcm_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'pcm_crm_' ) . '%' ) );

	foreach ( $pcm_tables as $pcm_table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$pcm_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- names come from SHOW TABLES above
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'pcm_crm_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'pcm_crm_' ) . '%' ) );

	wp_clear_scheduled_hook( 'pcm_crm_send_scheduled' );

	// Users who held these roles are left with none, not deleted: a staff
	// member or client may still own posts or comments on the site.
	remove_role( 'pcm_crm_staff' );
	remove_role( 'pcm_client' );

	foreach ( wp_roles()->role_objects as $pcm_role ) {
		$pcm_role->remove_cap( 'pcm_crm_manage' );
		$pcm_role->remove_cap( 'pcm_crm_settings' );
	}

	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pcm_site_id ) {
		switch_to_blog( $pcm_site_id );
		pcm_crm_uninstall_site();
		restore_current_blog();
	}
} else {
	pcm_crm_uninstall_site();
}
