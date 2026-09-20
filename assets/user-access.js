/**
 * CRM Access on WordPress's own Add New User / Edit User screens
 * (includes/user-access.php) — reveals the row and opens the dialog the
 * moment Role is set to Staff, and keeps the row's own summary text in sync
 * with whatever is actually checked inside the dialog.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var role = document.getElementById('role');
		var row = document.getElementById('pcm-crm-user-access-row');
		var dialog = document.getElementById('pcm-crm-user-access-dialog');

		if (!role || !row || !dialog) {
			return;
		}

		var staffRole = row.dataset.staffRole;
		var openButton = document.getElementById('pcm-crm-user-access-open');
		var doneButton = document.getElementById('pcm-crm-user-access-done');
		var summary = document.getElementById('pcm-crm-user-access-summary');

		function fieldLabel(input) {
			var label = input.closest('label');
			if (!label) { return ''; }
			var strong = label.querySelector('strong');
			return strong ? strong.textContent.trim() : label.textContent.trim();
		}

		function updateSummary() {
			var profile = dialog.querySelector('input[name="profile"]:checked');
			var profileText = profile && profile.value ? fieldLabel(profile) : 'None — no CRM access';

			var setLabels = Array.prototype.map.call(
				dialog.querySelectorAll('input[name="sets[]"]:checked'),
				fieldLabel
			);

			summary.textContent = 'Profile: ' + profileText +
				(setLabels.length ? ' · Permission Extensions: ' + setLabels.join(', ') : '');
		}

		function openDialog() {
			if (typeof dialog.showModal === 'function') {
				dialog.showModal();
			} else {
				// A browser with no <dialog> support at all falls back to a
				// plain block — the fields are still there, still part of
				// the same form, just not floated over the page.
				dialog.setAttribute('open', 'open');
			}
		}

		function syncRow(openIfStaff) {
			if (role.value === staffRole) {
				row.hidden = false;
				if (openIfStaff) { openDialog(); }
			} else {
				row.hidden = true;
			}
		}

		role.addEventListener('change', function () { syncRow(true); });

		// A page load is not a role *change* — no 'change' event fires just
		// because the browser restored a select's value — but it can still
		// arrive with Staff already selected: editing an existing staff
		// member (server-rendered that way from the start, so this is
		// usually a no-op), or user-new.php re-rendering the same request
		// after a validation error (sanitize_user_field(), say) with every
		// posted field, Role included, intact. Without this, that reload
		// would show Role: Staff with the row that is supposed to explain it
		// hidden. Never auto-opens the dialog here, only on an actual
		// change — a staff member's existing assignment should not pop a
		// modal simply for being looked at.
		syncRow(false);

		if (openButton) {
			openButton.addEventListener('click', openDialog);
		}

		if (doneButton) {
			doneButton.addEventListener('click', function () {
				updateSummary();
				if (typeof dialog.close === 'function') {
					dialog.close();
				} else {
					dialog.removeAttribute('open');
				}
			});
		}

		// Esc closes a showModal() dialog natively and fires 'close', which
		// updateSummary() is already listening for below. A backdrop click
		// does not close a native <dialog> on its own, though — only a click
		// that lands on the dialog element itself (never its content, which
		// stops the click from bubbling this far) can be the backdrop.
		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) {
				updateSummary();
				dialog.close();
			}
		});

		dialog.addEventListener('close', updateSummary);

		updateSummary();
	});
}());
