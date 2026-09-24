/**
 * The client portal.
 *
 * A small, standalone vanilla-JS app against the /portal REST routes — no
 * framework, and deliberately no dependency on crm.js/pm.js: this is a
 * separate small app for a separate audience (a client, not staff), sharing
 * only the visual language via portal.css, which restates the CRM's palette
 * the way pcm_crm_email_wrapper() does for email rather than importing the
 * admin-only crm.css.
 */
(function (window, document) {
	'use strict';

	var cfg = window.PCM_CRM_PORTAL || {};
	var root = document.getElementById('pcm-portal-root');
	if (!root) { return; }

	var state = { me: null, project: 0, view: 'summary', ticket: 0, summary: null };

	/* ---------------------------------------------------------------------
	   DOM + API helpers — the same small shape crm.js uses, kept separate on
	   purpose so this file has no dependency on that one.
	   --------------------------------------------------------------------- */

	function el(spec, attrs, children) {
		var parts = spec.split('.');
		var node = document.createElement(parts.shift() || 'div');
		if (parts.length) { node.className = parts.join(' '); }

		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === null || value === undefined || value === false) { return; }
			if (key === 'text') { node.textContent = value; }
			else if (key.indexOf('on') === 0 && typeof value === 'function') { node.addEventListener(key.slice(2).toLowerCase(), value); }
			else if (value === true) { node.setAttribute(key, ''); }
			else { node.setAttribute(key, value); }
		});

		(children || []).forEach(function (child) {
			if (child === null || child === undefined || child === false) { return; }
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});

		return node;
	}

	function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }

	function api(path, options) {
		options = options || {};
		var url = cfg.root + path;

		if (options.query) {
			var pairs = Object.keys(options.query)
				.filter(function (key) { return options.query[key] !== undefined && options.query[key] !== ''; })
				.map(function (key) { return encodeURIComponent(key) + '=' + encodeURIComponent(options.query[key]); });
			if (pairs.length) { url += '?' + pairs.join('&'); }
		}

		return window.fetch(url, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: options.body ? JSON.stringify(options.body) : undefined
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) { throw new Error((data && data.message) || 'Something went wrong.'); }
				return data;
			});
		});
	}

	/**
	 * Upload one file, multipart — api()'s JSON body/Content-Type does not fit
	 * a raw file, and the browser has to set its own boundary'd Content-Type
	 * for FormData, so this is a separate small helper rather than an option
	 * on api().
	 */
	function apiUpload(path, file, fields) {
		var body = new window.FormData();
		body.append('file', file);

		Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });

		return window.fetch(cfg.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce },
			body: body
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) { throw new Error((data && data.message) || 'Something went wrong.'); }
				return data;
			});
		});
	}

	/**
	 * Every selected file, uploaded one after another rather than in parallel
	 * — a burst of simultaneous multipart POSTs is exactly the kind of thing a
	 * host's request-rate guard notices.
	 */
	function uploadAll(path, files) {
		return files.reduce(function (chain, file) {
			return chain.then(function () { return apiUpload(path, file); });
		}, Promise.resolve());
	}

	/**
	 * A stored date as a Date in the reader's own timezone.
	 *
	 * Built from the parts rather than handed to the Date constructor: a
	 * date-only string is parsed as UTC midnight, which renders as the day
	 * before for anyone west of Greenwich — a milestone due the 1st reading
	 * as the 31st is exactly the kind of quiet wrongness a client notices and
	 * cannot explain. MySQL's zero date is a blank, not a date in year 0.
	 */
	function parseDate(value) {
		if (!value) { return null; }

		var parts = String(value).split(/[^0-9]+/).filter(function (p) { return p !== ''; }).map(Number);
		if (parts.length < 3 || !parts[0] || !parts[1]) { return null; }

		var d = new Date(parts[0], parts[1] - 1, parts[2], parts[3] || 0, parts[4] || 0, parts[5] || 0);
		return isNaN(d.getTime()) ? null : d;
	}

	function formatDate(value) {
		var d = parseDate(value);
		if (!value) { return '—'; }
		return d ? d.toLocaleDateString() : String(value);
	}

	/**
	 * A Date we already hold, written out. Not formatDate(d.toISOString()) —
	 * toISOString() is UTC, which moves a local midnight back a day for
	 * anyone east of Greenwich and would mislabel the chart's own scale.
	 */
	function formatDay(date) { return date ? date.toLocaleDateString() : '—'; }

	/** Today at local midnight — the granularity every date on this screen has. */
	function today() {
		var now = new Date();
		return new Date(now.getFullYear(), now.getMonth(), now.getDate());
	}

	var DAY_MS = 86400000;

	function daysBetween(from, to) { return Math.round((to.getTime() - from.getTime()) / DAY_MS); }

	function hours(n) { return (Math.round(Number(n || 0) * 100) / 100) + 'h'; }

	function days(n) { return Math.round(Number(n || 0)) + (1 === Math.round(Number(n || 0)) ? ' day' : ' days'); }

	/**
	 * The share one number is of another, as a whole percent — uncapped on
	 * purpose. The bar is clamped to its track, but the number beside it says
	 * 105%, because "98.5h of 94h · 100%" would read as finishing exactly on
	 * budget when the truth is the opposite.
	 */
	function percent(used, available) {
		if (!(Number(available) > 0)) { return null; }
		return Math.round((Number(used) / Number(available)) * 100);
	}

	function showError(container, error) {
		clear(container);
		container.appendChild(el('div.pcm-portal-error', { text: error.message || 'Something went wrong.' }));
	}

	/* ---------------------------------------------------------------------
	   Shell: header, project switcher, nav.
	   --------------------------------------------------------------------- */

	/**
	 * "Account Name — Project Name", without saying the account twice.
	 *
	 * Some projects are named "Account — Project" already (a house naming
	 * habit, not something this code enforces), and appending the account
	 * again on top of that read as "Account — Project — Account". Prepending
	 * it only when the name does not already start with it keeps both
	 * conventions readable.
	 */
	function projectLabel(project) {
		var name = project.name || '';
		var account = project.account_name || '';

		if (!account || 0 === name.toLowerCase().indexOf(account.toLowerCase())) { return name; }

		return account + ' — ' + name;
	}

	/**
	 * The account behind whichever project is currently selected — the portal
	 * kicker names the client's own organization rather than the generic
	 * "Client Portal" label, so it has to track the switcher.
	 */
	function currentProject() {
		var match = null;
		state.me.projects.forEach(function (project) {
			if (project.id === state.project) { match = project; }
		});
		return match;
	}

	function render() {
		clear(root);

		var shell = el('div.pcm-portal-shell');
		var header = el('div.pcm-portal-header');
		var body = el('div.pcm-portal-body');
		var current = currentProject();

		header.appendChild(el('div.pcm-portal-heading', {}, [
			el('h1', { text: state.me.contact_name || 'Your Portal' })
		]));
		header.appendChild(el('p.pcm-portal-kicker', { text: (current && current.account_name) || 'Client Portal' }));

		var projectRow = el('div.pcm-portal-projectrow');

		if (state.me.projects.length > 1) {
			var switcher = el('select.pcm-portal-switcher', {
				'aria-label': 'Project',
				onchange: function (event) {
					state.project = Number(event.target.value);
					state.view = 'summary';
					// The cached summary belongs to the project being left —
					// the Milestones chart reads its dates out of it, and a
					// stale one would draw the new project's milestones
					// against the old one's window.
					state.summary = null;
					render();
				}
			});

			state.me.projects.forEach(function (project) {
				switcher.appendChild(el('option', {
					value: project.id,
					text: projectLabel(project),
					selected: project.id === state.project
				}));
			});

			projectRow.appendChild(switcher);
			header.appendChild(projectRow);
		} else if (state.me.projects.length === 1) {
			projectRow.appendChild(el('p.pcm-portal-project-name', { text: state.me.projects[0].name }));
			header.appendChild(projectRow);
		}

		var nav = renderNav(body);

		shell.appendChild(header);
		shell.appendChild(nav);
		shell.appendChild(body);
		root.appendChild(shell);

		renderBody(body);
	}

	/**
	 * A tab's icon as inline SVG — outline glyphs (stroke, no fill) borrowed
	 * from the same common icon set crm.js draws its dashicons from
	 * conceptually, but hand-inlined here rather than pulled in as a
	 * dependency: portal.js is deliberately standalone, and dashicons itself
	 * is a wp-admin asset this front-end page has no reason to load. Built
	 * with innerHTML rather than el()/createElement, since createElement
	 * cannot make a real, renderable <svg> without the SVG namespace —
	 * innerHTML's parser handles that switch on its own.
	 *
	 * stroke="currentColor" is the whole trick: the glyph follows the tab's
	 * own text color, active or not, with no separate color rule to keep in
	 * sync with .pcm-portal-tab.is-active.
	 */
	function tabIcon(name) {
		var paths = {
			summary: '<path d="m3 9.5 9-7 9 7"/><path d="M9 21.5v-9h6v9"/><path d="M5 10.5v9a1 1 0 0 0 1 1h3"/><path d="M19 10.5v9a1 1 0 0 1-1 1h-3"/>',
			milestones: '<path d="M5 21V4"/><path d="M5 5s1-1 4-1 5 2 8 2 4-1 4-1v9s-1 1-4 1-5-2-8-2-4 1-4 1"/>',
			roles: '<circle cx="9" cy="8" r="3.5"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9" r="2.5"/><path d="M15 14a5 5 0 0 1 5.5 5"/>',
			raid: '<path d="M12 3 2 20h20z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
			documents: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M13 3v4a1 1 0 0 0 1 1h5"/><path d="M8.5 13h7"/><path d="M8.5 17h7"/>',
			tickets: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="m5.6 5.6 3.3 3.3M18.4 5.6l-3.3 3.3M18.4 18.4l-3.3-3.3M5.6 18.4l3.3-3.3"/>'
		};

		var span = el('span.pcm-portal-tab-icon', { 'aria-hidden': 'true' });
		span.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" '
			+ 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (paths[name] || '') + '</svg>';

		return span;
	}

	/**
	 * The tab strip, kept in its own function so a tab click can update both
	 * the active button and the body from one place — building it once inside
	 * render() and never touching it again left the Summary tab looking
	 * "selected" no matter which tab was actually showing.
	 */
	function renderNav(body) {
		var nav = el('nav.pcm-portal-nav');

		[
			{ id: 'summary', label: 'Summary' },
			{ id: 'milestones', label: 'Milestones' },
			{ id: 'roles', label: 'Project Team' },
			{ id: 'raid', label: 'RAID Log' },
			{ id: 'documents', label: 'Documents' },
			{ id: 'tickets', label: 'Help Tickets' }
		].forEach(function (tab) {
			nav.appendChild(el('button.pcm-portal-tab' + (state.view === tab.id ? '.is-active' : ''), {
				type: 'button',
				'data-tab': tab.id,
				onclick: function () {
					state.view = tab.id;
					state.ticket = 0;

					Array.prototype.forEach.call(nav.querySelectorAll('.pcm-portal-tab'), function (btn) {
						btn.classList.toggle('is-active', btn.getAttribute('data-tab') === tab.id);
					});

					renderBody(body);
				}
			}, [tabIcon(tab.id), el('span', { text: tab.label })]));
		});

		return nav;
	}

	function renderBody(body) {
		clear(body);
		body.appendChild(el('p.pcm-portal-loading', { text: 'Loading…' }));

		if (state.view === 'summary') { return loadSummary(body); }
		if (state.view === 'milestones') { return loadMilestones(body); }
		if (state.view === 'roles') { return loadRoles(body); }
		if (state.view === 'raid') { return loadRaid(body); }
		if (state.view === 'documents') { return loadDocuments(body); }
		if (state.view === 'tickets') { return state.ticket ? loadTicket(body) : loadTickets(body); }
	}

	/* ---------------------------------------------------------------------
	   Summary — one card: what this project is, then how far through. Hours
	   only, never a rate or a dollar figure.
	   --------------------------------------------------------------------- */

	/**
	 * One labelled bar. `format` is how the two numbers are written (hours
	 * here, days for the timeline).
	 *
	 * The percentage rides after the figures because that is the number that
	 * actually answers "how are we doing" — "12.5h of 16.48h" makes the reader
	 * do the division. The fill is clamped to the track while the percentage
	 * is not, so an overrun reads as a full red bar *and* the honest 105%.
	 * Nothing below the bar states that in words — the bar is the answer.
	 */
	function meter(label, used, available, format) {
		format = format || hours;

		var pct = percent(used, available);
		var fill = null === pct ? 0 : Math.min(100, Math.max(0, pct));
		var over = null !== pct && pct > 100;

		return el('div.pcm-portal-meter' + (over ? '.is-over' : ''), {}, [
			el('div.pcm-portal-meter-head', {}, [
				el('strong', { text: label }),
				el('span.pcm-portal-meter-figures', {
					text: format(used) + ' of ' + format(available) + (null === pct ? '' : ' · ' + pct + '%')
				})
			]),
			el('div.pcm-portal-meter-track', {}, [
				el('span.pcm-portal-meter-fill', { style: 'width:' + fill + '%' })
			])
		]);
	}

	/**
	 * How far through the planned window today is.
	 *
	 * Inclusive of both end days, so a one-day project is one day long rather
	 * than zero — and returns null rather than a bar when either date is
	 * missing, since "0 of 0 days" says nothing.
	 */
	function timelineMeter(project) {
		var start = parseDate(project.start_date);
		var end = parseDate(project.end_date);

		if (!start || !end || end < start) { return null; }

		var total = daysBetween(start, end) + 1;
		var elapsed = Math.min(total, Math.max(0, daysBetween(start, today()) + 1));

		return meter('Timeline', elapsed, total, days);
	}

	/**
	 * The one Summary card: identity, then the bars, then what's next — in
	 * that order because each answers a narrower question than the last. The
	 * name is the card's own heading rather than another row in the list — it
	 * is the one thing on the screen the client already knows by heart.
	 */
	function summaryCard(summary) {
		var project = summary.project || {};
		var card = el('div.pcm-portal-card.pcm-portal-summary');

		card.appendChild(el('h2.pcm-portal-summary-name', { text: project.name || (currentProject() && currentProject().name) || 'Project' }));

		// The same label/value pairs as a RAID item, so the two tabs read alike.
		var facts = el('div.pcm-portal-raid-fields.pcm-portal-facts');

		function fact(label, value) {
			var pair = raidField(label, value);
			if (pair) { facts.appendChild(pair); }
		}

		fact('Project Type', project.type_label || summary.type_label);
		fact('Stage', project.stage_name);
		fact('Start Date', project.start_date ? formatDate(project.start_date) : '');
		fact('Planned End Date', project.end_date ? formatDate(project.end_date) : '');

		card.appendChild(facts);

		// Timeline first, then the period (a retainer's short-term view), then
		// the whole-project budget — narrowest window to widest.
		var meters = el('div.pcm-portal-meters');
		var timeline = timelineMeter(project);

		if (timeline) { meters.appendChild(timeline); }
		if (summary.current_period) {
			// The label follows the retainer's own cadence (portal-rest.php's
			// pcm_crm_portal_period_label()) — "This Month" would be wrong on
			// a quarterly retainer, not just vague.
			meters.appendChild(meter(summary.current_period.label || 'Hours Used This Period', summary.current_period.used, summary.current_period.available));
		}
		if (summary.budget_hours) { meters.appendChild(meter('Budget', summary.logged_hours, summary.budget_hours)); }

		if (meters.childNodes.length) { card.appendChild(meters); }

		if (summary.next_milestone) {
			var next = el('div.pcm-portal-raid-fields.pcm-portal-facts');
			next.appendChild(raidField('Next Milestone', summary.next_milestone.name + (summary.next_milestone.due_date ? ' · ' + formatDate(summary.next_milestone.due_date) : '')));
			card.appendChild(next);
		}

		return card;
	}

	function loadSummary(body) {
		api('/portal/summary', { query: { project_id: state.project } }).then(function (summary) {
			clear(body);
			state.summary = summary;
			body.appendChild(summaryCard(summary));
		}).catch(function (error) { showError(body, error); });
	}

	/* ---------------------------------------------------------------------
	   Milestones — read only, drawn as a Gantt.
	   --------------------------------------------------------------------- */

	/**
	 * The window the chart spans.
	 *
	 * Wide enough to hold the project's planned dates *and* every dated
	 * milestone, because the two disagree in practice: a milestone slips past
	 * the planned end, or an early one predates a start date entered later.
	 * Clipping either would draw a chart that silently omits the thing the
	 * client came to look at. A degenerate window (one milestone, no project
	 * dates) is padded, since a zero-width scale puts every bar at 0%.
	 */
	function ganttRange(milestones, project) {
		var dates = [];

		[project.start_date, project.end_date].forEach(function (value) {
			var d = parseDate(value);
			if (d) { dates.push(d); }
		});

		milestones.forEach(function (row) {
			var d = parseDate(row.due_date);
			if (d) { dates.push(d); }
		});

		if (!dates.length) { return null; }

		var min = new Date(Math.min.apply(null, dates));
		var max = new Date(Math.max.apply(null, dates));

		if (daysBetween(min, max) < 14) {
			min = new Date(min.getTime() - 7 * DAY_MS);
			max = new Date(max.getTime() + 7 * DAY_MS);
		}

		return { start: min, end: max, span: Math.max(1, daysBetween(min, max)) };
	}

	function ganttOffset(range, date) {
		return Math.min(100, Math.max(0, (daysBetween(range.start, date) / range.span) * 100));
	}

	/**
	 * The month gridlines across the top, thinned to quarters once there are
	 * more than eighteen of them — a year-and-a-half engagement labelled every
	 * month is unreadable at phone width, and the ticks stop being a scale and
	 * become texture.
	 */
	function ganttScale(range) {
		var scale = el('div.pcm-portal-gantt-scale');
		var months = [];
		var cursor = new Date(range.start.getFullYear(), range.start.getMonth(), 1);

		while (cursor <= range.end) {
			months.push(new Date(cursor.getTime()));
			cursor = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1);
		}

		var step = months.length > 18 ? 3 : 1;

		months.forEach(function (month, index) {
			if (index % step !== 0 || month < range.start) { return; }

			scale.appendChild(el('span.pcm-portal-gantt-tick', {
				style: 'left:' + ganttOffset(range, month) + '%',
				text: month.toLocaleDateString(undefined, { month: 'short' })
					+ (0 === month.getMonth() || 0 === index ? ' ’' + String(month.getFullYear()).slice(2) : '')
			}));
		});

		return scale;
	}

	/**
	 * A milestone's own class: hit, still to come, or past its date and not
	 * hit. Overdue is worth its own colour — it is the one state on this chart
	 * a client would want to ask about.
	 */
	function milestoneClass(row) { return 'is-' + milestoneState(row).toLowerCase().replace(/[^a-z]+/g, '-'); }

	function milestoneState(row) {
		if ('Done' === row.status) { return 'Done'; }

		var due = parseDate(row.due_date);

		return due && due < today() ? 'Past due' : 'Planned';
	}

	/**
	 * One row of the chart: a bar running from where the last milestone landed
	 * to where this one is due, plus a marker on the date itself.
	 *
	 * A milestone is a point in time, not a span — it has a due date and no
	 * start (see model-project-milestone.php). Drawing the run-up to it as the
	 * bar is what turns a row of dots into something that reads as a schedule:
	 * the bar is the stretch of work this milestone closes.
	 */
	function ganttRow(row, range, from) {
		var due = parseDate(row.due_date);
		var left = ganttOffset(range, from);
		var right = ganttOffset(range, due);
		var cls = milestoneClass(row);

		var track = el('div.pcm-portal-gantt-track', {}, [
			el('span.pcm-portal-gantt-bar.' + cls, {
				style: 'left:' + left + '%;width:' + Math.max(0, right - left) + '%',
				title: row.name + ' · due ' + formatDate(row.due_date)
			}),
			el('span.pcm-portal-gantt-point.' + cls, { style: 'left:' + right + '%' })
		]);

		var todayAt = ganttTodayOffset(range);
		if (null !== todayAt) { track.appendChild(el('span.pcm-portal-gantt-today', { style: 'left:' + todayAt + '%' })); }

		// The date reads in the label rail rather than beside the marker. In
		// the track it had nowhere good to go: to the right of a late
		// milestone it ran off the chart, and flipped to the left it printed
		// on top of that milestone's own bar.
		return el('div.pcm-portal-gantt-row', {}, [
			el('div.pcm-portal-gantt-label', {}, [
				el('strong', { text: row.name }),
				el('span.pcm-portal-muted', { text: milestoneState(row) + ' · ' + formatDate(row.due_date) })
			]),
			track
		]);
	}

	/** Today's position, or null when today falls outside the charted window. */
	function ganttTodayOffset(range) {
		var now = today();

		if (now < range.start || now > range.end) { return null; }

		return ganttOffset(range, now);
	}

	function gantt(milestones, project) {
		var dated = milestones.filter(function (row) { return parseDate(row.due_date); });

		if (!dated.length) { return null; }

		var range = ganttRange(dated, project);
		if (!range) { return null; }

		var chart = el('div.pcm-portal-gantt');
		var todayAt = ganttTodayOffset(range);

		chart.appendChild(el('div.pcm-portal-gantt-row.pcm-portal-gantt-head', {}, [
			el('div.pcm-portal-gantt-label', {}, [
				el('span.pcm-portal-muted', { text: formatDay(range.start) + ' – ' + formatDay(range.end) })
			]),
			ganttScale(range)
		]));

		// Each bar starts where the previous one ended, so the chart reads as
		// one chain of work rather than a set of unrelated dots.
		var from = parseDate(project.start_date) || parseDate(dated[0].due_date);

		dated.forEach(function (row) {
			chart.appendChild(ganttRow(row, range, from));
			from = parseDate(row.due_date);
		});

		var legend = el('div.pcm-portal-gantt-legend', {}, [
			el('span.pcm-portal-gantt-key.is-done', { text: 'Done' }),
			el('span.pcm-portal-gantt-key.is-planned', { text: 'Planned' }),
			el('span.pcm-portal-gantt-key.is-past-due', { text: 'Past due' }),
			null === todayAt ? null : el('span.pcm-portal-gantt-key.is-today', { text: 'Today' })
		]);

		return el('div', {}, [el('div.pcm-portal-gantt-scroll', {}, [chart]), legend]);
	}

	function milestoneItem(row) {
		var fields = el('div.pcm-portal-raid-fields');

		// No Status row: the badge already carries it, and stating the stored
		// word beside a badge reading the derived one ("Planned" next to
		// "Past due") reads as a contradiction rather than as two facts.
		[
			raidField('Due', row.due_date ? formatDate(row.due_date) : ''),
			raidField('Completed', row.completed_date ? formatDate(row.completed_date) : ''),
			raidField('Notes', row.description)
		].forEach(function (field) { if (field) { fields.appendChild(field); } });

		return el('div.pcm-portal-raid-item', {}, [
			el('div.pcm-portal-raid-head', {}, [
				el('span.pcm-portal-badge.' + milestoneClass(row), { text: milestoneState(row) }),
				el('strong', { text: row.name })
			]),
			fields
		]);
	}

	/**
	 * The chart needs the project's planned window, which lives on the summary
	 * — fetched alongside rather than read from the cache alone, so landing on
	 * this tab first (a bookmark, a switcher change) draws the same chart as
	 * arriving from Summary.
	 */
	function loadMilestones(body) {
		Promise.all([
			api('/portal/milestones', { query: { project_id: state.project } }),
			state.summary ? Promise.resolve(state.summary) : api('/portal/summary', { query: { project_id: state.project } })
		]).then(function (results) {
			var rows = results[0];
			state.summary = results[1];

			clear(body);

			if (!rows.length) {
				body.appendChild(el('p.pcm-portal-empty', { text: 'No milestones have been set on this project yet.' }));
				return;
			}

			var chart = gantt(rows, (state.summary && state.summary.project) || {});
			if (chart) { body.appendChild(chart); }

			var undated = rows.filter(function (row) { return !parseDate(row.due_date); });

			body.appendChild(el('h3', { text: 'Every milestone' }));
			rows.forEach(function (row) { body.appendChild(milestoneItem(row)); });

			if (undated.length && chart) {
				body.appendChild(el('p.pcm-portal-note', {
					text: undated.length + (1 === undated.length ? ' milestone has' : ' milestones have') + ' no date set yet, so ' + (1 === undated.length ? 'it is' : 'they are') + ' not on the chart above.'
				}));
			}
		}).catch(function (error) { showError(body, error); });
	}

	/* ---------------------------------------------------------------------
	   Project Team — read only, the roles on this project.
	   --------------------------------------------------------------------- */

	/**
	 * Grouped by party, in the order a client reads them: the delivery team
	 * first, then partners, then their own people. Each group is its own
	 * colour-keyed section of compact cards, so which side someone is on reads
	 * at a glance rather than from a heading scrolled past. Names and roles
	 * only — portal-rest.php has already dropped both rates.
	 */
	function loadRoles(body) {
		api('/portal/roles', { query: { project_id: state.project } }).then(function (rows) {
			clear(body);

			if (!rows.length) {
				body.appendChild(el('p.pcm-portal-empty', { text: 'Nobody has been assigned to this project yet.' }));
				return;
			}

			var team = el('div.pcm-portal-team');

			[
				['internal', 'Delivery Team', 'The people doing the work'],
				['partner', 'Partners', 'Firms working alongside us'],
				['client', 'Your Team', 'People on your side of the project']
			].forEach(function (group) {
				var members = rows.filter(function (row) { return row.party_type === group[0]; });
				if (!members.length) { return; }

				var cards = el('div.pcm-portal-team-cards');
				members.forEach(function (row) { cards.appendChild(roleCard(row)); });

				team.appendChild(el('section.pcm-portal-team-group.is-' + group[0], {}, [
					el('div.pcm-portal-team-head', {}, [
						el('h3', { text: group[1] }),
						el('span.pcm-portal-team-count', { text: String(members.length) }),
						el('span.pcm-portal-team-note', { text: group[2] })
					]),
					cards
				]));
			});

			body.appendChild(team);
		}).catch(function (error) { showError(body, error); });
	}

	/**
	 * Initials for the card's mark: first and last word, so "Bright Harbor
	 * Consulting" reads BC rather than BH.
	 */
	function initials(name) {
		var words = String(name || '').trim().split(/\s+/).filter(Boolean);
		if (!words.length) { return '?'; }
		var first = words[0].charAt(0);
		var last = words.length > 1 ? words[words.length - 1].charAt(0) : '';
		return (first + last).toUpperCase();
	}

	/**
	 * A partner firm may be engaged before anyone there is named, so the card
	 * falls back to the firm's own name rather than a blank line.
	 */
	function roleCard(row) {
		var name = row.party_name || row.organization || 'Not yet named';
		var meta = [];

		if (row.party_name && row.organization) { meta.push(row.organization); }
		if (row.start_date && row.end_date) { meta.push(formatDate(row.start_date) + ' – ' + formatDate(row.end_date)); }
		else if (row.start_date) { meta.push('Since ' + formatDate(row.start_date)); }
		else if (row.end_date) { meta.push('Until ' + formatDate(row.end_date)); }

		return el('div.pcm-portal-team-card', {}, [
			el('span.pcm-portal-team-mark', { text: initials(name), 'aria-hidden': 'true' }),
			el('div.pcm-portal-team-body', {}, [
				el('div.pcm-portal-team-name', {}, [
					el('strong', { text: name }),
					row.is_primary ? el('span.pcm-portal-team-primary', { text: 'Primary' }) : null
				]),
				el('span.pcm-portal-team-role', { text: row.role || row.party_label }),
				meta.length ? el('span.pcm-portal-team-meta', { text: meta.join(' · ') }) : null
			])
		]);
	}

	/* ---------------------------------------------------------------------
	   RAID — read only.
	   --------------------------------------------------------------------- */

	/**
	 * Every level word offered for Impact/Probability, or every status word —
	 * from cfg when the server sent them, falling back to the live data so an
	 * older cached bundle still gets a usable filter instead of an empty one.
	 */
	function raidFilterOptions(rows, field, fallback) {
		var fromConfig = field === 'status' ? cfg.raidStatuses : cfg.raidLevels;
		if (fromConfig && fromConfig.length) { return fromConfig; }

		var seen = [];
		rows.forEach(function (row) {
			if (row[field] && seen.indexOf(row[field]) === -1) { seen.push(row[field]); }
		});
		return seen.length ? seen : fallback;
	}

	function raidMatchesFilter(row, filters) {
		if (filters.status && row.status !== filters.status) { return false; }
		if (filters.impact && row.impact !== filters.impact) { return false; }
		if (filters.probability && row.probability !== filters.probability) { return false; }
		return true;
	}

	/**
	 * Sort options are a single field, not a separate field+direction pair —
	 * "Reported Date, newest first" is one choice a client makes, not two.
	 */
	var RAID_SORTS = {
		reported_desc: { field: 'raised_date', dir: -1 },
		reported_asc:  { field: 'raised_date', dir: 1 },
		resolved_desc: { field: 'resolved_date', dir: -1 },
		resolved_asc:  { field: 'resolved_date', dir: 1 }
	};

	var RAID_SORT_LABELS = [
		['reported_desc', 'Reported Date (newest first)'],
		['reported_asc', 'Reported Date (oldest first)'],
		['resolved_desc', 'Resolution Date (newest first)'],
		['resolved_asc', 'Resolution Date (oldest first)']
	];

	function sortRaid(rows, sortKey) {
		var sort = RAID_SORTS[sortKey];
		if (!sort) { return rows; }

		return rows.slice().sort(function (a, b) {
			var av = a[sort.field], bv = b[sort.field];
			if (!av && !bv) { return 0; }
			if (!av) { return 1; }
			if (!bv) { return -1; }
			return av < bv ? -sort.dir : (av > bv ? sort.dir : 0);
		});
	}

	function raidFilterRow(rows, filters, onChange) {
		var bar = el('div.pcm-portal-filters');

		function field(label, control) {
			return el('div.pcm-portal-filter', {}, [el('label', { text: label }), control]);
		}

		function select(label, current, options) {
			return el('select', {
				'aria-label': label,
				onchange: function (event) { current.set(event.target.value); onChange(); }
			}, options.map(function (opt) {
				return el('option', { value: opt.value, text: opt.text, selected: current.get() === opt.value });
			}));
		}

		function picklistSelect(label, key, options) {
			return field(label, select(label, {
				get: function () { return filters[key]; },
				set: function (value) { filters[key] = value; }
			}, [{ value: '', text: 'All ' + label }].concat(options.map(function (opt) { return { value: opt, text: opt }; }))));
		}

		bar.appendChild(picklistSelect('Status', 'status', raidFilterOptions(rows, 'status', [])));
		bar.appendChild(picklistSelect('Impact', 'impact', raidFilterOptions(rows, 'impact', [])));
		bar.appendChild(picklistSelect('Probability', 'probability', raidFilterOptions(rows, 'probability', [])));

		bar.appendChild(field('Sort By', select('Sort By', {
			get: function () { return filters.sort; },
			set: function (value) { filters.sort = value; }
		}, RAID_SORT_LABELS.map(function (pair) { return { value: pair[0], text: pair[1] }; })
		)));

		if (filters.status || filters.impact || filters.probability || filters.sort !== RAID_SORT_LABELS[0][0]) {
			bar.appendChild(el('button.pcm-portal-btn.pcm-portal-filters-clear', {
				type: 'button', text: 'Clear filters',
				onclick: function () {
					filters.status = ''; filters.impact = ''; filters.probability = '';
					filters.sort = RAID_SORT_LABELS[0][0];
					onChange();
				}
			}));
		}

		return bar;
	}

	function loadRaid(body) {
		api('/portal/raid', { query: { project_id: state.project } }).then(function (rows) {
			var filters = { status: '', impact: '', probability: '', sort: RAID_SORT_LABELS[0][0] };

			function paint() {
				clear(body);
				body.appendChild(raidFilterRow(rows, filters, paint));

				var visible = sortRaid(rows.filter(function (row) { return raidMatchesFilter(row, filters); }), filters.sort);

				if (!visible.length) {
					body.appendChild(el('p.pcm-portal-empty', { text: rows.length ? 'No RAID items match those filters.' : 'Nothing on the RAID log right now.' }));
					return;
				}

				var list = el('div.pcm-portal-list');
				visible.forEach(function (row) { list.appendChild(raidItem(row)); });
				body.appendChild(list);
			}

			paint();
		}).catch(function (error) { showError(body, error); });
	}

	function raidField(label, value) {
		if (value === null || value === undefined || value === '') { return null; }

		return el('div.pcm-portal-raid-field-pair', {}, [
			el('span.pcm-portal-raid-field-label', { text: label }),
			el('span.pcm-portal-raid-field-value', { text: value })
		]);
	}

	function raidItem(row) {
		var fields = el('div.pcm-portal-raid-fields');

		[
			raidField('Status', row.status),
			raidField('Impact', row.impact),
			raidField('Probability', row.probability),
			raidField('Reported', row.raised_date ? formatDate(row.raised_date) : ''),
			raidField('Review by', row.due_date ? formatDate(row.due_date) : ''),
			raidField('Resolved', row.resolved_date ? formatDate(row.resolved_date) : ''),
			raidField('Owner', row._owner_contact_id_name),
			raidField('Details', row.description),
			raidField('Mitigation', row.mitigation),
			raidField('Resolution', row.resolution)
		].forEach(function (field) { if (field) { fields.appendChild(field); } });

		return el('div.pcm-portal-raid-item', {}, [
			el('div.pcm-portal-raid-head', {}, [
				el('span.pcm-portal-badge', { text: row.raid_type }),
				el('strong', { text: row.title })
			]),
			fields
		]);
	}

	/* ---------------------------------------------------------------------
	   Documents — list and download, never a bare Media Library URL.
	   --------------------------------------------------------------------- */

	function documentUrl(row, disposition) {
		return cfg.root + '/portal/documents/' + row.id + '/download?disposition=' + disposition + '&_wpnonce=' + encodeURIComponent(cfg.nonce);
	}

	function documentPreviewNode(row) {
		var mime = row._mime_type || '';

		if (0 === mime.indexOf('image/')) {
			return el('img.pcm-portal-doc-preview-img', { src: documentUrl(row, 'inline'), alt: row.label || row._filename });
		}

		if ('application/pdf' === mime) {
			return el('iframe.pcm-portal-doc-preview-frame', { src: documentUrl(row, 'inline'), title: row.label || row._filename });
		}

		return el('p.pcm-portal-muted', { text: 'No preview available for this file type.' });
	}

	/** Who put a document there and when — the byline under its label. */
	function documentByline(row) {
		var parts = [];
		if (row.uploaded_by) { parts.push('Uploaded by ' + row.uploaded_by); }
		if (row.created_date) { parts.push(formatDate(row.created_date)); }
		return parts.join(' · ');
	}

	function loadDocuments(body) {
		api('/portal/documents', { query: { project_id: state.project } }).then(function (rows) {
			clear(body);
			rows = rows.filter(function (row) { return !row._missing; });

			body.appendChild(el('button.pcm-portal-btn.pcm-portal-btn-primary', {
				type: 'button',
				text: 'Upload Document',
				onclick: function () { newDocumentForm(body); }
			}));

			if (!rows.length) {
				body.appendChild(el('p.pcm-portal-empty', { text: 'No documents have been shared yet.' }));
				return;
			}

			var list = el('div.pcm-portal-list');
			var preview = el('div.pcm-portal-card.pcm-portal-doc-preview');

			function showPreview(row) {
				clear(preview);
				preview.appendChild(el('div.pcm-portal-doc-preview-head', {}, [
					el('div', {}, [
						el('strong', { text: row.label || row._filename }),
						el('p.pcm-portal-muted', { text: documentByline(row) })
					]),
					el('a.pcm-portal-btn', {
						href: documentUrl(row, 'attachment'),
						text: 'Download'
					})
				]));
				preview.appendChild(documentPreviewNode(row));
			}

			rows.forEach(function (row, index) {
				var rowButton = el('button.pcm-portal-row', {
					type: 'button',
					onclick: function () { showPreview(row); }
				}, [
					el('div.pcm-portal-row-body', {}, [
						el('strong', { text: row.label || row._filename }),
						el('span.pcm-portal-muted', { text: documentByline(row) || row._filename })
					])
				]);

				list.appendChild(rowButton);
				if (0 === index) { showPreview(row); }
			});

			body.appendChild(el('div.pcm-portal-doc-layout', {}, [list, preview]));
		}).catch(function (error) { showError(body, error); });
	}

	/**
	 * A single file plus what it's for — the portal's twin of
	 * newTicketForm(), but one file rather than several, since each upload
	 * carries its own description rather than one description covering a
	 * batch. The description becomes the row's `label`
	 * (pcm_crm_portal_rest_create_document()), the same field a staff-side
	 * document already carries.
	 */
	function newDocumentForm(body) {
		clear(body);

		var file = el('input', { type: 'file', required: true, accept: 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt' });
		var description = el('input', { type: 'text', placeholder: 'What is this document?' });
		var status = el('p.pcm-portal-note');

		body.appendChild(el('form.pcm-portal-form', { onsubmit: function (e) { e.preventDefault(); } }, [
			el('label', { text: 'File' }), file,
			el('label', { text: 'Description' }), description,
			status,
			el('div.pcm-portal-form-actions', {}, [
				el('button.pcm-portal-btn.pcm-portal-btn-primary', {
					type: 'button',
					text: 'Upload',
					onclick: function (event) {
						var selected = (file.files || [])[0];

						if (!selected) { status.textContent = 'Choose a file first.'; return; }

						event.target.disabled = true;
						status.textContent = 'Uploading…';

						apiUpload('/portal/documents?project_id=' + encodeURIComponent(state.project), selected, { label: description.value })
							.then(function () { loadDocuments(body); })
							.catch(function (error) {
								status.textContent = error.message;
								event.target.disabled = false;
							});
					}
				}),
				el('button.pcm-portal-btn', { type: 'button', text: 'Cancel', onclick: function () { loadDocuments(body); } })
			])
		]));
	}

	/* ---------------------------------------------------------------------
	   Help Tickets — list across every project, create, comment, set status.
	   --------------------------------------------------------------------- */

	function statusClass(status) { return 'is-' + String(status || '').toLowerCase().replace(/[^a-z]+/g, '-'); }

	function loadTickets(body) {
		api('/portal/tickets').then(function (rows) {
			clear(body);

			body.appendChild(el('button.pcm-portal-btn.pcm-portal-btn-primary', {
				type: 'button',
				text: 'New Ticket',
				onclick: function () { newTicketForm(body); }
			}));

			if (!rows.length) {
				body.appendChild(el('p.pcm-portal-empty', { text: 'No tickets yet.' }));
				return;
			}

			var list = el('div.pcm-portal-list');

			rows.forEach(function (row) {
				list.appendChild(el('button.pcm-portal-row', {
					type: 'button',
					onclick: function () { state.ticket = row.id; renderBody(body); }
				}, [
					el('span.pcm-portal-badge.' + statusClass(row.status), { text: row.status }),
					el('div.pcm-portal-row-body', {}, [
						el('strong', { text: row.subject }),
						el('span.pcm-portal-muted', { text: (row._project_id_name || '') + ' · ' + formatDate(row.last_modified_date) })
					])
				]));
			});

			body.appendChild(list);
		}).catch(function (error) { showError(body, error); });
	}

	function newTicketForm(body) {
		clear(body);

		var projectSelect = el('select', { 'aria-label': 'Project' });
		state.me.projects.forEach(function (project) {
			projectSelect.appendChild(el('option', { value: project.id, text: project.name }));
		});

		var subject = el('input', { type: 'text', placeholder: 'What do you need help with?', required: true });
		var description = el('textarea', { placeholder: 'Any detail that would help — steps or a link.', rows: '5' });
		var files = el('input', { type: 'file', multiple: true, accept: 'image/*,.pdf,.doc,.docx' });
		var status = el('p.pcm-portal-note');

		body.appendChild(el('form.pcm-portal-form', { onsubmit: function (e) { e.preventDefault(); } }, [
			el('label', { text: 'Project' }), projectSelect,
			el('label', { text: 'Subject' }), subject,
			el('label', { text: 'Details' }), description,
			el('label', { text: 'Attach files (optional)' }), files,
			status,
			el('div.pcm-portal-form-actions', {}, [
				el('button.pcm-portal-btn.pcm-portal-btn-primary', {
					type: 'button',
					text: 'Submit',
					onclick: function (event) {
						if (!subject.value.trim()) { status.textContent = 'A subject is needed.'; return; }

						event.target.disabled = true;
						status.textContent = 'Sending…';

						api('/portal/tickets', {
							method: 'POST',
							body: { project_id: Number(projectSelect.value), subject: subject.value, description: description.value }
						}).then(function (ticket) {
							var selected = Array.prototype.slice.call(files.files || []);

							if (!selected.length) { state.ticket = ticket.id; renderBody(body); return; }

							status.textContent = 'Uploading attachments…';

							// The ticket exists whether or not an attachment upload
							// fails, so a failed upload is surfaced but does not lose
							// the ticket the client just filed.
							uploadAll('/portal/tickets/' + ticket.id + '/attachments', selected)
								.catch(function (error) { window.alert('The ticket was created, but a file did not upload: ' + error.message); })
								.then(function () { state.ticket = ticket.id; renderBody(body); });
						}).catch(function (error) {
							status.textContent = error.message;
							event.target.disabled = false;
						});
					}
				}),
				el('button.pcm-portal-btn', { type: 'button', text: 'Cancel', onclick: function () { loadTickets(body); } })
			])
		]));
	}

	function loadTicket(body) {
		Promise.all([
			api('/portal/tickets/' + state.ticket),
			api('/portal/tickets/' + state.ticket + '/comments'),
			api('/portal/tickets/' + state.ticket + '/attachments')
		]).then(function (results) {
			clear(body);
			body.appendChild(ticketPanel(body, results[0], results[1], results[2]));
		}).catch(function (error) { showError(body, error); });
	}

	/**
	 * A ticket's attachments as a small row list — the same preview-on-click
	 * shape the Documents tab uses, reusing documentUrl()/documentPreviewNode()
	 * rather than a parallel implementation, since a ticket attachment is a
	 * project_documents row like any other (see model-project-document.php).
	 */
	function attachmentsBlock(rows) {
		if (!rows.length) { return null; }

		var list = el('div.pcm-portal-list');
		var preview = el('div.pcm-portal-card.pcm-portal-doc-preview');

		function showPreview(row) {
			clear(preview);
			preview.appendChild(el('div.pcm-portal-doc-preview-head', {}, [
				el('strong', { text: row.label || row._filename }),
				el('a.pcm-portal-btn', { href: documentUrl(row, 'attachment'), text: 'Download' })
			]));
			preview.appendChild(documentPreviewNode(row));
		}

		rows.forEach(function (row, index) {
			list.appendChild(el('button.pcm-portal-row', {
				type: 'button',
				onclick: function () { showPreview(row); }
			}, [
				el('div.pcm-portal-row-body', {}, [
					el('strong', { text: row.label || row._filename }),
					el('span.pcm-portal-muted', { text: row._filename })
				])
			]));

			if (0 === index) { showPreview(row); }
		});

		return el('div.pcm-portal-doc-layout', {}, [list, preview]);
	}

	function ticketPanel(body, ticket, comments, attachments) {
		var wrap = el('div.pcm-portal-ticket');

		wrap.appendChild(el('button.pcm-portal-back', {
			type: 'button',
			text: '← All tickets',
			onclick: function () { state.ticket = 0; renderBody(body); }
		}));

		wrap.appendChild(el('h2', { text: ticket.subject }));

		var statuses = (cfg.statuses && cfg.statuses.length) ? cfg.statuses : ['To Do', 'In Progress', 'Client Testing', 'Client Approved'];
		var statusRow = el('div.pcm-portal-status-row');

		statuses.forEach(function (name) {
			statusRow.appendChild(el('button.pcm-portal-status-btn' + (name === ticket.status ? '.is-current' : ''), {
				type: 'button',
				text: name,
				disabled: name === ticket.status,
				onclick: function () {
					api('/portal/tickets/' + ticket.id + '/status', { method: 'PATCH', body: { status: name } })
						.then(function () { loadTicket(body); })
						.catch(function (error) { window.alert(error.message); });
				}
			}));
		});

		wrap.appendChild(statusRow);

		if (ticket.description) { wrap.appendChild(el('p.pcm-portal-description', { text: ticket.description })); }

		var attachmentsNode = attachmentsBlock(attachments || []);
		if (attachmentsNode) {
			wrap.appendChild(el('h3', { text: 'Attachments' }));
			wrap.appendChild(attachmentsNode);
		}

		var thread = el('div.pcm-portal-thread');
		var byId = {};
		comments.forEach(function (c) { byId[c.id] = c; });

		comments.filter(function (c) { return !c.parent_id; }).forEach(function (c) {
			thread.appendChild(commentNode(c));
			comments.filter(function (r) { return Number(r.parent_id) === Number(c.id); }).forEach(function (r) {
				thread.appendChild(commentNode(r, true));
			});
		});

		wrap.appendChild(el('h3', { text: 'Comments' }));
		wrap.appendChild(thread);
		wrap.appendChild(replyForm(body, ticket.id));

		return wrap;
	}

	function commentNode(comment, indented) {
		return el('div.pcm-portal-comment' + (indented ? '.is-reply' : '') + (comment.is_client_comment ? '.is-mine' : '.is-staff'), {}, [
			el('div.pcm-portal-comment-head', {}, [
				el('strong', { text: comment.is_client_comment ? 'You' : 'PCM' }),
				el('span.pcm-portal-muted', { text: formatDate(comment.created_date) })
			]),
			el('p', { text: comment.body })
		]);
	}

	function replyForm(body, ticketId) {
		var text = el('textarea', { placeholder: 'Add a comment…', rows: '3' });
		var files = el('input', { type: 'file', multiple: true, accept: 'image/*,.pdf,.doc,.docx' });
		var status = el('span.pcm-portal-note');

		return el('form.pcm-portal-reply', { onsubmit: function (e) { e.preventDefault(); } }, [
			text,
			el('label', { text: 'Attach files (optional)' }), files,
			el('div.pcm-portal-form-actions', {}, [
				el('button.pcm-portal-btn.pcm-portal-btn-primary', {
					type: 'button',
					text: 'Post comment',
					onclick: function (event) {
						var selected = Array.prototype.slice.call(files.files || []);

						if (!text.value.trim() && !selected.length) { return; }

						event.target.disabled = true;
						status.textContent = 'Sending…';

						var posted = text.value.trim()
							? api('/portal/tickets/' + ticketId + '/comments', { method: 'POST', body: { body: text.value } })
							: Promise.resolve();

						posted
							.then(function () { return selected.length ? uploadAll('/portal/tickets/' + ticketId + '/attachments', selected) : null; })
							.then(function () { state.ticket = ticketId; loadTicket(body); })
							.catch(function (error) {
								status.textContent = error.message;
								event.target.disabled = false;
							});
					}
				}),
				status
			])
		]);
	}

	/* ---------------------------------------------------------------------
	   Boot.
	   --------------------------------------------------------------------- */

	function init() {
		api('/portal/me').then(function (me) {
			state.me = me;
			state.project = me.projects.length ? me.projects[0].id : 0;

			if (!me.projects.length) {
				root.appendChild(el('div.pcm-portal-empty', null, [
					el('h2', { text: 'Welcome' + (me.contact_name ? ', ' + me.contact_name : '') }),
					el('p', { text: 'No project is set up for you yet. Once one is, it will appear here.' })
				]));
				return;
			}

			render();
		}).catch(function (error) { showError(root, error); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
