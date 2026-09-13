/**
 * Records as pages, read first, with lookups that search and link.
 *
 * Runs the real crm.js against the shared DOM shim and clicks through what a
 * person does: open a contact from its list, read it, follow its account link,
 * edit a field and save, go Back to the list — then fill a deal's primary
 * contact by searching, narrowed to the deal's account, and create one from
 * inside the lookup.
 *
 * Run with:  node tests/record.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { makeEl, matches } = require('./dom');

const asset = file => fs.readFileSync(path.join(__dirname, '..', 'assets', file), 'utf8');

let failed = 0;
function check(label, got, want) {
	const ok = JSON.stringify(got) === JSON.stringify(want);
	if (!ok) { failed++; }
	console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
	if (!ok) { console.log(`    got:  ${JSON.stringify(got)}\n   want:  ${JSON.stringify(want)}`); }
}

/* Fixtures --------------------------------------------------------------- */

const lookup = (key, target, extra) => Object.assign({ key, type: 'id', label: key, lookup: target }, extra || {});
const field = (key, type) => ({ key, type: type || 'text', label: key });

const contact = {
	id: 5, first_name: 'Ada', last_name: 'Lovelace', email: 'ada@example.org', account_id: 2,
	do_not_contact: 0, do_not_contact_reason: '', owner_id: 4,
	_account_name: 'Acme', _account_id_name: 'Acme', _owner_name: 'Dana Reyes',
};

const schema = {
	contacts: {
		label: 'contact',
		fields: [field('first_name'), field('last_name'), lookup('account_id', 'accounts'), field('email', 'email'),
			field('do_not_contact', 'bool'), field('do_not_contact_reason')],
		layout: [{ title: '', fields: ['first_name', 'last_name', 'account_id', 'email', 'do_not_contact', 'do_not_contact_reason'] }],
		related: [],
	},
	accounts: { label: 'account', fields: [field('name')], layout: [{ title: '', fields: ['name'] }], related: [] },
	opportunities: {
		label: 'opportunity',
		fields: [field('name'), lookup('account_id', 'accounts'),
			lookup('primary_contact_id', 'contacts', { lookup_filter: { account_id: 'account_id' } })],
		layout: [{ title: '', fields: ['name', 'account_id', 'primary_contact_id'] }],
		related: [],
	},
	activities: { label: 'activity', fields: [field('subject')], layout: [{ title: '', fields: ['subject'] }], related: [] },
};

const boot = {
	stages: [{ name: 'Qualification', is_closed: 0, is_won: 0 }, { name: 'Closed Won', is_closed: 1, is_won: 1 }],
	accountTypes: [], industries: [], opportunityTypes: [], leadSources: [], activityTypes: ['Call'],
	activityStatuses: [], priorities: [], interests: {}, owners: [{ id: 4, name: 'Dana Reyes' }], users: [],
	currency: '$', stallDays: 30, frequencies: [], weekdays: {}, emailVariables: [], templates: [], sequences: [], recyclable: {},
	objects: {
		accounts: { label: 'Account', plural: 'Accounts', icon: 'building', color: 1, page: 'pcm-crm-accounts' },
		contacts: { label: 'Contact', plural: 'Contacts', icon: 'id', color: 5, page: 'pcm-crm-contacts' },
		opportunities: { label: 'Opportunity', plural: 'Opportunities', icon: 'awards', color: 7, page: 'pcm-crm-opportunities' },
		activities: { label: 'Activity', plural: 'Activities', icon: 'calendar-alt', color: 6, page: 'pcm-crm-activities' },
	},
};

const requests = [];

function respond(method, route, body) {
	const [pathPart, qs] = route.split('?');
	const query = decodeURIComponent(qs || '');

	if (pathPart === '/bootstrap') { return boot; }
	if (pathPart === '/schema') { return schema; }

	if (method === 'PUT' && pathPart === '/contacts/5') { Object.assign(contact, body); return contact; }
	if (method === 'POST' && pathPart === '/contacts') {
		return Object.assign({ id: 31, first_name: '', account_id: 0 }, body);
	}

	if (pathPart === '/contacts/5') { return contact; }
	if (pathPart === '/related/contacts/5') {
		return { opportunities: [{ id: 7, name: 'Acme pilot', stage_name: 'Qualification', account_id: 2 }], activities: [], enrollments: [] };
	}

	if (pathPart === '/contacts') {
		// A narrowed search only offers the people at that account.
		if (query.indexOf('filters[account_id]=3') !== -1) { return { items: [], total: 0 }; }
		return { items: [contact], total: 1 };
	}

	if (pathPart === '/accounts') {
		return { items: [{ id: 2, name: 'Acme' }, { id: 3, name: 'Bolt' }], total: 2 };
	}

	return { items: [], total: 0 };
}

/* The page ------------------------------------------------------------------ */

const root = makeEl('div');
root.className = 'pcm-crm';
root.dataset.view = 'contacts';
['actions', 'filters', 'body', 'drawer', 'scrim'].forEach(role => {
	const n = makeEl('div');
	n.dataset.role = role;
	if (role === 'drawer' || role === 'scrim') { n.hidden = true; }
	root.appendChild(n);
});

