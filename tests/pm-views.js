/**
 * Checks that pm.js registers against the contracts crm.js actually implements.
 *
 * These are the mistakes this file exists for, all of which were found by
 * looking at a screenshot rather than by a test:
 *
 *   - kickerLink returning a URL string, when the drawer wants { object, id }
 *     and calls .replace() on link.object
 *   - a column's render() returning a DOM node, when a cell takes its return
 *     as text and would print [object HTMLSpanElement]
 *   - a filter carrying an invented `type`, which falls through to the select
 *     branch and renders an empty dropdown
 *   - a conditional field naming an invented `showWhenRetainer`, which is never
 *     written to the dataset, so the condition falls through to Number() of a
 *     project type — NaN, and the fields stay hidden forever
 *
 * None of them threw where they were written. A registration is data, and data
 * that is the wrong shape is only found where it is consumed.
 *
 * Run with:  node tests/pm-views.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const pmSrc = fs.readFileSync(path.join(__dirname, '..', 'assets', 'pm.js'), 'utf8');
const crmSrc = fs.readFileSync(path.join(__dirname, '..', 'assets', 'crm.js'), 'utf8');

let failed = 0;
function check(label, got, want) {
	const ok = JSON.stringify(got) === JSON.stringify(want);
	if (!ok) { failed++; }
	console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
	if (!ok) { console.log(`    got:  ${JSON.stringify(got)}\n   want:  ${JSON.stringify(want)}`); }
}

/* What crm.js actually supports, read out of crm.js rather than copied, so this
   goes stale loudly instead of quietly. ------------------------------------ */

// Every `column.<flag>` cell() branches on.
const cellFlags = new Set(
	[...crmSrc.matchAll(/column\.(\w+)/g)].map(m => m[1])
);

// Every `filter.<key>` the filter bar reads.
const filterKeys = new Set(
	[...crmSrc.matchAll(/filter\.(\w+)/g)].map(m => m[1])
);

// Every `field.<key>` written into a field's dataset or read by fieldControl.
const fieldKeys = new Set(
	[...crmSrc.matchAll(/field\.(\w+)/g)].map(m => m[1])
);

check('crm.js still has a cell flag vocabulary to check against', cellFlags.size > 5, true);
check('and a filter vocabulary', filterKeys.has('range') && filterKeys.has('lookup'), true);

/* Run pm.js against a recording stub. --------------------------------------- */

const registered = { objects: {}, views: {}, controls: {}, children: {}, columns: {} };

const node = () => ({ __node: true, appendChild() {}, classList: { add() {} } });

const app = {
	registerObject: (slug, def) => { registered.objects[slug] = def; },
	registerView: (name, handlers) => { registered.views[name] = handlers; },
	registerControl: (type, spec) => { registered.controls[type] = spec; },
	registerChildTypes: (slug, fn) => { (registered.children[slug] = registered.children[slug] || []).push(fn); },
	registerRelatedColumns: (kind, fn) => { registered.columns[kind] = fn; },
	registerRecordTabs: (slug, fn) => { (registered.tabs = registered.tabs || {})[slug] = fn; },
	registerDerived: fn => { (registered.derived = registered.derived || []).push(fn); },
	ready: () => {},
	state: {
		boot: {
			projectTypes: [
				{ value: 'salesforce-support-retainer', label: 'Salesforce Support Retainer' },
				{ value: 'custom-development', label: 'Custom Development' }
			],
			retainerTypes: ['salesforce-support-retainer'],
			projectStages: ['Active', 'Closed'],
			projectHealth: ['Green', 'Amber', 'Red'],
			opportunityTypeMap: { 'Renewal': 'salesforce-support-retainer', 'New Business': 'custom-development' },
			projectTypeDefs: {
				'salesforce-support-retainer': {
					label: 'Salesforce Support Retainer', archetype: 'retainer', active: 1, icon: 'backup',
					stages: [{ name: 'Active', is_closed: 0 }, { name: 'Ended', is_closed: 1 }],
					fields: { hidden: ['budget_amount', 'budget_hours'], required: ['retainer_hours'], labels: {} },
					time: { task_required: 0 }, defaults: { default_bill_rate: 185 }, tabs: ['time', 'tasks']
				},
				'custom-development': {
					label: 'Custom Development', archetype: 'fixed', active: 1, icon: 'flag',
					stages: [{ name: 'Build', is_closed: 0 }, { name: 'Closed', is_closed: 1 }],
					fields: { hidden: ['retainer_hours', 'retainer_period'], required: ['budget_amount'], labels: {} },
					time: { task_required: 1 }, defaults: {}, tabs: ['tasks', 'raid']
				}
			},
			archetypes: { retainer: { label: 'Retainer' }, fixed: { label: 'Fixed scope' } }
		},
		schema: {}
	},
	helpers: {
		el: node,
		clear() {},
		money: v => '$' + (v || 0),
		formatDate: v => String(v || ''),
		formatDateTime: v => String(v || ''),
		today: () => '2026-09-13',
		options: (list, blank) => (blank ? [{ value: '', label: '—' }] : []).concat(
			(list || []).map(x => (typeof x === 'string' ? { value: x, label: x } : x))
		),
		ownerOptions: () => [{ value: '', label: 'Anyone' }]
	}
};

