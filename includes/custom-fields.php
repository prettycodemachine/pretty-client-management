<?php
/**
 * Admin-defined fields.
 *
 * A custom field becomes a real column on its object's table, not a row in a
 * meta table. That decision carries the whole feature: everything downstream —
 * the filter builder, sorting, grouping, the CSV export, the report builder —
 * already works off each model's field map, so a custom field arriving there
 * is indistinguishable from a built-in one and needs no special case anywhere.
 * A meta table would have meant re-implementing every one of those against a
 * second storage shape, and doing it more slowly.
 *
 * The cost is an ALTER TABLE when a field is created, which is why creation is
 * the only moment that touches the schema and why columns are never dropped.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_CUSTOM_FIELDS_OPTION = 'pcm_crm_custom_fields';

/** Custom columns are prefixed so they can never collide with a built-in. */
const PCM_CRM_CUSTOM_PREFIX = 'cf_';

/**
 * The objects that can carry custom fields.
 *
 * Read from the object registry rather than listed here, so an object declares
 * the fact beside its own field map. The labels are the registry's plain
 * strings, matching the convention the field map itself follows — a label is
 * data, not a translated call site.
 */
function pcm_crm_customisable_objects() {
	$pcm_out = array();

	foreach ( pcm_crm_objects_where( 'customisable' ) as $pcm_slug => $pcm_object ) {
		$pcm_out[ $pcm_slug ] = $pcm_object['plural'];
	}

	return $pcm_out;
}

/**
 * The field types an admin can create, and how each is stored.
 *
 * `column` is the MySQL type, `model` the field map's type — the one the
 * sanitiser, the filter operators and the CSV export all read.
 */
function pcm_crm_custom_field_types() {
	return array(
		'text'         => array( 'label' => __( 'Text', 'pcm-crm' ), 'column' => 'varchar(255) NOT NULL DEFAULT \'\'', 'model' => 'text' ),
		'number'       => array( 'label' => __( 'Number', 'pcm-crm' ), 'column' => 'decimal(18,4) DEFAULT NULL', 'model' => 'decimal' ),
		'currency'     => array( 'label' => __( 'Currency', 'pcm-crm' ), 'column' => 'decimal(18,2) DEFAULT NULL', 'model' => 'decimal' ),
		'date'         => array( 'label' => __( 'Date', 'pcm-crm' ), 'column' => 'date DEFAULT NULL', 'model' => 'date' ),
		'checkbox'     => array( 'label' => __( 'Checkbox', 'pcm-crm' ), 'column' => 'tinyint(1) NOT NULL DEFAULT 0', 'model' => 'bool' ),
		'picklist'     => array( 'label' => __( 'Picklist', 'pcm-crm' ), 'column' => 'varchar(160) NOT NULL DEFAULT \'\'', 'model' => 'text' ),
		'url'          => array( 'label' => __( 'URL', 'pcm-crm' ), 'column' => 'varchar(255) NOT NULL DEFAULT \'\'', 'model' => 'url' ),
		'textarea'     => array( 'label' => __( 'Long text', 'pcm-crm' ), 'column' => 'longtext', 'model' => 'longtext' ),
		'relationship' => array( 'label' => __( 'Relationship', 'pcm-crm' ), 'column' => 'bigint(20) unsigned NOT NULL DEFAULT 0', 'model' => 'id' ),
	);
}

/**
 * Every custom field, keyed by object.
 */
function pcm_crm_all_custom_fields() {
	$pcm_saved = get_option( PCM_CRM_CUSTOM_FIELDS_OPTION, array() );

	return is_array( $pcm_saved ) ? $pcm_saved : array();
}

function pcm_crm_custom_fields( $pcm_object ) {
	$pcm_all = pcm_crm_all_custom_fields();

	return isset( $pcm_all[ $pcm_object ] ) && is_array( $pcm_all[ $pcm_object ] ) ? $pcm_all[ $pcm_object ] : array();
}

function pcm_crm_custom_column( $pcm_key ) {
	return PCM_CRM_CUSTOM_PREFIX . $pcm_key;
}

