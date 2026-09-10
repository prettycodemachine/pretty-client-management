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
		attachments();
		logoPicker();
		fieldBuilder();
		mergeFields();
		copyShortcode();
	}

	/* -------------------------------------------------------------------
	   Attachments
	   ------------------------------------------------------------------- */

	function attachments() {
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
				frame = window.wp.media({ title: 'Choose attachments', button: { text: 'Use these files' }, multiple: true });

				frame.on('select', function () {
					frame.state().get('selection').each(function (model) { addItem(model.toJSON()); });
				});
			}

			frame.open();
		});

		list.addEventListener('click', function (event) {
			var button = event.target.closest('.pcm-crm-attachment-remove');
			if (!button) { return; }

			event.preventDefault();
			button.closest('.pcm-crm-attachment').remove();
			sync();
		});
	}

	/* -------------------------------------------------------------------
	   Email logo
	   ------------------------------------------------------------------- */

	function logoPicker() {
		var root = document.querySelector('[data-role="logo"]');
		if (!root || !window.wp || !window.wp.media) { return; }

		var preview = root.querySelector('[data-role="logo-preview"]');
		var field = root.querySelector('[data-role="logo-id"]');
		var remove = root.querySelector('[data-role="logo-remove"]');
		var frame = null;

		root.querySelector('[data-role="logo-choose"]').addEventListener('click', function () {
			if (!frame) {
				frame = window.wp.media({
					title: 'Choose an email logo',
					button: { text: 'Use this logo' },
					library: { type: 'image' },
					multiple: false
				});

				frame.on('select', function () {
					var image = frame.state().get('selection').first().toJSON();
					var src = (image.sizes && image.sizes.medium) ? image.sizes.medium.url : image.url;

					field.value = image.id;
					preview.replaceChildren();

					var img = document.createElement('img');
					img.src = src;
					img.alt = '';
					preview.appendChild(img);

					remove.hidden = false;
				});
			}

			frame.open();
		});

		remove.addEventListener('click', function () {
			// Cleared rather than deleted: the file stays in the media library,
			// and the email falls back to the theme's own logo.
			field.value = '0';
			preview.replaceChildren();
			remove.hidden = true;
		});
	}

	/* -------------------------------------------------------------------
	   Field builder
	   ------------------------------------------------------------------- */

	function fieldBuilder() {
		var list = document.querySelector('[data-role="form-fields"]');
		if (!list) { return; }

		var template = document.getElementById('tmpl-pcm-crm-field-row');
		var addButton = document.querySelector('[data-role="add-field"]');

		/**
		 * Renumber every row's input names.
		 *
		 * The names carry the array index, so reordering or removing a row has
		 * to rewrite them — otherwise PHP receives gaps, or two rows claiming
		 * the same index and one of them lost.
		 */
		function renumber() {
			Array.prototype.forEach.call(list.querySelectorAll('[data-role="field-row"]'), function (row, index) {
				Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (input) {
					input.name = input.name.replace(/\[(?:\d+|__index__)\]/, '[' + index + ']');
				});
			});
		}

		if (addButton && template) {
			addButton.addEventListener('click', function () {
				var wrapper = document.createElement('div');
				wrapper.innerHTML = template.innerHTML;

				var row = wrapper.querySelector('[data-role="field-row"]');
				list.appendChild(row);
				renumber();
				row.querySelector('.pcm-crm-field-label').focus();
			});
		}

		list.addEventListener('click', function (event) {
			var row = event.target.closest('[data-role="field-row"]');
			if (!row) { return; }

			if (event.target.closest('[data-role="remove-field"]')) {
				event.preventDefault();
				row.remove();
				renumber();
				return;
			}

			if (event.target.closest('[data-role="move-up"]')) {
				event.preventDefault();
				if (row.previousElementSibling) { list.insertBefore(row, row.previousElementSibling); }
				renumber();
				return;
			}

			if (event.target.closest('[data-role="move-down"]')) {
				event.preventDefault();
				if (row.nextElementSibling) { list.insertBefore(row.nextElementSibling, row); }
				renumber();
			}
		});

		// Only a dropdown needs a list of choices.
		list.addEventListener('change', function (event) {
			var select = event.target.closest('[data-role="field-type"]');
			if (!select) { return; }

			var row = select.closest('[data-role="field-row"]');
			var options = row.querySelector('[data-role="field-options"]');

			if (options) { options.hidden = select.value !== 'select'; }
		});
	}

	/* -------------------------------------------------------------------
	   Merge fields
	   ------------------------------------------------------------------- */

	function mergeFields() {
		var bar = document.querySelector('[data-role="tokens"]');
		if (!bar) { return; }

		bar.addEventListener('click', function (event) {
			var button = event.target.closest('[data-token]');
			if (!button) { return; }

			event.preventDefault();
			insertToken(button.dataset.token);
		});
	}

	/**
	 * Put a token where the cursor is.
	 *
	 * The editor has two modes and they need different handling: TinyMCE owns
	 * an iframe in Visual mode, while Text mode is a plain textarea. Appending
	 * to the end in both cases would be simpler and would put the token in the
	 * wrong place every time.
	 */
	function insertToken(token) {
		var editor = window.tinymce && window.tinymce.get('pcm_autoresponder_body');

		if (editor && !editor.isHidden()) {
			editor.execCommand('mceInsertContent', false, token);
			editor.focus();
			return;
		}

		var textarea = document.getElementById('pcm_autoresponder_body');
		if (!textarea) { return; }

		var start = textarea.selectionStart || 0;
		var end = textarea.selectionEnd || 0;

		textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(end);
		textarea.selectionStart = textarea.selectionEnd = start + token.length;
		textarea.focus();
	}

	/* -------------------------------------------------------------------
	   Embed
	   ------------------------------------------------------------------- */

	function copyShortcode() {
		var button = document.querySelector('[data-role="copy-shortcode"]');
		if (!button) { return; }

		button.addEventListener('click', function () {
			var text = button.dataset.shortcode;
			var done = function () {
				var original = button.textContent;
				button.textContent = 'Copied';
				window.setTimeout(function () { button.textContent = original; }, 1600);
			};

			// The clipboard API needs a secure context, which an admin over
			// plain http is not — hence the fallback rather than a failure.
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done);
				return;
			}

			var helper = document.createElement('textarea');
			helper.value = text;
			helper.style.position = 'fixed';
			helper.style.left = '-9999px';
			document.body.appendChild(helper);
			helper.select();
			document.execCommand('copy');
			helper.remove();
			done();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