const body = makeEl('body');
body.appendChild(root);

const windowListeners = {};
const pushed = [];

const document = {
	readyState: 'loading',
	body,
	createElement: makeEl,
	createElementNS: (ns, tag) => makeEl(tag),
	createTextNode: text => ({ nodeType: 3, textContent: String(text), parentNode: null }),
	querySelector: sel => (matches(root, sel) ? root : body.querySelector(sel)),
	querySelectorAll: sel => body.querySelectorAll(sel),
	listeners: {},
	addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
	removeEventListener() {},
};

const location = { hash: '', pathname: '/wp-admin/admin.php', search: '?page=pcm-crm-contacts', href: '' };
const errors = [];

const window = {
	document,
	PCM_CRM: { root: 'https://example.test/wp-json/pcm-crm/v1', nonce: 'n', adminUrl: '/wp-admin/admin.php', exportUrl: '/x', exportNonce: 'x' },
	location,
	history: {
		replaceState(s, t, url) { location.hash = url.indexOf('#') === -1 ? '' : url.slice(url.indexOf('#')); },
		pushState(s, t, url) { pushed.push(url); location.hash = url.indexOf('#') === -1 ? '' : url.slice(url.indexOf('#')); },
	},
	getComputedStyle: () => ({ getPropertyValue: () => '#112233' }),
	matchMedia: () => ({ matches: false }),
	setTimeout: fn => { fn(); return 0; },
	clearTimeout() {},
	scrollTo() {},
	console: { log() {}, warn() {}, error: (...a) => errors.push(a.map(String).join(' ')) },
	confirm: () => true,
	prompt: () => '',
	alert() {},
	addEventListener(type, fn) { (windowListeners[type] = windowListeners[type] || []).push(fn); },
	fetch(url, init) {
		const route = String(url).replace(/^.*\/v1/, '');
		const method = (init && init.method) || 'GET';
		const payload = init && init.body ? JSON.parse(init.body) : null;
		requests.push({ method, route, payload });

		return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(respond(method, route, payload)) });
	},
};
window.window = window;

function load(file) {
	// eslint-disable-next-line no-new-func
	new Function('window', 'document', 'console', `with (window) { ${asset(file)} }`)(window, document, window.console);
}

const settle = async () => { for (let i = 0; i < 30; i++) { await new Promise(r => setImmediate(r)); } };

const bodyNode = () => root.querySelector('[data-role="body"]');
const drawer = () => root.querySelector('[data-role="drawer"]');
const fieldWrap = (scope, key) => scope.querySelector(`[data-field="${key}"]`);
// The shim has no descendant combinator, so a nested lookup is two steps.
const within = (scope, outer, inner) => { const o = scope && scope.querySelector(outer); return o ? o.querySelector(inner) : null; };
const saveButton = scope => within(scope, '.pcm-crm-form-footer', '.pcm-btn-primary');