function pcm_crm_is_custom_column( $pcm_column ) {
	return 0 === strpos( (string) $pcm_column, PCM_CRM_CUSTOM_PREFIX );
}

/**
 * A custom field's Salesforce API name.
 *
 * Built from the label rather than the key so it reads the way someone would
 * have named the field in Salesforce themselves, and suffixed __c because that
 * is what Salesforce calls a field nobody shipped.
 */
function pcm_crm_custom_api_name( array $pcm_field ) {
	if ( ! empty( $pcm_field['api_name'] ) ) {
		return $pcm_field['api_name'];
	}

	$pcm_name = preg_replace( '/[^A-Za-z0-9]+/', '_', $pcm_field['label'] );
	$pcm_name = trim( $pcm_name, '_' );

	// A Salesforce API name cannot start with a digit.
	if ( '' === $pcm_name || is_numeric( $pcm_name[0] ) ) {
		$pcm_name = 'PCM_' . $pcm_name;
	}

	return $pcm_name . '__c';
}

/**
 * Custom fields as field-map entries, ready to merge into a model.
 */
function pcm_crm_custom_field_map( $pcm_object ) {
	$pcm_types = pcm_crm_custom_field_types();
	$pcm_map   = array();

	foreach ( pcm_crm_custom_fields( $pcm_object ) as $pcm_field ) {
		if ( empty( $pcm_field['key'] ) || ! isset( $pcm_types[ $pcm_field['type'] ] ) ) {
			continue;
		}

		$pcm_entry = array(
			'type'   => $pcm_types[ $pcm_field['type'] ]['model'],
			'label'  => $pcm_field['label'],
			'sf'     => pcm_crm_custom_api_name( $pcm_field ),
			'custom' => true,
			'ui'     => $pcm_field['type'],
		);

		if ( 'picklist' === $pcm_field['type'] && ! empty( $pcm_field['options'] ) ) {
			$pcm_entry['options'] = array_values( (array) $pcm_field['options'] );
		}

		if ( 'relationship' === $pcm_field['type'] && ! empty( $pcm_field['related'] ) ) {
			$pcm_entry['related'] = $pcm_field['related'];
		}

		if ( ! empty( $pcm_field['help'] ) ) {
			$pcm_entry['help'] = $pcm_field['help'];
		}

		$pcm_map[ pcm_crm_custom_column( $pcm_field['key'] ) ] = $pcm_entry;
	}

	return $pcm_map;
}

/* ---------------------------------------------------------------------------
   Schema
   --------------------------------------------------------------------------- */

/**
 * Add the column for a field that does not have one yet.
 *
 * Checked before altering rather than relying on IF NOT EXISTS, which MariaDB
 * supports and MySQL does not.
 */
function pcm_crm_ensure_custom_column( $pcm_object, array $pcm_field ) {
	global $wpdb;

	$pcm_types = pcm_crm_custom_field_types();

	if ( empty( $pcm_field['key'] ) || ! isset( $pcm_types[ $pcm_field['type'] ] ) ) {
		return false;
	}

	$pcm_table = pcm_crm_object_table( $pcm_object );

	if ( ! $pcm_table ) {
		return false;
	}

	$pcm_column = pcm_crm_custom_column( $pcm_field['key'] );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	$pcm_exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$pcm_table} LIKE %s", $pcm_column ) );

	if ( $pcm_exists ) {
		return false;
	}

	$pcm_definition = $pcm_types[ $pcm_field['type'] ]['column'];

	// phpcs:ignore WordPress.DB.PreparedSQL -- column name and type are both from a whitelist
	$wpdb->query( "ALTER TABLE {$pcm_table} ADD COLUMN {$pcm_column} {$pcm_definition}" );

	// Relationship and picklist columns get an index: they are the two that
	// get filtered on, and a table scan per filter is the thing custom fields
	// most easily introduce.
	if ( in_array( $pcm_field['type'], array( 'relationship', 'picklist', 'date' ), true ) ) {
		// phpcs:ignore WordPress.DB.PreparedSQL -- column name is from a whitelist
		$wpdb->query( "ALTER TABLE {$pcm_table} ADD INDEX {$pcm_column}_idx ({$pcm_column})" );
	}

	return true;
}

