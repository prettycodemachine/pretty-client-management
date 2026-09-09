/**
 * Checks the parent → child linking in crm.js.
 *
 * The prefill is the substance of "create from a related list": if a key is
 * wrong or missing, the record still saves and simply arrives unlinked, which
 * no error will tell you about. So childTypes() is lifted out of the file and
 * run against a stub.
 *
 * Run with:  node tests/prefill.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'crm.js'), 'utf8');

// Lift the function out by brace matching, so this does not go stale the way a
// copied duplicate would.
const start = src.indexOf('function childTypes(');
if (start === -1) { throw new Error('childTypes() not found in crm.js'); }

let depth = 0, end = start;
for (let i = src.indexOf('{', start); i < src.length; i++) {
	if (src[i] === '{') { depth++; }
	else if (src[i] === '}') { depth--; if (depth === 0) { end = i + 1; break; } }
}

const state = { boot: { stages: [
	{ name: 'Qualification', is_closed: 0 },
	{ name: 'Closed Won', is_closed: 1 }
] } };

// eslint-disable-next-line no-new-func
const childTypes = new Function('state', src.slice(start, end) + '; return childTypes;')(state);

let failed = 0;
function check(label, got, want) {
	const ok = JSON.stringify(got) === JSON.stringify(want);
	if (!ok) { failed++; }
	console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
	if (!ok) { console.log(`    got:  ${JSON.stringify(got)}\n   want:  ${JSON.stringify(want)}`); }
}

const byId = (list, id) => list.find(c => c.id === id);

// --- Account -------------------------------------------------------------
const account = childTypes('accounts', { id: 42, name: 'Acme' });
check('an account offers all three child lists', account.map(c => c.id), ['contacts', 'opportunities', 'activities']);
check('a contact created from an account is linked to it', byId(account, 'contacts').prefill.account_id, 42);
check('an opportunity created from an account is linked to it', byId(account, 'opportunities').prefill.account_id, 42);
check('it opens in the first open stage', byId(account, 'opportunities').prefill.stage_name, 'Qualification');
check('never in a closed stage', byId(account, 'opportunities').prefill.stage_name === 'Closed Won', false);
check('an activity created from an account points at it',
	[byId(account, 'activities').prefill.what_type, byId(account, 'activities').prefill.what_id], ['account', 42]);

// --- Contact -------------------------------------------------------------
const contact = childTypes('contacts', { id: 7, account_id: 42 });
check('a contact offers opportunities and activities', contact.map(c => c.id), ['opportunities', 'activities']);
check('a deal created from a contact carries both the person and their account',
	[byId(contact, 'opportunities').prefill.primary_contact_id, byId(contact, 'opportunities').prefill.account_id], [7, 42]);

// What they asked about on the contact form travels onto the deal.
const interested = childTypes('contacts', { id: 7, account_id: 42, service_interest: 'AI Enablement' });
check('a deal inherits what the contact asked about',
	byId(interested, 'opportunities').prefill.service_interest, 'AI Enablement');
check('a contact with no recorded interest leaves it blank',
	byId(contact, 'opportunities').prefill.service_interest, '');
check('an activity created from a contact is linked to the person',
	byId(contact, 'activities').prefill.who_id, 7);
check('and to their account', 
	[byId(contact, 'activities').prefill.what_type, byId(contact, 'activities').prefill.what_id], ['account', 42]);

// A contact with no account must not claim to belong to account zero.
const orphan = childTypes('contacts', { id: 7, account_id: 0 });
check('an account-less contact leaves the parent link empty',
	[byId(orphan, 'activities').prefill.what_type, byId(orphan, 'activities').prefill.what_id], ['', 0]);

// --- Opportunity ---------------------------------------------------------
const opp = childTypes('opportunities', { id: 9, primary_contact_id: 7 });
check('an opportunity offers activities', opp.map(c => c.id), ['activities']);
check('an activity created from a deal points at the deal',
	[byId(opp, 'activities').prefill.what_type, byId(opp, 'activities').prefill.what_id], ['opportunity', 9]);
check('and carries its primary contact', byId(opp, 'activities').prefill.who_id, 7);

// --- Activity ------------------------------------------------------------
check('an activity has no children of its own', childTypes('activities', { id: 3 }), []);

// --- Dates ---------------------------------------------------------------
const closeDate = byId(account, 'opportunities').prefill.close_date;
check('a new deal gets a close date in the future', closeDate > new Date().toISOString().slice(0, 10), true);
check('the close date is a plain ISO date', /^\d{4}-\d{2}-\d{2}$/.test(closeDate), true);

console.log(failed ? `\n${failed} FAILED` : '\nAll checks passed');
process.exit(failed ? 1 : 0);
