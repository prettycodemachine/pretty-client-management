<?php
/**
 * The contact form's field definitions.
 *
 * One list drives four things: the markup the shortcode renders, the rules the
 * handler validates against, the CRM fields the intake writes to, and the merge
 * tokens the reply email offers. Keeping them in one place is the point — a
 * field added to the markup but not the validator, or a token offered that no
 * field fills, are the two ways a form like this rots.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_FIELDS_OPTION = 'pcm_crm_form_fields';

/**
 * The form as shipped: exactly the fields the theme's hard-coded form had, with
 * the same input names, so an existing page keeps working and a submission
 * from a cached copy still validates.
 */
function pcm_crm_default_form_fields() {
	return array(
		array( 'key' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => 1, 'map' => 'contact.first_name', 'autocomplete' => 'given-name', 'half' => 1 ),
		array( 'key' => 'last_name', 'label' => 'Last name', 'type' => 'text', 'required' => 1, 'map' => 'contact.last_name', 'autocomplete' => 'family-name', 'half' => 1 ),
		array( 'key' => 'org', 'label' => 'Organization', 'type' => 'text', 'required' => 1, 'map' => 'account.name', 'autocomplete' => 'organization' ),
		array( 'key' => 'interest', 'label' => 'What are you interested in?', 'type' => 'select', 'required' => 1, 'map' => 'contact.service_interest', 'source' => 'interests' ),
		array( 'key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => 1, 'map' => 'contact.email', 'autocomplete' => 'email' ),
		array( 'key' => 'message', 'label' => 'What are you looking for help with?', 'type' => 'textarea', 'required' => 1, 'map' => 'activity.description' ),
	);
}

function pcm_crm_form_fields() {
	$pcm_saved = get_option( PCM_CRM_FIELDS_OPTION, array() );

	$pcm_fields = ( is_array( $pcm_saved ) && $pcm_saved ) ? $pcm_saved : pcm_crm_default_form_fields();

	return apply_filters( 'pcm_crm_form_fields', array_values( array_filter( $pcm_fields, 'pcm_crm_valid_form_field' ) ) );
}

function pcm_crm_valid_form_field( $pcm_field ) {
	return is_array( $pcm_field ) && ! empty( $pcm_field['key'] ) && ! empty( $pcm_field['label'] );
}

function pcm_crm_form_field_types() {
	return array(
		'text'     => __( 'Single line', 'pcm-crm' ),
		'email'    => __( 'Email address', 'pcm-crm' ),
		'tel'      => __( 'Phone number', 'pcm-crm' ),
		'textarea' => __( 'Paragraph', 'pcm-crm' ),
		'select'   => __( 'Dropdown', 'pcm-crm' ),
	);
}

/**
 * Where a form field can be written in the CRM.
 *
 * Deliberately a short list of the things a contact form actually collects,
 * not every column: offering a form field a home in, say, forecast_category
 * would be noise with a trap in it.
 */
function pcm_crm_form_field_targets() {
	return array(
		''                         => __( '— Not stored on a record —', 'pcm-crm' ),
		'contact.first_name'       => __( 'Contact: First name', 'pcm-crm' ),
		'contact.last_name'        => __( 'Contact: Last name', 'pcm-crm' ),
		'contact.email'            => __( 'Contact: Email', 'pcm-crm' ),
		'contact.phone'            => __( 'Contact: Phone', 'pcm-crm' ),
		'contact.title'            => __( 'Contact: Job title', 'pcm-crm' ),
		'contact.service_interest' => __( 'Contact: Interested in', 'pcm-crm' ),
		'contact.description'      => __( 'Contact: Notes', 'pcm-crm' ),
		'account.name'             => __( 'Account: Organization name', 'pcm-crm' ),
		'account.website'          => __( 'Account: Website', 'pcm-crm' ),
		'account.phone'            => __( 'Account: Phone', 'pcm-crm' ),
		'activity.description'     => __( 'Activity: Message body', 'pcm-crm' ),
	);
}

/**
 * The posted input name for a field.
 *
 * Prefixed because these land in $_POST beside WordPress's own parameters, and
 * a field called "action" would be a genuinely confusing afternoon.
 */
function pcm_crm_field_input_name( array $pcm_field ) {
	return 'pcm_' . $pcm_field['key'];
}

/**
 * A field's merge token, derived from its key.
 *
 * Derived rather than stored so a token can never name a field that does not
 * exist — the two cannot drift if only one of them is written down.
 */
function pcm_crm_field_token( array $pcm_field ) {
	return '{{' . strtoupper( str_replace( '_', ' ', $pcm_field['key'] ) ) . '}}';
}

/**
 * Every token the reply email can use.
 *
 * The composed ones come last because they are not fields: FULL NAME is two
 * fields joined, and offering it is more useful than making everyone write
 * "{{FIRST NAME}} {{LAST NAME}}".
 */
function pcm_crm_tokens() {
	$pcm_tokens = array();

	foreach ( pcm_crm_form_fields() as $pcm_field ) {
		$pcm_tokens[] = pcm_crm_field_token( $pcm_field );
	}

	$pcm_first = pcm_crm_field_with_map( 'contact.first_name' );
	$pcm_last  = pcm_crm_field_with_map( 'contact.last_name' );

	if ( $pcm_first && $pcm_last ) {
		$pcm_tokens[] = '{{FULL NAME}}';
	}

	return array_values( array_unique( $pcm_tokens ) );
}

/**
 * The field mapped to a given CRM target, or null.
 */
function pcm_crm_field_with_map( $pcm_target ) {
	foreach ( pcm_crm_form_fields() as $pcm_field ) {
		if ( isset( $pcm_field['map'] ) && $pcm_field['map'] === $pcm_target ) {
			return $pcm_field;
		}
	}

	return null;
}

/**
 * A dropdown's choices.
 *
 * A field can either carry its own list or draw one from the site — the
 * interest list belongs to the theme's offerings, and duplicating it into the
 * form builder would let the two disagree about what is on sale.
 */
function pcm_crm_field_options( array $pcm_field ) {
	if ( ! empty( $pcm_field['source'] ) && 'interests' === $pcm_field['source'] ) {
		return array_values( pcm_crm_interest_options() );
	}

	if ( empty( $pcm_field['options'] ) ) {
		return array();
	}

	if ( is_array( $pcm_field['options'] ) ) {
		return array_values( array_filter( array_map( 'trim', $pcm_field['options'] ) ) );
	}

	return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $pcm_field['options'] ) ) ) );
}

