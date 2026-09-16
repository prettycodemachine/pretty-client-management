/**
 * Does the app actually leave out what the server would refuse?
 *
 * The gating is a courtesy — every route resolves its own answer regardless of
 * what the browser believes — but a courtesy that silently stops working is
 * worse than none: it offers a New button that 403s, or hides Edit from
 * somebody who has it. Neither shows up as an error anywhere.
 *
 * So this renders the same list screen twice against the real crm.js, once
 * with full grants and once with view only, and asserts the difference.
 *
 * Run with:  node tests/permissions.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { makeEl, matches } = require('./dom');

let failed = 0;
function check(label, got, want) {
	const ok = JSON.stringify(got) === JSON.stringify(want);
	if (!ok) { failed++; }
	console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
	if (!ok) { console.log(`    got:  ${JSON.stringify(got)}\n   want:  ${JSON.stringify(want)}`); }
}

const field = (key, type) => ({ key, type: type || 'text', label: key });

const schema = {
	contacts: {
		label: 'contact',
		fields: [field('first_name'), field('last_name')],
		layout: [{ title: '', fields: ['first_name', 'last_name'] }],
		related: [],
	},
	projects: {
		label: 'project',
		fields: [field('name')],
		layout: [{ title: '', fields: ['name'] }],
		related: [],
	},
};

// The area travels with each object, which is what lets the app answer for a
// module's objects without knowing what belongs to which app.
const objectDirectory = {
	contacts: { label: 'Contact', plural: 'Contacts', icon: 'id', color: 5, page: 'pcm-crm-contacts', area: 'crm' },
	projects: { label: 'Project', plural: 'Projects', icon: 'clipboard', color: 2, page: 'pcm-crm-projects', area: 'pm' },
};

function bootWith(permissions) {
	return {
		stages: [], accountTypes: [], industries: [], opportunityTypes: [], leadSources: [],
		activityTypes: [], activityStatuses: [], priorities: [], interests: {},
		owners: [], users: [], currency: '$', stallDays: 30, frequencies: [], weekdays: {},
		emailVariables: [], templates: [], sequences: [], recyclable: {},
		objects: objectDirectory,
		permissions,
	};
}

/**
 * Every bit of text in a rendered tree.
 *
 * Walked rather than selected: tests/dom.js supports only the selectors the
 * app itself uses, and has no descendant combinator to find a button inside a
 * toolbar with.
 */
function textsIn(node, found) {
	found = found || [];

	if (!node || typeof node !== 'object') { return found; }
	if (node.textContent && !node.children) { found.push(String(node.textContent)); }

	(node.children || []).forEach(child => {
		if (child.textContent && !(child.children || []).length) { found.push(String(child.textContent)); }
		textsIn(child, found);
	});

	return found;
}

function hasText(node, text) {
	return textsIn(node).some(t => t.trim() === text);
}

/**
 * Render the contacts list with the given grants, and return its chrome.
 */
