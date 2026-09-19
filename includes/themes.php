<?php
/**
 * Admin themes.
 *
 * A theme is nothing but a set of custom properties. Every colour in the CRM's
 * stylesheet already reads from one, so a theme overrides the tokens and the
 * whole interface follows — including the charts, which read the same values
 * at runtime rather than carrying their own palette.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_themes() {
	return array(
		'pcm' => array(
			'label'       => __( 'Pretty Client Management', 'pcm-crm' ),
			'description' => __( 'The house palette — raspberry and powder blue on paper.', 'pcm-crm' ),
			'swatch'      => array( '#c94040', '#aac8e6', '#ffffff' ),
		),
		'paper' => array(
			'label'       => __( 'Paper', 'pcm-crm' ),
			'description' => __( 'Quiet greys. Gets out of the way when the data is the point.', 'pcm-crm' ),
			'swatch'      => array( '#3d4450', '#9aa5b1', '#ffffff' ),
		),
		'dark' => array(
			'label'       => __( 'Dark', 'pcm-crm' ),
			'description' => __( 'Low light, for long sessions and late evenings.', 'pcm-crm' ),
			'swatch'      => array( '#e8998d', '#7fb3d5', '#1b1f27' ),
		),
		'forest' => array(
			'label'       => __( 'Forest', 'pcm-crm' ),
			'description' => __( 'Deep greens and warm sand. Calm without being cold.', 'pcm-crm' ),
			'swatch'      => array( '#2f6f4e', '#c8a86b', '#fbfaf6' ),
		),
		'neon' => array(
			'label'       => __( 'Neon', 'pcm-crm' ),
			'description' => __( 'Magenta and cyan on deep violet. Regrets are for the morning.', 'pcm-crm' ),
			'swatch'      => array( '#ff4dd2', '#22e0ff', '#160d2b' ),
		),
	);
}

/**
 * The chosen theme, falling back to the house one.
 */
function pcm_crm_theme() {
	$pcm_theme = (string) get_option( 'pcm_crm_theme', 'pcm' );

	return isset( pcm_crm_themes()[ $pcm_theme ] ) ? $pcm_theme : 'pcm';
}

function pcm_crm_sanitize_theme( $pcm_value ) {
	$pcm_value = sanitize_key( $pcm_value );

	return isset( pcm_crm_themes()[ $pcm_value ] ) ? $pcm_value : 'pcm';
}