/**
 * An object's table, read from its own registered model rather than a second
 * hand-kept list — the object registry (includes/objects.php) already ended
 * four of those, and a table name is exactly the kind of fact a model already
 * knows about itself.
 */
function pcm_crm_object_table( $pcm_object ) {
	$pcm_def = pcm_crm_object( $pcm_object );

	if ( ! $pcm_def || empty( $pcm_def['model'] ) || ! is_callable( $pcm_def['model'] ) ) {
		return '';
	}

	$pcm_model = call_user_func( $pcm_def['model'] );

	return $pcm_model instanceof PCM_CRM_Model ? $pcm_model->table() : '';
}

/**
 * Make sure every defined field has its column.
 *
 * Runs on the settings save and on a schema upgrade, so a definition restored
 * from a backup, or copied between environments, brings its column with it.
 */
function pcm_crm_sync_custom_columns() {
	foreach ( pcm_crm_all_custom_fields() as $pcm_object => $pcm_fields ) {
		foreach ( (array) $pcm_fields as $pcm_field ) {
			pcm_crm_ensure_custom_column( $pcm_object, (array) $pcm_field );
		}
	}
}

/* ---------------------------------------------------------------------------
   Saving definitions
   --------------------------------------------------------------------------- */

/**
 * Clean one posted field definition — the part pcm_crm_sanitize_custom_fields()
 * and pcm_crm_handle_save_custom_field() both need, factored out so a save
 * from either path cleans a field exactly the same way.
 *
 * $pcm_existing_key, when given, wins over anything in $pcm_field['key'] — the
 * caller already knows which stored field this is (the Add/Edit dialog posts
 * it as a separate hidden field precisely so this function does not have to
 * guess), and a field's key is fixed once created regardless of what a
 * request claims it is.
 *
 * Returns false for a definition with no label — the caller decides what
 * "nothing to save" means in its own context (skipped in a bulk list, an
 * error message from a single-field form).
 */
function pcm_crm_clean_custom_field( array $pcm_field, $pcm_existing_key = '' ) {
	$pcm_types   = pcm_crm_custom_field_types();
	$pcm_objects = pcm_crm_customisable_objects();

	$pcm_label = sanitize_text_field( isset( $pcm_field['label'] ) ? $pcm_field['label'] : '' );

	if ( '' === trim( $pcm_label ) ) {
		return false;
	}

	$pcm_key_source = $pcm_existing_key ? $pcm_existing_key : ( isset( $pcm_field['key'] ) ? $pcm_field['key'] : '' );
	$pcm_key        = $pcm_key_source
		? sanitize_key( $pcm_key_source )
		: sanitize_key( str_replace( '-', '_', sanitize_title( $pcm_label ) ) );

	if ( '' === $pcm_key ) {
		return false;
	}

	$pcm_type = isset( $pcm_field['type'] ) && isset( $pcm_types[ $pcm_field['type'] ] ) ? $pcm_field['type'] : 'text';

	$pcm_clean = array(
		'key'      => $pcm_key,
		'label'    => $pcm_label,
		'type'     => $pcm_type,
		'api_name' => ! empty( $pcm_field['api_name'] ) ? sanitize_text_field( $pcm_field['api_name'] ) : '',
		'help'     => ! empty( $pcm_field['help'] ) ? sanitize_text_field( $pcm_field['help'] ) : '',
	);

	if ( 'picklist' === $pcm_type ) {
		$pcm_raw = isset( $pcm_field['options'] ) ? $pcm_field['options'] : '';
		// A textarea always posts a plain string; anything else reaching here
		// is malformed input, not a set of chosen values, and (string)-casting
		// an array here is what used to store the literal word "Array" as a
		// picklist's one and only choice.
		$pcm_lines = is_array( $pcm_raw ) ? array() : explode( "\n", (string) $pcm_raw );

		$pcm_clean['options'] = array_values( array_filter( array_map(
			'sanitize_text_field',
			array_map( 'trim', $pcm_lines )
		) ) );
	}

	if ( 'relationship' === $pcm_type ) {
		$pcm_related          = isset( $pcm_field['related'] ) ? sanitize_key( $pcm_field['related'] ) : '';
		$pcm_clean['related'] = isset( $pcm_objects[ $pcm_related ] ) ? $pcm_related : 'accounts';
	}

	return $pcm_clean;
}

