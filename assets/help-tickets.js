/**
 * Help Tickets, on the staff side of the CRM.
 *
 * A separate small file rather than growing pm.js further — Help Tickets has
 * no relationship to project-type archetypes, which is what pm.js is mostly
 * organised around. Registers against window.PCM_CRM_App the same way pm.js
 * does, so nothing here runs unless the Projects module's script is enqueued,
 * which only happens while the module is switched on.
 */
(function (window, document) {
	'use strict';

	var app = window.PCM_CRM_App;
	if (!app) { return; }

	var el = app.helpers.el;
	var state = app.state;

	function statuses() {
		return (state.boot && state.boot.ticketStatuses) || ['To Do', 'In Progress', 'Client Testing', 'Client Approved'];
	}

	app.registerObject('help_tickets', {
		label: 'Help Ticket',
		plural: 'Help Tickets',
		title: function (row) { return row.subject || 'Ticket'; },
		kicker: function (row) { return row._project_id_name || 'Ticket'; },
		kickerLink: function (row) {
			return row.project_id ? { object: 'projects', id: row.project_id } : null;
		},
		// The four statuses read as a path the same way a deal's stages do —
		// pathBar()/moveToStage() in crm.js have no dependency on Opportunity
		// or Project concepts, so this reuses them outright. Client Approved is
		// marked 'closed' only for the path's visual done-tone; nothing server
		// side treats a ticket as closed.
		path: function (row) {
			return {
				field: 'status',
				current: row.status,
				stages: statuses().map(function (name) {
					return { name: name, closed: name === 'Client Approved', lost: false };
				})
			};
		},
		highlights: function (row) {
			return [
				{ label: 'Project', value: row._project_id_name, link: row.project_id ? { object: 'projects', id: row.project_id } : null },
				{ label: 'Contact', value: row._contact_id_name, link: row.contact_id ? { object: 'contacts', id: row.contact_id } : null },
				{ label: 'Status', value: row.status },
				{ label: 'Owner', value: row._owner_name }
			];
		},
		columns: [
			{ key: 'subject', label: 'Subject', strong: true },
			{ key: '_project_id_name', label: 'Project', link: 'project_id' },
			{ key: '_contact_id_name', label: 'Contact', link: 'contact_id' },
			{ key: 'status', label: 'Status', badge: true },
			{ key: 'last_modified_date', label: 'Updated', date: true }
		],
		filters: function () {
			return [
				{ key: 'status', label: 'Status', options: app.helpers.options(statuses(), true), blank: 'Any status' },
				{ key: 'project_id', label: 'Project', lookup: 'projects' }
			];
		},
		hints: function (name) {
			var hints = {};
			if (name === 'subject' || name === 'description') { hints.wide = true; }
			return hints;
		}
	});

	/**
	 * The comment thread as a record tab — fetched once, redrawn after every
	 * reply, badged by which side wrote it. is_client_comment is stamped
	 * server-side (pcm_crm_pm_stamp_comment() in model-ticket-comment.php), so
	 * this only ever reads it, never sets it.
	 */
	app.registerRecordTabs('help_tickets', function (record) {
		var node = el('div.pcm-crm-thread', {}, [el('p.pcm-crm-loading', { text: 'Loading…' })]);

		function load() {
			app.helpers.api('/pm/tickets/' + record.id + '/comments').then(function (comments) {
				app.helpers.clear(node);
				node.appendChild(threadPanel(record, comments, load));
			}).catch(function (error) {
				app.helpers.clear(node, el('div.pcm-crm-error', { text: 'Could not load comments: ' + error.message }));
			});
		}

		load();

		return [{ id: 'comments', label: 'Comments', node: node }];
	});

	function commentRow(comment, indented) {
		return el('div.pcm-crm-comment' + (indented ? '.pcm-crm-comment-reply' : ''), {}, [
			el('div.pcm-crm-comment-head', {}, [
				el('strong', { text: comment._author_name || (Number(comment.is_client_comment) ? 'Client' : 'Staff') }),
				el('span.pcm-crm-badge' + (Number(comment.is_client_comment) ? '' : '.pcm-crm-badge-open'), {
					text: Number(comment.is_client_comment) ? 'Client' : 'Staff'
				}),
				el('span.pcm-crm-muted', { text: app.helpers.formatDateTime(comment.created_date) })
			]),
			el('p.pcm-crm-comment-body', { text: comment.body })
		]);
	}

	function threadPanel(record, comments, reload) {
		var wrap = el('div.pcm-crm-thread-inner');
		var list = el('div.pcm-crm-comment-list');

		var top = comments.filter(function (c) { return !Number(c.parent_id); });

		if (!top.length) {
			list.appendChild(el('p.pcm-crm-related-empty', { text: 'No comments yet.' }));
		}

		top.forEach(function (comment) {
			list.appendChild(commentRow(comment));
			comments.filter(function (r) { return Number(r.parent_id) === Number(comment.id); })
				.forEach(function (reply) { list.appendChild(commentRow(reply, true)); });
		});

		var body = el('textarea', { placeholder: 'Reply on this ticket…', rows: '3' });
		var status = el('span.pcm-crm-muted');

		var form = el('div.pcm-crm-form-actions', {}, [
			el('button.pcm-btn.pcm-btn-primary.pcm-btn-sm', {
				type: 'button',
				text: 'Post reply',
				onclick: function (event) {
					if (!body.value.trim()) { return; }

					event.target.disabled = true;
					status.textContent = 'Sending…';

					app.helpers.api('/pm/tickets/' + record.id + '/comments', { method: 'POST', body: { body: body.value } })
						.then(function () { reload(); })
						.catch(function (error) {
							status.textContent = error.message;
							event.target.disabled = false;
						});
				}
			}),
			status
		]);

		wrap.appendChild(list);
		wrap.appendChild(el('div.pcm-crm-fields', {}, [el('div.pcm-crm-field.pcm-crm-field-wide', {}, [body])]));
		wrap.appendChild(form);

		return wrap;
	}

	/**
	 * Tickets hang off the project they belong to, the account they belong to,
	 * and the contact who raised them — three related-list tabs from one
	 * additive registration each, the same shape pm.js already uses for RAID
	 * and tasks on a project.
	 */
	app.registerChildTypes('projects', function (record) {
		return [{
			id: 'tickets', label: 'Help Tickets', object: 'help_tickets', newLabel: 'New Ticket',
			prefill: { project_id: record.id, status: 'To Do' }
		}];
	});

	app.registerChildTypes('accounts', function (record) {
		return [{
			id: 'tickets', label: 'Help Tickets', object: 'help_tickets', newLabel: 'New Ticket',
			prefill: { account_id: record.id, status: 'To Do' }
		}];
	});

	app.registerChildTypes('contacts', function (record) {
		return [{
			id: 'tickets', label: 'Help Tickets', object: 'help_tickets', newLabel: 'New Ticket',
			prefill: { contact_id: record.id, account_id: record.account_id, status: 'To Do' }
		}];
	});

	app.registerRelatedColumns('tickets', function (row) {
		return [
			{ text: row.subject, strong: true },
			{ badge: row.status },
			{ text: row._contact_id_name || '—' },
			{ text: app.helpers.formatDate(row.last_modified_date) }
		];
	});
})(window, document);
