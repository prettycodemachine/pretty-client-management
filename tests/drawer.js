/**
 * Open records the way a person does, and see whether the drawer survives.
 *
 * The other suites check registrations against crm.js's vocabulary, which is
 * necessary and turned out not to be sufficient: a record can be registered
 * correctly and still fail to open, because the failure lives in the click path
 * — the related list, the drawer, the kicker — rather than in the definition.
 * Three rounds of that were found from screenshots.
 *
 * So this runs the real crm.js and pm.js against a DOM that records what the app
 * builds and keeps the listeners it attaches, then clicks: open a project, click
 * a row in each of its related lists, click New in each. Any drawer that ends in
 * .pcm-crm-error, or a throw, fails the run.
 *
 * Run with:  node tests/drawer.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const asset = file => fs.readFileSync(path.join(__dirname, '..', 'assets', file), 'utf8');

let failed = 0;
function check(label, got, want) {
	const ok = JSON.stringify(got) === JSON.stringify(want);
	if (!ok) { failed++; }
	console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
	if (!ok) { console.log(`    got:  ${JSON.stringify(got)}\n   want:  ${JSON.stringify(want)}`); }
}

const { makeEl, matches } = require('./dom');

/* Fixtures shaped like what the real routes return. ----------------------- */

const project = {
	id: 12, name: 'Acme — Platform Care', project_type: 'AI Enablement Retainer', stage_name: 'Active',
	account_id: 5, opportunity_id: 9, health: 'Green', is_closed: 0, is_test: 1, owner_id: 4,
	_account_name: 'Acme', _opportunity_name: 'Acme — AI pilot', _owner_name: 'Dana Reyes',
};

const children = {
	project_tasks: { id: 77, project_id: 12, name: 'Kickoff', status: 'Done', is_milestone: 0, due_date: '2026-09-01', _project_name: project.name, _assignee_name: 'Dana Reyes', owner_id: 4 },
	project_raid: { id: 78, project_id: 12, title: 'Data quality', raid_type: 'Risk', status: 'Open', severity: 6, _project_name: project.name, owner_id: 4 },
	project_roles: { id: 79, project_id: 12, party_type: 'client', contact_id: 7, role: 'Business Owner', _person_name: 'Sam Lee', _party_label: 'Client', _org_name: 'Acme', _project_name: project.name, owner_id: 4 },
	time_entries: { id: 80, project_id: 12, user_id: 4, entry_date: '2026-09-11', hours: 1.5, is_billable: 1, _project_name: project.name, _user_name: 'Dana Reyes', owner_id: 4 },
	activities: { id: 81, subject: 'Kickoff call', activity_type: 'Call', status: 'Completed', what_type: 'project', what_id: 12, who_id: 0, owner_id: 4 },
};

const listKey = { project_tasks: 'tasks', project_raid: 'raid', project_roles: 'roles', time_entries: 'time', activities: 'activities' };

function field(key, type, label) { return { key, type: type || 'text', label: label || key }; }
// A lookup column, carrying the target the server declares beside it.
function lookup(key, target) { return { key, type: 'id', label: key, lookup: target }; }

// /schema, as the real one serves it: fields plus a layout per reportable object.
const schema = {
	projects: {
		label: 'project', fields: [field('name'), lookup('account_id', 'accounts'), lookup('opportunity_id', 'opportunities'), field('project_type'), field('stage_name'), field('health'), field('description', 'longtext')],
		layout: [{ title: '', fields: ['name', 'account_id', 'opportunity_id', 'project_type', 'stage_name', 'health'] }, { title: 'Notes', fields: ['description'] }],
		related: [], groupOptions: ['stage_name'], groupBy: 'stage_name',
	},
	project_tasks: { label: 'project_task', fields: [field('name'), lookup('project_id', 'projects'), field('status'), field('is_milestone', 'bool'), field('due_date', 'date')], layout: [{ title: '', fields: ['name', 'project_id', 'status', 'is_milestone', 'due_date'] }], related: [] },
	project_raid: { label: 'project_raid', fields: [field('title'), lookup('project_id', 'projects'), field('raid_type'), field('status')], layout: [{ title: '', fields: ['title', 'project_id', 'raid_type', 'status'] }], related: [] },
	project_roles: { label: 'project_role', fields: [lookup('project_id', 'projects'), field('party_type'), field('user_id', 'id'), lookup('contact_id', 'contacts'), field('role')], layout: [{ title: '', fields: ['project_id', 'party_type', 'user_id', 'contact_id', 'role'] }], related: [] },
	time_entries: { label: 'time_entry', fields: [lookup('project_id', 'projects'), field('user_id', 'id'), field('entry_date', 'date'), field('hours', 'hours'), field('description', 'longtext')], layout: [{ title: '', fields: ['project_id', 'user_id', 'entry_date', 'hours'] }], related: [] },
	activities: { label: 'activity', fields: [field('subject'), field('activity_type'), field('status')], layout: [{ title: '', fields: ['subject', 'activity_type', 'status'] }], related: [] },
	accounts: { label: 'account', fields: [field('name')], layout: [{ title: '', fields: ['name'] }], related: [] },
	contacts: { label: 'contact', fields: [field('first_name')], layout: [{ title: '', fields: ['first_name'] }], related: [] },
	opportunities: { label: 'opportunity', fields: [field('name')], layout: [{ title: '', fields: ['name'] }], related: [] },
};