/**
 * Clean a posted set of custom field definitions.
 *
 * A field's key is fixed once created: it is the column name, and letting it
 * change would either orphan the data or need a rename nobody asked for. The
 * label is free to change, since nothing is stored under it.
 */
function pcm_crm_sanitize_custom_fields( $pcm_value ) {
	if ( ! is_array( $pcm_value ) ) {
		return pcm_crm_all_custom_fields();
	}

	$pcm_objects = pcm_crm_customisable_objects();

	// Started from what is already stored, because the screen edits one object
	// at a time: a form that posts only Contacts must not be read as an
	// instruction to delete every field on Accounts.
	$pcm_out = pcm_crm_all_custom_fields();

	foreach ( $pcm_value as $pcm_object => $pcm_fields ) {
		if ( ! isset( $pcm_objects[ $pcm_object ] ) || ! is_array( $pcm_fields ) ) {
			continue;
		}

		$pcm_seen          = array();
		$pcm_object_fields = array();

		foreach ( $pcm_fields as $pcm_field ) {
			if ( ! is_array( $pcm_field ) ) {
				continue;
			}

			$pcm_clean = pcm_crm_clean_custom_field( $pcm_field );

			if ( ! $pcm_clean || isset( $pcm_seen[ $pcm_clean['key'] ] ) ) {
				continue;
			}

			$pcm_seen[ $pcm_clean['key'] ] = true;
			$pcm_object_fields[]           = $pcm_clean;
		}

		$pcm_out[ $pcm_object ] = $pcm_object_fields;
	}

	return $pcm_out;
}

/**
 * Create or update exactly one field, independent of the bulk sanitiser
 * above — the Fields & Layouts tab's Add/Edit dialog posts here so that
 * adding one field is its own atomic round trip rather than a row folded
 * into a much larger multi-field, multi-section form submission.
 *
 * An existing field's type and (for a relationship) target are never taken
 * from the request, even if the dialog somehow posted different ones —
 * both are fixed at creation the same way the key is, since the column's
 * SQL type was already chosen from the original.
 */
