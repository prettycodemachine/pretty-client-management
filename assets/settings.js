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
		customFields();
		layoutEditor();
	}

	/* -------------------------------------------------------------------
	   Custom fields
	   ------------------------------------------------------------------- */

	function customFields() {
		var list = document.querySelector('[data-role="custom-fields"]');
		if (!list) { return; }

		var template = document.getElementById('tmpl-pcm-crm-custom-field');
		var addButton = document.querySelector('[data-role="add-custom-field"]');

		function renumber() {
			Array.prototype.forEach.call(list.querySelectorAll('[data-role="custom-field-row"]'), function (row, index) {
				Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (input) {
					input.name = input.name.replace(/\[(?:\d+|__index__)\]/, '[' + index + ']');
				});
			});
		}

		if (addButton && template) {
			addButton.addEventListener('click', function () {
				var wrapper = document.createElement('div');
				wrapper.innerHTML = template.innerHTML;

				var row = wrapper.querySelector('[data-role="custom-field-row"]');
				list.appendChild(row);
				renumber();
				row.querySelector('.pcm-crm-field-label').focus();
			});
		}

		list.addEventListener('click', function (event) {
			if (!event.target.closest('[data-role="remove-custom-field"]')) { return; }

			event.preventDefault();

			// Removing a definition hides the field; the column and its data
			// stay, so this is not the destructive act it looks like.
			if (!window.confirm('Remove this field from the CRM? Its column and data are kept.')) { return; }

			event.target.closest('[data-role="custom-field-row"]').remove();
			renumber();
		});

		// Only a picklist needs values, and only a relationship needs a target.
		list.addEventListener('change', function (event) {
			var select = event.target.closest('[data-role="custom-type"]');
			if (!select) { return; }

			var row = select.closest('[data-role="custom-field-row"]');
			var options = row.querySelector('[data-role="custom-options"]');
			var related = row.querySelector('[data-role="custom-related"]');

			if (options) { options.hidden = select.value !== 'picklist'; }
			if (related) { related.hidden = select.value !== 'relationship'; }
		});
	}

	/* -------------------------------------------------------------------
	   Layout editor
	   ------------------------------------------------------------------- */

	/**
	 * Drag fields between sections, and within them.
	 *
	 * A chip inside a section carries a hidden input naming that section's
	 * field array; one in Available carries none. Moving a chip is therefore
	 * a DOM move plus adding or removing that input, which is why the whole
	 * thing needs no model of its own to keep in sync.
	 */
	function layoutEditor() {
		var editor = document.querySelector('[data-role="layout"]');
		if (!editor) { return; }

		var dragging = null;

		function inputNameFor(list) {
			return list.dataset.name || '';
		}

		/**
		 * Put a chip's hidden input in step with the list it now sits in.
		 */
		function sync(chip, list) {
			var existing = chip.querySelector('input[type="hidden"]');
			var name = inputNameFor(list);

			if (!name) {
				// Back in Available: no input, so the field is simply absent
				// from the posted layout.
				if (existing) { existing.remove(); }
				return;
			}

			if (existing) {
				existing.name = name;
				return;
			}

			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = chip.dataset.field;
			chip.appendChild(input);
		}

		function syncAll() {
			Array.prototype.forEach.call(editor.querySelectorAll('[data-role="section-fields"], [data-role="available"]'), function (list) {
				Array.prototype.forEach.call(list.querySelectorAll('[data-role="chip"]'), function (chip) { sync(chip, list); });
			});
		}

		/**
		 * Which chip a drop should land before.
		 *
		 * Measured from each chip's midpoint, so a drop reads as "before the
		 * one I am pointing above" rather than snapping to the end.
		 */
		function chipAfter(list, y) {
			var chips = Array.prototype.slice.call(list.querySelectorAll('[data-role="chip"]:not(.is-dragging)'));

			return chips.reduce(function (closest, chip) {
				var box = chip.getBoundingClientRect();
				var offset = y - box.top - box.height / 2;

				return (offset < 0 && offset > closest.offset) ? { offset: offset, element: chip } : closest;
			}, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
		}

		editor.addEventListener('dragstart', function (event) {
			var chip = event.target.closest('[data-role="chip"]');
			if (!chip) { return; }

			dragging = chip;
			chip.classList.add('is-dragging');
			event.dataTransfer.effectAllowed = 'move';
			// Firefox will not start a drag without data on the transfer.
			event.dataTransfer.setData('text/plain', chip.dataset.field);
		});

		editor.addEventListener('dragend', function () {
			if (dragging) { dragging.classList.remove('is-dragging'); }
			dragging = null;
			syncAll();
		});

		editor.addEventListener('dragover', function (event) {
			var list = event.target.closest('[data-role="section-fields"], [data-role="available"]');
			if (!list || !dragging) { return; }

			event.preventDefault();
			list.classList.add('is-over');

			var before = chipAfter(list, event.clientY);

			if (before) { list.insertBefore(dragging, before); }
			else { list.appendChild(dragging); }
		});

		editor.addEventListener('dragleave', function (event) {
			var list = event.target.closest('[data-role="section-fields"], [data-role="available"]');
			if (list) { list.classList.remove('is-over'); }
		});

		editor.addEventListener('drop', function (event) {
			event.preventDefault();

			Array.prototype.forEach.call(editor.querySelectorAll('.is-over'), function (list) {
				list.classList.remove('is-over');
			});

			syncAll();
		});

		// Keyboard equivalent. A layout editor reachable only by mouse would
		// lock out anyone who cannot use one, and this is the whole feature.
		editor.addEventListener('keydown', function (event) {
			var chip = event.target.closest('[data-role="chip"]');
			if (!chip) { return; }

			var list = chip.parentNode;
			var handled = true;

			if (event.key === 'ArrowUp' && chip.previousElementSibling) {
				list.insertBefore(chip, chip.previousElementSibling);
			} else if (event.key === 'ArrowDown' && chip.nextElementSibling) {
				list.insertBefore(chip.nextElementSibling, chip);
			} else if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
				var lists = Array.prototype.slice.call(
					editor.querySelectorAll('[data-role="section-fields"], [data-role="available"]')
				);
				var index = lists.indexOf(list);
				var target = lists[index + (event.key === 'ArrowRight' ? 1 : -1)];

				if (target) { target.appendChild(chip); }
			} else {
				handled = false;
			}

			if (handled) {
				event.preventDefault();
				syncAll();
				chip.focus();
			}
		});

		var addSection = document.querySelector('[data-role="add-section"]');

		if (addSection) {
			addSection.addEventListener('click', function () {
				var sections = editor.querySelector('[data-role="sections"]');
				var index = sections.querySelectorAll('[data-role="section"]').length;
				var object = editor.dataset.object;
				var base = 'pcm_crm_layouts[' + object + '][' + index + ']';

				var section = document.createElement('div');
				section.className = 'pcm-crm-layout-section';
				section.dataset.role = 'section';
				section.innerHTML =
					'<div class="pcm-crm-layout-section-head">' +
					'<input type="text" name="' + base + '[title]" placeholder="Section heading (optional)">' +
					'<button type="button" class="button-link pcm-crm-field-remove" data-role="remove-section" aria-label="Remove section">&times;</button>' +
					'</div>' +
					'<div class="pcm-crm-layout-list" data-role="section-fields" data-name="' + base + '[fields][]"></div>';

				sections.appendChild(section);
				section.querySelector('input').focus();
			});
		}

		editor.addEventListener('click', function (event) {
			if (!event.target.closest('[data-role="remove-section"]')) { return; }

			event.preventDefault();

			var section = event.target.closest('[data-role="section"]');
			var available = editor.querySelector('[data-role="available"]');

			// The fields go back to Available rather than away with the
			// section — removing a heading should not quietly remove six
			// fields from the form.
			Array.prototype.forEach.call(section.querySelectorAll('[data-role="chip"]'), function (chip) {
				available.appendChild(chip);
			});

			section.remove();
			syncAll();
		});
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
