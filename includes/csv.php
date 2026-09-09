<?php
/**
 * CSV export, with Salesforce API names as the header row.
 *
 * This is the migration path the whole schema exists for: the file that comes
 * out of here should load into a Salesforce org through Data Loader with no
 * column mapping beyond the record-id lookups.
 *
 * Served through admin-post rather than REST because it is a file download —
 * the browser navigates to it, so it cannot carry a REST nonce header.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_export_url( $pcm_object, array $pcm_query = array() ) {
	return wp_nonce_url(
		add_query_arg(
			array_merge( array( 'action' => 'pcm_crm_export', 'object' => $pcm_object ), $pcm_query ),
			admin_url( 'admin-post.php' )
		),
		'pcm_crm_export',
		'pcm_crm_nonce'
	);
}

function pcm_crm_handle_export() {
	if (
		! pcm_crm_user_can() ||
		! isset( $_GET['pcm_crm_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_GET['pcm_crm_nonce'] ), 'pcm_crm_export' )
	) {
		wp_die( esc_html__( 'You are not allowed to export CRM data.', 'pcm-crm' ), 403 );
	}

	$pcm_object = isset( $_GET['object'] ) ? sanitize_key( wp_unslash( $_GET['object'] ) ) : '';
	$pcm_model  = PCM_CRM_REST::model( $pcm_object );

	if ( ! $pcm_model ) {
		wp_die( esc_html__( 'Unknown object.', 'pcm-crm' ), 404 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above
	$pcm_filters = isset( $_GET['filters'] ) && is_array( $_GET['filters'] ) ? wp_unslash( $_GET['filters'] ) : array();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above
	$pcm_search  = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

	$pcm_rows = $pcm_model->find( array(
		'search'   => $pcm_search,
		'filters'  => $pcm_filters,
		'per_page' => 0,
		'orderby'  => 'id',
		'order'    => 'ASC',
	) );

	$pcm_map      = $pcm_model->salesforce_map();
	$pcm_filename = 'pcm-' . $pcm_object . '-' . gmdate( 'Y-m-d' ) . '.csv';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $pcm_filename . '"' );

	$pcm_out = fopen( 'php://output', 'w' );

	// Excel reads a bare UTF-8 CSV as Latin-1 and mangles anything accented;
	// the BOM is what makes an exported name survive the round trip.
	fwrite( $pcm_out, "\xEF\xBB\xBF" );

	// The local id leads, under a name Salesforce will not claim, so a loaded
	// record can still be traced back to the row it came from here.
	fputcsv( $pcm_out, array_merge( array( 'PCM_Id__c' ), array_values( $pcm_map ) ) );

	foreach ( $pcm_rows as $pcm_row ) {
		$pcm_line = array( $pcm_row['id'] );

		foreach ( array_keys( $pcm_map ) as $pcm_field ) {
			$pcm_value = isset( $pcm_row[ $pcm_field ] ) ? $pcm_row[ $pcm_field ] : '';

			// Salesforce reads a booleans column as TRUE/FALSE, and rejects an
			// empty date rather than treating 0000-00-00 as null.
			if ( isset( $pcm_model->fields()[ $pcm_field ] ) ) {
				$pcm_type = $pcm_model->fields()[ $pcm_field ]['type'];

				if ( 'bool' === $pcm_type ) {
					$pcm_value = $pcm_value ? 'TRUE' : 'FALSE';
				} elseif ( in_array( $pcm_type, array( 'date', 'datetime' ), true ) && ( ! $pcm_value || 0 === strpos( (string) $pcm_value, '0000' ) ) ) {
					$pcm_value = '';
				}
			}

			$pcm_line[] = $pcm_value;
		}

		fputcsv( $pcm_out, $pcm_line );
	}

	fclose( $pcm_out );
	exit;
}
add_action( 'admin_post_pcm_crm_export', 'pcm_crm_handle_export' );
