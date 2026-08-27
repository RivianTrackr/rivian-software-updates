/**
 * Rivian Account settings screen — connect flow, vehicle mapping, manual poll.
 *
 * Talks to the rsu_rivian_* admin-ajax endpoints. The password only ever
 * travels from this form to the server on the one Connect request; it is
 * cleared from the DOM as soon as the request is sent.
 */
(function () {
	'use strict';

	var cfg = window.RSU_RIVIAN || {};

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	// ── Feedback banner ──
	function notify(message, type) {
		var host = qs('#rsu-rivian-feedback');
		if (!host) return;

		host.innerHTML = '';

		var el = document.createElement('div');
		el.className = 'rsu-notice rsu-notice--' + (type || 'info');
		el.textContent = message;
		el.style.marginBottom = '20px';
		host.appendChild(el);

		if (type === 'success') {
			host.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	// ── Transport ──
	function post(action, fields) {
		var body = new FormData();
		body.append('action', 'rsu_rivian_' + action);
		body.append('nonce', cfg.nonce || '');

		Object.keys(fields || {}).forEach(function (key) {
			var value = fields[key];
			if (value && typeof value === 'object') {
				Object.keys(value).forEach(function (inner) {
					body.append(key + '[' + inner + ']', value[inner]);
				});
			} else {
				body.append(key, value);
			}
		});

		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					var msg = (json && json.data && json.data.message)
						? json.data.message
						: 'Something went wrong.';
					throw new Error(msg);
				}
				return json.data || {};
			});
	}

	// Run an async action with a button locked out for its duration.
	function withBusy(btn, label, work) {
		if (!btn) return work();

		var original = btn.textContent;
		btn.disabled = true;
		btn.textContent = label;

		return work().then(
			function (value) {
				btn.disabled = false;
				btn.textContent = original;
				return value;
			},
			function (err) {
				btn.disabled = false;
				btn.textContent = original;
				throw err;
			}
		);
	}

	function reload() {
		window.setTimeout(function () { window.location.reload(); }, 700);
	}

	// ── Connect: email + password ──
	var loginBtn = qs('#rsu-rivian-login');
	if (loginBtn) {
		loginBtn.addEventListener('click', function () {
			var emailEl = qs('#rsu-rivian-email');
			var passEl = qs('#rsu-rivian-password');
			var email = emailEl ? emailEl.value.trim() : '';
			var password = passEl ? passEl.value : '';

			if (!email || !password) {
				notify('Enter your Rivian email and password.', 'error');
				return;
			}

			withBusy(loginBtn, 'Connecting…', function () {
				return post('login', { email: email, password: password });
			})
				.then(function (data) {
					if (passEl) passEl.value = '';

					if (data.mfa) {
						var loginStep = qs('#rsu-rivian-login-step');
						var otpStep = qs('#rsu-rivian-otp-step');
						if (loginStep) loginStep.hidden = true;
						if (otpStep) {
							otpStep.hidden = false;
							var otp = qs('#rsu-rivian-otp');
							if (otp) otp.focus();
						}
						notify(data.message, 'info');
						return;
					}

					notify(data.message, 'success');
					reload();
				})
				.catch(function (err) { notify(err.message, 'error'); });
		});
	}

	// ── Connect: one-time code ──
	var verifyBtn = qs('#rsu-rivian-verify');
	if (verifyBtn) {
		var submitOtp = function () {
			var codeEl = qs('#rsu-rivian-otp');
			var code = codeEl ? codeEl.value.trim() : '';

			if (!code) {
				notify('Enter the verification code.', 'error');
				return;
			}

			withBusy(verifyBtn, 'Verifying…', function () {
				return post('otp', { code: code });
			})
				.then(function (data) {
					notify(data.message, 'success');
					reload();
				})
				.catch(function (err) { notify(err.message, 'error'); });
		};

		verifyBtn.addEventListener('click', submitOtp);

		var otpInput = qs('#rsu-rivian-otp');
		if (otpInput) {
			otpInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					submitOtp();
				}
			});
		}
	}

	// ── Abandon a half-finished MFA login ──
	var restartBtn = qs('#rsu-rivian-restart');
	if (restartBtn) {
		restartBtn.addEventListener('click', function () {
			withBusy(restartBtn, 'Resetting…', function () {
				return post('disconnect', {});
			})
				.then(function () { window.location.reload(); })
				.catch(function (err) { notify(err.message, 'error'); });
		});
	}

	// ── Disconnect ──
	var disconnectBtn = qs('#rsu-rivian-disconnect');
	if (disconnectBtn) {
		disconnectBtn.addEventListener('click', function () {
			if (!window.confirm('Disconnect the Rivian account and stop checking for updates?')) {
				return;
			}

			withBusy(disconnectBtn, 'Disconnecting…', function () {
				return post('disconnect', {});
			})
				.then(function (data) {
					notify(data.message, 'success');
					reload();
				})
				.catch(function (err) { notify(err.message, 'error'); });
		});
	}

	// ── Save vehicle mapping ──
	var saveMapBtn = qs('#rsu-rivian-save-map');
	if (saveMapBtn) {
		saveMapBtn.addEventListener('click', function () {
			var map = {};
			Array.prototype.forEach.call(document.querySelectorAll('.rsu-rivian-map'), function (select) {
				if (select.value) {
					map[select.getAttribute('data-vehicle-id')] = select.value;
				}
			});

			withBusy(saveMapBtn, 'Saving…', function () {
				return post('save_map', { map: map });
			})
				.then(function (data) { notify(data.message, 'success'); })
				.catch(function (err) { notify(err.message, 'error'); });
		});
	}

	// ── Manual poll ──
	var pollBtn = qs('#rsu-rivian-poll-now');
	if (pollBtn) {
		pollBtn.addEventListener('click', function () {
			withBusy(pollBtn, 'Checking…', function () {
				return post('poll_now', {});
			})
				.then(function (data) {
					notify(data.message, data.found ? 'success' : 'info');
					if (data.found) reload();
				})
				.catch(function (err) { notify(err.message, 'error'); });
		});
	}
})();
