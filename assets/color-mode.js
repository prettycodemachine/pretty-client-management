/**
 * The light/dark switch, made instant.
 *
 * The switch is a real form (pcm_crm_color_mode_toggle(), includes/themes.php)
 * that works without this file. This only saves the round trip: it flips the
 * palette on the page at once, then saves the choice in the background.
 *
 * Everything it needs is on the form itself, so it takes no localized data.
 */
(function () {
	'use strict';

	function apply(mode) {
		var theme = mode === 'dark' ? 'dark' : 'pcm';
		var body = document.body;

		document.querySelectorAll('.pcm-crm[data-theme]').forEach(function (shell) {
			shell.setAttribute('data-theme', theme);
		});

		body.classList.remove('pcm-crm-mode-light', 'pcm-crm-mode-dark');
		body.classList.add('pcm-crm-mode-' + mode);

		// Every switch on the page now offers the other mode.
		document.querySelectorAll('.pcm-crm-mode-toggle input[name="mode"]').forEach(function (input) {
			input.value = mode === 'dark' ? 'light' : 'dark';
		});

		// crm.js redraws its charts from the new tokens (they read colours at
		// draw time, so they would otherwise keep the old palette).
		var event;
		try {
			event = new CustomEvent('pcm-crm-mode', { detail: { mode: mode } });
		} catch (e) {
			event = document.createEvent('CustomEvent');
			event.initCustomEvent('pcm-crm-mode', false, false, { mode: mode });
		}
		document.dispatchEvent(event);
	}

	document.addEventListener('submit', function (event) {
		var form = event.target;

		if (!form.classList || !form.classList.contains('pcm-crm-mode-toggle') || !window.fetch || !window.FormData) {
			return;
		}

		event.preventDefault();

		var data = new FormData(form);
		var mode = data.get('mode') === 'dark' ? 'dark' : 'light';
		data.append('ajax', '1');

		apply(mode);

		// getAttribute, never form.action: the form carries an input named
		// "action" (admin-post.php routes on it), and a named control shadows
		// the form property of the same name — form.action is that input.
		//
		// If the save fails the page is still switched; it simply opens in the
		// old mode next time, which is the least surprising way to lose it.
		fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' }).catch(function () {});
	});
})();
