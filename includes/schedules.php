<?php
/**
 * Scheduled delivery of the dashboard and reports.
 *
 * A schedule is a saved view — object, grouping, filters — plus who gets it
 * and when. The filters are stored as the same JSON the screens put in the
 * URL, so a schedule and the view it was created from cannot drift apart.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_schedules() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'schedule',
			PCM_CRM_Schema::schedules(),
			array_merge(
				array(
					'name'         => array( 'type' => 'text', 'label' => 'Name' ),
					'report_type'  => array( 'type' => 'text', 'label' => 'Type', 'options' => 'pcm_crm_schedule_types' ),
					'object'       => array( 'type' => 'text', 'label' => 'Report On' ),
					'group_by'     => array( 'type' => 'text', 'label' => 'Grouped By' ),
					// Stored verbatim rather than sanitised as text: it is JSON,
					// and sanitize_text_field would strip the quotes out of it.
					'filters'      => array( 'type' => 'raw', 'internal' => true ),
					'recipients'   => array( 'type' => 'text', 'label' => 'Recipients' ),
					'frequency'    => array( 'type' => 'text', 'label' => 'Frequency', 'options' => 'pcm_crm_frequencies' ),
					'send_time'    => array( 'type' => 'text', 'label' => 'Send At' ),
					'day_of_week'  => array( 'type' => 'int', 'label' => 'Day Of Week' ),
					'day_of_month' => array( 'type' => 'int', 'label' => 'Day Of Month' ),
					'attach_csv'   => array( 'type' => 'bool', 'label' => 'Attach CSV' ),
					'is_active'    => array( 'type' => 'bool', 'label' => 'Active' ),
					'last_sent'    => array( 'type' => 'datetime', 'label' => 'Last Sent', 'readonly' => true ),
					'last_error'   => array( 'type' => 'text', 'label' => 'Last Error', 'readonly' => true ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'name', 'recipients' )
		);
	}

	return $pcm_model;
}

function pcm_crm_schedule_types() {
	return array( 'dashboard', 'report' );
}

function pcm_crm_frequencies() {
	return array( 'daily', 'weekly', 'monthly' );
}

function pcm_crm_weekdays() {
	return array( 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' );
}

/**
 * The addresses a schedule sends to.
 *
 * Accepts a mix of email addresses and WordPress user logins or ids, because
 * "send it to Jason" and "send it to jason@..." are the same intent and asking
 * someone to know which one the field wants is a poor reason to fail.
 */
function pcm_crm_schedule_recipients( $pcm_raw ) {
	$pcm_out = array();

	foreach ( preg_split( '/[,;\s]+/', (string) $pcm_raw ) as $pcm_entry ) {
		$pcm_entry = trim( $pcm_entry );

		if ( '' === $pcm_entry ) {
			continue;
		}

		if ( is_email( $pcm_entry ) ) {
			$pcm_out[] = sanitize_email( $pcm_entry );
			continue;
		}

		$pcm_user = is_numeric( $pcm_entry ) ? get_userdata( (int) $pcm_entry ) : get_user_by( 'login', $pcm_entry );

		if ( $pcm_user && is_email( $pcm_user->user_email ) ) {
			$pcm_out[] = $pcm_user->user_email;
		}
	}

	return array_values( array_unique( $pcm_out ) );
}

/**
 * Is this schedule due?
 *
 * Compared in site time, and against the last send rather than against a
 * window, so a cron run that arrives late still delivers instead of skipping
 * the day entirely — which is the failure a busy site would hit most.
 */
function pcm_crm_schedule_is_due( array $pcm_schedule, $pcm_now = null ) {
	if ( empty( $pcm_schedule['is_active'] ) ) {
		return false;
	}

	$pcm_now  = $pcm_now ? $pcm_now : current_time( 'timestamp' );
	$pcm_time = preg_match( '/^\d{1,2}:\d{2}$/', (string) $pcm_schedule['send_time'] ) ? $pcm_schedule['send_time'] : '08:00';

	list( $pcm_hour, $pcm_minute ) = array_map( 'intval', explode( ':', $pcm_time ) );

	$pcm_due_today = mktime( $pcm_hour, $pcm_minute, 0, (int) gmdate( 'n', $pcm_now ), (int) gmdate( 'j', $pcm_now ), (int) gmdate( 'Y', $pcm_now ) );

	if ( $pcm_now < $pcm_due_today ) {
		return false;
	}

	// The right day for the frequency.
	if ( 'weekly' === $pcm_schedule['frequency'] && (int) gmdate( 'N', $pcm_now ) !== (int) $pcm_schedule['day_of_week'] ) {
		return false;
	}

	if ( 'monthly' === $pcm_schedule['frequency'] ) {
		$pcm_last_day = (int) gmdate( 't', $pcm_now );
		// A 31st schedule in February should still land, on the last day.
		$pcm_target   = min( (int) $pcm_schedule['day_of_month'], $pcm_last_day );

		if ( (int) gmdate( 'j', $pcm_now ) !== $pcm_target ) {
			return false;
		}
	}

	// Already sent since it came due.
	if ( ! empty( $pcm_schedule['last_sent'] ) && ! pcm_crm_is_zero_date( $pcm_schedule['last_sent'] ) ) {
		if ( strtotime( $pcm_schedule['last_sent'] ) >= $pcm_due_today ) {
			return false;
		}
	}

	return true;
}

