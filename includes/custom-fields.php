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
 */
function pcm_crm_customisable_objects() {
	return array(
		'accounts'      => __( 'Accounts', 'pcm-crm' ),
		'contacts'      => __( 'Contacts', 'pcm-crm' ),
		'opportunities' => __( 'Opportunities', 'pcm-crm' ),
		'activities'    => __( 'Activities', 'pcm-crm' ),
	);
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

function pcm_crm_object_table( $pcm_object ) {
	$pcm_tables = array(
		'accounts'      => PCM_CRM_Schema::accounts(),
		'contacts'      => PCM_CRM_Schema::contacts(),
		'opportunities' => PCM_CRM_Schema::opportunities(),
		'activities'    => PCM_CRM_Schema::activities(),
	);

	return isset( $pcm_tables[ $pcm_object ] ) ? $pcm_tables[ $pcm_object ] : '';
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

	$pcm_types   = pcm_crm_custom_field_types();
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
			if ( ! is_array( $pcm_field ) || '' === trim( (string) ( isset( $pcm_field['label'] ) ? $pcm_field['label'] : '' ) ) ) {
				continue;
			}

			$pcm_label = sanitize_text_field( $pcm_field['label'] );
			$pcm_key   = ! empty( $pcm_field['key'] )
				? sanitize_key( $pcm_field['key'] )
				: sanitize_key( str_replace( '-', '_', sanitize_title( $pcm_label ) ) );

			if ( '' === $pcm_key || isset( $pcm_seen[ $pcm_key ] ) ) {
				continue;
			}

			$pcm_seen[ $pcm_key ] = true;

			$pcm_type = isset( $pcm_field['type'] ) && isset( $pcm_types[ $pcm_field['type'] ] ) ? $pcm_field['type'] : 'text';

			$pcm_clean = array(
				'key'      => $pcm_key,
				'label'    => $pcm_label,
				'type'     => $pcm_type,
				'api_name' => ! empty( $pcm_field['api_name'] ) ? sanitize_text_field( $pcm_field['api_name'] ) : '',
				'help'     => ! empty( $pcm_field['help'] ) ? sanitize_text_field( $pcm_field['help'] ) : '',
			);

			if ( 'picklist' === $pcm_type ) {
				$pcm_clean['options'] = array_values( array_filter( array_map(
					'sanitize_text_field',
					array_map( 'trim', explode( "\n", (string) ( isset( $pcm_field['options'] ) ? $pcm_field['options'] : '' ) ) )
				) ) );
			}

			if ( 'relationship' === $pcm_type ) {
				$pcm_related = isset( $pcm_field['related'] ) ? sanitize_key( $pcm_field['related'] ) : '';
				$pcm_clean['related'] = isset( $pcm_objects[ $pcm_related ] ) ? $pcm_related : 'accounts';
			}

			$pcm_object_fields[] = $pcm_clean;
		}

		$pcm_out[ $pcm_object ] = $pcm_object_fields;
	}

	return $pcm_out;
}

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