function renderList(permissions) {
	const boot = bootWith(permissions);

	const root = makeEl('div');
	root.className = 'pcm-crm';
	root.dataset.view = 'contacts';

	const roles = {};
	['actions', 'filters', 'body', 'drawer', 'scrim'].forEach(role => {
		const n = makeEl('div');
		n.dataset.role = role;
		if (role === 'drawer' || role === 'scrim') { n.hidden = true; }
		roles[role] = n;
		root.appendChild(n);
	});

	const body = makeEl('body');
	body.appendChild(root);

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

	const settled = [];
	const window = {
		PCM_CRM: {
			root: '/wp-json/pcm-crm/v1',
			nonce: 'n',
			adminUrl: '/wp-admin/admin.php',
			exportUrl: '/wp-admin/admin-post.php',
			exportNonce: 'e',
			currentUser: 1,
			permissions,
		},
		location: { hash: '', pathname: '/wp-admin/admin.php', search: '?page=pcm-crm-contacts', href: '' },
		history: { pushState() {}, replaceState() {} },
		console: { error() {}, warn() {}, log() {} },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		getComputedStyle: () => ({ getPropertyValue: () => '' }),
		addEventListener() {},
		removeEventListener() {},
		scrollTo() {},
		setTimeout: (fn) => { settled.push(fn); return 0; },
		clearTimeout() {},
		requestAnimationFrame: (fn) => { settled.push(fn); return 0; },
		matchMedia: () => ({ matches: false, addListener() {}, addEventListener() {} }),
		fetch(url) {
			const route = String(url).replace('/wp-json/pcm-crm/v1', '').split('?')[0];
			const payload = route === '/bootstrap' ? boot
				: route === '/schema' ? schema
					: { items: [{ id: 5, first_name: 'Ada', last_name: 'Lovelace' }], total: 1 };

			return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(payload) });
		},
	};

	window.window = window;
	window.document = document;

	new Function('window', 'document', fs.readFileSync(path.join(__dirname, '..', 'assets', 'charts.js'), 'utf8'))(window, document);
	new Function('window', 'document', fs.readFileSync(path.join(__dirname, '..', 'assets', 'crm.js'), 'utf8'))(window, document);

	(document.listeners.DOMContentLoaded || []).forEach(fn => fn());

	// Let the bootstrap and schema promises land, then the list fetch.
	return new Promise(resolve => {
		setImmediate(() => setImmediate(() => setImmediate(() => {
			settled.splice(0).forEach(fn => { try { fn(); } catch (e) { /* layout only */ } });
			resolve(roles);
		})));
	});
}

/**
 * The front-end config, built the way wp_localize_script() actually builds
 * it — every value a string, recordId included — rather than the JS object
 * literal every other fixture in this file uses. wp_localize_script() has no
 * concept of a number; it stringifies the whole array. A fixture that hands
 * crm.js a real JS 0 for "no record" never exercises the one case that
 * matters, since 0 and "0" behave identically as numbers but not as
 * booleans — a bare `if (cfg.recordId)` reads the string "0" as truthy,
 * because any non-empty string is.
 *
 * Returns both the rendered mount and every REST path actually requested, so
 * a test can assert the list loaded and not, say, GET /contacts/0.
 */
async function renderFrontList(recordIdAsString) {
	const boot = bootWith({ crm: ['view', 'edit'] });

	const root = makeEl('div');
	root.className = 'pcm-crm';
	root.dataset.view = 'contacts';

	const roles = {};
	['actions', 'filters', 'body', 'drawer', 'scrim'].forEach(role => {
		const n = makeEl('div');
		n.dataset.role = role;
		if (role === 'drawer' || role === 'scrim') { n.hidden = true; }
		roles[role] = n;
		root.appendChild(n);
	});

	const body = makeEl('body');
	body.appendChild(root);

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

	const requested = [];
	const settled = [];
	const window = {
		// Every value a string — host, screens' values, recordId — the exact
		// shape wp_localize_script() actually produces, not a convenient
		// object literal.
		PCM_CRM: {
			root: '/wp-json/pcm-crm/v1', nonce: 'n', adminUrl: '/wp-admin/admin.php',
			exportUrl: '/wp-admin/admin-post.php', exportNonce: 'e', currentUser: '1',
			permissions: { crm: ['view', 'edit'] },
			host: 'front', frontBase: 'https://example.com/staff/',
			screens: { 'pcm-crm-contacts': 'contacts' },
			recordId: recordIdAsString,
		},
		location: { hash: '', pathname: '/staff/contacts/', search: '', href: '' },
		history: { pushState() {}, replaceState() {} },
		console: { error() {}, warn() {}, log() {} },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		getComputedStyle: () => ({ getPropertyValue: () => '' }),
		addEventListener() {}, removeEventListener() {}, scrollTo() {},
		setTimeout: (fn) => { settled.push(fn); return 0; },
		clearTimeout() {},
		requestAnimationFrame: (fn) => { settled.push(fn); return 0; },
		matchMedia: () => ({ matches: false, addListener() {}, addEventListener() {} }),
		fetch(url) {
			const route = String(url).replace('/wp-json/pcm-crm/v1', '').split('?')[0];
			requested.push(route);

			if ('/bootstrap' === route) { return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(boot) }); }
			if ('/schema' === route) { return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(schema) }); }
			if ('/contacts' === route) {
				return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ items: [{ id: 5, first_name: 'Ada', last_name: 'Lovelace' }], total: 1 }) });
			}
			if ('/related/contacts/5' === route) {
				return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) });
			}
			if ('/contacts/5' === route) {
				return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ id: 5, first_name: 'Ada', last_name: 'Lovelace' }) });
			}
			// The list screen makes other background requests unrelated to
			// this test — an Account filter's lookup options among them — so
			// the permissive default renderList() itself uses covers those.
			// Only the one route this test actually cares about gets the
			// real 404 shape, deliberately: /contacts/0 is exactly the
			// request that must never be made.
			if ('/contacts/0' === route) {
				return Promise.resolve({ ok: false, status: 404, json: () => Promise.resolve({ code: 'pcm_crm_not_found', message: 'Record not found.' }) });
			}

			return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ items: [], total: 0 }) });
		},
	};

	window.window = window;
	window.document = document;

	new Function('window', 'document', fs.readFileSync(path.join(__dirname, '..', 'assets', 'charts.js'), 'utf8'))(window, document);
	new Function('window', 'document', fs.readFileSync(path.join(__dirname, '..', 'assets', 'crm.js'), 'utf8'))(window, document);

	(document.listeners.DOMContentLoaded || []).forEach(fn => fn());

	// One round trip more than renderList()'s own fixed three ticks: this
	// fixture's chain is bootstrap+schema, then the list load they unblock,
	// so a fixed depth copied from a shorter chain resolves before the list
	// fetch ever fires. Draining in a loop until nothing is left waiting is
	// what makes that not a number to get right by counting.
	for (let round = 0; round < 8; round++) {
		await new Promise(resolve => setImmediate(resolve));
		settled.splice(0).forEach(fn => { try { fn(); } catch (e) { /* layout only */ } });
	}

	return { roles, requested };
}

