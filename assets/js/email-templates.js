/**
 * JavaScript: Gestione Modelli Email
 * ScubaDiabetes Logbook
 */
(function ($) {
	'use strict';

	var state = {
		forms: sdEmailTpl.forms || {},
		selectedFormKey: sdEmailTpl.defaultFormKey || '',
		templates: [],
		activeId: 0,
		dirty: false,
		activeField: 'subject',
		previewOpen: false,
		currentVariables: []
	};

	var presets = {
		reminder_renewal: {
			name: 'Reminder rinnovo annuale',
			subject: 'Rinnovo quota associativa {{anno_oggi}} - {{nome_completo}}',
			body: '<p>Caro/a <strong>{{nome}} {{cognome}}</strong>,</p><p>ti ricordiamo che la tua iscrizione a ScubaDiabetes scade il <strong>{{scadenza}}</strong>.</p><p>Per il rinnovo ti chiediamo di versare la quota associativa di <strong>{{tassa_sociale}}</strong>.</p><p>Grazie per il tuo supporto e per la fiducia.</p>',
			signature: '<p>Cordiali saluti,<br>Segretariato ScubaDiabetes<br><a href="mailto:{{email_associazione}}">{{email_associazione}}</a></p>{{logo_esteso}}'
		},
		payment_solicit: {
			name: 'Sollecito pagamento quota',
			subject: 'Sollecito pagamento quota {{anno_oggi}} - {{nome_completo}}',
			body: '<p>Gentile <strong>{{nome}} {{cognome}}</strong>,</p><p>ad oggi non risulta ancora registrato il pagamento della quota associativa <strong>{{anno_oggi}}</strong>.</p><p>Importo dovuto: <strong>{{tassa_sociale}}</strong>.</p><p>Ti invitiamo a regolarizzare entro breve e a contattarci in caso di necessità.</p>',
			signature: '<p>Grazie per la collaborazione,<br>Segretariato ScubaDiabetes<br><a href="mailto:{{email_associazione}}">{{email_associazione}}</a></p>'
		},
		welcome_member: {
			name: 'Benvenuto nuovo socio',
			subject: 'Benvenuto in ScubaDiabetes, {{nome}}!',
			body: '<p>Caro/a <strong>{{nome}} {{cognome}}</strong>,</p><p>benvenuto/a in ScubaDiabetes. Siamo felici di averti nella nostra associazione.</p><p>Per qualsiasi informazione puoi scriverci a <a href="mailto:{{email_associazione}}">{{email_associazione}}</a>.</p><p>A presto in acqua!</p>',
			signature: '<p>Un caro saluto,<br>Team ScubaDiabetes</p>{{logo}}'
		}
	};

	// =========================================================================
	// INIT
	// =========================================================================

	$(document).ready(function () {
		if (!$('#sd-email-tpl-page').length) {
			return;
		}

		populateFormSelectors();
		renderVariables();
		initEditors();
		loadTemplates();
		bindEvents();
		updateModuleBadge();

		$(window).on('resize', function () {
			refreshEditors();
		});
	});

	function populateFormSelectors() {
		var groups = {};
		Object.keys(state.forms).forEach(function (formKey) {
			var form = state.forms[formKey] || {};
			var group = form.group || 'Moduli';
			if (!groups[group]) {
				groups[group] = [];
			}
			groups[group].push({ key: formKey, label: form.label || formKey });
		});

		['#sd-tpl-form-key-select', '#sd-tpl-form-key-filter'].forEach(function (selector) {
			var html = '';
			Object.keys(groups).forEach(function (group) {
				html += '<optgroup label="' + escAttr(group) + '">';
				groups[group].forEach(function (item) {
					html += '<option value="' + escAttr(item.key) + '">' + escHtml(item.label) + '</option>';
				});
				html += '</optgroup>';
			});

			$(selector).html(html).val(state.selectedFormKey);
		});

		$('#sd-tpl-form-key').val(state.selectedFormKey);
	}

	// =========================================================================
	// EDITOR VISUALE (TinyMCE)
	// =========================================================================

	function initEditors() {
		initVisualEditor('sd-tpl-body', 'body', 320);
		initVisualEditor('sd-tpl-signature', 'signature', 220);
	}

	function initVisualEditor(textareaId, fieldName, height) {
		if (!window.wp || !wp.editor || typeof wp.editor.initialize !== 'function') {
			return;
		}

		wp.editor.initialize(textareaId, {
			tinymce: {
				wpautop: false,
				height: height,
				menubar: true,
				branding: false,
				toolbar1: 'formatselect styleselect | bold italic underline strikethrough forecolor backcolor | alignleft aligncenter alignright alignjustify',
				toolbar2: 'bullist numlist outdent indent | blockquote hr | link unlink image media | removeformat',
				toolbar3: 'undo redo | pastetext charmap | fullscreen',
				plugins: 'lists link image media hr charmap paste fullscreen',
				fontsize_formats: '8pt 10pt 11pt 12pt 14pt 16pt 18pt 24pt 30pt 36pt',
				setup: function (editor) {
					editor.on('init', function () {
						editor.save();
					});

					editor.on('focus', function () {
						state.activeField = fieldName;
					});

					editor.on('change keyup input undo redo SetContent', function () {
						editor.save();
						state.dirty = true;
						if (state.previewOpen) {
							updatePreview();
						}
					});
				}
			},
			quicktags: true,
			mediaButtons: true
		});
	}

	function getVisualEditor(textareaId) {
		if (!window.tinymce || typeof tinymce.get !== 'function') {
			return null;
		}
		return tinymce.get(textareaId);
	}

	function setFieldValue(textareaId, value) {
		var val = value || '';
		var editor = getVisualEditor(textareaId);

		if (editor) {
			editor.setContent(val);
			editor.save();
		}

		$('#' + textareaId).val(val);
	}

	function getFieldValue(textareaId) {
		var editor = getVisualEditor(textareaId);

		if (editor) {
			return editor.getContent();
		}

		return $('#' + textareaId).val();
	}

	// =========================================================================
	// CARICA LISTA
	// =========================================================================

	function loadTemplates() {
		$('#sd-email-tpl-loading').show();
		$('#sd-email-tpl-list').html('<div class="sd-email-tpl-loading">' +
			(sdEmailTpl.strings && sdEmailTpl.strings.loading || 'Caricamento...') + '</div>');

		$.post(sdEmailTpl.ajaxUrl, {
			action: 'sd_email_tpl_list',
			nonce: sdEmailTpl.nonce,
			form_key: state.selectedFormKey
		}, function (resp) {
			if (!resp.success) {
				showMessage('error', (resp.data && resp.data.message) || sdEmailTpl.strings.errorGeneric);
				$('#sd-email-tpl-list').html('<div class="sd-email-tpl-empty">Errore caricamento.</div>');
				return;
			}
			state.templates = resp.data.templates || [];
			renderList();
		}).fail(function () {
			$('#sd-email-tpl-list').html('<div class="sd-email-tpl-empty">Errore di rete.</div>');
		});
	}

	function renderList() {
		var $list = $('#sd-email-tpl-list');
		if (!state.templates.length) {
			$list.html('<div class="sd-email-tpl-empty">' + escHtml(sdEmailTpl.strings.emptyTemplates || 'Nessun modello salvato.') + '</div>');
			return;
		}

		var html = '';
		state.templates.forEach(function (tpl) {
			var isActive = (parseInt(tpl.id, 10) === state.activeId) ? ' is-active' : '';
			html += '<div class="sd-email-tpl-item' + isActive + '" data-id="' + escInt(tpl.id) + '">' +
				'<div class="sd-email-tpl-item-main">' +
					'<span class="sd-email-tpl-item-name" title="' + escAttr(tpl.name) + '">' + escHtml(tpl.name) + '</span>' +
					'<span class="sd-email-tpl-item-meta">' + escHtml(tpl.source_form_label || '') + '</span>' +
				'</div>' +
				'<div class="sd-email-tpl-item-actions">' +
					'<button type="button" class="sd-email-tpl-item-duplicate" data-id="' + escInt(tpl.id) + '" title="Duplica">Duplica</button>' +
					'<button type="button" class="sd-email-tpl-item-delete" data-id="' + escInt(tpl.id) + '" title="Elimina">✕</button>' +
				'</div>' +
			'</div>';
		});
		$list.html(html);
	}

	// =========================================================================
	// EDITOR: mostra / nascondi
	// =========================================================================

	function showEditor() {
		$('#sd-email-tpl-form').show();
		$('#sd-email-tpl-placeholder').hide();
		refreshEditors();
	}

	function hideEditor() {
		$('#sd-email-tpl-form').hide();
		$('#sd-email-tpl-placeholder').show();
		state.activeId = 0;
		state.dirty    = false;
		$('#sd-tpl-duplicate').hide();
		renderList();
	}

	function refreshEditors() {
		var bodyEditor = getVisualEditor('sd-tpl-body');
		var sigEditor  = getVisualEditor('sd-tpl-signature');

		if (bodyEditor) {
			bodyEditor.save();
		}

		if (sigEditor) {
			sigEditor.save();
		}
	}

	// =========================================================================
	// CREA NUOVO
	// =========================================================================

	function newTemplate() {
		if (state.dirty && !confirm(sdEmailTpl.strings.unsavedChanges)) {
			return;
		}
		state.activeId = 0;
		state.dirty    = false;
		resetForm();
		showEditor();
		renderList();
		$('#sd-tpl-name').focus();
	}

	function resetForm() {
		$('#sd-tpl-id').val('0');
		$('#sd-tpl-form-key').val(state.selectedFormKey);
		$('#sd-tpl-form-key-select').val(state.selectedFormKey);
		$('#sd-tpl-name').val('');
		$('#sd-tpl-subject').val('');
		setFieldValue('sd-tpl-body', '');
		setFieldValue('sd-tpl-signature', '');
		state.previewOpen = false;
		syncPreviewLayout();
		$('#sd-tpl-toggle-preview').text('Mostra anteprima');
		$('#sd-tpl-duplicate').hide();
		updateModuleBadge();
	}

	// =========================================================================
	// CARICA TEMPLATE IN EDITOR
	// =========================================================================

	function loadTemplate(id) {
		if (state.dirty && !confirm(sdEmailTpl.strings.unsavedChanges)) {
			return;
		}

		$.post(sdEmailTpl.ajaxUrl, {
			action: 'sd_email_tpl_get',
			nonce:  sdEmailTpl.nonce,
			id:     id
		}, function (resp) {
			if (!resp.success) {
				showMessage('error', (resp.data && resp.data.message) || sdEmailTpl.strings.errorGeneric);
				return;
			}
			var tpl = resp.data.template;
			state.activeId = parseInt(tpl.id, 10);
			state.dirty    = false;
			state.selectedFormKey = tpl.source_form_key || state.selectedFormKey;

			$('#sd-tpl-id').val(tpl.id);
			$('#sd-tpl-form-key').val(state.selectedFormKey);
			$('#sd-tpl-form-key-select, #sd-tpl-form-key-filter').val(state.selectedFormKey);
			renderVariables();
			updateModuleBadge(tpl.source_form_label || '');
			$('#sd-tpl-name').val(tpl.name || '');
			$('#sd-tpl-subject').val(tpl.subject || '');
			setFieldValue('sd-tpl-body', tpl.body || '');
			setFieldValue('sd-tpl-signature', tpl.signature || '');

			state.previewOpen = false;
			syncPreviewLayout();
			$('#sd-tpl-toggle-preview').text('Mostra anteprima');
			$('#sd-tpl-duplicate').show();

			showEditor();
			renderList();
		}).fail(function () {
			showMessage('error', sdEmailTpl.strings.errorGeneric);
		});
	}

	// =========================================================================
	// SALVA
	// =========================================================================

	function saveTemplate() {
		var $btn = $('#sd-tpl-save');
		$btn.prop('disabled', true).text('Salvataggio...');

		var body      = getFieldValue('sd-tpl-body');
		var signature = getFieldValue('sd-tpl-signature');

		$.post(sdEmailTpl.ajaxUrl, {
			action:    'sd_email_tpl_save',
			nonce:     sdEmailTpl.nonce,
			id:        $('#sd-tpl-id').val(),
			source_form_key: $('#sd-tpl-form-key').val(),
			name:      $('#sd-tpl-name').val(),
			subject:   $('#sd-tpl-subject').val(),
			body:      body,
			signature: signature
		}, function (resp) {
			$btn.prop('disabled', false).text('Salva modello');
			if (!resp.success) {
				showMessage('error', (resp.data && resp.data.message) || sdEmailTpl.strings.errorGeneric);
				return;
			}
			state.activeId = parseInt(resp.data.id, 10);
			state.dirty    = false;
			state.selectedFormKey = resp.data.source_form_key || state.selectedFormKey;
			$('#sd-tpl-id').val(state.activeId);
			$('#sd-tpl-duplicate').show();
			$('#sd-tpl-form-key-select, #sd-tpl-form-key-filter').val(state.selectedFormKey);
			showMessage('success', resp.data.message || sdEmailTpl.strings.saveSuccess);
			loadTemplates();
		}).fail(function () {
			$btn.prop('disabled', false).text('Salva modello');
			showMessage('error', sdEmailTpl.strings.errorGeneric);
		});
	}

	// =========================================================================
	// ELIMINA
	// =========================================================================

	function deleteTemplate(id) {
		if (!confirm(sdEmailTpl.strings.confirmDelete)) {
			return;
		}

		$.post(sdEmailTpl.ajaxUrl, {
			action: 'sd_email_tpl_delete',
			nonce:  sdEmailTpl.nonce,
			id:     id
		}, function (resp) {
			if (!resp.success) {
				showMessage('error', (resp.data && resp.data.message) || sdEmailTpl.strings.errorGeneric);
				return;
			}
			showMessage('success', resp.data.message || sdEmailTpl.strings.deleteSuccess);
			if (state.activeId === parseInt(id, 10)) {
				hideEditor();
			}
			loadTemplates();
		}).fail(function () {
			showMessage('error', sdEmailTpl.strings.errorGeneric);
		});
	}

	function duplicateTemplate(id) {
		if (!confirm(sdEmailTpl.strings.confirmDuplicate || 'Duplicare il modello selezionato?')) {
			return;
		}

		$.post(sdEmailTpl.ajaxUrl, {
			action: 'sd_email_tpl_duplicate',
			nonce:  sdEmailTpl.nonce,
			id:     id
		}, function (resp) {
			if (!resp.success) {
				showMessage('error', (resp.data && resp.data.message) || sdEmailTpl.strings.errorGeneric);
				return;
			}
			showMessage('success', resp.data.message || sdEmailTpl.strings.duplicateSuccess);
			loadTemplates();
			if (resp.data && resp.data.id) {
				loadTemplate(resp.data.id);
			}
		}).fail(function () {
			showMessage('error', sdEmailTpl.strings.errorGeneric);
		});
	}

	// =========================================================================
	// ANTEPRIMA
	// =========================================================================

	function togglePreview() {
		state.previewOpen = !state.previewOpen;
		syncPreviewLayout();
		if (state.previewOpen) {
			updatePreview();
			$('#sd-tpl-toggle-preview').text('Nascondi anteprima');
		} else {
			$('#sd-tpl-toggle-preview').text('Mostra anteprima');
		}
		refreshEditors();
	}

	function syncPreviewLayout() {
		var $layout = $('.sd-email-tpl-editor-layout');
		if (state.previewOpen) {
			$layout.addClass('is-preview-open');
			$('#sd-tpl-preview-col').show();
		} else {
			$layout.removeClass('is-preview-open');
			$('#sd-tpl-preview-col').hide();
		}
	}

	function updatePreview() {
		if (!state.previewOpen) { return; }

		var subject   = $('#sd-tpl-subject').val();
		var body      = getFieldValue('sd-tpl-body');
		var signature = getFieldValue('sd-tpl-signature');

		var resolvedSubject = resolvePreviewVariables(subject || '');
		var resolvedBody = resolvePreviewVariables(body || '');
		var resolvedSignature = resolvePreviewVariables(signature || '');

		$('#sd-tpl-preview-subject').text(resolvedSubject || '(oggetto vuoto)');
		$('#sd-tpl-preview-body').html(resolvedBody || '');
		$('#sd-tpl-preview-signature').html(resolvedSignature || '');
	}

	function resolvePreviewVariables(text) {
		var now = new Date();
		var months = [
			'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
			'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'
		];

		var day = String(now.getDate()).padStart(2, '0');
		var monthIndex = now.getMonth();
		var monthNumber = String(monthIndex + 1).padStart(2, '0');
		var year = String(now.getFullYear());
		var nextYear = String(now.getFullYear() + 1);
		var uploadsBase = window.location.origin + '/wp-content/uploads/2026/04/';

		var map = {
			'{{data_oggi_breve}}': day + '.' + monthNumber + '.' + year,
			'{{data_oggi_estesa}}': day + ' ' + months[monthIndex] + ' ' + year,
			'{{mese_oggi}}': months[monthIndex],
			'{{anno_oggi}}': year,
			'{{anno_prossimo}}': nextYear,
			'{{scadenza}}': '31.12.' + year,
			'{{tipo_socio}}': 'Individuale',
			'{{tipo_iscrizione}}': 'individuale',
			'{{tassa_sociale}}': 'CHF 80.00',
			'{{tassa_sociale_numero}}': '80.00',
			'{{nome}}': 'Mario',
			'{{cognome}}': 'Rossi',
			'{{nome_completo}}': 'Mario Rossi',
			'{{email_socio}}': 'mario.rossi@example.com',
			'{{email_associazione}}': 'segreteria@scubadiabetes.org',
			'{{telefono_associazione}}': '+41 79 000 00 00',
			'{{indirizzo_associazione}}': 'Via Esempio 1, 6900 Lugano',
			'{{logo}}': '<img src="' + uploadsBase + 'cropped-cropped-ScubaDS-1.jpeg" alt="Logo" style="max-height:80px;display:block;">',
			'{{logo_esteso}}': '<img src="' + uploadsBase + 'scubadiabetes_radius60.png" alt="Logo esteso" style="max-height:120px;display:block;">'
		};

		state.currentVariables.forEach(function (variable) {
			if (variable && variable.tag && typeof map[variable.tag] === 'undefined') {
				map[variable.tag] = variable.sample || variable.label || variable.tag;
			}
		});

		var resolved = String(text || '');
		Object.keys(map).forEach(function (tag) {
			resolved = resolved.split(tag).join(map[tag]);
		});

		return resolved;
	}

	// =========================================================================
	// INSERT VARIABILE
	// =========================================================================

	function insertVariable(target, variable) {
		var actualTarget = target || state.activeField || 'subject';

		if (actualTarget === 'body') {
			insertIntoVisualEditor('sd-tpl-body', variable);
		} else if (actualTarget === 'signature') {
			insertIntoVisualEditor('sd-tpl-signature', variable);
		} else {
			insertIntoInput($('#sd-tpl-subject'), variable);
		}
		state.dirty = true;

		if (state.previewOpen) {
			updatePreview();
		}
	}

	function insertIntoVisualEditor(textareaId, value) {
		var editor = getVisualEditor(textareaId);

		if (editor) {
			editor.focus();
			editor.execCommand('mceInsertContent', false, value);
			editor.save();
			return;
		}

		insertIntoInput($('#' + textareaId), value);
	}

	function insertIntoInput($inp, value) {
		if (!$inp || !$inp.length) {
			return;
		}

		var el = $inp[0];
		var pos = (typeof el.selectionStart === 'number') ? el.selectionStart : $inp.val().length;
		var val = $inp.val() || '';

		$inp.val(val.substring(0, pos) + value + val.substring(pos));
		if (typeof el.setSelectionRange === 'function') {
			el.setSelectionRange(pos + value.length, pos + value.length);
		}
		$inp.focus();
	}

	// =========================================================================
	// BIND EVENTI
	// =========================================================================

	function bindEvents() {
		// Nuovo modello
		$('#sd-email-tpl-new').on('click', newTemplate);

		// Click su item lista
		$(document).on('click', '.sd-email-tpl-item', function (e) {
			if ($(e.target).hasClass('sd-email-tpl-item-delete') || $(e.target).hasClass('sd-email-tpl-item-duplicate')) { return; }
			var id = parseInt($(this).data('id'), 10);
			if (id && id !== state.activeId) {
				loadTemplate(id);
			}
		});

		// Elimina (click sul ✕)
		$(document).on('click', '.sd-email-tpl-item-delete', function (e) {
			e.stopPropagation();
			var id = parseInt($(this).data('id'), 10);
			if (id) { deleteTemplate(id); }
		});

		$(document).on('click', '.sd-email-tpl-item-duplicate', function (e) {
			e.stopPropagation();
			var id = parseInt($(this).data('id'), 10);
			if (id) { duplicateTemplate(id); }
		});

		// Annulla
		$('#sd-tpl-cancel').on('click', function () {
			if (state.dirty && !confirm(sdEmailTpl.strings.unsavedChanges)) { return; }
			hideEditor();
		});

		// Salva
		$('#sd-email-tpl-form').on('submit', function (e) {
			e.preventDefault();
			saveTemplate();
		});
		$('#sd-tpl-save').on('click', function () {
			$('#sd-email-tpl-form').trigger('submit');
		});

		$('#sd-tpl-apply-preset').on('click', applyPreset);
		$('#sd-tpl-duplicate').on('click', function () {
			if (state.activeId) {
				duplicateTemplate(state.activeId);
			}
		});

		// Anteprima
		$('#sd-tpl-toggle-preview').on('click', togglePreview);

		$('#sd-tpl-form-key-select, #sd-tpl-form-key-filter').on('change', function () {
			var nextKey = $(this).val();
			if (!nextKey || nextKey === state.selectedFormKey) {
				return;
			}

			if (state.dirty && !confirm(sdEmailTpl.strings.unsavedChanges)) {
				$(this).val(state.selectedFormKey);
				return;
			}

			state.selectedFormKey = nextKey;
			$('#sd-tpl-form-key').val(state.selectedFormKey);
			$('#sd-tpl-form-key-select, #sd-tpl-form-key-filter').val(state.selectedFormKey);
			renderVariables();
			updateModuleBadge();
			resetForm();
			showEditor();
			loadTemplates();
		});

		// Dirty flag su input subject / name
		$('#sd-tpl-name, #sd-tpl-subject').on('input', function () {
			state.dirty = true;
			if (state.previewOpen) { updatePreview(); }
		});

		$('#sd-tpl-subject').on('focus', function () {
			state.activeField = 'subject';
		});

		$('#sd-tpl-body').on('focus input', function () {
			state.activeField = 'body';
			state.dirty = true;
			if (state.previewOpen) { updatePreview(); }
		});

		$('#sd-tpl-signature').on('focus input', function () {
			state.activeField = 'signature';
			state.dirty = true;
			if (state.previewOpen) { updatePreview(); }
		});

		// Insert variabile
		$(document).on('click', '.sd-var-chip', function () {
			var varTag = $(this).data('var');
			var target = $(this).data('target');
			insertVariable(target, varTag);
		});
	}

	// =========================================================================
	// MESSAGGI
	// =========================================================================

	function showMessage(type, text) {
		var $msg = $('#sd-email-tpl-message');
		$msg
			.removeClass('sd-notice-success sd-notice-error')
			.addClass(type === 'success' ? 'sd-notice-success' : 'sd-notice-error')
			.text(text)
			.show();
		clearTimeout(showMessage._timer);
		showMessage._timer = setTimeout(function () { $msg.fadeOut(); }, 5000);
	}

	function applyPreset() {
		var presetKey = $('#sd-tpl-preset').val();
		if (!presetKey || !presets[presetKey]) {
			showMessage('error', 'Seleziona un preset valido.');
			return;
		}

		if (state.dirty && !confirm(sdEmailTpl.strings.unsavedChanges)) {
			return;
		}

		var p = presets[presetKey];
		$('#sd-tpl-name').val(p.name || '');
		$('#sd-tpl-subject').val(p.subject || '');
		setFieldValue('sd-tpl-body', p.body || '');
		setFieldValue('sd-tpl-signature', p.signature || '');

		state.activeId = 0;
		$('#sd-tpl-id').val('0');
		state.dirty = true;

		if (state.previewOpen) {
			updatePreview();
		}

		showMessage('success', 'Preset applicato. Verifica il contenuto e salva il modello.');
	}

	function renderVariables() {
		var form = state.forms[state.selectedFormKey] || {};
		state.currentVariables = Array.isArray(form.variables) ? form.variables : [];

		var chipsHtml = '';
		var rowsHtml = '';
		if (!state.currentVariables.length) {
			chipsHtml = '<div class="sd-email-tpl-empty">' + escHtml(sdEmailTpl.strings.emptyVariables || '') + '</div>';
			rowsHtml = '<tr><td colspan="2" class="sd-email-tpl-empty">' + escHtml(sdEmailTpl.strings.emptyVariables || '') + '</td></tr>';
		} else {
			state.currentVariables.forEach(function (variable) {
				chipsHtml += '<button type="button" class="sd-var-chip" data-var="' + escAttr(variable.tag) + '" title="' + escAttr(variable.description || variable.label || variable.tag) + '">' + escHtml(variable.tag) + '</button>';
				rowsHtml += '<tr><td><code>' + escHtml(variable.tag) + '</code></td><td>' + escHtml(variable.description || variable.label || '') + '</td></tr>';
			});
		}

		$('#sd-var-chips-main').html(chipsHtml);
		$('.sd-email-tpl-vars-table tbody').html(rowsHtml);
	}

	function updateModuleBadge(labelOverride) {
		var form = state.forms[state.selectedFormKey] || {};
		var label = labelOverride || form.label || state.selectedFormKey;
		$('#sd-email-tpl-current-module').text(label ? 'Tipo mailing list attivo: ' + label : '');
	}

	// =========================================================================
	// HELPERS
	// =========================================================================

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function escAttr(str) { return escHtml(str); }
	function escInt(v)    { return parseInt(v, 10) || 0; }

}(jQuery));