function pcm_crm_handle_save_custom_field() {
	if ( ! pcm_crm_can( 'settings', 'edit' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	check_admin_referer( 'pcm_crm_save_custom_field' );

	$pcm_object  = isset( $_POST['object'] ) ? sanitize_key( wp_unslash( $_POST['object'] ) ) : '';
	$pcm_objects = pcm_crm_customisable_objects();
	$pcm_back    = add_query_arg( 'object', $pcm_object, pcm_crm_setup_url( 'fields' ) );

	if ( ! isset( $pcm_objects[ $pcm_object ] ) ) {
		wp_die( esc_html__( 'That is not an object custom fields can be added to.', 'pcm-crm' ), 400 );
	}

	$pcm_existing_key  = isset( $_POST['existing_key'] ) ? sanitize_key( wp_unslash( $_POST['existing_key'] ) ) : '';
	$pcm_all           = pcm_crm_all_custom_fields();
	$pcm_object_fields = isset( $pcm_all[ $pcm_object ] ) ? $pcm_all[ $pcm_object ] : array();

	$pcm_current_index = null;
	$pcm_current       = array();

	if ( $pcm_existing_key ) {
		foreach ( $pcm_object_fields as $pcm_index => $pcm_field ) {
			if ( $pcm_field['key'] === $pcm_existing_key ) {
				$pcm_current_index = $pcm_index;
				$pcm_current       = $pcm_field;
				break;
			}
		}
	}

	$pcm_raw = array(
		'label'   => isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '',
		// Fixed once created — see the docblock above.
		'type'    => $pcm_current ? $pcm_current['type'] : ( isset( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : 'text' ),
		'options' => isset( $_POST['options'] ) ? wp_unslash( $_POST['options'] ) : '',
		'related' => $pcm_current && isset( $pcm_current['related'] ) ? $pcm_current['related'] : ( isset( $_POST['related'] ) ? wp_unslash( $_POST['related'] ) : '' ),
	);

	$pcm_clean = pcm_crm_clean_custom_field( $pcm_raw, $pcm_existing_key );

	if ( ! $pcm_clean ) {
		wp_safe_redirect( add_query_arg( 'pcm_crm_field', 'label-required', $pcm_back ) );
		exit;
	}

	if ( null !== $pcm_current_index ) {
		$pcm_object_fields[ $pcm_current_index ] = $pcm_clean;
	} else {
		// A brand-new field landing on a key an existing one already owns —
		// two different labels can sanitize_title() to the same slug — would
		// silently overwrite that field's definition otherwise.
		foreach ( $pcm_object_fields as $pcm_field ) {
			if ( $pcm_field['key'] === $pcm_clean['key'] ) {
				wp_safe_redirect( add_query_arg( 'pcm_crm_field', 'key-taken', $pcm_back ) );
				exit;
			}
		}

		$pcm_object_fields[] = $pcm_clean;
	}

	$pcm_all[ $pcm_object ] = $pcm_object_fields;
	update_option( PCM_CRM_CUSTOM_FIELDS_OPTION, $pcm_all );
	pcm_crm_sync_custom_columns();

	wp_safe_redirect( add_query_arg( 'pcm_crm_field', 'saved', $pcm_back ) );
	exit;
}
add_action( 'admin_post_pcm_crm_save_custom_field', 'pcm_crm_handle_save_custom_field' );

/**
 * Delete one field's definition. The column and its data are never touched —
 * see this file's own docblock for why columns are never dropped.
 */
function pcm_crm_handle_delete_custom_field() {
	if ( ! pcm_crm_can( 'settings', 'edit' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'pcm-crm' ), 403 );
	}

	check_admin_referer( 'pcm_crm_delete_custom_field' );

	$pcm_object = isset( $_POST['object'] ) ? sanitize_key( wp_unslash( $_POST['object'] ) ) : '';
	$pcm_key    = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
	$pcm_back   = add_query_arg( 'object', $pcm_object, pcm_crm_setup_url( 'fields' ) );

	$pcm_all = pcm_crm_all_custom_fields();

	if ( isset( $pcm_all[ $pcm_object ] ) ) {
		$pcm_all[ $pcm_object ] = array_values( array_filter( $pcm_all[ $pcm_object ], function ( $pcm_field ) use ( $pcm_key ) {
			return $pcm_field['key'] !== $pcm_key;
		} ) );

		update_option( PCM_CRM_CUSTOM_FIELDS_OPTION, $pcm_all );
	}

	wp_safe_redirect( add_query_arg( 'pcm_crm_field', 'deleted', $pcm_back ) );
	exit;
}
add_action( 'admin_post_pcm_crm_delete_custom_field', 'pcm_crm_handle_delete_custom_field' );

/**
 * Add the columns as soon as the definitions are saved.
 *
 * On the option write rather than on the next page load, so a field is usable
 * the moment its definition exists — a field that appears in the layout but
 * has nowhere to store a value is a bug report waiting to happen.
 */
function pcm_crm_custom_fields_saved( $pcm_old, $pcm_new ) {
	pcm_crm_sync_custom_columns();
}
add_action( 'update_option_' . PCM_CRM_CUSTOM_FIELDS_OPTION, 'pcm_crm_custom_fields_saved', 10, 2 );
add_action( 'add_option_' . PCM_CRM_CUSTOM_FIELDS_OPTION, 'pcm_crm_sync_custom_columns' );
