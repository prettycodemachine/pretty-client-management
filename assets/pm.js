/**
 * The project management module's screens.
 *
 * Registers against window.PCM_CRM_App rather than living inside crm.js, which
 * is already long enough — and which has to keep working with this file absent.
 * Nothing here runs unless the module is switched on, because the script is only
 * enqueued then.
 *
 * It declares pcm-crm as a dependency, so it executes after the app has defined
 * its surface and before the app's DOMContentLoaded listener fires. Registration
 * is therefore synchronous and needs no coordination.
 */
(function (window, document) {
	'use strict';

	var app = window.PCM_CRM_App;

	// Defensive rather than decorative: a stale cached crm.js served from behind
	// a CDN would otherwise take the whole admin page down with a TypeError
	// rather than merely missing the project screens.
	if (!app) { return; }

	var el = app.helpers.el;
	var state = app.state;

	/* Project types ---------------------------------------------------------
	   A type is chosen before anything else about a project, because it
	   decides what the rest of the form is: which fields apply, which are
	   required, which stages are legal. Its definition — and its archetype's —
	   arrive in the bootstrap as projectTypeDefs.
	   --------------------------------------------------------------------- */

	function typeDefs() {
		return (state.boot && state.boot.projectTypeDefs) || {};
	}

	function typeDef(key) {
		return typeDefs()[key] || null;
	}

	function typeLabel(key) {
		var def = typeDef(key);
		return def ? def.label : (key || '');
	}

	/**
	 * The keys of the types a field applies to, or null when it applies to all.
	 */
	function typesShowing(field) {
		var defs = typeDefs();
		var keys = Object.keys(defs);
		var showing = keys.filter(function (key) {
			return (defs[key].fields.hidden || []).indexOf(field) === -1;
		});

		return showing.length === keys.length ? null : showing;
	}

	function typesRequiring(field) {
		var defs = typeDefs();

		return Object.keys(defs).filter(function (key) {
			return (defs[key].fields.required || []).indexOf(field) !== -1;
		});
	}

	/**
	 * A new project's opening values for a type: the type's defaults, its
	 * first stage, and whatever the caller already knew — which wins.
	 */
	function openingValues(key, prefill) {
		var def = typeDef(key) || { stages: [], defaults: {} };
		var first = (def.stages || []).filter(function (stage) { return !Number(stage.is_closed); })[0];

		return Object.assign(
			{ health: 'Green', start_date: app.helpers.today() },
			def.defaults || {},
			prefill,
			{ project_type: key, stage_name: (prefill && prefill.stage_name) || (first ? first.name : '') }
		);
	}

	/**
	 * Which kind of project: one card per active type, its process and what
	 * that means, before the form.
	 */
	function chooseType(prefill, modal) {
		var defs = typeDefs();
		var archetypes = (state.boot && state.boot.archetypes) || {};

		// A deal whose type is mapped already answered the question.
		if (prefill.project_type && defs[prefill.project_type] && Number(defs[prefill.project_type].active)) {
			modal.proceed(openingValues(prefill.project_type, prefill));
			return;
		}

		var active = Object.keys(defs).filter(function (key) { return Number(defs[key].active); });

		if (active.length === 1) {
			modal.proceed(openingValues(active[0], prefill));
			return;
		}

		var list = el('div.pcm-crm-type-choices', { role: 'list' });

		active.forEach(function (key) {
			var def = defs[key];
			var archetype = archetypes[def.archetype] || {};

			list.appendChild(el('button.pcm-crm-type-choice', {
				type: 'button',
				role: 'listitem',
				onclick: function () { modal.proceed(openingValues(key, prefill)); }
			}, [
				el('span.pcm-crm-type-choice-icon.dashicons.dashicons-' + (def.icon || 'portfolio'), { 'aria-hidden': 'true' }),
				el('span.pcm-crm-type-choice-text', {}, [
					el('strong', { text: def.label }),
					el('span.pcm-crm-type-choice-process', { text: archetype.label || '' }),
					el('span.pcm-crm-type-choice-note', { text: def.description || archetype.description || '' })
				])
			]));
		});

		if (!active.length) {
			list.appendChild(el('p.pcm-crm-muted', { text: 'There are no project types to choose from. Add one under PCM Settings › Projects › Project Types.' }));
		}

		modal.show('What kind of project?', el('div', {}, [
			el('p.pcm-crm-type-intro', { text: 'The type decides the stages, the fields, and how time is logged. It can be changed later, if its stage is one the new type has.' }),
			list
		]));
	}

	app.registerObject('projects', {
		label: 'Project',
		plural: 'Projects',
		related: 'fetch',
		title: function (row) { return row.name; },
		kicker: function (row) { return typeLabel(row.project_type) || 'Project'; },
		kickerLink: function (row) {
			return row.account_id ? { object: 'accounts', id: row.account_id } : null;
		},
		beforeCreate: chooseType,
		// The stages this project's type moves through, not every stage there is.
		path: function (row) {
			var def = typeDef(row.project_type);

			return {
				field: 'stage_name',
				current: row.stage_name,
				stages: ((def && def.stages) || []).map(function (stage) {
					return { name: stage.name, closed: !!Number(stage.is_closed), lost: false };
				})
			};
		},
		// Related lists in the order this project's process reads them: a
		// retainer opens on its time, a build on its tasks.
		tabOrder: function (row) {
			var def = typeDef(row.project_type);
			var order = def ? (def.tabs || []).slice() : [];

			order.unshift('burn');
			return order;
		},
		highlights: function (row) {
			var def = typeDef(row.project_type);
			var archetype = def ? def.archetype : '';
			var money = app.helpers.money;
			var items = [
				{ label: 'Account', value: row._account_name, link: row.account_id ? { object: 'accounts', id: row.account_id } : null },
				{ label: 'Type', value: typeLabel(row.project_type) },
				{ label: 'Health', value: row.health }
			];

			if (archetype === 'retainer') {
				items.push({ label: 'Allotment', value: row.retainer_hours ? row.retainer_hours + 'h ' + String(row.retainer_period || '').toLowerCase() : '' });
			} else if (archetype === 'fixed') {
				items.push({ label: 'Budget', value: row.budget_amount ? money(row.budget_amount) : '' });
				items.push({ label: 'Ends', value: app.helpers.formatDate(row.end_date) });
			} else if (archetype === 'tm') {
				items.push({ label: 'Rate', value: row.default_bill_rate ? money(row.default_bill_rate) + '/h' : '' });
				items.push({ label: 'Cap', value: row.budget_amount ? money(row.budget_amount) : '' });
			} else {
				items.push({ label: 'Ends', value: app.helpers.formatDate(row.end_date) });
			}

			items.push({ label: 'Owner', value: row._owner_name });

			return items;
		},
		columns: [
			{ key: 'name', label: 'Project', strong: true },
			{ key: '_account_name', label: 'Account', link: 'account_id' },
			{ key: 'project_type', label: 'Type', render: function (row) { return typeLabel(row.project_type) || '—'; } },
			// A plain badge rather than the `stage` flag, which tones itself from
			// is_won/is_closed: a project has no is_won, so a finished one would
			// come out in the losing red.
			{ key: 'stage_name', label: 'Stage', badge: true },
			{ key: 'health', label: 'Health' },
			{ key: 'budget_amount', label: 'Budget', money: true },
			{ key: 'end_date', label: 'Ends', due: true },
			{ key: 'last_modified_date', label: 'Modified', date: true }
		],
		filters: function () {
			return [
				{ key: 'project_type', label: 'Type', options: app.helpers.options(state.boot.projectTypes || [], true), blank: 'Any type' },
				{ key: 'stage_name', label: 'Stage', options: app.helpers.options(state.boot.projectStages || [], true), blank: 'Any stage' },
				{ key: 'health', label: 'Health', options: app.helpers.options(state.boot.projectHealth || [], true), blank: 'Any health' },
				{ key: 'owner_id', label: 'Owner', options: app.helpers.ownerOptions() }
			];
		},
		hints: function (name) {
			var hints = {};

			if (name === 'owner_id') { hints.options = app.helpers.ownerOptions(); }
			if (name === 'name' || name === 'description' || name === 'health_note') { hints.wide = true; }

			// A field that only some processes use is hidden until the type is
			// one of them — the retainer allotment on a build would sit there
			// inviting a value nothing reads. Worked out from each type's own
			// definition, so a new type needs nothing added here.
			var showing = typesShowing(name);

			if (showing) {
				hints.showWhen = 'project_type';
				hints.showWhenOneOf = showing;
			}

			var requiring = typesRequiring(name);

			if (requiring.length) {
				hints.note = 'Required on ' + requiring.map(typeLabel).join(', ') + '.';
			}

			if (name === 'stage_name') { hints.ui = 'project-stage'; }

			return hints;
		}
	});

	/**
	 * The stage picklist, narrowed to the chosen type's stages.
	 */
	app.registerControl('project-stage', {
		wide: false,
		label: true,
		build: function (field, values) {
			var select = el('select', {
				id: 'pcm-crm-field-' + field.key,
				onchange: function (event) { values[field.key] = event.target.value; }
			});

			fillStages(select, values);
			return select;
		}
	});

	function fillStages(select, values) {
		var def = typeDef(values.project_type);
		var stages = def ? def.stages.map(function (stage) { return stage.name; }) : (state.boot.projectStages || []);

		// A stage the new type does not have is cleared to the type's first,
		// rather than left selected in a list that no longer offers it.
		if (stages.indexOf(values.stage_name) === -1) {
			values.stage_name = stages[0] || '';
		}

		app.helpers.clear(select);

		stages.forEach(function (name) {
			select.appendChild(el('option', { value: name, text: name, selected: name === values.stage_name }));
		});
	}

	app.registerDerived(function (key, values, scope) {
		if (key !== 'project_type' || !scope) { return; }

		var select = scope.querySelector('#pcm-crm-field-stage_name');
		if (select) { fillStages(select, values); }
	});

	/**
	 * What a project has burned against what it was given, drawn to suit its
	 * process: a retainer's periods, a budget's spend, internal hours.
	 */
	app.registerRecordTabs('projects', function (record) {
		var def = typeDef(record.project_type);
		var archetype = def ? def.archetype : '';
		var label = archetype === 'retainer' ? 'Burn-down' : (archetype === 'internal' ? 'Hours' : 'Budget');
		var node = el('div.pcm-crm-burn', {}, [el('p.pcm-crm-loading', { text: 'Loading…' })]);

		app.helpers.api('/pm/projects/' + record.id + '/summary').then(function (summary) {
			app.helpers.clear(node);
			node.appendChild(burnPanel(record, summary));
		}).catch(function (error) {
			app.helpers.clear(node, el('div.pcm-crm-error', { text: 'Could not load the summary: ' + error.message }));
		});

		return [{ id: 'burn', label: label, node: node }];
	});

	function meter(label, used, available, unit) {
		var pct = available > 0 ? Math.min(100, Math.round((used / available) * 100)) : 0;
		var over = available > 0 && used > available;
		var fmt = unit === '$' ? app.helpers.money : function (n) { return (Math.round(n * 100) / 100) + 'h'; };

		return el('div.pcm-crm-meter' + (over ? '.is-over' : (pct >= 85 ? '.is-near' : '')), {}, [
			el('div.pcm-crm-meter-head', {}, [
				el('strong', { text: label }),
				el('span', { text: fmt(used) + ' of ' + fmt(available) + (over ? ' — over by ' + fmt(used - available) : '') })
			]),
			el('div.pcm-crm-meter-track', {
				role: 'meter',
				'aria-valuemin': '0',
				'aria-valuemax': String(available),
				'aria-valuenow': String(used),
				'aria-label': label
			}, [el('span.pcm-crm-meter-fill', { style: 'width:' + pct + '%' })])
		]);
	}

	function stat(label, value) {
		return el('div.pcm-crm-highlight', {}, [
			el('span.pcm-crm-highlight-label', { text: label }),
			el('span.pcm-crm-highlight-value', { text: value })
		]);
	}

	function burnPanel(record, summary) {
		var money = app.helpers.money;
		var hours = function (n) { return (Math.round(Number(n || 0) * 100) / 100) + 'h'; };
		var wrap = el('div');

		if (summary.archetype === 'retainer') {
			if (summary.current_period) {
				wrap.appendChild(meter('This period', summary.current_period.used, summary.current_period.available, 'h'));
			} else {
				wrap.appendChild(el('p.pcm-crm-muted', { text: 'No retainer period covers today yet, so entries are not being counted against an allotment.' }));
			}

			if (summary.periods.length) {
				var list = el('div.pcm-crm-related-rows');

				summary.periods.forEach(function (period) {
					list.appendChild(el('div.pcm-crm-related-row.pcm-crm-period-row', {}, [
						el('span.pcm-crm-cell.pcm-crm-cell-primary', { text: app.helpers.formatDate(period.start) + ' – ' + app.helpers.formatDate(period.end) }),
						el('span.pcm-crm-cell', { text: hours(period.used) + ' used' }),
						el('span.pcm-crm-cell', { text: hours(period.available) + ' available' + (period.carried ? ' (' + hours(period.carried) + ' carried)' : '') }),
						el('span.pcm-crm-cell.pcm-crm-num', { text: (period.remaining < 0 ? 'Over ' + hours(-period.remaining) : hours(period.remaining) + ' left') })
					]));
				});

				wrap.appendChild(el('h4.pcm-crm-group-head', { text: 'Periods' }));
				wrap.appendChild(list);
			}
		}

		if (summary.archetype === 'fixed' || summary.archetype === 'tm') {
			if (summary.budget_amount) {
				wrap.appendChild(meter(summary.archetype === 'tm' ? 'Against the cap' : 'Budget spent', summary.billable_value, summary.budget_amount, '$'));
			}

			if (summary.budget_hours) {
				wrap.appendChild(meter('Hours', summary.logged_hours, summary.budget_hours, 'h'));
			}

			if (summary.archetype === 'fixed' && summary.estimate_hours) {
				wrap.appendChild(meter('Against task estimates', summary.logged_hours, summary.estimate_hours, 'h'));
			}
		}

		var stats = el('div.pcm-crm-highlights');

		stats.appendChild(stat('Logged', hours(summary.logged_hours)));

		if (summary.archetype !== 'internal') {
			stats.appendChild(stat('Billable', hours(summary.billable_hours)));
			stats.appendChild(stat('Billed value', money(summary.billable_value)));
			stats.appendChild(stat('Not yet invoiced', hours(summary.unbilled_hours) + ' · ' + money(summary.unbilled_value)));
		}

		stats.appendChild(stat('Cost', money(summary.cost_value)));

		if (summary.next_milestone) {
			stats.appendChild(stat('Next milestone', summary.next_milestone.name + (summary.next_milestone.due_date ? ' · ' + app.helpers.formatDate(summary.next_milestone.due_date) : '')));
		}

		wrap.insertBefore(stats, wrap.firstChild);

		return wrap;
	}

	/**
	 * The step back up to the project, shared by everything hanging off one.
	 */
	function projectLink(row) {
		return row.project_id ? { object: 'projects', id: row.project_id } : null;
	}

	app.registerObject('project_tasks', {
		label: 'Task',
		plural: 'Tasks',
		title: function (row) { return row.name || 'Task'; },
		kicker: function (row) { return row._project_name || 'Task'; },
		kickerLink: projectLink,
		highlights: function (row) {
			return [
				{ label: 'Status', value: row.status },
				{ label: 'Assigned To', value: row._assignee_name },
				{ label: 'Due', value: app.helpers.formatDate(row.due_date) },
				{ label: 'Estimate', value: row.estimated_hours ? row.estimated_hours + 'h' : '' }
			];
		},
		columns: [
			{ key: 'name', label: 'Task', strong: true },
			{ key: '_project_name', label: 'Project', link: 'project_id' },
			{ key: 'status', label: 'Status', badge: true },
			{ key: '_assignee_name', label: 'Assigned To' },
			{ key: 'due_date', label: 'Due', due: true }
		],
		filters: function () {
			return [
				{ key: 'status', label: 'Status', options: app.helpers.options(state.boot.taskStatuses || [], true), blank: 'Any status' },
				{ key: 'due_date', label: 'Due', range: 'date' }
			];
		},
		hints: function (name) {
			var hints = {};

			if (name === 'assignee_user_id') { hints.options = app.helpers.ownerOptions(); }
			if (name === 'name' || name === 'description') { hints.wide = true; }

			return hints;
		}
	});

	app.registerObject('project_milestones', {
		label: 'Milestone',
		plural: 'Milestones',
		title: function (row) { return row.name || 'Milestone'; },
		kicker: function (row) { return row._project_name || 'Milestone'; },
		kickerLink: projectLink,
		highlights: function (row) {
			return [
				{ label: 'Status', value: row.status },
				{ label: 'Due', value: app.helpers.formatDate(row.due_date) },
				{ label: 'Completed', value: app.helpers.formatDate(row.completed_date) }
			];
		},
		columns: [
			{ key: 'name', label: 'Milestone', strong: true },
			{ key: '_project_name', label: 'Project', link: 'project_id' },
			{ key: 'status', label: 'Status', badge: true },
			{ key: 'due_date', label: 'Due', due: true }
		],
		filters: function () {
			return [
				{ key: 'status', label: 'Status', options: app.helpers.options(state.boot.milestoneStatuses || [], true), blank: 'Any status' },
				{ key: 'due_date', label: 'Due', range: 'date' }
			];
		},
		hints: function (name) {
			var hints = {};
			if (name === 'name' || name === 'description') { hints.wide = true; }
			return hints;
		}
	});

	app.registerObject('project_raid', {
		label: 'RAID Entry',
		plural: 'RAID Log',
		title: function (row) { return row.title || 'RAID entry'; },
		kicker: function (row) { return row.raid_type || 'RAID'; },
		kickerLink: projectLink,
		highlights: function (row) {
			return [
				{ label: 'Project', value: row._project_name, link: row.project_id ? { object: 'projects', id: row.project_id } : null },
				{ label: 'Status', value: row.status },
				{ label: 'Severity', value: row.severity ? String(row.severity) : '' },
				{ label: 'Review By', value: app.helpers.formatDate(row.due_date) }
			];
		},
		columns: [
			{ key: 'title', label: 'Title', strong: true },
			{ key: 'raid_type', label: 'Kind', badge: true },
			{ key: '_project_name', label: 'Project', link: 'project_id' },
			{ key: 'status', label: 'Status', badge: true },
			{ key: 'severity', label: 'Severity', num: true },
			{ key: 'due_date', label: 'Review By', due: true }
		],
		filters: function () {
			return [
				{ key: 'raid_type', label: 'Kind', options: app.helpers.options(state.boot.raidTypes || [], true), blank: 'Any kind' },
				{ key: 'status', label: 'Status', options: app.helpers.options(state.boot.raidStatuses || [], true), blank: 'Any status' },
				{ key: 'impact', label: 'Impact', options: app.helpers.options(state.boot.raidLevels || [], true), blank: 'Any impact' }
			];
		},
		hints: function (name) {
			var hints = {};

			if (name === 'owner_id') { hints.options = app.helpers.ownerOptions(); }
			if (name === 'title' || name === 'description' || name === 'mitigation' || name === 'resolution') { hints.wide = true; }

			return hints;
		}
	});

	app.registerObject('project_roles', {
		label: 'Project Role',
		plural: 'Project Roles',
		title: function (row) { return row._person_name || 'Project role'; },
		kicker: function (row) { return row._party_label || 'Role'; },
		kickerLink: projectLink,
		highlights: function (row) {
			return [
				{ label: 'Project', value: row._project_name, link: row.project_id ? { object: 'projects', id: row.project_id } : null },
				{ label: 'Role', value: row.role },
				{ label: 'Organisation', value: row._org_name }
			];
		},
		columns: [
			{ key: '_person_name', label: 'Person', strong: true },
			{ key: '_party_label', label: 'Side', badge: true },
			{ key: 'role', label: 'Role' },
			{ key: '_project_name', label: 'Project', link: 'project_id' },
			{ key: '_org_name', label: 'Organisation' }
		],
		filters: function () {
			var parties = (state.boot && state.boot.partyTypes) || {};

			return [
				{ key: 'party_type', label: 'Side', options: [{ value: '', label: 'Any side' }].concat(
					Object.keys(parties).map(function (key) { return { value: key, label: parties[key] }; })
				) },
				{ key: 'role', label: 'Role', options: app.helpers.options(state.boot.projectRoles || [], true), blank: 'Any role' }
			];
		},
		hints: function (name) {
			var hints = {};

			if (name === 'user_id') { hints.options = app.helpers.ownerOptions(); }
			if (name === 'description') { hints.wide = true; }

			// Each side names its person differently, so only the id column that
			// side uses is offered: a team member internally, a contact for the
			// client, and a contact or the firm for a partner.
			if (name === 'user_id') { hints.showWhen = 'party_type'; hints.showWhenValue = 'internal'; }
			if (name === 'contact_id') { hints.showWhen = 'party_type'; hints.showWhenOneOf = ['client', 'partner']; }
			if (name === 'partner_account_id') { hints.showWhen = 'party_type'; hints.showWhenValue = 'partner'; }

			return hints;
		}
	});

	app.registerObject('time_entries', {
		label: 'Time Entry',
		plural: 'Time',
		title: function (row) {
			return (row._project_name || 'Time') + ' — ' + app.helpers.formatDate(row.entry_date);
		},
		kicker: function (row) { return Number(row.is_billable) ? 'Billable' : 'Internal'; },
		kickerLink: projectLink,
		highlights: function (row) {
			return [
				{ label: 'Project', value: row._project_name, link: row.project_id ? { object: 'projects', id: row.project_id } : null },
				{ label: 'Person', value: row._user_name },
				{ label: 'Date', value: row.entry_date },
				{ label: 'Hours', value: row.hours }
			];
		},
		columns: [
			{ key: 'entry_date', label: 'Date', date: true },
			{ key: '_project_name', label: 'Project', strong: true, link: 'project_id' },
			{ key: '_user_name', label: 'Person' },
			{ key: 'hours', label: 'Hours', num: true },
			// render() is handed to a cell as text, not as a node, so this returns
			// a string rather than a badge element.
			{ key: 'is_billable', label: 'Billable', render: function (row) {
				return Number(row.is_billable) ? 'Billable' : 'Internal';
			} },
			{ key: 'description', label: 'What you did' }
		],
		filters: function () {
			return [
				{ key: 'user_id', label: 'Person', options: app.helpers.ownerOptions() },
				{ key: 'is_billable', label: 'Billable', options: [
					{ value: '1', label: 'Billable' }, { value: '0', label: 'Internal' }
				], blank: 'Either' },
				{ key: 'entry_date', label: 'Date', range: 'date' }
			];
		},
		hints: function (name) {
			var hints = {};

			if (name === 'user_id') { hints.options = app.helpers.ownerOptions(); }
			if (name === 'description') { hints.wide = true; }

			return hints;
		}
	});

	/**
	 * An hours input.
	 *
	 * Typed as 1.5, 1:30, 90m or 1h30m and normalised on blur, so what is shown
	 * back is what will be stored. The server parses it again regardless — this
	 * is a courtesy, not the validation.
	 */
	app.registerControl('hours', {
		wide: false,
		label: false,
		build: function (field, values) {
			var id = 'pcm-crm-field-' + field.key;

			var input = el('input', {
				id: id,
				type: 'text',
				inputmode: 'decimal',
				placeholder: '1.5 or 1:30',
				value: values[field.key] === null || values[field.key] === undefined ? '' : values[field.key],
				oninput: function (event) { values[field.key] = event.target.value; },
				onblur: function (event) {
					var parsed = roundHours(parseHours(event.target.value));

					if (parsed === null) { return; }

					event.target.value = parsed;
					values[field.key] = parsed;
				}
			});

			return el('span', {}, [el('label', { for: id, text: field.label }), input]);
		}
	});

	/**
	 * The browser's half of pcm_crm_parse_hours(), deliberately kept in step
	 * with it. Returns null for anything it cannot read, which leaves the typed
	 * text alone for the server to reject with a sentence.
	 */
	function parseHours(value) {
		var raw = String(value === null || value === undefined ? '' : value)
			.trim().toLowerCase().replace(/[\s,]/g, '');

		if (!raw || raw.charAt(0) === '-') { return null; }

		var parts = raw.match(/^(\d*):(\d{1,2})$/);
		if (parts) {
			return round2((parts[1] === '' ? 0 : Number(parts[1])) + Number(parts[2]) / 60);
		}

		parts = raw.match(/^(\d+(?:\.\d+)?)m$/);
		if (parts) { return round2(Number(parts[1]) / 60); }

		if (raw.indexOf('h') !== -1) {
			parts = raw.match(/^(?:(\d+(?:\.\d+)?)h)(?:(\d+(?:\.\d+)?)m?)?$/);
			if (parts) {
				return round2(Number(parts[1] || 0) + Number(parts[2] || 0) / 60);
			}
			return null;
		}

		if (/^\d+(?:\.\d+)?$/.test(raw)) { return round2(Number(raw)); }

		return null;
	}

	function round2(n) { return Math.round(n * 100) / 100; }

	/**
	 * Round up to the increment set under PCM Settings › Projects › Time Entry, so a
	 * 20-minute call on a quarter-hour team is 0.5 rather than 0.33.
	 */
	function roundHours(hours, increment) {
		if (hours === null || hours === undefined) { return null; }

		var step = increment === undefined
			? Number((state.boot && state.boot.timeSettings && state.boot.timeSettings.increment) || 0)
			: Number(increment);

		if (!step) { return hours; }

		return round2(Math.ceil(round2(hours / step) - 1e-9) * step);
	}

	/**
	 * Projects hang off the account they are for and the opportunity they came
	 * from. Registered as additions, so an Account keeps its Contacts,
	 * Opportunities and Activities and gains a fourth list.
	 */
	app.registerChildTypes('accounts', function (record) {
		return [{
			id: 'projects',
			label: 'Projects',
			object: 'projects',
			newLabel: 'New Project',
			prefill: { account_id: record.id, health: 'Green' }
		}];
	});

	app.registerChildTypes('opportunities', function (record) {
		return [{
			id: 'projects',
			label: 'Projects',
			object: 'projects',
			newLabel: 'New Project',
			// Everything the deal already knows, so delivery starts from what
			// was sold rather than from a blank form: the account, the deal
			// itself, the amount as the budget, and the type mapped across.
			prefill: projectFromOpportunity(record)
		}];
	});

	/**
	 * A project's opening values, taken from the opportunity it came from.
	 *
	 * The project type is always left blank rather than guessed from the deal:
	 * the type decides which stages are legal, so guessing it wrong means the
	 * stage picklist offers the wrong lifecycle. A blank type is what makes the
	 * New Project chooser ask.
	 */
	function projectFromOpportunity(row) {
		return {
			account_id: row.account_id || 0,
			opportunity_id: row.id,
			name: row.name || '',
			project_type: '',
			budget_amount: row.amount || null,
			health: 'Green',
			start_date: app.helpers.today()
		};
	}

	/**
	 * How a project reads in someone else's related list.
	 */
	app.registerRelatedColumns('projects', function (row) {
		return [
			{ text: row.name, strong: true },
			{ badge: row.stage_name, tone: row.is_closed ? 'won' : 'open' },
			{ text: typeLabel(row.project_type) || '—' },
			{ text: app.helpers.money(row.budget_amount), num: true }
		];
	});

	app.registerRelatedColumns('tasks', function (row) {
		return [
			{ text: row.name, strong: true },
			{ badge: row.status },
			{ text: row._assignee_name || '—' },
			{ text: app.helpers.formatDate(row.due_date) }
		];
	});

	app.registerRelatedColumns('milestones', function (row) {
		return [
			{ text: row.name, strong: true },
			{ badge: row.status },
			{ text: app.helpers.formatDate(row.due_date) }
		];
	});

	app.registerRelatedColumns('raid', function (row) {
		return [
			{ text: row.title, strong: true },
			{ badge: row.raid_type },
			{ text: row.status || '—' },
			// Worst first is how the list is ordered, so the score has to be
			// visible or the ordering looks arbitrary.
			{ text: row.severity ? String(row.severity) : '—', num: true }
		];
	});

	app.registerRelatedColumns('roles', function (row) {
		return [
			{ text: row._person_name || '—', strong: true },
			{ badge: row._party_label || row.party_type },
			{ text: row.role || '—' },
			{ text: row._org_name || '—' }
		];
	});

	app.registerRelatedColumns('time', function (row) {
		return [
			{ text: app.helpers.formatDate(row.entry_date), strong: true },
			{ text: row._user_name || '—' },
			{ text: String(row.hours), num: true },
			{ text: row.description || '—' }
		];
	});

	/**
	 * What hangs off a project, and how a new child is linked back to it.
	 */
	app.registerChildTypes('projects', function (record, helpers) {
		return [
			{
				id: 'tasks',
				label: 'Task',
				object: 'project_tasks',
				prefill: { project_id: record.id, status: 'Not Started' }
			},
			{
				id: 'raid',
				label: 'RAID Entry',
				object: 'project_raid',
				prefill: { project_id: record.id, raid_type: 'Risk', status: 'Open', probability: 'Medium', impact: 'Medium' }
			},
			{
				id: 'milestones',
				label: 'Milestone',
				object: 'project_milestones',
				prefill: { project_id: record.id, status: 'Planned' }
			},
			{
				id: 'roles',
				label: 'Project Role',
				object: 'project_roles',
				prefill: { project_id: record.id, party_type: 'internal' }
			},
			{
				id: 'time',
				label: 'Time Entry',
				object: 'time_entries',
				prefill: { project_id: record.id, is_billable: 1, entry_date: app.helpers.today() }
			},
			{
				id: 'activities',
				label: 'Activity',
				object: 'activities',
				prefill: helpers.activity({ what_type: 'project', what_id: record.id })
			}
		];
	});

	/* Time entry, shaped by the project ----------------------------------------
	   Which fields a time entry needs depends on the project it is logged
	   against: a build wants the task, a T&M engagement a rate, internal work
	   no billing at all. Once a project is chosen the form asks the server what
	   it is about to be counted against, says so in a line at the top, and hides
	   or marks the fields that follow from it. The server enforces the same
	   rules on save; this is so nobody has to find out that way.
	   --------------------------------------------------------------------- */

	var contextSeq = 0;

	function describeContext(context) {
		var hours = function (n) { return round2(Number(n || 0)) + 'h'; };
		var parts = [];

		if (context.type_label) { parts.push(context.type_label); }

		if (context.rules.resolves_period) {
			parts.push(context.period
				? 'This period: ' + hours(context.period.used) + ' of ' + hours(context.period.available) + ' used, ' +
					(context.period.remaining < 0 ? hours(-context.period.remaining) + ' over' : hours(context.period.remaining) + ' left')
				: 'No retainer period covers this date, so it will not count against an allotment');
		}

		if (context.task) {
			parts.push(context.task.name + ': ' + hours(context.task.logged) + ' logged' +
				(context.task.estimated ? ' of ' + hours(context.task.estimated) + ' estimated' : ''));
		} else if (context.rules.task_required) {
			parts.push('Choose the task this was for');
		}

		if (context.rules.rate_required && context.default_rate) {
			parts.push('Default rate ' + app.helpers.money(context.default_rate) + '/h');
		}

		return parts.join(' · ');
	}

	function toggleField(scope, key, show) {
		var wrap = scope.querySelector('[data-field="' + key + '"]');
		if (wrap) { wrap.hidden = !show; }
	}

	function markRequired(scope, key, required) {
		var wrap = scope.querySelector('[data-field="' + key + '"]');
		var label = wrap && wrap.querySelector('label');

		if (!label) { return; }

		var text = String(label.textContent || '').replace(/ \*$/, '');
		label.textContent = required ? text + ' *' : text;
	}

	function applyTimeContext(values, scope, context) {
		var line = scope.querySelector('.pcm-crm-time-context');

		if (!line) {
			line = el('p.pcm-crm-time-context', { role: 'status' });
			scope.insertBefore(line, scope.firstChild);
		}

		if (!context) {
			line.textContent = 'Choose a project, and this will show what the time counts against.';
			['is_billable', 'bill_rate', 'cost_rate', 'invoice_ref'].forEach(function (key) { toggleField(scope, key, true); });
			markRequired(scope, 'task_id', false);
			markRequired(scope, 'description', false);
			return;
		}

		var rules = context.rules || {};
		var billing = context.archetype !== 'internal';

		line.textContent = describeContext(context);

		// Internal work is never billed, so none of the billing fields apply;
		// a locked default is not a choice, so its box goes too.
		toggleField(scope, 'is_billable', billing && !Number(rules.billable_locked));
		['bill_rate', 'cost_rate', 'invoice_ref'].forEach(function (key) { toggleField(scope, key, billing); });

		if (Number(rules.billable_locked)) { values.is_billable = Number(rules.billable_default) ? 1 : 0; }

		markRequired(scope, 'task_id', !!Number(rules.task_required));
		markRequired(scope, 'description', !!Number(rules.description_required));

		var rate = scope.querySelector('#pcm-crm-field-bill_rate');
		if (rate && context.default_rate) { rate.setAttribute('placeholder', String(context.default_rate)); }
	}

	app.registerDerived(function (key, values, scope) {
		if (!scope || !scope.dataset || scope.dataset.object !== 'time_entries') { return; }
		if (key !== '' && key !== 'project_id' && key !== 'task_id' && key !== 'entry_date') { return; }

		var projectId = Number(values.project_id);
		var mine = ++contextSeq;

		if (!projectId) {
			applyTimeContext(values, scope, null);
			return;
		}

		app.helpers.api('/pm/time-context', {
			query: { project_id: projectId, task_id: Number(values.task_id) || '', date: values.entry_date || '' }
		}).then(function (context) {
			if (mine === contextSeq) { applyTimeContext(values, scope, context); }
		}).catch(function () {
			if (mine === contextSeq) { applyTimeContext(values, scope, null); }
		});
	});

	/* Timesheet ---------------------------------------------------------------
	   A week at a glance: a row per project (and task), a column per day, hours
	   typed straight into the grid. Each row follows its project's type — a
	   fixed-scope row needs its task before its cells open, an internal row is
	   marked non-billable and asks what the time was for.

	   A cell holding one entry edits that entry; an empty cell creates one; a
	   cell holding several is read-only here, because splitting a typed total
	   back across entries would be a guess. Clearing a one-entry cell deletes
	   the entry, to the Recycle Bin like any other.
	   --------------------------------------------------------------------- */

	var sheet = { week: '', user: 0, rows: [], projects: [], entries: [], dirty: {} };

	function isoDate(date) {
		return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
	}

	function parseIso(value) {
		var parts = String(value).split('-').map(Number);
		return new Date(parts[0], parts[1] - 1, parts[2], 12);
	}

	function weekStart(value, startsOn) {
		var date = parseIso(value);
		var back = (date.getDay() - Number(startsOn) + 7) % 7;
		date.setDate(date.getDate() - back);
		return isoDate(date);
	}

	function addDays(value, days) {
		var date = parseIso(value);
		date.setDate(date.getDate() + days);
		return isoDate(date);
	}

	function weekDays(start) {
		var out = [];
		for (var i = 0; i < 7; i++) { out.push(addDays(start, i)); }
		return out;
	}

	function rowKey(projectId, taskId) {
		return Number(projectId) + ':' + (Number(taskId) || 0);
	}

	/**
	 * Group a week's entries into rows and cells. Pure, so the tests can hold it
	 * to the rules without a DOM.
	 */
	function buildSheet(entries, days, extraRows) {
		var rows = {};
		var order = [];

		function ensure(projectId, taskId, seed) {
			var key = rowKey(projectId, taskId);

			if (!rows[key]) {
				rows[key] = Object.assign({ key: key, project_id: Number(projectId), task_id: Number(taskId) || 0, project_name: '', task_name: '', project_type: '', cells: {} }, seed || {});
				days.forEach(function (day) { rows[key].cells[day] = []; });
				order.push(key);
			}

			return rows[key];
		}

		entries.forEach(function (entry) {
			var row = ensure(entry.project_id, entry.task_id, {
				project_name: entry._project_name || entry._project_id_name || '',
				task_name: entry._task_id_name || ''
			});

			if (row.cells[entry.entry_date]) { row.cells[entry.entry_date].push(entry); }
		});

		(extraRows || []).forEach(function (extra) {
			ensure(extra.project_id, extra.task_id, extra);
		});

		return order.map(function (key) { return rows[key]; });
	}

	function cellHours(entries) {
		return round2(entries.reduce(function (sum, entry) { return sum + Number(entry.hours || 0); }, 0));
	}

	function projectRules(projectId) {
		var project = sheet.projects.filter(function (p) { return Number(p.id) === Number(projectId); })[0];
		var def = project ? typeDef(project.project_type) : null;

		return { project: project, def: def, rules: def ? def.time : {} };
	}

	function renderTimesheet() {
		var mount = app.dom();
		var startsOn = (state.boot && state.boot.weekStartsOn) || 0;

		if (!sheet.week) { sheet.week = weekStart(app.helpers.today(), startsOn); }
		if (!sheet.user) { sheet.user = Number((window.PCM_CRM && window.PCM_CRM.currentUser) || 0); }

		app.helpers.clear(mount.filters);
		app.helpers.clear(mount.actions);

		var person = el('select', {
			'aria-label': 'Person',
			onchange: function (event) { sheet.user = Number(event.target.value); loadTimesheet(); }
		});

		app.helpers.ownerOptions().filter(function (o) { return o.value; }).forEach(function (option) {
			person.appendChild(el('option', { value: option.value, text: option.label, selected: Number(option.value) === sheet.user }));
		});

		mount.filters.appendChild(el('div.pcm-crm-sheet-nav', {}, [
			el('button.pcm-btn.pcm-btn-sm.pcm-btn-quiet', { type: 'button', text: '‹ Previous week', onclick: function () { moveWeek(-7); } }),
			el('button.pcm-btn.pcm-btn-sm.pcm-btn-quiet', { type: 'button', text: 'This week', onclick: function () {
				if (!confirmLeave()) { return; }
				sheet.week = weekStart(app.helpers.today(), startsOn);
				sheet.rows = [];
				loadTimesheet();
			} }),
			el('button.pcm-btn.pcm-btn-sm.pcm-btn-quiet', { type: 'button', text: 'Next week ›', onclick: function () { moveWeek(7); } }),
			el('label.pcm-crm-sheet-person', {}, ['Person ', person])
		]));

		mount.actions.appendChild(el('button.pcm-btn.pcm-btn-quiet', { type: 'button', text: 'Copy last week’s rows', onclick: copyLastWeek }));
		mount.actions.appendChild(el('a.pcm-btn.pcm-btn-quiet', { href: app.helpers.screenUrl('pcm-crm-time'), text: 'All entries' }));

		loadTimesheet();
	}

	function confirmLeave() {
		return !Object.keys(sheet.dirty).length || window.confirm('Discard the hours you have not saved?');
	}

	function moveWeek(days) {
		if (!confirmLeave()) { return; }

		sheet.week = addDays(sheet.week, days);
		sheet.rows = [];
		loadTimesheet();
	}

	function loadTimesheet() {
		var mount = app.dom();
		var days = weekDays(sheet.week);

		sheet.dirty = {};
		app.helpers.clear(mount.body, el('p.pcm-crm-loading', { text: 'Loading…' }));

		var entries = app.helpers.api('/time_entries', {
			query: {
				per_page: 500,
				orderby: 'entry_date',
				order: 'ASC',
				filters: { user_id: sheet.user, entry_date: { min: days[0], max: days[6] } }
			}
		});

		var projects = sheet.projects.length
			? Promise.resolve({ items: sheet.projects })
			: app.helpers.api('/projects', { query: { per_page: 500, orderby: 'name', order: 'ASC', filters: { is_closed: 0 } } });

		Promise.all([entries, projects]).then(function (results) {
			sheet.entries = results[0].items || [];
			sheet.projects = results[1].items || [];
			drawTimesheet();
		}).catch(app.helpers.showError);
	}

	function drawTimesheet() {
		var mount = app.dom();
		var days = weekDays(sheet.week);
		var rows = buildSheet(sheet.entries, days, sheet.rows);
		var today = app.helpers.today();

		var head = el('tr', {}, [el('th', { text: 'Project / task' })]);

		days.forEach(function (day) {
			var date = parseIso(day);
			head.appendChild(el('th.pcm-crm-num' + (day === today ? '.is-today' : ''), {}, [
				el('span.pcm-crm-sheet-dow', { text: date.toLocaleDateString(undefined, { weekday: 'short' }) }),
				el('span.pcm-crm-sheet-date', { text: date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) })
			]));
		});

		head.appendChild(el('th.pcm-crm-num', { text: 'Total' }));

		var body = el('tbody');
		var dayTotals = {};
		days.forEach(function (day) { dayTotals[day] = 0; });

		rows.forEach(function (row) {
			var info = projectRules(row.project_id);
			var rules = info.rules || {};
			var needsTask = !!Number(rules.task_required) && !row.task_id;
			var project = info.project;

			row.project_name = row.project_name || (project ? project.name : 'Project #' + row.project_id);
			row.project_type = project ? project.project_type : row.project_type;

			var label = el('td.pcm-crm-sheet-label', {}, [
				app.helpers.recordLink ? app.helpers.recordLink('projects', row.project_id, row.project_name, { icon: false }) : el('strong', { text: row.project_name }),
				row.task_id ? el('span.pcm-crm-sheet-task', { text: row.task_name || 'Task #' + row.task_id }) : null,
				needsTask ? taskPicker(row) : null,
				info.def ? el('span.pcm-crm-sheet-type', { text: info.def.label + (info.def.archetype === 'internal' ? ' · non-billable' : '') }) : null,
				Number(rules.description_required) ? noteInput(row) : null
			]);

			var tr = el('tr', {}, [label]);
			var rowTotal = 0;

			days.forEach(function (day) {
				var entries = row.cells[day] || [];
				var total = cellHours(entries);
				var key = row.key + '@' + day;

				rowTotal += total;
				dayTotals[day] += total;

				if (entries.length > 1) {
					tr.appendChild(el('td.pcm-crm-num.pcm-crm-sheet-many', {
						title: entries.length + ' entries — edit them under All entries'
					}, [String(total)]));
					return;
				}

				var input = el('input.pcm-crm-sheet-cell', {
					type: 'text',
					inputmode: 'decimal',
					'aria-label': row.project_name + ', ' + day,
					value: sheet.dirty[key] !== undefined ? sheet.dirty[key].raw : (total ? String(total) : ''),
					disabled: needsTask,
					title: needsTask ? 'Choose the task first — time on this project is logged against one.' : null,
					oninput: function (event) {
						sheet.dirty[key] = { row: row, day: day, entry: entries[0] || null, raw: event.target.value };
						event.target.classList.add('is-dirty');
					},
					onblur: function (event) {
						var parsed = roundHours(parseHours(event.target.value));
						if (parsed !== null && sheet.dirty[key]) {
							event.target.value = String(parsed);
							sheet.dirty[key].raw = String(parsed);
						}
					}
				});

				tr.appendChild(el('td.pcm-crm-num', {}, [input]));
			});

			tr.appendChild(el('td.pcm-crm-num.pcm-crm-strong', { text: String(round2(rowTotal)) }));
			body.appendChild(tr);
		});

		if (!rows.length) {
			body.appendChild(el('tr', {}, [el('td.pcm-crm-muted', { colspan: '9', text: 'Nothing logged this week. Add a row to start.' })]));
		}

		var foot = el('tr', {}, [el('th', { text: 'Total' })]);
		var weekTotal = 0;

		days.forEach(function (day) {
			weekTotal += dayTotals[day];
			foot.appendChild(el('th.pcm-crm-num', { text: String(round2(dayTotals[day])) }));
		});

		foot.appendChild(el('th.pcm-crm-num', { text: String(round2(weekTotal)) }));

		var status = el('span.pcm-crm-muted', { role: 'status' });

		app.helpers.clear(mount.body);
		mount.body.appendChild(el('div.pcm-crm-table-wrap', {}, [
			el('table.pcm-crm-table.pcm-crm-sheet', {}, [el('thead', {}, [head]), body, el('tfoot', {}, [foot])])
		]));
		mount.body.appendChild(addRowControl());
		mount.body.appendChild(el('div.pcm-crm-form-actions', {}, [
			el('button.pcm-btn.pcm-btn-primary', { type: 'button', text: 'Save hours', onclick: function (event) { saveTimesheet(event.target, status); } }),
			status
		]));
	}

	function noteInput(row) {
		return el('input.pcm-crm-sheet-note', {
			type: 'text',
			placeholder: 'What was it for? (required)',
			'aria-label': 'Note for ' + row.project_name,
			value: row.note || '',
			oninput: function (event) { row.note = event.target.value; remember(row); }
		});
	}

	function remember(row) {
		var known = sheet.rows.filter(function (r) { return r.key === row.key; })[0];

		if (known) { Object.assign(known, { note: row.note, task_id: row.task_id, task_name: row.task_name }); }
		else { sheet.rows.push({ key: row.key, project_id: row.project_id, task_id: row.task_id, task_name: row.task_name, note: row.note }); }
	}

	function taskPicker(row) {
		var select = el('select.pcm-crm-sheet-taskpick', {
			'aria-label': 'Task for ' + row.project_name,
			onchange: function (event) {
				var option = event.target.options[event.target.selectedIndex];

				sheet.rows = sheet.rows.filter(function (r) { return r.key !== row.key; });
				sheet.rows.push({ project_id: row.project_id, task_id: Number(event.target.value), task_name: option ? option.textContent : '' });
				drawTimesheet();
			}
		}, [el('option', { value: '', text: 'Choose a task…' })]);

		app.helpers.api('/project_tasks', { query: { per_page: 200, orderby: 'name', order: 'ASC', filters: { project_id: row.project_id } } })
			.then(function (data) {
				(data.items || []).forEach(function (task) {
					select.appendChild(el('option', { value: task.id, text: task.name }));
				});
			});

		return select;
	}

	function addRowControl() {
		var project = el('select', { 'aria-label': 'Project to add' }, [el('option', { value: '', text: 'Add a project…' })]);

		sheet.projects.forEach(function (p) {
			project.appendChild(el('option', { value: p.id, text: p.name + (typeDef(p.project_type) ? ' — ' + typeDef(p.project_type).label : '') }));
		});

		return el('div.pcm-crm-sheet-add', {}, [
			project,
			el('button.pcm-btn.pcm-btn-sm', {
				type: 'button',
				text: 'Add row',
				onclick: function () {
					if (!project.value) { return; }
					sheet.rows.push({ project_id: Number(project.value), task_id: 0 });
					drawTimesheet();
				}
			})
		]);
	}

	function copyLastWeek() {
		var days = weekDays(addDays(sheet.week, -7));

		app.helpers.api('/time_entries', {
			query: { per_page: 500, filters: { user_id: sheet.user, entry_date: { min: days[0], max: days[6] } } }
		}).then(function (data) {
			buildSheet(data.items || [], days, []).forEach(function (row) {
				sheet.rows.push({ project_id: row.project_id, task_id: row.task_id, project_name: row.project_name, task_name: row.task_name });
			});
			drawTimesheet();
		}).catch(app.helpers.showError);
	}

	/**
	 * The writes a timesheet's edits come to: one per changed cell. Pure, so a
	 * test can check what a grid of typing turns into.
	 */
	function sheetChanges(dirty, user) {
		var out = [];

		Object.keys(dirty).forEach(function (key) {
			var change = dirty[key];
			var raw = String(change.raw || '').trim();
			var hours = raw === '' ? 0 : parseHours(raw);

			if (hours === null) {
				out.push({ key: key, error: 'Could not read “' + raw + '” as hours.' });
				return;
			}

			hours = roundHours(hours);

			if (change.entry) {
				if (!hours) {
					out.push({ key: key, method: 'DELETE', path: '/time_entries/' + change.entry.id });
				} else if (Number(change.entry.hours) !== hours) {
					out.push({ key: key, method: 'PUT', path: '/time_entries/' + change.entry.id, body: { hours: hours } });
				}
				return;
			}

			if (!hours) { return; }

			var body = { project_id: change.row.project_id, entry_date: change.day, hours: hours, user_id: user };
			if (change.row.task_id) { body.task_id = change.row.task_id; }
			if (change.row.note) { body.description = change.row.note; }

			out.push({ key: key, method: 'POST', path: '/time_entries', body: body });
		});

		return out;
	}

	function saveTimesheet(button, status) {
		var changes = sheetChanges(sheet.dirty, sheet.user);
		var failures = [];

		if (!changes.length) {
			status.textContent = 'Nothing has changed.';
			return;
		}

		button.disabled = true;
		status.textContent = 'Saving…';

		changes.reduce(function (chain, change) {
			return chain.then(function () {
				if (change.error) { failures.push(change.error); return null; }

				return app.helpers.api(change.path, { method: change.method, body: change.body }).then(function () {
					delete sheet.dirty[change.key];
				}).catch(function (error) {
					failures.push(error.message);
				});
			});
		}, Promise.resolve()).then(function () {
			button.disabled = false;

			if (failures.length) {
				status.textContent = failures.length + ' not saved: ' + failures.filter(function (m, i, a) { return a.indexOf(m) === i; }).join(' ');
				return;
			}

			status.textContent = 'Saved.';
			sheet.rows = sheet.rows.filter(function (row) { return row.note; });
			loadTimesheet();
		});
	}

	app.registerView('timesheet', { render: renderTimesheet, load: loadTimesheet });

	/* Project documents ---------------------------------------------------------
	   A second, independent registerRecordTabs('projects', ...) call rather than
	   folding into the burn-down one above — recordTabs collects every provider
	   for an object and concatenates their tabs, so a second call is exactly as
	   valid as adding a second entry inside the first, and keeps this unrelated
	   concern out of that function.
	   --------------------------------------------------------------------- */
	app.registerRecordTabs('projects', function (record, related) {
		var documents = related && related.documents ? related.documents : [];
		var node = el('div.pcm-crm-documents');
		node.appendChild(documentsPanel(record, documents));
		return [{ id: 'documents', label: 'Documents', node: node, count: documents.length }];
	});

	function humanSize(bytes) {
		bytes = Number(bytes || 0);
		if (bytes < 1024) { return bytes + ' B'; }
		if (bytes < 1024 * 1024) { return Math.round(bytes / 1024) + ' KB'; }
		return (Math.round(bytes / (1024 * 1024) * 10) / 10) + ' MB';
	}

	function documentUrl(row, disposition) {
		var root = window.PCM_CRM && window.PCM_CRM.root ? window.PCM_CRM.root : '';
		var nonce = (window.PCM_CRM && window.PCM_CRM.nonce) || '';
		return root + '/pm/documents/' + row.id + '/download?disposition=' + disposition + '&_wpnonce=' + encodeURIComponent(nonce);
	}

	function documentPreviewNode(row) {
		var mime = row._mime_type || '';

		if (0 === mime.indexOf('image/')) {
			return el('img.pcm-crm-doc-preview-img', { src: documentUrl(row, 'inline'), alt: row.label || row._filename });
		}

		if ('application/pdf' === mime) {
			return el('iframe.pcm-crm-doc-preview-frame', { src: documentUrl(row, 'inline'), title: row.label || row._filename });
		}

		return el('p.pcm-crm-related-empty', { text: 'No preview available for this file type.' });
	}

	/**
	 * Documents get a real preview pane, not just a link, because a client or
	 * a teammate deciding whether this is the right file has to open it in a
	 * new tab otherwise — this shows it in place, still routed through
	 * pcm_crm_stream_document() rather than a raw Media Library URL.
	 */
	function documentsPanel(record, documents) {
		var reloadRecord = app.helpers.reloadRecord;
		var wrap = el('div.pcm-crm-doc-panel');
		var list = el('div.pcm-crm-related-rows');
		var preview = el('div.pcm-crm-doc-preview');
		var selectedId = 0;

		function showPreview(row) {
			selectedId = row.id;
			app.helpers.clear(preview);

			preview.appendChild(el('div.pcm-crm-doc-preview-head', {}, [
				el('strong', { text: row.label || row._filename }),
				el('a.pcm-btn.pcm-btn-quiet.pcm-btn-sm', {
					// A plain link, not fetch() — the nonce travels as a query
					// param since no custom header can ride a browser navigation,
					// which is what rest_cookie_check_errors() checks for.
					href: documentUrl(row, 'attachment'),
					text: 'Download'
				})
			]));
			preview.appendChild(documentPreviewNode(row));
		}

		function draw(rows) {
			app.helpers.clear(list);

			if (!rows.length) {
				list.appendChild(el('p.pcm-crm-related-empty', { text: 'No documents yet.' }));
				app.helpers.clear(preview);
				selectedId = 0;
				return;
			}

			rows.forEach(function (row) {
				list.appendChild(el('button.pcm-crm-related-row.pcm-crm-doc-row' + (row.id === selectedId ? '.is-active' : ''), {
					type: 'button',
					onclick: function () { showPreview(row); }
				}, [
					el('span.pcm-crm-cell.pcm-crm-cell-primary', { text: row.label || row._filename }),
					el('span.pcm-crm-cell', { text: row._filename }),
					el('span.pcm-crm-cell', { text: humanSize(row._filesize) }),
					el('span.pcm-btn.pcm-btn-quiet.pcm-btn-sm', {
						text: 'Remove',
						onclick: function (event) {
							event.stopPropagation();
							if (!window.confirm('Remove this document?')) { return; }

							app.helpers.api('/pm/documents/' + row.id, { method: 'DELETE' }).then(function () {
								reloadRecord();
							}).catch(function (error) { window.alert(error.message); });
						}
					})
				]));
			});

			if (!selectedId) { showPreview(rows[0]); }
		}

		draw(documents);

		var addButton = el('button.pcm-btn.pcm-btn-primary.pcm-btn-sm', {
			type: 'button',
			text: 'Upload',
			onclick: function () {
				if (!window.wp || !window.wp.media) { return; }

				var frame = window.wp.media({ title: 'Choose a file', button: { text: 'Add to project' }, multiple: true });

				frame.on('select', function () {
					var uploads = frame.state().get('selection').map(function (model) {
						var attachment = model.toJSON();

						return app.helpers.api('/pm/projects/' + record.id + '/documents', {
							method: 'POST',
							body: { attachment_id: attachment.id, label: attachment.title || attachment.filename || '' }
						});
					});

					// Uploads can span several files from one picker session; the
					// related-list count (and this tab's own list) should reflect
					// all of them, not just whichever call happened to finish last.
					Promise.all(uploads).then(function () {
						reloadRecord();
					}).catch(function (error) { window.alert(error.message); });
				});

				frame.open();
			}
		});

		wrap.appendChild(addButton);
		wrap.appendChild(el('div.pcm-crm-doc-layout', {}, [list, preview]));

		return wrap;
	}

	// Exposed for tests/pm-views.js, which lifts the pure helpers out by name
	// rather than keeping a copy that would go stale.
	window.PCM_CRM_PM = {
		parseHours: parseHours,
		roundHours: roundHours,
		buildSheet: buildSheet,
		sheetChanges: sheetChanges,
		weekStart: weekStart,
		describeContext: describeContext
	};
})(window, document);
