/* Secure Login Shield — admin (Media Library logo picker + copy button) */
(function ($) {
	'use strict';

	$(function () {
		// === Media Library logo picker =================================
		var frame;
		var $idInput = $('#tssl-login-logo-id');
		var $preview = $('#tssl-login-logo-preview');
		var $remove = $('#tssl-remove-logo');

		$('#tssl-upload-logo').on('click', function (event) {
			event.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: (window.TSSLAdmin && TSSLAdmin.mediaTitle) || 'Select Login Logo',
				button: {
					text: (window.TSSLAdmin && TSSLAdmin.mediaButton) || 'Use this image'
				},
				multiple: false,
				library: { type: 'image' }
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				$idInput.val(attachment.id);
				$preview.html(
					$('<img />', {
						src: attachment.url,
						css: { maxWidth: '220px', height: 'auto' },
						alt: ''
					})
				);
				$remove.show();
			});

			frame.open();
		});

		$remove.on('click', function (event) {
			event.preventDefault();
			$idInput.val('');
			$preview.empty();
			$(this).hide();
		});

		// === Copy-to-clipboard buttons =================================
		document.querySelectorAll('.tssl-copy').forEach(function (btn) {
			btn.addEventListener('click', function (event) {
				event.preventDefault();
				var targetSel = btn.getAttribute('data-tssl-copy-target');
				if (!targetSel) return;
				var target = document.querySelector(targetSel);
				if (!target) return;
				var text = target.textContent || target.value || '';
				var doneLabel = btn.getAttribute('data-tssl-copied-label') || 'Copied!';
				var resetLabel = btn.getAttribute('data-tssl-copy-label') || btn.textContent;
				var finish = function () {
					var originalLabel = btn.textContent;
					btn.textContent = doneLabel;
					btn.classList.add('is-copied');
					setTimeout(function () {
						btn.textContent = resetLabel || originalLabel;
						btn.classList.remove('is-copied');
					}, 1500);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(finish).catch(function () {
						fallback(text, finish);
					});
				} else {
					fallback(text, finish);
				}
			});
		});

		function fallback(text, done) {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); } catch (e) { /* ignore */ }
			document.body.removeChild(ta);
			done();
		}

		// Toggle helper — sets both the `hidden` attribute (for a11y) and
		// inline `display:none` (so grid/flex rows actually disappear,
		// which the `hidden` attribute alone can't guarantee against a
		// `.tssl-setting-row { display: grid }` rule).
		function tsslShow(el, show) {
			if (show) {
				el.removeAttribute('hidden');
				el.removeAttribute('style');
			} else {
				el.setAttribute('hidden', '');
				el.setAttribute('style', 'display:none');
			}
		}

		// === CAPTCHA provider switcher (progressive disclosure) =======
		var providerSelect = document.getElementById('tssl-captcha-provider');
		if (providerSelect) {
			var panels = document.querySelectorAll('.tssl-provider-fields');
			var applyProvider = function () {
				var v = providerSelect.value;
				panels.forEach(function (p) {
					tsslShow(p, p.getAttribute('data-provider') === v);
				});
			};
			providerSelect.addEventListener('change', applyProvider);
			applyProvider();
		}

		// === reCAPTCHA mode switcher (inside the Google panel) ========
		var modeSelect = document.getElementById('tssl-recaptcha-mode');
		if (modeSelect) {
			var modePanels = document.querySelectorAll('.tssl-mode-panel');
			var applyMode = function () {
				var v = modeSelect.value;
				modePanels.forEach(function (p) {
					tsslShow(p, p.getAttribute('data-mode') === v);
				});
			};
			modeSelect.addEventListener('change', applyMode);
			applyMode();
		}

		// === Branding fields toggle ===================================
		var brandingCb = document.getElementById('tssl-enable-branding');
		if (brandingCb) {
			var brandingRows = document.querySelectorAll('.tssl-conditional-branding');
			var applyBranding = function () {
				brandingRows.forEach(function (row) { tsslShow(row, brandingCb.checked); });
			};
			brandingCb.addEventListener('change', applyBranding);
			applyBranding();
		}

		// === Blocked-behavior custom URL toggle =======================
		var behaviorSel = document.getElementById('tssl-blocked-behavior');
		if (behaviorSel) {
			var customRows = document.querySelectorAll('.tssl-conditional-redirect-custom');
			var applyBehavior = function () {
				customRows.forEach(function (row) {
					tsslShow(row, behaviorSel.value === 'redirect_custom_url');
				});
			};
			behaviorSel.addEventListener('change', applyBehavior);
			applyBehavior();
		}

		// === Tooltip portal ============================================
		// The cards use `overflow: hidden` for clean rounded corners, which
		// would otherwise clip absolutely-positioned tooltips at the card
		// boundary. We portal the tooltip to <body> and position it with
		// fixed coordinates so it always escapes every container, then clamp
		// it to the viewport.
		(function () {
			var TIP_GAP = 8;
			var TIP_PAD = 8;
			var TIP_MAX_WIDTH = 280;

			var tipEl = null;
			var currentIcon = null;

			function ensureTipEl() {
				if (tipEl) return tipEl;
				tipEl = document.createElement('div');
				tipEl.className = 'tssl-help-tip-floating';
				tipEl.setAttribute('role', 'tooltip');
				tipEl.setAttribute('aria-hidden', 'true');
				tipEl.style.display = 'none';
				document.body.appendChild(tipEl);
				return tipEl;
			}

			function positionTip(icon) {
				var tip = ensureTipEl();
				tip.style.maxWidth = TIP_MAX_WIDTH + 'px';
				tip.style.left = '0px';
				tip.style.top = '0px';
				tip.style.visibility = 'hidden';
				tip.style.display = 'block';

				var iconRect = icon.getBoundingClientRect();
				var tipRect = tip.getBoundingClientRect();
				var viewportW = window.innerWidth || document.documentElement.clientWidth;
				var viewportH = window.innerHeight || document.documentElement.clientHeight;

				var iconCenterX = iconRect.left + iconRect.width / 2;

				// Default: above the icon, centered.
				var top = iconRect.top - tipRect.height - TIP_GAP;
				var left = iconCenterX - tipRect.width / 2;
				var placement = 'top';

				// Flip below if there isn't room above.
				if (top < TIP_PAD) {
					top = iconRect.bottom + TIP_GAP;
					placement = 'bottom';
				}
				// If even below would clip, snap to the closer edge.
				if (top + tipRect.height > viewportH - TIP_PAD) {
					top = Math.max(TIP_PAD, viewportH - tipRect.height - TIP_PAD);
				}

				// Clamp horizontally.
				if (left < TIP_PAD) left = TIP_PAD;
				if (left + tipRect.width > viewportW - TIP_PAD) {
					left = Math.max(TIP_PAD, viewportW - tipRect.width - TIP_PAD);
				}

				// Arrow always points at the icon's horizontal center.
				var arrowLeft = iconCenterX - left;
				arrowLeft = Math.max(10, Math.min(arrowLeft, tipRect.width - 10));
				tip.style.setProperty('--tssl-arrow-x', arrowLeft + 'px');

				tip.style.left = left + 'px';
				tip.style.top = top + 'px';
				tip.setAttribute('data-placement', placement);
				tip.style.visibility = '';
			}

			function showTip(icon) {
				var text = icon.getAttribute('data-tssl-tip') || icon.getAttribute('aria-label') || '';
				if (!text) return;
				currentIcon = icon;
				var tip = ensureTipEl();
				tip.textContent = text;
				positionTip(icon);
			}

			function hideTip() {
				currentIcon = null;
				if (tipEl) {
					tipEl.style.display = 'none';
					tipEl.removeAttribute('data-placement');
				}
			}

			function reposition() {
				if (currentIcon) positionTip(currentIcon);
			}

			document.querySelectorAll('.tssl-help').forEach(function (icon) {
				icon.addEventListener('mouseenter', function () { showTip(icon); });
				icon.addEventListener('mouseleave', hideTip);
				icon.addEventListener('focus', function () { showTip(icon); });
				icon.addEventListener('blur', hideTip);
			});
			window.addEventListener('scroll', reposition, true);
			window.addEventListener('resize', reposition);
			// Hide on Escape for keyboard users.
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') hideTip();
			});
		})();
	});
})(jQuery);
