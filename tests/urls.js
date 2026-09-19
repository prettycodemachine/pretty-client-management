/**
 * screenUrl() — the one place "wp-admin or the front end" gets decided.
 *
 * Boots the real crm.js once against a minimal fixture and calls the function
 * it actually registers on PCM_CRM_App.helpers, rather than a copy — the same
 * discipline tests/prefill.js uses for childTypes().
 *
 * Run with:  node tests/urls.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

let failed = 0;
function check(label, got, want) {
	const ok = JSON.stringify(got) === JSON.stringify(want);
	if (!ok) { failed++; }
	console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
	if (!ok) { console.log(`    got:  ${JSON.stringify(got)}\n   want:  ${JSON.stringify(want)}`); }
}

function makeEl() {
	return {
		dataset: {}, children: [], className: '', hidden: false, style: {},
		appendChild(c) { this.children.push(c); return c; },
		insertBefore(c) { this.children.unshift(c); return c; },
		get firstChild() { return this.children[0] || null; },
		get nextSibling() { return null; },
		replaceChildren() { this.children = []; },
		addEventListener() {}, querySelector() { return makeEl(); }, querySelectorAll() { return []; },
		setAttribute() {}, getAttribute() { return null; },
	};
}

const root = makeEl();
root.className = 'pcm-crm';
root.dataset.view = 'contacts';

const document = {
	readyState: 'complete',
	body: makeEl(),
	createElement: makeEl,
	createElementNS: () => makeEl(),
	createTextNode: text => ({ nodeType: 3, textContent: String(text), parentNode: null }),
	querySelector: () => null, // no mount — init() returns immediately, boot only registers PCM_CRM_App
	querySelectorAll: () => [],
	listeners: {},
	addEventListener() {}, removeEventListener() {},
};

// cfg is captured by reference at crm.js load time (`var cfg = window.PCM_CRM || {}`),
// so mutating this same object's properties between cases is what lets one
// boot serve every case below, rather than reloading the script per case.
const PCM_CRM = { root: '/wp-json/pcm-crm/v1', nonce: 'n', adminUrl: '/wp-admin/admin.php', host: 'admin' };

const window = {
	PCM_CRM,
	location: { hash: '', pathname: '/wp-admin/admin.php', search: '', href: '' },
	history: { pushState() {}, replaceState() {} },
	console: { error() {}, warn() {}, log() {} },
	localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	getComputedStyle: () => ({ getPropertyValue: () => '' }),
	addEventListener() {}, removeEventListener() {}, scrollTo() {},
	setTimeout: () => 0, clearTimeout() {}, requestAnimationFrame: () => 0,
	matchMedia: () => ({ matches: false, addListener() {}, addEventListener() {} }),
	fetch: () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }),
};
window.window = window;
window.document = document;

new Function('window', 'document', fs.readFileSync(path.join(__dirname, '..', 'assets', 'charts.js'), 'utf8'))(window, document);
new Function('window', 'document', fs.readFileSync(path.join(__dirname, '..', 'assets', 'crm.js'), 'utf8'))(window, document);

const screenUrl = window.PCM_CRM_App.helpers.screenUrl;

// --- admin host: unchanged shape, whatever else is set ---
PCM_CRM.host = 'admin';
check('admin: a plain screen link', screenUrl('pcm-crm-contacts'), '/wp-admin/admin.php?page=pcm-crm-contacts');
check('admin: a record link carries #id=', screenUrl('pcm-crm-contacts', 5), '/wp-admin/admin.php?page=pcm-crm-contacts#id=5');
check('admin: a fragment is kept when there is no id', screenUrl('pcm-crm-reports', 0, '#foo'), '/wp-admin/admin.php?page=pcm-crm-reports#foo');
check('admin: id wins over a fragment if somehow both are passed',
	screenUrl('pcm-crm-contacts', 5, '#foo'), '/wp-admin/admin.php?page=pcm-crm-contacts#id=5');

// --- front host, with a route for the screen ---
PCM_CRM.host = 'front';
PCM_CRM.frontBase = 'https://example.com/staff/';
PCM_CRM.screens = { 'pcm-crm-contacts': 'contacts', 'pcm-crm-reports': 'reports' };

check('front: a plain screen link', screenUrl('pcm-crm-contacts'), 'https://example.com/staff/contacts/');
check('front: a record link is a path segment, not a hash',
	screenUrl('pcm-crm-contacts', 5), 'https://example.com/staff/contacts/5/');
check('front: a fragment with no id is appended after the trailing slash',
	screenUrl('pcm-crm-reports', 0, '#foo'), 'https://example.com/staff/reports/#foo');

// --- front host, screen has no route yet (PCM Settings, before it is wired) ---
check('front: an unmapped screen falls through to the admin shape rather than linking nowhere',
	screenUrl('pcm-crm-settings'), '/wp-admin/admin.php?page=pcm-crm-settings');

// --- front host but no frontBase configured (misconfiguration) — same fallback ---
PCM_CRM.frontBase = '';
check('front: no frontBase at all also falls through',
	screenUrl('pcm-crm-contacts'), '/wp-admin/admin.php?page=pcm-crm-contacts');

console.log(failed ? `\n${failed} FAILED` : '\nAll checks passed');
process.exit(failed ? 1 : 0);
