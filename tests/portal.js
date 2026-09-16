/**
 * The client portal's pure arithmetic — dates, percentages, and where a
 * Gantt bar lands.
 *
 * These are the mistakes this file exists for, none of which throw:
 *
 *   - `new Date('2026-09-01')` is UTC midnight, so a milestone due the 1st
 *     renders as 8/31 for every reader west of Greenwich. It looks like a
 *     plausible date, which is why nobody catches it by reading the screen.
 *   - a percentage clamped at 100 alongside an uncapped bar, which turns
 *     98.5h against a 94h estimate into "100%" — the one reading that says
 *     the opposite of the truth.
 *   - a Gantt range taken from the project's dates alone, which silently
 *     drops every milestone that slipped past the planned end.
 *
 * The helpers are lifted out of assets/portal.js by name rather than copied,
 * the way tests/pm-views.js reads crm.js's own vocabulary, so this goes stale
 * loudly instead of quietly.
 *
 * Run with:  node tests/portal.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'portal.js'), 'utf8');

let failed = 0;
function check(label, got, want) {
	const ok = JSON.stringify(got) === JSON.stringify(want);
	if (!ok) { failed++; }
	console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
	if (!ok) { console.log(`    got:  ${JSON.stringify(got)}\n   want:  ${JSON.stringify(want)}`); }
}

/**
 * One function declaration's source, matched by counting braces from its
 * opening one — a regex to the closing brace would stop at the first `}`
 * inside the body.
 */
function lift(name) {
	const at = src.indexOf(`function ${name}(`);
	if (at === -1) { throw new Error(`portal.js no longer declares ${name}() — this suite is out of date.`); }

	let depth = 0;
	for (let i = src.indexOf('{', at); i < src.length; i++) {
		if (src[i] === '{') { depth++; }
		if (src[i] === '}') { depth--; }
		if (depth === 0) { return src.slice(at, i + 1); }
	}

	throw new Error(`unbalanced braces reading ${name}()`);
}

const names = ['parseDate', 'formatDate', 'today', 'daysBetween', 'days', 'percent', 'ganttRange', 'ganttOffset', 'milestoneState', 'milestoneClass', 'projectLabel', 'documentByline'];

// DAY_MS is a module constant rather than a function, so it comes across on
// its own — lifted from the source too, not restated here.
const dayMs = src.match(/var DAY_MS = (\d+);/);
if (!dayMs) { throw new Error('portal.js no longer declares DAY_MS — this suite is out of date.'); }

// eslint-disable-next-line no-new-func
const lifted = new Function(`
	'use strict';
	var DAY_MS = ${dayMs[1]};
	${names.map(lift).join('\n')}
	return { ${names.join(', ')} };
`)();

const { parseDate, formatDate, today, daysBetween, days, percent, ganttRange, ganttOffset, milestoneState, milestoneClass, projectLabel, documentByline } = lifted;

/* Dates. ------------------------------------------------------------------- */

check('a date-only string parses to local midnight on the day it names, not the day before',
	[parseDate('2026-09-01').getFullYear(), parseDate('2026-09-01').getMonth(), parseDate('2026-09-01').getDate()],
	[2026, 8, 1]);
check('a datetime keeps its time of day', parseDate('2026-09-01 14:30:00').getHours(), 14);
check("MySQL's zero date is a blank, not the year 0", parseDate('0000-00-00'), null);
check('so is an empty string', parseDate(''), null);
check('and so is null', parseDate(null), null);
check('rubbish does not become a date', parseDate('soon'), null);

check('a window counted between two dates is the days across it',
	daysBetween(parseDate('2026-01-01'), parseDate('2026-01-31')), 30);
check('and is unaffected by a daylight-saving boundary inside it',
	daysBetween(parseDate('2026-03-01'), parseDate('2026-04-01')), 31);

check('one day is singular', days(1), '1 day');
check('and everything else is not', days(2.4), '2 days');

/* Percentages. ------------------------------------------------------------- */

check('a percentage rounds to a whole number', percent(12.5, 16.48), 76);
check('an overrun reads past 100, because that is the honest number',
	percent(98.5, 94), 105);
check('nothing to measure against is no percentage at all, not 0%',
	percent(10, 0), null);
check('and neither is a missing allowance', percent(10, null), null);

/* The project switcher's label. -------------------------------------------- */