/**
 * Clean a posted field list from the builder.
 */
function pcm_crm_sanitize_form_fields( $pcm_value ) {
	if ( ! is_array( $pcm_value ) ) {
		return pcm_crm_default_form_fields();
	}

	$pcm_types   = pcm_crm_form_field_types();
	$pcm_targets = pcm_crm_form_field_targets();
	$pcm_out     = array();
	$pcm_seen    = array();

	foreach ( $pcm_value as $pcm_field ) {
		if ( ! is_array( $pcm_field ) || '' === trim( (string) ( isset( $pcm_field['label'] ) ? $pcm_field['label'] : '' ) ) ) {
			continue;
		}

		$pcm_label = sanitize_text_field( $pcm_field['label'] );
		$pcm_key   = ! empty( $pcm_field['key'] ) ? sanitize_key( $pcm_field['key'] ) : sanitize_key( str_replace( '-', '_', sanitize_title( $pcm_label ) ) );

		if ( '' === $pcm_key ) {
			continue;
		}

		// Two fields with one key would post into the same input name and the
		// second would silently win.
		if ( isset( $pcm_seen[ $pcm_key ] ) ) {
			$pcm_key .= '_' . ( count( $pcm_out ) + 1 );
		}
		$pcm_seen[ $pcm_key ] = true;

		$pcm_type = isset( $pcm_field['type'] ) && isset( $pcm_types[ $pcm_field['type'] ] ) ? $pcm_field['type'] : 'text';
		$pcm_map  = isset( $pcm_field['map'] ) && isset( $pcm_targets[ $pcm_field['map'] ] ) ? $pcm_field['map'] : '';

		$pcm_clean = array(
			'key'      => $pcm_key,
			'label'    => $pcm_label,
			'type'     => $pcm_type,
			'required' => empty( $pcm_field['required'] ) ? 0 : 1,
			'map'      => $pcm_map,
			'half'     => empty( $pcm_field['half'] ) ? 0 : 1,
		);

		if ( ! empty( $pcm_field['autocomplete'] ) ) {
			$pcm_clean['autocomplete'] = sanitize_text_field( $pcm_field['autocomplete'] );
		}

		if ( 'select' === $pcm_type ) {
			if ( ! empty( $pcm_field['source'] ) && 'interests' === $pcm_field['source'] ) {
				$pcm_clean['source'] = 'interests';
			} else {
				$pcm_clean['options'] = array_values( array_filter( array_map(
					'sanitize_text_field',
					array_map( 'trim', explode( "\n", (string) ( isset( $pcm_field['options'] ) ? $pcm_field['options'] : '' ) ) )
				) ) );
			}
		}

		$pcm_out[] = $pcm_clean;
	}

	// An empty form would leave the page with a submit button and nothing else,
	// so the shipped set stands in rather than saving nothing.
	return $pcm_out ? $pcm_out : pcm_crm_default_form_fields();
}

function pcm_crm_form_button_label() {
	$pcm_label = trim( (string) get_option( 'pcm_crm_form_button', '' ) );

	return '' !== $pcm_label ? $pcm_label : __( 'Send message', 'pcm-crm' );
}
