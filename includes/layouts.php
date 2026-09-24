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
 * Layout overrides for an object that carries more than one — a Project's
 * layout differs by project_type, the way a CRM object's does not. Kept in
 * its own option rather than folded into PCM_CRM_LAYOUTS_OPTION so an object
 * with no variants (every CRM object, most of Projects') keeps exactly the
 * flat shape it always has, and the objects that do vary are additive on top
 * rather than a second shape the same option has to be sniffed for.
 *
 * Shape: object slug => variant key => sections, the same section shape
 * PCM_CRM_LAYOUTS_OPTION uses.
 */
const PCM_CRM_LAYOUT_VARIANTS_OPTION = 'pcm_crm_layout_variants';

/**
 * The objects whose field arrangement can be edited on Fields & Layouts.
 *
 * Read from the object registry, the same way pcm_crm_customisable_objects()
 * is — an object states the fact beside its own field map rather than being
 * listed here by hand.
 */
function pcm_crm_layoutable_objects() {
	$pcm_out = array();

	foreach ( pcm_crm_objects_where( 'layoutable' ) as $pcm_slug => $pcm_object ) {
		$pcm_out[ $pcm_slug ] = $pcm_object['plural'];
	}

	return $pcm_out;
}

/**
 * The variant keys valid for one object's layout — 'custom-development',
 * 'support-retainer' and so on for 'projects', by way of the Project module
 * hooking this filter. Empty for every object without a layout_variant field,
 * and core stays unaware of what a project type even is: the module that
 * knows supplies the keys, the same way it supplies the default layout itself
 * through the pcm_crm_layout filter below.
 */
function pcm_crm_layout_variant_keys( $pcm_object ) {
	return apply_filters( 'pcm_crm_layout_variant_keys', array(), $pcm_object );
}

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
			array( 'title' => '', 'fields' => array( 'first_name', 'last_name', 'title', 'account_id', 'owner_id', 'lead_source' ) ),
			array( 'title' => 'Contact details', 'fields' => array( 'email', 'phone', 'mobile_phone', 'do_not_contact', 'do_not_contact_reason' ) ),
			array( 'title' => 'Mailing address', 'fields' => array( 'mailing_street', 'mailing_city', 'mailing_state', 'mailing_postal_code', 'mailing_country' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
		),
		'opportunities' => array(
			array( 'title' => '', 'fields' => array( 'name', 'account_id', 'primary_contact_id', 'owner_id', 'stage_name', 'closed_lost_reason' ) ),
			array( 'title' => 'Forecast', 'fields' => array( 'amount', 'close_date', 'probability', 'type', 'lead_source' ) ),
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
 * What an object's layout defaults to when nothing at all is saved for it —
 * core's own list for the four CRM objects, or, for an object core does not
 * describe, whatever a module supplies through the pcm_crm_layout filter
 * (pcm_crm_pm_layout() in pm-rest.php, for the Project module's objects).
 *
 * This is what makes those filter-supplied defaults visible on the Fields &
 * Layouts editor itself, not only on the live record form — before this
 * existed, pcm_crm_layout_sections() read pcm_crm_default_layouts() directly
 * and never ran the filter, so the editor showed an object like Project as
 * entirely empty even though records of it rendered a real form.
 */
function pcm_crm_layout_default( $pcm_object, $pcm_variant = '' ) {
	$pcm_layouts = pcm_crm_default_layouts();

	return apply_filters( 'pcm_crm_layout',
		isset( $pcm_layouts[ $pcm_object ] ) ? $pcm_layouts[ $pcm_object ] : array(),
		$pcm_object, $pcm_variant );
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
 *
 * A non-empty $pcm_variant is resolved first, and only for an object with a
 * saved override under that exact key — an object with no variants, or a
 * variant nobody has customised yet, falls through to the object's one
 * shared layout below it, the same as an object with no variants at all.
 */
function pcm_crm_layout_sections( $pcm_object, $pcm_variant = '' ) {
	if ( '' !== $pcm_variant ) {
		$pcm_variants = get_option( PCM_CRM_LAYOUT_VARIANTS_OPTION, array() );

		if ( is_array( $pcm_variants ) && ! empty( $pcm_variants[ $pcm_object ][ $pcm_variant ] ) ) {
			return pcm_crm_prune_layout( $pcm_object, $pcm_variants[ $pcm_object ][ $pcm_variant ] );
		}
	}

	$pcm_saved  = get_option( PCM_CRM_LAYOUTS_OPTION, array() );
	$pcm_layout = ( is_array( $pcm_saved ) && ! empty( $pcm_saved[ $pcm_object ] ) )
		? $pcm_saved[ $pcm_object ]
		: pcm_crm_layout_default( $pcm_object, $pcm_variant );

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
 *
 * No filter runs here directly — pcm_crm_layout_sections() above already ran
 * it, and only when nothing was saved for this object (or this variant of
 * it). Filtering again here would mean a module's default-supplying callback
 * has to remember to leave a saved arrangement alone; resolving "is anything
 * saved" once, in one place, before the filter ever runs is what lets that
 * callback (pcm_crm_pm_layout()) just answer for the objects it knows about.
 */
function pcm_crm_layout( $pcm_object, $pcm_variant = '' ) {
	return pcm_crm_append_unplaced( $pcm_object, pcm_crm_layout_sections( $pcm_object, $pcm_variant ) );
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
function pcm_crm_layout_available_fields( $pcm_object, $pcm_variant = '' ) {
	$pcm_model = PCM_CRM_REST::model( $pcm_object );

	if ( ! $pcm_model ) {
		return array();
	}

	$pcm_placed = array();

	foreach ( pcm_crm_layout_sections( $pcm_object, $pcm_variant ) as $pcm_section ) {
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

/**
 * One object's posted sections, cleaned: unknown fields dropped, a field
 * placed twice kept only once, and a section left with nothing in it
 * dropped entirely — shared between the base layout sanitiser and the
 * per-variant one below, since both post the same shape for one object.
 */
function pcm_crm_clean_layout_sections( $pcm_object, array $pcm_sections ) {
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

	return $pcm_clean;
}

function pcm_crm_sanitize_layouts( $pcm_value ) {
	if ( ! is_array( $pcm_value ) ) {
		return get_option( PCM_CRM_LAYOUTS_OPTION, array() );
	}

	$pcm_objects = pcm_crm_layoutable_objects();

	// Same reason as the custom fields: the screen edits one object at a time.
	$pcm_stored = get_option( PCM_CRM_LAYOUTS_OPTION, array() );
	$pcm_out    = is_array( $pcm_stored ) ? $pcm_stored : array();

	foreach ( $pcm_value as $pcm_object => $pcm_sections ) {
		if ( ! isset( $pcm_objects[ $pcm_object ] ) || ! is_array( $pcm_sections ) ) {
			continue;
		}

		$pcm_clean = pcm_crm_clean_layout_sections( $pcm_object, $pcm_sections );

		// An empty layout would leave a record with no form at all, so the
		// effective default stands in rather than saving nothing — core's own
		// list for a CRM object, or a module's filter-supplied one (Project,
		// and the rest of the PM objects, have no entry in
		// pcm_crm_default_layouts() at all; pcm_crm_layout_default() is what
		// reaches their real default rather than an empty array).
		$pcm_out[ $pcm_object ] = $pcm_clean ? $pcm_clean : pcm_crm_layout_default( $pcm_object );
	}

	return $pcm_out;
}

/**
 * Per-variant overrides — a Project Type's own layout, saved separately from
 * the object's base layout above. Same shape and the same guards, plus a
 * variant key checked against pcm_crm_layout_variant_keys() rather than a
 * flat object allow-list.
 */
function pcm_crm_sanitize_layout_variants( $pcm_value ) {
	if ( ! is_array( $pcm_value ) ) {
		return get_option( PCM_CRM_LAYOUT_VARIANTS_OPTION, array() );
	}

	$pcm_objects = pcm_crm_layoutable_objects();
	$pcm_stored  = get_option( PCM_CRM_LAYOUT_VARIANTS_OPTION, array() );
	$pcm_out     = is_array( $pcm_stored ) ? $pcm_stored : array();

	foreach ( $pcm_value as $pcm_object => $pcm_variants ) {
		if ( ! isset( $pcm_objects[ $pcm_object ] ) || ! is_array( $pcm_variants ) ) {
			continue;
		}

		$pcm_valid_keys = pcm_crm_layout_variant_keys( $pcm_object );

		foreach ( $pcm_variants as $pcm_variant => $pcm_sections ) {
			if ( ! isset( $pcm_valid_keys[ $pcm_variant ] ) || ! is_array( $pcm_sections ) ) {
				continue;
			}

			$pcm_clean = pcm_crm_clean_layout_sections( $pcm_object, $pcm_sections );

			if ( $pcm_clean ) {
				$pcm_out[ $pcm_object ][ $pcm_variant ] = $pcm_clean;
			} else {
				// Nothing left placed reads as "back to the default" — the
				// dedicated delete action below is the primary way to revert
				// a type, but a save that empties every section should not
				// leave a dangling, unreachable empty override behind either.
				unset( $pcm_out[ $pcm_object ][ $pcm_variant ] );
			}
		}
	}

	return $pcm_out;
}

/**
 * Revert one Project Type (or any other object's variant) to its object's
 * default layout, by removing its saved override outright.
 *
 * Its own round trip rather than inferred from an empty save: a layout form
 * with every field dragged out of every section posts no
 * pcm_crm_layout_variants[...] inputs at all, which the sanitiser above
 * reads as "this form did not touch the option," not as "clear it" — the
 * same reason custom-field deletion has never been inferred from a save
 * either.
 */
function pcm_crm_handle_delete_layout_variant() {
	if ( ! pcm_crm_can( 'settings', 'edit' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	check_admin_referer( 'pcm_crm_delete_layout_variant' );

	$pcm_object  = isset( $_POST['object'] ) ? sanitize_key( wp_unslash( $_POST['object'] ) ) : '';
	$pcm_variant = isset( $_POST['variant'] ) ? sanitize_key( wp_unslash( $_POST['variant'] ) ) : '';

	if ( isset( pcm_crm_layoutable_objects()[ $pcm_object ] ) && isset( pcm_crm_layout_variant_keys( $pcm_object )[ $pcm_variant ] ) ) {
		$pcm_variants = get_option( PCM_CRM_LAYOUT_VARIANTS_OPTION, array() );

		if ( is_array( $pcm_variants ) ) {
			unset( $pcm_variants[ $pcm_object ][ $pcm_variant ] );
			update_option( PCM_CRM_LAYOUT_VARIANTS_OPTION, $pcm_variants );
		}
	}

	wp_safe_redirect( add_query_arg(
		array( 'module' => 'pm', 'object' => $pcm_object, 'variant' => $pcm_variant ),
		pcm_crm_setup_url( 'fields' )
	) );
	exit;
}
add_action( 'admin_post_pcm_crm_delete_layout_variant', 'pcm_crm_handle_delete_layout_variant' );