/**
 * A schedule with no reachable recipient can never do anything.
 *
 * Refused on save rather than discovered on the first send, which would be a
 * week later and silent. Validated against the merged record, so editing the
 * frequency on an existing schedule does not have to restate its recipients.
 */
function pcm_crm_validate_schedule( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( is_wp_error( $pcm_error ) || 'schedule' !== $pcm_object ) {
		return $pcm_error;
	}

	$pcm_existing = $pcm_id ? pcm_crm_schedules()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( (array) $pcm_existing, $pcm_row );

	$pcm_to = pcm_crm_schedule_recipients( isset( $pcm_merged['recipients'] ) ? $pcm_merged['recipients'] : '' );

	if ( ! $pcm_to ) {
		return new WP_Error(
			'pcm_crm_no_recipients',
			__( 'Choose at least one person, or add an email address, before saving.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_validate_schedule', 10, 4 );

/* ---------------------------------------------------------------------------
   Cron
   --------------------------------------------------------------------------- */

/**
 * A quarter-hour interval.
 *
 * Hourly would mean a schedule set for 08:30 arriving at 09:00, which reads as
 * broken. The check itself is one indexed query, so the extra runs are cheap.
 */
function pcm_crm_cron_interval( $pcm_schedules ) {
	$pcm_schedules['pcm_crm_quarter_hour'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 15 minutes (PCM CRM)', 'pcm-crm' ),
	);

	return $pcm_schedules;
}
add_filter( 'cron_schedules', 'pcm_crm_cron_interval' );

function pcm_crm_schedule_cron() {
	if ( ! wp_next_scheduled( 'pcm_crm_send_scheduled' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'pcm_crm_quarter_hour', 'pcm_crm_send_scheduled' );
	}
}
add_action( 'init', 'pcm_crm_schedule_cron' );

/**
 * Send everything that has come due.
 *
 * Each schedule is sent inside its own try/catch: one bad recipient list or
 * one broken filter must not stop the rest of the run, and the failure is
 * recorded on the row so it is visible rather than only in a log.
 */
function pcm_crm_run_schedules() {
	$pcm_due = pcm_crm_schedules()->find( array(
		'filters'  => array( 'is_active' => 1 ),
		'per_page' => 100,
	) );

	foreach ( $pcm_due as $pcm_schedule ) {
		if ( ! pcm_crm_schedule_is_due( $pcm_schedule ) ) {
			continue;
		}

		try {
			$pcm_sent = pcm_crm_send_schedule( $pcm_schedule );

			pcm_crm_touch_schedule( $pcm_schedule['id'], $pcm_sent ? '' : 'Mail was rejected by the server.' );
		} catch ( Exception $pcm_e ) {
			pcm_crm_touch_schedule( $pcm_schedule['id'], $pcm_e->getMessage() );
		} catch ( Error $pcm_e ) {
			pcm_crm_touch_schedule( $pcm_schedule['id'], $pcm_e->getMessage() );
		}
	}
}
add_action( 'pcm_crm_send_scheduled', 'pcm_crm_run_schedules' );

/**
 * Stamp the attempt, whether or not it worked.
 *
 * last_sent moves either way on purpose: a schedule that fails must not retry
 * every fifteen minutes for the rest of the day.
 */
function pcm_crm_touch_schedule( $pcm_id, $pcm_error ) {
	global $wpdb;

	$wpdb->update(
		PCM_CRM_Schema::schedules(),
		array( 'last_sent' => current_time( 'mysql' ), 'last_error' => $pcm_error ),
		array( 'id' => absint( $pcm_id ) ),
		array( '%s', '%s' ),
		array( '%d' )
	);
}

function pcm_crm_deactivate_cron() {
	$pcm_next = wp_next_scheduled( 'pcm_crm_send_scheduled' );

	if ( $pcm_next ) {
		wp_unschedule_event( $pcm_next, 'pcm_crm_send_scheduled' );
	}
}

/* ---------------------------------------------------------------------------
   Rendering and sending
   --------------------------------------------------------------------------- */

/**
 * The filters a schedule was saved with.
 */
function pcm_crm_schedule_filters( array $pcm_schedule ) {
	$pcm_filters = json_decode( (string) $pcm_schedule['filters'], true );

	return is_array( $pcm_filters ) ? $pcm_filters : array();
}

/**
 * Build and send one schedule.
 *
 * Charts are not sent. Mail clients render inline SVG unreliably and strip
 * scripts entirely, so the email carries the same numbers as tables — which
 * survive everywhere and are what someone reads a scheduled report for. The
 * link back into the CRM is where the charts live.
 */
function pcm_crm_send_schedule( array $pcm_schedule ) {
	$pcm_to = pcm_crm_schedule_recipients( $pcm_schedule['recipients'] );

	if ( ! $pcm_to ) {
		// Naming what was stored, because "no usable recipients" on a schedule
		// that visibly has one is a dead end — the useful question is whether
		// the field is empty or holds something that no longer resolves.
		$pcm_stored = trim( (string) $pcm_schedule['recipients'] );

		throw new Exception(
			'' === $pcm_stored
				? 'No recipients are saved on this schedule.'
				: sprintf( 'None of the saved recipients resolve to an email address (%s).', $pcm_stored )
		);
	}

	$pcm_args = array( 'filters' => pcm_crm_schedule_filters( $pcm_schedule ) );

	$pcm_body = ( 'dashboard' === $pcm_schedule['report_type'] )
		? pcm_crm_dashboard_email( $pcm_schedule, $pcm_args )
		: pcm_crm_report_email( $pcm_schedule, $pcm_args );

	$pcm_subject = $pcm_schedule['name'] ? $pcm_schedule['name'] : __( 'Your CRM report', 'pcm-crm' );
	$pcm_subject .= ' — ' . date_i18n( get_option( 'date_format' ) );

	$pcm_attachments = array();
	$pcm_temp        = '';

	if ( ! empty( $pcm_schedule['attach_csv'] ) && 'report' === $pcm_schedule['report_type'] ) {
		$pcm_temp = pcm_crm_schedule_csv( $pcm_schedule, $pcm_args );

		if ( $pcm_temp ) {
			$pcm_attachments[] = $pcm_temp;
		}
	}

	$pcm_from    = pcm_crm_contact_recipient();
	$pcm_headers = array( 'From: Pretty Code Machine <' . $pcm_from . '>' );

	add_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );
	$pcm_sent = wp_mail( $pcm_to, $pcm_subject, pcm_crm_email_wrapper( $pcm_body ), $pcm_headers, $pcm_attachments );
	remove_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );

	// The attachment is a temporary file, and leaving it behind would fill the
	// uploads directory one scheduled send at a time.
	if ( $pcm_temp && file_exists( $pcm_temp ) ) {
		wp_delete_file( $pcm_temp );
	}

	return $pcm_sent;
}

/**
 * Inline styles only, and no <style> block: mail clients drop those, and the
 * CRM's stylesheet is not reachable from an inbox anyway.
 */
function pcm_crm_email_table( array $pcm_headings, array $pcm_rows ) {
	$pcm_out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;margin:0 0 22px;">';

	$pcm_out .= '<tr>';
	foreach ( $pcm_headings as $pcm_i => $pcm_heading ) {
		$pcm_align = $pcm_i ? 'right' : 'left';
		$pcm_out  .= '<th align="' . $pcm_align . '" style="padding:8px 10px;border-bottom:2px solid #1a1a1d;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#1a1a1d;">' . esc_html( $pcm_heading ) . '</th>';
	}
	$pcm_out .= '</tr>';

	foreach ( $pcm_rows as $pcm_row ) {
		$pcm_out .= '<tr>';
		foreach ( array_values( $pcm_row ) as $pcm_i => $pcm_cell ) {
			$pcm_align = $pcm_i ? 'right' : 'left';
			$pcm_weight = $pcm_i ? '400' : '700';
			$pcm_out .= '<td align="' . $pcm_align . '" style="padding:8px 10px;border-bottom:1px solid #e4e4e4;font-size:14px;font-weight:' . $pcm_weight . ';color:#46464a;">' . esc_html( $pcm_cell ) . '</td>';
		}
		$pcm_out .= '</tr>';
	}

	return $pcm_out . '</table>';
}

function pcm_crm_money( $pcm_value ) {
	return pcm_crm_currency_symbol() . number_format( (float) $pcm_value );
}

function pcm_crm_dashboard_email( array $pcm_schedule, array $pcm_args ) {
	$pcm_data  = pcm_crm_dashboard_data( $pcm_args );
	$pcm_tiles = $pcm_data['tiles'];

	$pcm_out = '<p style="margin:0 0 18px;">Here is the CRM dashboard as of ' . esc_html( date_i18n( get_option( 'date_format' ) ) ) . '.</p>';

	$pcm_out .= pcm_crm_email_table(
		array( __( 'Measure', 'pcm-crm' ), __( 'Value', 'pcm-crm' ) ),
		array(
			array( __( 'Open pipeline', 'pcm-crm' ), pcm_crm_money( $pcm_tiles['openValue'] ) . ' (' . $pcm_tiles['openCount'] . ')' ),
			array( __( 'Weighted pipeline', 'pcm-crm' ), pcm_crm_money( $pcm_tiles['weightedValue'] ) ),
			array( __( 'Won', 'pcm-crm' ), pcm_crm_money( $pcm_tiles['wonValue'] ) . ' (' . $pcm_tiles['wonCount'] . ')' ),
			array( __( 'Win rate', 'pcm-crm' ), $pcm_tiles['winRate'] . '%' ),
			array( __( 'Average sales cycle', 'pcm-crm' ), $pcm_tiles['cycleDays'] . ' days' ),
			array( __( 'Stalled deals', 'pcm-crm' ), $pcm_tiles['stalledCount'] . ' (' . $pcm_tiles['stallDays'] . '+ days)' ),
			array( __( 'Overdue activities', 'pcm-crm' ), (string) $pcm_tiles['overdueCount'] ),
			array( __( 'Accounts', 'pcm-crm' ), $pcm_tiles['accountCount'] . ' / ' . $pcm_tiles['contactCount'] . ' contacts' ),
		)
	);

	$pcm_stage_rows = array();
	foreach ( $pcm_data['charts']['pipelineByStage'] as $pcm_row ) {
		$pcm_stage_rows[] = array( $pcm_row['value'], (string) $pcm_row['count'], pcm_crm_money( $pcm_row['total'] ) );
	}

	$pcm_out .= '<h3 style="font-size:15px;color:#1a1a1d;margin:0 0 10px;">' . esc_html__( 'Pipeline by stage', 'pcm-crm' ) . '</h3>';
	$pcm_out .= pcm_crm_email_table( array( __( 'Stage', 'pcm-crm' ), __( 'Deals', 'pcm-crm' ), __( 'Value', 'pcm-crm' ) ), $pcm_stage_rows );

	$pcm_conversion = array();
	foreach ( $pcm_data['charts']['conversion'] as $pcm_row ) {
		$pcm_conversion[] = array( $pcm_row['stage'] . ' → ' . $pcm_row['next'], $pcm_row['deals'] . ' / ' . $pcm_row['moved'], $pcm_row['rate'] . '%' );
	}

	if ( $pcm_conversion ) {
		$pcm_out .= '<h3 style="font-size:15px;color:#1a1a1d;margin:0 0 10px;">' . esc_html__( 'Stage conversion', 'pcm-crm' ) . '</h3>';
		$pcm_out .= pcm_crm_email_table( array( __( 'Step', 'pcm-crm' ), __( 'Reached / moved on', 'pcm-crm' ), __( 'Rate', 'pcm-crm' ) ), $pcm_conversion );
	}

	return $pcm_out . pcm_crm_email_footer_link( 'pcm-crm' );
}

function pcm_crm_report_email( array $pcm_schedule, array $pcm_args ) {
	$pcm_object = $pcm_schedule['object'] ? $pcm_schedule['object'] : 'opportunities';
	$pcm_model  = PCM_CRM_REST::model( $pcm_object );

	if ( ! $pcm_model ) {
		throw new Exception( 'That report is for an object that no longer exists.' );
	}

	$pcm_report = pcm_crm_run_report( $pcm_object, $pcm_schedule['group_by'], $pcm_args );

	$pcm_out = '<p style="margin:0 0 18px;">' .
		esc_html( sprintf( /* translators: 1: record count, 2: object name */ __( '%1$d %2$s match this report.', 'pcm-crm' ), $pcm_report['total'], $pcm_object ) ) .
		'</p>';

	if ( $pcm_report['groups'] ) {
		$pcm_rows = array();

		foreach ( $pcm_report['groups'] as $pcm_group ) {
			$pcm_rows[] = array(
				'' !== $pcm_group['value'] ? $pcm_group['value'] : __( 'Unspecified', 'pcm-crm' ),
				(string) $pcm_group['count'],
				$pcm_group['total'] ? pcm_crm_money( $pcm_group['total'] ) : '—',
			);
		}

		$pcm_out .= pcm_crm_email_table(
			array( ucfirst( str_replace( '_', ' ', $pcm_schedule['group_by'] ) ), __( 'Records', 'pcm-crm' ), __( 'Value', 'pcm-crm' ) ),
			$pcm_rows
		);
	}

	// A summary, not the whole table: a thousand rows in an inbox helps nobody,
	// and the CSV attachment carries the full set.
	$pcm_sample = array_slice( $pcm_report['rows'], 0, 20 );
	$pcm_rows   = array();

	foreach ( $pcm_sample as $pcm_row ) {
		$pcm_rows[] = array(
			pcm_crm_record_label( $pcm_object, $pcm_row ),
			isset( $pcm_row['amount'] ) && null !== $pcm_row['amount'] ? pcm_crm_money( $pcm_row['amount'] ) : '',
		);
	}

	if ( $pcm_rows ) {
		$pcm_out .= '<h3 style="font-size:15px;color:#1a1a1d;margin:0 0 10px;">' .
			esc_html( count( $pcm_report['rows'] ) > 20 ? __( 'First 20 records', 'pcm-crm' ) : __( 'Records', 'pcm-crm' ) ) .
			'</h3>';
		$pcm_out .= pcm_crm_email_table( array( __( 'Record', 'pcm-crm' ), __( 'Amount', 'pcm-crm' ) ), $pcm_rows );
	}

	return $pcm_out . pcm_crm_email_footer_link( 'pcm-crm-reports' );
}

/**
 * How a record reads in one line.
 */
function pcm_crm_record_label( $pcm_object, array $pcm_row ) {
	if ( 'contacts' === $pcm_object ) {
		return pcm_crm_contact_name( $pcm_row );
	}

	if ( 'activities' === $pcm_object ) {
		return $pcm_row['subject'] ? $pcm_row['subject'] : $pcm_row['activity_type'];
	}

	return isset( $pcm_row['name'] ) ? $pcm_row['name'] : ( '#' . $pcm_row['id'] );
}

function pcm_crm_email_footer_link( $pcm_page ) {
	$pcm_url = set_url_scheme( admin_url( 'admin.php?page=' . $pcm_page ), 'https' );

	return '<p style="margin:24px 0 0;"><a href="' . esc_url( $pcm_url ) . '" style="color:#c94040;font-weight:700;">' .
		esc_html__( 'Open the CRM for the full picture', 'pcm-crm' ) . '</a></p>';
}

/**
 * Write the report's rows to a temporary CSV for attaching.
 *
 * Uses the same Salesforce header mapping the manual export does, so a
 * scheduled file and a downloaded one are the same file.
 */
function pcm_crm_schedule_csv( array $pcm_schedule, array $pcm_args ) {
	$pcm_object = $pcm_schedule['object'] ? $pcm_schedule['object'] : 'opportunities';
	$pcm_model  = PCM_CRM_REST::model( $pcm_object );

	if ( ! $pcm_model ) {
		return '';
	}

	$pcm_rows = $pcm_model->find( array_merge( $pcm_args, array( 'per_page' => 0, 'orderby' => 'id', 'order' => 'ASC' ) ) );
	$pcm_path = trailingslashit( get_temp_dir() ) . 'pcm-' . $pcm_object . '-' . gmdate( 'Y-m-d-His' ) . '.csv';

	$pcm_handle = fopen( $pcm_path, 'w' );

	if ( ! $pcm_handle ) {
		return '';
	}

	// The BOM is what stops Excel reading an accented name as Latin-1.
	fwrite( $pcm_handle, "ï»¿" );

	$pcm_map = $pcm_model->salesforce_map();
	fputcsv( $pcm_handle, array_merge( array( 'PCM_Id__c' ), array_values( $pcm_map ) ) );

	foreach ( $pcm_rows as $pcm_row ) {
		$pcm_line = array( $pcm_row['id'] );

		foreach ( array_keys( $pcm_map ) as $pcm_field ) {
			$pcm_line[] = isset( $pcm_row[ $pcm_field ] ) ? $pcm_row[ $pcm_field ] : '';
		}

		fputcsv( $pcm_handle, $pcm_line );
	}

	fclose( $pcm_handle );

	return $pcm_path;
}

pcm_crm_register_object( 'schedules', array(
	'model'  => 'pcm_crm_schedules',
	'label'  => 'Schedule',
	'plural' => 'Schedules',
) );
