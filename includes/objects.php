<?php
/**
 * The object registry.
 *
 * Which slugs are real records, and which of them can be reported on, carry
 * custom fields or be exported, used to be four separate hardcoded lists in
 * four files — PCM_CRM_REST::models(), the skip-list inside
 * PCM_CRM_REST::schema(), pcm_crm_customisable_objects() and
 * pcm_crm_exportable_objects(). They answered the same question and had to be
 * kept in step by hand, which is the kind of agreement that holds right up
 * until a new object is added. They are now one registry, and each object
 * states its own facts beside its model.
 *
 * An object registers itself at the bottom of its own model file, so the
 * declaration sits next to the field map it describes. A module is the one
 * exception: its registration lives in a separate file that the module gate
 * can withhold, because the point of the gate is to keep the model while
 * withholding the surface.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register an object.
 *
 * @param string $pcm_slug Plural slug, as it appears in a REST route.
 * @param array  $pcm_args {
 *     @type callable $model        Returns the object's PCM_CRM_Model.
 *     @type string   $label        Singular, human-facing.
 *     @type string   $plural       Plural, human-facing.
 *     @type bool     $reportable   In the filter builder, reports and /schema.
 *     @type bool     $customisable Can carry admin-defined custom fields.
 *     @type bool     $exportable   Offered on the Data Export tab.
 *     @type string   $sf           Salesforce object name, for the export tab.
 *     @type string   $group_by     Default report grouping column.
 *     @type array    $group_options Columns the report screen offers to
 *                                  group by.
 *     @type string   $related      'fetch' to load related lists over REST,
 *                                  'local' to build them from the record in
 *                                  hand, '' for a record with no related tab.
 *     @type string   $module       Owning module slug, '' for core.
 * }
 */
function pcm_crm_register_object( $pcm_slug, array $pcm_args ) {
	if ( ! isset( $GLOBALS['pcm_crm_objects'] ) ) {
		$GLOBALS['pcm_crm_objects'] = array();
	}

	$GLOBALS['pcm_crm_objects'][ $pcm_slug ] = array_merge( array(
		'model'         => '',
		'label'         => '',
		'plural'        => '',
		'reportable'    => false,
		'customisable'  => false,
		'exportable'    => false,
		'sf'            => '',
		'group_by'      => '',
		'group_options' => array(),
		'related'       => '',
		'module'        => '',
	), $pcm_args );
}

/**
 * Every registered object.
 *
 * Insertion order is preserved and is load-bearing: the generic REST routes
 * build their slug alternation from these keys, so a reordering would reorder
 * the regex. Register in the order the routes should try.
 */
function pcm_crm_objects() {
	$pcm_objects = isset( $GLOBALS['pcm_crm_objects'] ) ? $GLOBALS['pcm_crm_objects'] : array();

	return apply_filters( 'pcm_crm_objects', $pcm_objects );
}

function pcm_crm_object( $pcm_slug ) {
	$pcm_objects = pcm_crm_objects();

	return isset( $pcm_objects[ $pcm_slug ] ) ? $pcm_objects[ $pcm_slug ] : null;
}

/**
 * Objects with a given flag set, as slug => object.
 */
function pcm_crm_objects_where( $pcm_flag ) {
	$pcm_out = array();

	foreach ( pcm_crm_objects() as $pcm_slug => $pcm_object ) {
		if ( ! empty( $pcm_object[ $pcm_flag ] ) ) {
			$pcm_out[ $pcm_slug ] = $pcm_object;
		}
	}

	return $pcm_out;
}

/**
 * Whether an object has a flag set.
 */
function pcm_crm_object_is( $pcm_slug, $pcm_flag ) {
	$pcm_object = pcm_crm_object( $pcm_slug );

	return $pcm_object && ! empty( $pcm_object[ $pcm_flag ] );
}

/**
 * An object's model, or null.
 *
 * The registry stores a callable rather than an instance so that registration
 * costs nothing: every model accessor merges a custom-field map, which is a
 * get_option, and building nine of those on a front-end page load that will
 * never touch the CRM would be waste.
 */
function pcm_crm_object_model( $pcm_slug ) {
	$pcm_object = pcm_crm_object( $pcm_slug );

	if ( ! $pcm_object || ! is_callable( $pcm_object['model'] ) ) {
		return null;
	}

	return call_user_func( $pcm_object['model'] );
}
