/* Nexus by Therum — admin interactions for the connector card grid */
(function() {
	'use strict';

	var cfg = window.NexusAdmin || {};
	var AJAX  = cfg.ajaxurl || (window.ajaxurl || '/wp-admin/admin-ajax.php');
	var NONCE = cfg.nonce || '';

	document.addEventListener('click', function(e) {
		// Toggle config form
		var btn = e.target.closest('[data-conn-toggle]');
		if (btn) {
			var card = btn.closest('[data-connector]');
			var form = card ? card.querySelector('[data-conn-form]') : null;
			if (!form) return;
			var open = !form.hidden;
			form.hidden = open;
			btn.textContent = open ? (btn.getAttribute('data-label-open') || 'Configure') : 'Cancel';
			if (!open) {
				var first = form.querySelector('input,select,textarea');
				if (first) first.focus();
			}
			return;
		}

		// Save
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
			save.textContent = 'Saving…';
			if (result) { result.textContent = ''; result.style.color = ''; }

			fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					save.disabled = false;
					save.textContent = 'Save';
					if (j && j.success) {
						if (result) { result.textContent = '✓ Saved'; result.style.color = 'var(--ok,#10b981)'; }
						var dot = card.querySelector('[data-conn-status]');
						if (dot) {
							dot.textContent = 'Connected';
							dot.className = 'th-conn-status th-conn-status-connected';
						}
						setTimeout(function() { if (result) result.textContent = ''; }, 2000);
					} else {
						if (result) { result.textContent = '✗ ' + ((j && j.data) || 'Error'); result.style.color = 'var(--err,#ef4444)'; }
					}
				})
				.catch(function() {
					save.disabled = false;
					save.textContent = 'Save';
					if (result) { result.textContent = '✗ Network error'; result.style.color = 'var(--err,#ef4444)'; }
				});
			return;
		}

		// Disconnect
		var disc = e.target.closest('[data-conn-disconnect]');
		if (disc) {
			var card = disc.closest('[data-connector]');
			var id   = card ? card.getAttribute('data-connector') : '';
			if (!id || !confirm('Disconnect ' + id + '? Saved credentials will be removed.')) return;

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
						var toggle = card.querySelector('[data-conn-toggle]');
						if (toggle) toggle.textContent = toggle.getAttribute('data-label-open') || 'Configure';
						disc.hidden = true;
					}
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
