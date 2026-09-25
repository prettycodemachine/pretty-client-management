/**
 * The CRM admin app.
 *
 * Plain JS against the pcm-crm/v1 REST API. No framework and no build step —
 * the repo has neither, and the screens here are a list, a form, a board and a
 * few charts, which is well inside what small render functions handle.
 *
 * The pattern throughout: state lives in one object, every change calls
 * render(), and render() replaces a container's children rather than patching
 * them. Filter state is mirrored into the URL hash, so a filtered view is
 * linkable and survives a reload.
 */
(function (window, document) {
	'use strict';

	var cfg = window.PCM_CRM || {};
	var charts = window.PCM_CRM_Charts;

	// wp_localize_script() stringifies every value it hands to JS, cfg.recordId
	// included — so an unset id arrives here as the string "0", not the number
	// 0, and a bare `if (cfg.recordId)` reads that as truthy: a non-empty
	// string is always truthy in JS, whatever it contains. Normalised once,
	// here, rather than at every place cfg.recordId is read or compared —
	// state.recordId is always a real number (Number(id) wherever it is set),
	// and cfg.recordId has to match that or every comparison between the two
	// silently fails instead of erroring.
	cfg.recordId = Number(cfg.recordId) || 0;

	/* ---------------------------------------------------------------------
	   Permissions

	   A copy of what the server already decided, carried so the app can leave
	   out a button the server would refuse rather than offering one that
	   errors. It is never the decision — every route resolves its own answer,
	   and hiding a control is a courtesy, not a gate.
	   --------------------------------------------------------------------- */

	/**
	 * Read from the config first and the bootstrap second.
	 *
	 * The nav and the app bar are drawn before /bootstrap resolves, so the
	 * localized copy has to answer during that window; once the fetch lands
	 * the two say the same thing.
	 */
	function grants() {
		return (state.boot && state.boot.permissions) || cfg.permissions || {};
	}

	function can(area, action) {
		var allowed = grants()[area];

		return !!allowed && allowed.indexOf(action || 'view') !== -1;
	}

	/**
	 * The same question about an object, which knows its own area.
	 *
	 * The area travels with the object in /bootstrap rather than being mapped
	 * here, so a module's objects answer correctly without this file learning
	 * what belongs to which app.
	 */
	function canObject(object, action) {
		var def = state.boot && state.boot.objects && state.boot.objects[object];

		return can((def && def.area) || 'crm', action);
	}

	/* ---------------------------------------------------------------------
	   DOM helpers
	   --------------------------------------------------------------------- */

	/**
	 * el('div.foo', { attr: v }, [children])
	 *
	 * Text children are set through textContent, never innerHTML, so a record
	 * whose name contains markup renders as its name rather than as markup.
	 */
	function el(spec, attrs, children) {
		var parts = spec.split('.');
		var node = document.createElement(parts.shift() || 'div');

		if (parts.length) { node.className = parts.join(' '); }

		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === null || value === undefined || value === false) { return; }

			if (key === 'text') { node.textContent = value; }
			else if (key === 'html') { node.innerHTML = value; }
			else if (key.indexOf('on') === 0 && typeof value === 'function') {
				node.addEventListener(key.slice(2).toLowerCase(), value);
			} else if (key === 'dataset') {
				Object.keys(value).forEach(function (k) { node.dataset[k] = value[k]; });
			} else if (value === true) { node.setAttribute(key, ''); }
			else { node.setAttribute(key, value); }
		});

		(children || []).forEach(function (child) {
			if (child === null || child === undefined || child === false) { return; }
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});

		return node;
	}

	function clear(node, replacement) {
		node.replaceChildren();
		if (replacement) { node.appendChild(replacement); }
	}

	/* ---------------------------------------------------------------------
	   Formatting
	   --------------------------------------------------------------------- */

	function money(value) {
		if (value === null || value === undefined || value === '') { return '—'; }
		return (cfg.currency || '$') + Number(value).toLocaleString(undefined, {
			minimumFractionDigits: 0,
			maximumFractionDigits: 0
		});
	}

	/**
	 * Dates arrive as MySQL strings in site time. Parsing them with the Date
	 * constructor would treat them as UTC in some browsers and local in
	 * others, so they are split by hand — a close date must not drift a day.
	 */
	function formatDate(value) {
		if (!value || String(value).indexOf('0000') === 0) { return '—'; }

		var parts = String(value).slice(0, 10).split('-');
		if (parts.length !== 3) { return value; }

		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		return months[Number(parts[1]) - 1] + ' ' + Number(parts[2]) + ', ' + parts[0];
	}

	/**
	 * A date with its time, for the audit stamps — "modified today" is not
	 * much use without knowing whether that was before or after your own edit.
	 */
	function formatDateTime(value) {
		if (!value || String(value).indexOf('0000') === 0) { return '—'; }

		var parts = String(value).split(' ');
		var date = formatDate(parts[0]);

		if (parts.length < 2) { return date; }

		var clock = parts[1].split(':');
		var hour = Number(clock[0]);
		var suffix = hour >= 12 ? 'pm' : 'am';

		hour = hour % 12;
		if (hour === 0) { hour = 12; }

		return date + ' at ' + hour + ':' + clock[1] + ' ' + suffix;
	}

	function today() {
		var now = new Date();
		return now.getFullYear() + '-' +
			String(now.getMonth() + 1).padStart(2, '0') + '-' +
			String(now.getDate()).padStart(2, '0');
	}

	function isOverdue(activity) {
		return !activity.is_completed && activity.due_date && activity.due_date < today();
	}

	/**
	 * The current theme's colours, read from the CSS rather than duplicated.
	 *
	 * The stylesheet is the single source of truth for a palette; asking the
	 * browser what it resolved means a new theme needs no JS change at all,
	 * and a chart can never disagree with the interface around it.
	 */
	function themeColors() {
		if (!dom.root || !window.getComputedStyle) { return {}; }

		var styles = window.getComputedStyle(dom.root);
		var read = function (name) { return String(styles.getPropertyValue(name) || '').trim(); };

		var ramp = [];
		for (var i = 1; i <= 8; i++) {
			var color = read('--pcm-chart-' + i);
			if (color) { ramp.push(color); }
		}

		return {
			ink: read('--pcm-ink'),
			body: read('--pcm-body'),
			muted: read('--pcm-gray'),
			track: read('--pcm-paper-tint'),
			grid: read('--pcm-gray-light'),
			// Text sitting on a filled bar or slice: that is the fill colour's
			// problem, not the page ground's.
			onFill: read('--pcm-paper'),
			palette: ramp.length ? ramp : undefined
		};
	}

	/* ---------------------------------------------------------------------
	   API
	   --------------------------------------------------------------------- */

	function api(path, options) {
		options = options || {};

		var url = cfg.root + path;

		if (options.query) {
			var qs = buildQuery(options.query);
			if (qs) { url += (url.indexOf('?') === -1 ? '?' : '&') + qs; }
		}

		return window.fetch(url, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: options.body ? JSON.stringify(options.body) : undefined
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) {
					throw new Error((data && data.message) || 'Request failed (' + response.status + ')');
				}
				return data;
			});
		});
	}

	/**
	 * Serialise nested query params the way PHP reads them back:
	 * filters[stage_name]=Proposal, filters[close_date][min]=2026-01-01.
	 */
	function buildQuery(params, prefix) {
		var pairs = [];

		Object.keys(params).forEach(function (key) {
			var value = params[key];
			if (value === '' || value === null || value === undefined) { return; }

			var name = prefix ? prefix + '[' + key + ']' : key;

			if (value && typeof value === 'object' && !Array.isArray(value)) {
				var nested = buildQuery(value, name);
				if (nested) { pairs.push(nested); }
			} else if (Array.isArray(value)) {
				value.forEach(function (item) {
					pairs.push(encodeURIComponent(name + '[]') + '=' + encodeURIComponent(item));
				});
			} else {
				pairs.push(encodeURIComponent(name) + '=' + encodeURIComponent(value));
			}
		});

		return pairs.join('&');
	}

	/* ---------------------------------------------------------------------
	   State
	   --------------------------------------------------------------------- */

	// Which report the Reports screen is showing. Declared beside the rest of
	// the state because the hash carries it, and the hash is read before the
	// Reports view is ever built.
	var report = { object: 'opportunities', groupBy: 'stage_name' };

	var state = {
		view: '',
		boot: null,
		schema: null,
		drillTitle: '',
		binObject: '',
		query: { search: '', filters: {}, orderby: '', order: 'DESC', page: 1, per_page: 25 },
		recordId: 0,
		creating: null
	};

	var dom = {};

	/**
	 * Mirror the query into the hash, and read it back on load.
	 *
	 * JSON in a hash is ugly but honest: the filter shape is nested, and
	 * flattening it into individual params would need parsing rules on both
	 * sides that the two would eventually disagree about.
	 */
	/**
	 * @param {boolean} push A move between a list and a record is a history
	 *                       entry, so Back returns; a filter change replaces
	 *                       the current one, so Back does not step through
	 *                       every checkbox.
	 */
	function writeHash(push) {
		var payload = {
			s: state.query.search || undefined,
			f: Object.keys(state.query.filters).length ? state.query.filters : undefined,
			o: state.query.orderby || undefined,
			d: state.query.order !== 'DESC' ? state.query.order : undefined,
			p: state.query.page > 1 ? state.query.page : undefined,
			id: state.recordId || undefined,
			ro: state.view === 'reports' ? report.object : undefined,
			rg: state.view === 'reports' ? report.groupBy : undefined,
			t: state.drillTitle || undefined
		};

		var pruned = {};
		Object.keys(payload).forEach(function (key) {
			if (payload[key] !== undefined) { pruned[key] = payload[key]; }
		});

		var hash = Object.keys(pruned).length ? '#' + encodeURIComponent(JSON.stringify(pruned)) : '';

		// A record on its own is written the short way, the same form the
		// notification email links to — unless the path itself already names
		// this record (the front end's /staff/contacts/5/), in which case a
		// second #id=5 alongside it would be pure noise.
		if (state.recordId && pageMode(state.view) && !(cfg.host === 'front' && cfg.recordId === state.recordId)) {
			hash = '#id=' + state.recordId;
		}

		if (hash !== window.location.hash) {
			var url = window.location.pathname + window.location.search + hash;

			if (push && window.history.pushState) { window.history.pushState(null, '', url); }
			else { window.history.replaceState(null, '', url); }
		}
	}

	function readHash() {
		var raw = window.location.hash.replace(/^#/, '');
		if (!raw) { return; }

		// The notification email links to #id=123, a form a human might also
		// type, so that shorthand is accepted alongside the JSON payload.
		var short = raw.match(/^id=(\d+)$/);
		if (short) {
			state.recordId = Number(short[1]);
			return;
		}

		try {
			var data = JSON.parse(decodeURIComponent(raw));
			state.query.search = data.s || '';
			state.query.filters = data.f || {};
			state.query.orderby = data.o || '';
			state.query.order = data.d || 'DESC';
			state.query.page = data.p || 1;
			state.recordId = data.id || 0;
			state.drillTitle = data.t || '';

			if (data.ro) { report.object = data.ro; }
			if (data.rg) { report.groupBy = data.rg; }
		} catch (e) {
			// A hash we cannot parse is not worth failing the page over.
		}
	}

	/* ---------------------------------------------------------------------
	   Object definitions

	   Columns for the list view and fields for the drawer, in one place per
	   object so the two cannot drift.
	   --------------------------------------------------------------------- */

	function options(list, includeBlank) {
		var out = includeBlank ? [{ value: '', label: '—' }] : [];
		(list || []).forEach(function (item) {
			if (typeof item === 'string') { out.push({ value: item, label: item }); }
			else if (item.name !== undefined) { out.push({ value: item.name, label: item.name }); }
			else { out.push(item); }
		});
		return out;
	}

	/**
	 * The stage definition behind a stage name.
	 */
	function stageByName(name) {
		return ((state.boot && state.boot.stages) || []).filter(function (stage) {
			return stage.name === name;
		})[0] || null;
	}

	function stageIsLost(name) {
		var stage = stageByName(name);

		return !!(stage && Number(stage.is_closed) && !Number(stage.is_won));
	}

	/**
	 * What a schedule actually sends, in words.
	 *
	 * Inherited from the screen it was created on rather than asked for — a
	 * dashboard schedule made from the dashboard could only ever be the
	 * dashboard, and offering the choice again invited it to be answered
	 * wrongly.
	 */
	function scheduleContents(row) {
		if (row.report_type !== 'report') { return 'Dashboard'; }

		var object = objects[row.object] ? objects[row.object].plural : row.object;
		var grouped = row.group_by ? ' grouped by ' + row.group_by.replace(/_id$/, '').replace(/_/g, ' ') : '';

		return object + grouped;
	}

	/**
	 * Recipients are stored as a comma-separated mix of user ids and plain
	 * addresses; this renders them as names where it can.
	 */
	function recipientSummary(raw) {
		return parseRecipients(raw).map(function (entry) {
			return entry.user ? entry.user.name : entry.email;
		}).join(', ');
	}

	function parseRecipients(raw) {
		var users = (state.boot && state.boot.users) || [];

		return String(raw || '').split(/[,;\s]+/).filter(Boolean).map(function (token) {
			if (/^\d+$/.test(token)) {
				var match = users.filter(function (u) { return String(u.id) === token; })[0];
				return { id: token, user: match || null, email: match ? match.email : token };
			}

			return { email: token, user: null };
		});
	}

	function weekdayOptions() {
		var days = (state.boot && state.boot.weekdays) || {};

		return Object.keys(days).map(function (key) { return { value: key, label: days[key] }; });
	}

	/**
	 * A schedule's timing in one readable line.
	 */
	function scheduleSummary(row) {
		var days = (state.boot && state.boot.weekdays) || {};
		var at = ' at ' + (row.send_time || '08:00');

		if (row.frequency === 'daily') { return 'Every day' + at; }
		if (row.frequency === 'weekly') { return 'Every ' + (days[row.day_of_week] || 'Monday') + at; }

		return 'Day ' + (row.day_of_month || 1) + ' of the month' + at;
	}

	function ownerOptions() {
		return [{ value: '', label: 'Anyone' }].concat((state.boot.owners || []).map(function (owner) {
			return { value: owner.id, label: owner.name };
		}));
	}

	/**
	 * Per-object definitions: how a record reads in a list, in a header, and
	 * which filters sit permanently in the bar.
	 *
	 * The four CRM objects deliberately declare no field list. Their record
	 * forms are built from the layout the server sends, because those layouts
	 * are editable — a copy here would be a second source of truth that drifts
	 * the first time someone rearranges one. Only objects outside that system,
	 * like schedules, still declare their own.
	 */
	/**
	 * Screens that are not a list of one object: dashboard, pipeline, reports,
	 * recycle bin. A map rather than an if-chain so a module can register its
	 * own without this file knowing the name.
	 */
	var views = {};

	/**
	 * Field types that build their own control. Each entry carries the two
	 * things the three built-in ones all did by hand — force the field wide,
	 * emit a label — as data, so an entry that wants neither can say so.
	 */
	var controls = {};

	/** slug => [function (record, helpers)] returning extra child types. */
	var childProviders = {};

	/** related-list key => function (row) returning that list's cells. */
	var relatedRenderers = {};

	/** slug => [function (record, related, helpers)] returning extra record tabs. */
	var recordTabs = {};

	/** slug => [function (record, helpers)] returning extra header action nodes. */
	var recordActions = {};

	/** [function (key, values, scope)] run when a form field changes. */
	var derivers = [];

	/** Callbacks waiting for /bootstrap and /schema. */
	var readyQueue = [];

	var objects = {
		accounts: {
			label: 'Account',
			// 'fetch' has a /related route behind it; 'local' builds its section
			// from the record itself. An object declaring neither has no related
			// section at all, which is what templates and schedules want.
			related: 'fetch',
			plural: 'Accounts',
			title: function (row) { return row.name; },
			kicker: function (row) { return row.type || 'Account'; },
			highlights: function (row) {
				return [
					{ label: 'Industry', value: row.industry },
					{ label: 'Phone', value: row.phone, href: row.phone ? 'tel:' + row.phone : '' },
					{ label: 'Website', value: row.website, href: row.website },
					{ label: 'Location', value: [row.billing_city, row.billing_state].filter(Boolean).join(', ') },
					{ label: 'Owner', value: row._owner_name }
				];
			},
			columns: [
				{ key: 'name', label: 'Name', strong: true },
				{ key: 'type', label: 'Type', badge: true },
				{ key: 'industry', label: 'Industry' },
				{ key: 'phone', label: 'Phone' },
				{ key: '_owner_name', label: 'Owner' },
				{ key: 'last_modified_date', label: 'Modified', date: true }
			],
			filters: function () {
				return [
					{ key: 'type', label: 'Type', options: options(state.boot.accountTypes, true), blank: 'Any type' },
					{ key: 'industry', label: 'Industry', options: options(state.boot.industries, true), blank: 'Any industry' },
					{ key: 'owner_id', label: 'Owner', options: ownerOptions() }
				];
			},
},

		contacts: {
			label: 'Contact',
			related: 'fetch',
			plural: 'Contacts',
			title: function (row) {
				return ((row.first_name || '') + ' ' + (row.last_name || '')).trim() || row.email || 'Contact';
			},
			// The account is what gives a person context, so it sits above
			// the name the way Salesforce puts the parent record there.
			kicker: function (row) { return row._account_name || 'No account'; },
			kickerLink: function (row) {
				return row.account_id ? { object: 'accounts', id: row.account_id } : null;
			},
			highlights: function (row) {
				return [
					{ label: 'Title', value: row.title },
					{ label: 'Email', value: row.email, href: row.email ? 'mailto:' + row.email : '' },
					{ label: 'Mobile', value: row.mobile_phone, href: row.mobile_phone ? 'tel:' + row.mobile_phone : '' },
					{ label: 'Phone', value: row.phone, href: row.phone ? 'tel:' + row.phone : '' },
					{ label: 'Owner', value: row._owner_name }
				];
			},
			columns: [
				{ key: 'last_name', label: 'Name', strong: true, render: function (row) { return objects.contacts.title(row); } },
				{ key: 'title', label: 'Title' },
				{ key: '_account_name', label: 'Account', link: 'account_id' },
				{ key: 'email', label: 'Email' },
				{ key: 'phone', label: 'Phone' },
				{ key: 'lead_source', label: 'Source', badge: true },
				{ key: 'created_date', label: 'Created', date: true }
			],
			filters: function () {
				return [
					{ key: 'lead_source', label: 'Source', options: options(state.boot.leadSources, true), blank: 'Any source' },
					{ key: 'account_id', label: 'Account', lookup: 'accounts' },
					{ key: 'owner_id', label: 'Owner', options: ownerOptions() }
				];
			},
},

		opportunities: {
			label: 'Opportunity',
			related: 'fetch',
			plural: 'Opportunities',
			title: function (row) { return row.name; },
			kicker: function (row) { return row._account_name || 'No account'; },
			kickerLink: function (row) {
				return row.account_id ? { object: 'accounts', id: row.account_id } : null;
			},
			// The Salesforce path: every stage in order, the deal's marked.
			path: function (row) {
				return {
					field: 'stage_name',
					current: row.stage_name,
					stages: (state.boot.stages || []).map(function (stage) {
						return { name: stage.name, closed: !!Number(stage.is_closed), lost: stageIsLost(stage.name) };
					})
				};
			},
			highlights: function (row) {
				return [
					{ label: 'Primary contact', value: row._primary_contact_id_name || row._contact_name,
						link: row.primary_contact_id ? { object: 'contacts', id: row.primary_contact_id } : null },
					{ label: 'Stage', value: row.stage_name },
					{ label: 'Amount', value: money(row.amount) },
					{ label: 'Close date', value: formatDate(row.close_date) },
					{ label: 'Probability', value: row.probability + '%' },
					{ label: 'Owner', value: row._owner_name }
				];
			},
			columns: [
				{ key: 'name', label: 'Name', strong: true },
				{ key: '_account_name', label: 'Account', link: 'account_id' },
				{ key: 'stage_name', label: 'Stage', stage: true },
				{ key: 'amount', label: 'Amount', money: true },
				{ key: 'probability', label: '%', render: function (row) { return row.probability + '%'; }, num: true },
				{ key: 'close_date', label: 'Close', date: true },
				// Sorted on stage_entered_date, the real column behind it —
				// ascending puts the longest-sitting deals first, which is the
				// order worth looking at.
				{ key: 'stage_entered_date', label: 'In stage', age: true },
				{ key: '_owner_name', label: 'Owner' }
			],
			filters: function () {
				return [
					{ key: 'stage_name', label: 'Stage', options: options(state.boot.stages, true), blank: 'Any stage' },
					{ key: 'type', label: 'Type', options: options(state.boot.opportunityTypes, true), blank: 'Any type' },
					{ key: 'is_closed', label: 'Status', options: [
						{ value: '', label: 'All' },
						{ value: '0', label: 'Open' },
						{ value: '1', label: 'Closed' }
					] },
					{ key: 'close_date', label: 'Closing', range: 'date' },
					{ key: 'owner_id', label: 'Owner', options: ownerOptions() }
				];
			},
},

		templates: {
			label: 'Template',
			plural: 'Email Templates',
			title: function (row) { return row.name || 'Template'; },
			kicker: function (row) { return Number(row.is_active) ? 'Active' : 'Inactive'; },
			highlights: function (row) {
				return [
					{ label: 'Subject', value: row.subject },
					{ label: 'Status', value: Number(row.is_active) ? 'Active' : 'Inactive' },
					{ label: 'Owner', value: row._owner_name }
				];
			},
			columns: [
				{ key: 'name', label: 'Name', strong: true },
				{ key: 'subject', label: 'Subject' },
				{ key: 'is_active', label: 'Status', render: function (row) { return Number(row.is_active) ? 'Active' : 'Inactive'; } },
				{ key: 'last_modified_date', label: 'Modified', date: true }
			],
			filters: function () {
				return [{ key: 'is_active', label: 'Status', options: [
					{ value: '', label: 'All' },
					{ value: '1', label: 'Active' },
					{ value: '0', label: 'Inactive' }
				] }];
			},
			fields: function () {
				return [
					{ fields: [
						{ key: 'name', label: 'Name', required: true, wide: true },
						{ key: 'subject', label: 'Subject', required: true, wide: true },
						{ key: 'is_active', label: 'Active', type: 'checkbox' }
					] },
					{ title: 'Message', fields: [
						{ key: 'body', label: 'Body', type: 'email-body', wide: true }
					] }
				];
			}
		},

		sequences: {
			label: 'Sequence',
			plural: 'Sequences',
			title: function (row) { return row.name || 'Sequence'; },
			kicker: function (row) { return Number(row.is_active) ? 'Active' : 'Paused'; },
			highlights: function (row) {
				return [
					{ label: 'Steps', value: String(sequenceSteps(row).length) },
					{ label: 'Status', value: Number(row.is_active) ? 'Active' : 'Paused' },
					{ label: 'Owner', value: row._owner_name }
				];
			},
			columns: [
				{ key: 'name', label: 'Name', strong: true },
				{ key: 'description', label: 'Description' },
				{ key: 'steps', label: 'Steps', render: function (row) { return String(sequenceSteps(row).length); } },
				{ key: 'is_active', label: 'Status', render: function (row) { return Number(row.is_active) ? 'Active' : 'Paused'; } }
			],
			filters: function () {
				return [{ key: 'is_active', label: 'Status', options: [
					{ value: '', label: 'All' },
					{ value: '1', label: 'Active' },
					{ value: '0', label: 'Paused' }
				] }];
			},
			fields: function () {
				return [
					{ fields: [
						{ key: 'name', label: 'Name', required: true, wide: true },
						{ key: 'description', label: 'Description', wide: true },
						{ key: 'is_active', label: 'Active', type: 'checkbox' }
					] },
					{ title: 'Steps', fields: [
						{ key: 'steps', label: 'Steps', type: 'sequence-steps', wide: true }
					] }
				];
			}
		},

		schedules: {
			label: 'Scheduled Report',
			plural: 'Scheduled Reports',
			title: function (row) { return row.name || 'Scheduled report'; },
			kicker: function (row) { return row.report_type === 'dashboard' ? 'Dashboard' : 'Report'; },
			highlights: function (row) {
				return [
					{ label: 'Contents', value: scheduleContents(row) },
					{ label: 'Sends', value: scheduleSummary(row) },
					{ label: 'To', value: recipientSummary(row.recipients) },
					{ label: 'Last sent', value: formatDateTime(row.last_sent) },
					{ label: 'Status', value: Number(row.is_active) ? 'Active' : 'Paused' }
				];
			},
			columns: [
				{ key: 'name', label: 'Name', strong: true },
				{ key: 'report_type', label: 'Sends', render: function (row) { return scheduleContents(row); } },
				{ key: 'frequency', label: 'When', render: function (row) { return scheduleSummary(row); } },
				{ key: 'recipients', label: 'Recipients', render: function (row) { return recipientSummary(row.recipients); } },
				{ key: 'is_active', label: 'Status', render: function (row) { return Number(row.is_active) ? 'Active' : 'Paused'; } },
				{ key: 'last_sent', label: 'Last sent', date: true }
			],
			rowActions: function () {
				return [{
					label: 'Cancel',
					title: 'Stop this schedule and remove it',
					danger: true,
					run: function (row, reload) {
						if (!window.confirm('Cancel “' + (row.name || 'this schedule') + '”? It will stop sending.')) { return; }

						api('/schedules/' + row.id, { method: 'DELETE' }).then(reload).catch(showError);
					}
				}];
			},
			// No filter bar: a handful of subscriptions with a Cancel on each
			// reads better than a search box over five rows, and a filter
			// builder over them would be furniture.
			noFilters: true,
			fields: function () {
				return [
					{ fields: [
						{ key: 'name', label: 'Name', required: true, wide: true },
						{ key: 'recipients', label: 'Recipients', wide: true, type: 'recipients' },
						{ key: 'frequency', label: 'Frequency', options: options(state.boot.frequencies || []) },
						{ key: 'send_time', label: 'Send at', type: 'time' },
						{ key: 'day_of_week', label: 'Day of week',
							options: weekdayOptions(), showWhen: 'frequency', showWhenValue: 'weekly' },
						{ key: 'day_of_month', label: 'Day of month', type: 'number',
							showWhen: 'frequency', showWhenValue: 'monthly' },
						{ key: 'is_active', label: 'Active', type: 'checkbox' },
						{ key: 'attach_csv', label: 'Attach CSV', type: 'checkbox' }
					] }
				];
			}
		},

		activities: {
			label: 'Activity',
			// A leaf: nothing hangs off an activity, so its section lists the
			// records it hangs off instead, built from the record in hand.
			related: 'local',
			plural: 'Activities',
			title: function (row) { return row.subject || 'Activity'; },
			kicker: function (row) { return row.activity_type || 'Activity'; },
			highlights: function (row) {
				return [
					{ label: 'Status', value: row.status },
					{ label: 'Contact', value: row._contact_name },
					{ label: 'Due', value: formatDate(row.due_date) },
					{ label: 'Logged', value: formatDate(row.activity_date) },
					{ label: 'Owner', value: row._owner_name }
				];
			},
			columns: [
				{ key: 'subject', label: 'Subject', strong: true },
				{ key: 'activity_type', label: 'Type', badge: true },
				{ key: '_contact_name', label: 'Contact' },
				{ key: 'status', label: 'Status', badge: true },
				{ key: 'due_date', label: 'Due', due: true },
				{ key: 'activity_date', label: 'Logged', date: true },
				{ key: '_owner_name', label: 'Owner' }
			],
			filters: function () {
				return [
					{ key: 'activity_type', label: 'Type', options: options(state.boot.activityTypes, true), blank: 'Any type' },
					{ key: 'status', label: 'Status', options: options(state.boot.activityStatuses, true), blank: 'Any status' },
					{ key: 'is_completed', label: 'Done', options: [
						{ value: '', label: 'All' },
						{ value: '0', label: 'Open' },
						{ value: '1', label: 'Completed' }
					] },
					{ key: 'due_date', label: 'Due', range: 'date' },
					{ key: 'owner_id', label: 'Owner', options: ownerOptions() }
				];
			},
}
	};

	/* ---------------------------------------------------------------------
	   Lookup cache

	   Account and contact pickers are the same few hundred rows on every
	   screen, so they are fetched once per page load rather than per drawer.
	   --------------------------------------------------------------------- */

	var lookups = {};

	function loadLookup(object) {
		if (lookups[object]) { return Promise.resolve(lookups[object]); }

		return api('/' + object, {
			// Contacts are the one object without a name column. Everything else
			// sorts by name — including a module's objects, which would otherwise
			// ask for last_name, get it refused, and arrive in modified order.
			query: { per_page: 500, orderby: object === 'contacts' ? 'last_name' : 'name', order: 'ASC' }
		}).then(function (data) {
			lookups[object] = data.items.map(function (row) {
				return { value: row.id, label: objects[object].title(row) };
			});
			return lookups[object];
		});
	}

	function lookupLabel(object, id) {
		var list = lookups[object] || [];
		for (var i = 0; i < list.length; i++) {
			if (Number(list[i].value) === Number(id)) { return list[i].label; }
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	   Filter bar
	   --------------------------------------------------------------------- */

	function renderFilters(definition, onChange, extra, object) {
		var bar = dom.filters;
		clear(bar);

		bar.appendChild(el('div.pcm-crm-filter.pcm-crm-filter-search', {}, [
			el('label', { text: 'Search', for: 'pcm-crm-search' }),
			el('input', {
				type: 'search',
				id: 'pcm-crm-search',
				value: state.query.search,
				placeholder: 'Search…',
				oninput: debounce(function (event) {
					state.query.search = event.target.value;
					state.query.page = 1;
					onChange();
				}, 300)
			})
		]));

		(definition || []).forEach(function (filter) {
			if (filter.range) {
				bar.appendChild(rangeFilter(filter, onChange));
			} else if (filter.lookup) {
				bar.appendChild(lookupFilter(filter, onChange));
			} else {
				bar.appendChild(selectFilter(filter, onChange));
			}
		});

		var actions = el('div.pcm-crm-filter-actions', {}, [
			el('span.pcm-crm-filter-count', { 'data-role': 'count' }),
			el('button.pcm-btn.pcm-btn-quiet', {
				type: 'button',
				text: 'Clear',
				onclick: function () {
					state.query.search = '';
					state.query.filters = {};
					state.query.page = 1;
					// render() rebuilds the bar and reloads on its own; calling
					// onChange as well fetches the same rows twice.
					render();
				}
			})
		]);

		(extra || []).forEach(function (node) { actions.appendChild(node); });
		bar.appendChild(actions);

		// The builder sits on its own row below the fixed controls: its chips
		// wrap unpredictably, and mixed in among the dropdowns they would push
		// those around as filters come and go.
		if (object && state.schema && state.schema[object]) {
			bar.appendChild(builderRow(object, onChange));
		}
	}

	/* ---------------------------------------------------------------------
	   Filter builder

	   The fixed filter bar covers the handful of fields worth a permanent
	   control. This covers the rest: any field on the object, and any field
	   on a parent it can be reached through.
	   --------------------------------------------------------------------- */

	/**
	 * Every field that can be filtered, flattened, each carrying the name of
	 * the object it came from.
	 *
	 * That name is what makes a cross-object filter legible: "Industry" on an
	 * opportunity list is meaningless until it says Account beside it.
	 */
	function filterableFields(object) {
		var schema = state.schema && state.schema[object];
		if (!schema) { return []; }

		var groups = [{ label: objects[object].label, fields: schema.fields }];

		(schema.related || []).forEach(function (related) {
			groups.push({ label: related.label, fields: related.fields, isRelated: true });
		});

		var flat = [];

		groups.forEach(function (group) {
			group.fields.forEach(function (field) {
				flat.push(Object.assign({}, field, { group: group.label, isRelated: !!group.isRelated }));
			});
		});

		return flat;
	}

	function findField(object, key) {
		return filterableFields(object).filter(function (field) { return field.key === key; })[0] || null;
	}

	/**
	 * The operators that make sense for a field's type.
	 *
	 * A picklist gets "is / is not", not "contains" — offering a substring
	 * match against a fixed set of values invites filters that look right and
	 * return nothing.
	 */
	function operatorsFor(field) {
		if (!field) { return []; }

		if (field.options) {
			return [
				{ value: 'eq', label: 'is' },
				{ value: 'ne', label: 'is not' },
				{ value: 'empty', label: 'is blank' },
				{ value: 'notempty', label: 'is not blank' }
			];
		}

		if (field.type === 'bool') {
			return [{ value: 'eq', label: 'is' }];
		}

		if (field.type === 'date' || field.type === 'datetime') {
			return [
				{ value: 'eq', label: 'on' },
				{ value: 'gte', label: 'on or after' },
				{ value: 'lte', label: 'on or before' },
				{ value: 'between', label: 'between' },
				{ value: 'empty', label: 'is blank' }
			];
		}

		if (field.type === 'int' || field.type === 'decimal' || field.type === 'id') {
			return [
				{ value: 'eq', label: '=' },
				{ value: 'ne', label: '≠' },
				{ value: 'gt', label: '>' },
				{ value: 'lt', label: '<' },
				{ value: 'between', label: 'between' },
				{ value: 'empty', label: 'is blank' }
			];
		}

		return [
			{ value: 'contains', label: 'contains' },
			{ value: 'notcontains', label: 'does not contain' },
			{ value: 'eq', label: 'is' },
			{ value: 'starts', label: 'starts with' },
			{ value: 'empty', label: 'is blank' },
			{ value: 'notempty', label: 'is not blank' }
		];
	}

	function operatorLabel(field, op) {
		var match = operatorsFor(field).filter(function (o) { return o.value === op; })[0];
		return match ? match.label : op;
	}

	function valueLabel(field, filter) {
		if (filter.op === 'empty' || filter.op === 'notempty') { return ''; }
		if (filter.op === 'between') { return (filter.min || '…') + ' – ' + (filter.max || '…'); }

		if (field && field.options) {
			var match = field.options.filter(function (o) { return String(o.value) === String(filter.value); })[0];
			if (match) { return match.label; }
		}

		if (field && field.type === 'bool') { return Number(filter.value) ? 'Yes' : 'No'; }

		return String(filter.value === undefined ? '' : filter.value);
	}

	/**
	 * The built filters as chips, plus the control that adds one.
	 */
	function builderRow(object, onChange) {
		var row = el('div.pcm-crm-builder');
		var built = builtFilters(object);

		built.forEach(function (entry) {
			var field = findField(object, entry.key);

			row.appendChild(el('span.pcm-crm-chip' + (field && field.isRelated ? '.is-related' : ''), {}, [
				// The object name rides on every chip, so a filter on a
				// parent's field never reads as one of this object's own.
				el('span.pcm-crm-chip-object', { text: field ? field.group : '?' }),
				el('span.pcm-crm-chip-field', { text: field ? field.label : entry.key }),
				el('span.pcm-crm-chip-op', { text: operatorLabel(field, entry.filter.op) }),
				el('span.pcm-crm-chip-value', { text: valueLabel(field, entry.filter) }),
				el('button.pcm-crm-chip-remove', {
					type: 'button',
					'aria-label': 'Remove this filter',
					text: '×',
					onclick: function () {
						delete state.query.filters[entry.key];
						state.query.page = 1;
						render();
					}
				})
			]));
		});

		row.appendChild(el('button.pcm-btn.pcm-btn-quiet.pcm-btn-sm', {
			type: 'button',
			text: built.length ? '+ Add another filter' : '+ Add filter',
			onclick: function (event) { openBuilder(object, onChange, event.target); }
		}));

		return row;
	}

	/**
	 * Filters that came from the builder rather than from a fixed control.
	 *
	 * The fixed bar owns a few keys; showing those as chips as well would give
	 * one filter two places to be removed from.
	 */
	function builtFilters(object) {
		var fixed = (objects[object].filters ? objects[object].filters() : []).map(function (f) { return f.key; });
		var out = [];

		Object.keys(state.query.filters).forEach(function (key) {
			if (fixed.indexOf(key) !== -1) { return; }

			var value = state.query.filters[key];

			out.push({
				key: key,
				filter: (value && typeof value === 'object' && value.op) ? value : { op: 'eq', value: value }
			});
		});

		return out;
	}

	/**
	 * The add-a-filter panel: field, then operator, then value.
	 */
	function openBuilder(object, onChange, anchor) {
		var existing = dom.root.querySelector('.pcm-crm-builder-panel');
		if (existing) { existing.remove(); }

		var fields = filterableFields(object);
		var draft = { key: fields.length ? fields[0].key : '', op: '', value: '', min: '', max: '' };

		var panel = el('div.pcm-crm-builder-panel');
		var fieldSelect = el('select');

		// Grouped by object, which is the whole point — an <optgroup> per
		// table says where each field comes from without a legend.
		var groups = {};
		fields.forEach(function (field) {
			if (!groups[field.group]) {
				groups[field.group] = el('optgroup', { label: field.group });
				fieldSelect.appendChild(groups[field.group]);
			}
			groups[field.group].appendChild(el('option', { value: field.key, text: field.label }));
		});

		var opSelect = el('select');
		var valueWrap = el('div.pcm-crm-builder-value');

		function refresh() {
			var field = findField(object, draft.key);
			var ops = operatorsFor(field);

			if (!ops.filter(function (o) { return o.value === draft.op; }).length) {
				draft.op = ops.length ? ops[0].value : 'eq';
			}

			clear(opSelect);
			ops.forEach(function (op) {
				opSelect.appendChild(el('option', { value: op.value, text: op.label, selected: op.value === draft.op }));
			});

			clear(valueWrap);
			valueWrap.appendChild(valueControl(field, draft));
		}

		fieldSelect.addEventListener('change', function (event) {
			draft.key = event.target.value;
			draft.value = '';
			draft.min = '';
			draft.max = '';
			refresh();
		});

		opSelect.addEventListener('change', function (event) {
			draft.op = event.target.value;
			refresh();
		});

		refresh();

		panel.appendChild(el('div.pcm-crm-builder-fields', {}, [fieldSelect, opSelect, valueWrap]));
		panel.appendChild(el('div.pcm-crm-builder-actions', {}, [
			el('button.pcm-btn.pcm-btn-primary.pcm-btn-sm', {
				type: 'button',
				text: 'Apply',
				onclick: function () {
					var filter = { op: draft.op };

					if (draft.op === 'between') {
						filter.min = draft.min;
						filter.max = draft.max;
					} else if (draft.op !== 'empty' && draft.op !== 'notempty') {
						if (draft.value === '' || draft.value === undefined) { return; }
						filter.value = draft.value;
					}

					state.query.filters[draft.key] = filter;
					state.query.page = 1;
					panel.remove();
					render();
				}
			}),
			el('button.pcm-btn.pcm-btn-quiet.pcm-btn-sm', {
				type: 'button',
				text: 'Cancel',
				onclick: function () { panel.remove(); }
			})
		]));

		anchor.parentNode.insertBefore(panel, anchor.nextSibling);
		fieldSelect.focus();
	}

	function valueControl(field, draft) {
		if (draft.op === 'empty' || draft.op === 'notempty') {
			return el('span.pcm-crm-muted', { text: 'No value needed' });
		}

		if (draft.op === 'between') {
			var type = (field && (field.type === 'date' || field.type === 'datetime')) ? 'date' : 'number';

			return el('span', { style: 'display:flex;gap:6px' }, [
				el('input', { type: type, 'aria-label': 'From', value: draft.min,
					onchange: function (e) { draft.min = e.target.value; } }),
				el('input', { type: type, 'aria-label': 'To', value: draft.max,
					onchange: function (e) { draft.max = e.target.value; } })
			]);
		}

		if (field && field.options) {
			var select = el('select', { onchange: function (e) { draft.value = e.target.value; } });

			select.appendChild(el('option', { value: '', text: 'Choose…' }));
			field.options.forEach(function (option) {
				select.appendChild(el('option', { value: option.value, text: option.label, selected: String(draft.value) === String(option.value) }));
			});

			return select;
		}

		if (field && field.type === 'bool') {
			var toggle = el('select', { onchange: function (e) { draft.value = e.target.value; } });

			[{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }].forEach(function (option) {
				toggle.appendChild(el('option', { value: option.value, text: option.label, selected: String(draft.value) === option.value }));
			});

			draft.value = draft.value === '' ? '1' : draft.value;
			return toggle;
		}

		var inputType = 'text';
		if (field && (field.type === 'date' || field.type === 'datetime')) { inputType = 'date'; }
		if (field && (field.type === 'int' || field.type === 'decimal')) { inputType = 'number'; }

		return el('input', {
			type: inputType,
			value: draft.value,
			placeholder: 'Value',
			oninput: function (e) { draft.value = e.target.value; }
		});
	}

	function selectFilter(filter, onChange) {
		var current = state.query.filters[filter.key];

		var select = el('select', {
			onchange: function (event) {
				setFilter(filter.key, event.target.value);
				onChange();
			}
		});

		var list = filter.options.slice();
		if (filter.blank && list.length && list[0].value === '') { list[0] = { value: '', label: filter.blank }; }

		list.forEach(function (option) {
			select.appendChild(el('option', {
				value: option.value,
				text: option.label,
				selected: String(current === undefined ? '' : current) === String(option.value)
			}));
		});

		return el('div.pcm-crm-filter', {}, [el('label', { text: filter.label }), select]);
	}

	function lookupFilter(filter, onChange) {
		var wrap = selectFilter({
			key: filter.key,
			label: filter.label,
			options: [{ value: '', label: 'Any ' + filter.lookup.replace(/s$/, '') }].concat(lookups[filter.lookup] || [])
		}, onChange);

		// The list may not be cached yet on first paint; refresh in place once
		// it lands rather than blocking the whole bar on it.
		if (!lookups[filter.lookup]) {
			loadLookup(filter.lookup).then(function () {
				var replacement = lookupFilter(filter, onChange);
				if (wrap.parentNode) { wrap.parentNode.replaceChild(replacement, wrap); }
			});
		}

		return wrap;
	}

	function rangeFilter(filter, onChange) {
		var current = state.query.filters[filter.key] || {};

		function input(bound, label) {
			return el('input', {
				type: filter.range === 'date' ? 'date' : 'number',
				value: current[bound] || '',
				'aria-label': filter.label + ' ' + label,
				onchange: function (event) {
					var range = Object.assign({}, state.query.filters[filter.key] || {});
					if (event.target.value) { range[bound] = event.target.value; }
					else { delete range[bound]; }

					setFilter(filter.key, Object.keys(range).length ? range : '');
					onChange();
				}
			});
		}

		return el('div.pcm-crm-filter', {}, [
			el('label', { text: filter.label }),
			el('div', { style: 'display:flex;gap:6px' }, [input('min', 'from'), input('max', 'to')])
		]);
	}

	function setFilter(key, value) {
		if (value === '' || value === null || value === undefined) {
			delete state.query.filters[key];
		} else {
			state.query.filters[key] = value;
		}
		state.query.page = 1;
	}

	function debounce(fn, wait) {
		var timer = null;
		return function () {
			var args = arguments, self = this;
			window.clearTimeout(timer);
			timer = window.setTimeout(function () { fn.apply(self, args); }, wait);
		};
	}

	function setCount(text) {
		var node = dom.filters.querySelector('[data-role="count"]');
		if (node) { clear(node, el('span', { html: text })); }
	}

	/* ---------------------------------------------------------------------
	   List view
	   --------------------------------------------------------------------- */

	function renderList(object) {
		var def = objects[object];

		if (def.noFilters) {
			// Emptied rather than left as it was, or the previous screen's
			// controls would still be sitting there. The bar hides itself when
			// it has no children.
			clear(dom.filters);
		} else {
			renderFilters(def.filters(), function () { loadList(object); }, canObject(object, 'export') ? [
				el('a.pcm-btn.pcm-btn-quiet', {
					href: exportUrl(object),
					text: 'Export CSV'
				})
			] : [], object);
		}

		clear(dom.actions);

		// A schedule with no view behind it would have nothing to send, so it
		// is created from the Dashboard or a Report rather than from here.
		if (object === 'schedules') {
			dom.actions.appendChild(el('span.pcm-crm-muted', {
				style: 'align-self:center;font-size:0.82rem;max-width:340px;text-align:right',
				text: 'Create one with the Schedule button on the Dashboard or a Report.'
			}));
		} else if (canObject(object, 'edit')) {
			dom.actions.appendChild(el('button.pcm-btn.pcm-btn-primary', {
				type: 'button',
				text: 'New ' + def.label,
				onclick: function () { openDrawer(object, 0); }
			}));
		}

		loadList(object);
	}

	function exportUrl(object) {
		var query = buildQuery({
			action: 'pcm_crm_export',
			object: object,
			pcm_crm_nonce: cfg.exportNonce,
			search: state.query.search,
			filters: state.query.filters
		});

		return cfg.exportUrl + '?' + query;
	}

	function loadList(object) {
		writeHash();

		var def = objects[object];

		api('/' + object, { query: state.query }).then(function (data) {
			setCount('<strong>' + data.total + '</strong> ' + (data.total === 1 ? def.label.toLowerCase() : def.plural.toLowerCase()));

			// Keep the export link in step with the filters that were just
			// applied, or it downloads the previous view.
			var link = dom.filters.querySelector('a.pcm-btn');
			if (link) { link.href = exportUrl(object); }

			if (!data.items.length) {
				clear(dom.body, el('div.pcm-crm-empty', {}, [
					el('h3', { text: 'Nothing here yet' }),
					el('p', { text: state.query.search || Object.keys(state.query.filters).length
						? 'No ' + def.plural.toLowerCase() + ' match these filters.'
						: 'Create the first one to get started.' })
				]));
				return;
			}

			clear(dom.body);
			dom.body.appendChild(buildTable(object, def, data.items));
			dom.body.appendChild(pagination(data.total, function () { loadList(object); }));
		}).catch(showError);
	}

	function buildTable(object, def, items, overrideActions, reload) {
		var head = el('tr');

		// A view can supply its own row actions — the recycle bin's Restore and
		// Delete forever belong to the bin, not to the object.
		var actions = overrideActions || (def.rowActions ? def.rowActions() : null);

		// Sorting has to re-run whichever load function actually built this
		// table. Reports and the recycle bin share buildTable with the plain
		// list view but query different endpoints (or different filters), so
		// hardcoding loadList(object) here re-fetched the wrong data — most
		// visibly on Reports, where it silently dropped back to the list
		// endpoint and only looked like it worked because that endpoint
		// happens to expand lookups like Account and Owner.
		var doReload = reload || function () { loadList(object); };

		def.columns.forEach(function (column) {
			var sorted = state.query.orderby === column.key;

			head.appendChild(el('th' + (sorted ? '.is-sorted' : '') + '.is-sortable', {
				onclick: function () {
					// Clicking the sorted column flips direction; a new column
					// starts descending, which is what "most recent first"
					// means for every date column here.
					if (state.query.orderby === column.key) {
						state.query.order = state.query.order === 'ASC' ? 'DESC' : 'ASC';
					} else {
						state.query.orderby = column.key;
						state.query.order = 'DESC';
					}
					doReload();
				}
			}, [
				column.label,
				el('span.pcm-sort', { text: sorted ? (state.query.order === 'ASC' ? '▲' : '▼') : '⇅' })
			]));
		});

		if (actions) {
			head.appendChild(el('th', { 'aria-label': 'Actions' }));
		}

		var body = el('tbody');

		items.forEach(function (row) {
			// A deleted record has no editor to open — restore it first.
			var tr = overrideActions
				? el('tr', { style: 'cursor:default' })
				: el('tr', {
					tabindex: '0',
					onclick: function () { openRecord(object, row.id); },
					onkeydown: function (event) {
						if (event.key === 'Enter') { openRecord(object, row.id); }
					}
				});

			def.columns.forEach(function (column) { tr.appendChild(cell(column, row, object)); });

			if (actions) {
				tr.appendChild(el('td.pcm-crm-row-actions', {}, actions.map(function (action) {
					return el('button.pcm-btn.pcm-btn-sm' + (action.danger ? '.pcm-btn-danger' : '.pcm-btn-quiet'), {
						type: 'button',
						text: action.label,
						title: action.title || action.label,
						// The row opens the record on click and on Enter, so an
						// action inside it has to stop both — otherwise
						// deleting a row also opens it on the way out.
						onclick: function (event) {
							event.stopPropagation();
							action.run(row, function () { loadList(object); });
						},
						onkeydown: function (event) { event.stopPropagation(); }
					});
				})));
			}

			body.appendChild(tr);
		});

		return el('div.pcm-crm-table-wrap', {}, [
			el('table.pcm-crm-table', {}, [el('thead', {}, [head]), body])
		]);
	}

	function cell(column, row, object) {
		var value = row[column.key];

		// A column naming a lookup's record links to it. The lookup's target
		// comes from the schema, so a column only has to say which id it shows.
		if (column.link) {
			var field = object ? fieldDefinition(object, column.link) : null;
			var target = field && field.lookup;
			var id = Number(row[column.link]);

			if (target && target !== 'polymorphic' && id && value) {
				return el('td' + (column.strong ? '.pcm-crm-strong' : ''), {}, [recordLink(target, id, String(value), { icon: false })]);
			}
		}

		if (column.render) {
			return el('td' + (column.strong ? '.pcm-crm-strong' : ''), { text: column.render(row) });
		}

		if (column.money) {
			return el('td.pcm-crm-num', { text: money(value) });
		}

		if (column.date) {
			return el('td', { text: formatDate(value) });
		}

		if (column.due) {
			if (!value) { return el('td.pcm-crm-muted', { text: '—' }); }
			return el('td', {}, [
				el('span.pcm-crm-badge' + (isOverdue(row) ? '.pcm-crm-badge-due' : ''), { text: formatDate(value) })
			]);
		}

		if (column.age) {
			var days = Number(row._days_in_stage || 0);
			var text = days === 1 ? '1 day' : days + ' days';

			if (row._is_stalled) {
				return el('td', {}, [el('span.pcm-crm-badge.pcm-crm-badge-due', { text: '⚠ ' + text })]);
			}

			return el('td', { text: row.is_closed ? '—' : text });
		}

		if (column.stage) {
			var tone = row.is_won ? '.pcm-crm-badge-won' : (row.is_closed ? '.pcm-crm-badge-lost' : '.pcm-crm-badge-open');
			return el('td', {}, [el('span.pcm-crm-badge' + tone, { text: value || '—' })]);
		}

		if (column.badge) {
			return el('td', {}, value ? [el('span.pcm-crm-badge', { text: value })] : [el('span.pcm-crm-muted', { text: '—' })]);
		}

		if (column.num) {
			return el('td.pcm-crm-num', { text: value === null || value === '' ? '—' : String(value) });
		}

		return el('td' + (column.strong ? '.pcm-crm-strong' : (value ? '' : '.pcm-crm-muted')), {
			text: value === null || value === undefined || value === '' ? '—' : String(value)
		});
	}

	function pagination(total, reload) {
		var perPage = state.query.per_page;
		var pages = Math.ceil(total / perPage);

		if (pages <= 1) { return el('div'); }

		function button(label, page, disabled) {
			return el('button.pcm-btn.pcm-btn-quiet', {
				type: 'button',
				text: label,
				disabled: disabled,
				onclick: function () { state.query.page = page; reload(); }
			});
		}

		return el('div.pcm-crm-pagination', {}, [
			button('‹ Previous', state.query.page - 1, state.query.page <= 1),
			el('span', { text: 'Page ' + state.query.page + ' of ' + pages }),
			button('Next ›', state.query.page + 1, state.query.page >= pages)
		]);
	}

	function showError(error) {
		clear(dom.body, el('div.pcm-crm-error', { text: error.message || 'Something went wrong.' }));
		window.console.error(error);
	}

	/* ---------------------------------------------------------------------
	   Records: the page, the modal, and the way between them

	   A record opens as a page of its own when its object has one, the way a
	   Salesforce record does: the URL names it, Back returns to the list, and a
	   lookup on it is a link to the record it points at. The centred modal is
	   kept for creating — New from a list or a related list, and a quick create
	   from inside a lookup — and for the objects with no page of their own
	   (templates, schedules, project roles), which open there as before.

	   Both draw through renderRecord(), into whichever host `current` names, so
	   the two cannot drift into different forms of the same record.
	   --------------------------------------------------------------------- */

	/**
	 * The record on screen: where it is drawn, whether it is being edited, and
	 * a token that lets a slow fetch notice it has been overtaken.
	 */
	var current = { host: null, mode: '', object: '', record: null, options: {}, editing: false, dirty: false, token: 0 };

	function recordHost() {
		return current.host || dom.drawer;
	}

	/**
	 * The admin page an object's records open on, from the registry.
	 */
	function objectPage(object) {
		var directory = (state.boot && state.boot.objects) || {};

		return (directory[object] && directory[object].page) || '';
	}

	/**
	 * A link to one of the app's own screens, in the shape this host uses.
	 *
	 * Every place that used to build '?page=' + slug by hand now calls this
	 * instead, so moving a screen onto the front end is a change to cfg (host,
	 * frontBase, screens) rather than a change at every place that links to it.
	 *
	 * cfg.screens maps an admin page slug to its front-end one; a slug absent
	 * from that map has no front-end route yet (PCM Settings, until it is
	 * wired), and falls through to the admin shape rather than linking nowhere.
	 */
	function screenUrl(page, id, fragment) {
		if (cfg.host === 'front' && cfg.frontBase) {
			var slug = cfg.screens && cfg.screens[page];

			if (slug) {
				return cfg.frontBase + slug + '/' + (id ? id + '/' : '') + (fragment || '');
			}
		}

		return (cfg.adminUrl || 'admin.php') + '?page=' + page + (id ? '#id=' + id : (fragment || ''));
	}

	function recordUrl(object, id) {
		var page = objectPage(object);

		return page && id ? screenUrl(page, id) : '';
	}

	/**
	 * Whether this screen shows records as pages. Only an object's own list
	 * screen does — a record id on the dashboard means nothing.
	 */
	function pageMode(view) {
		return !!(objects[view] && objectPage(view) && !views[view]);
	}

	/**
	 * A plural slug for the singular what_type an activity stores.
	 */
	function pluralFor(singular) {
		var directory = (state.boot && state.boot.objects) || {};
		var match = '';

		Object.keys(directory).forEach(function (slug) {
			if (!match && String(directory[slug].label).toLowerCase().replace(/\s+/g, '_') === String(singular || '')) {
				match = slug;
			}
		});

		return match;
	}

	function singularFor(slug) {
		var directory = (state.boot && state.boot.objects) || {};

		return directory[slug] ? String(directory[slug].label).toLowerCase().replace(/\s+/g, '_') : '';
	}

	/**
	 * An object's mark: its icon, in its colour. Decorative — the label beside
	 * it always says the same thing in words.
	 */
	function objectIcon(object) {
		var directory = (state.boot && state.boot.objects) || {};
		var entry = directory[object] || {};

		return el('span.pcm-crm-obj-icon.pcm-crm-obj-c' + (entry.color || 1) + '.dashicons.dashicons-' + (entry.icon || 'media-default'), {
			'aria-hidden': 'true'
		});
	}

	/**
	 * Unsaved edits are worth one question before they are thrown away.
	 */
	function confirmDiscard() {
		if (!current.editing || !current.dirty) { return true; }

		return window.confirm('Discard your unsaved changes?');
	}

	/**
	 * Go to a record.
	 *
	 * On its own list screen that is a history entry and a repaint; anywhere
	 * else it is a real navigation, so the browser's Back button, a new tab and
	 * a copied link all do what they say. An object with no page opens in the
	 * modal.
	 */
	function openRecord(object, id) {
		if (!id || !confirmDiscard()) { return; }

		if (pageMode(state.view) && object === state.view) {
			if (dom.drawer && !dom.drawer.hidden) { closeDrawer(true); }

			state.recordId = Number(id);
			writeHash(true);
			render();
			if (window.scrollTo) { window.scrollTo(0, 0); }
			return;
		}

		var url = recordUrl(object, id);

		if (url) {
			current.dirty = false;
			window.location.href = url;
			return;
		}

		openDrawer(object, id);
	}

	/**
	 * Open a record in the modal.
	 *
	 * options.prefill seeds a new record's fields — used when creating a child
	 * from its parent, so the link is already made before the form is shown.
	 * options.returnTo names the record a new one is being created for, which
	 * the modal's heading says ("Contact for Northfield Historical Society").
	 */
	function openDrawer(object, id, options) {
		options = options || {};

		// The page stays underneath, so its record is what the modal returns to
		// when it closes.
		if (current.mode === 'page') { current.below = Object.assign({}, current); }

		if (!id && current.mode !== 'page') { state.recordId = 0; }
		if (id && !pageMode(state.view)) { state.recordId = id; writeHash(); }

		dom.drawer.hidden = false;
		dom.scrim.hidden = false;
		clear(dom.drawer, el('p.pcm-crm-loading', { text: 'Loading…' }));

		// An object can ask a question before its form: a project asks which
		// kind of project, because the answer decides what the form is.
		var def = objects[object];

		if (!id && def && def.beforeCreate && !options.chosen) {
			def.beforeCreate(options.prefill || {}, {
				show: function (title, node) {
					clear(dom.drawer);
					dom.drawer.appendChild(el('div.pcm-crm-modal-head', {}, [
						objectIcon(object),
						el('div.pcm-crm-modal-heading', {}, [
							el('p.pcm-crm-drawer-kicker', { text: options.returnTo && options.returnTo.label ? def.label + ' for ' + options.returnTo.label : def.label }),
							el('h2', { text: title })
						]),
						el('button.pcm-crm-drawer-close', { type: 'button', 'aria-label': 'Close', text: '×', onclick: function () { closeDrawer(); } })
					]));
					dom.drawer.appendChild(el('div.pcm-crm-modal-body', {}, [node]));
				},
				proceed: function (prefill) {
					// The page underneath is still what the modal returns to.
					var below = current.below;
					openDrawer(object, 0, Object.assign({}, options, { prefill: prefill, chosen: true }));
					if (below && !current.below) { current.below = below; }
				},
				close: function () { closeDrawer(); }
			});
			return;
		}

		loadRecord(dom.drawer, 'modal', object, id, options);
	}

	/**
	 * @param {boolean} keepHash Closing on the way to another record, whose own
	 *                           navigation writes the hash.
	 */
	function closeDrawer(keepHash) {
		if (current.mode === 'modal' && !confirmDiscard()) { return; }

		dom.drawer.hidden = true;
		dom.scrim.hidden = true;

		var below = current.below;

		if (below) {
			current = below;
			current.below = null;
			return;
		}

		current = { host: null, mode: '', object: '', record: null, options: {}, editing: false, dirty: false, token: current.token + 1 };

		if (!pageMode(state.view)) {
			state.recordId = 0;
			if (keepHash !== true) { writeHash(); }
		}
	}

	/**
	 * A record as the whole screen, under its list's heading.
	 */
	function renderRecordPage(object, id) {
		dom.root.classList.add('is-record');
		clear(dom.filters);
		clear(dom.actions);

		var page = el('div.pcm-crm-record-page');
		clear(dom.body, page);
		page.appendChild(el('p.pcm-crm-loading', { text: 'Loading…' }));

		loadRecord(page, 'page', object, id, {});
	}

	/**
	 * Fetch a record and draw it into a host, then its related lists.
	 */
	function loadRecord(host, mode, object, id, options) {
		var token = current.token + 1;

		current = {
			host: host,
			mode: mode,
			object: object,
			record: null,
			options: options || {},
			editing: !id || !servedLayout(object),
			dirty: false,
			token: token,
			below: current.below
		};

		var fetch = id ? api('/' + object + '/' + id) : Promise.resolve(options.prefill || {});

		return fetch.then(function (record) {
			if (current.token !== token) { return; }

			current.record = record;
			renderRecord(object, record, current.options);

			if (id) { rememberRecent(object, record.id, objects[object].title(record)); }

			if (id) { loadRelated(object, record, token); }
		}).catch(function (error) {
			if (current.token !== token) { return; }

			clear(host, el('div.pcm-crm-error', { text: error.message }));
		});
	}

	/**
	 * Each object declares how its related section is built, because asking
	 * for a route that does not exist is a guaranteed 404 — which then showed
	 * up as an error tab on a record that simply has nothing hanging off it.
	 * 'local' is a leaf like an activity: nothing hangs off one, so its section
	 * comes from the record's own parents rather than a fetch.
	 */
	function loadRelated(object, record, token) {
		var relatedMode = (objects[object] || {}).related;

		if (relatedMode === 'local') {
			renderRelated(object, record, {});
			return;
		}

		if (relatedMode !== 'fetch') { return; }

		api('/related/' + object + '/' + record.id).then(function (related) {
			if (current.token !== token) { return; }

			renderRelated(object, record, related);
		}).catch(function (error) {
			if (current.token !== token) { return; }

			// Swallowing this would leave a record showing only a Details tab,
			// which reads as "nothing is attached to this" — a different and
			// wrong statement.
			var panels = recordHost().querySelector('[data-role="panels"]');

			if (panels) {
				panels.appendChild(el('div.pcm-crm-panel', { dataset: { tab: 'related-error' }, hidden: true }, [
					el('div.pcm-crm-error', {
						text: 'Could not load related records: ' + (error.message || 'request failed')
					})
				]));

				renderTabs([
					{ id: 'details', label: 'Details' },
					{ id: 'related-error', label: 'Related' }
				]);
			}

			window.console.error(error);
		});
	}

	/**
	 * Draw the current record again from what is on the server — after a save,
	 * a send, an enrollment — without losing where it is drawn.
	 */
	function reloadRecord() {
		if (!current.object || !current.record || !current.record.id) { return; }

		if (current.mode === 'page') {
			renderRecordPage(current.object, current.record.id);
		} else {
			loadRecord(current.host, current.mode, current.object, current.record.id, {});
		}
	}

	/**
	 * Whether an object's form comes from a served layout. Those read as a
	 * record and edit in place; an object that declares its own form —
	 * templates, sequences, schedules — is a form and nothing else.
	 */
	function servedLayout(object) {
		var schema = state.schema && state.schema[object];

		return !!(schema && schema.layout && schema.layout.length);
	}

	/**
	 * Alias kept for callers that predate the record page.
	 */
	function renderDrawer(object, record, options) {
		renderRecord(object, record, options);
	}

	function renderRecord(object, record, options) {
		options = options || {};

		var host = recordHost();
		var def = objects[object];
		var isNew = !record.id;
		var isPage = current.mode === 'page';

		clear(host);

		var actions = el('div.pcm-crm-record-actions');

		if (!isNew && !current.editing && canObject(object, 'edit')) {
			actions.appendChild(el('button.pcm-btn.pcm-btn-sm', {
				type: 'button',
				text: 'Edit',
				onclick: function () { enterEdit(); }
			}));
		}

		if (!isNew && !current.editing && canObject(object, 'delete')) {
			actions.appendChild(el('button.pcm-btn.pcm-btn-sm.pcm-btn-danger', {
				type: 'button',
				text: 'Delete',
				onclick: function () { deleteRecord(object, record); }
			}));
		}

		// A record glimpsed in the modal — a deal off the board — has a page of
		// its own, and one click should reach it.
		if (!isNew && !isPage && objectPage(object) && !pageMode(state.view)) {
			actions.appendChild(el('a.pcm-btn.pcm-btn-sm.pcm-btn-quiet', {
				href: recordUrl(object, record.id),
				text: 'Open page'
			}));
		}

		// A module adds a record-level action — Invite to Portal on a Contact —
		// without this file knowing it exists, the same shape registerRecordTabs
		// already gives a module for adding a tab.
		if (!isNew && !current.editing) {
			(recordActions[object] || []).forEach(function (provider) {
				(provider(record, { api: api, el: el, reloadRecord: reloadRecord }) || []).forEach(function (node) {
					actions.appendChild(node);
				});
			});
		}

		host.appendChild(el('div.pcm-crm-modal-head' + (isPage ? '.pcm-crm-record-head' : ''), {}, [
			objectIcon(object),
			el('div.pcm-crm-modal-heading', {}, [
				kicker(def, record, isNew, options),
				el(isPage ? 'h2.pcm-crm-record-title' : 'h2', { text: isNew ? 'New ' + def.label : def.title(record) })
			]),
			// Sample records are flagged in the database, and saying so on the
			// record means nobody has to remember which is which before acting
			// on one.
			Number(record.is_test) ? el('span.pcm-crm-test-badge', { text: 'Sample data' }) : null,
			actions.children.length ? actions : null,
			isPage ? null : el('button.pcm-crm-drawer-close', {
				type: 'button',
				'aria-label': 'Close',
				text: '×',
				onclick: closeDrawer
			})
		]));

		// The record's fixed chrome. Everything here stays put; only the tab
		// panel below it scrolls. Sticky positioning was doing this job and
		// doing it badly — the scroll container's top padding sits inside the
		// scrollport, so fields scrolled through the uncovered band above the
		// strip and over the tab labels.
		var top = el('div.pcm-crm-modal-top');

		// A restriction on contacting someone has to be visible before anyone
		// reads the phone number below it, and on every tab — so it lives in
		// the chrome rather than inside the details panel.
		if (!isNew && record.do_not_contact) {
			top.appendChild(el('div.pcm-crm-alert', {}, [
				el('strong', { text: 'Do not contact.' }),
				' ' + (record.do_not_contact_reason || 'No reason recorded.')
			]));
		}

		if (!isNew && def.highlights) {
			top.appendChild(highlightPanel(def.highlights(record)));
		}

		if (!isNew && def.path) {
			var path = pathBar(object, record, def.path(record));
			if (path) { top.appendChild(path); }
		}

		top.appendChild(el('div.pcm-crm-tabs', { 'data-role': 'tabs', role: 'tablist' }));
		host.appendChild(top);

		var values = Object.assign({}, record);

		var scroll = el('div.pcm-crm-modal-body', {}, [
			el('div.pcm-crm-panels', { 'data-role': 'panels' }, [
				detailsPanel(object, record, values, options)
			])
		]);

		host.appendChild(scroll);

		// Details is the only tab until the related lists arrive and say what
		// else this record has.
		renderTabs([{ id: 'details', label: 'Details' }]);

		scroll.scrollTop = 0;
	}

	/**
	 * Swap the Details panel between reading and editing, in place, so the
	 * related tabs and the scroll position survive the switch.
	 */
	function redrawDetails(focusKey) {
		var host = recordHost();
		var panels = host.querySelector('[data-role="panels"]');
		var old = panels && panels.querySelector('[data-tab="details"]');

		if (!old || !current.record) { return; }

		var next = detailsPanel(current.object, current.record, Object.assign({}, current.record), current.options);

		panels.replaceChild(next, old);

		// The header's Edit and Delete belong to reading.
		var actions = host.querySelector('.pcm-crm-record-actions');
		if (actions) { actions.hidden = current.editing; }

		selectTab('details');

		if (focusKey) {
			var input = host.querySelector('#pcm-crm-field-' + focusKey);
			if (input && input.focus) { input.focus(); }
		}
	}

	function enterEdit(focusKey) {
		if (current.editing) { return; }

		current.editing = true;
		current.dirty = false;
		redrawDetails(focusKey);
	}

	function cancelEdit() {
		if (!confirmDiscard()) { return; }

		current.editing = false;
		current.dirty = false;
		redrawDetails();
	}

	function deleteRecord(object, record) {
		var def = objects[object];

		if (!window.confirm('Delete this ' + def.label.toLowerCase() + '? It goes to the Recycle Bin, and can be restored from there.')) { return; }

		api('/' + object + '/' + record.id, { method: 'DELETE' }).then(function () {
			current.dirty = false;

			if (current.mode === 'page') {
				state.recordId = 0;
				writeHash(true);
				render();
				return;
			}

			closeDrawer();
			refreshView();
		}).catch(showError);
	}

	/**
	 * The tab strip.
	 *
	 * Tabs are rebuilt rather than patched when the related lists land, so the
	 * counts in their labels always come from the data actually rendered.
	 * Selection is preserved across that rebuild, which matters because the
	 * fetch can resolve after someone has already clicked away from Details.
	 */
	function renderTabs(tabs, selected) {
		var strip = recordHost().querySelector('[data-role="tabs"]');
		if (!strip) { return; }

		var active = selected || strip.dataset.active || 'details';

		// Fall back to Details if the selected tab no longer exists.
		if (!tabs.some(function (tab) { return tab.id === active; })) { active = 'details'; }

		strip.dataset.active = active;
		clear(strip);

		tabs.forEach(function (tab) {
			strip.appendChild(el('button.pcm-crm-tab' + (tab.id === active ? '.is-active' : ''), {
				type: 'button',
				role: 'tab',
				dataset: { tab: tab.id },
				'aria-selected': tab.id === active ? 'true' : 'false',
				onclick: function () { selectTab(tab.id); }
			}, [
				tab.label,
				tab.count === undefined ? null : el('span.pcm-crm-tab-count', { text: String(tab.count) })
			]));
		});

		selectTab(active);
	}

	function selectTab(id) {
		var strip = recordHost().querySelector('[data-role="tabs"]');
		var panels = recordHost().querySelector('[data-role="panels"]');
		if (!strip || !panels) { return; }

		strip.dataset.active = id;

		// Matched on the tab id both sides carry, not on position — the panels
		// are appended in a different order than the tabs are built.
		strip.querySelectorAll('[data-tab]').forEach(function (button) {
			var isActive = button.dataset.tab === id;

			button.classList.toggle('is-active', isActive);
			button.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		panels.querySelectorAll('[data-tab]').forEach(function (panel) {
			panel.hidden = panel.dataset.tab !== id;
		});
	}

	/**
	 * The details, as a set of two-column field groups — read, or in a form.
	 *
	 * Two columns rather than as many as fit: three made related fields — a
	 * street and the city under it — land in different columns, which is
	 * exactly the pairing a form like this should preserve.
	 *
	 * Reading is the default for a saved record, the way Salesforce shows one:
	 * values as text and lookups as links. A pencil beside any field, a double
	 * click on it, or Edit in the header turns the whole panel into the form,
	 * with Save and Cancel held at the foot.
	 */
	function detailsPanel(object, record, values, options) {
		options = options || {};

		var def = objects[object];
		var isNew = !record.id;
		var editing = current.editing || isNew;

		var form = el('form.pcm-crm-details' + (editing ? '.is-editing' : '.is-reading'), {
			dataset: { object: object },
			onsubmit: function (e) { e.preventDefault(); }
		});

		// Lookups on this form, so choosing one can clear the ones it narrows.
		form.pcmLookups = [];

		if (editing) {
			var markDirty = function () { if (form === current.form) { current.dirty = true; } };
			form.addEventListener('input', markDirty);
			form.addEventListener('change', markDirty);
			current.form = form;
		}

		layoutFor(object, values).forEach(function (group) {
			var grid = el('div.pcm-crm-fields');

			// A layout section holds field *names*, to be looked up in the
			// schema. An object that declares its own form — templates,
			// sequences, schedules — holds the definitions themselves. Both
			// shapes arrive here, and assuming only the first left those three
			// forms rendering nothing at all.
			group.fields.forEach(function (entry) {
				var field = ( typeof entry === 'string' ) ? fieldDefinition(object, entry) : entry;

				if (!field) { return; }

				grid.appendChild(editing
					? fieldControl(field, values, form)
					: fieldOutput(object, field, record));
			});

			// A section whose fields have all been removed would otherwise
			// print its heading over nothing.
			if (!grid.children.length) { return; }

			// A section whose fields are all individually showWhen-hidden (a
			// Retainer group on a fixed-scope project, say) still has that many
			// DOM children — just none of them visible — so the heading needs
			// its own check rather than trusting grid.children.length.
			if (group.title) {
				var heading = el('h4.pcm-crm-group-head', { text: group.title });
				heading.hidden = !groupHasVisibleChild(grid);
				form.appendChild(heading);
			}

			form.appendChild(grid);
		});

		// Last group, and read-only: these are stamps the database writes, so
		// showing them as inputs would invite edits that the model discards.
		if (!isNew) {
			form.appendChild(el('h4.pcm-crm-group-head', { text: 'System Information' }));
			form.appendChild(systemInfo(record));
		}

		if (!editing) {
			return el('div.pcm-crm-panel', { dataset: { tab: 'details' } }, [form]);
		}

		var status = el('span.pcm-crm-muted', { role: 'status' });

		var actions = el('div.pcm-crm-form-actions.pcm-crm-form-footer', {}, [
			el('button.pcm-btn.pcm-btn-primary', {
				type: 'button',
				text: isNew ? 'Create' : 'Save',
				onclick: function (event) { saveRecord(object, record.id, values, event.target, status); }
			}),
			el('button.pcm-btn.pcm-btn-quiet', {
				type: 'button',
				text: 'Cancel',
				onclick: function () {
					if (isNew || !servedLayout(object)) {
						if (!confirmDiscard()) { return; }
						current.dirty = false;
						closeDrawer();
						// Back to the parent it was started from, in the modal it was in.
						if (options.returnTo && current.mode !== 'page') { openDrawer(options.returnTo.object, options.returnTo.id); }
						return;
					}

					cancelEdit();
				}
			}),
			status
		]);

		if (!isNew && object === 'schedules') {
			actions.appendChild(el('button.pcm-btn', {
				type: 'button',
				text: 'Send now',
				onclick: function (event) { sendScheduleNow(record, values, event.target, status); }
			}));
		}

		// An object that is only ever a form has no read view, and so no header
		// Delete — it keeps its own here.
		if (!isNew && !servedLayout(object)) {
			actions.appendChild(el('button.pcm-btn.pcm-btn-danger.pcm-crm-delete', {
				type: 'button',
				text: 'Delete',
				onclick: function () { deleteRecord(object, record); }
			}));
		}

		form.appendChild(actions);

		// A form that reshapes itself from what it holds — a time entry from its
		// project's rules — needs to hear once that it exists, not only when a
		// field changes.
		derivers.forEach(function (fn) { fn('', values, form); });

		return el('div.pcm-crm-panel', { dataset: { tab: 'details' } }, [form]);
	}

	/**
	 * The line above the record's name: on a page, the way back to its list;
	 * then the parent it belongs to, as a link.
	 */
	function kicker(def, record, isNew, options) {
		// A new child names the parent it will be attached to, so the link the
		// prefill made is visible before saving rather than taken on trust.
		if (isNew) {
			return el('p.pcm-crm-drawer-kicker', {
				text: options.returnTo
					? def.label + ' for ' + (options.returnTo.label || lookupLabel(options.returnTo.object, options.returnTo.id) || 'this record')
					: def.label
			});
		}

		var label = def.kicker ? def.kicker(record) : def.label;
		var link = def.kickerLink ? def.kickerLink(record) : null;
		var line = el('p.pcm-crm-drawer-kicker');

		if (current.mode === 'page') {
			line.appendChild(el('a.pcm-crm-crumb', {
				href: '#',
				text: def.plural,
				onclick: function (event) {
					event.preventDefault();
					if (!confirmDiscard()) { return; }
					current.dirty = false;
					state.recordId = 0;
					writeHash(true);
					render();
				}
			}));
			line.appendChild(el('span.pcm-crm-crumb-sep', { text: ' › ', 'aria-hidden': 'true' }));
		}

		line.appendChild(link ? recordLink(link.object, link.id, label, { quiet: true }) : el('span', { text: label }));

		return line;
	}

	/**
	 * The Salesforce-style highlights strip: the handful of fields you need
	 * before deciding whether to read the rest of the record.
	 *
	 * An item may carry `link: { object, id }`, and then reads as a lookup.
	 */
	function highlightPanel(items) {
		var panel = el('div.pcm-crm-highlights');

		items.forEach(function (item) {
			if (!item.value || item.value === '—') { return; }

			var value;

			if (item.link && item.link.id) {
				value = el('span.pcm-crm-highlight-value', {}, [recordLink(item.link.object, item.link.id, item.value)]);
			} else if (item.href) {
				value = el('a.pcm-crm-highlight-value', { href: item.href, text: item.value });
			} else {
				value = el('span.pcm-crm-highlight-value', { text: item.value });
			}

			panel.appendChild(el('div.pcm-crm-highlight', {}, [
				el('span.pcm-crm-highlight-label', { text: item.label }),
				value
			]));
		});

		return panel;
	}

	/**
	 * A stage path: every stage as a step, the current one marked, and a click
	 * on another offering to move the record there.
	 *
	 * spec: { field, stages: [{ name, closed, lost }], current }. A losing stage
	 * asks for its reason first, because the server refuses one without — the
	 * board does the same, for the same reason.
	 */
	function pathBar(object, record, spec) {
		if (!spec || !spec.stages || !spec.stages.length) { return null; }

		var currentIndex = -1;

		spec.stages.forEach(function (stage, i) {
			if (stage.name === spec.current) { currentIndex = i; }
		});

		var bar = el('ol.pcm-crm-path', { 'aria-label': 'Stage' });

		spec.stages.forEach(function (stage, i) {
			var tone = i === currentIndex ? '.is-current' : (i < currentIndex ? '.is-done' : '');
			if (i === currentIndex && stage.lost) { tone += '.is-lost'; }

			bar.appendChild(el('li.pcm-crm-path-step' + tone, {}, [
				el('button', {
					type: 'button',
					text: stage.name,
					'aria-current': i === currentIndex ? 'step' : null,
					disabled: i === currentIndex,
					title: i === currentIndex ? 'Current stage' : 'Move to ' + stage.name,
					onclick: function () { moveToStage(object, record, spec.field, stage); }
				})
			]));
		});

		return el('div.pcm-crm-path-wrap', {}, [bar]);
	}

	function moveToStage(object, record, field, stage) {
		if (current.editing && current.dirty) {
			window.alert('Save or cancel your edits first.');
			return;
		}

		var body = {};
		body[field] = stage.name;

		if (stage.lost) {
			var reason = window.prompt('Why was it lost?');
			if (!reason) { return; }
			body.closed_lost_reason = reason;
		} else if (!window.confirm('Mark as ' + stage.name + '?')) {
			return;
		}

		api('/' + object + '/' + record.id, { method: 'PUT', body: body })
			.then(function () {
				reloadRecord();
				// The board or list behind a modal shows the stage too.
				if (current.mode !== 'page') { refreshView(); }
			})
			.catch(function (error) { window.alert(error.message); });
	}

	/**
	 * A link to a record: its object's mark and its name, going to its page.
	 *
	 * A real anchor with a real href, so a middle click or a copied link works;
	 * a plain click is taken over so moving within a list screen is a history
	 * entry rather than a reload. Hovering shows the record's highlights.
	 */
	function recordLink(object, id, label, opts) {
		opts = opts || {};

		var url = recordUrl(object, id);

		var link = el('a.pcm-crm-lookup-link' + (opts.quiet ? '.is-quiet' : ''), {
			href: url || '#',
			dataset: { object: object, id: String(id) },
			onclick: function (event) {
				event.stopPropagation();

				if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) { return; }

				event.preventDefault();
				hideCard();
				openRecord(object, id);
			},
			onkeydown: function (event) { event.stopPropagation(); },
			onmouseenter: function () { queueCard(link, object, id); },
			onmouseleave: function () { leaveCard(); },
			onfocus: function () { queueCard(link, object, id); },
			onblur: function () { leaveCard(); }
		}, [opts.icon === false ? null : objectIcon(object), el('span', { text: label || '#' + id })]);

		return link;
	}

	/**
	 * One field, read.
	 */
	function fieldOutput(object, field, record) {
		var editable = !field.readonly && servedLayout(object);
		var wrap = el('div.pcm-crm-field.pcm-crm-field-read' + (field.wide ? '.pcm-crm-field-wide' : ''), {
			dataset: { field: field.key }
		});

		wrap.appendChild(el('span.pcm-crm-read-label', { text: field.label }));

		var line = el('div.pcm-crm-read-value', {
			ondblclick: editable ? function (event) {
				// A double click on a link is two clicks on the link.
				if (event.target && event.target.closest && event.target.closest('a')) { return; }
				enterEdit(field.key);
			} : null
		}, [displayValue(object, field, record)]);

		if (editable) {
			line.appendChild(el('button.pcm-crm-read-edit', {
				type: 'button',
				'aria-label': 'Edit ' + field.label,
				title: 'Edit',
				onclick: function () { enterEdit(field.key); }
			}, [el('span.dashicons.dashicons-edit', { 'aria-hidden': 'true' })]));
		}

		wrap.appendChild(line);

		// Hidden under the same condition as its input, so a field that does
		// not apply is no more visible read than it is edited.
		if (field.showWhen) {
			wrap.dataset.showWhen = field.showWhen;
			if (field.showWhenLost) { wrap.dataset.showWhenLost = '1'; }
			if (field.showWhenValue) { wrap.dataset.showWhenValue = field.showWhenValue; }
			if (field.showWhenOneOf) { wrap.dataset.showWhenOneOf = field.showWhenOneOf.join('|'); }
			wrap.hidden = !conditionMet(wrap.dataset, record);
		}

		return wrap;
	}

	/**
	 * A value as it reads: a link, a sum of money, a date, a tick.
	 */
	function displayValue(object, field, record) {
		var value = record[field.key];
		var empty = value === null || value === undefined || value === '' ||
			(field.type === 'id' && !Number(value));

		var target = field.lookup === 'polymorphic'
			? pluralFor(record.what_type)
			: (field.lookup || (field.ui === 'relationship' ? field.related : ''));

		if (target || field.lookup === 'polymorphic') {
			if (empty || !target) { return el('span.pcm-crm-muted', { text: '—' }); }

			var name = record['_' + field.key + '_name'] || lookupLabel(target, value) || '';

			return recordLink(target, Number(value), name || ((objects[target] && objects[target].label) || 'Record') + ' #' + value);
		}

		if (field.type === 'checkbox' || field.type === 'bool' || field.ui === 'checkbox') {
			return el('span', { text: Number(value) ? '✓ Yes' : 'No' });
		}

		if (empty) { return el('span.pcm-crm-muted', { text: '—' }); }

		if (field.key === 'owner_id' && record._owner_name) {
			return el('span', { text: record._owner_name });
		}

		if (field.options && field.options.length) {
			var chosen = field.options.filter(function (option) {
				return String(option.value) === String(value);
			})[0];

			return el('span', { text: chosen ? chosen.label : String(value) });
		}

		if (field.ui === 'currency') { return el('span', { text: money(value) }); }
		if (field.type === 'date' || field.ui === 'date') { return el('span', { text: formatDate(value) }); }
		if (field.type === 'datetime') { return el('span', { text: formatDateTime(value) }); }
		if (field.type === 'hours' || field.ui === 'hours') { return el('span', { text: value + 'h' }); }

		if (field.type === 'email' || /(^|_)email$/.test(field.key)) {
			return el('a', { href: 'mailto:' + value, text: String(value) });
		}

		if (/phone$/.test(field.key)) {
			return el('a', { href: 'tel:' + value, text: String(value) });
		}

		if (field.type === 'url' || field.ui === 'url' || field.key === 'website') {
			var href = /^https?:\/\//i.test(value) ? value : 'https://' + value;
			return el('a', { href: href, text: String(value), target: '_blank', rel: 'noopener noreferrer' });
		}

		if (field.type === 'textarea' || field.type === 'longtext') {
			return el('div.pcm-crm-read-long', { text: String(value) });
		}

		return el('span', { text: String(value) });
	}

	/* Hover cards ------------------------------------------------------------
	   A lookup link says a name; resting on it says the rest — the record's
	   highlights, fetched once per page. Not on touch, where there is no
	   resting, and a tap follows the link.
	   --------------------------------------------------------------------- */

	var cardCache = {};
	var cardTimer = 0;
	var cardLeaveTimer = 0;

	function canHover() {
		return !!(window.matchMedia && window.matchMedia('(hover: hover)').matches);
	}

	function queueCard(link, object, id) {
		if (!canHover() || !objects[object]) { return; }

		window.clearTimeout(cardLeaveTimer);
		window.clearTimeout(cardTimer);

		cardTimer = window.setTimeout(function () { showCard(link, object, id); }, 400);
	}

	function leaveCard() {
		window.clearTimeout(cardTimer);
		cardLeaveTimer = window.setTimeout(hideCard, 150);
	}

	function hideCard() {
		window.clearTimeout(cardTimer);
		if (dom.card) { dom.card.hidden = true; }
	}

	function showCard(link, object, id) {
		if (!dom.card) {
			dom.card = el('div.pcm-crm-hovercard', {
				role: 'tooltip',
				hidden: true,
				onmouseenter: function () { window.clearTimeout(cardLeaveTimer); },
				onmouseleave: function () { leaveCard(); }
			});
			dom.root.appendChild(dom.card);
		}

		var key = object + ':' + id;
		var fetch = cardCache[key] || (cardCache[key] = api('/' + object + '/' + id));

		fetch.then(function (row) {
			var def = objects[object];
			var rect = link.getBoundingClientRect();

			clear(dom.card);
			dom.card.appendChild(el('div.pcm-crm-hovercard-head', {}, [
				objectIcon(object),
				el('div', {}, [
					el('span.pcm-crm-hovercard-kind', { text: def.label }),
					el('strong', { text: def.title(row) })
				])
			]));

			if (def.highlights) {
				var list = el('dl.pcm-crm-hovercard-list');

				def.highlights(row).forEach(function (item) {
					if (!item.value || item.value === '—') { return; }
					list.appendChild(el('dt', { text: item.label }));
					list.appendChild(el('dd', { text: item.value }));
				});

				dom.card.appendChild(list);
			}

			dom.card.style.top = Math.round(rect.bottom + 6) + 'px';
			dom.card.style.left = Math.round(Math.max(8, Math.min(rect.left, (window.innerWidth || 1200) - 320))) + 'px';
			dom.card.hidden = false;
		}).catch(function () {
			delete cardCache[key];
		});
	}

	/* Recently viewed ---------------------------------------------------------
	   What a lookup offers before anything is typed. Per browser, because it is
	   a convenience rather than a record of anything.
	   --------------------------------------------------------------------- */

	function recentKey(object) { return 'pcm-crm-recent-' + object; }

	function readRecent(object) {
		try {
			var raw = window.localStorage && window.localStorage.getItem(recentKey(object));
			var list = raw ? JSON.parse(raw) : [];
			return Array.isArray(list) ? list : [];
		} catch (e) {
			return [];
		}
	}

	function rememberRecent(object, id, label) {
		if (!label) { return; }

		try {
			if (!window.localStorage) { return; }

			var list = readRecent(object).filter(function (item) { return Number(item.id) !== Number(id); });

			list.unshift({ id: Number(id), label: label });
			window.localStorage.setItem(recentKey(object), JSON.stringify(list.slice(0, 8)));
		} catch (e) {
			// Private windows and full storage both land here; neither matters.
		}
	}

	/**
	 * The sections a record's form is built from.
	 *
	 * Served rather than declared here, so an admin rearranging a layout
	 * changes what this returns. The object's own definition supplies only the
	 * few hints a layout cannot carry — which fields sit full width, which are
	 * conditional on another.
	 */
	function layoutFor(object, values) {
		var schema = state.schema && state.schema[object];

		// An object with layout variants (Project, by its project_type) keeps
		// one arrangement per type; a record without a matching override, or
		// with no type chosen yet, falls through to the object's one shared
		// layout below exactly as an object with no variants at all does.
		if (schema && schema.layoutVariantField && schema.layoutVariants && values) {
			var variant = schema.layoutVariants[values[schema.layoutVariantField]];
			if (variant && variant.length) { return variant; }
		}

		if (schema && schema.layout && schema.layout.length) { return schema.layout; }

		// Schedules and anything else outside the customisable objects keep
		// their own declared list.
		return (objects[object] && objects[object].fields) ? objects[object].fields() : [];
	}

	/**
	 * A field's definition for the form: what the server knows about the
	 * column, plus this screen's presentation hints.
	 */
	function fieldDefinition(object, name) {
		var schema = state.schema && state.schema[object];

		if (!schema) { return null; }

		var field = schema.fields.filter(function (f) { return f.key === name; })[0];

		if (!field) { return null; }

		return Object.assign({}, field, fieldHints(object, name), { key: name });
	}

	/**
	 * Presentation that the column type cannot imply.
	 *
	 * Kept small and per object rather than stored with the layout: which
	 * field is wide and which is conditional is a fact about the data model,
	 * not a choice someone makes when arranging a form.
	 */
	function fieldHints(object, name) {
		// An object that ships its own hints answers for itself; the map below
		// is core's, not a default every object has to opt out of.
		if (objects[object] && objects[object].hints) {
			return objects[object].hints(name) || {};
		}

		var wide = {
			accounts: ['name', 'billing_street', 'description'],
			contacts: ['do_not_contact_reason', 'mailing_street', 'description'],
			opportunities: ['name', 'next_step', 'description'],
			activities: ['subject', 'description']
		};

		// Which fields are lookups, and at what, is served with the schema —
		// it is a fact about the model, declared beside the column.
		var hints = {};

		if ((wide[object] || []).indexOf(name) !== -1) { hints.wide = true; }
		if (name === 'owner_id') { hints.options = ownerOptions(); }

		if (name === 'closed_lost_reason') {
			hints.showWhen = 'stage_name';
			hints.showWhenLost = true;
			hints.wide = true;
		}

		if (name === 'do_not_contact_reason') {
			hints.showWhen = 'do_not_contact';
			hints.note = 'Required. Whoever revisits this later needs to know why.';
		}

		if (name === 'description' || name === 'next_step') { hints.wide = true; }

		return hints;
	}

	function fieldControl(field, values, form, opts) {
		var id = 'pcm-crm-field-' + field.key;

		function onInput(event) {
			values[field.key] = event.target.value;
			applyDerived(field.key, values, form);
			applyConditionalFields(values, form);
		}

		// A checkbox is a control with a label beside it, not a labelled box
		// in a column — laid out like the text fields it collapses to a line.
		if (field.type === 'checkbox' || field.type === 'bool' || field.ui === 'checkbox') {
			var box = el('input', {
				id: id,
				type: 'checkbox',
				checked: !!Number(values[field.key]),
				onchange: function (event) {
					values[field.key] = event.target.checked ? 1 : 0;
					applyConditionalFields(values, form);
				}
			});

			return withCondition(el('div.pcm-crm-field.pcm-crm-field-check', { dataset: { field: field.key } }, [
				el('label.pcm-crm-check', { for: id }, [box, el('span', { text: field.label })])
			]), field, values);
		}

		var wrap = el('div.pcm-crm-field' + (field.wide ? '.pcm-crm-field-wide' : ''), {
			dataset: { field: field.key }
		});
		var control;

		// A lookup, built-in or a custom relationship, is a search rather than a
		// dropdown of every record there is.
		if (field.lookup || field.ui === 'relationship') {
			return withCondition(lookupControl(field, values, form || el('form'), opts), field, values);
		}

		var custom = controls[field.type] || controls[field.ui];

		if (custom) {
			if (custom.wide) { wrap.classList.add('pcm-crm-field-wide'); }
			if (custom.label) { wrap.appendChild(el('label', { text: field.label })); }
			wrap.appendChild(custom.build(field, values));

			return wrap;
		}

		if (field.options) {
			control = el('select', { id: id, onchange: onInput });

			var list = field.options;

			list.forEach(function (option) {
				control.appendChild(el('option', {
					value: option.value,
					text: option.label,
					selected: String(values[field.key] || '') === String(option.value)
				}));
			});
		} else if (field.type === 'textarea' || field.type === 'longtext') {
			control = el('textarea', { id: id, oninput: onInput, text: values[field.key] || '' });
		} else {
			control = el('input', {
				id: id,
				type: inputTypeFor(field),
				step: (field.ui === 'currency' || field.type === 'decimal') ? '0.01' : null,
				value: values[field.key] === null || values[field.key] === undefined ? '' : values[field.key],
				required: field.required,
				oninput: onInput
			});
		}

		wrap.appendChild(el('label', { for: id, text: field.label + (field.required ? ' *' : '') }));
		wrap.appendChild(control);

		if (field.note) { wrap.appendChild(el('span.pcm-crm-field-note', { text: field.note })); }

		return withCondition(wrap, field, values);
	}

	/**
	 * Fields that only apply under some other field's value start hidden, and
	 * are revealed by the control that makes them relevant.
	 */
	function withCondition(wrap, field, values) {
		if (field.showWhen) {
			wrap.dataset.showWhen = field.showWhen;
			if (field.showWhenLost) { wrap.dataset.showWhenLost = '1'; }
			if (field.showWhenValue) { wrap.dataset.showWhenValue = field.showWhenValue; }
			if (field.showWhenOneOf) { wrap.dataset.showWhenOneOf = field.showWhenOneOf.join('|'); }
			wrap.hidden = !conditionMet(wrap.dataset, values);
		}

		return wrap;
	}

	/**
	 * Created and last-modified stamps.
	 *
	 * A record that looks wrong is usually a record someone changed, so who
	 * touched it last is the first thing worth knowing. Seeded and imported
	 * rows have no user behind them, which reads as "—" rather than a blank
	 * that looks like a rendering failure.
	 */
	function systemInfo(record) {
		var rows = [
			{ label: 'Created date', value: formatDateTime(record.created_date) },
			{ label: 'Created by', value: record._created_by_name || '—' },
			{ label: 'Last modified date', value: formatDateTime(record.last_modified_date) },
			{ label: 'Last modified by', value: record._modified_by_name || '—' }
		];

		return el('div.pcm-crm-fields', {}, rows.map(function (row) {
			return el('div.pcm-crm-field.pcm-crm-field-static', {}, [
				el('label', { text: row.label }),
				el('span.pcm-crm-static-value', { text: row.value })
			]);
		}));
	}

	/**
	 * Which HTML input a field wants.
	 *
	 * The UI hint wins where it is more specific than the storage type — a
	 * currency and a plain number are both decimals, and a date field stored
	 * as a date is not the same control as a datetime stamp.
	 */
	function inputTypeFor(field) {
		var byUi = { currency: 'number', number: 'number', date: 'date', url: 'url', text: 'text' };

		if (field.ui && byUi[field.ui]) { return byUi[field.ui]; }

		var byType = {
			decimal: 'number', int: 'number', id: 'number',
			date: 'date', datetime: 'datetime-local',
			email: 'email', url: 'url', text: 'text'
		};

		return byType[field.type] || field.type || 'text';
	}

	/**
	 * Whether a layout group has anything left to show, once its individually
	 * conditional fields are accounted for — a group heading is only worth
	 * printing over at least one visible field.
	 */
	function groupHasVisibleChild(grid) {
		return Array.prototype.some.call(grid.children, function (child) { return !child.hidden; });
	}

	/**
	 * Show or hide the fields that depend on another field's value.
	 *
	 * Searches the whole modal rather than one grid, because a dependency can
	 * cross a group boundary — probability sits under Forecast and the stage
	 * that sets it does not.
	 */
	function applyConditionalFields(values, scope) {
		var root = scope || recordHost();
		if (!root) { return; }

		root.querySelectorAll('[data-show-when]').forEach(function (node) {
			node.hidden = !conditionMet(node.dataset, values);
		});

		// A group's heading follows its fields, not just their state when the
		// form was first built — Project Type, say, can change what the group
		// beneath it shows while the form stays open (model-project.php's
		// before-update rules already treat that as a real, handled edit).
		root.querySelectorAll('.pcm-crm-group-head').forEach(function (heading) {
			var grid = heading.nextElementSibling;
			if (grid && grid.classList.contains('pcm-crm-fields')) {
				heading.hidden = !groupHasVisibleChild(grid);
			}
		});
	}

	function conditionMet(data, values) {
		// A losing stage is identified by its flags, not its name, so a
		// renamed or added losing stage still reveals the reason field.
		if (data.showWhenLost) { return stageIsLost(values[data.showWhen]); }

		// Depends on another field holding a particular value — a weekday
		// only matters on a weekly schedule.
		if (data.showWhenValue) { return String(values[data.showWhen] || '') === data.showWhenValue; }

		// Or any of several: retainer terms matter on both kinds of retainer,
		// and naming them one at a time would mean a new condition per type.
		if (data.showWhenOneOf) {
			return data.showWhenOneOf.split('|').indexOf(String(values[data.showWhen] || '')) !== -1;
		}

		return !!Number(values[data.showWhen]);
	}

	/**
	 * Values another field decides.
	 *
	 * The server derives probability from the stage on save either way; doing
	 * it here as well is what makes the form show the number it is about to
	 * store, rather than the previous stage's until someone saves and reopens.
	 */
	function applyDerived(key, values, scope) {
		var root = scope || recordHost();

		derivers.forEach(function (fn) { fn(key, values, root); });

		if (key !== 'stage_name' || !root) { return; }

		var stage = stageByName(values.stage_name);
		if (!stage) { return; }

		values.probability = Number(stage.probability);

		var input = root.querySelector('#pcm-crm-field-probability');
		if (input) { input.value = values.probability; }
	}

	/**
	 * Choose recipients from the WordPress users, or type an address.
	 *
	 * Users are stored as their ids rather than their addresses, so a schedule
	 * follows someone who changes their email instead of quietly going to the
	 * old one. Anyone without an account is still reachable through the free
	 * text field beside it.
	 */
	function recipientsControl(values) {
		var parsed = parseRecipients(values.recipients);
		var chosen = parsed.filter(function (e) { return e.user; }).map(function (e) { return String(e.id); });
		var extra = parsed.filter(function (e) { return !e.user; }).map(function (e) { return e.email; }).join(', ');

		var users = (state.boot && state.boot.users) || [];
		var list = el('div.pcm-crm-recipients');

		function sync() {
			var ids = Array.prototype.slice.call(list.querySelectorAll('input:checked')).map(function (box) { return box.value; });
			var typed = extraInput.value.split(/[,;]+/).map(function (v) { return v.trim(); }).filter(Boolean);

			values.recipients = ids.concat(typed).join(',');
		}

		users.forEach(function (user) {
			var id = 'pcm-crm-rcpt-' + user.id;

			list.appendChild(el('label.pcm-crm-recipient', { for: id }, [
				el('input', {
					id: id,
					type: 'checkbox',
					value: String(user.id),
					checked: chosen.indexOf(String(user.id)) !== -1,
					onchange: sync
				}),
				el('span', {}, [
					el('span.pcm-crm-recipient-name', { text: user.name }),
					el('span.pcm-crm-recipient-email', { text: user.email })
				])
			]));
		});

		if (!users.length) {
			list.appendChild(el('p.pcm-crm-related-empty', { text: 'No WordPress users with an email address.' }));
		}

		var extraInput = el('input', {
			type: 'text',
			value: extra,
			placeholder: 'Other addresses, separated by commas',
			oninput: sync
		});

		return el('div', {}, [
			list,
			el('span.pcm-crm-field-note', { text: 'Pick people, and add anyone without an account below.' }),
			extraInput
		]);
	}

	/**
	 * A sequence's steps, decoded from the JSON they are stored as.
	 */
	function sequenceSteps(row) {
		try {
			var steps = JSON.parse(row.steps || '[]');
			return Array.isArray(steps) ? steps : [];
		} catch (e) {
			return [];
		}
	}

	/**
	 * A message body with its variables listed beside it.
	 *
	 * A plain textarea rather than a rich editor: these are short outreach
	 * emails, the branded wrapper supplies the styling, and a rich editor
	 * inside a modal inside an admin page is a lot of machinery for bold.
	 */
	function emailBodyControl(field, values) {
		var textarea = el('textarea', {
			rows: '12',
			text: values[field.key] || '',
			oninput: function (event) { values[field.key] = event.target.value; }
		});

		var picker = el('div.pcm-crm-variables');

		(state.boot.emailVariables || []).forEach(function (group) {
			var select = el('select', {
				onchange: function (event) {
					if (!event.target.value) { return; }

					insertAtCursor(textarea, event.target.value);
					values[field.key] = textarea.value;
					event.target.value = '';
				}
			});

			select.appendChild(el('option', { value: '', text: group.label + '…' }));

			group.fields.forEach(function (variable) {
				select.appendChild(el('option', { value: variable.token, text: variable.label }));
			});

			picker.appendChild(select);
		});

		return el('div', {}, [
			picker,
			el('span.pcm-crm-field-note', { text: 'Pick a variable to drop it in where the cursor is. A variable with nothing behind it comes out empty rather than as itself.' }),
			textarea
		]);
	}

	/**
	 * Put text where the cursor is, not at the end.
	 */
	function insertAtCursor(textarea, text) {
		var start = textarea.selectionStart || 0;
		var end = textarea.selectionEnd || 0;

		textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(end);
		textarea.selectionStart = textarea.selectionEnd = start + text.length;
		textarea.focus();
	}

	/**
	 * The steps of a sequence: a template and how long to wait first.
	 *
	 * Stored as JSON in one field, so the control keeps the value in step with
	 * every edit rather than reading the rows back at save time.
	 */
	function sequenceStepsControl(values) {
		var wrap = el('div.pcm-crm-steps-editor');
		var list = el('div.pcm-crm-step-list');

		var steps;
		try {
			steps = JSON.parse(values.steps || '[]');
			if (!Array.isArray(steps)) { steps = []; }
		} catch (e) {
			steps = [];
		}

		function commit() {
			values.steps = JSON.stringify(steps);
			draw();
		}

		function draw() {
			clear(list);

			if (!steps.length) {
				list.appendChild(el('p.pcm-crm-related-empty', { text: 'No steps yet. A sequence with no steps cannot be started.' }));
			}

			steps.forEach(function (step, index) {
				var templateSelect = el('select', {
					onchange: function (event) { steps[index].template_id = Number(event.target.value); values.steps = JSON.stringify(steps); }
				});

				templateSelect.appendChild(el('option', { value: '', text: 'Choose a template…' }));

				(state.boot.templates || []).forEach(function (template) {
					templateSelect.appendChild(el('option', {
						value: template.id,
						text: template.name,
						selected: Number(step.template_id) === Number(template.id)
					}));
				});

				list.appendChild(el('div.pcm-crm-step', {}, [
					el('span.pcm-crm-step-number', { text: String(index + 1) }),
					templateSelect,
					el('span.pcm-crm-step-delay', {}, [
						el('input', {
							type: 'number',
							min: '0',
							value: step.delay_days === undefined ? 0 : step.delay_days,
							onchange: function (event) { steps[index].delay_days = Math.max(0, Number(event.target.value)); values.steps = JSON.stringify(steps); }
						}),
						// The first step counts from enrollment, the rest from
						// the step before — saying so beats a tooltip.
						index === 0 ? ' days after enrolling' : ' days after step ' + index
					]),
					el('button.pcm-btn.pcm-btn-sm.pcm-btn-danger', {
						type: 'button',
						text: 'Remove',
						onclick: function () { steps.splice(index, 1); commit(); }
					})
				]));
			});
		}

		draw();

		wrap.appendChild(list);
		wrap.appendChild(el('button.pcm-btn.pcm-btn-sm', {
			type: 'button',
			text: 'Add step',
			onclick: function () {
				steps.push({ template_id: 0, delay_days: steps.length ? 3 : 0 });
				commit();
			}
		}));

		return wrap;
	}

	/**
	 * A lookup, the Salesforce way: type to search, pick from the matches, and
	 * the choice sits in the field as a named pill rather than an id in a
	 * dropdown of every record there is.
	 *
	 * Empty and focused, it offers the records of that kind viewed recently.
	 * A lookup narrowed by another field on the form (a deal's contact by its
	 * account) says so over the results, with a way to see all of them. The last
	 * option creates one, in a modal over this form, and selects it.
	 *
	 * The polymorphic Related To gets the same control, with the kind of record
	 * chosen first.
	 */
	function lookupControl(field, values, form, opts) {
		opts = opts || {};

		var id = 'pcm-crm-field-' + field.key;
		var listId = id + '-list';
		var polymorphic = field.lookup === 'polymorphic';
		var directory = (state.boot && state.boot.objects) || {};

		function target() {
			return polymorphic ? pluralFor(values.what_type) : (field.lookup || field.related);
		}

		var wrap = el('div.pcm-crm-field.pcm-crm-field-lookup' + (field.wide ? '.pcm-crm-field-wide' : ''), {
			dataset: { field: field.key }
		});

		var selection = null;
		var showAll = false;
		var active = -1;
		var options = [];
		var timer = 0;
		var seq = 0;

		var input = el('input.pcm-crm-lookup-input', {
			id: id,
			type: 'text',
			role: 'combobox',
			autocomplete: 'off',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': listId
		});

		var list = el('ul.pcm-crm-lookup-list', { id: listId, role: 'listbox', hidden: true });
		var pillSlot = el('span.pcm-crm-lookup-pill-slot');
		var box = el('div.pcm-crm-lookup', {}, [pillSlot, input, list]);

		if (polymorphic) {
			var kinds = ['accounts', 'opportunities', 'projects'].filter(function (slug) { return directory[slug]; });
			var kind = el('select.pcm-crm-lookup-kind', {
				'aria-label': 'Kind of record',
				onchange: function (event) {
					values.what_type = singularFor(event.target.value);
					choose(null);
				}
			});

			kind.appendChild(el('option', { value: '', text: '—' }));
			kinds.forEach(function (slug) {
				kind.appendChild(el('option', {
					value: slug,
					text: directory[slug].label,
					selected: target() === slug
				}));
			});

			box.insertBefore(kind, pillSlot);
		}

		function pluralLabel() {
			var t = target();
			return t && directory[t] ? directory[t].plural.toLowerCase() : 'records';
		}

		function narrowing() {
			var filters = {};
			var names = [];

			Object.keys(field.lookup_filter || {}).forEach(function (column) {
				var source = field.lookup_filter[column];

				if (values[source] && Number(values[source])) {
					filters[column] = values[source];
					names.push(values['_' + source + '_name'] || '');
				}
			});

			return { filters: filters, active: Object.keys(filters).length > 0 && !showAll, names: names.filter(Boolean) };
		}

		function paint() {
			clear(pillSlot);

			input.hidden = !!selection;
			input.placeholder = target() ? 'Search ' + pluralLabel() + '…' : 'Choose a kind first';
			input.disabled = !target();

			if (selection) {
				pillSlot.appendChild(el('span.pcm-crm-lookup-pill', {}, [
					recordLink(target(), selection.id, selection.label),
					el('button.pcm-crm-lookup-clear', {
						type: 'button',
						'aria-label': 'Clear ' + field.label,
						text: '×',
						onclick: function () {
							choose(null);
							input.focus();
						}
					})
				]));
			}
		}

		function close() {
			list.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			active = -1;
		}

		function draw(heading, rows, filtered) {
			clear(list);
			options = [];

			if (heading) {
				list.appendChild(el('li.pcm-crm-lookup-heading', { role: 'presentation', text: heading }));
			}

			rows.forEach(function (row) {
				options.push({ kind: 'record', row: row });
			});

			if (!rows.length) {
				list.appendChild(el('li.pcm-crm-lookup-empty', { role: 'presentation', text: 'No matches.' }));
			}

			if (filtered) { options.push({ kind: 'all' }); }
			if (!opts.noCreate && target() && objects[target()] && servedLayout(target())) { options.push({ kind: 'new' }); }

			options.forEach(function (option, i) {
				var content;

				if (option.kind === 'record') {
					content = [objectIcon(target()), el('span', { text: option.row.label })];
				} else if (option.kind === 'all') {
					content = [el('span', { text: 'Show all ' + pluralLabel() })];
				} else {
					content = [el('span.dashicons.dashicons-plus-alt2', { 'aria-hidden': 'true' }), el('span', { text: 'New ' + objects[target()].label })];
				}

				list.appendChild(el('li.pcm-crm-lookup-option.is-' + option.kind, {
					id: listId + '-' + i,
					role: 'option',
					'aria-selected': 'false',
					// mousedown rather than click: the input's blur fires before
					// a click lands, and would close the list out from under it.
					onmousedown: function (event) { event.preventDefault(); pick(i); }
				}, content));
			});

			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		function search(term) {
			var t = target();
			if (!t || !objects[t]) { return; }

			var narrow = narrowing();
			var mine = ++seq;

			// Recently viewed, when nothing is typed and nothing narrows the
			// choice — which is when a person is most likely to want one of them.
			if (!term && !narrow.active) {
				var recent = readRecent(t);

				if (recent.length) {
					draw('Recent ' + pluralLabel(), recent.map(function (item) {
						return { id: item.id, label: item.label };
					}), false);
					return;
				}
			}

			var query = {
				search: term,
				per_page: 10,
				orderby: t === 'contacts' ? 'last_name' : '',
				order: 'ASC'
			};

			if (narrow.active) { query.filters = narrow.filters; }

			api('/' + t, { query: query }).then(function (data) {
				if (mine !== seq) { return; }

				var heading = narrow.active
					? objects[t].plural + (narrow.names.length ? ' at ' + narrow.names.join(', ') : ', narrowed')
					: (term ? '' : objects[t].plural);

				draw(heading, (data.items || []).map(function (row) {
					return { id: row.id, label: objects[t].title(row), row: row };
				}), narrow.active);
			}).catch(function () { close(); });
		}

		function highlight(i) {
			var nodes = list.querySelectorAll('.pcm-crm-lookup-option');

			nodes.forEach(function (node, n) {
				var on = n === i;
				node.classList.toggle('is-active', on);
				node.setAttribute('aria-selected', on ? 'true' : 'false');
				if (on) {
					input.setAttribute('aria-activedescendant', node.id);
					if (node.scrollIntoView) { node.scrollIntoView({ block: 'nearest' }); }
				}
			});

			active = i;
		}

		function pick(i) {
			var option = options[i];
			if (!option) { return; }

			if (option.kind === 'all') {
				showAll = true;
				search(input.value.trim());
				return;
			}

			if (option.kind === 'new') {
				var t = target();
				var prefill = {};

				// What narrowed the search is what the new record belongs to.
				Object.keys(narrowing().filters).forEach(function (column) {
					prefill[column] = narrowing().filters[column];
				});

				if (input.value.trim()) {
					prefill[t === 'contacts' ? 'last_name' : (t === 'project_raid' ? 'title' : 'name')] = input.value.trim();
				}

				close();
				quickCreate(t, prefill, function (created) {
					rememberRecent(t, created.id, objects[t].title(created));
					choose({ id: created.id, label: objects[t].title(created) });
				});
				return;
			}

			choose({ id: option.row.id, label: option.row.label });
		}

		function choose(item) {
			var previous = values[field.key];

			selection = item;
			values[field.key] = item ? item.id : 0;
			values['_' + field.key + '_name'] = item ? item.label : '';
			input.value = '';
			showAll = false;
			close();
			paint();

			if (form === current.form) { current.dirty = true; }

			applyDerived(field.key, values, form);
			applyConditionalFields(values, form);

			if (String(previous || '') !== String(values[field.key] || '')) {
				clearDependents(form, field.key, values);
			}
		}

		input.addEventListener('focus', function () {
			if (!selection) { search(input.value.trim()); }
		});

		input.addEventListener('input', function () {
			window.clearTimeout(timer);
			timer = window.setTimeout(function () { search(input.value.trim()); }, 200);
		});

		input.addEventListener('blur', function () {
			window.setTimeout(close, 120);
		});

		input.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				if (list.hidden) { search(input.value.trim()); return; }
				highlight(Math.min(options.length - 1, active + 1));
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				highlight(Math.max(0, active - 1));
			} else if (event.key === 'Enter') {
				event.preventDefault();
				if (!list.hidden && active >= 0) { pick(active); }
			} else if (event.key === 'Escape') {
				if (!list.hidden) {
					// Closing the list is all Escape should do here, not the modal.
					event.stopPropagation();
					close();
				}
			}
		});

		var start = Number(values[field.key]);
		if (start) {
			selection = {
				id: start,
				label: values['_' + field.key + '_name'] || lookupLabel(target(), start) || '#' + start
			};
		}

		form.pcmLookups = form.pcmLookups || [];
		form.pcmLookups.push({
			field: field,
			clear: function () { if (selection) { choose(null); } }
		});

		paint();

		wrap.appendChild(el('label', { for: id, text: field.label + (field.required ? ' *' : '') }));
		wrap.appendChild(box);

		if (field.note) { wrap.appendChild(el('span.pcm-crm-field-note', { text: field.note })); }

		return wrap;
	}

	/**
	 * Choosing a record clears the lookups it narrows, when what they hold no
	 * longer belongs: a contact at the old account, a task on the old project.
	 * Checked against the record rather than cleared blindly, so a contact who
	 * is also at the new account stays.
	 */
	function clearDependents(form, sourceKey, values) {
		(form.pcmLookups || []).forEach(function (entry) {
			var filter = entry.field.lookup_filter || {};

			Object.keys(filter).forEach(function (column) {
				if (filter[column] !== sourceKey) { return; }

				var chosen = Number(values[entry.field.key]);
				if (!chosen) { return; }

				if (!Number(values[sourceKey])) { return; }

				api('/' + entry.field.lookup + '/' + chosen).then(function (row) {
					if (String(row[column] || '') !== String(values[sourceKey] || '')) { entry.clear(); }
				}).catch(function () { entry.clear(); });
			});
		});
	}

	/**
	 * Create a record from inside a lookup, in a modal of its own over the
	 * form, and hand the new record back.
	 *
	 * Its own host rather than the record modal, which may be the very form the
	 * lookup is on. It creates and nothing else — no related lists, no nested
	 * quick create — because it exists to fill one field.
	 */
	function quickCreate(object, prefill, onCreated) {
		var def = objects[object];

		if (!dom.quick) {
			dom.quickScrim = el('div.pcm-crm-scrim.pcm-crm-scrim-quick', { hidden: true, onclick: closeQuick });
			dom.quick = el('div.pcm-crm-drawer.pcm-crm-quick', { hidden: true, role: 'dialog', 'aria-modal': 'true' });
			dom.root.appendChild(dom.quickScrim);
			dom.root.appendChild(dom.quick);
		}

		var values = Object.assign({}, prefill || {});
		var form = el('form.pcm-crm-details.is-editing', { dataset: { object: object }, onsubmit: function (e) { e.preventDefault(); } });
		form.pcmLookups = [];

		layoutFor(object, values).forEach(function (group) {
			var grid = el('div.pcm-crm-fields');

			group.fields.forEach(function (entry) {
				var field = ( typeof entry === 'string' ) ? fieldDefinition(object, entry) : entry;
				if (field) { grid.appendChild(fieldControl(field, values, form, { noCreate: true })); }
			});

			if (!grid.children.length) { return; }

			if (group.title) {
				var heading = el('h4.pcm-crm-group-head', { text: group.title });
				heading.hidden = !groupHasVisibleChild(grid);
				form.appendChild(heading);
			}

			form.appendChild(grid);
		});

		var status = el('span.pcm-crm-muted', { role: 'status' });

		form.appendChild(el('div.pcm-crm-form-actions.pcm-crm-form-footer', {}, [
			el('button.pcm-btn.pcm-btn-primary', {
				type: 'button',
				text: 'Create',
				onclick: function (event) {
					var button = event.target;
					button.disabled = true;
					status.textContent = 'Saving…';

					api('/' + object, { method: 'POST', body: values }).then(function (record) {
						lookups = {};
						closeQuick();
						onCreated(record);
					}).catch(function (error) {
						status.textContent = error.message;
						button.disabled = false;
					});
				}
			}),
			el('button.pcm-btn.pcm-btn-quiet', { type: 'button', text: 'Cancel', onclick: closeQuick }),
			status
		]));

		clear(dom.quick);
		dom.quick.appendChild(el('div.pcm-crm-modal-head', {}, [
			objectIcon(object),
			el('div.pcm-crm-modal-heading', {}, [
				el('p.pcm-crm-drawer-kicker', { text: 'Quick create' }),
				el('h2', { text: 'New ' + def.label })
			]),
			el('button.pcm-crm-drawer-close', { type: 'button', 'aria-label': 'Close', text: '×', onclick: closeQuick })
		]));
		dom.quick.appendChild(el('div.pcm-crm-modal-body', {}, [form]));

		dom.quick.hidden = false;
		dom.quickScrim.hidden = false;
		applyConditionalFields(values, form);

		var first = form.querySelector('input, select, textarea');
		if (first && first.focus) { first.focus(); }
	}

	function closeQuick() {
		if (dom.quick) { dom.quick.hidden = true; }
		if (dom.quickScrim) { dom.quickScrim.hidden = true; }
	}

	function saveRecord(object, id, values, button, status) {
		button.disabled = true;
		status.textContent = 'Saving…';

		var request = id
			? api('/' + object + '/' + id, { method: 'PUT', body: values })
			: api('/' + object, { method: 'POST', body: values });

		request.then(function (record) {
			status.textContent = 'Saved';
			button.disabled = false;
			current.dirty = false;

			// A new account or contact has to reach the pickers immediately,
			// or the next record cannot be linked to it without a reload.
			lookups = {};
			cardCache = {};

			if (id) {
				// Back to reading, with the related lists fetched again: a save
				// can move what hangs off a record (a stage, an account).
				current.editing = false;
				reloadRecord();
				if (current.mode !== 'page') { refreshView(); }
				return;
			}

			closeDrawer(true);

			var hasPage = !!recordUrl(object, record.id);

			if (afterCreate(hasPage, current.mode === 'page') === 'parent') {
				// Back on the record it was created from, with the new row in
				// the related list it was added to.
				reloadRecord();
				return;
			}

			// Bring whatever is underneath up to date before opening the new
			// record over it in the modal.
			if (!hasPage) { refreshView(); }

			openRecord(object, record.id);
		}).catch(function (error) {
			status.textContent = error.message;
			button.disabled = false;
		});
	}

	/**
	 * Where a newly created record lands: 'open' to open it, 'parent' to stay
	 * on the record page it was created from.
	 *
	 * A record with its own page (a Contact made from an Account) opens on
	 * that page, so the person is not left hunting for what they made. One
	 * with no page — a Project Role, a Milestone — would only open in the
	 * modal again, which then had to be closed by hand; made from a record
	 * page's related list, it returns to that record instead, the new row
	 * visible in the list it was added to.
	 */
	function afterCreate(hasOwnPage, fromRecordPage) {
		return !hasOwnPage && fromRecordPage ? 'parent' : 'open';
	}

	/**
	 * What each object can have hanging off it, and how a new child is linked
	 * back to the parent it was created from.
	 *
	 * The prefill is the whole point of creating from a related list: the link
	 * is made before the form is shown, rather than left to whoever remembers
	 * to set it afterwards.
	 */
	function childTypes(object, record) {
		var soon = new Date();
		soon.setDate(soon.getDate() + 30);
		var closeDate = soon.toISOString().slice(0, 10);

		var openStages = (state.boot.stages || []).filter(function (stage) { return !stage.is_closed; });
		var firstStage = openStages.length ? openStages[0].name : '';

		function activity(prefill) {
			return Object.assign({
				activity_type: 'Call',
				status: 'Not Started',
				priority: 'Normal',
				activity_date: new Date().toISOString().slice(0, 19).replace('T', ' ')
			}, prefill);
		}

		// Providers are appended to whatever core answers, never substituted for
		// it: a module adding a list to an Account must not take away the three
		// it already has. The shared locals are handed over so a provider does
		// not recompute them.
		function withProviders(types) {
			(childProviders[object] || []).forEach(function (provider) {
				types = types.concat(provider(record, {
					firstStage: firstStage,
					closeDate: closeDate,
					activity: activity
				}) || []);
			});

			return types;
		}

		if (object === 'accounts') {
			return withProviders([
				{ id: 'contacts', label: 'Contacts', object: 'contacts', newLabel: 'New Contact',
					prefill: { account_id: record.id, _account_id_name: record.name } },
				{ id: 'opportunities', label: 'Opportunities', object: 'opportunities', newLabel: 'New Opportunity',
					prefill: { account_id: record.id, _account_id_name: record.name, stage_name: firstStage, close_date: closeDate } },
				{ id: 'activities', label: 'Activities', object: 'activities', newLabel: 'New Activity',
					prefill: activity({ what_type: 'account', what_id: record.id }) }
			]);
		}

		if (object === 'contacts') {
			var contactName = ((record.first_name || '') + ' ' + (record.last_name || '')).trim() || record.email || '';

			return withProviders([
				// The account comes from the contact, so a deal created here
				// is attached to both the person and their organization.
				{ id: 'opportunities', label: 'Opportunities', object: 'opportunities', newLabel: 'New Opportunity',
					prefill: { account_id: record.account_id || 0, _account_id_name: record._account_id_name || record._account_name,
						primary_contact_id: record.id, _primary_contact_id_name: contactName,
						stage_name: firstStage, close_date: closeDate } },
				{ id: 'activities', label: 'Activities', object: 'activities', newLabel: 'New Activity',
					prefill: activity({ who_id: record.id,
						what_type: record.account_id ? 'account' : '', what_id: record.account_id || 0 }) }
			]);
		}

		if (object === 'opportunities') {
			return withProviders([
				{ id: 'activities', label: 'Activities', object: 'activities', newLabel: 'New Activity',
					prefill: activity({ what_type: 'opportunity', what_id: record.id,
						who_id: record.primary_contact_id || 0 }) }
			]);
		}

		// An object core knows nothing about is entirely its provider's answer.
		return withProviders([]);
	}

	function renderRelated(object, record, related) {
		var panels = recordHost().querySelector('[data-role="panels"]');
		if (!panels) { return; }

		var tabs = [{ id: 'details', label: 'Details' }];

		// Anything added by a previous render goes, so reopening a record
		// after a save does not stack two copies of each list.
		panels.querySelectorAll('[data-tab]:not([data-tab="details"])').forEach(function (node) {
			node.remove();
		});

		function addTab(id, label, rows, list) {
			tabs.push({ id: id, label: label, count: rows.length });
			panels.appendChild(el('div.pcm-crm-panel', { dataset: { tab: id }, hidden: true }, [list]));
		}

		if (object === 'opportunities' && related.history && related.history.length) {
			tabs.push({ id: 'history', label: 'Stage History', count: related.history.length });
			panels.appendChild(el('div.pcm-crm-panel', { dataset: { tab: 'history' }, hidden: true }, [
				stageHistory(record, related.history)
			]));
		}

		// Outreach lives on the person, which is where you are when you decide
		// to send something.
		if (object === 'contacts') {
			tabs.push({ id: 'email', label: 'Email' });
			panels.appendChild(el('div.pcm-crm-panel', { dataset: { tab: 'email' }, hidden: true }, [
				emailPanel(record, related.enrollments || [])
			]));
		}

		// An activity has no children, but it does have parents, and being
		// able to step up to them is the same affordance in the other
		// direction.
		if (object === 'activities') {
			var parents = activityParents(record);

			if (parents.length) {
				addTab('parents', 'Related to', parents, relatedList({
					rows: parents,
					object: '',
					columns: function (row) {
						return [{ text: row.label, strong: true }, { badge: row.kind }];
					},
					open: function (row) { openRecord(row.object, row.id); }
				}));
			}

			finishTabs(object, record, related, tabs, panels);
			return;
		}

		// Every applicable list gets a tab, empty or not: an empty one is
		// where you go to create the first child, so hiding it would hide the
		// only route to making one.
		childTypes(object, record).forEach(function (child) {
			var rows = related[child.id] || [];

			addTab(child.id, child.label, rows, relatedList({
				rows: rows,
				object: child.object,
				newLabel: child.newLabel,
				onNew: function () {
					openDrawer(child.object, 0, {
						prefill: child.prefill,
						returnTo: { object: object, id: record.id, label: objects[object].title(record) }
					});
				},
				empty: 'No ' + child.label.toLowerCase() + ' yet.',
				columns: relatedColumns(child.id)
			}));
		});

		finishTabs(object, record, related, tabs, panels);
	}

	/**
	 * Add the tabs a module draws itself — a retainer's burn-down — and put
	 * every tab in the order the object asks for, Details always first.
	 */
	function finishTabs(object, record, related, tabs, panels) {
		(recordTabs[object] || []).forEach(function (provider) {
			(provider(record, related, { api: api, el: el }) || []).forEach(function (tab) {
				tabs.push({ id: tab.id, label: tab.label, count: tab.count });
				panels.appendChild(el('div.pcm-crm-panel', { dataset: { tab: tab.id }, hidden: true }, [tab.node]));
			});
		});

		var order = objects[object] && objects[object].tabOrder ? (objects[object].tabOrder(record) || []) : [];

		if (order.length) {
			var rank = function (tab) {
				if (tab.id === 'details') { return -1; }
				var i = order.indexOf(tab.id);
				return i === -1 ? order.length : i;
			};

			tabs = tabs.map(function (tab, i) { return { tab: tab, i: i }; })
				.sort(function (a, b) { return (rank(a.tab) - rank(b.tab)) || (a.i - b.i); })
				.map(function (entry) { return entry.tab; });
		}

		renderTabs(pinTrailingTabs(tabs));
	}

	/**
	 * Activities trails every other related list, on whichever object it
	 * appears — and Tasks, on the one object that has both, sits immediately
	 * before it. Enforced here rather than in each object's own tab order, so
	 * a new object carrying either tab follows the rule without code of its
	 * own, and a hand-written archetype tab list cannot drift from it either.
	 */
	function pinTrailingTabs(tabs) {
		var activities = null;
		var tasks = null;

		var rest = tabs.filter(function (tab) {
			if (tab.id === 'activities') { activities = tab; return false; }
			if (tab.id === 'tasks') { tasks = tab; return false; }
			return true;
		});

		if (tasks) { rest.push(tasks); }
		if (activities) { rest.push(activities); }

		return rest;
	}

	function relatedColumns(kind) {
		// A module's own list brings its own columns; without this every one of
		// them would fall through to the activity shape below and render a
		// column of dashes.
		if (relatedRenderers[kind]) { return relatedRenderers[kind]; }

		if (kind === 'contacts') {
			return function (row) {
				return [
					{ text: objects.contacts.title(row), strong: true },
					{ text: row.title || '—' },
					{ text: row.email || '—' }
				];
			};
		}

		if (kind === 'opportunities') {
			return function (row) {
				return [
					{ text: row.name, strong: true },
					{ badge: row.stage_name, tone: row.is_won ? 'won' : (row.is_closed ? 'lost' : 'open') },
					{ text: formatDate(row.close_date) },
					{ text: money(row.amount), num: true }
				];
			};
		}

		return function (row) {
			return [
				{ text: row.subject || row.activity_type, strong: true },
				{ badge: row.activity_type },
				{ text: row.status || '—' },
				{ text: formatDate(row.activity_date) }
			];
		};
	}

	/**
	 * A deal's path through the stages.
	 *
	 * Read as a timeline rather than a table because the shape of it is the
	 * point — where it moved quickly, and where it sat.
	 */
	function stageHistory(record, rows) {
		var block = el('div.pcm-crm-related');

		var total = rows.reduce(function (sum, row) { return sum + Number(row._days || 0); }, 0);
		var closed = Number(record.is_closed);

		block.appendChild(el('div.pcm-crm-history-summary', {}, [
			el('span', {}, [el('strong', { text: String(total) }), closed ? ' days, start to close' : ' days in the pipeline so far']),
			el('span', {}, [el('strong', { text: String(rows.length) }), rows.length === 1 ? ' stage' : ' stages'])
		]));

		var list = el('ol.pcm-crm-history');

		rows.forEach(function (row) {
			var stalled = row._is_current && !closed && Number(row._days) >= Number(state.boot.stallDays || 30);

			list.appendChild(el('li.pcm-crm-history-step' + (row._is_current ? '.is-current' : '') + (stalled ? '.is-stalled' : ''), {}, [
				el('div.pcm-crm-history-head', {}, [
					el('span.pcm-crm-history-stage', { text: row.stage_name }),
					el('span.pcm-crm-history-days', {
						text: row._is_current
							? Number(row._days) + (Number(row._days) === 1 ? ' day (current)' : ' days (current)')
							: Number(row._days) + (Number(row._days) === 1 ? ' day' : ' days')
					})
				]),
				el('div.pcm-crm-history-when', {
					text: 'Entered ' + formatDateTime(row.entered_date) +
						(row.exited_date ? ' · left ' + formatDateTime(row.exited_date) : '') +
						(row._changed_by ? ' · ' + row._changed_by : '')
				})
			]));
		});

		block.appendChild(list);
		return block;
	}

	/**
	 * Send an email, or start a sequence, from the contact record.
	 */
	function emailPanel(record, enrollments) {
		var panel = el('div.pcm-crm-related');

		if (Number(record.do_not_contact)) {
			// Nothing else on this panel is offered: the flag exists to stop
			// exactly this, and a disabled-looking form invites a workaround.
			panel.appendChild(el('div.pcm-crm-alert', {}, [
				el('strong', { text: 'Do not contact.' }),
				' ' + (record.do_not_contact_reason || 'No reason recorded.') +
					' Nothing can be sent to this contact until that is cleared on the Details tab.'
			]));

			return panel;
		}

		if (!record.email) {
			panel.appendChild(el('p.pcm-crm-related-empty', {
				text: 'This contact has no email address, so nothing can be sent to them yet.'
			}));

			return panel;
		}

		panel.appendChild(sendOneControl(record));
		panel.appendChild(enrollControl(record, enrollments));

		return panel;
	}

	function sendOneControl(record) {
		var subject = el('input', { type: 'text', placeholder: 'Subject' });
		var body = el('textarea', { rows: '8', placeholder: 'Message' });
		var status = el('span.pcm-crm-muted');

		var picker = el('select', {
			onchange: function (event) {
				var template = (state.boot.templates || []).filter(function (t) {
					return String(t.id) === event.target.value;
				})[0];

				if (!template) { return; }

				subject.value = template.subject;
				body.value = template.body;
			}
		});

		picker.appendChild(el('option', { value: '', text: 'Start from a template…' }));
		(state.boot.templates || []).forEach(function (template) {
			picker.appendChild(el('option', { value: template.id, text: template.name }));
		});

		function send(button) {
			if (!subject.value.trim() || !body.value.trim()) {
				status.textContent = 'A subject and a message, please.';
				return;
			}

			button.disabled = true;
			status.textContent = 'Sending…';

			api('/contacts/' + record.id + '/email', {
				method: 'POST',
				body: { subject: subject.value, body: body.value }
			}).then(function () {
				status.textContent = 'Sent, and logged on the activity list.';
				subject.value = '';
				body.value = '';
				button.disabled = false;

				// The send wrote an activity, so the record's other tabs are
				// now out of date.
				reloadRecord();
			}).catch(function (error) {
				status.textContent = error.message;
				button.disabled = false;
			});
		}

		return el('div.pcm-crm-section-block', {}, [
			el('h4.pcm-crm-group-head', { text: 'Send an email' }),
			picker,
			subject,
			body,
			el('div.pcm-crm-form-actions', {}, [
				el('button.pcm-btn.pcm-btn-primary', {
					type: 'button',
					text: 'Send',
					onclick: function (event) { send(event.target); }
				}),
				status
			])
		]);
	}

	function enrollControl(record, enrollments) {
		var block = el('div.pcm-crm-section-block', {}, [
			el('h4.pcm-crm-group-head', { text: 'Sequences' })
		]);

		var active = enrollments.filter(function (row) { return row.status === 'active'; })[0];
		var status = el('span.pcm-crm-muted');

		if (active) {
			var sequence = (state.boot.sequences || []).filter(function (s) {
				return Number(s.id) === Number(active.sequence_id);
			})[0];

			block.appendChild(el('p', {}, [
				el('strong', { text: sequence ? sequence.name : 'A sequence' }),
				' — step ' + (Number(active.current_step) + 1) +
					(active.next_send_at ? ', next on ' + formatDate(active.next_send_at) : '')
			]));

			block.appendChild(el('div.pcm-crm-form-actions', {}, [
				el('button.pcm-btn.pcm-btn-danger', {
					type: 'button',
					text: 'They replied — stop',
					onclick: function (event) { stopEnrollment(active, record, event.target, status, 'They replied'); }
				}),
				el('button.pcm-btn.pcm-btn-quiet', {
					type: 'button',
					text: 'Stop',
					onclick: function (event) { stopEnrollment(active, record, event.target, status, 'Stopped by hand'); }
				}),
				status
			]));
		} else {
			var picker = el('select');
			picker.appendChild(el('option', { value: '', text: 'Choose a sequence…' }));

			(state.boot.sequences || []).forEach(function (sequence) {
				picker.appendChild(el('option', {
					value: sequence.id,
					text: sequence.name + ' (' + sequence.steps + (sequence.steps === 1 ? ' step)' : ' steps)')
				}));
			});

			block.appendChild(el('div.pcm-crm-form-actions', {}, [
				picker,
				el('button.pcm-btn.pcm-btn-primary', {
					type: 'button',
					text: 'Enroll',
					onclick: function (event) {
						if (!picker.value) { status.textContent = 'Pick a sequence first.'; return; }

						event.target.disabled = true;
						status.textContent = 'Enrolling…';

						api('/contacts/' + record.id + '/enroll', {
							method: 'POST',
							body: { sequence_id: Number(picker.value) }
						}).then(function () {
							reloadRecord();
						}).catch(function (error) {
							status.textContent = error.message;
							event.target.disabled = false;
						});
					}
				}),
				status
			]));
		}

		var past = enrollments.filter(function (row) { return row.status !== 'active'; });

		if (past.length) {
			var list = el('ul.pcm-crm-related-list');

			past.forEach(function (row) {
				var sequence = (state.boot.sequences || []).filter(function (s) {
					return Number(s.id) === Number(row.sequence_id);
				})[0];

				list.appendChild(el('li', {}, [
					el('span', {}, [
						el('span.primary', { text: sequence ? sequence.name : 'Sequence #' + row.sequence_id }),
						el('span.secondary', {
							text: ' — ' + row.status + (row.stopped_reason ? ': ' + row.stopped_reason : '')
						})
					])
				]));
			});

			block.appendChild(el('p.pcm-crm-field-note', { text: 'Earlier runs' }));
			block.appendChild(list);
		}

		return block;
	}

	function stopEnrollment(enrollment, record, button, status, reason) {
		button.disabled = true;
		status.textContent = 'Stopping…';

		api('/enrollments/' + enrollment.id + '/stop', { method: 'POST', body: { reason: reason } })
			.then(function () { reloadRecord(); })
			.catch(function (error) {
				status.textContent = error.message;
				button.disabled = false;
			});
	}

	/**
	 * The records an activity hangs off: its contact, and whichever account or
	 * opportunity it was logged against.
	 */
	function activityParents(record) {
		var rows = [];

		if (record.who_id) {
			rows.push({
				id: record.who_id,
				object: 'contacts',
				kind: 'Contact',
				label: record._who_id_name || record._contact_name || lookupLabel('contacts', record.who_id) || 'Contact #' + record.who_id
			});
		}

		var object = record.what_type ? pluralFor(record.what_type) : '';

		if (record.what_id && object) {
			rows.push({
				id: record.what_id,
				object: object,
				kind: objects[object] ? objects[object].label : 'Record',
				label: record._what_id_name || lookupLabel(object, record.what_id) || 'Record #' + record.what_id
			});
		}

		return rows;
	}

	/**
	 * A Salesforce-style related list: the parent's children as rows that open
	 * their own record.
	 *
	 * Rows are buttons rather than divs with a click handler, so they are
	 * reachable by keyboard and announced as actionable — a related list whose
	 * only affordance is the mouse pointer is half a control.
	 */
	function relatedList(config) {
		var block = el('div.pcm-crm-related');

		if (config.onNew) {
			block.appendChild(el('div.pcm-crm-related-toolbar', {}, [
				el('button.pcm-btn.pcm-btn-primary.pcm-btn-sm', {
					type: 'button',
					text: config.newLabel || 'New',
					onclick: config.onNew
				})
			]));
		}

		if (!config.rows.length) {
			block.appendChild(el('p.pcm-crm-related-empty', { text: config.empty || 'None yet.' }));
			return block;
		}

		var open = config.open || function (row) { openRecord(config.object, row.id); };

		var list = el('div.pcm-crm-related-rows');

		config.rows.forEach(function (row) {
			var cells = config.columns(row).map(function (col) {
				if (col.badge) {
					return el('span.pcm-crm-cell', {}, [
						el('span.pcm-crm-badge' + (col.tone ? '.pcm-crm-badge-' + col.tone : ''), { text: col.badge })
					]);
				}

				return el(
					'span.pcm-crm-cell' + (col.strong ? '.pcm-crm-cell-primary' : '') + (col.num ? '.pcm-crm-num' : ''),
					{ text: col.text }
				);
			});

			cells.push(el('span.pcm-crm-cell-go', { text: '›', 'aria-hidden': 'true' }));

			list.appendChild(el('button.pcm-crm-related-row', {
				type: 'button',
				title: config.object ? 'Open this ' + config.object.replace(/s$/, '') : 'Open this record',
				onclick: function () { open(row); }
			}, cells));
		});

		block.appendChild(list);
		return block;
	}


	/* ---------------------------------------------------------------------
	   Pipeline
	   --------------------------------------------------------------------- */

	function renderPipeline() {
		renderFilters(objects.opportunities.filters().filter(function (filter) {
			// The board is already organised by stage, and a status filter on
			// a board of stages would just empty columns.
			return filter.key !== 'stage_name' && filter.key !== 'is_closed';
		}), loadPipeline, [], 'opportunities');

		clear(dom.actions, el('button.pcm-btn.pcm-btn-primary', {
			type: 'button',
			text: 'New Opportunity',
			onclick: function () { openDrawer('opportunities', 0); }
		}));

		loadPipeline();
	}

	function loadPipeline() {
		writeHash();

		api('/pipeline', { query: state.query }).then(function (data) {
			var open = data.columns.filter(function (column) { return !column.isClosed; });
			var value = open.reduce(function (acc, column) { return acc + column.total; }, 0);
			var count = open.reduce(function (acc, column) { return acc + column.count; }, 0);

			setCount('<strong>' + count + '</strong> open · <strong>' + money(value) + '</strong>');

			var board = el('div.pcm-crm-pipeline');
			data.columns.forEach(function (column) { board.appendChild(pipelineColumn(column)); });

			clear(dom.body, board);
		}).catch(showError);
	}

	function pipelineColumn(column) {
		var node = el('div.pcm-crm-column', { dataset: { stage: column.stage } });

		node.appendChild(el('div.pcm-crm-column-head', {}, [
			el('span.pcm-crm-column-name', { text: column.stage }),
			el('span.pcm-crm-column-total', { text: column.count + ' · ' + money(column.total) })
		]));

		if (!column.items.length) {
			node.appendChild(el('p.pcm-crm-column-empty', { text: 'Nothing here' }));
		}

		column.items.forEach(function (item) { node.appendChild(dealCard(item)); });

		node.addEventListener('dragover', function (event) {
			event.preventDefault();
			node.classList.add('is-over');
		});
		node.addEventListener('dragleave', function () { node.classList.remove('is-over'); });
		node.addEventListener('drop', function (event) {
			event.preventDefault();
			node.classList.remove('is-over');

			var id = event.dataTransfer.getData('text/plain');
			if (!id) { return; }

			var body = { stage_name: column.stage };

			// The server refuses a loss with no reason, and a card that snaps
			// back with an error underneath the board is a poor way to learn
			// that — so the reason is asked for before the move is attempted.
			if (stageIsLost(column.stage)) {
				var existing = (column.items || []).filter(function (item) { return String(item.id) === String(id); })[0];
				var reason = window.prompt('Why was this lost?', (existing && existing.closed_lost_reason) || '');

				// Cancelled, or left blank: the deal stays where it was rather
				// than moving without the reason that move requires.
				if (reason === null || !reason.trim()) { return; }

				body.closed_lost_reason = reason.trim();
			}

			// The board is reloaded rather than patched: moving a card changes
			// two column totals and the deal's probability, and re-fetching is
			// both simpler and guaranteed to match what was stored.
			api('/opportunities/' + id, { method: 'PUT', body: body })
				.then(loadPipeline)
				.catch(showError);
		});

		return node;
	}

	function dealCard(item) {
		var card = el('div.pcm-crm-card-deal', {
			draggable: 'true',
			tabindex: '0',
			onclick: function () { openDrawer('opportunities', item.id); },
			onkeydown: function (event) {
				if (event.key === 'Enter') { openDrawer('opportunities', item.id); }
			}
		}, [
			el('span.name', { text: item.name }),
			el('span.meta', {}, [
				el('span', { text: item._account_name || '—' }),
				el('span', { text: money(item.amount) })
			]),
			el('span.meta', {}, [
				el('span.pcm-crm-muted', { text: 'Closes ' + formatDate(item.close_date) }),
				stageAge(item)
			])
		]);

		if (item._is_stalled) { card.classList.add('is-stalled'); }

		card.addEventListener('dragstart', function (event) {
			event.dataTransfer.setData('text/plain', String(item.id));
			event.dataTransfer.effectAllowed = 'move';
			card.classList.add('is-dragging');
		});
		card.addEventListener('dragend', function () { card.classList.remove('is-dragging'); });

		return card;
	}

	/**
	 * How long a deal has sat where it is.
	 *
	 * The board exists to show what is not moving, so this is on the card
	 * rather than a column to sort by — you should not have to go looking.
	 */
	function stageAge(item) {
		var days = Number(item._days_in_stage || 0);
		var label = days === 1 ? '1 day here' : days + ' days here';

		if (item._is_stalled) {
			return el('span.pcm-crm-stalled', { title: 'No stage change in ' + days + ' days', text: '⚠ ' + label });
		}

		return el('span.pcm-crm-muted', { text: label });
	}

	/**
	 * Open the Reports screen on a slice of the data.
	 *
	 * The dashboard's own filters are carried through unchanged and the
	 * clicked dimension is added to them, so a drill-down always shows the
	 * records the number was counted from — a chart that reports one figure
	 * and drills into another is worse than one you cannot click at all.
	 *
	 * Delivered as a page load with a hash rather than an in-page view swap,
	 * so the result is a real URL: linkable, bookmarkable, and survives the
	 * back button.
	 */
	function drillTo(object, extraFilters, options) {
		options = options || {};

		var filters = Object.assign({}, state.query.filters, extraFilters || {});

		// Filters on the object's parent do not survive a change of object —
		// an account.industry filter means nothing on the activities table.
		if (object !== 'opportunities') {
			Object.keys(filters).forEach(function (key) {
				if (key.indexOf('.') !== -1) { delete filters[key]; }
			});
		}

		var payload = {
			s: state.query.search || undefined,
			f: Object.keys(filters).length ? filters : undefined,
			ro: object,
			rg: options.groupBy || defaultGroupBy(object),
			t: options.title || undefined
		};

		Object.keys(payload).forEach(function (key) {
			if (payload[key] === undefined) { delete payload[key]; }
		});

		window.location.href = screenUrl('pcm-crm-reports', 0, '#' + encodeURIComponent(JSON.stringify(payload)));
	}

	/**
	 * Served by /schema from the object registry, so an object states it beside
	 * its own field map. The literals remain as a fallback for the moment
	 * before /schema has landed.
	 */
	function defaultGroupBy(object) {
		var schema = state.schema && state.schema[object];

		if (schema && schema.groupBy) { return schema.groupBy; }

		return {
			accounts: 'type',
			contacts: 'lead_source',
			opportunities: 'stage_name',
			activities: 'activity_type'
		}[object] || '';
	}

	/**
	 * The first and last moment of a YYYY-MM month, for drilling into a bar of
	 * a monthly chart.
	 */
	function monthRange(month) {
		var parts = month.split('-');
		var last = new Date(Number(parts[0]), Number(parts[1]), 0).getDate();

		return { min: month + '-01 00:00:00', max: month + '-' + String(last).padStart(2, '0') + ' 23:59:59' };
	}

	/* ---------------------------------------------------------------------
	   Recycle bin
	   --------------------------------------------------------------------- */

	/**
	 * Deleted records, per object, with a way back.
	 *
	 * The CRM soft-deletes the way Salesforce does, which is only defensible
	 * if there is somewhere to see what was deleted and undo it. Without this
	 * screen a deleted record was simply gone from every view while still
	 * sitting in the table.
	 */
	function renderRecycleBin() {
		clear(dom.filters);
		clear(dom.actions);

		api('/recycle-bin').then(function (counts) {
			var total = counts.reduce(function (sum, row) { return sum + row.count; }, 0);

			if (!counts.length) {
				clear(dom.body, el('div.pcm-crm-empty', {}, [
					el('h3', { text: 'Nothing to show' }),
					el('p', { text: 'No objects report a recycle bin.' })
				]));
				return;
			}

			if (!state.binObject) {
				// Open on something with contents rather than on an empty tab
				// that says nothing is here when something is.
				var populated = counts.filter(function (row) { return row.count > 0; })[0];
				state.binObject = populated ? populated.object : counts[0].object;
			}

			var pills = el('div.pcm-crm-object-switch');

			counts.forEach(function (row) {
				pills.appendChild(el('button.pcm-crm-object-pill' + (row.object === state.binObject ? '.is-active' : ''), {
					type: 'button',
					onclick: function () { state.binObject = row.object; renderRecycleBin(); }
				}, [
					row.label,
					el('span.pcm-crm-tab-count', { text: String(row.count) })
				]));
			});

			clear(dom.filters, pills);

			if (!total) {
				clear(dom.body, el('div.pcm-crm-empty', {}, [
					el('h3', { text: 'The bin is empty' }),
					el('p', { text: 'Records you delete in the CRM appear here, and can be put back.' })
				]));
				return;
			}

			// Emptying everything belongs in the header rather than under one
			// object's table, where it would read as being about that object.
			clear(dom.actions, el('button.pcm-btn.pcm-btn-danger', {
				type: 'button',
				text: 'Empty the whole bin (' + total + ')',
				onclick: function (event) {
					if (!window.confirm('Permanently delete all ' + total + ' records in the bin, across every object? This cannot be undone.')) { return; }

					event.target.disabled = true;

					api('/recycle-bin', { method: 'POST' })
						.then(function () {
							state.binObject = '';
							renderRecycleBin();
						})
						.catch(showError);
				}
			}));

			loadRecycleBin();
		}).catch(showError);
	}

	function loadRecycleBin() {
		var object = state.binObject;
		var def = objects[object];

		api('/' + object, {
			query: {
				filters: { is_deleted: '1' },
				include_deleted: 1,
				orderby: 'last_modified_date',
				order: 'DESC',
				per_page: 100
			}
		}).then(function (data) {
			clear(dom.body);

			if (!data.items.length) {
				dom.body.appendChild(el('div.pcm-crm-empty', {}, [
					el('p', { text: 'Nothing deleted in ' + def.plural.toLowerCase() + '.' })
				]));
				return;
			}

			dom.body.appendChild(buildTable(object, def, data.items, [
				{
					label: 'Restore',
					title: 'Put this record back',
					run: function (row, reload) {
						api('/' + object + '/' + row.id + '/restore', { method: 'POST' })
							.then(function () { renderRecycleBin(); })
							.catch(showError);
					}
				},
				{
					label: 'Delete forever',
					danger: true,
					run: function (row) {
						if (!window.confirm('Permanently delete “' + def.title(row) + '”? This cannot be undone.')) { return; }

						api('/' + object + '/' + row.id + '/purge', { method: 'POST' })
							.then(function () { renderRecycleBin(); })
							.catch(showError);
					}
				}
			], loadRecycleBin));

			dom.body.appendChild(el('div.pcm-crm-form-actions', {}, [
				el('button.pcm-btn.pcm-btn-danger', {
					type: 'button',
					text: 'Empty ' + def.plural.toLowerCase() + ' bin',
					onclick: function () {
						if (!window.confirm('Permanently delete all ' + data.items.length + ' deleted ' + def.plural.toLowerCase() + '? This cannot be undone.')) { return; }

						api('/' + object + '/empty-bin', { method: 'POST' })
							.then(function () { renderRecycleBin(); })
							.catch(showError);
					}
				}),
				el('span.pcm-crm-muted', {
					text: 'Restoring puts a record back exactly as it was, with its related records intact. ' +
						'Deleting for good is the only thing here that cannot be undone.'
				})
			]));
		}).catch(showError);
	}

	/* ---------------------------------------------------------------------
	   Dashboard
	   --------------------------------------------------------------------- */

	/**
	 * A line naming the screen and its filters, for the printed page only.
	 *
	 * A printout with no record of what it was filtered to is a page of
	 * numbers about nothing, and the filter controls themselves do not print.
	 */
	function printHead(title) {
		var applied = builtFilters(state.view === 'reports' ? report.object : 'opportunities')
			.map(function (entry) {
				var field = findField(state.view === 'reports' ? report.object : 'opportunities', entry.key);
				return (field ? field.group + ' ' + field.label : entry.key) + ' ' +
					operatorLabel(field, entry.filter.op) + ' ' + valueLabel(field, entry.filter);
			});

		if (state.query.search) { applied.unshift('Search: ' + state.query.search); }

		return el('div.pcm-crm-print-head', {}, [
			el('strong', { text: title }),
			el('div', { text: 'Printed ' + formatDateTime(new Date().toISOString().slice(0, 19).replace('T', ' ')) }),
			applied.length ? el('div', { text: 'Filtered by: ' + applied.join('; ') }) : null
		]);
	}

	function headerActions(title, scheduleType) {
		return [
			el('button.pcm-btn.pcm-btn-quiet', {
				type: 'button',
				text: 'Print',
				onclick: function () { window.print(); }
			}),
			el('button.pcm-btn.pcm-btn-quiet', {
				type: 'button',
				text: 'Schedule',
				onclick: function () { openScheduleForm(scheduleType, title); }
			})
		];
	}

	function renderDashboard() {
		// Scoped to opportunities, which is what most of this screen counts.
		// A filter on a column another object does not have is dropped by that
		// object's model, so the account and contact tiles answer only the
		// filters that apply to them.
		renderFilters([
			{ key: 'owner_id', label: 'Owner', options: ownerOptions() },
			{ key: 'created_date', label: 'Created', range: 'date' }
		], loadDashboard, [], 'opportunities');

		clear(dom.actions);
		headerActions('CRM Dashboard', 'dashboard').forEach(function (node) { dom.actions.appendChild(node); });

		loadDashboard();
	}

	function loadDashboard() {
		writeHash();

		api('/dashboard', { query: state.query }).then(function (data) {
			var tiles = data.tiles;

			setCount('');

			var stalledCutoff = new Date();
			stalledCutoff.setDate(stalledCutoff.getDate() - Number(tiles.stallDays || 30));
			var stalledMax = stalledCutoff.toISOString().slice(0, 10) + ' 23:59:59';

			var grid = el('div.pcm-crm-tiles', {}, [
				tile('Open pipeline', money(tiles.openValue), tiles.openCount + ' open ' + (tiles.openCount === 1 ? 'deal' : 'deals'), false,
					function () { drillTo('opportunities', { is_closed: '0' }, { title: 'Open pipeline' }); }),
				tile('Weighted', money(tiles.weightedValue), 'Discounted by stage probability', false,
					function () { drillTo('opportunities', { is_closed: '0' }, { title: 'Open pipeline (weighted)' }); }),
				tile('Won', money(tiles.wonValue), tiles.wonCount + ' closed won', false,
					function () { drillTo('opportunities', { is_won: '1' }, { title: 'Closed won' }); }),
				tile('Win rate', tiles.winRate + '%', 'Of everything closed', false,
					function () { drillTo('opportunities', { is_closed: '1' }, { title: 'Everything closed' }); }),
				tile('Sales cycle', tiles.cycleDays + (tiles.cycleDays === 1 ? ' day' : ' days'), 'Average, created to won', false,
					function () { drillTo('opportunities', { is_won: '1' }, { title: 'Won deals, by cycle' }); }),
				tile('Stalled', String(tiles.stalledCount),
					'No stage change in ' + tiles.stallDays + '+ days', tiles.stalledCount > 0,
					function () {
						drillTo('opportunities', { is_closed: '0', stage_entered_date: { max: stalledMax } },
							{ title: 'Stalled ' + tiles.stallDays + '+ days' });
					}),
				tile('Accounts', String(tiles.accountCount), tiles.contactCount + ' contacts', false,
					function () { drillTo('accounts', {}, { title: 'Accounts' }); }),
				tile('Overdue', String(tiles.overdueCount), 'Activities past due', tiles.overdueCount > 0,
					function () {
						drillTo('activities', { is_completed: '0', due_date: { max: today() } }, { title: 'Overdue activities' });
					})
			]);

			var charts_ = el('div.pcm-crm-charts', {}, [
				chartCard('Pipeline by stage', charts.funnel(data.charts.pipelineByStage, {
					onSelect: function (row) {
						drillTo('opportunities', { stage_name: row.value, is_closed: '0' }, { title: row.value });
					}
				})),
				chartCard('Created and won by month',
					charts.columns(data.charts.byMonth, [{ key: 'created' }, { key: 'won' }], {
						onSelect: function (row) {
							drillTo('opportunities', { created_date: monthRange(row.month) },
								{ title: 'Created in ' + row.label });
						}
					}),
					legend([{ label: 'Created' }, { label: 'Won' }])),
				chartCard('Value by lead source', charts.bar(data.charts.byLeadSource, {
					onSelect: function (row) {
						drillTo('opportunities', { lead_source: row.value }, { title: row.value || 'No lead source', groupBy: 'stage_name' });
					}
				})),
				chartCard('Activity by type', charts.donut(data.charts.activityByType, {
					metric: 'count',
					centerLabel: 'activities',
					onSelect: function (row) {
						drillTo('activities', { activity_type: row.value }, { title: row.value || 'Activities' });
					}
				}), legend(data.charts.activityByType.map(function (row) {
					return { label: (row.value || 'Unspecified') + ' (' + row.count + ')' };
				}))),
				chartCard('Average days in stage', charts.days(data.charts.avgDaysByStage, {
					onSelect: function (row) {
						drillTo('opportunities', { stage_name: row.value }, { title: row.value });
					}
				})),
				chartCard('Stage conversion', conversionTable(data.charts.conversion))
			]);

			clear(dom.body);
			dom.body.appendChild(printHead('CRM Dashboard'));
			dom.body.appendChild(grid);
			dom.body.appendChild(charts_);
		}).catch(showError);
	}

	function tile(label, value, note, alert, onSelect) {
		var children = [
			el('p.pcm-crm-tile-label', { text: label }),
			el('div.pcm-crm-tile-value', { text: value }),
			note ? el('div.pcm-crm-tile-note', { text: note }) : null
		];

		if (!onSelect) {
			return el('div.pcm-crm-tile' + (alert ? '.pcm-crm-tile-alert' : ''), {}, children);
		}

		// A button, so the drill-down is reachable by keyboard and announced
		// as an action rather than as a number someone might click.
		return el('button.pcm-crm-tile.pcm-crm-tile-link' + (alert ? '.pcm-crm-tile-alert' : ''), {
			type: 'button',
			onclick: onSelect
		}, children);
	}

	function chartCard(title, svgNode, legendNode) {
		return el('div.pcm-crm-card.pcm-crm-chart', {}, [
			el('h3', { text: title }),
			svgNode,
			legendNode || null
		]);
	}

	function legend(items) {
		var palette = charts.palette();

		return el('div.pcm-crm-legend', {}, items.map(function (item, i) {
			return el('span', {}, [
				el('i', { style: 'background:' + palette[i % palette.length] }),
				item.label
			]);
		}));
	}

	/**
	 * Conversion as a table rather than a chart.
	 *
	 * Each row is a claim with two numbers behind it — "62% of deals that
	 * reached Proposal went on to Negotiation" — and a bar would show the
	 * percentage while hiding the counts that say whether to believe it.
	 */
	function conversionTable(rows) {
		if (!rows || !rows.length) {
			return el('p.pcm-crm-related-empty', { text: 'No stage history yet.' });
		}

		var body = el('tbody');

		rows.forEach(function (row) {
			body.appendChild(el('tr', {
				title: 'Show deals currently in ' + row.stage,
				onclick: function () { drillTo('opportunities', { stage_name: row.stage }, { title: row.stage }); }
			}, [
				el('td.pcm-crm-strong', { text: row.stage }),
				el('td', {}, [el('span.pcm-crm-muted', { text: '→ ' + (row.next || 'Won') })]),
				el('td.pcm-crm-num', { text: String(row.deals) }),
				el('td.pcm-crm-num', { text: String(row.moved) }),
				el('td.pcm-crm-num', {}, [
					el('span.pcm-crm-rate', {}, [
						el('span.pcm-crm-rate-bar', { style: 'width:' + row.rate + '%' }),
						el('span.pcm-crm-rate-value', { text: row.rate + '%' })
					])
				])
			]));
		});

		return el('div.pcm-crm-table-wrap', { style: 'border:0' }, [
			el('table.pcm-crm-table', {}, [
				el('thead', {}, [el('tr', {}, [
					el('th', { text: 'Stage' }),
					el('th', { text: 'To' }),
					el('th', { text: 'Reached' }),
					el('th', { text: 'Moved on' }),
					el('th', { text: 'Rate' })
				])]),
				body
			])
		]);
	}

	/* ---------------------------------------------------------------------
	   Reports
	   --------------------------------------------------------------------- */

	function renderReports() {
		// Whichever objects /schema says are reportable, with the columns each
		// one offers. Built from the registry so a new object appears here by
		// registering rather than by editing this file.
		var groupOptions = {};

		Object.keys(state.schema || {}).forEach(function (slug) {
			var options = state.schema[slug].groupOptions || [];

			if (options.length) { groupOptions[slug] = options; }
		});

		function build() {
			var objectSelect = el('select', {
				onchange: function (event) {
					report.object = event.target.value;
					report.groupBy = (groupOptions[report.object] || [''])[0];
					state.query.filters = {};
					state.query.page = 1;
					renderReports();
				}
			});

			// Only what can actually be grouped. The dropdown used to list every
			// object the app knows, including the three that are machinery —
			// and picking one of those threw on groupOptions[object][0], since
			// there were never any columns behind it.
			Object.keys(groupOptions).forEach(function (key) {
				if (!objects[key]) { return; }

				objectSelect.appendChild(el('option', {
					value: key, text: objects[key].plural, selected: report.object === key
				}));
			});

			var groupSelect = el('select', {
				onchange: function (event) { report.groupBy = event.target.value; loadReport(); }
			});

			(groupOptions[report.object] || []).forEach(function (key) {
				groupSelect.appendChild(el('option', {
					value: key,
					text: key.replace(/_id$/, '').replace(/_/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); }),
					selected: report.groupBy === key
				}));
			});

			renderFilters(objects[report.object].filters(), loadReport, canObject(report.object, 'export') ? [
				el('a.pcm-btn.pcm-btn-quiet', { href: exportUrl(report.object), text: 'Export CSV' })
			] : [], report.object);

			// The object and grouping choices belong with the filters they act
			// on, so they are prepended into the same bar rather than sitting
			// in a second one above it.
			dom.filters.insertBefore(
				el('div.pcm-crm-filter', {}, [el('label', { text: 'Report on' }), objectSelect]),
				dom.filters.firstChild
			);
			dom.filters.insertBefore(
				el('div.pcm-crm-filter', {}, [el('label', { text: 'Group by' }), groupSelect]),
				dom.filters.firstChild.nextSibling
			);
		}

		build();

		clear(dom.actions);

		// A drilled report says what slice it is showing, with a way back to
		// the whole set — otherwise a filtered report and an empty one look
		// identical.
		if (state.drillTitle) {
			dom.actions.appendChild(el('span.pcm-crm-drill', {}, [
				'Showing: ' + state.drillTitle,
				el('a.pcm-crm-drill-clear', {
					href: screenUrl('pcm-crm-reports'),
					title: 'Clear this drill-down',
					text: '×'
				})
			]));
		}

		headerActions(state.drillTitle || objects[report.object].plural, 'report')
			.forEach(function (node) { dom.actions.appendChild(node); });

		loadReport();
	}

	function loadReport() {
		writeHash();

		api('/report', {
			query: Object.assign({}, state.query, { object: report.object, group_by: report.groupBy })
		}).then(function (data) {
			var def = objects[report.object];

			setCount('<strong>' + data.total + '</strong> rows' + (data.sum ? ' · <strong>' + money(data.sum) + '</strong>' : ''));

			clear(dom.body);
			dom.body.appendChild(printHead(
				(state.drillTitle ? state.drillTitle + ' — ' : '') + objects[report.object].plural
			));

			if (data.groups.length) {
				var rows = data.groups.map(function (row) {
					return {
						value: labelForGroup(report.groupBy, row.value),
						count: row.count,
						total: row.total
					};
				});

				dom.body.appendChild(el('div.pcm-crm-card.pcm-crm-chart', { style: 'max-width:620px' }, [
					el('h3', { text: 'Grouped by ' + report.groupBy.replace(/_/g, ' ') }),
					charts.bar(rows)
				]));
			}

			if (data.rows.length) {
				dom.body.appendChild(buildTable(report.object, def, data.rows, null, loadReport));
			} else {
				dom.body.appendChild(el('div.pcm-crm-empty', {}, [el('p', { text: 'No records match these filters.' })]));
			}
		}).catch(showError);
	}

	/**
	 * Group values come back as raw column values, so an owner or account
	 * grouping would otherwise show a row of bare ids.
	 */
	function labelForGroup(key, value) {
		if (key === 'owner_id') {
			var owner = (state.boot.owners || []).filter(function (o) { return String(o.id) === String(value); })[0];
			return owner ? owner.name : 'Unassigned';
		}

		if (key === 'account_id') {
			return lookupLabel('accounts', value) || 'No account';
		}

		return value;
	}

	/**
	 * Schedule the view currently on screen.
	 *
	 * The filters are captured as they stand and stored with the schedule, so
	 * what arrives on Monday is the view that was scheduled — not whatever the
	 * dashboard happens to be filtered to when the cron runs.
	 */
	function openScheduleForm(type, title) {
		var filters = Object.assign({}, state.query.filters);

		openDrawer('schedules', 0, {
			prefill: {
				name: title,
				report_type: type,
				object: type === 'report' ? report.object : '',
				group_by: type === 'report' ? report.groupBy : '',
				filters: JSON.stringify(filters),
				recipients: '',
				frequency: 'weekly',
				send_time: '08:00',
				day_of_week: 1,
				day_of_month: 1,
				attach_csv: 1,
				is_active: 1
			}
		});
	}

	/**
	 * Send a schedule now, from its own record.
	 */
	/**
	 * Send a schedule now.
	 *
	 * Saves first. The server sends the stored record, so sending without
	 * saving would deliver the previous version — silently, and looking for
	 * all the world like the edits on screen had been used.
	 */
	function sendScheduleNow(record, values, button, status) {
		button.disabled = true;
		status.textContent = 'Saving…';

		api('/schedules/' + record.id, { method: 'PUT', body: values }).then(function () {
			status.textContent = 'Sending…';

			return api('/schedules/' + record.id + '/send', { method: 'POST' });
		}).then(function (result) {
			status.textContent = result.sent
				? 'Sent to ' + result.recipients.join(', ')
				: 'The server would not send it. Check the site’s mail configuration.';
			button.disabled = false;
			refreshView();
		}).catch(function (error) {
			status.textContent = error.message;
			button.disabled = false;
		});
	}

	/* ---------------------------------------------------------------------
	   Boot
	   --------------------------------------------------------------------- */

	controls['email-body'] = {
		wide: true,
		label: true,
		build: function (field, values) { return emailBodyControl(field, values); }
	};
	controls['sequence-steps'] = {
		wide: true,
		label: true,
		build: function (field, values) { return sequenceStepsControl(values); }
	};
	controls.recipients = {
		wide: true,
		label: true,
		build: function (field, values) { return recipientsControl(values); }
	};

	views.dashboard = { render: renderDashboard, load: loadDashboard };
	views.pipeline = { render: renderPipeline, load: loadPipeline };
	views.reports = { render: renderReports, load: loadReport };
	views.recycle = { render: renderRecycleBin, load: renderRecycleBin };

	function refreshView() {
		var view = views[state.view];

		// On a record page the record is the view; its list is a Back away.
		if (state.recordId && pageMode(state.view)) {
			renderRecordPage(state.view, state.recordId);
			return;
		}

		if (view && view.load) { view.load(); }
		else if (objects[state.view]) { loadList(state.view); }
	}

	function render() {
		var view = views[state.view];

		if (dom.root && dom.root.classList) { dom.root.classList.remove('is-record'); }
		hideCard();

		// A record id in the hash — a link from the notification email, a
		// lookup on another record, or a reloaded page — is that record's page.
		if (state.recordId && pageMode(state.view)) {
			renderRecordPage(state.view, state.recordId);
			return;
		}

		current = { host: null, mode: '', object: '', record: null, options: {}, editing: false, dirty: false, token: current.token + 1 };

		if (view) { view.render(); }
		else if (objects[state.view]) { renderList(state.view); }
		else { clear(dom.body, el('p', { text: 'Unknown screen.' })); }

		// An object with no page of its own still opens in the modal.
		if (state.recordId && objects[state.view]) {
			openDrawer(state.view, state.recordId);
		}
	}

	/**
	 * Back and Forward between a list and its records.
	 */
	function onPopState() {
		if (current.editing && current.dirty && !window.confirm('Discard your unsaved changes?')) {
			// The browser has already moved; put the record back where it was.
			writeHash(true);
			return;
		}

		current.dirty = false;
		state.recordId = 0;
		state.query = { search: '', filters: {}, orderby: '', order: 'DESC', page: 1, per_page: state.query.per_page };
		readHash();

		if (dom.drawer && !dom.drawer.hidden) {
			dom.drawer.hidden = true;
			dom.scrim.hidden = true;
		}

		closeQuick();
		render();
	}

	function init() {
		var root = document.querySelector('.pcm-crm[data-view]');

		// No mount is not a failure — crm.js can be enqueued on a page that
		// simply is not a CRM screen.
		if (!root) { return; }

		// A missing config is. It means the localized PCM_CRM block never ran —
		// most plausibly an asset optimiser moved or dropped it, which is only
		// possible on the front end where minifying and combining are active.
		// Returning quietly here is what leaves the screen on "Loading…" with a
		// clean console, and that is the hardest version of this failure to
		// diagnose, so say so on the screen instead.
		if (!cfg.root) {
			clear(root.querySelector('[data-role="body"]') || root, el('div.pcm-crm-error', {
				text: 'The CRM could not start — its configuration did not load. If an asset optimiser is active, exclude this plugin’s scripts from combining.'
			}));
			window.console.error('Pretty Client Management: window.PCM_CRM is missing or carries no REST root.');
			return;
		}

		dom.root = root;
		dom.filters = root.querySelector('[data-role="filters"]');
		dom.body = root.querySelector('[data-role="body"]');
		dom.actions = root.querySelector('[data-role="actions"]');
		dom.drawer = root.querySelector('[data-role="drawer"]');
		dom.scrim = root.querySelector('[data-role="scrim"]');

		// The app bar is sticky (crm.css) and the filter bar stacks under it,
		// which means the filter bar's own offset has to know the app bar's
		// real rendered height — translation length, item count and viewport
		// width (crm.css wraps it onto a second row at narrow widths) all move
		// it, so a guessed CSS constant would drift the moment any of those
		// did. Measured instead, the same way initHeroArt() (theme nav.js)
		// measures a dimension CSS alone cannot know. Setup screens have no
		// app bar at all, hence the null guard.
		var appbar = root.querySelector('.pcm-crm-appbar');
		if (appbar) {
			var syncAppbarHeight = function () {
				root.style.setProperty('--pcm-appbar-height', appbar.offsetHeight + 'px');
			};
			syncAppbarHeight();
			if (window.ResizeObserver) {
				new ResizeObserver(syncAppbarHeight).observe(appbar);
			} else if (window.addEventListener) {
				window.addEventListener('resize', syncAppbarHeight);
			}
		}

		state.view = root.dataset.view;
		readHash();

		// The front-end router puts a record's id in the path itself
		// (/staff/contacts/5/), not the hash — seed it the same way an #id=N
		// link would, but only when the hash did not already say something.
		if (!window.location.hash && cfg.recordId) {
			state.recordId = cfg.recordId;
		}

		dom.scrim.addEventListener('click', function () { closeDrawer(); });
		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape') { return; }

			// The innermost thing open is what Escape closes.
			if (dom.quick && !dom.quick.hidden) { closeQuick(); return; }
			if (!dom.drawer.hidden) { closeDrawer(); return; }
			hideCard();
		});

		if (window.addEventListener) {
			window.addEventListener('popstate', onPopState);

			// Leaving the page with an edit half made asks first, as the browser
			// allows: the wording is the browser's own.
			window.addEventListener('beforeunload', function (event) {
				if (current.editing && current.dirty) {
					event.preventDefault();
					event.returnValue = '';
				}
			});
		}

		// Before anything draws, so the first chart is already on-theme.
		if (charts && charts.setTheme) { charts.setTheme(themeColors()); }

		Promise.all([api('/bootstrap'), api('/schema')]).then(function (results) {
			state.boot = results[0];
			state.schema = results[1];
			cfg.currency = state.boot.currency || '$';

			// Drained here rather than at registration: a module's filter bar
			// needs a picklist out of state.boot, and this is the first moment
			// there is one. Before render(), so a view registered from a
			// callback is available to the screen that wants it.
			while (readyQueue.length) { readyQueue.shift()(state); }

			render();
		}).catch(showError);
	}

	/**
	 * The surface a module's script registers against.
	 *
	 * This file exposed nothing for a long time and that was the right default.
	 * What is here is the minimum that lets a second script add a screen
	 * without editing this one: the four registration points, and the helpers
	 * carrying a contract a caller cannot re-derive — api()'s nonce handling,
	 * buildQuery()'s PHP-compatible nesting of filters, openDrawer()'s modal
	 * lifecycle. No DOM internals, no render functions, and state is readable
	 * rather than a way in: everything that changes it is a helper above.
	 *
	 * A module script declares this one as a dependency, so it runs while the
	 * document is still loading and its registrations land before init().
	 */
	window.PCM_CRM_App = {
		registerObject: function (slug, def) { objects[slug] = def; },
		registerView: function (name, handlers) { views[name] = handlers; },
		registerControl: function (type, spec) { controls[type] = spec; },
		registerChildTypes: function (slug, fn) {
			(childProviders[slug] = childProviders[slug] || []).push(fn);
		},
		registerRelatedColumns: function (kind, fn) { relatedRenderers[kind] = fn; },
		registerRecordTabs: function (slug, fn) {
			(recordTabs[slug] = recordTabs[slug] || []).push(fn);
		},
		registerRecordActions: function (slug, fn) {
			(recordActions[slug] = recordActions[slug] || []).push(fn);
		},
		registerDerived: function (fn) { derivers.push(fn); },
		// The screen's containers, for a module view that draws its own screen.
		dom: function () { return { root: dom.root, body: dom.body, filters: dom.filters, actions: dom.actions }; },
		ready: function (fn) { readyQueue.push(fn); },
		state: state,
		helpers: {
			el: el,
			clear: clear,
			api: api,
			buildQuery: buildQuery,
			money: money,
			formatDate: formatDate,
			formatDateTime: formatDateTime,
			today: today,
			options: options,
			ownerOptions: ownerOptions,
			openDrawer: openDrawer,
			closeDrawer: closeDrawer,
			openRecord: openRecord,
			reloadRecord: reloadRecord,
			recordLink: recordLink,
			recordUrl: recordUrl,
			screenUrl: screenUrl,
			objectIcon: objectIcon,
			quickCreate: quickCreate,
			currentRecord: function () { return current; },
			refreshView: refreshView,
			renderFilters: renderFilters,
			drillTo: drillTo,
			showError: showError,
			tile: tile,
			chartCard: chartCard,
			legend: legend,
			relatedList: relatedList,
			applyConditionalFields: applyConditionalFields,
			fieldDefinition: fieldDefinition,
			stageIsLost: stageIsLost,
			pagination: pagination,
			setCount: setCount,
			loadList: loadList,
			loadLookup: loadLookup,
			lookupLabel: lookupLabel,
			insertAtCursor: insertAtCursor,
			exportUrl: exportUrl
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