check('an account name is prepended to a plain project name',
	projectLabel({ name: 'Copilot Rollout', account_name: 'Kestrel Analytics' }),
	'Kestrel Analytics — Copilot Rollout');
check('and left alone when the project name already starts with it, so it is not said twice',
	projectLabel({ name: 'Kestrel Analytics — Data Migration', account_name: 'Kestrel Analytics' }),
	'Kestrel Analytics — Data Migration');
check('the match against an existing prefix ignores case',
	projectLabel({ name: 'kestrel analytics — data migration', account_name: 'Kestrel Analytics' }),
	'kestrel analytics — data migration');
check('a project with no account is just its own name', projectLabel({ name: 'Internal Tooling', account_name: '' }), 'Internal Tooling');

/* A document's byline. ------------------------------------------------------ */

check('a byline names who uploaded it and when',
	documentByline({ uploaded_by: 'Jason Jensen', created_date: '2026-01-25 14:03:00' }),
	'Uploaded by Jason Jensen · ' + formatDate('2026-01-25 14:03:00'));
check('an unresolved uploader is left out rather than shown as a blank name',
	documentByline({ uploaded_by: '', created_date: '2026-01-25' }),
	formatDate('2026-01-25'));
check('nothing dated and nothing named is an empty byline, not a stray separator',
	documentByline({ uploaded_by: '', created_date: '' }), '');

/* The Gantt's window. ------------------------------------------------------ */

const project = { start_date: '2026-01-01', end_date: '2026-06-30' };
const milestones = [
	{ name: 'Kickoff', status: 'Done', due_date: '2026-02-01' },
	{ name: 'UAT', status: 'Planned', due_date: '2026-09-15' }
];

const range = ganttRange(milestones, project);

check('the charted window holds the planned start',
	[range.start.getFullYear(), range.start.getMonth(), range.start.getDate()], [2026, 0, 1]);
check('and stretches past the planned end to hold a milestone that slipped',
	[range.end.getFullYear(), range.end.getMonth(), range.end.getDate()], [2026, 8, 15]);
check('a date at the window start sits at 0%', ganttOffset(range, range.start), 0);
check('a date at the window end sits at 100%', ganttOffset(range, range.end), 100);
check('a date before the window is clamped rather than drawn off the chart',
	ganttOffset(range, parseDate('2025-01-01')), 0);
check('and a date after it likewise', ganttOffset(range, parseDate('2030-01-01')), 100);

// A single milestone and no project dates: the window would be zero days wide,
// which puts every bar at 0% and draws nothing.
const tight = ganttRange([{ due_date: '2026-05-10' }], {});
check('a one-date window is padded, so it has a scale to position against',
	daysBetween(tight.start, tight.end) >= 14, true);
check('nothing dated at all is no chart', ganttRange([], {}), null);

check('a project with no dates still charts against its milestones',
	ganttOffset(ganttRange(milestones, {}), parseDate('2026-02-01')), 0);

/* A milestone's state. ----------------------------------------------------- */

const yesterday = new Date(today().getTime() - Number(dayMs[1]));
const tomorrow = new Date(today().getTime() + Number(dayMs[1]));
const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

check('a milestone still to come is Planned',
	milestoneState({ status: 'Planned', due_date: iso(tomorrow) }), 'Planned');
check('one whose date has passed is Past due',
	milestoneState({ status: 'Planned', due_date: iso(yesterday) }), 'Past due');
check('a hit milestone is Done whatever its date said',
	milestoneState({ status: 'Done', due_date: iso(yesterday) }), 'Done');
check('one due today has not slipped yet',
	milestoneState({ status: 'Planned', due_date: iso(today()) }), 'Planned');
check('an undated milestone is Planned, not overdue',
	milestoneState({ status: 'Planned', due_date: '' }), 'Planned');

// The class and the badge are one decision, so the stylesheet's selector and
// the word beside it can never disagree.
check('the chart class follows the state word', milestoneClass({ status: 'Planned', due_date: iso(yesterday) }), 'is-past-due');
check('and matches a selector the stylesheet actually has',
	fs.readFileSync(path.join(__dirname, '..', 'assets', 'portal.css'), 'utf8').includes('.pcm-portal-gantt-bar.is-past-due'),
	true);

console.log(failed ? `\n${failed} check(s) failed` : '\nThe portal arithmetic holds');
process.exit(failed ? 1 : 0);
