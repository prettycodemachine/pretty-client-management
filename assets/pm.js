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

	app.registerObject('projects', {
		label: 'Project',
		plural: 'Projects',
		related: 'fetch',
		title: function (row) { return row.name; },
		kicker: function (row) { return row.project_type || 'Project'; },
		kickerLink: function (row) {
			return row.account_id ? { object: 'accounts', id: row.account_id } : null;
		},
		highlights: function (row) {
			return [
				{ label: 'Account', value: row._account_name },
				{ label: 'Stage', value: row.stage_name },
				{ label: 'Health', value: row.health },
				{ label: 'Ends', value: app.helpers.formatDate(row.end_date) },
				{ label: 'Owner', value: row._owner_name }
			];
		},
		columns: [
			{ key: 'name', label: 'Project', strong: true },
			{ key: '_account_name', label: 'Account' },
			{ key: 'project_type', label: 'Type' },
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

			if (name === 'account_id') { hints.lookup = 'accounts'; }
			if (name === 'opportunity_id') { hints.lookup = 'opportunities'; }
			if (name === 'owner_id') { hints.options = app.helpers.ownerOptions(); }
			if (name === 'name' || name === 'description' || name === 'health_note') { hints.wide = true; }

			// The retainer fields are meaningless on a fixed-price build, so they
			// are hidden until the type says otherwise rather than sitting there
			// inviting a value that nothing would read. Matched against the list
			// of retainer types rather than the word "Retainer" in the name, so
			// renaming a type does not change which fields it offers.
			if (name.indexOf('retainer_') === 0) {
				hints.showWhen = 'project_type';
				hints.showWhenOneOf = (state.boot && state.boot.retainerTypes) || [];
			}

			return hints;
		}
	});

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
		kicker: function (row) { return row._project_name || (Number(row.is_milestone) ? 'Milestone' : 'Task'); },
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
			{ key: '_project_name', label: 'Project' },
			{ key: 'status', label: 'Status', badge: true },
			{ key: '_assignee_name', label: 'Assigned To' },
			{ key: 'due_date', label: 'Due', due: true }
		],
		filters: function () {
			return [
				{ key: 'status', label: 'Status', options: app.helpers.options(state.boot.taskStatuses || [], true), blank: 'Any status' },
				{ key: 'is_milestone', label: 'Milestone', options: [
					{ value: '', label: 'Either' }, { value: '1', label: 'Milestones' }, { value: '0', label: 'Tasks' }
				] },
				{ key: 'due_date', label: 'Due', range: 'date' }
			];
		},
		hints: function (name) {
			var hints = {};

			if (name === 'project_id') { hints.lookup = 'projects'; }
			if (name === 'assignee_user_id') { hints.options = app.helpers.ownerOptions(); }
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
				{ label: 'Project', value: row._project_name },
				{ label: 'Status', value: row.status },
				{ label: 'Severity', value: row.severity ? String(row.severity) : '' },
				{ label: 'Review By', value: app.helpers.formatDate(row.due_date) }
			];
		},
		columns: [
			{ key: 'title', label: 'Title', strong: true },
			{ key: 'raid_type', label: 'Kind', badge: true },
			{ key: '_project_name', label: 'Project' },
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

			if (name === 'project_id') { hints.lookup = 'projects'; }
			if (name === 'owner_contact_id') { hints.lookup = 'contacts'; }
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
				{ label: 'Project', value: row._project_name },
				{ label: 'Role', value: row.role },
				{ label: 'Organisation', value: row._org_name }
			];
		},
		columns: [
			{ key: '_person_name', label: 'Person', strong: true },
			{ key: '_party_label', label: 'Side', badge: true },
			{ key: 'role', label: 'Role' },
			{ key: '_project_name', label: 'Project' },
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

			if (name === 'project_id') { hints.lookup = 'projects'; }
			if (name === 'contact_id') { hints.lookup = 'contacts'; }
			if (name === 'partner_account_id') { hints.lookup = 'accounts'; }
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
				{ label: 'Project', value: row._project_name },
				{ label: 'Person', value: row._user_name },
				{ label: 'Date', value: row.entry_date },
				{ label: 'Hours', value: row.hours }
			];
		},
		columns: [
			{ key: 'entry_date', label: 'Date', date: true },
			{ key: '_project_name', label: 'Project', strong: true },
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

			if (name === 'project_id') { hints.lookup = 'projects'; }
			if (name === 'task_id') { hints.lookup = 'project_tasks'; }
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
					var parsed = parseHours(event.target.value);

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
	 * An unmapped deal type leaves the project type blank rather than guessing:
	 * the type decides which stages are legal, so guessing it wrong means the
	 * stage picklist offers the wrong lifecycle.
	 */
	function projectFromOpportunity(row) {
		var map = (state.boot && state.boot.opportunityTypeMap) || {};

		return {
			account_id: row.account_id || 0,
			opportunity_id: row.id,
			name: row.name || '',
			project_type: map[row.type] || '',
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
			{ text: row.project_type || '—' },
			{ text: app.helpers.money(row.budget_amount), num: true }
		];
	});

	app.registerRelatedColumns('tasks', function (row) {
		return [
			{ text: row.name, strong: true },
			{ badge: row.status },
			{ text: Number(row.is_milestone) ? 'Milestone' : '—' },
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

	// Exposed for tests/pm-views.js, which lifts the pure helpers out by name
	// rather than keeping a copy that would go stale.
	window.PCM_CRM_PM = { parseHours: parseHours };
})(window, document);
