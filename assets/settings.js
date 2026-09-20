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
		logoPicker('logo', 'Choose an email logo', 'Use this logo');
		logoPicker('employee-portal-logo', 'Choose a logo', 'Use this logo');
		logoPicker('client-portal-logo', 'Choose a logo', 'Use this logo');
		fieldBuilder();
		mergeFields();
		copyShortcode();
		customFieldDialog();
		layoutEditor();
	}

	/* -------------------------------------------------------------------
	   Custom fields — the Add/Edit dialog on the Fields & Layouts tab.

	   Each save (and each delete) is its own ordinary form submission to
	   admin-post.php (pcm_crm_handle_save_custom_field() /
	   _delete_custom_field(), includes/custom-fields.php) — a real page
	   navigation, not fetch/AJAX — which is what lets one field's own
	   dialog carry its own Save button instead of the big layout form's,
	   all the way at the bottom of the page.
	   ------------------------------------------------------------------- */

	function customFieldDialog() {
		var dialog = document.getElementById('pcm-crm-custom-field-dialog');
		if (!dialog) { return; }

		var title = dialog.querySelector('[data-role="custom-field-dialog-title"]');
		var existingKeyInput = dialog.querySelector('[data-role="custom-field-existing-key"]');
		var labelInput = dialog.querySelector('[data-role="custom-field-label"]');
		var typeSelect = dialog.querySelector('[data-role="custom-field-type"]');
		var keyDisplay = dialog.querySelector('[data-role="custom-field-key-display"]');
		var optionsRow = dialog.querySelector('[data-role="custom-field-options-row"]');
		var optionsInput = dialog.querySelector('[data-role="custom-field-options"]');
		var relatedRow = dialog.querySelector('[data-role="custom-field-related-row"]');
		var relatedSelect = dialog.querySelector('[data-role="custom-field-related"]');
		var cancelButton = dialog.querySelector('[data-role="custom-field-cancel"]');
		var deleteForm = document.getElementById('pcm-crm-delete-custom-field-form');

		function syncRows() {
			optionsRow.hidden = typeSelect.value !== 'picklist';
			relatedRow.hidden = typeSelect.value !== 'relationship';
		}

		function openDialog(custom) {
			dialog.querySelector('form').reset();

			if (custom) {
				title.textContent = 'Edit custom field';
				existingKeyInput.value = custom.key;
				labelInput.value = custom.label;
				typeSelect.value = custom.type;
				// Fixed once created — the column's SQL type already matches
				// the original, and the server ignores a posted type here
				// regardless, but disabling it is what tells a person that.
				typeSelect.disabled = true;
				keyDisplay.hidden = false;
				keyDisplay.textContent = 'cf_' + custom.key;
				optionsInput.value = (custom.options || []).join('\n');
				if (custom.related) { relatedSelect.value = custom.related; }
			} else {
				title.textContent = 'Add custom field';
				existingKeyInput.value = '';
				typeSelect.disabled = false;
				keyDisplay.hidden = true;
			}

			syncRows();
			dialog.showModal();
			labelInput.focus();
		}

		typeSelect.addEventListener('change', syncRows);

		var addButton = document.querySelector('[data-role="add-custom-field"]');
		if (addButton) {
			addButton.addEventListener('click', function () { openDialog(null); });
		}

		document.addEventListener('click', function (event) {
			var editButton = event.target.closest('[data-role="edit-custom-field"]');
			if (editButton) {
				var chip = editButton.closest('[data-role="chip"]');
				var custom = chip && chip.dataset.custom ? JSON.parse(chip.dataset.custom) : null;
				if (custom) { openDialog(custom); }
				return;
			}

			var deleteButton = event.target.closest('[data-role="delete-custom-field"]');
			if (deleteButton && deleteForm) {
				var deleteChip = deleteButton.closest('[data-role="chip"]');
				var deleteCustom = deleteChip && deleteChip.dataset.custom ? JSON.parse(deleteChip.dataset.custom) : null;
				if (!deleteCustom) { return; }

				// Removing a definition hides the field; the column and its
				// data stay, but it disappears from every layout and the
				// filter builder until re-added.
				if (!window.confirm('Delete this field? Its column and data are kept, but it disappears from every layout and filter until re-added.')) { return; }

				deleteForm.querySelector('[data-role="delete-custom-field-key"]').value = deleteCustom.key;
				deleteForm.submit();
			}
		});

		cancelButton.addEventListener('click', function () { dialog.close(); });

		// Native <dialog> does not close on a backdrop click by itself.
		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) { dialog.close(); }
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
			// A chip's own Edit/Delete buttons must behave like buttons, not
			// like the start of a drag on the chip they happen to sit inside.
			if (event.target.closest('[data-role="edit-custom-field"], [data-role="delete-custom-field"]')) {
				event.preventDefault();
				return;
			}

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
	   Logo pickers — the email logo (Contact Form tab) and the PCM
	   Settings band logo (Branding tab) are the same control against two
	   different data-role prefixes, so one function serves both rather
	   than two near-identical copies drifting apart.
	   ------------------------------------------------------------------- */

	function logoPicker(role, title, buttonText) {
		var root = document.querySelector('[data-role="' + role + '"]');
		if (!root || !window.wp || !window.wp.media) { return; }

		var preview = root.querySelector('[data-role="' + role + '-preview"]');
		var field = root.querySelector('[data-role="' + role + '-id"]');
		var remove = root.querySelector('[data-role="' + role + '-remove"]');
		var frame = null;

		root.querySelector('[data-role="' + role + '-choose"]').addEventListener('click', function () {
			if (!frame) {
				frame = window.wp.media({
					title: title,
					button: { text: buttonText },
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
			// and whichever default this picker's field falls back to takes
			// over once the page is saved and reloaded.
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
		var targetSelect = document.querySelector('[data-role="add-field-target"]');
		var defaultsScript = document.getElementById('pcm-crm-field-target-defaults');
		var defaults = defaultsScript ? JSON.parse(defaultsScript.textContent || '{}') : {};

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

		// A question can only be added by picking a field that already exists
		// (built-in or a custom field created beforehand under Fields &
		// Layouts) — the picker prefills the new row from that field's own
		// shape, rather than starting from a blank, inventable one. Everything
		// on the row, including the label, stays freely editable afterwards.
		if (addButton && template && targetSelect) {
			addButton.addEventListener('click', function () {
				var target = targetSelect.value;
				if (!target) { targetSelect.focus(); return; }

				var wrapper = document.createElement('div');
				wrapper.innerHTML = template.innerHTML;
				var row = wrapper.querySelector('[data-role="field-row"]');

				var preset = defaults[target] || { label: '', type: 'text', options: [] };

				row.querySelector('.pcm-crm-field-label').value = preset.label || '';

				var typeSelect = row.querySelector('[data-role="field-type"]');
				typeSelect.value = preset.type || 'text';

				var mapSelect = row.querySelector('select[name$="[map]"]');
				if (mapSelect) { mapSelect.value = target; }

				var optionsRow = row.querySelector('[data-role="field-options"]');
				var optionsInput = row.querySelector('textarea[name$="[options]"]');
				var isSelect = 'select' === (preset.type || 'text');
				if (optionsRow) { optionsRow.hidden = !isSelect; }
				if (optionsInput && isSelect) { optionsInput.value = (preset.options || []).join('\n'); }

				list.appendChild(row);
				renumber();

				// Taken once — the same field cannot be added as a second,
				// separate question.
				var chosenOption = targetSelect.querySelector('option[value="' + CSS.escape(target) + '"]');
				if (chosenOption) { chosenOption.remove(); }
				targetSelect.value = '';

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