async function main() {
	load('charts.js');
	load('crm.js');

	(document.listeners.DOMContentLoaded || []).forEach(fn => fn());
	await settle();

	/* The list, and a row click. */
	const row = bodyNode().querySelector('tr[tabindex]');
	check('the contacts list draws a clickable row', !!row, true);

	row.click();
	await settle();

	check('clicking a row is a history entry, written the short way', pushed[pushed.length - 1], '/wp-admin/admin.php?page=pcm-crm-contacts#id=5');
	check('the record draws as a page, not the modal', [!!bodyNode().querySelector('.pcm-crm-record-page'), drawer().hidden], [true, true]);
	check('and the list heading steps aside', root.classList.contains('is-record'), true);

	/* Reading. */
	const page = bodyNode().querySelector('.pcm-crm-record-page');
	check('a saved record opens to read, not to a form', !!page.querySelector('.pcm-crm-details.is-reading'), true);

	const accountLink = fieldWrap(page, 'account_id').querySelector('a.pcm-crm-lookup-link');
	check('a lookup reads as a link to the record', accountLink && accountLink.getAttribute('href'), '/wp-admin/admin.php?page=pcm-crm-accounts#id=2');
	check('named, not numbered', accountLink && accountLink.textContent, 'Acme');
	check('a field that does not apply is hidden when read, too', fieldWrap(page, 'do_not_contact_reason').hidden, true);
	check('an email reads as a mailto link', fieldWrap(page, 'email').querySelector('a').getAttribute('href'), 'mailto:ada@example.org');

	accountLink.dispatch('click', { button: 0 });
	check('following a lookup to another object goes to its page', location.href, '/wp-admin/admin.php?page=pcm-crm-accounts#id=2');
	location.href = '';

	/* Editing in place. */
	fieldWrap(page, 'email').querySelector('.pcm-crm-read-edit').click();

	const editing = bodyNode().querySelector('.pcm-crm-details.is-editing');
	check('the pencil turns the panel into the form', !!editing, true);
	check('with Save held at the foot', !!saveButton(editing), true);
	check('the related tabs survive the switch', bodyNode().querySelectorAll('.pcm-crm-panel[data-tab]').map(p => p.dataset.tab).sort(), ['activities', 'details', 'email', 'opportunities']);
	check('the account is a lookup control holding a named pill',
		(fieldWrap(editing, 'account_id').querySelector('.pcm-crm-lookup-pill') || {}).textContent, 'Acme×');

	const email = editing.querySelector('#pcm-crm-field-email');
	email.value = 'ada@analytical.example';
	email.dispatch('input');
	editing.dispatch('input');

	saveButton(editing).click();
	await settle();

	const put = requests.filter(r => r.method === 'PUT').pop();
	check('Save writes the edit', put && put.payload.email, 'ada@analytical.example');
	check('and the record goes back to reading', !!bodyNode().querySelector('.pcm-crm-details.is-reading'), true);
	check('still as a page', !!bodyNode().querySelector('.pcm-crm-record-page'), true);

	/* Back. */
	location.hash = '';
	(windowListeners.popstate || []).forEach(fn => fn({}));
	await settle();

	check('Back returns to the list', [!!bodyNode().querySelector('table'), root.classList.contains('is-record')], [true, false]);

	/* A lookup that searches, narrowed by the form. */
	window.PCM_CRM_App.helpers.openDrawer('opportunities', 0, { prefill: { account_id: 2, _account_id_name: 'Acme' } });
	await settle();

	const form = drawer().querySelector('.pcm-crm-details.is-editing');
	check('New opens the form in the modal', !!form && !drawer().hidden, true);

	const contactInput = fieldWrap(form, 'primary_contact_id').querySelector('input.pcm-crm-lookup-input');
	requests.length = 0;
	contactInput.dispatch('focus');
	await settle();

	const search = requests.find(r => r.route.indexOf('/contacts?') === 0);
	check('focusing searches contacts at the deal’s account', !!search && decodeURIComponent(search.route).indexOf('filters[account_id]=2') !== -1, true);

	const list = fieldWrap(form, 'primary_contact_id').querySelector('.pcm-crm-lookup-list');
	check('and says so over the results', (list.querySelector('.pcm-crm-lookup-heading') || {}).textContent, 'Contacts at Acme');
	check('offering Show all and New after the matches',
		list.querySelectorAll('.pcm-crm-lookup-option').map(o => o.className.split(' ').pop()), ['is-record', 'is-all', 'is-new']);

	contactInput.dispatch('keydown', { key: 'ArrowDown' });
	contactInput.dispatch('keydown', { key: 'Enter' });

	check('the keyboard picks a match into a pill',
		(within(fieldWrap(form, 'primary_contact_id'), '.pcm-crm-lookup-pill', 'a') || {}).textContent, 'Ada Lovelace');

	/* Changing the account clears a contact who is not there. */
	const accountInput = fieldWrap(form, 'account_id').querySelector('input.pcm-crm-lookup-input');
	fieldWrap(form, 'account_id').querySelector('.pcm-crm-lookup-clear').click();
	accountInput.value = 'Bol';
	accountInput.dispatch('input');
	await settle();

	const accountOptions = fieldWrap(form, 'account_id').querySelectorAll('.pcm-crm-lookup-option.is-record');
	const bolt = accountOptions.find(o => o.textContent === 'Bolt');
	bolt.dispatch('mousedown');
	await settle();

	check('a contact at the old account is cleared when the account changes',
		!!fieldWrap(form, 'primary_contact_id').querySelector('.pcm-crm-lookup-pill'), false);

	/* Create from inside the lookup. */
	contactInput.value = 'Babbage';
	contactInput.dispatch('input');
	await settle();

	const newOption = fieldWrap(form, 'primary_contact_id').querySelector('.pcm-crm-lookup-option.is-new');
	check('the empty narrowed search still offers New', !!newOption, true);

	newOption.dispatch('mousedown');
	await settle();

	const quick = root.querySelector('.pcm-crm-quick');
	check('New opens a quick create over the form', !!quick && !quick.hidden, true);
	check('prefilled from what was typed', quick.querySelector('#pcm-crm-field-last_name').getAttribute('value'), 'Babbage');

	saveButton(quick).click();
	await settle();

	const created = requests.filter(r => r.method === 'POST' && r.route === '/contacts').pop();
	check('Create posts the new contact, at the account that narrowed the search', created && [created.payload.last_name, created.payload.account_id], ['Babbage', 3]);
	check('and selects it in the lookup', (within(fieldWrap(form, 'primary_contact_id'), '.pcm-crm-lookup-pill', 'a') || {}).textContent, 'Babbage');
	check('the quick create closes', quick.hidden, true);

	check('nothing was logged to the console as an error', errors, []);

	console.log(failed ? `\n${failed} FAILED` : '\nAll checks passed');
	process.exit(failed ? 1 : 0);
}

main().catch(error => {
	console.log('FAIL the run threw: ' + (error && error.stack || error));
	process.exit(1);
});