const win = { PCM_CRM_App: app };
// eslint-disable-next-line no-new-func
new Function('window', 'document', pmSrc)(win, { });

check('pm.js registers every object a project record can open',
	Object.keys(registered.objects).sort(), ['project_raid', 'project_roles', 'project_tasks', 'projects', 'time_entries']);

// Core's own object map, read out of crm.js, so a child pointing at a core
// object (activities) counts as resolved.
const coreObjects = new Set(
	[...crmSrc.matchAll(/^\t\t([a-z_]+): \{\n\t\t\tlabel:/gm)].map(m => m[1])
);
check('crm.js still declares its core objects', coreObjects.has('activities') && coreObjects.has('accounts'), true);

/* The contracts. ------------------------------------------------------------ */

const sampleRows = {
	project_tasks: {
		id: 4, project_id: 12, name: 'Kickoff', status: 'Done', is_milestone: 0, due_date: '2026-09-01',
		_project_name: 'Acme retainer', _assignee_name: 'Dana', estimated_hours: 4
	},
	project_raid: {
		id: 5, project_id: 12, title: 'Data quality', raid_type: 'Risk', status: 'Open', severity: 6,
		due_date: '2026-10-01', _project_name: 'Acme retainer'
	},
	project_roles: {
		id: 6, project_id: 12, party_type: 'client', contact_id: 7, role: 'Business Owner',
		_person_name: 'Sam Lee', _party_label: 'Client', _org_name: 'Acme', _project_name: 'Acme retainer'
	},
	projects: {
		id: 12, name: 'Acme retainer', account_id: 5, opportunity_id: 9, project_type: 'AI Enablement Retainer',
		stage_name: 'Active', health: 'Green', budget_amount: 20000, end_date: '2026-12-31',
		is_closed: 0, _account_name: 'Acme', _owner_name: 'Dana', last_modified_date: '2026-09-01 10:00:00'
	},
	time_entries: {
		id: 3, entry_date: '2026-09-11', hours: 1.5, is_billable: 1, project_id: 12,
		_project_name: 'Acme retainer', _user_name: 'Dana', description: 'Config'
	}
};

Object.keys(registered.objects).forEach(slug => {
	const def = registered.objects[slug];
	const row = sampleRows[slug];

	// kickerLink: the drawer does link.object.replace(...), so a string here is
	// a TypeError the moment a record with a parent is opened.
	if (def.kickerLink) {
		const link = def.kickerLink(row);
		check(`${slug}: kickerLink returns an object or null, never a string`,
			link === null || (typeof link === 'object' && typeof link.object === 'string' && 'id' in link), true);

		// Every parent column zeroed, since objects link up through different
		// ones — a project through its account, everything under it through
		// its project.
		const orphan = def.kickerLink(Object.assign({}, row, { account_id: 0, project_id: 0 }));
		check(`${slug}: and null when there is no parent to open`, orphan === null || orphan === undefined, true);
	}

	// Columns: only flags cell() knows, and render() returning text.
	(def.columns || []).forEach(column => {
		Object.keys(column).forEach(key => {
			if (key === 'key' || key === 'label') { return; }
			check(`${slug}.${column.key}: '${key}' is a cell flag crm.js implements`, cellFlags.has(key), true);
		});

		if (column.render) {
			const out = column.render(row);
			check(`${slug}.${column.key}: render() returns text, not a node`, typeof out === 'string', true);
		}
	});

	// Filters: range, lookup, or a select that has options to show.
	(def.filters ? def.filters() : []).forEach(filter => {
		Object.keys(filter).forEach(key => {
			check(`${slug} filter '${filter.key}': '${key}' is a filter key crm.js reads`, filterKeys.has(key), true);
		});

		if (!filter.range && !filter.lookup) {
			check(`${slug} filter '${filter.key}': a select filter has options`,
				Array.isArray(filter.options) && filter.options.length > 0, true);
		}
	});

	// Hints become a field's properties, and anything crm.js does not read is
	// silently inert — which is how a permanently hidden section happens.
	if (def.hints) {
		['name', 'title', 'account_id', 'project_id', 'owner_id', 'description', 'retainer_hours', 'user_id',
			'task_id', 'contact_id', 'partner_account_id', 'assignee_user_id', 'owner_contact_id']
			.forEach(field => {
				const hints = def.hints(field) || {};

				Object.keys(hints).forEach(key => {
					check(`${slug} hint on '${field}': '${key}' is read by crm.js`, fieldKeys.has(key), true);
				});

				// A condition has to name the mode it wants, or conditionMet()
				// falls through to Number() of whatever the field holds.
				if (hints.showWhen) {
					check(`${slug} hint on '${field}': a condition names how to test it`,
						!!(hints.showWhenLost || hints.showWhenValue || hints.showWhenOneOf), true);
				}

				if (hints.showWhenOneOf) {
					check(`${slug} hint on '${field}': the value set is a non-empty array`,
						Array.isArray(hints.showWhenOneOf) && hints.showWhenOneOf.length > 0, true);
				}
			});
	}
});

/* Related-list columns return cells, not nodes. ----------------------------- */

const relatedRows = {
	projects: sampleRows.projects,
	tasks: { name: 'Kickoff', status: 'Done', is_milestone: 0, due_date: '2026-09-01' },
	raid: { title: 'Data quality', raid_type: 'Risk', status: 'Open', severity: 6 },
	roles: { _person_name: 'Dana', _party_label: 'Internal', party_type: 'internal', role: 'Lead', _org_name: 'PCM' },
	time: sampleRows.time_entries
};

Object.keys(registered.columns).forEach(kind => {
	const cells = registered.columns[kind](relatedRows[kind] || {});

	check(`related '${kind}' returns an array of cells`, Array.isArray(cells), true);

	cells.forEach((cellDef, i) => {
		const keys = Object.keys(cellDef);
		const known = keys.every(k => ['text', 'strong', 'badge', 'tone', 'num'].indexOf(k) !== -1);
		check(`related '${kind}' cell ${i} uses known cell keys`, known, true);

		if ('text' in cellDef) {
			check(`related '${kind}' cell ${i} text is a string`, typeof cellDef.text === 'string', true);
		}
	});
});

/* Child types carry the parent link. ---------------------------------------- */

const helpers = { firstStage: 'Qualification', closeDate: '2026-10-01', activity: o => Object.assign({ activity_type: 'Call' }, o) };

check('a project is offered from an account and from the deal it came from',
	Object.keys(registered.children).sort(), ['accounts', 'opportunities', 'projects']);

const fromAccount = registered.children.accounts[0]({ id: 5 }, helpers)[0];
check('a project created from an account is linked to it', fromAccount.prefill.account_id, 5);

const fromDeal = registered.children.opportunities[0](
	{ id: 9, account_id: 5, name: 'Acme — AI pilot', type: 'Renewal', amount: 24000 }, helpers
)[0];
check('a project created from a deal carries the account', fromDeal.prefill.account_id, 5);
check('and the deal itself', fromDeal.prefill.opportunity_id, 9);
check('and the amount as the budget', fromDeal.prefill.budget_amount, 24000);
check('and the mapped project type', fromDeal.prefill.project_type, 'salesforce-support-retainer');

// Guessing the type would offer the wrong lifecycle, and nothing says so until a
// stage refuses to save.
const unmapped = registered.children.opportunities[0]({ id: 9, account_id: 5, type: 'Barter' }, helpers)[0];
/* The type chooser, and a form that follows the type. ---------------------- */

const projectDef = registered.objects.projects;
const chooser = (prefill) => {
	const out = { shown: null, proceeded: null };
	projectDef.beforeCreate(prefill, {
		show: (title, body) => { out.shown = title; },
		proceed: values => { out.proceeded = values; },
		close() {}
	});
	return out;
};

const mapped = chooser({ project_type: 'salesforce-support-retainer', account_id: 5 });
check('a mapped type skips the chooser', mapped.shown, null);
check('and opens on the type\'s first open stage, with its defaults under what was known',
	[mapped.proceeded.stage_name, mapped.proceeded.default_bill_rate, mapped.proceeded.account_id], ['Active', 185, 5]);
check('an unmapped one asks which kind of project', chooser({ account_id: 5 }).shown, 'What kind of project?');

const retainerHints = projectDef.hints('retainer_hours');
check('a field only some processes use is shown only for their types',
	[retainerHints.showWhen, retainerHints.showWhenOneOf], ['project_type', ['salesforce-support-retainer']]);
check('and says which types require it', retainerHints.note, 'Required on Salesforce Support Retainer.');
check('a field every type uses carries no condition', projectDef.hints('name').showWhen, undefined);
check('the stage picklist narrows to the type', projectDef.hints('stage_name').ui, 'project-stage');
check('a build opens its related lists on tasks', projectDef.tabOrder({ project_type: 'custom-development' }).slice(0, 3), ['burn', 'tasks', 'raid']);
check('and reads its type by name in a list', projectDef.columns.find(c => c.key === 'project_type').render({ project_type: 'custom-development' }), 'Custom Development');

check('an unmapped deal type leaves the project type blank rather than guessing',
	unmapped.prefill.project_type, '');

registered.children.projects[0]({ id: 12 }, helpers).forEach(child => {
	check(`a project's '${child.id}' child is linked back to it`,
		child.prefill.project_id === 12 || child.prefill.what_id === 12, true);
});

// The bug this block exists for: a child list whose object nothing defines.
// "New Task" opened the drawer for project_tasks, renderDrawer() read
// objects.project_tasks.label, and there was no objects.project_tasks. The list
// itself rendered fine, so it only failed at the click. Every child of every
// parent has to land on a definition — and so must every existing row in the
// list, which opens the same drawer.
Object.keys(registered.children).forEach(parent => {
	registered.children[parent].forEach(provider => {
		provider({ id: 1, account_id: 1 }, helpers).forEach(child => {
			check(`'${parent}' offers '${child.id}', whose object '${child.object}' is defined`,
				!!(registered.objects[child.object] || coreObjects.has(child.object)), true);

			// A list without its own renderer falls through to the activity
			// shape and draws a column of dashes. Core's own kinds have one.
			check(`'${parent}' list '${child.id}' has columns to draw`,
				!!(registered.columns[child.id] || ['contacts', 'opportunities', 'activities'].indexOf(child.id) !== -1), true);
		});
	});
});

// An object that can be opened has to be able to say what it is.
Object.keys(registered.objects).forEach(slug => {
	const def = registered.objects[slug];

	['label', 'plural'].forEach(key => {
		check(`${slug}: has a ${key}`, typeof def[key] === 'string' && def[key].length > 0, true);
	});

	['title', 'kicker', 'highlights', 'filters'].forEach(key => {
		check(`${slug}: has ${key}()`, typeof def[key] === 'function', true);
	});

	check(`${slug}: has columns`, Array.isArray(def.columns) && def.columns.length > 0, true);
});

/* The hours control mirrors the PHP parser. --------------------------------- */

const parseHours = win.PCM_CRM_PM.parseHours;

[['1.5', 1.5], ['1:30', 1.5], [':45', 0.75], ['0:15', 0.25], ['90m', 1.5], ['1h30m', 1.5],
	['1h 30', 1.5], ['1.5h', 1.5], ['1h', 1], ['8', 8], ['2:75', 3.25]].forEach(([input, want]) => {
	check(`hours '${input}' reads as ${want}`, parseHours(input), want);
});

// Null, not zero, and not a guess — the same contract pcm_crm_parse_hours() has,
// because the server parses the value again and the two must agree.
[['', null], ['abc', null], ['-2', null], ['1.2.3', null]].forEach(([input, want]) => {
	check(`hours '${input}' is refused`, parseHours(input), want);
});

console.log(failed ? `\n${failed} FAILED` : '\nAll checks passed');
process.exit(failed ? 1 : 0);
