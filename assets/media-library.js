/**
 * The Media area's browse frame — public/staff-media.php's single button
 * opens the same wp.media() modal every picker elsewhere in this app already
 * uses (assets/settings.js, assets/pm.js), configured with no select target:
 * clicking a file just shows its details in the sidebar, and the button
 * closes the frame rather than inserting anything. Browsing and uploading
 * both work through it regardless — neither depends on a selection ever
 * being made.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var button = document.querySelector('[data-role="media-open"]');

		if (!button || !window.wp || !window.wp.media) {
			return;
		}

		var frame = null;

		button.addEventListener('click', function () {
			if (!frame) {
				frame = window.wp.media({
					title: 'Media Library',
					library: {},
					multiple: false,
					button: { text: 'Close' }
				});
			}

			frame.open();
		});
	});
}());
