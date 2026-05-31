/* Secure Login Shield — reCAPTCHA v3 form handler.
 *
 * Intercepts a tssl-login-form submission, asks Google for a fresh v3 token
 * scoped to the current step's action, writes the token into the hidden
 * `g-recaptcha-response` input, then submits the form. A submit-lock flag
 * prevents the user from triggering a second execution mid-flight.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		document.addEventListener(
			'submit',
			function (event) {
				var form = event.target;
				if (!form || !form.classList || !form.classList.contains('tssl-login-form')) {
					return;
				}
				var wrapper = form.querySelector('.tssl-recaptcha-v3');
				if (!wrapper) {
					return;
				}
				if (form.dataset.tsslV3Sent === '1') {
					return;
				}

				var siteKey = wrapper.getAttribute('data-sitekey') || '';
				var action = wrapper.getAttribute('data-action') || 'tssl_login';
				if (!siteKey || typeof window.grecaptcha === 'undefined' || !window.grecaptcha.execute) {
					form.dataset.tsslV3Sent = '1';
					return;
				}

				event.preventDefault();
				form.dataset.tsslV3Sent = '1';

				window.grecaptcha.ready(function () {
					window.grecaptcha
						.execute(siteKey, { action: action })
						.then(function (token) {
							var input = form.querySelector('input[name="g-recaptcha-response"]');
							if (input) {
								input.value = token;
							}
							form.submit();
						})
						.catch(function () {
							form.submit();
						});
				});
			},
			true
		);
	});
})();
