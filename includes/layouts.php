<?php
/**
 * Page layouts: which fields appear on a record, in which sections, in which
 * order.
 *
 * The record form used to be a hardcoded list in the JS. Moving it here is what
 * makes it editable — the browser now renders whatever this returns, so a
 * layout change is data rather than a deploy.
 *
 * A layout stores only field *names*. The label, type, picklist values and
 * everything else still come from the model's field map, so renaming a field
 * or changing its type does not require touching every layout that uses it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_LAYOUTS_OPTION = 'pcm_crm_layouts';

/**
 * The layouts as shipped, matching the forms these screens have always had.
 */
function pcm_crm_default_layouts() {
	return array(
		'accounts' => array(
			array( 'title' => '', 'fields' => array( 'name', 'website', 'owner_id', 'type', 'industry', 'phone', 'annual_revenue', 'number_of_employees' ) ),
			array( 'title' => 'Billing address', 'fields' => array( 'billing_street', 'billing_city', 'billing_state', 'billing_postal_code', 'billing_country' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
		),
		'contacts' => array(
			array( 'title' => '', 'fields' => array( 'first_name', 'last_name', 'title', 'account_id', 'owner_id', 'lead_source', 'service_interest' ) ),
			array( 'title' => 'Contact details', 'fields' => array( 'email', 'phone', 'mobile_phone', 'do_not_contact', 'do_not_contact_reason' ) ),
			array( 'title' => 'Mailing address', 'fields' => array( 'mailing_street', 'mailing_city', 'mailing_state', 'mailing_postal_code', 'mailing_country' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
		),
		'opportunities' => array(
			array( 'title' => '', 'fields' => array( 'name', 'account_id', 'primary_contact_id', 'owner_id', 'stage_name', 'closed_lost_reason' ) ),
			array( 'title' => 'Forecast', 'fields' => array( 'amount', 'close_date', 'probability', 'type', 'lead_source', 'service_interest' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'next_step', 'description' ) ),
		),
		'activities' => array(
			array( 'title' => '', 'fields' => array( 'subject', 'owner_id', 'activity_type', 'status', 'priority', 'due_date' ) ),
			array( 'title' => 'Related records', 'fields' => array( 'who_id', 'what_id' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
		),
	);
}

/**
 * One object's own saved (or default) sections, pruned of fields the object
 * no longer has — no synthetic "Custom fields" catch-all appended.
 *
 * This is the shape the Fields & Layouts editor's own section list wants:
 * an unplaced custom field belongs in Available Fields, once, not also
 * duplicated into a section nobody actually created. pcm_crm_layout() below
 * is the one that appends the catch-all, for the live record form, which
 * has no "Available" list of its own to fall back to.
 */
function pcm_crm_layout_sections( $pcm_object ) {
	$pcm_saved   = get_option( PCM_CRM_LAYOUTS_OPTION, array() );
	$pcm_layouts = pcm_crm_default_layouts();

	$pcm_layout = ( is_array( $pcm_saved ) && ! empty( $pcm_saved[ $pcm_object ] ) )
		? $pcm_saved[ $pcm_object ]
		: ( isset( $pcm_layouts[ $pcm_object ] ) ? $pcm_layouts[ $pcm_object ] : array() );

	return pcm_crm_prune_layout( $pcm_object, $pcm_layout );
}

/**
 * One object's layout, with any custom field not yet placed appended.
 *
 * A field created after a layout was saved would otherwise be invisible on
 * an actual record — it would exist, be filterable, be exported, and have
 * nowhere to be typed into, with no "Available Fields" palette on the record
 * form the way the admin editor has one. Appending it to a "Custom fields"
 * section is the least surprising answer there, and it can be dragged
 * anywhere afterwards from the editor.
 */
function pcm_crm_layout( $pcm_object ) {
	$pcm_layout = pcm_crm_layout_sections( $pcm_object );

	return apply_filters( 'pcm_crm_layout', pcm_crm_append_unplaced( $pcm_object, $pcm_layout ), $pcm_object );
}

/**
 * Drop field names the object no longer has.
 *
 * A layout outlives the fields in it — a custom field can be removed from the
 * definitions — and rendering a control for a column that is gone would throw
 * rather than degrade.
 */
function pcm_crm_prune_layout( $pcm_object, array $pcm_layout ) {
	$pcm_model = PCM_CRM_REST::model( $pcm_object );

	if ( ! $pcm_model ) {
		return $pcm_layout;
	}

	foreach ( $pcm_layout as $pcm_i => $pcm_section ) {
		$pcm_layout[ $pcm_i ]['fields'] = array_values( array_filter(
			(array) $pcm_section['fields'],
			array( $pcm_model, 'has_field' )
		) );
	}

	return $pcm_layout;
}

function pcm_crm_append_unplaced( $pcm_object, array $pcm_layout ) {
	$pcm_placed = array();

	foreach ( $pcm_layout as $pcm_section ) {
		$pcm_placed = array_merge( $pcm_placed, (array) $pcm_section['fields'] );
	}

	$pcm_missing = array();

	foreach ( array_keys( pcm_crm_custom_field_map( $pcm_object ) ) as $pcm_column ) {
		if ( ! in_array( $pcm_column, $pcm_placed, true ) ) {
			$pcm_missing[] = $pcm_column;
		}
	}

	if ( $pcm_missing ) {
		$pcm_layout[] = array( 'title' => __( 'Custom fields', 'pcm-crm' ), 'fields' => $pcm_missing );
	}

	return $pcm_layout;
}

/**
 * Every field that could be put on a layout but is not on this one.
 *
 * The palette the layout editor drags from. Placement is read off
 * pcm_crm_layout_sections() rather than pcm_crm_layout() — the real, saved
 * sections only, never the synthetic "Custom fields" catch-all — so an
 * unplaced custom field shows up here, once, instead of also being folded
 * into a section the editor never asked for.
 */
function pcm_crm_layout_available_fields( $pcm_object ) {
	$pcm_model = PCM_CRM_REST::model( $pcm_object );

	if ( ! $pcm_model ) {
		return array();
	}

	$pcm_placed = array();

	foreach ( pcm_crm_layout_sections( $pcm_object ) as $pcm_section ) {
		$pcm_placed = array_merge( $pcm_placed, (array) $pcm_section['fields'] );
	}

	$pcm_available = array();

	foreach ( $pcm_model->fields() as $pcm_name => $pcm_def ) {
		// Audit stamps have their own panel on the record, and the internal
		// columns are not fields anyone should be typing into. 'no_layout' is
		// the narrower case: a field that is deliberately filterable (so it
		// keeps its label and stays off 'internal') but still has no business
		// being dragged onto a form by hand — is_test is the one example.
		if ( ! empty( $pcm_def['internal'] ) || ! empty( $pcm_def['readonly'] ) || ! empty( $pcm_def['no_layout'] ) || empty( $pcm_def['label'] ) ) {
			continue;
		}

		if ( in_array( $pcm_name, $pcm_placed, true ) ) {
			continue;
		}

		$pcm_available[] = $pcm_name;
	}

	return $pcm_available;
}

function pcm_crm_sanitize_layouts( $pcm_value ) {
	if ( ! is_array( $pcm_value ) ) {
		return get_option( PCM_CRM_LAYOUTS_OPTION, array() );
	}

	$pcm_objects = pcm_crm_customisable_objects();

	// Same reason as the custom fields: the screen edits one object at a time.
	$pcm_stored = get_option( PCM_CRM_LAYOUTS_OPTION, array() );
	$pcm_out    = is_array( $pcm_stored ) ? $pcm_stored : array();

	foreach ( $pcm_value as $pcm_object => $pcm_sections ) {
		if ( ! isset( $pcm_objects[ $pcm_object ] ) || ! is_array( $pcm_sections ) ) {
			continue;
		}

		$pcm_model = PCM_CRM_REST::model( $pcm_object );
		$pcm_clean = array();
		$pcm_seen  = array();

		foreach ( $pcm_sections as $pcm_section ) {
			$pcm_fields = array();

			foreach ( (array) ( isset( $pcm_section['fields'] ) ? $pcm_section['fields'] : array() ) as $pcm_field ) {
				$pcm_field = sanitize_key( $pcm_field );

				// A field placed twice would render twice and the second copy
				// would silently win on save.
				if ( isset( $pcm_seen[ $pcm_field ] ) || ! $pcm_model || ! $pcm_model->has_field( $pcm_field ) ) {
					continue;
				}

				$pcm_seen[ $pcm_field ] = true;
				$pcm_fields[]           = $pcm_field;
			}

			// An empty section is exactly how the old synthetic "Custom fields"
			// catch-all used to get permanently baked into a real, saved layout
			// the first time anyone hit Save while it happened to be showing —
			// still there, and still empty, long after the field it once held
			// had been moved or deleted. Dropping it here means a section with
			// nothing left in it disappears rather than lingering as clutter.
			if ( $pcm_fields ) {
				$pcm_clean[] = array(
					'title'  => sanitize_text_field( isset( $pcm_section['title'] ) ? $pcm_section['title'] : '' ),
					'fields' => $pcm_fields,
				);
			}
		}

		// An empty layout would leave a record with no form at all, so the
		// shipped one stands in rather than saving nothing.
		$pcm_out[ $pcm_object ] = $pcm_clean ? $pcm_clean : pcm_crm_default_layouts()[ $pcm_object ];
	}

	return $pcm_out;
}
