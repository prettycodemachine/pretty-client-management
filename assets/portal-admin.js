/**
 * Invite to Portal — the one thing the Client Portal module adds to the CRM
 * admin proper. A record action on the Contact page, via
 * registerRecordActions(), the same shape registerRecordTabs() already gives
 * a module for adding a tab, so nothing here needs to touch crm.js itself.
 */
(function (window, document) {
	'use strict';

	var app = window.PCM_CRM_App;
	if (!app) { return; }

	var el = app.helpers.el;

	// Shared by every action below: disable while in flight, restore and alert
	// on failure, reload the record (which is what flips the button set once
	// portal_user_id changes) on success.
	function portalAction(record, helpers, button, route, busyText, restText) {
		button.disabled = true;
		button.textContent = busyText;

		helpers.api('/contacts/' + record.id + '/' + route, { method: 'POST' }).then(function () {
			helpers.reloadRecord();
		}).catch(function (error) {
			button.disabled = false;
			button.textContent = restText;
			window.alert(error.message);
		});
	}

	app.registerRecordActions('contacts', function (record, helpers) {
		if (Number(record.portal_user_id)) {
			return [
				el('span.pcm-crm-muted', { text: 'Portal access sent' }),
				el('button.pcm-btn.pcm-btn-sm', {
					type: 'button',
					text: 'Resend invite',
					onclick: function (event) {
						portalAction(record, helpers, event.target, 'invite-portal', 'Sending…', 'Resend invite');
					}
				}),
				el('button.pcm-btn.pcm-btn-sm.pcm-btn-danger', {
					type: 'button',
					text: 'Remove portal access',
					onclick: function (event) {
						if (!window.confirm('Remove this contact’s portal access? They will no longer be able to see anything in their client portal. You can invite them again later.')) { return; }
						portalAction(record, helpers, event.target, 'revoke-portal', 'Removing…', 'Remove portal access');
					}
				})
			];
		}

		return [el('button.pcm-btn.pcm-btn-sm', {
			type: 'button',
			text: 'Invite to Portal',
			onclick: function (event) {
				portalAction(record, helpers, event.target, 'invite-portal', 'Sending…', 'Invite to Portal');
			}
		})];
	});
})(window, document);
