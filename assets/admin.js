/* Nexus by Therum — admin interactions for the connector card grid */
(function() {
	'use strict';

	var cfg = window.NexusAdmin || {};
	var AJAX  = cfg.ajaxurl || (window.ajaxurl || '/wp-admin/admin-ajax.php');
	var NONCE = cfg.nonce || '';

	// ─── Card foot helpers ────────────────────────────────────────────────
	//
	// Each card has exactly ONE credential button at any time:
	//   - Connect    (primary)         when not configured
	//   - Disconnect (destructive)     when configured
	// On save success we swap Connect→Disconnect. On disconnect we swap back.
	// Open form ↔ Cancel button is independent of that swap.

	function nexusMakeConnectBtn() {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'th-button th-button-primary';
		b.setAttribute('data-conn-toggle', '');
		b.setAttribute('data-label-open', 'Connect');
		b.textContent = 'Connect';
		return b;
	}
	function nexusMakeDisconnectBtn() {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'th-button';
		b.setAttribute('data-conn-disconnect', '');
		b.style.color = 'var(--err)';
		b.style.borderColor = 'color-mix(in srgb,var(--err) 30%,transparent)';
		b.textContent = 'Disconnect';
		return b;
	}
	function nexusSwapFootButton(card, newBtn) {
		var foot = card.querySelector('.th-conn-foot-actions');
		if (!foot) return;
		var existing = foot.querySelector('[data-conn-toggle], [data-conn-disconnect]');
		if (existing) existing.replaceWith(newBtn);
		else foot.appendChild(newBtn);
	}
	function nexusSetResult(el, text, ok) {
		if (!el) return;
		el.textContent = text || '';
		el.style.color = ok ? 'var(--ok,#10b981)' : 'var(--err,#ef4444)';
		el.style.whiteSpace = 'normal';
	}

	// ─── Product feed cards (Channels section) ───────────────────────────
	//
	// Same shape as connector cards but separate handlers and a separate
	// AJAX action namespace. Toggling, saving, and disabling all flip
	// the right UI without a page reload.

	document.addEventListener('click', function(e) {
		var feedToggle = e.target.closest('[data-feed-toggle]');
		if (feedToggle) {
			var fc = feedToggle.closest('[data-feed-channel]');
			var ff = fc && fc.querySelector('[data-feed-form]');
			if (!ff) return;
			var fOpen = !ff.hidden;
			ff.hidden = fOpen;
			feedToggle.textContent = fOpen
				? (feedToggle.getAttribute('data-label-open') || 'Configure')
				: 'Cancel';
			if (!fOpen) {
				var firstInput = ff.querySelector('input,select');
				if (firstInput) firstInput.focus();
			}
			return;
		}

		var feedSave = e.target.closest('[data-feed-save]');
		if (feedSave) {
			var fc = feedSave.closest('[data-feed-channel]');
			var ch = fc && fc.getAttribute('data-feed-channel');
			if (!ch) return;
			var ff = fc.querySelector('[data-feed-form]');
			var fr = fc.querySelector('[data-feed-result]');
			var inputs = ff ? ff.querySelectorAll('[data-feed-field]') : [];

			var fd = new FormData();
			fd.append('action', 'nexus_feed_save');
			fd.append('nonce', NONCE);
			fd.append('channel', ch);
			inputs.forEach(function(el) {
				if (el.type === 'checkbox') {
					fd.append('config[' + el.getAttribute('data-feed-field') + ']', el.checked ? '1' : '');
				} else {
					fd.append('config[' + el.getAttribute('data-feed-field') + ']', el.value);
				}
			});

			feedSave.disabled = true;
			feedSave.textContent = 'Saving…';
			if (fr) { fr.textContent = ''; fr.style.color = ''; }

			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					feedSave.disabled = false;
					feedSave.textContent = 'Save & generate URL';
					if (j && j.success) {
						if (fr) { fr.textContent = '✓ ' + (j.data.message || 'Saved'); fr.style.color = 'var(--ok,#10b981)'; }
						// Reload to surface the freshly-generated feed URL + flip pill.
						setTimeout(function() { location.reload(); }, 700);
					} else {
						if (fr) { fr.textContent = '✗ ' + ((j && j.data && (j.data.message || j.data)) || 'Save failed.'); fr.style.color = 'var(--err,#ef4444)'; }
					}
				})
				.catch(function() {
					feedSave.disabled = false;
					feedSave.textContent = 'Save & generate URL';
					if (fr) { fr.textContent = '✗ Network error'; fr.style.color = 'var(--err,#ef4444)'; }
				});
			return;
		}

		// Validate a feed — runs the pipeline server-side, surfaces
		// counts + list of products missing required fields.
		var feedValidate = e.target.closest('[data-feed-validate]');
		if (feedValidate) {
			e.preventDefault();
			var ch = feedValidate.getAttribute('data-feed-validate');
			var card = feedValidate.closest('[data-feed-channel]');
			var result = card && card.querySelector('[data-feed-validate-result]');
			feedValidate.disabled = true;
			feedValidate.dataset.orig = feedValidate.textContent;
			feedValidate.textContent = 'Validating…';
			if (result) result.textContent = '';
			var fd = new FormData();
			fd.append('action', 'nexus_feed_validate');
			fd.append('nonce', NONCE);
			fd.append('channel', ch);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					feedValidate.disabled = false;
					feedValidate.textContent = feedValidate.dataset.orig || 'Validate';
					if (!result) return;
					if (!j || !j.success) {
						result.style.color = 'var(--err)';
						result.textContent = '✗ ' + ((j && j.data && j.data.message) || 'Validation failed');
						return;
					}
					var d = j.data;
					var html = '<strong>' + d.valid + ' / ' + d.total + ' items would be accepted by ' + d.channel_name + '</strong>';
					result.style.color = d.invalid === 0 ? 'var(--ok)' : 'var(--wrn)';
					if (d.invalid > 0) {
						html += '<br>' + d.invalid + ' would be rejected for missing fields:';
						html += '<div style="margin-top:6px;max-height:180px;overflow-y:auto;font-size:11px;background:var(--sf2);padding:6px;border-radius:6px;color:var(--tx2)">';
						d.failures.forEach(function(f) {
							html += '<div style="padding:2px 0">• ' + (f.id || '?') + ' <em>' + (f.title || '') + '</em> — missing: ' + (f.missing||[]).join(', ') + '</div>';
						});
						if (d.invalid > d.failures.length) html += '<div style="opacity:.6">…and ' + (d.invalid - d.failures.length) + ' more</div>';
						html += '</div>';
					}
					result.innerHTML = html;
				})
				.catch(function(){
					feedValidate.disabled = false;
					feedValidate.textContent = feedValidate.dataset.orig || 'Validate';
					if (result) { result.style.color = 'var(--err)'; result.textContent = '✗ Network error.'; }
				});
			return;
		}

		var feedCopy = e.target.closest('[data-feed-copy]');
		if (feedCopy) {
			var url = feedCopy.getAttribute('data-copy-target') || '';
			if (!url) return;
			var done = function(ok) {
				var orig = feedCopy.dataset.origLabel || feedCopy.textContent;
				if (!feedCopy.dataset.origLabel) feedCopy.dataset.origLabel = feedCopy.textContent;
				feedCopy.textContent = ok ? '✓ Copied' : '✗ Copy failed';
				setTimeout(function() { feedCopy.textContent = feedCopy.dataset.origLabel; }, 1500);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(function(){ done(true); }, function(){ done(false); });
			} else {
				// Fallback: select an offscreen input + execCommand. Old browsers.
				var ta = document.createElement('textarea');
				ta.value = url; ta.style.position = 'absolute'; ta.style.left = '-9999px';
				document.body.appendChild(ta); ta.select();
				try { done(document.execCommand('copy')); } catch (_) { done(false); }
				document.body.removeChild(ta);
			}
			return;
		}

		var feedDisable = e.target.closest('[data-feed-disable]');
		if (feedDisable) {
			var fc = feedDisable.closest('[data-feed-channel]');
			var ch = fc && fc.getAttribute('data-feed-channel');
			if (!ch || !confirm('Disable the ' + ch + ' feed? Its URL will stop returning data.')) return;
			feedDisable.disabled = true;
			feedDisable.textContent = 'Disabling…';

			var fd = new FormData();
			fd.append('action', 'nexus_feed_delete');
			fd.append('nonce', NONCE);
			fd.append('channel', ch);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					if (j && j.success) location.reload();
					else { feedDisable.disabled = false; feedDisable.textContent = 'Disable feed'; alert('Disable failed'); }
				})
				.catch(function() { feedDisable.disabled = false; feedDisable.textContent = 'Disable feed'; alert('Network error'); });
			return;
		}
	});

	// ─── OAuth Sign-in button ────────────────────────────────────────────
	//
	// Clicking [data-nexus-oauth-start] POSTs to ajax→ nexus_oauth_start
	// which returns the provider's authorize URL with state + PKCE wired
	// up. We then redirect the whole window so the provider gets a real
	// browser navigation. If the response says "needs setup" (no client
	// id/secret saved yet), we open the form and surface the OAuth
	// section so the user can paste their app credentials first.

	// Replay an inbound webhook
	document.addEventListener('click', function(e) {
		var replay = e.target.closest('[data-nexus-webhook-replay]');
		if (replay) {
			e.preventDefault();
			var id = replay.getAttribute('data-nexus-webhook-replay');
			if (!confirm('Replay this webhook event? Downstream listeners will be fired again with the original payload.')) return;
			replay.disabled = true;
			replay.dataset.orig = replay.textContent;
			replay.textContent = '…';
			var fd = new FormData();
			fd.append('action', 'nexus_webhook_replay');
			fd.append('nonce', NONCE);
			fd.append('id', id);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					replay.disabled = false;
					replay.textContent = replay.dataset.orig || 'Replay';
					if (j && j.success) { replay.textContent = '✓'; setTimeout(function(){ location.reload(); }, 600); }
					else alert((j && j.data && j.data.message) || 'Replay failed.');
				})
				.catch(function(){ replay.disabled = false; replay.textContent = replay.dataset.orig || 'Replay'; alert('Network error.'); });
			return;
		}

		var sweep = e.target.closest('[data-nexus-health-sweep]');
		if (sweep) {
			e.preventDefault();
			if (!confirm('Run the credential health check on every configured connector now? This validates each one against its provider.')) return;
			sweep.disabled = true;
			var orig = sweep.textContent;
			sweep.textContent = 'Sweeping…';
			var fd = new FormData();
			fd.append('action', 'nexus_health_check_now');
			fd.append('nonce', NONCE);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					sweep.disabled = false;
					sweep.textContent = orig;
					alert((j && j.data && j.data.message) || 'Done.');
					location.reload();
				})
				.catch(function(){ sweep.disabled = false; sweep.textContent = orig; alert('Network error.'); });
			return;
		}
	});

	document.addEventListener('click', function(e) {
		var oauthBtn = e.target.closest('[data-nexus-oauth-start]');
		if (oauthBtn) {
			e.preventDefault();
			var connectorId = oauthBtn.getAttribute('data-nexus-oauth-start');
			var card = oauthBtn.closest('[data-connector]');
			var needsSetup = oauthBtn.getAttribute('data-nexus-oauth-needs-setup') === '1';

			// If the foot button is gated (no app creds yet), expand the form
			// + focus the first OAuth field. Don't fire the AJAX yet.
			if (needsSetup && card) {
				var form = card.querySelector('[data-conn-form]');
				if (form) {
					form.hidden = false;
					var firstOauthInput = form.querySelector('[data-field="oauth_client_id"]');
					if (firstOauthInput) {
						firstOauthInput.focus();
						firstOauthInput.select && firstOauthInput.select();
					}
					var toggle = card.querySelector('.th-conn-foot-actions [data-conn-toggle]');
					if (toggle) toggle.textContent = 'Cancel';
				}
				return;
			}

			oauthBtn.disabled = true;
			var origLabel = oauthBtn.textContent;
			oauthBtn.textContent = 'Saving creds…';

			// Auto-save the form first. The AJAX endpoint reads client_id/secret
			// from the DB, not the POST body — so if the user pasted values and
			// clicked Sign in without saving, the OAuth start would fail with
			// "missing app credentials." We trigger a save here, wait for it,
			// then start OAuth. If there's no form (e.g. foot button click on a
			// fully-configured card), skip the save and go straight to OAuth.
			var saveBeforeOauth = card
				? new Promise(function(resolve) {
					var form = card.querySelector('[data-conn-form]');
					if (!form || form.hidden) return resolve();
					var inputs = form.querySelectorAll('[data-field]');
					if (!inputs.length) return resolve();
					var saveFd = new FormData();
					saveFd.append('action', 'nexus_connector_save');
					saveFd.append('nonce', NONCE);
					saveFd.append('connector', connectorId);
					inputs.forEach(function(el) {
						if (el.type === 'checkbox') {
							saveFd.append('config[' + el.getAttribute('data-field') + ']', el.checked ? '1' : '');
						} else {
							saveFd.append('config[' + el.getAttribute('data-field') + ']', el.value);
						}
					});
					fetch(AJAX, { method:'POST', credentials:'same-origin', body: saveFd })
						.then(function(){ resolve(); })
						.catch(function(){ resolve(); }); // proceed even if save errored; OAuth start will report it
				})
				: Promise.resolve();

			saveBeforeOauth.then(function() {
				oauthBtn.textContent = 'Redirecting…';
				var fd = new FormData();
				fd.append('action', 'nexus_oauth_start');
				fd.append('nonce', NONCE);
				fd.append('connector', connectorId);
				return fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd });
			})
				.then(function(r){ return r.json(); })
				.then(function(j){
					if (j && j.success && j.data && j.data.url) {
						window.location.href = j.data.url;
					} else {
						oauthBtn.disabled = false;
						oauthBtn.textContent = origLabel;
						var msg = (j && j.data && (j.data.message || j.data)) || 'Could not start OAuth.';
						alert(msg);
					}
				})
				.catch(function(){
					oauthBtn.disabled = false;
					oauthBtn.textContent = origLabel;
					alert('Network error — try again.');
				});
			return;
		}
	});

	document.addEventListener('click', function(e) {
		// Bridge-only Connect/Manage button → toggle the platform picker.
		// Picker lives outside the foot (sibling div), keyed by the closest
		// card. Independent from the normal credential form toggle.
		var bridgeBtn = e.target.closest('[data-conn-bridge-toggle]');
		if (bridgeBtn) {
			var bcard   = bridgeBtn.closest('[data-connector]');
			var picker  = bcard ? bcard.querySelector('[data-conn-bridge-picker]') : null;
			if (!picker) return;
			var bopen = !picker.hidden;
			picker.hidden = bopen;
			// Defer label flip: keep it as "Connect"/"Manage" when closed,
			// flip to "Close" when open so the action is reversible.
			if (bopen) {
				bridgeBtn.textContent = bridgeBtn.dataset.openLabel || bridgeBtn.textContent;
			} else {
				bridgeBtn.dataset.openLabel = bridgeBtn.textContent;
				bridgeBtn.textContent = 'Close';
			}
			return;
		}

		// Toggle config form (Connect button + Cancel button both carry this attr)
		var btn = e.target.closest('[data-conn-toggle]');
		if (btn) {
			var card = btn.closest('[data-connector]');
			var form = card ? card.querySelector('[data-conn-form]') : null;
			if (!form) return;
			var open = !form.hidden;
			form.hidden = open;
			btn.textContent = open ? (btn.getAttribute('data-label-open') || 'Connect') : 'Cancel';
			if (!open) {
				var first = form.querySelector('input,select,textarea');
				if (first) first.focus();
			}
			return;
		}

		// Save — validates server-side, only flips to Disconnect on success
		var save = e.target.closest('[data-conn-save]');
		if (save) {
			var card = save.closest('[data-connector]');
			var id   = card ? card.getAttribute('data-connector') : '';
			if (!id) return;
			var form   = card.querySelector('[data-conn-form]');
			var result = card.querySelector('[data-conn-result]');
			var inputs = form ? form.querySelectorAll('[data-field]') : [];

			var fd = new FormData();
			fd.append('action', 'nexus_connector_save');
			fd.append('nonce', NONCE);
			fd.append('connector', id);
			inputs.forEach(function(el) {
				if (el.type === 'checkbox') {
					fd.append('config[' + el.getAttribute('data-field') + ']', el.checked ? '1' : '');
				} else {
					fd.append('config[' + el.getAttribute('data-field') + ']', el.value);
				}
			});

			save.disabled = true;
			save.textContent = 'Connecting…';
			nexusSetResult(result, '', true);

			fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					save.disabled = false;
					save.textContent = 'Save';
					if (j && j.success) {
						var msg = (j.data && j.data.msg) || 'Connected.';
						var unvalidated = !!(j.data && j.data.unvalidated);

						// Status pill — green if live-validated, amber-ish if just stored
						var dot = card.querySelector('[data-conn-status]');
						if (dot) {
							dot.textContent = unvalidated ? 'Saved' : 'Connected';
							dot.className = 'th-conn-status ' + (unvalidated ? 'th-conn-status-off' : 'th-conn-status-connected');
						}

						// Re-mask password fields so re-opening doesn't leak typed secrets
						form.querySelectorAll('input[type="password"]').forEach(function(i){
							if (i.value !== '') i.value = '••••••••';
						});

						// Close the form, swap Connect → Disconnect
						form.hidden = true;
						nexusSwapFootButton(card, nexusMakeDisconnectBtn());

						nexusSetResult(result, '✓ ' + msg, true);
						setTimeout(function() { if (result) result.textContent = ''; }, 4000);
					} else {
						// Validation failed — keep the form open, show the real reason
						var err = (j && j.data && (j.data.message || j.data)) || 'Save failed.';
						nexusSetResult(result, '✗ ' + err, false);
					}
				})
				.catch(function() {
					save.disabled = false;
					save.textContent = 'Save';
					nexusSetResult(result, '✗ Network error — try again.', false);
				});
			return;
		}

		// Disconnect — clears creds, swaps Disconnect → Connect
		var disc = e.target.closest('[data-conn-disconnect]');
		if (disc) {
			var card = disc.closest('[data-connector]');
			var id   = card ? card.getAttribute('data-connector') : '';
			var name = (card && card.querySelector('.th-conn-name')) ? card.querySelector('.th-conn-name').firstChild.textContent.trim() : id;
			if (!id || !confirm('Disconnect ' + name + '? Saved credentials will be removed from this site.')) return;

			disc.disabled = true;
			disc.textContent = 'Disconnecting…';

			var fd = new FormData();
			fd.append('action', 'nexus_connector_delete');
			fd.append('nonce', NONCE);
			fd.append('connector', id);

			fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					if (j && j.success) {
						var dot = card.querySelector('[data-conn-status]');
						if (dot) { dot.textContent = 'Not configured'; dot.className = 'th-conn-status th-conn-status-off'; }
						var form = card.querySelector('[data-conn-form]');
						if (form) {
							form.querySelectorAll('input[type="password"],input[type="text"],input[type="url"]').forEach(function(i){ i.value = ''; });
							form.hidden = true;
						}
						nexusSwapFootButton(card, nexusMakeConnectBtn());
					} else {
						disc.disabled = false;
						disc.textContent = 'Disconnect';
						alert((j && j.data && (j.data.message || j.data)) || 'Disconnect failed.');
					}
				})
				.catch(function() {
					disc.disabled = false;
					disc.textContent = 'Disconnect';
					alert('Network error — try again.');
				});
		}
	});


	// ─── Custom connectors — Add / Edit / Delete ───────────────────────────
	//
	// `data-nexus-add-custom="cms|ecommerce|apis"` opens the modal in Add mode
	// `data-nexus-edit-custom="slug"`              opens it in Edit mode
	// `data-nexus-delete-custom="slug"`            confirms + POSTs delete
	//
	// The modal shares the page's admin-ajax nonce (window.NexusAjaxNonce).

	var modal = document.getElementById('nexus-modal');
	if (!modal) return;
	var form  = modal.querySelector('[data-nexus-custom-form]');
	var err   = modal.querySelector('[data-nexus-err]');
	var saveBtn = modal.querySelector('[data-nexus-custom-save]');
	var titleEl = modal.querySelector('#nexus-modal-title');
	var eyebrow = modal.querySelector('.nexus-modal-eyebrow');
	var slugDirty = false;

	function setField(name, val) {
		var el = form.elements[name];
		if (el) el.value = (val == null) ? '' : val;
	}
	function setError(msg) {
		if (!msg) { err.hidden = true; err.textContent = ''; return; }
		err.hidden = false;
		err.textContent = msg;
	}
	function closeModal() { modal.hidden = true; setError(null); slugDirty = false; }
	function openModal(mode, category, slug) {
		setError(null);
		form.reset();
		// Reset hidden defaults that form.reset() doesn't touch correctly.
		setField('category', category || '');
		setField('editing_slug', '');
		setField('color', '#6366f1');
		slugDirty = false;

		// Reset credential rows to defaults — row 1 = "API Key" (password),
		// rows 2-4 blank. form.reset() handles the input values, but we still
		// need to put row 1's label back since the user may have wiped it.
		var defaultLabels = ['API Key', '', '', ''];
		var defaultTypes  = ['password', 'password', 'password', 'text'];
		for (var di = 1; di <= 4; di++) {
			setField('cred_label_' + di, defaultLabels[di - 1]);
			setField('cred_type_'  + di, defaultTypes[di - 1]);
		}

		if (mode === 'edit' && slug && window.NexusCustomConnectors[slug]) {
			var c = window.NexusCustomConnectors[slug];
			setField('editing_slug', slug);
			setField('category',     c.category || category);
			setField('name',         c.name || '');
			setField('slug',         slug);
			slugDirty = true;
			setField('desc',         c.desc || '');
			setField('color',        c.color || '#6366f1');
			setField('docs',         c.docs || '');

			// Split fields[] into credential rows + base_url. Credentials are
			// anything non-base_url; we map them into the 4 rows in order.
			var baseUrl = '';
			var creds   = [];
			(c.fields || []).forEach(function(f){
				if (f.key === 'base_url') {
					baseUrl = (c.config && c.config.base_url) || f.placeholder || '';
				} else {
					creds.push(f);
				}
			});
			for (var ci = 1; ci <= 4; ci++) {
				var f = creds[ci - 1];
				setField('cred_label_' + ci, f ? (f.label || '') : '');
				setField('cred_type_'  + ci, f && f.type === 'text' ? 'text' : 'password');
			}
			setField('base_url', baseUrl);
			titleEl.textContent = 'Edit ' + c.name;
			eyebrow.textContent = 'Custom connector · Edit';
			saveBtn.textContent = 'Save changes';
		} else {
			titleEl.textContent = 'Add custom connector';
			eyebrow.textContent = 'Custom connector · ' + (category || '').toUpperCase();
			saveBtn.textContent = 'Add connector';
		}
		modal.hidden = false;
		setTimeout(function(){ var n = form.elements.name; if (n) n.focus(); }, 50);
	}

	// Click delegation — the modal can open from anywhere on the page.
	document.addEventListener('click', function(e) {
		var add = e.target.closest('[data-nexus-add-custom]');
		if (add) { openModal('add', add.getAttribute('data-nexus-add-custom')); return; }

		var edit = e.target.closest('[data-nexus-edit-custom]');
		if (edit) { openModal('edit', null, edit.getAttribute('data-nexus-edit-custom')); return; }

		var del = e.target.closest('[data-nexus-delete-custom]');
		if (del) {
			var slug = del.getAttribute('data-nexus-delete-custom');
			var label = (window.NexusCustomConnectors[slug] || {}).name || slug;
			if (!confirm('Remove the custom connector "' + label + '"? Any saved credential is also cleared.')) return;
			var fd = new FormData();
			fd.append('action', 'nexus_custom_delete');
			fd.append('nonce', window.NexusAjaxNonce);
			fd.append('connector', slug);
			fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(res){
					if (res && res.success) { location.reload(); }
					else { alert((res && res.data && res.data.message) || 'Delete failed.'); }
				})
				.catch(function(){ alert('Network error.'); });
			return;
		}

		var dismiss = e.target.closest('[data-nexus-modal-close]');
		if (dismiss && !modal.hidden) { closeModal(); return; }
	});

	// Auto-slug while typing Name until the user edits Slug themselves.
	form.elements.slug.addEventListener('input', function(){ slugDirty = true; });
	form.elements.name.addEventListener('input', function(){
		if (!slugDirty) {
			form.elements.slug.value = String(form.elements.name.value || '')
				.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').substring(0, 48);
		}
	});

	// Submit — POST to nexus_custom_add. Same handler covers add + edit.
	form.addEventListener('submit', function(e) {
		e.preventDefault();
		if (!form.elements.name.value.trim()) {
			setError('Name is required.');
			form.elements.name.focus();
			return;
		}
		saveBtn.setAttribute('disabled', '');
		saveBtn.dataset.label = saveBtn.textContent;
		saveBtn.textContent = 'Saving…';

		var fd = new FormData(form);
		fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', { method:'POST', credentials:'same-origin', body: fd })
			.then(function(r){ return r.json(); })
			.then(function(res){
				if (res && res.success) { location.reload(); return; }
				saveBtn.removeAttribute('disabled');
				saveBtn.textContent = saveBtn.dataset.label;
				setError((res && res.data && res.data.message) || 'Save failed.');
			})
			.catch(function(){
				saveBtn.removeAttribute('disabled');
				saveBtn.textContent = saveBtn.dataset.label;
				setError('Network error — try again.');
			});
	});

	// Esc to close.
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && !modal.hidden) closeModal();
	});


	// ─── Updates tab ──────────────────────────────────────────────────────
	//
	// Three actions:
	//   [data-nexus-update-check]   — refresh GitHub release info (re-render on success)
	//   [data-nexus-update-github]  — download + install from the latest release
	//   [data-nexus-update-zip]     — submit an uploaded zip for install
	//
	// On a successful install we hard-reload so the new plugin code is in play.

	function nexusUpdateMsg(el, text, ok) {
		if (!el) return;
		el.textContent = text || '';
		el.style.color = ok ? 'var(--ok,#10b981)' : 'var(--err,#ef4444)';
	}

	document.addEventListener('click', function(e) {
		var check = e.target.closest('[data-nexus-update-check]');
		if (check) {
			e.preventDefault();
			var result = check.parentElement.querySelector('[data-nexus-update-result]');
			check.disabled = true;
			check.dataset.label = check.textContent;
			check.textContent = 'Checking…';
			nexusUpdateMsg(result, '', true);

			var fd = new FormData();
			fd.append('action', 'nexus_update_check');
			fd.append('nonce', NONCE);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					check.disabled = false;
					check.textContent = check.dataset.label;
					if (j && j.success) {
						var r = j.data.release || {};
						nexusUpdateMsg(result, j.data.is_newer ? ('New: ' + r.tag) : ('Up to date (' + (r.tag || '?') + ')'), true);
						setTimeout(function(){ location.reload(); }, 700);
					} else {
						nexusUpdateMsg(result, (j && j.data && j.data.message) || 'Check failed.', false);
					}
				})
				.catch(function(){
					check.disabled = false;
					check.textContent = check.dataset.label;
					nexusUpdateMsg(result, 'Network error.', false);
				});
			return;
		}

		// Install a specific version (rollback or roll-forward)
		var installVer = e.target.closest('[data-nexus-update-install-version]');
		if (installVer) {
			e.preventDefault();
			var tag = installVer.getAttribute('data-nexus-update-install-version');
			if (!confirm('Install ' + tag + '?\n\nThis swaps the current Nexus install for ' + tag + '. A backup of the current state is taken automatically so you can roll back from "Local backups" if anything goes wrong.')) return;
			installVer.disabled = true;
			installVer.dataset.orig = installVer.textContent;
			installVer.textContent = 'Installing…';
			var fd = new FormData();
			fd.append('action', 'nexus_update_install_version');
			fd.append('nonce', NONCE);
			fd.append('tag', tag);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					installVer.disabled = false;
					installVer.textContent = installVer.dataset.orig || 'Install';
					if (j && j.success) { alert('✓ ' + (j.data.message || 'Installed.')); location.reload(); }
					else { alert('✗ ' + ((j && j.data && j.data.message) || 'Install failed.')); }
				})
				.catch(function(){ installVer.disabled = false; installVer.textContent = installVer.dataset.orig || 'Install'; alert('Network error.'); });
			return;
		}

		// Restore a local backup
		var restoreBtn = e.target.closest('[data-nexus-backup-restore]');
		if (restoreBtn) {
			e.preventDefault();
			var file = restoreBtn.getAttribute('data-nexus-backup-restore');
			if (!confirm('Restore from ' + file + '?\n\nThis swaps the current Nexus install for whatever was running when this backup was taken. The current state is snapshotted first so this restore is itself reversible.')) return;
			restoreBtn.disabled = true;
			restoreBtn.dataset.orig = restoreBtn.textContent;
			restoreBtn.textContent = 'Restoring…';
			var fd = new FormData();
			fd.append('action', 'nexus_update_restore_backup');
			fd.append('nonce', NONCE);
			fd.append('file', file);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					restoreBtn.disabled = false;
					restoreBtn.textContent = restoreBtn.dataset.orig || 'Restore';
					if (j && j.success) { alert('✓ ' + (j.data.message || 'Restored.')); location.reload(); }
					else { alert('✗ ' + ((j && j.data && j.data.message) || 'Restore failed.')); }
				})
				.catch(function(){ restoreBtn.disabled = false; restoreBtn.textContent = restoreBtn.dataset.orig || 'Restore'; alert('Network error.'); });
			return;
		}

		// Delete a local backup
		var deleteBtn = e.target.closest('[data-nexus-backup-delete]');
		if (deleteBtn) {
			e.preventDefault();
			var file = deleteBtn.getAttribute('data-nexus-backup-delete');
			if (!confirm('Delete backup ' + file + '? This cannot be undone.')) return;
			var fd = new FormData();
			fd.append('action', 'nexus_update_delete_backup');
			fd.append('nonce', NONCE);
			fd.append('file', file);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					if (j && j.success) location.reload();
					else alert('✗ ' + ((j && j.data && j.data.message) || 'Delete failed.'));
				});
			return;
		}

		// Take a manual snapshot of the current install
		var snapshotBtn = e.target.closest('[data-nexus-backup-create]');
		if (snapshotBtn) {
			e.preventDefault();
			snapshotBtn.disabled = true;
			var orig = snapshotBtn.textContent;
			snapshotBtn.textContent = 'Snapshotting…';
			var fd = new FormData();
			fd.append('action', 'nexus_update_create_backup');
			fd.append('nonce', NONCE);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					snapshotBtn.disabled = false;
					snapshotBtn.textContent = orig;
					if (j && j.success) location.reload();
					else alert('✗ ' + ((j && j.data && j.data.message) || 'Snapshot failed.'));
				});
			return;
		}

		var install = e.target.closest('[data-nexus-update-github]');
		if (install) {
			e.preventDefault();
			if (!confirm('Download and install the latest Nexus release from GitHub? Your saved connectors and credentials are preserved.')) return;
			var result = install.parentElement.querySelector('[data-nexus-update-result]');
			install.disabled = true;
			install.dataset.label = install.textContent;
			install.textContent = 'Installing…';
			nexusUpdateMsg(result, '', true);

			var fd = new FormData();
			fd.append('action', 'nexus_update_install_github');
			fd.append('nonce', NONCE);
			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					install.disabled = false;
					install.textContent = install.dataset.label;
					if (j && j.success) {
						nexusUpdateMsg(result, '✓ ' + (j.data.message || 'Installed.'), true);
						setTimeout(function(){ location.reload(); }, 1200);
					} else {
						nexusUpdateMsg(result, '✗ ' + ((j && j.data && j.data.message) || 'Install failed.'), false);
					}
				})
				.catch(function(){
					install.disabled = false;
					install.textContent = install.dataset.label;
					nexusUpdateMsg(result, 'Network error.', false);
				});
			return;
		}
	});

	var zipForm = document.querySelector('[data-nexus-update-zip]');
	if (zipForm) {
		var fileInput = zipForm.querySelector('input[type="file"]');
		var fileLabel = zipForm.querySelector('[data-nexus-zip-label]');
		var defaultLabel = fileLabel ? fileLabel.textContent : '';

		if (fileInput) {
			fileInput.addEventListener('change', function() {
				if (!fileLabel) return;
				var f = fileInput.files && fileInput.files[0];
				fileLabel.textContent = f ? (f.name + ' · ' + Math.round(f.size / 1024) + ' KB') : defaultLabel;
			});
		}

		zipForm.addEventListener('submit', function(e) {
			e.preventDefault();
			var result = zipForm.querySelector('[data-nexus-zip-result]');
			var submit = zipForm.querySelector('button[type="submit"]');
			if (!fileInput || !fileInput.files || !fileInput.files[0]) {
				nexusUpdateMsg(result, 'Pick a .zip first.', false);
				return;
			}
			if (!confirm('Install Nexus from the uploaded zip? Your saved connectors and credentials are preserved.')) return;

			submit.disabled = true;
			submit.dataset.label = submit.textContent;
			submit.textContent = 'Installing…';
			nexusUpdateMsg(result, '', true);

			var fd = new FormData();
			fd.append('action', 'nexus_update_install_zip');
			fd.append('nonce', NONCE);
			fd.append('package', fileInput.files[0]);

			fetch(AJAX, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(j){
					submit.disabled = false;
					submit.textContent = submit.dataset.label;
					if (j && j.success) {
						nexusUpdateMsg(result, '✓ ' + (j.data.message || 'Installed.'), true);
						setTimeout(function(){ location.reload(); }, 1200);
					} else {
						nexusUpdateMsg(result, '✗ ' + ((j && j.data && j.data.message) || 'Install failed.'), false);
					}
				})
				.catch(function(){
					submit.disabled = false;
					submit.textContent = submit.dataset.label;
					nexusUpdateMsg(result, 'Network error.', false);
				});
		});
	}
})();
