(function () {
	'use strict';

	if (!window.mksddnReddyAuthLogin) {
		return;
	}

	var config = window.mksddnReddyAuthLogin;
	var formRoot = document.querySelector('[data-mksddn-reddy-auth-form="1"]');

	if (!formRoot || !config.intentStatusUrl || !config.completeIntentUrl) {
		return;
	}

	var pollTimer = null;
	var completing = false;
	var currentInterval = Number(config.pollIntervalMs) || 3000;
	var maxInterval = Number(config.maxPollIntervalMs) || 15000;
	var backoffFactor = Number(config.pollBackoffFactor) || 2;
	var expiresAt = Number(config.expiresAt) || 0;
	var intentId = config.intentId || '';
	var intentSecret = config.intentSecret || '';

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

	function showMessage(text) {
		if (!text) {
			return;
		}

		var message = formRoot.querySelector('.mksddn-reddy-auth-message');
		if (!message) {
			message = document.createElement('p');
			message.className = 'mksddn-reddy-auth-message';
			formRoot.insertBefore(message, formRoot.firstChild);
		}

		message.textContent = text;
	}

	function stopPolling() {
		if (pollTimer) {
			window.clearTimeout(pollTimer);
			pollTimer = null;
		}
	}

	function schedulePoll(delayMs) {
		stopPolling();
		pollTimer = window.setTimeout(pollIntentStatus, delayMs);
	}

	function hasExpired() {
		if (!expiresAt) {
			return false;
		}

		return Math.floor(Date.now() / 1000) >= expiresAt;
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
				intent_id: intentId,
				intent_secret: intentSecret,
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
					showMessage(config.errorText || 'Unable to process authentication request.');
					if (result && result.payload && (result.payload.code === 'invalid_intent' || result.payload.code === 'intent_context_mismatch')) {
						stopPolling();
						return;
					}

					schedulePoll(currentInterval);
					return;
				}

				stopPolling();

				hideForms();
				window.location.href = config.redirectUrl || window.location.href;
			})
			.catch(function () {
				completing = false;
				showMessage(config.errorText || 'Unable to process authentication request.');
				schedulePoll(currentInterval);
			});
	}

	function pollIntentStatus() {
		if (hasExpired()) {
			showMessage(config.errorText || 'Unable to process authentication request.');
			stopPolling();
			return;
		}

		var statusRequest = {
			method: 'GET',
			credentials: 'same-origin'
		};

		if (intentId && intentSecret) {
			statusRequest.method = 'POST';
			statusRequest.headers = {
				'Content-Type': 'application/json'
			};
			statusRequest.body = JSON.stringify({
				intent_id: intentId,
				intent_secret: intentSecret
			});
		}

		fetch(config.intentStatusUrl, statusRequest)
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					if (payload && (payload.code === 'invalid_intent' || payload.code === 'intent_context_mismatch')) {
						showMessage(config.errorText || 'Unable to process authentication request.');
						stopPolling();
						return;
					}

					currentInterval = Math.min(maxInterval, currentInterval * backoffFactor);
					schedulePoll(currentInterval);
					return;
				}

				if (payload.status === 'approved') {
					completeIntent();
					return;
				}

				currentInterval = Number(config.pollIntervalMs) || 3000;
				schedulePoll(currentInterval);
			})
			.catch(function () {
				currentInterval = Math.min(maxInterval, currentInterval * backoffFactor);
				schedulePoll(currentInterval);
			});
	}

	pollIntentStatus();
})();