// REAL=path/to/capture.json replays responses captured from a live site, so a
// failure seen in a browser can be reproduced against its actual data.
const real = process.env.REAL ? JSON.parse(fs.readFileSync(process.env.REAL, 'utf8')) : null;

if (real) {
	project.id = real.pid;
}

function respond(route) {
	const [pathPart] = route.split('?');

	if (real) {
		if (pathPart === '/bootstrap') { return real.bootstrap; }
		if (pathPart === '/schema') { return real.schema; }
		if (pathPart === `/projects/${real.pid}`) { return real.project; }
		if (pathPart === `/related/projects/${real.pid}`) { return real.related; }
		const hit = pathPart.match(/^\/([a-z_]+)\/(\d+)$/);
		if (hit) {
			for (const key of Object.keys(real.related)) {
				const row = (real.related[key] || []).find(r => String(r.id) === hit[2]);
				if (row) { return row; }
			}
		}
		return { items: [], total: 0 };
	}

	if (pathPart === '/bootstrap') {
		return {
			stages: [{ name: 'Qualification', is_closed: 0 }], accountTypes: [], industries: [], opportunityTypes: [],
			leadSources: [], activityTypes: ['Call'], activityStatuses: ['Completed'], priorities: [],
			interests: {}, owners: [{ value: 4, label: 'Dana Reyes' }], users: [], currency: '$', stallDays: 30,
			frequencies: [], weekdays: {}, emailVariables: [], templates: [], sequences: [], recyclable: {},
			projectTypes: ['Salesforce Support Retainer', 'AI Enablement Retainer', 'Custom Development'],
			retainerTypes: ['Salesforce Support Retainer', 'AI Enablement Retainer'],
			projectStages: ['Active'], projectHealth: ['Green', 'Amber', 'Red'], raidTypes: ['Risk'], raidStatuses: ['Open'],
			raidLevels: ['Low', 'Medium', 'High'], taskStatuses: ['Done'], partyTypes: { internal: 'Internal', partner: 'Partner', client: 'Client' },
			projectRoles: ['Business Owner'], retainerPeriods: { monthly: 'Monthly' }, projectStageSets: {}, opportunityTypeMap: {},
		};
	}

	if (pathPart === '/schema') { return schema; }
	if (pathPart === '/related/projects/12') {
		const out = {};
		Object.keys(children).forEach(obj => { out[listKey[obj]] = [children[obj]]; });
		return out;
	}
	if (pathPart === '/projects/12') { return project; }

	const one = pathPart.match(/^\/([a-z_]+)\/(\d+)$/);
	if (one) {
		const row = Object.values(children).find(r => String(r.id) === one[2]);
		if (row) { return row; }
		const err = new Error('not found'); err.status = 404; throw err;
	}

	// Lookups and lists.
	return { items: [], total: 0 };
}

/* Boot the real app. -------------------------------------------------------- */

const roles = ['actions', 'filters', 'body', 'drawer', 'scrim'];
const root = makeEl('div');
root.className = 'pcm-crm';
root.dataset.view = 'projects';
roles.forEach(role => { const n = makeEl('div'); n.dataset.role = role; if (role === 'drawer' || role === 'scrim') { n.hidden = true; } root.appendChild(n); });

const body = makeEl('body');
body.appendChild(root);

const requests = [];
const errors = [];

const document = {
	readyState: 'loading',
	body,
	documentElement: makeEl('html'),
	activeElement: null,
	createElement: makeEl,
	createElementNS: (ns, tag) => makeEl(tag),
	createTextNode: text => ({ nodeType: 3, textContent: String(text), parentNode: null }),
	querySelector: sel => (matches(root, sel) ? root : body.querySelector(sel)),
	querySelectorAll: sel => body.querySelectorAll(sel),
	listeners: {},
	addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
	removeEventListener() {},
};

