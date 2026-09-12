/**
 * Does the admin app actually start?
 *
 * Everything else about this plugin is checkable without a browser except the
 * one thing most likely to break it: a reference error at boot leaves every
 * screen sitting on "Loading…" forever, with no error shown, because the
 * exception escapes init() before any promise exists to reject.
 *
 * This runs crm.js and charts.js against a shim that is only as real as it
 * needs to be — enough to reach the first fetch, not enough to render.
 *
 * Run with:  node tests/boot.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const assetPath = (file) => path.join(__dirname, '..', 'assets', file);

function makeEl(tag) {
	return {
		tagName: tag,
		dataset: {},
		children: [],
		className: '',
		hidden: false,
		style: {},
		appendChild(child) { this.children.push(child); return child; },
		insertBefore(child) { this.children.unshift(child); return child; },
		replaceChildren() { this.children = []; },
		addEventListener() {},
		querySelector() { return makeEl('div'); },
		querySelectorAll() { return []; },
		setAttribute() {},
		getAttribute() { return null; },
		classList: { add() {}, remove() {}, toggle() {} },
		remove() {},
		focus() {},
		closest() { return null; },
	};
}

function run(view) {
	const root = makeEl('div');
	root.dataset.view = view;

	const requests = [];

	const document = {
		readyState: 'complete',
		querySelector: (sel) => (sel.includes('pcm-crm[data-view]') ? root : makeEl('div')),
		querySelectorAll: () => [],
		createElement: makeEl,
		createElementNS: (ns, tag) => makeEl(tag),
		addEventListener() {},
		body: makeEl('body'),
	};

	const window = {
		document,
		PCM_CRM: { root: 'https://example.test/wp-json/pcm-crm/v1', nonce: 'n', adminUrl: '/wp-admin/admin.php' },
		location: { hash: '', pathname: '/wp-admin/admin.php', search: '' },
		history: { replaceState() {} },
		// Every custom property resolves, so themeColors() gets a full ramp.
		getComputedStyle: () => ({ getPropertyValue: () => '#112233' }),
		setTimeout,
		clearTimeout,
		console,
		confirm: () => true,
		prompt: () => '',
		fetch(url) {
			requests.push(String(url).replace(/^.*\/v1/, ''));
			return Promise.resolve({
				ok: true,
				json: () => Promise.resolve({ items: [], total: 0, stages: [], owners: [], users: [] }),
			});
		},
	};
	window.window = window;

	new Function('window', 'document', fs.readFileSync(assetPath('charts.js'), 'utf8'))(window, document);
	new Function('window', 'document', fs.readFileSync(assetPath('crm.js'), 'utf8'))(window, document);

	return { window, requests };
}

let failed = 0;

function check(label, got, want) {
	const ok = JSON.stringify(got) === JSON.stringify(want);
	if (!ok) { failed++; }
	console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
	if (!ok) { console.log(`    got:  ${JSON.stringify(got)}\n   want:  ${JSON.stringify(want)}`); }
}

// Every screen the menu can land on. A view that throws at boot shows nothing
// but the loading placeholder, so each is worth starting.
const views = ['dashboard', 'accounts', 'contacts', 'opportunities', 'pipeline',
	'activities', 'reports', 'recycle', 'templates', 'sequences', 'schedules'];

views.forEach((view) => {
	let result = null;
	let error = null;

	try {
		result = run(view);
	} catch (e) {
		error = e;
	}

	check(`${view} boots without throwing`, error ? String(error.message) : 'ok', 'ok');

	if (result) {
		// Reaching the first fetch is what proves init() ran to the end.
		check(`${view} asks the server for its data`, result.requests.length > 0, true);
	}
});

// The charts have to expose everything crm.js reaches for, including the theme
// hook added when themes arrived.
const { window } = run('dashboard');
['setTheme', 'bar', 'days', 'funnel', 'donut', 'columns', 'palette', 'formatCurrency'].forEach((fn) => {
	check(`charts expose ${fn}()`, typeof window.PCM_CRM_Charts[fn], 'function');
});

console.log(failed ? `\n${failed} FAILED` : '\nAll checks passed');
process.exit(failed ? 1 : 0);
