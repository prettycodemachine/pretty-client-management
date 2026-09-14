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

	app.registerRecordActions('contacts', function (record, helpers) {
		if (Number(record.portal_user_id)) {
			return [el('span.pcm-crm-muted', { text: 'Portal access sent' })];
		}

		return [el('button.pcm-btn.pcm-btn-sm', {
			type: 'button',
			text: 'Invite to Portal',
			onclick: function (event) {
				var button = event.target;
				button.disabled = true;
				button.textContent = 'Sending…';

				helpers.api('/contacts/' + record.id + '/invite-portal', { method: 'POST' }).then(function () {
					helpers.reloadRecord();
				}).catch(function (error) {
					button.disabled = false;
					button.textContent = 'Invite to Portal';
					window.alert(error.message);
				});
			}
		})];
	});
})(window, document);
