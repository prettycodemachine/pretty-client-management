/**
 * A DOM just real enough to click through, shared by the suites that run the
 * real crm.js and click it: tests/drawer.js and tests/record.js.
 *
 * Elements keep the listeners the app attaches, so a test can click what the
 * app built; selectors cover what the app actually uses — tag, #id, .class,
 * [data-x="y"], :not(...) and comma lists — and no more.
 */
'use strict';


function makeEl(tag) {
	const node = {
		tagName: String(tag).toUpperCase(),
		nodeType: 1,
		children: [],
		parentNode: null,
		dataset: {},
		attrs: {},
		listeners: {},
		className: '',
		hidden: false,
		value: '',
		checked: false,
		style: { setProperty() {} },
		_text: '',
		get textContent() { return this._text + this.children.map(c => c.textContent || '').join(''); },
		set textContent(v) { this._text = String(v); this.children = []; },
		set innerHTML(v) { this._text = String(v); },
		classList: null,
		appendChild(child) {
			if (child && child.parentNode) { child.parentNode.removeChild(child); }
			if (child) { child.parentNode = node; this.children.push(child); }
			return child;
		},
		insertBefore(child, ref) {
			if (child) { child.parentNode = node; }
			const i = this.children.indexOf(ref);
			if (i === -1) { this.children.push(child); } else { this.children.splice(i, 0, child); }
			return child;
		},
		replaceChild(next, prev) {
			const i = this.children.indexOf(prev);
			if (next.parentNode) { next.parentNode.removeChild(next); }
			next.parentNode = node;
			if (i === -1) { this.children.push(next); } else { this.children[i] = next; prev.parentNode = null; }
			return prev;
		},
		removeChild(child) {
			const i = this.children.indexOf(child);
			if (i !== -1) { this.children.splice(i, 1); }
			child.parentNode = null;
			return child;
		},
		remove() { if (this.parentNode) { this.parentNode.removeChild(this); } },
		replaceChildren(...kids) { this.children = []; this._text = ''; kids.forEach(k => this.appendChild(k)); },
		get firstChild() { return this.children[0] || null; },
		get nextSibling() {
			if (!this.parentNode) { return null; }
			const sibs = this.parentNode.children;
			return sibs[sibs.indexOf(this) + 1] || null;
		},
		addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
		removeEventListener() {},
		dispatch(type, extra) {
			const event = Object.assign({ type, target: node, currentTarget: node, preventDefault() {}, stopPropagation() {}, key: '' }, extra);
			(this.listeners[type] || []).forEach(fn => fn(event));
		},
		click() { this.dispatch('click'); },
		// A data-* attribute and dataset are one thing in a real browser, and
		// the app writes both ways — the panels container is found by a
		// data-role it was given through setAttribute.
		setAttribute(k, v) {
			this.attrs[k] = String(v);
			if (k === 'class') { this.className = String(v); }
			if (k.indexOf('data-') === 0) { this.dataset[k.slice(5).replace(/-([a-z])/g, (x, c) => c.toUpperCase())] = String(v); }
		},
		getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; },
		removeAttribute(k) { delete this.attrs[k]; },
		hasAttribute(k) { return k in this.attrs; },
		focus() {},
		blur() {},
		scrollIntoView() {},
		getBoundingClientRect() { return { top: 0, left: 0, width: 0, height: 0 }; },
		closest(sel) {
			let n = node;
			while (n) { if (matches(n, sel)) { return n; } n = n.parentNode; }
			return null;
		},
		querySelector(sel) { return queryAll(node, sel)[0] || null; },
		querySelectorAll(sel) { const found = queryAll(node, sel); found.forEach = Array.prototype.forEach; return found; },
		matches(sel) { return matches(node, sel); },
	};

	node.classList = {
		add(...c) { node.className = Array.from(new Set(node.className.split(' ').filter(Boolean).concat(c))).join(' '); },
		remove(...c) { node.className = node.className.split(' ').filter(x => c.indexOf(x) === -1).join(' '); },
		toggle(c, on) { (on === undefined ? !this.contains(c) : on) ? this.add(c) : this.remove(c); },
		contains(c) { return node.className.split(' ').indexOf(c) !== -1; },
	};

	return node;
}

// Selectors the app actually uses: .class, [data-x="y"], [data-x], :not(...),
// and compound forms of those. Enough, and no more.
function matches(node, selector) {
	return selector.split(',').some(sel => matchOne(node, sel.trim()));
}

function matchOne(node, sel) {
	if (!node || node.nodeType !== 1) { return false; }

	let rest = sel;
	const nots = [];
	rest = rest.replace(/:not\(([^)]+)\)/g, (m, inner) => { nots.push(inner); return ''; });

	if (nots.some(n => matchOne(node, n))) { return false; }

	const tag = rest.match(/^[a-z][a-z0-9]*/i);
	if (tag && node.tagName !== tag[0].toUpperCase()) { return false; }

	const id = rest.match(/#([\w-]+)/);
	if (id && node.getAttribute('id') !== id[1]) { return false; }

	for (const m of rest.matchAll(/\.([\w-]+)/g)) {
		if (!node.classList.contains(m[1])) { return false; }
	}

	for (const m of rest.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)) {
		const attr = m[1];
		let actual = null;

		if (attr.indexOf('data-') === 0) {
			const key = attr.slice(5).replace(/-([a-z])/g, (x, c) => c.toUpperCase());
			actual = key in node.dataset ? String(node.dataset[key]) : null;
		} else {
			actual = node.getAttribute(attr);
		}

		if (actual === null) { return false; }
		if (m[2] !== undefined && actual !== m[2]) { return false; }
	}

	return true;
}

function queryAll(root, selector) {
	const out = [];
	(function walk(n) {
		n.children.forEach(child => {
			if (child.nodeType === 1) {
				if (matches(child, selector)) { out.push(child); }
				walk(child);
			}
		});
	})(root);
	return out;
}


module.exports = { makeEl, matches, queryAll };
