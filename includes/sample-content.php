<?php
/**
 * The templates and sequence the plugin ships with.
 *
 * Installed rather than merely offered, because an empty Templates screen is a
 * feature nobody tries. They are ordinary records once created — editable,
 * deletable, and not reinstalled behind your back: a template is matched by
 * name, so one that has been renamed or rewritten is left alone.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The shipped templates.
 *
 * Written plainly and short, because that is what gets replied to, and because
 * they are meant to be edited rather than sent as they stand.
 */
function pcm_crm_sample_templates() {
	return array(
		'inbound-first-reply' => array(
			'name'    => __( 'Inbound inquiry — first reply', 'pcm-crm' ),
			'subject' => 'Thanks for reaching out, {{contact.first_name}}',
			'body'    =>
				"Hi {{contact.first_name}},\n\n" .
				"Thanks for getting in touch. I read what you sent about {{account.name}}.\n\n" .
				"Before I suggest anything, it would help to understand where things stand today — what is working, what is not, and what prompted you to look now.\n\n" .
				"Would a short call this week or next suit you? Happy to work around your calendar.\n\n" .
				"Talk soon,\n{{sender.name}}",
		),
		'follow-up-one' => array(
			'name'    => __( 'Follow-up — no reply yet', 'pcm-crm' ),
			'subject' => 'Following up — {{account.name}}',
			'body'    =>
				"Hi {{contact.first_name}},\n\n" .
				"Just making sure my last note reached you. No rush at all if the timing is not right.\n\n" .
				"If it is easier, reply with a couple of times that work and I will send an invitation.\n\n" .
				"Best,\n{{sender.name}}",
		),
		'follow-up-close' => array(
			'name'    => __( 'Follow-up — closing the loop', 'pcm-crm' ),
			'subject' => 'Closing the loop',
			'body'    =>
				"Hi {{contact.first_name}},\n\n" .
				"I have not heard back, so I will assume the timing is not right and leave it there — no need to reply.\n\n" .
				"If things change, or you would like a second opinion on something later, my door is open.\n\n" .
				"All the best,\n{{sender.name}}",
		),
		'meeting-recap' => array(
			'name'    => __( 'After a call — recap and next step', 'pcm-crm' ),
			'subject' => 'Notes from our conversation',
			'body'    =>
				"Hi {{contact.first_name}},\n\n" .
				"Thanks for your time today. Writing down what I heard so we are working from the same page:\n\n" .
				"- \n- \n- \n\n" .
				"Next step on my side: \n\nNext step on yours: \n\n" .
				"If I have any of that wrong, say so and I will correct it.\n\n" .
				"Best,\n{{sender.name}}",
		),
		'proposal-follow-up' => array(
			'name'    => __( 'After a proposal', 'pcm-crm' ),
			'subject' => 'The proposal for {{account.name}}',
			'body'    =>
				"Hi {{contact.first_name}},\n\n" .
				"Checking in on the proposal I sent over. No pressure either way — I would rather you took the time to be sure.\n\n" .
				"If anything in it does not fit, tell me which part and I will rework it. That is usually quicker than starting again.\n\n" .
				"Best,\n{{sender.name}}",
		),
		're-engage' => array(
			'name'    => __( 'Re-engage a quiet contact', 'pcm-crm' ),
			'subject' => 'Still worth a conversation?',
			'body'    =>
				"Hi {{contact.first_name}},\n\n" .
				"It has been a while since we last spoke. Priorities move, so this may be long settled.\n\n" .
				"If it is still on the list, I am glad to pick it up. If not, I will stop cluttering your inbox.\n\n" .
				"Best,\n{{sender.name}}",
		),
	);
}

/**
 * The shipped sequence: what happens after someone uses the contact form.
 *
 * Three steps over a week, then it stops. Anything logged against the contact
 * — a reply you record, a call, a second form submission — stops it sooner,
 * which is the whole reason it is safe to leave running.
 */
function pcm_crm_sample_sequences() {
	return array(
		'new-inbound-lead' => array(
			'name'        => __( 'New inbound lead', 'pcm-crm' ),
			'description' => __( 'For someone who has just come in through the contact form: a reply, one nudge, and a polite close.', 'pcm-crm' ),
			'steps'       => array(
				array( 'template' => 'inbound-first-reply', 'delay_days' => 0 ),
				array( 'template' => 'follow-up-one', 'delay_days' => 3 ),
				array( 'template' => 'follow-up-close', 'delay_days' => 5 ),
			),
		),
	);
}

/**
 * Create anything that is missing.
 *
 * Matched by name rather than by a stored id, so a template that has been
 * renamed is treated as yours and left alone — reinstalling should never
 * overwrite someone's edits, and should never leave a second copy either.
 */
function pcm_crm_install_sample_content() {
	$pcm_created = array( 'templates' => 0, 'sequences' => 0 );
	$pcm_ids     = array();

	foreach ( pcm_crm_sample_templates() as $pcm_key => $pcm_template ) {
		$pcm_existing = pcm_crm_find_by_name( pcm_crm_templates(), $pcm_template['name'] );

		if ( $pcm_existing ) {
			$pcm_ids[ $pcm_key ] = $pcm_existing['id'];
			continue;
		}

		$pcm_id = pcm_crm_templates()->insert( array(
			'name'      => $pcm_template['name'],
			'subject'   => $pcm_template['subject'],
			'body'      => $pcm_template['body'],
			'is_active' => 1,
		) );

		if ( ! is_wp_error( $pcm_id ) ) {
			$pcm_ids[ $pcm_key ] = (int) $pcm_id;
			$pcm_created['templates']++;
		}
	}

	foreach ( pcm_crm_sample_sequences() as $pcm_sequence ) {
		if ( pcm_crm_find_by_name( pcm_crm_sequences(), $pcm_sequence['name'] ) ) {
			continue;
		}

		$pcm_steps = array();

		foreach ( $pcm_sequence['steps'] as $pcm_step ) {
			// A step whose template failed to create would be a sequence that
			// stops itself on the first run; better to ship it a step short.
			if ( empty( $pcm_ids[ $pcm_step['template'] ] ) ) {
				continue;
			}

			$pcm_steps[] = array(
				'template_id' => $pcm_ids[ $pcm_step['template'] ],
				'delay_days'  => $pcm_step['delay_days'],
			);
		}

		if ( ! $pcm_steps ) {
			continue;
		}

		$pcm_id = pcm_crm_sequences()->insert( array(
			'name'        => $pcm_sequence['name'],
			'description' => $pcm_sequence['description'],
			'steps'       => wp_json_encode( $pcm_steps ),
			'is_active'   => 1,
		) );

		if ( ! is_wp_error( $pcm_id ) ) {
			$pcm_created['sequences']++;
		}
	}

	return $pcm_created;
}

function pcm_crm_find_by_name( PCM_CRM_Model $pcm_model, $pcm_name ) {
	$pcm_rows = $pcm_model->find( array(
		'filters'  => array( 'name' => $pcm_name ),
		'per_page' => 1,
	) );

	return $pcm_rows ? $pcm_rows[0] : null;
}

/**
 * Install the samples once, on activation and on a schema upgrade.
 *
 * Guarded by an option rather than by whether the tables are empty: someone
 * who deletes every shipped template meant to delete them, and reinstalling on
 * the next upgrade would be the software arguing with them.
 */
function pcm_crm_maybe_install_sample_content() {
	if ( get_option( 'pcm_crm_samples_installed' ) ) {
		return;
	}

	pcm_crm_install_sample_content();
	update_option( 'pcm_crm_samples_installed', 1 );
}
