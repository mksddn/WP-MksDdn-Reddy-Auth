(function () {
	'use strict';

	if (!window.mksddnReddyAuthLogin) {
		return;
	}

	var config = window.mksddnReddyAuthLogin;
	var formRoot = document.querySelector('[data-mksddn-reddy-auth-form="1"]');

	if (!formRoot || !config.intentId || !config.intentSecret) {
		return;
	}

	var pollTimer = null;
	var completing = false;

	function buildQuery(params) {
		return Object.keys(params)
			.map(function (key) {
				return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
			})
			.join('&');
	}

	function hideForms() {
		var sendForm = formRoot.querySelector('.mksddn-reddy-auth-send-form');
		var loginForm = formRoot.querySelector('.mksddn-reddy-auth-login-form');
		var waiting = formRoot.querySelector('.mksddn-reddy-auth-waiting');

		if (sendForm) {
			sendForm.style.display = 'none';
		}

		if (loginForm) {
			loginForm.style.display = 'none';
		}

		if (waiting) {
			waiting.textContent = config.redirectingText || 'Authorization confirmed. Redirecting...';
		}
	}

	function completeIntent() {
		if (completing) {
			return;
		}

		completing = true;

		fetch(config.completeIntentUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify({
				intent_id: config.intentId,
				intent_secret: config.intentSecret,
				issue_session: true
			})
		})
			.then(function (response) {
				return response.json().then(function (payload) {
					return {
						ok: response.ok,
						payload: payload
					};
				});
			})
			.then(function (result) {
				if (!result.ok || !result.payload || !result.payload.success) {
					completing = false;
					return;
				}

				if (pollTimer) {
					window.clearInterval(pollTimer);
				}

				hideForms();
				window.location.href = config.redirectUrl || window.location.href;
			})
			.catch(function () {
				completing = false;
			});
	}

	function pollIntentStatus() {
		var url = config.intentStatusUrl + '?' + buildQuery({
			intent_id: config.intentId,
			intent_secret: config.intentSecret
		});

		fetch(url, {
			method: 'GET',
			credentials: 'same-origin'
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					return;
				}

				if (payload.status === 'approved') {
					completeIntent();
				}
			})
			.catch(function () {
				// Keep polling on transient network errors.
			});
	}

	pollIntentStatus();
	pollTimer = window.setInterval(pollIntentStatus, config.pollIntervalMs || 3000);
})();
