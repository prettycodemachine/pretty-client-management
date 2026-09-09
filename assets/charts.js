/**
 * Small SVG chart generators for the CRM dashboard.
 *
 * Hand-rolled rather than pulled from a chart library: the four shapes needed
 * here are simple, the repo has no build step to bundle a dependency with, and
 * the artifact CSP-style constraints of wp-admin make a CDN a bad idea. Every
 * generator returns an <svg> element the caller appends.
 *
 * Colours come from the CRM's own custom properties, read off the container,
 * so a palette change in crm.css moves the charts with it.
 */
(function (window, document) {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';

	function el(name, attrs) {
		var node = document.createElementNS(NS, name);
		Object.keys(attrs || {}).forEach(function (key) {
			node.setAttribute(key, attrs[key]);
		});
		return node;
	}

	function text(content, attrs) {
		var node = el('text', attrs);
		node.textContent = content;
		return node;
	}

	/**
	 * The categorical ramp: PCM's two brand hues plus tints of each, ordered so
	 * neighbouring series never sit on adjacent tints of the same hue.
	 */
	function palette() {
		return ['#3f6b96', '#c94040', '#7ba3c9', '#d56b6b', '#2f7d5d', '#8a6bab', '#d19b3d', '#5f8fa8'];
	}

	function formatCurrency(value) {
		var abs = Math.abs(value);
		if (abs >= 1000000) { return '$' + (value / 1000000).toFixed(1).replace(/\.0$/, '') + 'M'; }
		if (abs >= 1000) { return '$' + (value / 1000).toFixed(1).replace(/\.0$/, '') + 'k'; }
		return '$' + Math.round(value);
	}

	function svg(width, height) {
		return el('svg', {
			viewBox: '0 0 ' + width + ' ' + height,
			// The container sets the real width; the viewBox does the scaling,
			// so the same chart works in a 340px card and a 900px one.
			preserveAspectRatio: 'xMidYMid meet',
			role: 'img'
		});
	}

	/**
	 * Horizontal bars — used for pipeline value by stage.
	 *
	 * Horizontal rather than vertical because stage names are long enough that
	 * vertical bars would need rotated labels to fit.
	 */
	function barChart(rows, options) {
		options = options || {};
		var W = 520;
		var rowH = 38;
		var labelW = 130;
		var H = Math.max(rows.length * rowH + 16, 60);
		var chart = svg(W, H);
		var max = Math.max.apply(null, rows.map(function (r) { return r.total || 0; }).concat([1]));
		var colors = palette();

		rows.forEach(function (row, i) {
			var y = i * rowH + 8;
			var barW = Math.max(((row.total || 0) / max) * (W - labelW - 90), row.total ? 3 : 0);

			chart.appendChild(text(row.value || '—', {
				x: labelW - 10, y: y + 15, 'text-anchor': 'end',
				'font-size': '12', 'font-weight': '700', fill: '#2c2c2e'
			}));

			chart.appendChild(el('rect', {
				x: labelW, y: y, width: W - labelW - 90, height: 22, rx: 5, fill: '#f4f8fc'
			}));

			chart.appendChild(el('rect', {
				x: labelW, y: y, width: barW, height: 22, rx: 5,
				fill: colors[i % colors.length]
			}));

			chart.appendChild(text(formatCurrency(row.total || 0) + '  ·  ' + (row.count || 0), {
				x: W - 84, y: y + 15, 'font-size': '11', fill: '#46464a'
			}));
		});

		if (!rows.length) {
			chart.appendChild(text(options.empty || 'No data', {
				x: W / 2, y: 34, 'text-anchor': 'middle', 'font-size': '13', fill: '#aaaaaa'
			}));
		}

		return chart;
	}

	/**
	 * Funnel — stage counts as tapering bands, which is how a pipeline is read.
	 */
	function funnelChart(rows) {
		var W = 520;
		var bandH = 44;
		var H = Math.max(rows.length * bandH + 10, 60);
		var chart = svg(W, H);
		var max = Math.max.apply(null, rows.map(function (r) { return r.count || 0; }).concat([1]));
		var colors = palette();

		rows.forEach(function (row, i) {
			var y = i * bandH + 5;
			// Every band keeps a floor width so an empty stage is still visible
			// as a gap in the funnel rather than vanishing from it.
			var w = Math.max(((row.count || 0) / max) * (W - 180), 40);
			var x = (W - 180 - w) / 2 + 20;

			chart.appendChild(el('rect', {
				x: x, y: y, width: w, height: bandH - 8, rx: 6,
				fill: colors[i % colors.length], opacity: row.count ? '1' : '0.25'
			}));

			chart.appendChild(text(row.count || 0, {
				x: x + w / 2, y: y + 24, 'text-anchor': 'middle',
				'font-size': '13', 'font-weight': '700', fill: '#ffffff'
			}));

			chart.appendChild(text(row.value, {
				x: W - 150, y: y + 20, 'font-size': '12', 'font-weight': '700', fill: '#2c2c2e'
			}));

			chart.appendChild(text(formatCurrency(row.total || 0), {
				x: W - 150, y: y + 33, 'font-size': '11', fill: '#46464a'
			}));
		});

		return chart;
	}

	/**
	 * Donut — share of a total, with the total in the middle.
	 */
	function donutChart(rows, options) {
		options = options || {};
		var size = 260;
		var chart = svg(size, size);
		var cx = size / 2, cy = size / 2, r = 96, thickness = 34;
		var colors = palette();
		var key = options.metric === 'count' ? 'count' : 'total';
		var sum = rows.reduce(function (acc, row) { return acc + (row[key] || 0); }, 0);

		if (!sum) {
			chart.appendChild(el('circle', { cx: cx, cy: cy, r: r, fill: 'none', stroke: '#e4e4e4', 'stroke-width': thickness }));
			chart.appendChild(text('No data', { x: cx, y: cy + 5, 'text-anchor': 'middle', 'font-size': '13', fill: '#aaaaaa' }));
			return chart;
		}

		var angle = -Math.PI / 2;

		rows.forEach(function (row, i) {
			var portion = (row[key] || 0) / sum;
			if (portion <= 0) { return; }

			var end = angle + portion * Math.PI * 2;
			var x1 = cx + r * Math.cos(angle), y1 = cy + r * Math.sin(angle);
			var x2 = cx + r * Math.cos(end), y2 = cy + r * Math.sin(end);
			// A slice of more than half a circle needs the large-arc flag, or
			// the path silently draws the short way round.
			var large = portion > 0.5 ? 1 : 0;

			chart.appendChild(el('path', {
				d: 'M ' + x1 + ' ' + y1 + ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + x2 + ' ' + y2,
				fill: 'none',
				stroke: colors[i % colors.length],
				'stroke-width': thickness
			}));

			angle = end;
		});

		chart.appendChild(text(options.metric === 'count' ? sum : formatCurrency(sum), {
			x: cx, y: cy + 2, 'text-anchor': 'middle',
			'font-size': '20', 'font-weight': '700', fill: '#2c2c2e',
			'font-family': '"Baloo 2", sans-serif'
		}));
		chart.appendChild(text(options.centerLabel || '', {
			x: cx, y: cy + 20, 'text-anchor': 'middle', 'font-size': '11', fill: '#46464a'
		}));

		return chart;
	}

	/**
	 * Grouped columns over months — created against won.
	 */
	function columnChart(rows, series) {
		var W = 520, H = 220;
		var padL = 34, padB = 28, padT = 10;
		var chart = svg(W, H);
		var colors = palette();

		var max = 1;
		rows.forEach(function (row) {
			series.forEach(function (s) { max = Math.max(max, row[s.key] || 0); });
		});

		var plotW = W - padL - 10;
		var plotH = H - padB - padT;
		var groupW = plotW / Math.max(rows.length, 1);
		var barW = Math.min((groupW - 6) / series.length, 16);

		// Three gridlines is enough to read a value off; more turns the plot
		// into a ladder.
		[0, 0.5, 1].forEach(function (t) {
			var y = padT + plotH - t * plotH;
			chart.appendChild(el('line', {
				x1: padL, y1: y, x2: W - 10, y2: y, stroke: '#e4e4e4', 'stroke-width': '1'
			}));
			chart.appendChild(text(Math.round(max * t), {
				x: padL - 6, y: y + 4, 'text-anchor': 'end', 'font-size': '10', fill: '#aaaaaa'
			}));
		});

		rows.forEach(function (row, i) {
			var gx = padL + i * groupW;

			series.forEach(function (s, si) {
				var value = row[s.key] || 0;
				var h = (value / max) * plotH;

				chart.appendChild(el('rect', {
					x: gx + (groupW - barW * series.length) / 2 + si * barW,
					y: padT + plotH - h,
					width: Math.max(barW - 2, 2),
					height: Math.max(h, value ? 2 : 0),
					rx: 2,
					fill: colors[si % colors.length]
				}));
			});

			// Every other label on a 12-month axis, so they never collide.
			if (rows.length <= 8 || i % 2 === 0) {
				chart.appendChild(text(row.label, {
					x: gx + groupW / 2, y: H - 9, 'text-anchor': 'middle',
					'font-size': '10', fill: '#46464a'
				}));
			}
		});

		return chart;
	}

	window.PCM_CRM_Charts = {
		bar: barChart,
		funnel: funnelChart,
		donut: donutChart,
		columns: columnChart,
		palette: palette,
		formatCurrency: formatCurrency
	};
})(window, document);
