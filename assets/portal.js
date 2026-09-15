/**
 * The client portal.
 *
 * A small, standalone vanilla-JS app against the /portal REST routes — no
 * framework, and deliberately no dependency on crm.js/pm.js: this is a
 * separate small app for a separate audience (a client, not staff), sharing
 * only the visual language via portal.css, which restates the CRM's palette
 * the way pcm_crm_email_wrapper() does for email rather than importing the
 * admin-only crm.css.
 */
(function (window, document) {
	'use strict';

	var cfg = window.PCM_CRM_PORTAL || {};
	var root = document.getElementById('pcm-portal-root');
	if (!root) { return; }

	var state = { me: null, project: 0, view: 'summary', ticket: 0 };

	/* ---------------------------------------------------------------------
	   DOM + API helpers — the same small shape crm.js uses, kept separate on
	   purpose so this file has no dependency on that one.
	   --------------------------------------------------------------------- */

	function el(spec, attrs, children) {
		var parts = spec.split('.');
		var node = document.createElement(parts.shift() || 'div');
		if (parts.length) { node.className = parts.join(' '); }

		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === null || value === undefined || value === false) { return; }
			if (key === 'text') { node.textContent = value; }
			else if (key.indexOf('on') === 0 && typeof value === 'function') { node.addEventListener(key.slice(2).toLowerCase(), value); }
			else if (value === true) { node.setAttribute(key, ''); }
			else { node.setAttribute(key, value); }
		});

		(children || []).forEach(function (child) {
			if (child === null || child === undefined || child === false) { return; }
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});

		return node;
	}

	function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }

	function api(path, options) {
		options = options || {};
		var url = cfg.root + path;

		if (options.query) {
			var pairs = Object.keys(options.query)
				.filter(function (key) { return options.query[key] !== undefined && options.query[key] !== ''; })
				.map(function (key) { return encodeURIComponent(key) + '=' + encodeURIComponent(options.query[key]); });
			if (pairs.length) { url += '?' + pairs.join('&'); }
		}

		return window.fetch(url, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: options.body ? JSON.stringify(options.body) : undefined
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) { throw new Error((data && data.message) || 'Something went wrong.'); }
				return data;
			});
		});
	}

	/**
	 * Upload one file, multipart — api()'s JSON body/Content-Type does not fit
	 * a raw file, and the browser has to set its own boundary'd Content-Type
	 * for FormData, so this is a separate small helper rather than an option
	 * on api().
	 */
	function apiUpload(path, file) {
		var body = new window.FormData();
		body.append('file', file);

		return window.fetch(cfg.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce },
			body: body
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) { throw new Error((data && data.message) || 'Something went wrong.'); }
				return data;
			});
		});
	}

	/**
	 * Every selected file, uploaded one after another rather than in parallel
	 * — a burst of simultaneous multipart POSTs is exactly the kind of thing a
	 * host's request-rate guard notices.
	 */
	function uploadAll(path, files) {
		return files.reduce(function (chain, file) {
			return chain.then(function () { return apiUpload(path, file); });
		}, Promise.resolve());
	}

	function formatDate(value) {
		if (!value) { return '—'; }
		var d = new Date(String(value).replace(' ', 'T'));
		return isNaN(d) ? String(value) : d.toLocaleDateString();
	}

	function hours(n) { return (Math.round(Number(n || 0) * 100) / 100) + 'h'; }

	function showError(container, error) {
		clear(container);
		container.appendChild(el('div.pcm-portal-error', { text: error.message || 'Something went wrong.' }));
	}

	/* ---------------------------------------------------------------------
	   Shell: header, project switcher, nav.
	   --------------------------------------------------------------------- */

	/**
	 * The account behind whichever project is currently selected — the portal
	 * kicker names the client's own organization rather than the generic
	 * "Client Portal" label, so it has to track the switcher.
	 */
	function currentProject() {
		var match = null;
		state.me.projects.forEach(function (project) {
			if (project.id === state.project) { match = project; }
		});
		return match;
	}

	function render() {
		clear(root);

		var shell = el('div.pcm-portal-shell');
		var header = el('div.pcm-portal-header');
		var body = el('div.pcm-portal-body');
		var current = currentProject();

		header.appendChild(el('div.pcm-portal-heading', {}, [
			el('h1', { text: state.me.contact_name || 'Your Portal' })
		]));
		header.appendChild(el('p.pcm-portal-kicker', { text: (current && current.account_name) || 'Client Portal' }));

		var projectRow = el('div.pcm-portal-projectrow');

		if (state.me.projects.length > 1) {
			var switcher = el('select.pcm-portal-switcher', {
				'aria-label': 'Project',
				onchange: function (event) {
					state.project = Number(event.target.value);
					state.view = 'summary';
					render();
				}
			});

			state.me.projects.forEach(function (project) {
				switcher.appendChild(el('option', {
					value: project.id,
					text: project.name + (project.account_name ? ' — ' + project.account_name : ''),
					selected: project.id === state.project
				}));
			});

			projectRow.appendChild(switcher);
			header.appendChild(projectRow);
		} else if (state.me.projects.length === 1) {
			projectRow.appendChild(el('p.pcm-portal-project-name', { text: state.me.projects[0].name }));
			header.appendChild(projectRow);
		}

		var nav = renderNav(body);

		shell.appendChild(header);
		shell.appendChild(nav);
		shell.appendChild(body);
		root.appendChild(shell);

		renderBody(body);
	}

	/**
	 * The tab strip, kept in its own function so a tab click can update both
	 * the active button and the body from one place — building it once inside
	 * render() and never touching it again left the Summary tab looking
	 * "selected" no matter which tab was actually showing.
	 */
	function renderNav(body) {
		var nav = el('nav.pcm-portal-nav');

		[
			{ id: 'summary', label: 'Summary' },
			{ id: 'raid', label: 'RAID Log' },
			{ id: 'documents', label: 'Documents' },
			{ id: 'tickets', label: 'Help Tickets' }
		].forEach(function (tab) {
			nav.appendChild(el('button.pcm-portal-tab' + (state.view === tab.id ? '.is-active' : ''), {
				type: 'button',
				text: tab.label,
				'data-tab': tab.id,
				onclick: function () {
					state.view = tab.id;
					state.ticket = 0;

					Array.prototype.forEach.call(nav.querySelectorAll('.pcm-portal-tab'), function (btn) {
						btn.classList.toggle('is-active', btn.getAttribute('data-tab') === tab.id);
					});

					renderBody(body);
				}
			}));
		});

		return nav;
	}

	function renderBody(body) {
		clear(body);
		body.appendChild(el('p.pcm-portal-loading', { text: 'Loading…' }));

		if (state.view === 'summary') { return loadSummary(body); }
		if (state.view === 'raid') { return loadRaid(body); }
		if (state.view === 'documents') { return loadDocuments(body); }
		if (state.view === 'tickets') { return state.ticket ? loadTicket(body) : loadTickets(body); }
	}

	/* ---------------------------------------------------------------------
	   Summary — hours only, never a rate or a dollar figure.
	   --------------------------------------------------------------------- */

	function meter(label, used, available) {
		var pct = available > 0 ? Math.min(100, Math.round((used / available) * 100)) : 0;
		var over = available > 0 && used > available;

		return el('div.pcm-portal-meter' + (over ? '.is-over' : ''), {}, [
			el('div.pcm-portal-meter-head', {}, [
				el('strong', { text: label }),
				el('span', { text: hours(used) + ' of ' + hours(available) })
			]),
			el('div.pcm-portal-meter-track', {}, [
				el('span.pcm-portal-meter-fill', { style: 'width:' + pct + '%' })
			])
		]);
	}

	function loadSummary(body) {
		api('/portal/summary', { query: { project_id: state.project } }).then(function (summary) {
			clear(body);

			var wrap = el('div.pcm-portal-card');

			if (summary.current_period) {
				wrap.appendChild(meter('This period', summary.current_period.used, summary.current_period.available));
				wrap.appendChild(el('p.pcm-portal-note', {
					text: 'Period runs ' + formatDate(summary.current_period.start) + ' to ' + formatDate(summary.current_period.end) + '.'
				}));
			}

			if (summary.budget_hours) { wrap.appendChild(meter('Hours', summary.logged_hours, summary.budget_hours)); }
			if (summary.estimate_hours) { wrap.appendChild(meter('Against the estimate', summary.logged_hours, summary.estimate_hours)); }

			var stats = el('dl.pcm-portal-stats');
			stats.appendChild(el('dt', { text: 'Hours logged' }));
			stats.appendChild(el('dd', { text: hours(summary.logged_hours) }));

			if (summary.next_milestone) {
				stats.appendChild(el('dt', { text: 'Next milestone' }));
				stats.appendChild(el('dd', { text: summary.next_milestone.name + (summary.next_milestone.due_date ? ' · ' + formatDate(summary.next_milestone.due_date) : '') }));
			}

			wrap.appendChild(stats);
			body.appendChild(wrap);
		}).catch(function (error) { showError(body, error); });
	}

	/* ---------------------------------------------------------------------
	   RAID — read only.
	   --------------------------------------------------------------------- */

	/**
	 * Every level word offered for Impact/Probability, or every status word —
	 * from cfg when the server sent them, falling back to the live data so an
	 * older cached bundle still gets a usable filter instead of an empty one.
	 */
	function raidFilterOptions(rows, field, fallback) {
		var fromConfig = field === 'status' ? cfg.raidStatuses : cfg.raidLevels;
		if (fromConfig && fromConfig.length) { return fromConfig; }

		var seen = [];
		rows.forEach(function (row) {
			if (row[field] && seen.indexOf(row[field]) === -1) { seen.push(row[field]); }
		});
		return seen.length ? seen : fallback;
	}

	function raidMatchesFilter(row, filters) {
		if (filters.status && row.status !== filters.status) { return false; }
		if (filters.impact && row.impact !== filters.impact) { return false; }
		if (filters.probability && row.probability !== filters.probability) { return false; }
		return true;
	}

	/**
	 * Sort options are a single field, not a separate field+direction pair —
	 * "Reported Date, newest first" is one choice a client makes, not two.
	 */
	var RAID_SORTS = {
		reported_desc: { field: 'raised_date', dir: -1 },
		reported_asc:  { field: 'raised_date', dir: 1 },
		resolved_desc: { field: 'resolved_date', dir: -1 },
		resolved_asc:  { field: 'resolved_date', dir: 1 }
	};

	var RAID_SORT_LABELS = [
		['reported_desc', 'Reported Date (newest first)'],
		['reported_asc', 'Reported Date (oldest first)'],
		['resolved_desc', 'Resolution Date (newest first)'],
		['resolved_asc', 'Resolution Date (oldest first)']
	];

	function sortRaid(rows, sortKey) {
		var sort = RAID_SORTS[sortKey];
		if (!sort) { return rows; }

		return rows.slice().sort(function (a, b) {
			var av = a[sort.field], bv = b[sort.field];
			if (!av && !bv) { return 0; }
			if (!av) { return 1; }
			if (!bv) { return -1; }
			return av < bv ? -sort.dir : (av > bv ? sort.dir : 0);
		});
	}

	function raidFilterRow(rows, filters, onChange) {
		var bar = el('div.pcm-portal-filters');

		function field(label, control) {
			return el('div.pcm-portal-filter', {}, [el('label', { text: label }), control]);
		}

		function select(label, current, options) {
			return el('select', {
				'aria-label': label,
				onchange: function (event) { current.set(event.target.value); onChange(); }
			}, options.map(function (opt) {
				return el('option', { value: opt.value, text: opt.text, selected: current.get() === opt.value });
			}));
		}

		function picklistSelect(label, key, options) {
			return field(label, select(label, {
				get: function () { return filters[key]; },
				set: function (value) { filters[key] = value; }
			}, [{ value: '', text: 'All ' + label }].concat(options.map(function (opt) { return { value: opt, text: opt }; }))));
		}

		bar.appendChild(picklistSelect('Status', 'status', raidFilterOptions(rows, 'status', [])));
		bar.appendChild(picklistSelect('Impact', 'impact', raidFilterOptions(rows, 'impact', [])));
		bar.appendChild(picklistSelect('Probability', 'probability', raidFilterOptions(rows, 'probability', [])));

		bar.appendChild(field('Sort By', select('Sort By', {
			get: function () { return filters.sort; },
			set: function (value) { filters.sort = value; }
		}, RAID_SORT_LABELS.map(function (pair) { return { value: pair[0], text: pair[1] }; })
		)));

		if (filters.status || filters.impact || filters.probability || filters.sort !== RAID_SORT_LABELS[0][0]) {
			bar.appendChild(el('button.pcm-portal-btn.pcm-portal-filters-clear', {
				type: 'button', text: 'Clear filters',
				onclick: function () {
					filters.status = ''; filters.impact = ''; filters.probability = '';
					filters.sort = RAID_SORT_LABELS[0][0];
					onChange();
				}
			}));
		}

		return bar;
	}

	function loadRaid(body) {
		api('/portal/raid', { query: { project_id: state.project } }).then(function (rows) {
			var filters = { status: '', impact: '', probability: '', sort: RAID_SORT_LABELS[0][0] };

			function paint() {
				clear(body);
				body.appendChild(raidFilterRow(rows, filters, paint));

				var visible = sortRaid(rows.filter(function (row) { return raidMatchesFilter(row, filters); }), filters.sort);

				if (!visible.length) {
					body.appendChild(el('p.pcm-portal-empty', { text: rows.length ? 'No RAID items match those filters.' : 'Nothing on the RAID log right now.' }));
					return;
				}

				var list = el('div.pcm-portal-list');
				visible.forEach(function (row) { list.appendChild(raidItem(row)); });
				body.appendChild(list);
			}

			paint();
		}).catch(function (error) { showError(body, error); });
	}

	function raidField(label, value) {
		if (value === null || value === undefined || value === '') { return null; }

		return el('div.pcm-portal-raid-field-pair', {}, [
			el('span.pcm-portal-raid-field-label', { text: label }),
			el('span.pcm-portal-raid-field-value', { text: value })
		]);
	}

	function raidItem(row) {
		var fields = el('div.pcm-portal-raid-fields');

		[
			raidField('Status', row.status),
			raidField('Impact', row.impact),
			raidField('Probability', row.probability),
			raidField('Reported', row.raised_date ? formatDate(row.raised_date) : ''),
			raidField('Review by', row.due_date ? formatDate(row.due_date) : ''),
			raidField('Resolved', row.resolved_date ? formatDate(row.resolved_date) : ''),
			raidField('Owner', row._owner_contact_id_name),
			raidField('Details', row.description),
			raidField('Mitigation', row.mitigation),
			raidField('Resolution', row.resolution)
		].forEach(function (field) { if (field) { fields.appendChild(field); } });

		return el('div.pcm-portal-raid-item', {}, [
			el('div.pcm-portal-raid-head', {}, [
				el('span.pcm-portal-badge', { text: row.raid_type }),
				el('strong', { text: row.title })
			]),
			fields
		]);
	}

	/* ---------------------------------------------------------------------
	   Documents — list and download, never a bare Media Library URL.
	   --------------------------------------------------------------------- */

	function documentUrl(row, disposition) {
		return cfg.root + '/portal/documents/' + row.id + '/download?disposition=' + disposition + '&_wpnonce=' + encodeURIComponent(cfg.nonce);
	}

	function documentPreviewNode(row) {
		var mime = row._mime_type || '';

		if (0 === mime.indexOf('image/')) {
			return el('img.pcm-portal-doc-preview-img', { src: documentUrl(row, 'inline'), alt: row.label || row._filename });
		}

		if ('application/pdf' === mime) {
			return el('iframe.pcm-portal-doc-preview-frame', { src: documentUrl(row, 'inline'), title: row.label || row._filename });
		}

		return el('p.pcm-portal-muted', { text: 'No preview available for this file type.' });
	}

	function loadDocuments(body) {
		api('/portal/documents', { query: { project_id: state.project } }).then(function (rows) {
			clear(body);
			rows = rows.filter(function (row) { return !row._missing; });

			if (!rows.length) {
				body.appendChild(el('p.pcm-portal-empty', { text: 'No documents have been shared yet.' }));
				return;
			}

			var list = el('div.pcm-portal-list');
			var preview = el('div.pcm-portal-card.pcm-portal-doc-preview');

			function showPreview(row) {
				clear(preview);
				preview.appendChild(el('div.pcm-portal-doc-preview-head', {}, [
					el('strong', { text: row.label || row._filename }),
					el('a.pcm-portal-btn', {
						href: documentUrl(row, 'attachment'),
						text: 'Download'
					})
				]));
				preview.appendChild(documentPreviewNode(row));
			}

			rows.forEach(function (row, index) {
				var rowButton = el('button.pcm-portal-row', {
					type: 'button',
					onclick: function () { showPreview(row); }
				}, [
					el('div.pcm-portal-row-body', {}, [
						el('strong', { text: row.label || row._filename }),
						el('span.pcm-portal-muted', { text: row._filename })
					])
				]);

				list.appendChild(rowButton);
				if (0 === index) { showPreview(row); }
			});

			body.appendChild(el('div.pcm-portal-doc-layout', {}, [list, preview]));
		}).catch(function (error) { showError(body, error); });
	}

	/* ---------------------------------------------------------------------
	   Help Tickets — list across every project, create, comment, set status.
	   --------------------------------------------------------------------- */

	function statusClass(status) { return 'is-' + String(status || '').toLowerCase().replace(/[^a-z]+/g, '-'); }

	function loadTickets(body) {
		api('/portal/tickets').then(function (rows) {
			clear(body);

			body.appendChild(el('button.pcm-portal-btn.pcm-portal-btn-primary', {
				type: 'button',
				text: 'New Ticket',
				onclick: function () { newTicketForm(body); }
			}));

			if (!rows.length) {
				body.appendChild(el('p.pcm-portal-empty', { text: 'No tickets yet.' }));
				return;
			}

			var list = el('div.pcm-portal-list');

			rows.forEach(function (row) {
				list.appendChild(el('button.pcm-portal-row', {
					type: 'button',
					onclick: function () { state.ticket = row.id; renderBody(body); }
				}, [
					el('span.pcm-portal-badge.' + statusClass(row.status), { text: row.status }),
					el('div.pcm-portal-row-body', {}, [
						el('strong', { text: row.subject }),
						el('span.pcm-portal-muted', { text: (row._project_id_name || '') + ' · ' + formatDate(row.last_modified_date) })
					])
				]));
			});

			body.appendChild(list);
		}).catch(function (error) { showError(body, error); });
	}

	function newTicketForm(body) {
		clear(body);

		var projectSelect = el('select', { 'aria-label': 'Project' });
		state.me.projects.forEach(function (project) {
			projectSelect.appendChild(el('option', { value: project.id, text: project.name }));
		});

		var subject = el('input', { type: 'text', placeholder: 'What do you need help with?', required: true });
		var description = el('textarea', { placeholder: 'Any detail that would help — steps or a link.', rows: '5' });
		var files = el('input', { type: 'file', multiple: true, accept: 'image/*,.pdf,.doc,.docx' });
		var status = el('p.pcm-portal-note');

		body.appendChild(el('form.pcm-portal-form', { onsubmit: function (e) { e.preventDefault(); } }, [
			el('label', { text: 'Project' }), projectSelect,
			el('label', { text: 'Subject' }), subject,
			el('label', { text: 'Details' }), description,
			el('label', { text: 'Attach files (optional)' }), files,
			status,
			el('div.pcm-portal-form-actions', {}, [
				el('button.pcm-portal-btn.pcm-portal-btn-primary', {
					type: 'button',
					text: 'Submit',
					onclick: function (event) {
						if (!subject.value.trim()) { status.textContent = 'A subject is needed.'; return; }

						event.target.disabled = true;
						status.textContent = 'Sending…';

						api('/portal/tickets', {
							method: 'POST',
							body: { project_id: Number(projectSelect.value), subject: subject.value, description: description.value }
						}).then(function (ticket) {
							var selected = Array.prototype.slice.call(files.files || []);

							if (!selected.length) { state.ticket = ticket.id; renderBody(body); return; }

							status.textContent = 'Uploading attachments…';

							// The ticket exists whether or not an attachment upload
							// fails, so a failed upload is surfaced but does not lose
							// the ticket the client just filed.
							uploadAll('/portal/tickets/' + ticket.id + '/attachments', selected)
								.catch(function (error) { window.alert('The ticket was created, but a file did not upload: ' + error.message); })
								.then(function () { state.ticket = ticket.id; renderBody(body); });
						}).catch(function (error) {
							status.textContent = error.message;
							event.target.disabled = false;
						});
					}
				}),
				el('button.pcm-portal-btn', { type: 'button', text: 'Cancel', onclick: function () { loadTickets(body); } })
			])
		]));
	}

	function loadTicket(body) {
		Promise.all([
			api('/portal/tickets/' + state.ticket),
			api('/portal/tickets/' + state.ticket + '/comments'),
			api('/portal/tickets/' + state.ticket + '/attachments')
		]).then(function (results) {
			clear(body);
			body.appendChild(ticketPanel(body, results[0], results[1], results[2]));
		}).catch(function (error) { showError(body, error); });
	}

	/**
	 * A ticket's attachments as a small row list — the same preview-on-click
	 * shape the Documents tab uses, reusing documentUrl()/documentPreviewNode()
	 * rather than a parallel implementation, since a ticket attachment is a
	 * project_documents row like any other (see model-project-document.php).
	 */
	function attachmentsBlock(rows) {
		if (!rows.length) { return null; }

		var list = el('div.pcm-portal-list');
		var preview = el('div.pcm-portal-card.pcm-portal-doc-preview');

		function showPreview(row) {
			clear(preview);
			preview.appendChild(el('div.pcm-portal-doc-preview-head', {}, [
				el('strong', { text: row.label || row._filename }),
				el('a.pcm-portal-btn', { href: documentUrl(row, 'attachment'), text: 'Download' })
			]));
			preview.appendChild(documentPreviewNode(row));
		}

		rows.forEach(function (row, index) {
			list.appendChild(el('button.pcm-portal-row', {
				type: 'button',
				onclick: function () { showPreview(row); }
			}, [
				el('div.pcm-portal-row-body', {}, [
					el('strong', { text: row.label || row._filename }),
					el('span.pcm-portal-muted', { text: row._filename })
				])
			]));

			if (0 === index) { showPreview(row); }
		});

		return el('div.pcm-portal-doc-layout', {}, [list, preview]);
	}

	function ticketPanel(body, ticket, comments, attachments) {
		var wrap = el('div.pcm-portal-ticket');

		wrap.appendChild(el('button.pcm-portal-back', {
			type: 'button',
			text: '← All tickets',
			onclick: function () { state.ticket = 0; renderBody(body); }
		}));

		wrap.appendChild(el('h2', { text: ticket.subject }));

		var statuses = (cfg.statuses && cfg.statuses.length) ? cfg.statuses : ['To Do', 'In Progress', 'Client Testing', 'Client Approved'];
		var statusRow = el('div.pcm-portal-status-row');

		statuses.forEach(function (name) {
			statusRow.appendChild(el('button.pcm-portal-status-btn' + (name === ticket.status ? '.is-current' : ''), {
				type: 'button',
				text: name,
				disabled: name === ticket.status,
				onclick: function () {
					api('/portal/tickets/' + ticket.id + '/status', { method: 'PATCH', body: { status: name } })
						.then(function () { loadTicket(body); })
						.catch(function (error) { window.alert(error.message); });
				}
			}));
		});

		wrap.appendChild(statusRow);

		if (ticket.description) { wrap.appendChild(el('p.pcm-portal-description', { text: ticket.description })); }

		var attachmentsNode = attachmentsBlock(attachments || []);
		if (attachmentsNode) {
			wrap.appendChild(el('h3', { text: 'Attachments' }));
			wrap.appendChild(attachmentsNode);
		}

		var thread = el('div.pcm-portal-thread');
		var byId = {};
		comments.forEach(function (c) { byId[c.id] = c; });

		comments.filter(function (c) { return !c.parent_id; }).forEach(function (c) {
			thread.appendChild(commentNode(c));
			comments.filter(function (r) { return Number(r.parent_id) === Number(c.id); }).forEach(function (r) {
				thread.appendChild(commentNode(r, true));
			});
		});

		wrap.appendChild(el('h3', { text: 'Comments' }));
		wrap.appendChild(thread);
		wrap.appendChild(replyForm(body, ticket.id));

		return wrap;
	}

	function commentNode(comment, indented) {
		return el('div.pcm-portal-comment' + (indented ? '.is-reply' : '') + (comment.is_client_comment ? '.is-mine' : '.is-staff'), {}, [
			el('div.pcm-portal-comment-head', {}, [
				el('strong', { text: comment.is_client_comment ? 'You' : 'PCM' }),
				el('span.pcm-portal-muted', { text: formatDate(comment.created_date) })
			]),
			el('p', { text: comment.body })
		]);
	}

	function replyForm(body, ticketId) {
		var text = el('textarea', { placeholder: 'Add a comment…', rows: '3' });
		var files = el('input', { type: 'file', multiple: true, accept: 'image/*,.pdf,.doc,.docx' });
		var status = el('span.pcm-portal-note');

		return el('form.pcm-portal-reply', { onsubmit: function (e) { e.preventDefault(); } }, [
			text,
			el('label', { text: 'Attach files (optional)' }), files,
			el('div.pcm-portal-form-actions', {}, [
				el('button.pcm-portal-btn.pcm-portal-btn-primary', {
					type: 'button',
					text: 'Post comment',
					onclick: function (event) {
						var selected = Array.prototype.slice.call(files.files || []);

						if (!text.value.trim() && !selected.length) { return; }

						event.target.disabled = true;
						status.textContent = 'Sending…';

						var posted = text.value.trim()
							? api('/portal/tickets/' + ticketId + '/comments', { method: 'POST', body: { body: text.value } })
							: Promise.resolve();

						posted
							.then(function () { return selected.length ? uploadAll('/portal/tickets/' + ticketId + '/attachments', selected) : null; })
							.then(function () { state.ticket = ticketId; loadTicket(body); })
							.catch(function (error) {
								status.textContent = error.message;
								event.target.disabled = false;
							});
					}
				}),
				status
			])
		]);
	}

	/* ---------------------------------------------------------------------
	   Boot.
	   --------------------------------------------------------------------- */

	function init() {
		api('/portal/me').then(function (me) {
			state.me = me;
			state.project = me.projects.length ? me.projects[0].id : 0;

			if (!me.projects.length) {
				root.appendChild(el('div.pcm-portal-error', { text: 'No project is set up for you yet. If you think this is a mistake, get in touch.' }));
				return;
			}

			render();
		}).catch(function (error) { showError(root, error); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
