/**
 * Contrast check for every admin theme.
 *
 * A theme is a palette anyone can switch to, so "is it readable" stops being a
 * judgement made once and becomes a property each one has to hold. This reads
 * the tokens straight out of the stylesheet rather than duplicating them, so a
 * new theme is covered the moment it is written.
 *
 * Run with:  node tests/contrast.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const css = fs.readFileSync(path.join(__dirname, '..', 'assets', 'crm.css'), 'utf8');

const channels = (hex) => [1, 3, 5].map((i) => parseInt(hex.substr(i, 2), 16));

const luminance = (hex) => {
	const linear = channels(hex).map((value) => {
		value /= 255;
		return value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
	});

	return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
};

const ratio = (a, b) => {
	const [l1, l2] = [luminance(a), luminance(b)];
	return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
};

function tokensIn(block) {
	const found = {};
	for (const match of block.matchAll(/--pcm-([a-z0-9-]+):\s*(#[0-9a-f]{6})/g)) {
		found[match[1]] = match[2];
	}
	return found;
}

function blockFor(selector) {
	const start = css.indexOf(selector);
	if (start === -1) { throw new Error('No block for ' + selector); }
	return css.slice(start, css.indexOf('}', start));
}

// The base declaration every theme inherits from before overriding.
const base = tokensIn(blockFor('.pcm-crm {'));

// Discovered rather than listed, so a theme added to the stylesheet is checked
// without anyone remembering to add it here.
const themes = [...new Set([...css.matchAll(/\[data-theme="([a-z]+)"\] \{/g)].map((m) => m[1]))];

let failed = 0;

function check(theme, label, foreground, background, minimum) {
	if (!foreground || !background) { return; }

	const measured = ratio(foreground, background);
	const ok = measured >= minimum;

	if (!ok) { failed++; }

	console.log(
		`${ok ? 'PASS' : 'FAIL'}  ${theme.padEnd(8)}${label.padEnd(26)}${measured.toFixed(2)}:1 (needs ${minimum})`
	);
}

console.log(`Checking ${themes.length + 1} themes\n`);

[['pcm (default)', base]].concat(
	themes.map((name) => [name, Object.assign({}, base, tokensIn(blockFor(`[data-theme="${name}"] {`)))])
).forEach(([name, t]) => {
	// Body text is the one that has to clear AA; headings are larger and the
	// accent is only ever used on a ground, never as a ground behind text.
	check(name, 'body on paper', t.body, t.paper, 4.5);
	check(name, 'body on tint', t.body, t['paper-tint'], 4.5);
	check(name, 'ink on paper', t.ink, t.paper, 4.5);
	check(name, 'accent text on paper', t['accent-deep'], t.paper, 4.5);
	// The muted grey carries text too — empty states, chart labels, the quiet
	// half of a timestamp — so it gets the text threshold, not the 3:1 a
	// border would be held to.
	check(name, 'muted on paper', t.gray, t.paper, 4.5);
	console.log('');
});

console.log(failed ? `${failed} pairings fail` : 'Every theme is readable');
process.exit(failed ? 1 : 0);