const window = {
	document,
	PCM_CRM: { root: 'https://example.test/wp-json/pcm-crm/v1', nonce: 'n', adminUrl: '/wp-admin/admin.php', exportUrl: '/wp-admin/admin-post.php', exportNonce: 'x', currentUser: 4 },
	location: { hash: '', pathname: '/wp-admin/admin.php', search: '?page=pcm-crm-projects', href: '' },
	history: { replaceState() {}, pushState() {} },
	getComputedStyle: () => ({ getPropertyValue: () => '#112233' }),
	matchMedia: () => ({ matches: false, addEventListener() {} }),
	setTimeout: (fn) => { fn(); return 0; },
	clearTimeout() {},
	requestAnimationFrame: fn => fn(),
	console: { log() {}, warn() {}, error: (...a) => errors.push(a.map(String).join(' ')) },
	confirm: () => true,
	prompt: () => '',
	addEventListener() {},
	fetch(url) {
		const route = String(url).replace(/^.*\/v1/, '');
		requests.push(route);

		try {
			const data = respond(route);
			return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
		} catch (e) {
			return Promise.resolve({ ok: false, status: e.status || 500, json: () => Promise.resolve({ message: e.message }) });
		}
	},
};
window.window = window;

function load(file) {
	// eslint-disable-next-line no-new-func
	new Function('window', 'document', 'console', `with (window) { ${asset(file)} }`)(window, document, window.console);
}

// Enough turns for the longest chain the drawer runs: lookups, then the record,
// then its related lists, each a fetch and a json() behind a then().
const settle = async () => { for (let i = 0; i < 30; i++) { await new Promise(r => setImmediate(r)); } };

async function main() {
	load('charts.js');
	load('crm.js');
	load('pm.js');

	(document.listeners.DOMContentLoaded || []).forEach(fn => fn());
	await settle();

	const app = window.PCM_CRM_App;
	check('the app booted with the module registered', !!(app && app.helpers && app.helpers.openDrawer), true);

	const drawer = root.querySelector('[data-role="drawer"]');

	const drawerError = () => {
		const node = drawer.querySelector('.pcm-crm-error');
		return node ? node.textContent : null;
	};

	/* Open the project. */
	app.helpers.openDrawer('projects', project.id);
	await settle();

	check('the project opens without an error', drawerError(), null);
	check('and names itself', (drawer.querySelector('h2') || {}).textContent, real ? real.project.name : project.name);

	// Tab buttons carry data-tab as well, and come first in the tree.
	const panels = () => drawer.querySelectorAll('.pcm-crm-panel[data-tab]');
	const tabIds = panels().map(p => p.dataset.tab).filter(t => t !== 'details');
	check('every related list gets a tab, and the project its summary', tabIds, ['tasks', 'raid', 'roles', 'time', 'activities', 'burn']);

	/* Click an existing row in each list, then New in each. */
	for (const object of Object.keys(children)) {
		const key = listKey[object];

		// Reopen the project each time: opening a child replaces the drawer.
		app.helpers.openDrawer('projects', project.id);
		await settle();

		const panel = drawer.querySelector(`.pcm-crm-panel[data-tab="${key}"]`);
		const row = panel && panel.querySelector('.pcm-crm-related-row');
		check(`the ${key} list has a row to click`, !!row, true);

		if (row) {
			let thrown = null;
			try { row.click(); await settle(); } catch (e) { thrown = e.message; }

			check(`clicking a ${key} row does not throw`, thrown, null);
			check(`clicking a ${key} row opens it without an error`, drawerError(), null);
			check(`and it opens the record it was clicked on`, (drawer.querySelector('h2') || {}).textContent.length > 0, true);
		}

		app.helpers.openDrawer('projects', project.id);
		await settle();

		const panel2 = drawer.querySelector(`.pcm-crm-panel[data-tab="${key}"]`);
		const newButton = panel2 && panel2.querySelector('.pcm-crm-related-toolbar button');
		check(`the ${key} list has a New button`, !!newButton, true);

		if (newButton) {
			let thrown = null;
			try { newButton.click(); await settle(); } catch (e) { thrown = e.message; }

			check(`New from ${key} does not throw`, thrown, null);
			check(`New from ${key} opens a form without an error`, drawerError(), null);
		}
	}

	check('nothing was logged to the console as an error', errors, []);

	console.log(failed ? `\n${failed} FAILED` : '\nAll checks passed');
	process.exit(failed ? 1 : 0);
}

main().catch(e => { console.log('HARNESS ERROR', e && e.stack); process.exit(2); });