(async function () {
	const full = await renderList({ crm: ['view', 'edit', 'delete', 'export'] });

	check('with edit, the list offers New', hasText(full.actions, 'New Contact'), true);
	check('with export, the list offers Export CSV', hasText(full.filters, 'Export CSV'), true);

	const readOnly = await renderList({ crm: ['view'] });

	check('view only hides New', hasText(readOnly.actions, 'New Contact'), false);
	check('view only hides Export CSV', hasText(readOnly.filters, 'Export CSV'), false);
	// The screen itself still works — view only takes the actions away, it does
	// not leave somebody looking at an empty page.
	check('but the list itself still draws', hasText(readOnly.body, 'Account'), true);

	// Granted the other area entirely: the answer is per area, not global, so
	// Projects rights must not unlock the contacts list.
	const otherArea = await renderList({ pm: ['view', 'edit', 'delete', 'export'] });

	check('rights in Projects do not unlock the CRM', hasText(otherArea.actions, 'New Contact'), false);

	// Nothing at all is the shape an old cached bundle would arrive in, and it
	// must fail closed rather than offering everything.
	const none = await renderList({});

	check('no grants at all offers nothing', hasText(none.actions, 'New Contact'), false);

	// wp_localize_script() stringifies cfg.recordId — a plain /staff/contacts/
	// visit (no record in the path) localizes recordId as the string "0", and
	// a bare `if (cfg.recordId)` would read that non-empty string as truthy,
	// try to open record "0", and show "Record not found" instead of the
	// list it was asked for. This is the config exactly as staging produced
	// it when this shipped broken.
	const zeroAsString = await renderFrontList('0');

	check('recordId "0" does not fetch a specific record',
		zeroAsString.requested.includes('/contacts/0'), false);
	check('and the list itself is what loads',
		zeroAsString.requested.includes('/contacts'), true);
	check('the list renders rather than "Record not found"',
		hasText(zeroAsString.roles.body, 'Ada Lovelace'), true);

	// The positive case: a real id in the path does open that record, still
	// arriving as a string.
	const realId = await renderFrontList('5');

	check('a real recordId, still a string, does open that record',
		realId.requested.includes('/contacts/5'), true);

	console.log(failed ? `\n${failed} FAILED` : '\nAll checks passed');
	process.exit(failed ? 1 : 0);
})();
