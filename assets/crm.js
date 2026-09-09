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

	function today() {
		var now = new Date();
		return now.getFullYear() + '-' +
			String(now.getMonth() + 1).padStart(2, '0') + '-' +
			String(now.getDate()).padStart(2, '0');
	}

	function isOverdue(activity) {
		return !activity.is_completed && activity.due_date && activity.due_date < today();
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

	var state = {
		view: '',
		boot: null,
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
	function writeHash() {
		var payload = {
			s: state.query.search || undefined,
			f: Object.keys(state.query.filters).length ? state.query.filters : undefined,
			o: state.query.orderby || undefined,
			d: state.query.order !== 'DESC' ? state.query.order : undefined,
			p: state.query.page > 1 ? state.query.page : undefined,
			id: state.recordId || undefined
		};

		var pruned = {};
		Object.keys(payload).forEach(function (key) {
			if (payload[key] !== undefined) { pruned[key] = payload[key]; }
		});

		var hash = Object.keys(pruned).length ? '#' + encodeURIComponent(JSON.stringify(pruned)) : '';

		if (hash !== window.location.hash) {
			window.history.replaceState(null, '', window.location.pathname + window.location.search + hash);
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

	function ownerOptions() {
		return [{ value: '', label: 'Anyone' }].concat((state.boot.owners || []).map(function (owner) {
			return { value: owner.id, label: owner.name };
		}));
	}

	var objects = {
		accounts: {
			label: 'Account',
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
			fields: function () {
				return [
					{ fields: [
						{ key: 'name', label: 'Account name', required: true },
						{ key: 'website', label: 'Website', type: 'url' },
						{ key: 'owner_id', label: 'Owner', options: ownerOptions() },
						{ key: 'type', label: 'Type', options: options(state.boot.accountTypes, true) },
						{ key: 'industry', label: 'Industry', options: options(state.boot.industries, true) },
						{ key: 'phone', label: 'Phone' },
						{ key: 'annual_revenue', label: 'Annual revenue', type: 'number' },
						{ key: 'number_of_employees', label: 'Employees', type: 'number' }
					] },
					{ title: 'Billing address', fields: [
						{ key: 'billing_street', label: 'Street', wide: true },
						{ key: 'billing_city', label: 'City' },
						{ key: 'billing_state', label: 'State' },
						{ key: 'billing_postal_code', label: 'Postal code' },
						{ key: 'billing_country', label: 'Country' }
					] },
					{ title: 'Notes', fields: [
						{ key: 'description', label: 'Notes', type: 'textarea', wide: true }
					] }
				];
			}
		},

		contacts: {
			label: 'Contact',
			plural: 'Contacts',
			title: function (row) {
				return ((row.first_name || '') + ' ' + (row.last_name || '')).trim() || row.email || 'Contact';
			},
			// The account is what gives a person context, so it sits above
			// the name the way Salesforce puts the parent record there.
			kicker: function (row) { return row._account_name || 'No account'; },
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
				{ key: '_account_name', label: 'Account' },
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
			fields: function () {
				return [
					{ fields: [
						{ key: 'first_name', label: 'First name' },
						{ key: 'last_name', label: 'Last name', required: true },
						{ key: 'title', label: 'Title' },
						{ key: 'account_id', label: 'Account', lookup: 'accounts' },
						{ key: 'owner_id', label: 'Owner', options: ownerOptions() },
						{ key: 'lead_source', label: 'Lead source', options: options(state.boot.leadSources, true) }
					] },
					{ title: 'Contact details', fields: [
						{ key: 'email', label: 'Email', type: 'email' },
						{ key: 'phone', label: 'Phone' },
						{ key: 'mobile_phone', label: 'Mobile' },
						{ key: 'do_not_contact', label: 'Do not contact', type: 'checkbox' },
						{ key: 'do_not_contact_reason', label: 'Reason for do not contact', wide: true,
							showWhen: 'do_not_contact',
							note: 'Required. Whoever revisits this later needs to know why.' }
					] },
					{ title: 'Mailing address', fields: [
						{ key: 'mailing_street', label: 'Street', wide: true },
						{ key: 'mailing_city', label: 'City' },
						{ key: 'mailing_state', label: 'State' },
						{ key: 'mailing_postal_code', label: 'Postal code' },
						{ key: 'mailing_country', label: 'Country' }
					] },
					{ title: 'Notes', fields: [
						{ key: 'description', label: 'Notes', type: 'textarea', wide: true }
					] }
				];
			}
		},

		opportunities: {
			label: 'Opportunity',
			plural: 'Opportunities',
			title: function (row) { return row.name; },
			kicker: function (row) { return row._account_name || 'No account'; },
			highlights: function (row) {
				return [
					{ label: 'Stage', value: row.stage_name },
					{ label: 'Amount', value: money(row.amount) },
					{ label: 'Close date', value: formatDate(row.close_date) },
					{ label: 'Probability', value: row.probability + '%' },
					{ label: 'Owner', value: row._owner_name }
				];
			},
			columns: [
				{ key: 'name', label: 'Name', strong: true },
				{ key: '_account_name', label: 'Account' },
				{ key: 'stage_name', label: 'Stage', stage: true },
				{ key: 'amount', label: 'Amount', money: true },
				{ key: 'probability', label: '%', render: function (row) { return row.probability + '%'; }, num: true },
				{ key: 'close_date', label: 'Close', date: true },
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
			fields: function () {
				return [
					{ fields: [
						{ key: 'name', label: 'Opportunity name', required: true, wide: true },
						{ key: 'account_id', label: 'Account', lookup: 'accounts' },
						{ key: 'primary_contact_id', label: 'Primary contact', lookup: 'contacts' },
						{ key: 'owner_id', label: 'Owner', options: ownerOptions() },
						{ key: 'stage_name', label: 'Stage', options: options(state.boot.stages) }
					] },
					{ title: 'Forecast', fields: [
						{ key: 'amount', label: 'Amount', type: 'number' },
						{ key: 'close_date', label: 'Close date', type: 'date' },
						{ key: 'probability', label: 'Probability %', type: 'number',
							note: 'Set from the stage; override if this one is different.' },
						{ key: 'type', label: 'Type', options: options(state.boot.opportunityTypes, true) },
						{ key: 'lead_source', label: 'Lead source', options: options(state.boot.leadSources, true) }
					] },
					{ title: 'Notes', fields: [
						{ key: 'next_step', label: 'Next step', wide: true },
						{ key: 'description', label: 'Notes', type: 'textarea', wide: true }
					] }
				];
			}
		},

		activities: {
			label: 'Activity',
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
			fields: function () {
				return [
					{ fields: [
						{ key: 'subject', label: 'Subject', required: true, wide: true },
						{ key: 'owner_id', label: 'Owner', options: ownerOptions() },
						{ key: 'activity_type', label: 'Type', options: options(state.boot.activityTypes) },
						{ key: 'status', label: 'Status', options: options(state.boot.activityStatuses) },
						{ key: 'priority', label: 'Priority', options: options(state.boot.priorities) },
						{ key: 'due_date', label: 'Due date', type: 'date' }
					] },
					{ title: 'Related records', fields: [
						{ key: 'who_id', label: 'Contact', lookup: 'contacts' },
						{ key: 'what_id', label: 'Related to', lookupPair: true }
					] },
					{ title: 'Notes', fields: [
						{ key: 'description', label: 'Details', type: 'textarea', wide: true }
					] }
				];
			}
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
			query: { per_page: 500, orderby: object === 'accounts' ? 'name' : 'last_name', order: 'ASC' }
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

	function renderFilters(definition, onChange, extra) {
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
					onChange();
					render();
				}
			})
		]);

		(extra || []).forEach(function (node) { actions.appendChild(node); });
		bar.appendChild(actions);
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

		renderFilters(def.filters(), function () { loadList(object); }, [
			el('a.pcm-btn.pcm-btn-quiet', {
				href: exportUrl(object),
				text: 'Export CSV'
			})
		]);

		clear(dom.actions, el('button.pcm-btn.pcm-btn-primary', {
			type: 'button',
			text: 'New ' + def.label.toLowerCase(),
			onclick: function () { openDrawer(object, 0); }
		}));

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

	function buildTable(object, def, items) {
		var head = el('tr');

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
					loadList(object);
				}
			}, [
				column.label,
				el('span.pcm-sort', { text: sorted ? (state.query.order === 'ASC' ? '▲' : '▼') : '⇅' })
			]));
		});

		var body = el('tbody');

		items.forEach(function (row) {
			var tr = el('tr', {
				tabindex: '0',
				onclick: function () { openDrawer(object, row.id); },
				onkeydown: function (event) {
					if (event.key === 'Enter') { openDrawer(object, row.id); }
				}
			});

			def.columns.forEach(function (column) { tr.appendChild(cell(column, row)); });
			body.appendChild(tr);
		});

		return el('div.pcm-crm-table-wrap', {}, [
			el('table.pcm-crm-table', {}, [el('thead', {}, [head]), body])
		]);
	}

	function cell(column, row) {
		var value = row[column.key];

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
	   Drawer
	   --------------------------------------------------------------------- */

	function openDrawer(object, id) {
		state.recordId = id;
		writeHash();

		dom.drawer.hidden = false;
		dom.scrim.hidden = false;
		clear(dom.drawer, el('p.pcm-crm-loading', { text: 'Loading…' }));

		var needed = [loadLookup('accounts'), loadLookup('contacts'), loadLookup('opportunities')];

		Promise.all(needed).then(function () {
			if (!id) { return Promise.resolve({}); }
			return api('/' + object + '/' + id);
		}).then(function (record) {
			renderDrawer(object, record);

			if (!id) { return; }

			// Activities are a leaf: nothing hangs off one, so its section is
			// built from the record's own parents rather than fetched.
			if (object === 'activities') {
				renderRelated(object, record, {});
				return;
			}

			api('/related/' + object + '/' + id).then(function (related) {
				renderRelated(object, record, related);
			}).catch(function (error) {
				// Swallowing this would leave a record showing only a Details
				// tab, which reads as "nothing is attached to this" — a
				// different and wrong statement.
				var panels = dom.drawer.querySelector('[data-role="panels"]');

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
		}).catch(function (error) {
			clear(dom.drawer, el('div.pcm-crm-error', { text: error.message }));
		});
	}

	function closeDrawer() {
		dom.drawer.hidden = true;
		dom.scrim.hidden = true;
		state.recordId = 0;
		writeHash();
	}

	function renderDrawer(object, record) {
		var def = objects[object];
		var isNew = !record.id;
		var values = Object.assign({}, record);

		clear(dom.drawer);

		dom.drawer.appendChild(el('div.pcm-crm-modal-head', {}, [
			el('div.pcm-crm-modal-heading', {}, [
				el('p.pcm-crm-drawer-kicker', {
					text: isNew ? def.label : (def.kicker ? def.kicker(record) : def.label)
				}),
				el('h2', { text: isNew ? 'New ' + def.label.toLowerCase() : def.title(record) })
			]),
			el('button.pcm-crm-drawer-close', {
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

		top.appendChild(el('div.pcm-crm-tabs', { 'data-role': 'tabs', role: 'tablist' }));
		dom.drawer.appendChild(top);

		var scroll = el('div.pcm-crm-modal-body', {}, [
			el('div.pcm-crm-panels', { 'data-role': 'panels' }, [
				detailsPanel(object, record, values)
			])
		]);

		dom.drawer.appendChild(scroll);

		// Details is the only tab until the related lists arrive and say what
		// else this record has.
		renderTabs([{ id: 'details', label: 'Details' }]);

		scroll.scrollTop = 0;
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
		var strip = dom.drawer.querySelector('[data-role="tabs"]');
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
		var strip = dom.drawer.querySelector('[data-role="tabs"]');
		var panels = dom.drawer.querySelector('[data-role="panels"]');
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
	 * The details form, as a set of two-column field groups.
	 *
	 * Two columns rather than as many as fit: three made related fields — a
	 * street and the city under it — land in different columns, which is
	 * exactly the pairing a form like this should preserve.
	 */
	function detailsPanel(object, record, values) {
		var def = objects[object];
		var isNew = !record.id;

		var form = el('form', { onsubmit: function (e) { e.preventDefault(); } });
		var firstGrid = null;

		def.fields().forEach(function (group) {
			var grid = el('div.pcm-crm-fields');

			if (!firstGrid) { firstGrid = grid; }

			group.fields.forEach(function (field) {
				grid.appendChild(fieldControl(field, values, grid));
			});

			if (group.title) {
				form.appendChild(el('h4.pcm-crm-group-head', { text: group.title }));
			}

			form.appendChild(grid);
		});

		var status = el('span.pcm-crm-muted');

		var actions = el('div.pcm-crm-form-actions', {}, [
			el('button.pcm-btn.pcm-btn-primary', {
				type: 'button',
				text: isNew ? 'Create' : 'Save',
				onclick: function (event) { saveRecord(object, record.id, values, event.target, status); }
			}),
			el('button.pcm-btn.pcm-btn-quiet', { type: 'button', text: 'Cancel', onclick: closeDrawer }),
			status
		]);

		if (!isNew) {
			actions.appendChild(el('button.pcm-btn.pcm-btn-danger.pcm-crm-delete', {
				type: 'button',
				text: 'Delete',
				onclick: function () {
					if (!window.confirm('Delete this ' + def.label.toLowerCase() + '? It can be restored from the database if needed.')) { return; }

					api('/' + object + '/' + record.id, { method: 'DELETE' }).then(function () {
						closeDrawer();
						refreshView();
					}).catch(showError);
				}
			}));
		}

		form.appendChild(actions);

		return el('div.pcm-crm-panel', { dataset: { tab: 'details' } }, [form]);
	}

	/**
	 * The Salesforce-style highlights strip: the handful of fields you need
	 * before deciding whether to read the rest of the record.
	 */
	function highlightPanel(items) {
		var panel = el('div.pcm-crm-highlights');

		items.forEach(function (item) {
			if (!item.value || item.value === '—') { return; }

			panel.appendChild(el('div.pcm-crm-highlight', {}, [
				el('span.pcm-crm-highlight-label', { text: item.label }),
				item.href
					? el('a.pcm-crm-highlight-value', { href: item.href, text: item.value })
					: el('span.pcm-crm-highlight-value', { text: item.value })
			]));
		});

		return panel;
	}

	function fieldControl(field, values, grid) {
		var id = 'pcm-crm-field-' + field.key;

		if (field.lookupPair) {
			// what_id is polymorphic: the object it points at has to be chosen
			// alongside the record, so the two controls move together.
			return relatedToControl(field, values);
		}

		function onInput(event) {
			values[field.key] = event.target.value;
		}

		// A checkbox is a control with a label beside it, not a labelled box
		// in a column — laid out like the text fields it collapses to a line.
		if (field.type === 'checkbox') {
			var box = el('input', {
				id: id,
				type: 'checkbox',
				checked: !!Number(values[field.key]),
				onchange: function (event) {
					values[field.key] = event.target.checked ? 1 : 0;
					if (grid) { applyConditionalFields(grid, values); }
				}
			});

			return el('div.pcm-crm-field.pcm-crm-field-check', { dataset: { field: field.key } }, [
				el('label.pcm-crm-check', { for: id }, [box, el('span', { text: field.label })])
			]);
		}

		var wrap = el('div.pcm-crm-field' + (field.wide ? '.pcm-crm-field-wide' : ''), {
			dataset: { field: field.key }
		});
		var control;

		if (field.options || field.lookup) {
			control = el('select', { id: id, onchange: onInput });

			var list = field.lookup
				? [{ value: '', label: '—' }].concat(lookups[field.lookup] || [])
				: field.options;

			list.forEach(function (option) {
				control.appendChild(el('option', {
					value: option.value,
					text: option.label,
					selected: String(values[field.key] || '') === String(option.value)
				}));
			});
		} else if (field.type === 'textarea') {
			control = el('textarea', { id: id, oninput: onInput, text: values[field.key] || '' });
		} else {
			control = el('input', {
				id: id,
				type: field.type || 'text',
				value: values[field.key] === null || values[field.key] === undefined ? '' : values[field.key],
				required: field.required,
				oninput: onInput
			});
		}

		wrap.appendChild(el('label', { for: id, text: field.label + (field.required ? ' *' : '') }));
		wrap.appendChild(control);

		if (field.note) { wrap.appendChild(el('span.pcm-crm-field-note', { text: field.note })); }

		// Fields that only apply when another is set start hidden, and are
		// revealed by the checkbox that makes them relevant.
		if (field.showWhen) {
			wrap.dataset.showWhen = field.showWhen;
			wrap.hidden = !Number(values[field.showWhen]);
		}

		return wrap;
	}

	/**
	 * Show or hide the fields that depend on another field's value.
	 *
	 * Driven off data attributes rather than a lookup table, so adding a
	 * conditional field is one property on its definition.
	 */
	function applyConditionalFields(grid, values) {
		grid.querySelectorAll('[data-show-when]').forEach(function (node) {
			node.hidden = !Number(values[node.dataset.showWhen]);
		});
	}

	function relatedToControl(field, values) {
		var wrap = el('div.pcm-crm-field');
		var row = el('div', { style: 'display:flex;gap:6px' });

		var typeSelect = el('select', {
			style: 'flex:0 0 42%',
			onchange: function (event) {
				values.what_type = event.target.value;
				values.what_id = '';
				rebuild();
			}
		});

		[{ value: '', label: '—' }, { value: 'account', label: 'Account' }, { value: 'opportunity', label: 'Opportunity' }]
			.forEach(function (option) {
				typeSelect.appendChild(el('option', {
					value: option.value, text: option.label,
					selected: (values.what_type || '') === option.value
				}));
			});

		var idSelect = el('select', { style: 'flex:1' });

		function rebuild() {
			clear(idSelect);
			idSelect.appendChild(el('option', { value: '', text: '—' }));

			var source = values.what_type === 'opportunity' ? 'opportunities' : 'accounts';

			if (!values.what_type) { return; }

			loadLookup(source).then(function (list) {
				list.forEach(function (option) {
					idSelect.appendChild(el('option', {
						value: option.value, text: option.label,
						selected: String(values.what_id || '') === String(option.value)
					}));
				});
			});
		}

		idSelect.addEventListener('change', function (event) { values.what_id = event.target.value; });
		rebuild();

		row.appendChild(typeSelect);
		row.appendChild(idSelect);

		wrap.appendChild(el('label', { text: field.label }));
		wrap.appendChild(row);

		return wrap;
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

			// A new account or contact has to reach the pickers immediately,
			// or the next record cannot be linked to it without a reload.
			lookups = {};

			if (!id) {
				closeDrawer();
			} else {
				renderDrawer(object, record);
			}

			refreshView();
		}).catch(function (error) {
			status.textContent = error.message;
			button.disabled = false;
		});
	}

	function renderRelated(object, record, related) {
		var panels = dom.drawer.querySelector('[data-role="panels"]');
		if (!panels) { return; }

		var tabs = [{ id: 'details', label: 'Details' }];

		// Anything already added by a previous render goes, so reopening a
		// record after a save does not stack two copies of each list.
		panels.querySelectorAll('[data-tab]:not([data-tab="details"])').forEach(function (node) {
			node.remove();
		});

		function addTab(id, label, rows, list) {
			tabs.push({ id: id, label: label, count: rows.length });
			panels.appendChild(el('div.pcm-crm-panel', { dataset: { tab: id }, hidden: true }, [list]));
		}

		if (related.contacts && related.contacts.length) {
			addTab('contacts', 'Contacts', related.contacts, relatedList({
				rows: related.contacts,
				object: 'contacts',
				columns: function (row) {
					return [
						{ text: objects.contacts.title(row), strong: true },
						{ text: row.title || '—' },
						{ text: row.email || '—' }
					];
				}
			}));
		}

		if (related.opportunities && related.opportunities.length) {
			addTab('opportunities', 'Opportunities', related.opportunities, relatedList({
				rows: related.opportunities,
				object: 'opportunities',
				columns: function (row) {
					return [
						{ text: row.name, strong: true },
						{ badge: row.stage_name, tone: row.is_won ? 'won' : (row.is_closed ? 'lost' : 'open') },
						{ text: formatDate(row.close_date) },
						{ text: money(row.amount), num: true }
					];
				}
			}));
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
					open: function (row) { openDrawer(row.object, row.id); }
				}));
			}
		} else {
			var activities = related.activities || [];

			// Always present, even at zero: an empty timeline is exactly when
			// the quick-log box is most wanted.
			addTab('activities', 'Activities', activities, relatedList({
				rows: activities,
				object: 'activities',
				before: quickLog(object, record),
				columns: function (row) {
					return [
						{ text: row.subject || row.activity_type, strong: true },
						{ badge: row.activity_type },
						{ text: row.status || '—' },
						{ text: formatDate(row.activity_date) }
					];
				}
			}));
		}

		renderTabs(tabs);
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
				label: record._contact_name || lookupLabel('contacts', record.who_id) || 'Contact #' + record.who_id
			});
		}

		if (record.what_id && record.what_type) {
			var object = record.what_type === 'opportunity' ? 'opportunities' : 'accounts';

			rows.push({
				id: record.what_id,
				object: object,
				kind: record.what_type === 'opportunity' ? 'Opportunity' : 'Account',
				label: lookupLabel(object, record.what_id) || 'Record #' + record.what_id
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

		if (config.before) { block.appendChild(config.before); }

		if (!config.rows.length) {
			block.appendChild(el('p.pcm-crm-related-empty', { text: 'None yet.' }));
			return block;
		}

		var open = config.open || function (row) { openDrawer(config.object, row.id); };

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

	/**
	 * Log a call, email or note without leaving the record.
	 *
	 * The single most frequent write in a CRM, so it is one field and a button
	 * rather than a trip through a new Activity form.
	 */
	function quickLog(object, record) {
		var input = el('input', { type: 'text', placeholder: 'Log a call, email or note…', style: 'flex:1' });
		var type = el('select', { style: 'flex:0 0 130px' });

		(state.boot.activityTypes || []).forEach(function (name) {
			if (name === 'Web Form') { return; }
			type.appendChild(el('option', { value: name, text: name, selected: name === 'Call' }));
		});

		function log() {
			if (!input.value.trim()) { return; }

			var payload = {
				subject: input.value.trim(),
				activity_type: type.value,
				status: 'Completed',
				activity_date: new Date().toISOString().slice(0, 19).replace('T', ' '),
				who_id: object === 'contacts' ? record.id : 0,
				what_id: object === 'contacts' ? (record.account_id || 0) : record.id,
				what_type: object === 'contacts' ? (record.account_id ? 'account' : '') : object.replace(/s$/, '')
			};

			api('/activities', { method: 'POST', body: payload }).then(function () {
				input.value = '';
				api('/related/' + object + '/' + record.id).then(function (related) {
					renderRelated(object, record, related);
				});
			}).catch(showError);
		}

		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') { event.preventDefault(); log(); }
		});

		return el('div.pcm-crm-quicklog', {}, [
			input,
			type,
			el('button.pcm-btn', { type: 'button', text: 'Log', onclick: log })
		]);
	}

	/* ---------------------------------------------------------------------
	   Pipeline
	   --------------------------------------------------------------------- */

	function renderPipeline() {
		renderFilters(objects.opportunities.filters().filter(function (filter) {
			// The board is already organised by stage, and a status filter on
			// a board of stages would just empty columns.
			return filter.key !== 'stage_name' && filter.key !== 'is_closed';
		}), loadPipeline);

		clear(dom.actions, el('button.pcm-btn.pcm-btn-primary', {
			type: 'button',
			text: 'New opportunity',
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

			// The board is reloaded rather than patched: moving a card changes
			// two column totals and the deal's probability, and re-fetching is
			// both simpler and guaranteed to match what was stored.
			api('/opportunities/' + id, {
				method: 'PUT',
				body: { stage_name: column.stage }
			}).then(loadPipeline).catch(showError);
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
				el('span.pcm-crm-muted', { text: 'Closes ' + formatDate(item.close_date) })
			])
		]);

		card.addEventListener('dragstart', function (event) {
			event.dataTransfer.setData('text/plain', String(item.id));
			event.dataTransfer.effectAllowed = 'move';
			card.classList.add('is-dragging');
		});
		card.addEventListener('dragend', function () { card.classList.remove('is-dragging'); });

		return card;
	}

	/* ---------------------------------------------------------------------
	   Dashboard
	   --------------------------------------------------------------------- */

	function renderDashboard() {
		renderFilters([
			{ key: 'owner_id', label: 'Owner', options: ownerOptions() },
			{ key: 'created_date', label: 'Created', range: 'date' }
		], loadDashboard);

		loadDashboard();
	}

	function loadDashboard() {
		writeHash();

		api('/dashboard', { query: state.query }).then(function (data) {
			var tiles = data.tiles;

			setCount('');

			var grid = el('div.pcm-crm-tiles', {}, [
				tile('Open pipeline', money(tiles.openValue), tiles.openCount + ' open ' + (tiles.openCount === 1 ? 'deal' : 'deals')),
				tile('Weighted', money(tiles.weightedValue), 'Discounted by stage probability'),
				tile('Won', money(tiles.wonValue), tiles.wonCount + ' closed won'),
				tile('Win rate', tiles.winRate + '%', 'Of everything closed'),
				tile('Accounts', String(tiles.accountCount), tiles.contactCount + ' contacts'),
				tile('Overdue', String(tiles.overdueCount), 'Activities past due', tiles.overdueCount > 0)
			]);

			var charts_ = el('div.pcm-crm-charts', {}, [
				chartCard('Pipeline by stage', charts.funnel(data.charts.pipelineByStage)),
				chartCard('Created and won by month',
					charts.columns(data.charts.byMonth, [{ key: 'created' }, { key: 'won' }]),
					legend([{ label: 'Created' }, { label: 'Won' }])),
				chartCard('Value by lead source', charts.bar(data.charts.byLeadSource)),
				chartCard('Activity by type', charts.donut(data.charts.activityByType, {
					metric: 'count',
					centerLabel: 'activities'
				}), legend(data.charts.activityByType.map(function (row) {
					return { label: (row.value || 'Unspecified') + ' (' + row.count + ')' };
				})))
			]);

			clear(dom.body);
			dom.body.appendChild(grid);
			dom.body.appendChild(charts_);
		}).catch(showError);
	}

	function tile(label, value, note, alert) {
		return el('div.pcm-crm-tile' + (alert ? '.pcm-crm-tile-alert' : ''), {}, [
			el('p.pcm-crm-tile-label', { text: label }),
			el('div.pcm-crm-tile-value', { text: value }),
			note ? el('div.pcm-crm-tile-note', { text: note }) : null
		]);
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

	/* ---------------------------------------------------------------------
	   Reports
	   --------------------------------------------------------------------- */

	var report = { object: 'opportunities', groupBy: 'stage_name' };

	function renderReports() {
		var groupOptions = {
			accounts: ['type', 'industry', 'billing_state', 'owner_id'],
			contacts: ['lead_source', 'account_id', 'title', 'owner_id'],
			opportunities: ['stage_name', 'type', 'lead_source', 'forecast_category', 'owner_id'],
			activities: ['activity_type', 'status', 'priority', 'owner_id']
		};

		function build() {
			var objectSelect = el('select', {
				onchange: function (event) {
					report.object = event.target.value;
					report.groupBy = groupOptions[report.object][0];
					state.query.filters = {};
					state.query.page = 1;
					renderReports();
				}
			});

			Object.keys(objects).forEach(function (key) {
				objectSelect.appendChild(el('option', {
					value: key, text: objects[key].plural, selected: report.object === key
				}));
			});

			var groupSelect = el('select', {
				onchange: function (event) { report.groupBy = event.target.value; loadReport(); }
			});

			groupOptions[report.object].forEach(function (key) {
				groupSelect.appendChild(el('option', {
					value: key,
					text: key.replace(/_id$/, '').replace(/_/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); }),
					selected: report.groupBy === key
				}));
			});

			renderFilters(objects[report.object].filters(), loadReport, [
				el('a.pcm-btn.pcm-btn-quiet', { href: exportUrl(report.object), text: 'Export CSV' })
			]);

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

			if (data.groups.length) {
				var rows = data.groups.map(function (row) {
					return {
						value: labelForGroup(report.groupBy, row.value),
						count: row.count,
						total: row.total
					};
				});

				dom.body.appendChild(el('div.pcm-crm-card', {}, [
					el('h3', { text: 'Grouped by ' + report.groupBy.replace(/_/g, ' ') }),
					el('div.pcm-crm-table-wrap', { style: 'border:0' }, [
						el('table.pcm-crm-table', {}, [
							el('thead', {}, [el('tr', {}, [
								el('th', { text: 'Group' }),
								el('th', { text: 'Records' }),
								el('th', { text: 'Value' })
							])]),
							el('tbody', {}, rows.map(function (row) {
								return el('tr', { style: 'cursor:default' }, [
									el('td.pcm-crm-strong', { text: row.value || '—' }),
									el('td.pcm-crm-num', { text: String(row.count) }),
									el('td.pcm-crm-num', { text: row.total ? money(row.total) : '—' })
								]);
							}))
						])
					]),
					charts.bar(rows)
				]));
			}

			if (data.rows.length) {
				dom.body.appendChild(buildTable(report.object, def, data.rows));
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

	/* ---------------------------------------------------------------------
	   Boot
	   --------------------------------------------------------------------- */

	function refreshView() {
		if (state.view === 'pipeline') { loadPipeline(); }
		else if (state.view === 'dashboard') { loadDashboard(); }
		else if (state.view === 'reports') { loadReport(); }
		else if (objects[state.view]) { loadList(state.view); }
	}

	function render() {
		if (state.view === 'dashboard') { renderDashboard(); }
		else if (state.view === 'pipeline') { renderPipeline(); }
		else if (state.view === 'reports') { renderReports(); }
		else if (objects[state.view]) { renderList(state.view); }
		else { clear(dom.body, el('p', { text: 'Unknown screen.' })); }

		// A record id in the hash — a link from the notification email, or a
		// reloaded page — opens straight onto that record.
		if (state.recordId && objects[state.view]) {
			openDrawer(state.view, state.recordId);
		}
	}

	function init() {
		var root = document.querySelector('.pcm-crm[data-view]');
		if (!root || !cfg.root) { return; }

		dom.root = root;
		dom.filters = root.querySelector('[data-role="filters"]');
		dom.body = root.querySelector('[data-role="body"]');
		dom.actions = root.querySelector('[data-role="actions"]');
		dom.drawer = root.querySelector('[data-role="drawer"]');
		dom.scrim = root.querySelector('[data-role="scrim"]');

		state.view = root.dataset.view;
		readHash();

		dom.scrim.addEventListener('click', closeDrawer);
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !dom.drawer.hidden) { closeDrawer(); }
		});

		api('/bootstrap').then(function (boot) {
			state.boot = boot;
			cfg.currency = boot.currency || '$';
			render();
		}).catch(showError);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
