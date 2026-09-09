/**
 * The attachment picker on CRM → Settings.
 *
 * Uses wp.media rather than a raw ID field, which is what the theme's version
 * had: an ID typed by hand is easy to get wrong, and wrong means an email that
 * silently arrives without its pricing sheet.
 *
 * The list posts as a comma-separated hidden field, since one hidden input
 * cannot carry an array on its own.
 */
(function (window, document) {
	'use strict';

	function init() {
		var root = document.querySelector('[data-role="attachments"]');
		if (!root || !window.wp || !window.wp.media) { return; }

		var list = root.querySelector('[data-role="attachment-list"]');
		var field = root.querySelector('[data-role="attachment-ids"]');
		var addButton = root.querySelector('[data-role="attachment-add"]');
		var frame = null;

		function ids() {
			return field.value.split(',').map(function (id) { return id.trim(); }).filter(Boolean);
		}

		function sync() {
			field.value = Array.prototype.slice.call(list.querySelectorAll('.pcm-crm-attachment'))
				.map(function (item) { return item.dataset.id; })
				.join(',');
		}

		function addItem(attachment) {
			if (ids().indexOf(String(attachment.id)) !== -1) { return; }

			var item = document.createElement('li');
			item.className = 'pcm-crm-attachment';
			item.dataset.id = attachment.id;

			var name = document.createElement('span');
			name.className = 'pcm-crm-attachment-name';
			name.textContent = attachment.filename || attachment.title || ('#' + attachment.id);

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'button-link pcm-crm-attachment-remove';
			remove.setAttribute('aria-label', 'Remove attachment');
			remove.textContent = '×';

			item.appendChild(name);
			item.appendChild(remove);
			list.appendChild(item);
			sync();
		}

		addButton.addEventListener('click', function () {
			// The frame is kept between opens so the library does not re-query
			// every time, but the selection is reset — reopening should not
			// arrive with the previous pick already ticked.
			if (!frame) {
				frame = window.wp.media({
					title: 'Choose attachments',
					button: { text: 'Use these files' },
					multiple: true
				});

				frame.on('select', function () {
					frame.state().get('selection').each(function (model) {
						addItem(model.toJSON());
					});
				});
			}

			frame.open();
		});

		// Delegated, so rows added after load behave like the ones rendered
		// by PHP.
		list.addEventListener('click', function (event) {
			var button = event.target.closest('.pcm-crm-attachment-remove');
			if (!button) { return; }

			event.preventDefault();
			button.closest('.pcm-crm-attachment').remove();
			sync();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
