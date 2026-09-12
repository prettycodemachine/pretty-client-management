<?php
/**
 * WP-CLI wrappers around the sample data.
 *
 *   wp pcm-crm seed          create the sample set
 *   wp pcm-crm seed --reset  remove what exists, then create a fresh set
 *   wp pcm-crm unseed        remove it
 *   wp pcm-crm samples       install the shipped templates and sequence
 *
 * The generating and removing live in includes/sample-data.php, which the CRM
 * Settings screen calls too — one implementation, two ways in.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class PCM_CRM_Seed_Command {

	/**
	 * Create a sample CRM: accounts, contacts, opportunities and activities.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Remove the existing sample set first.
	 */
	public function seed( $args, $assoc_args ) {
		$this->guard();

		if ( ! empty( $assoc_args['reset'] ) ) {
			WP_CLI::log( sprintf( 'Removed %d existing sample records.', pcm_crm_delete_sample_data() ) );
		} elseif ( pcm_crm_has_sample_data() ) {
			WP_CLI::warning( 'Sample data already exists. Use --reset to replace it, or `wp pcm-crm unseed` first.' );
			return;
		}

		WP_CLI::log( 'Building…' );
		$pcm_counts = pcm_crm_create_sample_data();

		WP_CLI::success( sprintf(
			'%d accounts, %d contacts, %d opportunities, %d activities.',
			$pcm_counts['accounts'],
			$pcm_counts['contacts'],
			$pcm_counts['opportunities'],
			$pcm_counts['activities']
		) );
	}

	/**
	 * Remove every record flagged as sample data.
	 */
	public function unseed( $args, $assoc_args ) {
		WP_CLI::success( sprintf( 'Removed %d sample records.', pcm_crm_delete_sample_data() ) );
	}

	/**
	 * Install the shipped email templates and sequence.
	 */
	public function samples( $args, $assoc_args ) {
		$pcm_created = pcm_crm_install_sample_content();

		WP_CLI::success( sprintf(
			'%d templates and %d sequences created; anything already present was left alone.',
			$pcm_created['templates'],
			$pcm_created['sequences']
		) );
	}

	/**
	 * Creating hundreds of fake records in a live CRM is still a bad day, even
	 * though they are now flagged and removable. Deleting them is allowed
	 * anywhere, because cleaning up is always safe.
	 */
	private function guard() {
		if ( pcm_crm_seed_allowed() ) {
			return;
		}

		WP_CLI::error(
			'This looks like production (' . wp_parse_url( home_url(), PHP_URL_HOST ) . '). ' .
			'Refusing to create sample data. Set PCM_CRM_ALLOW_SEED in wp-config.php if this really is a test site.'
		);
	}
}

WP_CLI::add_command( 'pcm-crm', 'PCM_CRM_Seed_Command' );
