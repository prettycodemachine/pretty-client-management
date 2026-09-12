<?php
/**
 * Parsing a typed duration into decimal hours.
 *
 * Its own field type rather than a decimal, because the decimal sanitiser
 * strips everything but digits, a dot and a minus — which turns "1:30" into
 * 130, a hundred and thirty hours, and does it silently. That stripping is
 * correct for a pasted "$12,500" and cannot be loosened, and it runs before
 * pcm_crm_before_insert, so no filter can rescue the value afterwards.
 *
 * Deliberately stricter than the decimal type, which accepts "1.2.3" as 1.2 and
 * quietly drops a leading plus. A time that cannot be read is worth rejecting:
 * the alternative is an invoice built on a guess.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Decimal hours from anything a person types into an hours box.
 *
 * Accepts 1.5, 1:30, :45, 90m, 1h, 1h30m, "1h 30", 1.5h. Returns null — not
 * zero — for blank, unreadable and negative input. Null means "no time
 * recorded", which is a different statement from "no time taken", and the
 * difference matters to a burn-down.
 *
 * @return float|null Rounded to two decimals.
 */
function pcm_crm_parse_hours( $pcm_value ) {
	if ( is_array( $pcm_value ) || is_object( $pcm_value ) ) {
		return null;
	}

	$pcm_raw = trim( (string) $pcm_value );

	if ( '' === $pcm_raw ) {
		return null;
	}

	// A leading minus is rejected rather than absolved: negative time is always
	// a typo or a misunderstanding, and silently flipping the sign would hide
	// whichever it was.
	if ( 0 === strpos( $pcm_raw, '-' ) ) {
		return null;
	}

	$pcm_raw = strtolower( str_replace( array( ' ', ',' ), '', $pcm_raw ) );

	// h:mm, and the bare :mm that a half-typed entry leaves behind.
	if ( preg_match( '/^(\d*):(\d{1,2})$/', $pcm_raw, $pcm_parts ) ) {
		$pcm_hours   = '' === $pcm_parts[1] ? 0 : (int) $pcm_parts[1];
		$pcm_minutes = (int) $pcm_parts[2];

		// Minutes past sixty are normalised rather than refused: "2:75" is an
		// arithmetic slip with an unambiguous meaning.
		return round( $pcm_hours + ( $pcm_minutes / 60 ), 2 );
	}

	// 1h30m, 1h30, 1h, 90m — and the h-only form that must not be read as 1.5.
	if ( preg_match( '/^(?:(\d+(?:\.\d+)?)h)?(?:(\d+(?:\.\d+)?)m?)?$/', $pcm_raw, $pcm_parts )
		&& ( '' !== $pcm_parts[1] || '' !== $pcm_parts[2] )
		&& false !== strpos( $pcm_raw, 'h' )
	) {
		$pcm_hours   = isset( $pcm_parts[1] ) && '' !== $pcm_parts[1] ? (float) $pcm_parts[1] : 0.0;
		$pcm_minutes = isset( $pcm_parts[2] ) && '' !== $pcm_parts[2] ? (float) $pcm_parts[2] : 0.0;

		return round( $pcm_hours + ( $pcm_minutes / 60 ), 2 );
	}

	// 90m.
	if ( preg_match( '/^(\d+(?:\.\d+)?)m$/', $pcm_raw, $pcm_parts ) ) {
		return round( ( (float) $pcm_parts[1] ) / 60, 2 );
	}

	// Plain decimal hours. Matched strictly, so "1.2.3" is refused rather than
	// read as 1.2.
	if ( preg_match( '/^\d+(?:\.\d+)?$/', $pcm_raw ) ) {
		return round( (float) $pcm_raw, 2 );
	}

	return null;
}

/**
 * Decimal hours back as h:mm, for a display that reads like a timesheet.
 */
function pcm_crm_format_hours( $pcm_hours ) {
	if ( null === $pcm_hours || '' === $pcm_hours ) {
		return '';
	}

	$pcm_total   = (int) round( (float) $pcm_hours * 60 );
	$pcm_minutes = $pcm_total % 60;

	return sprintf( '%d:%02d', (int) floor( $pcm_total / 60 ), $pcm_minutes );
}
