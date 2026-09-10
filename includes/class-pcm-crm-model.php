<?php
/**
 * Shared CRUD and query building for the four objects.
 *
 * Each model is one instance configured with a table and a field map. The
 * field map is the single source of truth for three things at once: what may
 * be written, how each value is sanitised, and the Salesforce API name the CSV
 * export uses as its header. Keeping them together is what stops the export
 * drifting away from the schema.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PCM_CRM_Model {

	/** Object name, e.g. 'account'. */
	protected $object;

	/** Fully-prefixed table name. */
	protected $table;

	/** field => array( 'type' => ..., 'sf' => Salesforce API name ). */
	protected $fields;

	/** Columns a free-text search looks in. */
	protected $searchable;

	/**
	 * Parent objects this one can be filtered through, keyed by the prefix a
	 * filter uses: 'account.industry' filters opportunities by their account's
	 * industry. Each entry names the local foreign key and a callable
	 * returning the parent's model.
	 */
	protected $related;

	public function __construct( $pcm_object, $pcm_table, array $pcm_fields, array $pcm_searchable = array(), array $pcm_related = array() ) {
		$this->object     = $pcm_object;
		$this->table      = $pcm_table;
		$this->fields     = $pcm_fields;
		$this->searchable = $pcm_searchable;
		$this->related    = $pcm_related;
	}

	public function related() {
		return $this->related;
	}

	public function object()     { return $this->object; }
	public function table()      { return $this->table; }
	public function fields()     { return $this->fields; }
	public function field_names() { return array_keys( $this->fields ); }

	public function has_field( $pcm_field ) {
		return isset( $this->fields[ $pcm_field ] );
	}

	/**
	 * field => Salesforce API name, for the CSV export's header row.
	 */
	public function salesforce_map() {
		$pcm_map = array();

		foreach ( $this->fields as $pcm_name => $pcm_def ) {
			if ( ! empty( $pcm_def['sf'] ) ) {
				$pcm_map[ $pcm_name ] = $pcm_def['sf'];
			}
		}

		return $pcm_map;
	}

	/* -----------------------------------------------------------------------
	   Sanitising
	   ----------------------------------------------------------------------- */

	/**
	 * Reduce arbitrary input to a writable row.
	 *
	 * Anything not in the field map is dropped rather than rejected — the REST
	 * layer sends whole records back on save, including read-only computed
	 * fields, and a save must not fail because of one of them.
	 */
	public function sanitize( array $pcm_input ) {
		$pcm_row = array();

		foreach ( $pcm_input as $pcm_key => $pcm_value ) {
			if ( ! isset( $this->fields[ $pcm_key ] ) ) {
				continue;
			}

			if ( ! empty( $this->fields[ $pcm_key ]['readonly'] ) ) {
				continue;
			}

			$pcm_row[ $pcm_key ] = $this->sanitize_value( $pcm_value, $this->fields[ $pcm_key ]['type'] );
		}

		return $pcm_row;
	}

	/**
	 * Nullable columns keep NULL rather than being coerced to 0 or an epoch
	 * date: an Opportunity with no amount and one worth nothing are different
	 * facts, and averaging them together would be wrong.
	 */
	protected function sanitize_value( $pcm_value, $pcm_type ) {
		switch ( $pcm_type ) {
			case 'int':
				return ( '' === $pcm_value || null === $pcm_value ) ? null : (int) $pcm_value;

			case 'id':
				return absint( $pcm_value );

			case 'decimal':
				if ( '' === $pcm_value || null === $pcm_value ) {
					return null;
				}
				// Strip currency formatting before casting, so "$12,500" from a
				// pasted value does not silently become 12.
				return (float) preg_replace( '/[^0-9.\-]/', '', (string) $pcm_value );

			case 'bool':
				return ( $pcm_value && 'false' !== $pcm_value && '0' !== $pcm_value ) ? 1 : 0;

			case 'date':
				$pcm_date = substr( trim( (string) $pcm_value ), 0, 10 );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pcm_date ) ? $pcm_date : null;

			case 'datetime':
				$pcm_time = strtotime( (string) $pcm_value );
				return $pcm_time ? gmdate( 'Y-m-d H:i:s', $pcm_time ) : '0000-00-00 00:00:00';

			case 'email':
				return sanitize_email( (string) $pcm_value );

			case 'url':
				return esc_url_raw( (string) $pcm_value );

			case 'longtext':
				return sanitize_textarea_field( (string) $pcm_value );

			// JSON, stored verbatim. Written only by the app, read back through
			// json_decode, which fails safely on anything malformed — running
			// it through a text sanitiser would take the structure apart.
			case 'raw':
				return is_scalar( $pcm_value ) ? (string) $pcm_value : wp_json_encode( $pcm_value );

			default:
				return sanitize_text_field( (string) $pcm_value );
		}
	}

	/**
	 * The wpdb format string for a row, so %s/%d/%f line up with the values.
	 */
	protected function formats( array $pcm_row ) {
		$pcm_formats = array();

		foreach ( $pcm_row as $pcm_key => $pcm_value ) {
			$pcm_type = isset( $this->fields[ $pcm_key ]['type'] ) ? $this->fields[ $pcm_key ]['type'] : 'text';

			if ( in_array( $pcm_type, array( 'int', 'id', 'bool' ), true ) ) {
				$pcm_formats[] = '%d';
			} elseif ( 'decimal' === $pcm_type ) {
				$pcm_formats[] = '%f';
			} else {
				$pcm_formats[] = '%s';
			}
		}

		return $pcm_formats;
	}

	/* -----------------------------------------------------------------------
	   Reads
	   ----------------------------------------------------------------------- */

	public function get( $pcm_id ) {
		global $wpdb;

		$pcm_id = absint( $pcm_id );

		if ( ! $pcm_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
		$pcm_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $pcm_id ), ARRAY_A );

		return $pcm_row ? $this->cast_row( $pcm_row ) : null;
	}

	/**
	 * Rows keyed by id, for resolving a page of foreign keys in one query
	 * rather than one per row.
	 */
	public function get_many( array $pcm_ids ) {
		global $wpdb;

		$pcm_ids = array_filter( array_map( 'absint', $pcm_ids ) );

		if ( ! $pcm_ids ) {
			return array();
		}

		$pcm_in = implode( ',', array_unique( $pcm_ids ) );

		// phpcs:ignore WordPress.DB.PreparedSQL -- ids are cast to int above
		$pcm_rows = $wpdb->get_results( "SELECT * FROM {$this->table} WHERE id IN ({$pcm_in})", ARRAY_A );

		$pcm_out = array();
		foreach ( (array) $pcm_rows as $pcm_row ) {
			$pcm_out[ (int) $pcm_row['id'] ] = $this->cast_row( $pcm_row );
		}

		return $pcm_out;
	}

	/**
	 * Cast a row out of MySQL's all-strings into real types.
	 *
	 * The admin app does arithmetic and boolean tests on these values in JS,
	 * where "0" is truthy — so the casting has to happen before the JSON, not
	 * in the browser.
	 */
	protected function cast_row( array $pcm_row ) {
		foreach ( $pcm_row as $pcm_key => $pcm_value ) {
			if ( ! isset( $this->fields[ $pcm_key ] ) ) {
				continue;
			}

			if ( null === $pcm_value ) {
				continue;
			}

			switch ( $this->fields[ $pcm_key ]['type'] ) {
				case 'int':
				case 'id':
				case 'bool':
					$pcm_row[ $pcm_key ] = (int) $pcm_value;
					break;
				case 'decimal':
					$pcm_row[ $pcm_key ] = (float) $pcm_value;
					break;
			}
		}

		$pcm_row['id'] = (int) $pcm_row['id'];

		return $pcm_row;
	}

	/**
	 * Build the WHERE clause shared by find() and count().
	 *
	 * Public because the aggregate queries in reports.php need the same
	 * filtering the list views get, and reaching it by reflection to avoid
	 * saying so would be worse than saying so.
	 *
	 * Column names are interpolated, never prepared — placeholders cannot
	 * stand in for identifiers — so every one is checked against the field map
	 * first and an unknown column is dropped rather than passed through.
	 */
	public function where( array $pcm_args ) {
		global $wpdb;

		$pcm_where = array();

		// Not every table carries the soft-delete flag — the submissions log is
		// an append-only audit trail — so the clause is added only where the
		// column actually exists.
		if ( empty( $pcm_args['include_deleted'] ) && $this->has_field( 'is_deleted' ) ) {
			$pcm_where[] = 'is_deleted = 0';
		}

		if ( ! empty( $pcm_args['ids'] ) ) {
			$pcm_ids = array_filter( array_map( 'absint', (array) $pcm_args['ids'] ) );
			$pcm_where[] = $pcm_ids ? 'id IN (' . implode( ',', $pcm_ids ) . ')' : '1 = 0';
		}

		if ( ! empty( $pcm_args['search'] ) && $this->searchable ) {
			$pcm_like  = '%' . $wpdb->esc_like( (string) $pcm_args['search'] ) . '%';
			$pcm_parts = array();

			foreach ( $this->searchable as $pcm_col ) {
				if ( $this->has_field( $pcm_col ) ) {
					$pcm_parts[] = $wpdb->prepare( "{$pcm_col} LIKE %s", $pcm_like );
				}
			}

			if ( $pcm_parts ) {
				$pcm_where[] = '(' . implode( ' OR ', $pcm_parts ) . ')';
			}
		}

		$pcm_filters = isset( $pcm_args['filters'] ) && is_array( $pcm_args['filters'] ) ? $pcm_args['filters'] : array();

		// A key of the form 'account.industry' filters through a parent
		// object. Those are collected per prefix and resolved together, so one
		// subquery covers every condition on that parent rather than one each.
		$pcm_through = array();

		foreach ( $pcm_filters as $pcm_col => $pcm_value ) {
			if ( false !== strpos( $pcm_col, '.' ) ) {
				list( $pcm_prefix, $pcm_field ) = explode( '.', $pcm_col, 2 );

				if ( isset( $this->related[ $pcm_prefix ] ) ) {
					$pcm_through[ $pcm_prefix ][ $pcm_field ] = $pcm_value;
				}

				continue;
			}

			if ( ! $this->has_field( $pcm_col ) ) {
				continue;
			}

			$pcm_clause = $this->clause( $pcm_col, $pcm_value, $this->fields[ $pcm_col ]['type'] );

			if ( '' !== $pcm_clause ) {
				$pcm_where[] = $pcm_clause;
			}
		}

		foreach ( $pcm_through as $pcm_prefix => $pcm_parent_filters ) {
			$pcm_clause = $this->related_clause( $pcm_prefix, $pcm_parent_filters );

			if ( '' !== $pcm_clause ) {
				$pcm_where[] = $pcm_clause;
			}
		}

		if ( ! empty( $pcm_args['where_raw'] ) ) {
			// Callers inside the plugin only — never anything from a request.
			$pcm_where[] = '(' . $pcm_args['where_raw'] . ')';
		}

		return $pcm_where ? 'WHERE ' . implode( ' AND ', $pcm_where ) : '';
	}

	/**
	 * One filter's SQL.
	 *
	 * Four shapes are accepted, because they arrived in that order and the
	 * earlier ones are still what the dashboard and pipeline send:
	 *   'Proposal'                       equals
	 *   array( 'a', 'b' )                IN
	 *   array( 'min' => x, 'max' => y )  range, either bound optional
	 *   array( 'op' => 'contains', 'value' => x )
	 *
	 * Column names are interpolated, never prepared — a placeholder cannot
	 * stand in for an identifier — so callers must have checked the column
	 * against the field map first, which where() does.
	 */
	protected function clause( $pcm_col, $pcm_value, $pcm_type ) {
		global $wpdb;

		if ( is_array( $pcm_value ) && isset( $pcm_value['op'] ) ) {
			return $this->operator_clause( $pcm_col, $pcm_value, $pcm_type );
		}

		// A range: either bound may be absent, which is an open-ended range
		// rather than an error.
		if ( is_array( $pcm_value ) && ( isset( $pcm_value['min'] ) || isset( $pcm_value['max'] ) ) ) {
			$pcm_parts = array();

			if ( isset( $pcm_value['min'] ) && '' !== $pcm_value['min'] ) {
				$pcm_parts[] = $wpdb->prepare( "{$pcm_col} >= %s", $this->sanitize_value( $pcm_value['min'], $pcm_type ) );
			}
			if ( isset( $pcm_value['max'] ) && '' !== $pcm_value['max'] ) {
				$pcm_parts[] = $wpdb->prepare( "{$pcm_col} <= %s", $this->sanitize_value( $pcm_value['max'], $pcm_type ) );
			}

			return $pcm_parts ? '(' . implode( ' AND ', $pcm_parts ) . ')' : '';
		}

		if ( is_array( $pcm_value ) ) {
			$pcm_set = array();

			foreach ( $pcm_value as $pcm_one ) {
				$pcm_set[] = $wpdb->prepare( '%s', $this->sanitize_value( $pcm_one, $pcm_type ) );
			}

			// An empty set matches nothing. Dropping the clause instead would
			// quietly turn "none of these" into "everything".
			return $pcm_set ? "{$pcm_col} IN (" . implode( ',', $pcm_set ) . ')' : '1 = 0';
		}

		if ( '' === $pcm_value || null === $pcm_value ) {
			return '';
		}

		return $wpdb->prepare( "{$pcm_col} = %s", $this->sanitize_value( $pcm_value, $pcm_type ) );
	}

	/**
	 * The operator forms the filter builder sends.
	 */
	protected function operator_clause( $pcm_col, array $pcm_filter, $pcm_type ) {
		global $wpdb;

		$pcm_op  = (string) $pcm_filter['op'];
		$pcm_raw = isset( $pcm_filter['value'] ) ? $pcm_filter['value'] : '';

		// Emptiness is the one test that needs no value, and the only one
		// where a blank input is meaningful rather than an unfinished filter.
		if ( 'empty' === $pcm_op || 'notempty' === $pcm_op ) {
			$pcm_blank = in_array( $pcm_type, array( 'int', 'id', 'decimal', 'bool' ), true ) ? '0' : "''";
			$pcm_test  = "({$pcm_col} IS NULL OR {$pcm_col} = {$pcm_blank})";

			return 'empty' === $pcm_op ? $pcm_test : "NOT {$pcm_test}";
		}

		if ( 'between' === $pcm_op ) {
			return $this->clause( $pcm_col, array(
				'min' => isset( $pcm_filter['min'] ) ? $pcm_filter['min'] : '',
				'max' => isset( $pcm_filter['max'] ) ? $pcm_filter['max'] : '',
			), $pcm_type );
		}

		if ( 'in' === $pcm_op ) {
			return $this->clause( $pcm_col, (array) $pcm_raw, $pcm_type );
		}

		if ( '' === $pcm_raw || null === $pcm_raw ) {
			return '';
		}

		// LIKE takes the raw string with its wildcards escaped; everything
		// else goes through the column's own sanitiser first.
		if ( in_array( $pcm_op, array( 'contains', 'notcontains', 'starts', 'ends' ), true ) ) {
			$pcm_like = $wpdb->esc_like( (string) $pcm_raw );

			$pcm_patterns = array(
				'contains'    => '%' . $pcm_like . '%',
				'notcontains' => '%' . $pcm_like . '%',
				'starts'      => $pcm_like . '%',
				'ends'        => '%' . $pcm_like,
			);

			$pcm_not = 'notcontains' === $pcm_op ? 'NOT ' : '';

			return $wpdb->prepare( "{$pcm_col} {$pcm_not}LIKE %s", $pcm_patterns[ $pcm_op ] );
		}

		$pcm_operators = array(
			'eq'  => '=',
			'ne'  => '!=',
			'gt'  => '>',
			'gte' => '>=',
			'lt'  => '<',
			'lte' => '<=',
		);

		if ( ! isset( $pcm_operators[ $pcm_op ] ) ) {
			return '';
		}

		return $wpdb->prepare(
			"{$pcm_col} {$pcm_operators[ $pcm_op ]} %s",
			$this->sanitize_value( $pcm_raw, $pcm_type )
		);
	}

	/**
	 * Filter through a parent object.
	 *
	 * A subquery rather than a join, so the parent's own rules — its soft
	 * delete, its column whitelist, its operators — come from its model
	 * unchanged, and so a page of opportunities does not multiply rows.
	 */
	protected function related_clause( $pcm_prefix, array $pcm_filters ) {
		if ( ! isset( $this->related[ $pcm_prefix ] ) || ! $pcm_filters ) {
			return '';
		}

		$pcm_link  = $this->related[ $pcm_prefix ];
		$pcm_model = call_user_func( $pcm_link['model'] );

		if ( ! $pcm_model instanceof self || ! $this->has_field( $pcm_link['column'] ) ) {
			return '';
		}

		$pcm_where = $pcm_model->where( array( 'filters' => $pcm_filters ) );

		// Compared against the parent's unfiltered clause rather than merely
		// checked for emptiness: an unknown column, or one with a blank value,
		// still leaves the parent's own "is_deleted = 0" behind, and that would
		// turn the subquery into "IN (every parent row)" — a filter that
		// silently matches everything instead of narrowing anything.
		if ( $pcm_where === $pcm_model->where( array() ) ) {
			return '';
		}

		$pcm_column = $pcm_link['column'];
		$pcm_table  = $pcm_model->table();

		return "{$pcm_column} IN (SELECT id FROM {$pcm_table} {$pcm_where})";
	}

	public function find( array $pcm_args = array() ) {
		global $wpdb;

		$pcm_args = wp_parse_args( $pcm_args, array(
			'search'          => '',
			'filters'         => array(),
			'orderby'         => 'last_modified_date',
			'order'           => 'DESC',
			'page'            => 1,
			'per_page'        => 25,
			'include_deleted' => false,
		) );

		$pcm_where = $this->where( $pcm_args );

		$pcm_orderby = $this->has_field( $pcm_args['orderby'] ) ? $pcm_args['orderby'] : 'id';
		$pcm_order   = 'ASC' === strtoupper( (string) $pcm_args['order'] ) ? 'ASC' : 'DESC';

		// per_page 0 means "everything" — used by exports and the pipeline,
		// which need the whole filtered set rather than a page of it.
		$pcm_per_page = (int) $pcm_args['per_page'];
		$pcm_limit    = '';

		if ( $pcm_per_page > 0 ) {
			$pcm_per_page = min( $pcm_per_page, 500 );
			$pcm_page     = max( 1, (int) $pcm_args['page'] );
			$pcm_offset   = ( $pcm_page - 1 ) * $pcm_per_page;
			$pcm_limit    = $wpdb->prepare( 'LIMIT %d OFFSET %d', $pcm_per_page, $pcm_offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL -- clauses are built and escaped above
		$pcm_rows = $wpdb->get_results( "SELECT * FROM {$this->table} {$pcm_where} ORDER BY {$pcm_orderby} {$pcm_order}, id DESC {$pcm_limit}", ARRAY_A );

		$pcm_items = array();
		foreach ( (array) $pcm_rows as $pcm_row ) {
			$pcm_items[] = $this->cast_row( $pcm_row );
		}

		return $pcm_items;
	}

	public function count( array $pcm_args = array() ) {
		global $wpdb;

		$pcm_where = $this->where( $pcm_args );

		// phpcs:ignore WordPress.DB.PreparedSQL -- clauses are built and escaped above
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} {$pcm_where}" );
	}

	/**
	 * COUNT and SUM grouped by a column, for the dashboard and reports.
	 *
	 * Returns rows of value / count / total, honouring the same filters as a
	 * list view so a chart and the list behind it can never disagree.
	 */
	public function group_by( $pcm_column, array $pcm_args = array(), $pcm_sum_column = '' ) {
		global $wpdb;

		if ( ! $this->has_field( $pcm_column ) ) {
			return array();
		}

		$pcm_where = $this->where( $pcm_args );
		$pcm_sum   = ( $pcm_sum_column && $this->has_field( $pcm_sum_column ) )
			? "COALESCE(SUM({$pcm_sum_column}), 0)"
			: '0';

		// phpcs:ignore WordPress.DB.PreparedSQL -- column names checked against the field map
		$pcm_rows = $wpdb->get_results(
			"SELECT {$pcm_column} AS value, COUNT(*) AS count, {$pcm_sum} AS total
			 FROM {$this->table} {$pcm_where}
			 GROUP BY {$pcm_column} ORDER BY count DESC",
			ARRAY_A
		);

		$pcm_out = array();
		foreach ( (array) $pcm_rows as $pcm_row ) {
			$pcm_out[] = array(
				'value' => (string) $pcm_row['value'],
				'count' => (int) $pcm_row['count'],
				'total' => (float) $pcm_row['total'],
			);
		}

		return $pcm_out;
	}

	public function sum( $pcm_column, array $pcm_args = array() ) {
		global $wpdb;

		if ( ! $this->has_field( $pcm_column ) ) {
			return 0.0;
		}

		$pcm_where = $this->where( $pcm_args );

		// phpcs:ignore WordPress.DB.PreparedSQL -- column name checked against the field map
		return (float) $wpdb->get_var( "SELECT COALESCE(SUM({$pcm_column}), 0) FROM {$this->table} {$pcm_where}" );
	}

	/* -----------------------------------------------------------------------
	   Writes
	   ----------------------------------------------------------------------- */

	public function insert( array $pcm_input ) {
		global $wpdb;

		$pcm_row = $this->sanitize( $pcm_input );
		$pcm_now = current_time( 'mysql' );

		// Guarded on has_field so the same insert path serves both the four
		// Salesforce-shaped objects and the plain submissions log, which has
		// no ownership or soft-delete columns.
		$pcm_stamps = array(
			'created_date'        => $pcm_now,
			'last_modified_date'  => $pcm_now,
			'created_by_id'       => get_current_user_id(),
			'last_modified_by_id' => get_current_user_id(),
			'is_deleted'          => 0,
		);

		foreach ( $pcm_stamps as $pcm_key => $pcm_value ) {
			if ( $this->has_field( $pcm_key ) ) {
				$pcm_row[ $pcm_key ] = $pcm_value;
			}
		}

		if ( $this->has_field( 'owner_id' ) && empty( $pcm_row['owner_id'] ) ) {
			$pcm_row['owner_id'] = get_current_user_id();
		}

		$pcm_row = apply_filters( 'pcm_crm_before_insert', $pcm_row, $this->object );

		$pcm_invalid = apply_filters( 'pcm_crm_validate', null, $this->object, $pcm_row, 0 );

		if ( is_wp_error( $pcm_invalid ) ) {
			return $pcm_invalid;
		}

		$pcm_ok = $wpdb->insert( $this->table, $pcm_row, $this->formats( $pcm_row ) );

		if ( ! $pcm_ok ) {
			return new WP_Error( 'pcm_crm_insert_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Could not save the record.', 'pcm-crm' ) );
		}

		$pcm_id = (int) $wpdb->insert_id;

		do_action( 'pcm_crm_inserted', $this->object, $pcm_id, $pcm_row );

		return $pcm_id;
	}

	public function update( $pcm_id, array $pcm_input ) {
		global $wpdb;

		$pcm_id = absint( $pcm_id );

		if ( ! $pcm_id || ! $this->get( $pcm_id ) ) {
			return new WP_Error( 'pcm_crm_not_found', __( 'That record no longer exists.', 'pcm-crm' ) );
		}

		$pcm_row = $this->sanitize( $pcm_input );

		if ( ! $pcm_row ) {
			return $pcm_id;
		}

		if ( $this->has_field( 'last_modified_date' ) ) {
			$pcm_row['last_modified_date'] = current_time( 'mysql' );
		}

		if ( $this->has_field( 'last_modified_by_id' ) ) {
			$pcm_row['last_modified_by_id'] = get_current_user_id();
		}

		$pcm_row = apply_filters( 'pcm_crm_before_update', $pcm_row, $this->object, $pcm_id );

		// Validation runs after the before_ filters, so a rule sees the row as
		// it will actually be written rather than as it was posted.
		$pcm_invalid = apply_filters( 'pcm_crm_validate', null, $this->object, $pcm_row, $pcm_id );

		if ( is_wp_error( $pcm_invalid ) ) {
			return $pcm_invalid;
		}

		$pcm_ok = $wpdb->update( $this->table, $pcm_row, array( 'id' => $pcm_id ), $this->formats( $pcm_row ), array( '%d' ) );

		if ( false === $pcm_ok ) {
			return new WP_Error( 'pcm_crm_update_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Could not save the record.', 'pcm-crm' ) );
		}

		do_action( 'pcm_crm_updated', $this->object, $pcm_id, $pcm_row );

		return $pcm_id;
	}

	/**
	 * Soft delete, following Salesforce's own IsDeleted recycle bin.
	 *
	 * Nothing here removes a row: a deleted Account's Contacts keep pointing at
	 * it, and undeleting restores the whole shape rather than a bare record.
	 */
	public function delete( $pcm_id ) {
		global $wpdb;

		$pcm_id = absint( $pcm_id );

		if ( ! $pcm_id ) {
			return false;
		}

		$pcm_ok = $wpdb->update(
			$this->table,
			array( 'is_deleted' => 1, 'last_modified_date' => current_time( 'mysql' ) ),
			array( 'id' => $pcm_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false !== $pcm_ok ) {
			do_action( 'pcm_crm_deleted', $this->object, $pcm_id );
		}

		return false !== $pcm_ok;
	}

	public function restore( $pcm_id ) {
		global $wpdb;

		return false !== $wpdb->update(
			$this->table,
			array( 'is_deleted' => 0, 'last_modified_date' => current_time( 'mysql' ) ),
			array( 'id' => absint( $pcm_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * The shared tail every object carries: ownership, audit stamps and the
	 * soft-delete flag. Defined once so the five field maps cannot drift.
	 */
	public static function system_fields() {
		return array(
			// 'internal' keeps a column out of the filter builder: sf_id is
			// blank until a migration fills it, and is_deleted is the query's
			// own business rather than something to filter on by hand.
			'sf_id'               => array( 'type' => 'text', 'sf' => 'Id', 'internal' => true ),
			'owner_id'            => array( 'type' => 'id', 'sf' => 'OwnerId', 'label' => 'Owner', 'options' => 'pcm_crm_owner_options' ),
			'created_by_id'       => array( 'type' => 'id', 'sf' => 'CreatedById', 'label' => 'Created By', 'options' => 'pcm_crm_owner_options', 'readonly' => true ),
			'created_date'        => array( 'type' => 'datetime', 'sf' => 'CreatedDate', 'label' => 'Created Date', 'readonly' => true ),
			'last_modified_by_id' => array( 'type' => 'id', 'sf' => 'LastModifiedById', 'label' => 'Last Modified By', 'options' => 'pcm_crm_owner_options', 'readonly' => true ),
			'last_modified_date'  => array( 'type' => 'datetime', 'sf' => 'LastModifiedDate', 'label' => 'Last Modified Date', 'readonly' => true ),
			'is_deleted'          => array( 'type' => 'bool', 'sf' => 'IsDeleted', 'readonly' => true, 'internal' => true ),
		);
	}
}
