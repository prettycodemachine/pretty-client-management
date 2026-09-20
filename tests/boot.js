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
		style: { setProperty() {} },
		appendChild(child) { this.children.push(child); return child; },
		insertBefore(child) { this.children.unshift(child); return child; },
		// Views that position one control relative to another walk these, so
		// they have to exist rather than be undefined.
		get firstChild() { return this.children[0] || null; },
		get nextSibling() { return null; },
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

function run(view, hash) {
	const root = makeEl('div');
	root.dataset.view = view;

	const requests = [];

	const document = {
		readyState: 'complete',
		querySelector: (sel) => (sel.includes('pcm-crm[data-view]') ? root : makeEl('div')),
		querySelectorAll: () => [],
		createElement: makeEl,
		createElementNS: (ns, tag) => makeEl(tag),
		createTextNode: (text) => ({ nodeType: 3, textContent: String(text) }),
		addEventListener() {},
		body: makeEl('body'),
	};

	const window = {
		document,
		PCM_CRM: { root: 'https://example.test/wp-json/pcm-crm/v1', nonce: 'n', adminUrl: '/wp-admin/admin.php' },
		// A hash of #id=5 opens that record on load — the same path a
		// notification email's "Open in the CRM" link takes.
		location: { hash: hash || '', pathname: '/wp-admin/admin.php', search: '' },
		history: { replaceState() {} },
		// Every custom property resolves, so themeColors() gets a full ramp.
		getComputedStyle: () => ({ getPropertyValue: () => '#112233' }),
		setTimeout,
		clearTimeout,
		console,
		confirm: () => true,
		prompt: () => '',
		fetch(url) {
			const route = String(url).replace(/^.*\/v1/, '');
			requests.push(route);

			// Shaped per route. A stub that answers everything with the same
			// object makes half the views throw on their own data, and that
			// noise is what hides a real failure.
			let body = { items: [], total: 0 };

			if (route.startsWith('/bootstrap')) {
				body = {
					stages: [], accountTypes: [], industries: [], opportunityTypes: [],
					leadSources: [], activityTypes: [], activityStatuses: [], priorities: [],
					interests: {}, owners: [], users: [], currency: '$', stallDays: 30,
					frequencies: [], weekdays: {}, emailVariables: [], templates: [], sequences: [],
					recyclable: {},
				};
			}

			if (route.startsWith('/schema')) {
				body = {};
			}

			if (route.startsWith('/dashboard')) {
				body = {
					tiles: {
						openValue: 0, openCount: 0, weightedValue: 0, wonValue: 0, wonCount: 0,
						winRate: 0, contactCount: 0, accountCount: 0, overdueCount: 0,
						cycleDays: 0, stalledCount: 0, stallDays: 30,
					},
					charts: {
						pipelineByStage: [], byMonth: [], byLeadSource: [],
						activityByType: [], avgDaysByStage: [], conversion: [],
					},
				};
			}

			if (route.startsWith('/pipeline')) { body = { columns: [] }; }
			if (route.startsWith('/report')) { body = { rows: [], groups: [], total: 0, sum: 0 }; }
			if (route.startsWith('/recycle-bin')) {
				body = [
					{ object: 'accounts', label: 'Accounts', count: 0 },
					{ object: 'contacts', label: 'Contacts', count: 0 },
					{ object: 'opportunities', label: 'Opportunities', count: 0 },
					{ object: 'activities', label: 'Activities', count: 0 },
				];
			}

			if (/^\/[a-z]+\/\d+(\?|$)/.test(route)) {
				body = { id: 5, name: 'Sample', subject: 'Sample', account_id: 0, who_id: 0, is_deleted: 0 };
			}

			if (route.startsWith('/related/')) {
				body = { contacts: [], opportunities: [], activities: [] };
			}

			return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
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

/**
 * Let the boot promises settle.
 *
 * Everything after init() is a chain of resolved promises, so the assertions
 * have to wait a turn or they read the request list before anything asked for
 * anything.
 */
const settle = () => new Promise((resolve) => setImmediate(resolve));

(async function () {
	// Opening a record has to reach for what hangs off it. The guard deciding
	// this reads a mode from each object's definition, so an object that
	// forgets to declare one loses its related lists silently — no error, just
	// a record with one tab. That is exactly how it went unnoticed once.
	const relatedChecks = [
		['accounts', true],
		['contacts', true],
		['opportunities', true],
		// A leaf: its section is built from the record rather than fetched, so
		// it must *not* ask for a route that does not exist.
		['activities', false],
	];

	for (const [view, shouldFetch] of relatedChecks) {
		const { requests } = run(view, '#id=5');

		await settle();
		await settle();
		await settle();

		const asked = requests.some((route) => route.startsWith('/related/'));

		check(`${view} ${shouldFetch ? 'fetches' : 'does not fetch'} its related records`, asked, shouldFetch);
	}

	// The charts have to expose everything crm.js reaches for, including the
	// theme hook added when themes arrived.
	const { window } = run('dashboard');

	['setTheme', 'bar', 'days', 'funnel', 'donut', 'columns', 'palette', 'formatCurrency'].forEach((fn) => {
		check(`charts expose ${fn}()`, typeof window.PCM_CRM_Charts[fn], 'function');
	});

	console.log(failed ? `\n${failed} FAILED` : '\nAll checks passed');
	process.exit(failed ? 1 : 0);
})();
