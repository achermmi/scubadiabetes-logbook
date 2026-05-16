/**
 * Dashboard Admin Attivita - ScubaDiabetes
 */
(function ($) {
	'use strict';

	var state = {
		activities: [],
		selectedActivityId: 0,
		registrations: [],
		currentFields: [],
		currentActivity: null,
		scrollToFieldId: null,
		pendingMessage: null,
		descriptionPendingValue: null,
		descriptionLastKnownHtml: '',
		descriptionRefreshTimer: null,
	};

	var visualDescriptionEditorEnabled = true;
	var descriptionDebugSeq = 0;
	var priceRateNoteDefault = 'Salva prima l\'attivita per poter aggiungere tariffe.';

	function debugDescriptionLog(eventName, extra) {
		var debugEnabled = true;
		if (window.sdActivityAdmin && typeof window.sdActivityAdmin.debugDescriptionEditor !== 'undefined') {
			debugEnabled = !!window.sdActivityAdmin.debugDescriptionEditor;
		}
		if (!debugEnabled || !window.console || typeof window.console.log !== 'function') {
			return;
		}

		descriptionDebugSeq += 1;
		var $textarea = $('#sd-activity-description');
		var textareaVal = $textarea.length ? String($textarea.val() || '') : '';
		var editor = getActivityDescriptionEditor();
		var mounted = false;
		var healthy = false;
		var initialized = false;
		var editorMode = '';
		var bodyContentEditable = '';
		var containerDisplay = '';
		var containerVisibility = '';
		var iframeWidth = '';
		var iframeHeight = '';

		if (editor) {
			mounted = isActivityDescriptionEditorMounted(editor);
			healthy = isActivityDescriptionEditorHealthy(editor);
			initialized = !!editor.initialized;
			editorMode = String(editor.mode && editor.mode.get ? editor.mode.get() : '');
			if (healthy && typeof editor.getBody === 'function') {
				try {
					var body = editor.getBody();
					bodyContentEditable = body ? String(body.getAttribute('contenteditable') || '') : '';
				} catch (errGetBodyAttr) {
					bodyContentEditable = '[getBodyAttr-error]';
				}

				try {
					var container = typeof editor.getContainer === 'function' ? editor.getContainer() : null;
					if (container) {
						containerDisplay = String(container.style && container.style.display ? container.style.display : '');
						containerVisibility = String(container.style && container.style.visibility ? container.style.visibility : '');
						if (typeof container.getBoundingClientRect === 'function') {
							var rect = container.getBoundingClientRect();
							iframeWidth = String(Math.round(rect.width || 0));
							iframeHeight = String(Math.round(rect.height || 0));
						}
					}
				} catch (errContainer) {
					containerDisplay = '[container-error]';
				}
			}
		}

		var snapshot = {
			seq: descriptionDebugSeq,
			event: eventName,
			selectedActivityId: parseInt(state.selectedActivityId, 10) || 0,
			textareaLen: textareaVal.length,
			pendingLen: String(state.descriptionPendingValue || '').length,
			lastKnownLen: String(state.descriptionLastKnownHtml || '').length,
			editorPresent: !!editor,
			editorMounted: !!mounted,
			editorHealthy: !!healthy,
			editorInitialized: initialized,
			editorMode: editorMode,
			bodyContentEditable: bodyContentEditable,
			containerDisplay: containerDisplay,
			containerVisibility: containerVisibility,
			containerWidth: iframeWidth,
			containerHeight: iframeHeight,
		};

		if (extra && typeof extra === 'object') {
			snapshot.extra = extra;
		}

		console.log('[SD Description Debug]', snapshot);
	}

	var defaultSections = [
		{ key: 'personal', label: 'Dati Personali', order: 10 },
		{ key: 'activity_data', label: 'Dati Attivita', order: 15 },
		{ key: 'additional', label: 'Informazioni Aggiuntive', order: 20 },
		{ key: 'pricing', label: 'Selezione Tariffa', order: 30 },
		{ key: 'consents', label: 'Consensi', order: 40 },
	];

	var personalBaseFieldsMap = {
		first_name: { key: 'first_name', field_label: 'Nome', field_type: 'text' },
		last_name: { key: 'last_name', field_label: 'Cognome', field_type: 'text' },
		email: { key: 'email', field_label: 'Email', field_type: 'email' },
		birth_date: { key: 'birth_date', field_label: 'Data di nascita', field_type: 'date' },
	};

	var activityDataBaseBlocksMap = {
		core: { key: 'core', label: 'Titolo, Luogo, Date e Max Partecipanti' },
		thumbnail: { key: 'thumbnail', label: 'Immagine URL' },
		description: { key: 'description', label: 'Descrizione' },
		extra_fields: { key: 'extra_fields', label: 'Campi aggiuntivi Dati Attivita' },
	};

	$(document).ready(function () {
		if (!$('#sd-activity-admin-page').length) {
			return;
		}

		if ($('.sd-admin-panel[data-panel="modifica"]').hasClass('is-active')) {
			initActivityDescriptionEditor();
		}
		bindTabs();
		bindActions();
		loadActivities();
	});

	function bindTabs() {
		$(document).on('click', '.sd-admin-tab', function () {
			var tab = $(this).data('tab');
			$('.sd-admin-tab').removeClass('is-active');
			$(this).addClass('is-active');
			$('.sd-admin-panel').removeClass('is-active');
			$('.sd-admin-panel[data-panel="' + tab + '"]').addClass('is-active');
		});
	}

	function bindActions() {
		$('#sd-activity-filter-btn').on('click', loadActivities);
		$('#sd-activity-new-btn').on('click', function () {
			resetActivityForm();
			switchTab('modifica');
		});

		$('#sd-activity-form').on('submit', saveActivity);
		$('#sd-activity-form-reset').on('click', resetActivityForm);
		$('#sd-activity-price-form').on('submit', savePrice);
		$('#sd-price-cancel-edit').on('click', resetPriceForm);
		$('#sd-price-chf').on('input change', updatePriceEurPreview);
		$('#sd-activity-field-form').on('submit', saveField);
		$('#sd-field-type').on('change', toggleFieldOptions);
		$('#sd-field-section').on('change', toggleFieldSectionInputs);
		$('#sd-add-option-btn').on('click', addFieldOption);
		// Use event delegation for dynamic form elements
		$(document).on('change', 'input[name="sd-image-type"]', toggleImageSourceInputs);
		$(document).on('change', 'input[name="sd-image-type"]', updateImagePreview);
		$(document).on('click', '#sd-image-media-btn', openMediaLibraryForImage);
		$(document).on('change', '#sd-image-url', updateImagePreview);
		$(document).on('change', '#sd-image-width, #sd-image-height', updateImagePreview);
		$(document).on('change', 'input[name="sd-image-align-h"], input[name="sd-image-align-v"]', updateImagePreview);
		$(document).on('change', '#sd-image-aspect-ratio', updateImagePreview);
		$(document).on('input change', '#sd-activity-thumbnail', updateActivityThumbnailPreview);
		$(document).on('click', '#sd-activity-thumbnail-media-btn', openMediaLibraryForActivityThumbnail);
		$('#sd-add-condition-rule').on('click', function () {
			addConditionRuleRow();
		});
		$('#sd-field-cancel-edit, #sd-field-cancel-edit-secondary').on('click', resetFieldForm);

		$(document).on('click', '.sd-condition-rule-remove', function () {
			$(this).closest('.sd-condition-rule-row').remove();
		});

		$('#sd-reg-filter-btn').on('click', loadRegistrations);
		$('#sd-reg-activity-id').on('change', loadRegistrations);
		$('#sd-reg-payment-filter').on('change', loadRegistrations);

		$(document).on('click', '.sd-activity-edit', function () {
			editActivity(parseInt($(this).data('id'), 10) || 0);
		});

		$(document).on('click', '.sd-activity-delete', function () {
			deleteActivity(parseInt($(this).data('id'), 10) || 0);
		});

		$(document).on('click', '.sd-field-edit', function () {
			editField(parseInt($(this).data('id'), 10) || 0);
		});

		$(document).on('click', '.sd-field-delete', function () {
			deleteField(parseInt($(this).data('id'), 10) || 0);
		});

		$(document).on('click', '.sd-price-edit', function () {
			startPriceEdit(parseInt($(this).data('id'), 10) || 0);
		});

		$(document).on('click', '.sd-price-delete', function () {
			deletePrice(parseInt($(this).data('id'), 10) || 0);
		});

		$(document).on('click', '.sd-price-set-default', function () {
			setDefaultPrice(parseInt($(this).data('id'), 10) || 0);
		});

		$(document).on('click', '.sd-field-move', function () {
			moveField(parseInt($(this).data('id'), 10) || 0, $(this).data('direction'));
		});

		$(document).on('click', '.sd-activity-data-field-move', function () {
			moveActivityDataField(parseInt($(this).data('id'), 10) || 0, $(this).data('direction'));
		});

		$(document).on('click', '.sd-static-personal-field-move', function () {
			moveStaticPersonalField($(this).data('key'), $(this).data('direction'));
		});

		$(document).on('click', '.sd-personal-field-move', function () {
			movePersonalField(String($(this).data('token') || ''), $(this).data('direction'));
		});

		$(document).on('click', '.sd-personal-field-span-toggle', function () {
			togglePersonalFieldSpan(String($(this).data('token') || ''));
		});

		$(document).on('click', '.sd-static-activity-block-move', function () {
			moveActivityDataBlock($(this).data('key'), $(this).data('direction'));
		});

		$(document).on('click', '.sd-section-rename', function () {
			renameSection($(this).data('section'));
		});

		$(document).on('click', '.sd-section-delete', function () {
			deleteSection($(this).data('section'));
		});

		$(document).on('click', '.sd-section-move', function () {
			moveSection($(this).data('section'), $(this).data('direction'));
		});

		$(document).on('change', '.sd-reg-status-select', function () {
			var registrationId = parseInt($(this).data('id'), 10) || 0;
			var status = String($(this).val() || 'registered');
			if (!registrationId) {
				return;
			}
			setSelectStatusClass($(this), status);
			updateRegistrationStatus(registrationId, status);
		});

		$(document).on('change', '.sd-reg-payment-select', function () {
			var registrationId = parseInt($(this).data('id'), 10) || 0;
			var paymentStatus = String($(this).val() || 'pending');
			if (!registrationId) {
				return;
			}
			setSelectStatusClass($(this), paymentStatus);
			updateRegistrationPaymentStatus(registrationId, paymentStatus);
		});

		$(document).on('click', '.sd-reg-resend-invoice', function () {
			var registrationId = parseInt($(this).data('id'), 10) || 0;
			if (!registrationId) {
				return;
			}
			resendInvoiceEmail(registrationId);
		});

		$(document).on('click', '#sd-copy-shortcode-btn', copyShortcodeToClipboard);
		$(document).on('click', '.sd-copy-shortcode-inline', copyInlineShortcodeToClipboard);
	}

	function loadActivities() {
		var search = $('#sd-activity-search').val() || '';
		var status = $('#sd-activity-status-filter').val() || '';

		setTableLoading('#sd-activities-tbody', 7);

		$.ajax({
			url: sdActivityAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activities_list',
				search: search,
				status: status,
				per_page: 100,
				page: 1,
				nonce: sdActivityAdmin.nonce,
			},
		}).done(function (resp) {
			if (!resp || !resp.success) {
				setTableError('#sd-activities-tbody', 6, (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			state.activities = resp.data.activities || [];
			renderActivitiesTable();
			populateActivitySelects();
		}).fail(function () {
			setTableError('#sd-activities-tbody', 7, sdActivityAdmin.strings.error);
		});
	}

	function renderActivitiesTable() {
		var rows = state.activities;
		var html = '';

		if (!rows.length) {
			setTableEmpty('#sd-activities-tbody', 7, 'Nessuna attivita trovata.');
			return;
		}

		rows.forEach(function (activity) {
			var activityId = parseInt(activity.id, 10) || 0;
			var inlineShortcode = '[sd_iscrizione_attivita activity_id="' + activityId + '"]';
			var eventStatusCode = String(activity.event_status || 'draft');
				html += '<tr>' +
					'<td><div class="sd-activity-id-cell"><strong>#' + activityId + '</strong><button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-copy-shortcode-inline" data-id="' + activityId + '" data-shortcode="' + esc(inlineShortcode) + '">Copia shortcode</button></div></td>' +
				'<td><strong>' + esc(activity.title) + '</strong></td>' +
				'<td>' + formatDate(activity.start_date) + '</td>' +
				'<td>' + esc(activity.location || '-') + '</td>' +
				'<td>' + parseInt(activity.registrations_count || 0, 10) + ' / ' + parseInt(activity.max_participants || 0, 10) + '</td>' +
				'<td><span class="sd-status-badge sd-status-' + esc(eventStatusCode) + '">' + esc(getEventStatusLabel(eventStatusCode)) + '</span></td>' +
				'<td class="sd-actions-cell">' +
					'<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-activity-edit" data-id="' + activityId + '">Modifica</button>' +
					'<button type="button" class="sd-btn sd-btn-danger sd-btn-sm sd-activity-delete" data-id="' + activityId + '">Elimina</button>' +
				'</td>' +
			'</tr>';
		});

		$('#sd-activities-tbody').html(html);
	}

	function populateActivitySelects() {
		var $regSelect = $('#sd-reg-activity-id');
		var html = '<option value="">Seleziona attivita</option>';

		state.activities.forEach(function (activity) {
			html += '<option value="' + parseInt(activity.id, 10) + '">#' + parseInt(activity.id, 10) + ' - ' + esc(activity.title) + '</option>';
		});

		$regSelect.html(html);
		if (state.selectedActivityId) {
			$regSelect.val(String(state.selectedActivityId));
		}
	}

	function editActivity(activityId) {
		if (!activityId) {
			return;
		}

		debugDescriptionLog('editActivity:start', { activityId: activityId });

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_get',
			activity_id: activityId,
			nonce: sdActivityAdmin.nonce,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			var a = resp.data;
			state.selectedActivityId = parseInt(a.id, 10) || 0;
			state.currentActivity = a;
			state.currentFields = a.form_fields || [];
			switchTab('modifica', { skipDescriptionRefresh: true });

			$('#sd-activity-id').val(state.selectedActivityId);
			$('#sd-activity-title').val(a.title || '');
			$('#sd-activity-location').val(a.location || '');
			$('#sd-activity-status').val(a.event_status || 'draft');
			$('#sd-activity-thumbnail').val(a.thumbnail_url || '');
			updateActivityThumbnailPreview();
			var activityDescription = normalizeActivityDescriptionHtml(a.description || '');
			debugDescriptionLog('editActivity:data-loaded', {
				activityId: parseInt(a.id, 10) || 0,
				descriptionLen: String(activityDescription || '').length,
			});
			setActivityDescriptionValue(activityDescription);
			$('#sd-activity-max').val(a.max_participants || '');
			$('#sd-activity-start').val(toDateTimeLocal(a.start_date));
			$('#sd-activity-end').val(toDateTimeLocal(a.end_date));

			updateActivityShortcodeHint(state.selectedActivityId);

			renderPricesList(a.prices || []);
			resetPriceForm();
			populateFieldSectionSelect(a.form_fields || []);
			renderActivityDataExtraFields(a.form_fields || []);
			renderActivityDataStaticOrderControls();
			applyActivityDataLayoutOrder();
			renderFieldsList(a.form_fields || []);
			initDragAndDropSortables();
			applyVirtualSectionMetaUI();
			resetFieldForm(false);
			populateActivitySelects();
			scheduleActivityDescriptionRefresh(activityDescription, 140, true);
			debugDescriptionLog('editActivity:after-schedule-refresh', { delayMs: 140, enforceContent: true });
			window.setTimeout(function () {
				debugDescriptionLog('editActivity:700ms-refresh-fired', { delayMs: 700 });
				applyActivityDescriptionContentToNativeEditor(activityDescription, true);
				refreshActivityDescriptionVisualEditor();
			}, 700);

			// Se è stato salvato un campo, resetta il flag
			state.scrollToFieldId = null;

			// Mostra messaggio pendente (es. dopo salvataggio campo) ora che il DOM è aggiornato
			if (state.pendingMessage) {
				var pm = state.pendingMessage;
				state.pendingMessage = null;
				showMessage(pm.type, pm.text, pm.scrollTarget || 'top');
			}
		});
	}

	function saveActivity(e) {
		e.preventDefault();

		var activityId = parseInt($('#sd-activity-id').val(), 10) || 0;
		var payload = {
			title: $('#sd-activity-title').val(),
			description: getActivityDescriptionValue(),
			start_date: fromDateTimeLocal($('#sd-activity-start').val()),
			end_date: fromDateTimeLocal($('#sd-activity-end').val()),
			location: $('#sd-activity-location').val(),
			max_participants: $('#sd-activity-max').val(),
			event_status: $('#sd-activity-status').val(),
			thumbnail_url: $('#sd-activity-thumbnail').val(),
		};

		if (!payload.title || !payload.start_date || !payload.end_date) {
			showMessage('error', 'Compila almeno titolo, data inizio e data fine.', 'top');
			return;
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_save',
			nonce: sdActivityAdmin.nonce,
			activity_id: activityId,
			activity: payload,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error, 'top');
				return;
			}

			state.selectedActivityId = parseInt(resp.data.activity_id, 10) || 0;
			state.currentActivity = $.extend(true, {}, state.currentActivity || {}, payload, {
				id: state.selectedActivityId,
				form_fields: state.currentFields || [],
			});
			$('#sd-activity-id').val(state.selectedActivityId);
			showMessage('success', resp.data.message || 'Attivita salvata.', 'top');
			loadActivities();
		});
	}

	function initActivityDescriptionEditor() {
		if (!$('#sd-activity-description').length) {
			return;
		}

		$(document)
			.off('click.sdDescriptionNativeTabs', '#sd-activity-description-tmce, #sd-activity-description-html')
			.on('click.sdDescriptionNativeTabs', '#sd-activity-description-tmce, #sd-activity-description-html', function () {
				if (this && this.id === 'sd-activity-description-tmce') {
					syncActivityDescriptionVisualFromTextareaWithRetry(10, 80);
					return;
				}
				if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
					try {
						window.tinymce.triggerSave();
					} catch (err) {
						// Keep current textarea value.
					}
				}
				syncActivityDescriptionHtmlTextareaFromEditor();
			});

		cleanupActivityDescriptionEditorUi();

		if (!visualDescriptionEditorEnabled) {
			initActivityDescriptionHtmlOnlyEditor();
			return;
		}

		var existingEditor = getActivityDescriptionEditor();
		if (existingEditor && !isActivityDescriptionEditorMounted(existingEditor)) {
			destroyActivityDescriptionEditor();
			existingEditor = null;
		}
		if (existingEditor && !isActivityDescriptionEditorHealthy(existingEditor)) {
			destroyActivityDescriptionEditor();
			existingEditor = null;
		}

		if (window.wp && window.wp.editor && typeof window.wp.editor.initialize === 'function') {
			if (existingEditor) {
				ensureActivityDescriptionVisualTabActive();
				syncActivityDescriptionMode('tmce');
				syncActivityDescriptionVisualFromTextareaWithRetry(8, 80);
				window.setTimeout(waitForActivityDescriptionEditor, 120);
				cleanupActivityDescriptionEditorUi();
				return;
			}

			if ($('#wp-sd-activity-description-wrap').length) {
				// Editor markup already rendered by wp_editor(); avoid double initialize.
				ensureActivityDescriptionVisualTabActive();
				syncActivityDescriptionVisualFromTextareaWithRetry(12, 90);
				window.setTimeout(waitForActivityDescriptionEditor, 120);
				return;
			}

			window.wp.editor.initialize('sd-activity-description', {
				mediaButtons: true,
				quicktags: true,
				tinymce: {
					wpautop: true,
					height: 260,
					toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
					toolbar2: 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,wp_help',
					setup: function (editor) {
						editor.on('init', function () {
							ensureActivityDescriptionVisualTabActive();
							syncActivityDescriptionMode('tmce');
							syncActivityDescriptionHtmlTextareaFromEditor();
							syncActivityDescriptionVisualFromTextareaWithRetry(6, 80);
							waitForActivityDescriptionEditor();
						});
						editor.on('change keyup SetContent Undo Redo', function () {
							syncActivityDescriptionHtmlTextareaFromEditor();
							editor.save();
						});
					},
				},
			});
			waitForActivityDescriptionEditor();
			return;
		}

		if (window.tinymce && !window.tinymce.get('sd-activity-description')) {
			window.tinymce.init({
				selector: '#sd-activity-description',
				plugins: 'link image media lists',
				toolbar: 'formatselect | bold italic underline | bullist numlist | link image | alignleft aligncenter alignright',
				menubar: 'edit insert format table',
				height: 260,
			});
			waitForActivityDescriptionEditor();
			return;
		}

		waitForActivityDescriptionEditor();
	}

	function initActivityDescriptionHtmlOnlyEditor() {
		var $textarea = $('#sd-activity-description');
		if (!$textarea.length) {
			return;
		}

		if (window.tinymce && typeof window.tinymce.get === 'function') {
			var existingEditor = window.tinymce.get('sd-activity-description');
			if (existingEditor && typeof existingEditor.remove === 'function') {
				existingEditor.remove();
			}
		}

		if (window.wp && window.wp.editor && typeof window.wp.editor.initialize === 'function') {
			window.wp.editor.initialize('sd-activity-description', {
				mediaButtons: true,
				quicktags: true,
				tinymce: false,
			});
		}

		forceActivityDescriptionHtmlMode();
	}

	function forceActivityDescriptionHtmlMode() {
		if (window.switchEditors && typeof window.switchEditors.go === 'function') {
			window.switchEditors.go('sd-activity-description', 'html');
		}

		var $textarea = $('#sd-activity-description');
		$textarea.prop('readonly', false).prop('disabled', false).show();
		$textarea.css({
			'pointer-events': 'auto',
			'user-select': 'text',
			'cursor': 'text',
		});

		// If the Visual tab is still present due to external scripts, disable it.
		var $tmceTab = $('#sd-activity-description-tmce');
		if ($tmceTab.length) {
			$tmceTab.hide().attr('aria-hidden', 'true');
		}
	}

	function getActivityDescriptionEditor() {
		if (!window.tinymce || typeof window.tinymce.get !== 'function') {
			return null;
		}

		var preferred = window.tinymce.get('sd-activity-description') || null;
		if (preferred && typeof preferred.getContainer === 'function') {
			var container = preferred.getContainer();
			if (container && document.body.contains(container)) {
				return preferred;
			}
		}

		var editors = Array.isArray(window.tinymce.editors) ? window.tinymce.editors : [];
		for (var i = 0; i < editors.length; i += 1) {
			var ed = editors[i];
			if (!ed || String(ed.id || '') !== 'sd-activity-description') {
				continue;
			}
			if (typeof ed.getContainer === 'function') {
				var edContainer = ed.getContainer();
				if (edContainer && document.body.contains(edContainer)) {
					return ed;
				}
			}
		}

		return preferred;
	}

	function getActivityDescriptionContentFromEditor() {
		var editor = getActivityDescriptionEditor();
		if (!editor) {
			return '';
		}

		try {
			var content = normalizeActivityDescriptionHtml(editor.getContent() || '');
			if (content) {
				return content;
			}
		} catch (err) {
			// Fallback to body HTML below.
		}

		try {
			var body = typeof editor.getBody === 'function' ? editor.getBody() : null;
			if (body && body.innerHTML) {
				return normalizeActivityDescriptionHtml(body.innerHTML);
			}
		} catch (err2) {
			// Ignore and fallback to empty string.
		}

		return '';
	}

	function isActivityDescriptionEditorMounted(editor) {
		if (!editor || typeof editor.getContainer !== 'function') {
			return false;
		}

		var container = editor.getContainer();
		if (!container || !container.parentNode || !document.body.contains(container)) {
			return false;
		}

		var wrap = document.getElementById('wp-sd-activity-description-wrap');
		if (wrap && !wrap.contains(container)) {
			return false;
		}

		return true;
	}

	function isActivityDescriptionEditorHealthy(editor) {
		if (!editor || !isActivityDescriptionEditorMounted(editor)) {
			return false;
		}

		try {
			var body = (typeof editor.getBody === 'function') ? editor.getBody() : null;
			var doc = (typeof editor.getDoc === 'function') ? editor.getDoc() : null;
			return !!(body && doc);
		} catch (err) {
			return false;
		}
	}

	function ensureActivityDescriptionVisualTabActive() {
		if (window.switchEditors && typeof window.switchEditors.go === 'function') {
			try {
				window.switchEditors.go('sd-activity-description', 'tmce');
			} catch (err) {
				// Keep current mode if switch fails.
			}
		}
	}

	function syncActivityDescriptionMode(mode) {
		var $textarea = $('#sd-activity-description');
		if (!$textarea.length) {
			return;
		}

		if (mode === 'tmce') {
			ensureActivityDescriptionVisualTabActive();
		}

		var editor = getActivityDescriptionEditor();
		if (mode === 'html') {
			if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
				try {
					window.tinymce.triggerSave();
				} catch (err) {
					// Continue with manual sync fallback.
				}
			}

			syncActivityDescriptionHtmlTextareaFromEditor();
			if (editor) {
				try {
					editor.save();
				} catch (err) {
					// Keep textarea sync fallback only.
				}
			}
			return;
		}

		if (!editor) {
			var textareaOnly = normalizeActivityDescriptionHtml($textarea.val() || state.descriptionLastKnownHtml || '');
			state.descriptionPendingValue = textareaOnly;
			state.descriptionLastKnownHtml = textareaOnly;
			return;
		}

		var sourceHtml = state.descriptionPendingValue !== null
			? String(state.descriptionPendingValue)
			: String($textarea.val() || state.descriptionLastKnownHtml || '');

		try {
			editor.setContent(sourceHtml || '');
			editor.save();
			state.descriptionLastKnownHtml = normalizeActivityDescriptionHtml(sourceHtml || '');
			state.descriptionPendingValue = null;
		} catch (err2) {
			state.descriptionPendingValue = sourceHtml;
		}
	}

	function syncActivityDescriptionHtmlTextareaFromEditor() {
		var content = getActivityDescriptionContentFromEditor();

		if (!content && state.descriptionPendingValue !== null) {
			content = normalizeActivityDescriptionHtml(String(state.descriptionPendingValue || ''));
		}

		if (!content) {
			content = normalizeActivityDescriptionHtml(String($('#sd-activity-description').val() || ''));
		}

		if (!content && state.descriptionLastKnownHtml) {
			content = normalizeActivityDescriptionHtml(String(state.descriptionLastKnownHtml || ''));
		}

		if (!content && state.currentActivity && state.currentActivity.description) {
			content = normalizeActivityDescriptionHtml(String(state.currentActivity.description || ''));
		}

		var $targets = $('#wp-sd-activity-description-wrap textarea.wp-editor-area, #sd-activity-description');
		if ($targets.length) {
			$targets.each(function () {
				$(this).val(content);
			});
		}

		state.descriptionLastKnownHtml = content;
		state.descriptionPendingValue = content;
	}

	function syncActivityDescriptionVisualFromTextareaWithRetry(maxTries, delayMs) {
		var tries = 0;
		var max = parseInt(maxTries, 10);
		if (!max || max < 1) {
			max = 8;
		}
		var delay = parseInt(delayMs, 10);
		if (!delay || delay < 20) {
			delay = 80;
		}

		function applySync() {
			tries += 1;
			var editor = getActivityDescriptionEditor();
			if (!editor) {
				if (tries < max) {
					window.setTimeout(applySync, delay);
				}
				return;
			}

			var html = normalizeActivityDescriptionHtml(
				String($('#sd-activity-description').val() || state.descriptionPendingValue || state.descriptionLastKnownHtml || '')
			);

			try {
				editor.setContent(html || '');
				editor.save();
				state.descriptionLastKnownHtml = html;
				state.descriptionPendingValue = null;
			} catch (err) {
				if (tries < max) {
					window.setTimeout(applySync, delay);
				}
			}
		}

		window.setTimeout(applySync, delay);
	}

	function waitForActivityDescriptionEditor() {
		var tries = 0;
		var maxTries = 30;

		function applyEditableState() {
			tries += 1;
			var ready = ensureActivityDescriptionEditable();
			if (!ready && tries < maxTries) {
				window.setTimeout(applyEditableState, 200);
				return;
			}

			if (!ready && visualDescriptionEditorEnabled) {
				rebuildActivityDescriptionEditor();
				return;
			}

			if (!ready && !visualDescriptionEditorEnabled) {
				activateActivityDescriptionHtmlFallback();
			}
		}

		window.setTimeout(applyEditableState, 120);
	}

	function ensureActivityDescriptionEditable() {
		var $textarea = $('#sd-activity-description');
		if (!$textarea.length) {
			return true;
		}

		$textarea.prop('readonly', false).prop('disabled', false);
		$textarea.css({
			'pointer-events': 'auto',
			'user-select': 'text',
			'cursor': 'text',
		});

		if (!window.tinymce || typeof window.tinymce.get !== 'function') {
			return true;
		}

		var editor = getActivityDescriptionEditor();
		if (!editor) {
			return false;
		}

		try {
			if (typeof editor.setMode === 'function') {
				editor.setMode('design');
			}
		} catch (err) {
			// Ignore and keep fallback textarea editable.
		}

		var body = (typeof editor.getBody === 'function') ? editor.getBody() : null;
		if (body) {
			body.setAttribute('contenteditable', 'true');
			body.classList.remove('mce-content-readonly');
			body.style.cursor = 'text';
			body.style.userSelect = 'text';
			body.style.pointerEvents = 'auto';
			body.style.minHeight = '220px';
			body.style.backgroundColor = '#ffffff';
			body.style.color = '#1d2327';
		}

		if (typeof editor.getDoc === 'function' && editor.getDoc()) {
			try {
				editor.getDoc().designMode = 'on';
			} catch (err2) {
				// Ignore and continue.
			}
		}

		var isEditable = !!(body && body.getAttribute('contenteditable') !== 'false');
		if (!isEditable) {
			return false;
		}

		if (typeof editor.save === 'function') {
			editor.save();
		}

		return true;
	}

	function activateActivityDescriptionHtmlFallback() {
		if (window.switchEditors && typeof window.switchEditors.go === 'function') {
			window.switchEditors.go('sd-activity-description', 'html');
		}

		var $textarea = $('#sd-activity-description');
		$textarea.prop('readonly', false).prop('disabled', false).show();
	}

	function getActivityDescriptionValue() {
		if (!visualDescriptionEditorEnabled) {
			var htmlOnly = normalizeActivityDescriptionHtml($('#sd-activity-description').val() || state.descriptionLastKnownHtml || '');
			state.descriptionLastKnownHtml = htmlOnly;
			return htmlOnly;
		}

		var editor = getActivityDescriptionEditor();
		if (editor) {
			try {
				editor.save();
				var fromEditor = normalizeActivityDescriptionHtml(editor.getContent());
				state.descriptionLastKnownHtml = fromEditor;
				return fromEditor;
			} catch (err) {
				var fromTextarea = normalizeActivityDescriptionHtml($('#sd-activity-description').val() || state.descriptionLastKnownHtml || '');
				state.descriptionLastKnownHtml = fromTextarea;
				return fromTextarea;
			}
		}

		var fallback = normalizeActivityDescriptionHtml($('#sd-activity-description').val() || state.descriptionLastKnownHtml || '');
		state.descriptionLastKnownHtml = fallback;
		return fallback;
	}

	function setActivityDescriptionValue(value) {
		var text = normalizeActivityDescriptionHtml(String(value || ''));
		$('#sd-activity-description').val(text);
		state.descriptionPendingValue = text;
		state.descriptionLastKnownHtml = text;
		if (state.currentActivity && typeof state.currentActivity === 'object') {
			state.currentActivity.description = text;
		}

		if (!visualDescriptionEditorEnabled) {
			forceActivityDescriptionHtmlMode();
			return;
		}

		syncActivityDescriptionMode('tmce');
	}

	function refreshActivityDescriptionVisualEditor() {
		if (!visualDescriptionEditorEnabled) {
			return;
		}

		var editor = getActivityDescriptionEditor();
		if (!editor) {
			return;
		}

		syncActivityDescriptionMode('tmce');

		try {
			if (typeof editor.show === 'function') {
				editor.show();
			}
			if (typeof editor.execCommand === 'function') {
				editor.execCommand('mceRepaint');
			}
		} catch (err) {
			// Ignore repaint errors.
		}

		window.setTimeout(waitForActivityDescriptionEditor, 120);
	}

	function scheduleActivityDescriptionRefresh(expectedHtml, delayMs, enforceContent) {
		if (state.descriptionRefreshTimer) {
			window.clearTimeout(state.descriptionRefreshTimer);
			state.descriptionRefreshTimer = null;
		}

		var delay = parseInt(delayMs, 10);
		if (!delay || delay < 0) {
			delay = 80;
		}

		state.descriptionRefreshTimer = window.setTimeout(function () {
			state.descriptionRefreshTimer = null;
			ensureActivityDescriptionVisualTabActive();
			initActivityDescriptionEditor();
			refreshActivityDescriptionVisualEditor();
			if (enforceContent) {
				ensureActivityDescriptionContent(expectedHtml || '');
			}
			syncActivityDescriptionVisualFromTextareaWithRetry(10, 80);
			syncActivityDescriptionHtmlTextareaFromEditor();
			cleanupActivityDescriptionEditorUi();
		}, delay);
	}

	function ensureActivityDescriptionContent(expectedHtml) {
		var desired = normalizeActivityDescriptionHtml(expectedHtml || '');
		var $textarea = $('#sd-activity-description');
		if ($textarea.length) {
			var textareaValue = normalizeActivityDescriptionHtml($textarea.val() || '');
			if (desired && textareaValue !== desired) {
				$textarea.val(desired);
			}
		}

		if (desired) {
			state.descriptionLastKnownHtml = desired;
		}

		if (!visualDescriptionEditorEnabled) {
			if (desired) {
				state.descriptionPendingValue = desired;
				state.descriptionLastKnownHtml = desired;
			}
			return;
		}

		var editor = getActivityDescriptionEditor();
		if (!editor) {
			if (desired) {
				state.descriptionPendingValue = desired;
				state.descriptionLastKnownHtml = desired;
			}
			return;
		}

		try {
			var current = normalizeActivityDescriptionHtml(editor.getContent() || '');
			if (desired && current !== desired) {
				editor.setContent(desired);
				editor.save();
				state.descriptionLastKnownHtml = desired;

				var updated = normalizeActivityDescriptionHtml(editor.getContent() || '');
				if (updated !== desired) {
					state.descriptionPendingValue = desired;
					destroyActivityDescriptionEditor();
					window.setTimeout(function () {
						initActivityDescriptionEditor();
						refreshActivityDescriptionVisualEditor();
					}, 80);
					return;
				}
			}
			state.descriptionPendingValue = null;
		} catch (err) {
			if (desired) {
				state.descriptionPendingValue = desired;
				state.descriptionLastKnownHtml = desired;
			}
		}

		window.setTimeout(waitForActivityDescriptionEditor, 120);
	}

	function normalizeActivityDescriptionHtml(value) {
		var html = String(value || '');
		if (!html) {
			return '';
		}

		html = html.replace(/<p>\s*<br\s*\/?>\s*<\/p>/gi, '<p>&nbsp;</p>');

		return html;
	}

	function compareActivityDescriptionHtml(left, right) {
		var normalizedLeft = normalizeActivityDescriptionHtml(left || '');
		var normalizedRight = normalizeActivityDescriptionHtml(right || '');

		normalizedLeft = normalizedLeft.replace(/\s+/g, ' ').replace(/>\s+</g, '><').trim();
		normalizedRight = normalizedRight.replace(/\s+/g, ' ').replace(/>\s+</g, '><').trim();

		return normalizedLeft === normalizedRight;
	}

	function getFieldContentEditor() {
		if (!window.tinymce || typeof window.tinymce.get !== 'function') {
			return null;
		}

		return window.tinymce.get('sd-field-content-editor') || null;
	}

	function destroyFieldContentEditor() {
		var editor = getFieldContentEditor();
		if (editor) {
			try {
				if (typeof editor.save === 'function') {
					editor.save();
				}
				if (typeof editor.remove === 'function') {
					editor.remove();
				}
			} catch (err) {
				// Ignore removal errors and continue with textarea fallback.
			}
		}

		$('#sd-field-content-editor').prop('readonly', false).prop('disabled', false).show();
	}

	function ensureFieldContentEditorEditable() {
		var $textarea = $('#sd-field-content-editor');
		if (!$textarea.length) {
			return true;
		}

		$textarea.prop('readonly', false).prop('disabled', false).show();
		$textarea.css({
			'pointer-events': 'auto',
			'user-select': 'text',
			'cursor': 'text',
		});

		var editor = getFieldContentEditor();
		if (!editor) {
			return false;
		}

		try {
			if (typeof editor.setMode === 'function') {
				editor.setMode('design');
			}
		} catch (err) {
			// Keep going with body/doc adjustments below.
		}

		var body = (typeof editor.getBody === 'function') ? editor.getBody() : null;
		if (body) {
			body.setAttribute('contenteditable', 'true');
			body.classList.remove('mce-content-readonly');
			body.style.pointerEvents = 'auto';
			body.style.userSelect = 'text';
			body.style.cursor = 'text';
			body.style.minHeight = '220px';
		}

		if (typeof editor.getDoc === 'function' && editor.getDoc()) {
			try {
				editor.getDoc().designMode = 'on';
			} catch (err2) {
				// Ignore doc mode errors.
			}
		}

		return !!(body && body.getAttribute('contenteditable') !== 'false');
	}

	function setFieldContentEditorValue(value, options) {
		var normalized = String(value || '');
		var opts = options || {};
		var editor = getFieldContentEditor();
		var $textarea = $('#sd-field-content-editor');

		$textarea.val(normalized).prop('readonly', false).prop('disabled', false);

		if (!editor) {
			return false;
		}

		try {
			editor.setContent(normalized);
			if (typeof editor.save === 'function') {
				editor.save();
			}
			ensureFieldContentEditorEditable();
			if (opts.focus && typeof editor.focus === 'function') {
				editor.focus();
			}
			return true;
		} catch (err) {
			return false;
		}
	}

	function initFieldContentEditor() {
		if (!window.tinymce || typeof window.tinymce.init !== 'function') {
			return;
		}

		var target = document.getElementById('sd-field-content-editor');
		if (!target) {
			return;
		}

		var existingEditor = getFieldContentEditor();
		if (existingEditor) {
			ensureFieldContentEditorEditable();
			return;
		}

		window.tinymce.init({
			target: target,
			plugins: 'link image media lists',
			toolbar: 'formatselect | bold italic underline | bullist numlist | link image | alignleft aligncenter alignright',
			menubar: 'edit insert format table',
			height: 250,
			setup: function (editor) {
				editor.on('init', function () {
					ensureFieldContentEditorEditable();
					editor.save();
				});
				editor.on('change keyup SetContent Undo Redo', function () {
					editor.save();
				});
			},
		});
	}

	function openMediaLibraryForActivityThumbnail(e) {
		if (e) {
			e.preventDefault();
		}

		if (!window.wp || !window.wp.media) {
			showMessage('warning', 'Media Library non disponibile in questa pagina.');
			return;
		}

		var mediaFrame = window.wp.media({
			title: 'Seleziona Immagine Attivita',
			button: { text: 'Usa Immagine' },
			multiple: false,
			library: { type: 'image' },
		});

		mediaFrame.on('open', function () {
			$('body').addClass('sd-activity-media-fix-open');
		});

		mediaFrame.on('close', function () {
			$('body').removeClass('sd-activity-media-fix-open');
		});

		mediaFrame.on('select', function () {
			var attachment = mediaFrame.state().get('selection').first();
			if (!attachment || !attachment.attributes || !attachment.attributes.url) {
				return;
			}

			$('#sd-activity-thumbnail').val(String(attachment.attributes.url));
			updateActivityThumbnailPreview();
		});

		mediaFrame.open();
	}

	function updateActivityThumbnailPreview() {
		var $preview = $('#sd-activity-thumb-preview');
		if (!$preview.length) {
			return;
		}

		var url = String($('#sd-activity-thumbnail').val() || '').trim();
		if (!url) {
			$preview.html('<div class="sd-activity-thumb-placeholder">Anteprima immagine attivita</div>');
			return;
		}

		$preview.html('<img src="' + esc(url) + '" alt="Anteprima immagine attività" loading="lazy">');
	}

	function deleteActivity(activityId) {
		if (!activityId || !window.confirm(sdActivityAdmin.strings.confirmDelete)) {
			return;
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_delete',
			nonce: sdActivityAdmin.nonce,
			activity_id: activityId,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			if (state.selectedActivityId === activityId) {
				resetActivityForm();
			}
			showMessage('success', resp.data.message || 'Attivita eliminata.');
			loadActivities();
		});
	}

	function savePrice(e) {
		e.preventDefault();

		if (!state.selectedActivityId) {
			showMessage('error', sdActivityAdmin.strings.saveFirst);
			return;
		}

		var priceName = $('#sd-price-name').val();
		var priceId = parseInt($('#sd-price-id').val(), 10) || 0;
		var priceChf = parseFloat($('#sd-price-chf').val() || 0);
		var priceEur = parseFloat($('#sd-price-eur').val() || 0);
		var isDefault = $('#sd-price-is-default').is(':checked') ? 1 : 0;
		var currentRate = parseFloat((window.sdActivityAdmin && window.sdActivityAdmin.currentChfEurRate) || 0);
		if (!priceName || priceChf <= 0) {
			showMessage('error', 'Inserisci nome tariffa e importo CHF valido.');
			return;
		}

		if (priceEur <= 0) {
			priceEur = getPriceEurWithCurrentRate(priceChf, 0);
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_price_save',
			nonce: sdActivityAdmin.nonce,
			activity_id: state.selectedActivityId,
			price: {
				id: priceId,
				price_name: priceName,
				price_chf: priceChf,
				price_eur: priceEur,
				currency_rate: currentRate,
				currency_rate_date: todayYmd(),
				is_default: isDefault,
			},
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			resetPriceForm();
			showMessage('success', resp.data.message || 'Tariffa salvata.');
			editActivity(state.selectedActivityId);
		});
	}

	function formatRateNote(rate) {
		var parsedRate = parseFloat(rate || 0);
		if (parsedRate > 0) {
			return 'Cambio del giorno: 1 CHF = EUR ' + num(parsedRate) + '.';
		}

		if (!state.selectedActivityId) {
			return priceRateNoteDefault;
		}

		return 'Tasso CHF/EUR non disponibile ora. L\'EUR verra calcolato al salvataggio se il cambio del giorno e disponibile.';
	}

	function updatePriceRateNote(text) {
		var $note = $('#sd-price-rate-note');
		if ($note.length) {
			$note.text(text || '');
		}
	}

	function resetPriceForm() {
		$('#sd-price-id').val('0');
		$('#sd-price-name').val('');
		$('#sd-price-chf').val('');
		$('#sd-price-eur').val('');
		$('#sd-price-is-default').prop('checked', false);
		$('#sd-price-submit-btn').text('Aggiungi Tariffa');
		$('#sd-price-cancel-edit').hide();
		updatePriceRateNote(formatRateNote((window.sdActivityAdmin && window.sdActivityAdmin.currentChfEurRate) || 0));
	}

	function startPriceEdit(priceId) {
		if (!priceId) {
			return;
		}

		var prices = (state.currentActivity && Array.isArray(state.currentActivity.prices)) ? state.currentActivity.prices : [];
		var price = prices.find(function (row) {
			return parseInt(row.id, 10) === parseInt(priceId, 10);
		});

		if (!price) {
			showMessage('error', 'Tariffa non trovata.');
			return;
		}

		$('#sd-price-id').val(String(parseInt(price.id, 10)));
		$('#sd-price-name').val(price.price_name || '');
		$('#sd-price-chf').val((parseFloat(price.price_chf || 0)).toFixed(2));
		$('#sd-price-eur').val((parseFloat(price.price_eur || 0)).toFixed(2));
		$('#sd-price-is-default').prop('checked', !!price.is_default);
		$('#sd-price-submit-btn').text('Aggiorna Tariffa');
		$('#sd-price-cancel-edit').show();
		updatePriceEurPreview();
	}

	function deletePrice(priceId) {
		if (!priceId || !state.selectedActivityId) {
			return;
		}

		if (!window.confirm('Eliminare questa tariffa?')) {
			return;
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_price_delete',
			nonce: sdActivityAdmin.nonce,
			activity_id: state.selectedActivityId,
			price_id: priceId,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			resetPriceForm();
			showMessage('success', (resp && resp.data && resp.data.message) ? resp.data.message : 'Tariffa eliminata.');
			editActivity(state.selectedActivityId);
		});
	}

	function setDefaultPrice(priceId) {
		if (!priceId || !state.selectedActivityId) {
			return;
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_price_set_default',
			nonce: sdActivityAdmin.nonce,
			activity_id: state.selectedActivityId,
			price_id: priceId,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			showMessage('success', (resp && resp.data && resp.data.message) ? resp.data.message : 'Tariffa predefinita aggiornata.');
			editActivity(state.selectedActivityId);
		});
	}

	function updatePriceEurPreview() {
		var rawValue = String($('#sd-price-chf').val() || '').trim();
		var chf = parseFloat(rawValue);
		var rate = parseFloat((window.sdActivityAdmin && window.sdActivityAdmin.currentChfEurRate) || 0);

		if (!rawValue) {
			$('#sd-price-eur').val('');
			updatePriceRateNote(formatRateNote(rate));
			return;
		}

		if (isNaN(chf) || chf <= 0) {
			$('#sd-price-eur').val('');
			updatePriceRateNote('Inserisci un importo CHF valido maggiore di zero.');
			return;
		}

		if (rate > 0) {
			$('#sd-price-eur').val((chf * rate).toFixed(2));
			updatePriceRateNote(formatRateNote(rate));
			return;
		}

		$('#sd-price-eur').val('');
		updatePriceRateNote(formatRateNote(0));
	}

	var fieldOptions = [];

	function toggleFieldOptions() {
		var type = $('#sd-field-type').val();
		var needsOptions = (type === 'radio' || type === 'select' || type === 'checkbox');
		var isContent = (type === 'content');
		var isImage = (type === 'image');
		
		$('#sd-field-options-wrap').toggle(needsOptions);
		$('#sd-field-content-wrap').toggle(isContent);
		$('#sd-field-image-wrap').toggle(isImage);
		
		if (!needsOptions) {
			fieldOptions = [];
			renderOptionsPreview();
		}
		
		// Initialize TinyMCE for formatted-content field with explicit target.
		if (isContent && window.tinymce) {
			window.setTimeout(function () {
				initFieldContentEditor();
				ensureFieldContentEditorEditable();
			}, 100);
		}
	}

	function toggleImageSourceInputs() {
		var type = $('input[name="sd-image-type"]:checked').val();
		var isDisplay = (type === 'display');
		$('#sd-image-source-wrap').toggle(isDisplay);
	}

	function openMediaLibraryForImage(e) {
		if (e) {
			e.preventDefault();
		}
		console.log('openMediaLibraryForImage called', window.wp, window.wp ? window.wp.media : 'undefined');
		if (!window.wp || !window.wp.media) {
			console.error('wp.media not available');
			return;
		}
		var mediaFrame = window.wp.media({
			title: 'Seleziona Immagine',
			button: { text: 'Usa Immagine' },
			multiple: false,
			library: { type: 'image' }
		});

		mediaFrame.on('select', function () {
			var attachment = mediaFrame.state().get('selection').first();
			console.log('Image selected:', attachment);
			if (attachment) {
				$('#sd-image-url').val(attachment.attributes.url);
				if (!$('#sd-image-alt-text').val()) {
					$('#sd-image-alt-text').val(attachment.attributes.alt || attachment.attributes.title || '');
				}
				updateImagePreview();
			}
		});

		mediaFrame.open();
	}

	function updateImagePreview() {
		var $preview = $('#sd-image-preview');
		if (!$preview.length) {
			return;
		}

		var imageType = $('input[name="sd-image-type"]:checked').val();
		var imageUrl = $('#sd-image-url').val();
		var imageWidth = parseInt($('#sd-image-width').val(), 10) || 'auto';
		var imageHeight = parseInt($('#sd-image-height').val(), 10) || 'auto';
		var imageAlignH = $('input[name="sd-image-align-h"]:checked').val() || 'left';
		var imageAlignV = $('input[name="sd-image-align-v"]:checked').val() || 'top';
		var maintainAspect = $('#sd-image-aspect-ratio').is(':checked');

		// Map vertical alignment to align-items
		var alignItemsMap = {
			'top': 'flex-start',
			'middle': 'center',
			'bottom': 'flex-end'
		};

		// Map horizontal alignment to justify-content
		var justifyContentMap = {
			'left': 'flex-start',
			'center': 'center',
			'right': 'flex-end'
		};

		if (imageType === 'display' && imageUrl) {
			// Show image preview
			var styleAttr = '';
			if (imageWidth !== 'auto' || imageHeight !== 'auto') {
				styleAttr += ' style="';
				if (imageWidth !== 'auto') {
					styleAttr += 'width: ' + imageWidth + 'px; ';
				}
				if (imageHeight !== 'auto') {
					styleAttr += 'height: ' + imageHeight + 'px; ';
				}
				if (maintainAspect) {
					styleAttr += 'object-fit: contain; ';
				}
				styleAttr += '"';
			}

			var previewHtml = '<img src="' + esc(imageUrl) + '" alt="Preview"' + styleAttr + '>';
			$preview.css({
				'justify-content': justifyContentMap[imageAlignH] || 'flex-start',
				'align-items': alignItemsMap[imageAlignV] || 'flex-start'
			}).html(previewHtml);
		} else {
			// Show placeholder
			$preview.css({
				'justify-content': 'center',
				'align-items': 'center'
			}).html('<div class="sd-image-preview-placeholder">' + (imageType === 'upload' ? 'Anteprima upload immagine' : 'Anteprima immagine') + '</div>');
		}
	}

	function toggleFieldSectionInputs() {
		var selected = $('#sd-field-section').val();
		var isNewSection = selected === '__new__';
		$('#sd-custom-section-wrap').toggle(isNewSection);
		if (!isNewSection) {
			var option = $('#sd-field-section option:selected');
			$('#sd-field-section-order').val(option.data('order') || inferSectionOrder(selected));
		}
	}

	function inferSectionOrder(sectionKey) {
		var found = getAllSections().find(function (section) {
			return section.key === sectionKey;
		});
		return found ? parseInt(found.order, 10) : 20;
	}

	function getAllSections() {
		var custom = [];
		state.currentFields.forEach(function (field) {
			var isDefault = defaultSections.some(function (section) {
				return section.key === field.section_key;
			});
			if (isDefault || !field.section_key) {
				return;
			}
			if (!custom.some(function (section) { return section.key === field.section_key; })) {
				custom.push({
					key: field.section_key,
					label: field.section_label || field.section_key,
					order: parseInt(field.section_order || 20, 10),
				});
			}
		});
		return defaultSections.concat(custom.sort(function (a, b) { return a.order - b.order; }));
	}

	function populateFieldSectionSelect(fields) {
		var html = '';
		var customSections = [];
		(fields || []).forEach(function (field) {
			var isDefault = defaultSections.some(function (section) {
				return section.key === field.section_key;
			});
			if (isDefault || !field.section_key) {
				return;
			}
			if (!customSections.some(function (section) { return section.key === field.section_key; })) {
				customSections.push({
					key: field.section_key,
					label: field.section_label || field.section_key,
					order: parseInt(field.section_order || 20, 10),
				});
			}
		});

		defaultSections.forEach(function (section) {
			html += '<option value="' + esc(section.key) + '" data-order="' + parseInt(section.order, 10) + '">Sezione: ' + esc(section.label) + '</option>';
		});
		customSections.sort(function (a, b) { return a.order - b.order; }).forEach(function (section) {
			html += '<option value="' + esc(section.key) + '" data-order="' + parseInt(section.order, 10) + '">Sezione personalizzata: ' + esc(section.label) + '</option>';
		});
		html += '<option value="__new__">Nuova sezione personalizzata</option>';

		$('#sd-field-section').html(html).val('additional');
		$('#sd-field-section-order').val('20');
		toggleFieldSectionInputs();
		populateConditionSourceFieldSelect(0);
	}

	function getFieldConditionsMap() {
		var formConfig = getFormConfig();
		if (!formConfig || typeof formConfig !== 'object' || typeof formConfig.field_conditions !== 'object' || !formConfig.field_conditions) {
			return {};
		}
		return formConfig.field_conditions;
	}

	function getConditionSourceOptionsHtml(targetFieldId) {
		var html = '<option value="">Seleziona campo sorgente</option>';
		var targetId = parseInt(targetFieldId, 10) || 0;

		(state.currentFields || []).forEach(function (field) {
			var sourceId = parseInt(field.id, 10) || 0;
			if (!sourceId || sourceId === targetId || field.field_type === 'content') {
				return;
			}
			html += '<option value="' + sourceId + '">' + esc(field.field_label || ('Campo #' + sourceId)) + '</option>';
		});

		return html;
	}

	function addConditionRuleRow(rule) {
		var targetId = parseInt($('#sd-field-id').val(), 10) || 0;
		var sourceId = rule && rule.source_field_id ? parseInt(rule.source_field_id, 10) : 0;
		var operator = (rule && rule.operator === 'not_equals') ? 'not_equals' : 'equals';
		var value = rule && rule.value ? String(rule.value) : '';
		var sourceOptions = getConditionSourceOptionsHtml(targetId);
		var html = '';

		html += '<div class="sd-field-builder-meta sd-condition-rule-row">';
		html += '<select class="sd-select sd-condition-source-field">' + sourceOptions + '</select>';
		html += '<select class="sd-select sd-condition-operator">';
		html += '<option value="equals">È uguale a</option>';
		html += '<option value="not_equals">È diverso da</option>';
		html += '</select>';
		html += '<input type="text" class="sd-input sd-condition-value" placeholder="Valore atteso (es. Non diabetico)">';
		html += '<button type="button" class="sd-btn sd-btn-danger sd-condition-rule-remove">Rimuovi</button>';
		html += '</div>';

		$('#sd-condition-rules').append(html);
		var $row = $('#sd-condition-rules .sd-condition-rule-row').last();
		$row.find('.sd-condition-source-field').val(sourceId ? String(sourceId) : '');
		$row.find('.sd-condition-operator').val(operator);
		$row.find('.sd-condition-value').val(value);
	}

	function collectConditionRulesFromForm() {
		var rules = [];
		$('#sd-condition-rules .sd-condition-rule-row').each(function () {
			var $row = $(this);
			var sourceId = parseInt($row.find('.sd-condition-source-field').val(), 10) || 0;
			var operator = $row.find('.sd-condition-operator').val() === 'not_equals' ? 'not_equals' : 'equals';
			var value = String($row.find('.sd-condition-value').val() || '').trim();

			if (!sourceId || value === '') {
				return;
			}

			rules.push({
				source_field_id: sourceId,
				operator: operator,
				value: value,
			});
		});

		return rules;
	}

	function populateConditionSourceFieldSelect(targetFieldId) {
		var targetId = parseInt(targetFieldId, 10) || 0;
		$('#sd-condition-rules .sd-condition-rule-row').each(function () {
			var $row = $(this);
			var selected = String($row.find('.sd-condition-source-field').val() || '');
			$row.find('.sd-condition-source-field').html(getConditionSourceOptionsHtml(targetId)).val(selected);
		});
	}

	function loadFieldConditionIntoForm(fieldId) {
		var map = getFieldConditionsMap();
		var key = String(parseInt(fieldId, 10) || 0);
		var raw = map[key] || null;
		var mode = 'and';
		var rules = [];

		if (raw && Array.isArray(raw.rules)) {
			mode = raw.mode === 'or' ? 'or' : 'and';
			rules = raw.rules;
		} else if (raw && raw.source_field_id) {
			// Backward compatibility: previous single-rule format.
			rules = [raw];
		}

		$('#sd-condition-mode').val(mode);
		$('#sd-condition-rules').empty();

		if (!rules.length) {
			return;
		}

		rules.forEach(function (rule) {
			addConditionRuleRow(rule);
		});

		populateConditionSourceFieldSelect(fieldId);
	}

	function saveFieldConditionRule(targetFieldId) {
		var targetId = parseInt(targetFieldId, 10) || 0;
		if (!targetId) {
			return $.Deferred().resolve().promise();
		}

		var mode = $('#sd-condition-mode').val() === 'or' ? 'or' : 'and';
		var rules = collectConditionRulesFromForm();
		var formConfig = $.extend(true, {}, getFormConfig());
		var map = (formConfig.field_conditions && typeof formConfig.field_conditions === 'object') ? formConfig.field_conditions : {};
		var key = String(targetId);

		if (!rules.length) {
			delete map[key];
		} else {
			map[key] = {
				mode: mode,
				rules: rules,
			};
		}

		formConfig.field_conditions = map;
		return saveFormConfiguration(formConfig, null, { noReload: true });
	}

	function addFieldOption() {
		var label = $('#sd-option-label').val().trim();
		var value = $('#sd-option-value').val().trim() || slugify(label);
		if (!label) {
			return;
		}
		fieldOptions.push({ label: label, value: value });
		$('#sd-option-label').val('');
		$('#sd-option-value').val('');
		renderOptionsPreview();
	}

	function renderOptionsPreview() {
		var html = '';
		if (!fieldOptions.length) {
			html = '<li class="sd-mini-list-empty">Nessuna opzione aggiunta.</li>';
		} else {
			fieldOptions.forEach(function (opt, idx) {
				html += '<li class="sd-option-item">';
				html += '<span>' + esc(opt.label) + ' <small>(' + esc(opt.value) + ')</small></span>';
				html += '<button type="button" class="sd-btn-remove-option" data-idx="' + idx + '">&#x2715;</button>';
				html += '</li>';
			});
		}
		$('#sd-options-preview').html(html);
		$('#sd-options-preview').off('click', '.sd-btn-remove-option').on('click', '.sd-btn-remove-option', function () {
			fieldOptions.splice(parseInt($(this).data('idx'), 10), 1);
			renderOptionsPreview();
		});
	}

	function saveField(e) {
		e.preventDefault();

		if (!state.selectedActivityId) {
			showMessage('error', sdActivityAdmin.strings.saveFirst);
			return;
		}

		var label = $('#sd-field-label').val().trim();
		var type = $('#sd-field-type').val();
		var fieldId = parseInt($('#sd-field-id').val(), 10) || 0;
		if (!label) {
			showMessage('error', 'Inserisci etichetta campo.');
			return;
		}

		var needsOptions = (type === 'radio' || type === 'select' || type === 'checkbox');
		var isContent = (type === 'content');
		var isImage = (type === 'image');
		
		if (needsOptions && fieldOptions.length === 0) {
			showMessage('error', 'Aggiungi almeno un\'opzione per questo tipo di campo.');
			return;
		}

		if (isImage) {
			var imageType = $('input[name="sd-image-type"]:checked').val();
			if (imageType === 'display') {
				var imageUrl = $('#sd-image-url').val().trim();
				if (!imageUrl) {
					showMessage('error', 'Inserisci l\'URL dell\'immagine o caricala dalla Media Library.');
					return;
				}
			}
		}

		var sectionValue = $('#sd-field-section').val();
		var sectionKey = sectionValue;
		var sectionLabel = '';
		var sectionOrder = parseInt($('#sd-field-section-order').val(), 10) || 20;

		if (sectionValue === '__new__') {
			sectionLabel = $('#sd-custom-section-label').val().trim();
			sectionKey = ($('#sd-custom-section-key').val().trim() || slugify(sectionLabel));
			if (!sectionLabel || !sectionKey) {
				showMessage('error', 'Inserisci titolo della nuova sezione.');
				return;
			}
			sectionKey = 'custom_' + slugify(sectionKey);
		} else {
			var section = getAllSections().find(function (item) { return item.key === sectionValue; });
			sectionLabel = section ? section.label : 'Informazioni Aggiuntive';
		}

		// Calcola field_order per la sezione specifica (non globale)
		var sectionFieldCount = 0;
		state.currentFields.forEach(function (f) {
			if ((f.section_key || 'additional') === sectionKey) {
				sectionFieldCount++;
			}
		});

		// Get content from editor if it's a content field
		var contentValue = '';
		if (isContent && window.tinymce) {
			if (typeof window.tinymce.triggerSave === 'function') {
				window.tinymce.triggerSave();
			}
			var editor = getFieldContentEditor();
			if (editor) {
				ensureFieldContentEditorEditable();
				contentValue = editor.getContent();
			} else {
				contentValue = $('#sd-field-content-editor').val();
			}
		} else if (isContent) {
			contentValue = $('#sd-field-content-editor').val();
		}

		// Collect image configuration if it's an image field
		var imageConfig = {};
		if (isImage) {
			imageConfig = {
				image_type: $('input[name="sd-image-type"]:checked').val(),
				image_url: $('#sd-image-url').val().trim(),
				image_width: parseInt($('#sd-image-width').val(), 10) || 0,
				image_height: parseInt($('#sd-image-height').val(), 10) || 0,
				image_aspect_ratio: $('#sd-image-aspect-ratio').is(':checked') ? 1 : 0,
				image_align_h: $('input[name="sd-image-align-h"]:checked').val(),
				image_align_v: $('input[name="sd-image-align-v"]:checked').val(),
				image_alt_text: $('#sd-image-alt-text').val().trim()
			};
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_update_form_field',
			nonce: sdActivityAdmin.nonce,
			activity_id: state.selectedActivityId,
			field_id: fieldId,
			field: {
				field_type: type,
				field_name: slugify(label),
				field_label: label,
				placeholder: '',
				is_required: isContent ? 0 : (isImage ? 0 : ($('#sd-field-required').is(':checked') ? 1 : 0)),
				section_key: sectionKey,
				section_label: sectionLabel,
				section_order: sectionOrder,
				field_order: fieldId ? getFieldOrder(fieldId) : (sectionFieldCount + 1),
				options: isImage ? imageConfig : (needsOptions ? fieldOptions : []),
				content: isContent ? contentValue : '',
			},
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			var savedFieldId = parseInt((resp.data && resp.data.field_id) ? resp.data.field_id : fieldId, 10) || fieldId;
			state.scrollToFieldId = null;
			var savedLabel = label;
			saveFieldConditionRule(savedFieldId).done(function () {
				state.pendingMessage = { type: 'success', text: (fieldId ? 'Campo \u201c' + savedLabel + '\u201d aggiornato.' : 'Campo \u201c' + savedLabel + '\u201d aggiunto.'), scrollTarget: 'top' };
				editActivity(state.selectedActivityId);
			}).fail(function () {
				state.pendingMessage = { type: 'error', text: 'Campo salvato, ma non \u00e8 stato possibile salvare la condizione.', scrollTarget: 'top' };
				editActivity(state.selectedActivityId);
			});
		});
	}

	function getFieldOrder(fieldId) {
		var field = state.currentFields.find(function (item) {
			return parseInt(item.id, 10) === parseInt(fieldId, 10);
		});
		return field ? parseInt(field.field_order || 1, 10) : ($('#sd-fields-list .sd-field-list-item[data-field-id]').length + 1);
	}

	function editField(fieldId) {
		var field = state.currentFields.find(function (item) {
			return parseInt(item.id, 10) === parseInt(fieldId, 10);
		});
		if (!field) {
			return;
		}

		$('#sd-field-id').val(field.id);
		$('#sd-field-label').val(field.field_label || '');
		$('#sd-field-type').val(field.field_type || 'text');
		$('#sd-field-required').prop('checked', !!field.is_required);
		$('#sd-field-section-order').val(parseInt(field.section_order || inferSectionOrder(field.section_key), 10));
		fieldOptions = Array.isArray(field.options) ? field.options.slice() : [];
		renderOptionsPreview();
		populateFieldSectionSelect(state.currentFields);
		populateConditionSourceFieldSelect(field.id);
		loadFieldConditionIntoForm(field.id);
		if ($('#sd-field-section option[value="' + field.section_key + '"]').length) {
			$('#sd-field-section').val(field.section_key);
			$('#sd-custom-section-label').val('');
			$('#sd-custom-section-key').val('');
		} else {
			$('#sd-field-section').val('__new__');
			$('#sd-custom-section-label').val(field.section_label || '');
			$('#sd-custom-section-key').val(String(field.section_key || '').replace(/^custom_/, ''));
		}
		toggleFieldOptions();
		toggleFieldSectionInputs();
		
		// Set content in editor if it's a content field
		if (field.field_type === 'content' && window.tinymce) {
			destroyFieldContentEditor();
			setTimeout(function () {
				initFieldContentEditor();
				if (!setFieldContentEditorValue(field.content || '', { focus: true })) {
					$('#sd-field-content-editor').val(field.content || '');
				}
				window.setTimeout(function () {
					ensureFieldContentEditorEditable();
					setFieldContentEditorValue(field.content || '', { focus: false });
				}, 160);
			}, 120);
		}

		// Load image configuration if it's an image field
		if (field.field_type === 'image') {
			console.log('Loading image field:', field.id, 'options:', field.options, 'type:', typeof field.options);
			var imgConfig = field.options || {};
			
			// Ensure options is an object if it's a string
			if (typeof imgConfig === 'string') {
				try {
					imgConfig = JSON.parse(imgConfig);
				} catch (e) {
					console.error('Failed to parse image config:', e);
					imgConfig = {};
				}
			}
			
			console.log('Parsed imgConfig:', imgConfig);
			
			if (Object.keys(imgConfig).length > 0) {
				$('input[name="sd-image-type"]').val([imgConfig.image_type || 'display']);
				$('#sd-image-url').val(imgConfig.image_url || '');
				$('#sd-image-width').val(imgConfig.image_width || '');
				$('#sd-image-height').val(imgConfig.image_height || '');
				$('#sd-image-aspect-ratio').prop('checked', imgConfig.image_aspect_ratio === 1 || imgConfig.image_aspect_ratio === true);
				$('input[name="sd-image-align-h"]').val([imgConfig.image_align_h || 'left']);
				$('input[name="sd-image-align-v"]').val([imgConfig.image_align_v || 'top']);
				$('#sd-image-alt-text').val(imgConfig.image_alt_text || '');
				toggleImageSourceInputs();
				updateImagePreview();
			}
		}
		
		$('#sd-field-submit-btn').text('Aggiorna Campo');
		$('#sd-field-cancel-edit, #sd-field-cancel-edit-secondary').show();
		switchTab('modifica');

		var $section = $('#sd-section-campi-modulo');
		if ($section.length) {
			$('html, body').animate({ scrollTop: $section.offset().top - 400 }, 280);
		}
	}

	function deleteField(fieldId) {
		if (!fieldId || !window.confirm('Eliminare questo campo?')) {
			return;
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_delete_form_field',
			nonce: sdActivityAdmin.nonce,
			activity_id: state.selectedActivityId,
			field_id: fieldId,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			showMessage('success', resp.data.message || 'Campo eliminato.');
			editActivity(state.selectedActivityId);
		});
	}

	function moveField(fieldId, direction) {
		if (!fieldId) {
			return;
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_move_form_field',
			nonce: sdActivityAdmin.nonce,
			activity_id: state.selectedActivityId,
			field_id: fieldId,
			direction: direction,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			state.pendingMessage = { type: 'success', text: 'Posizione campo aggiornata.', scrollTarget: 'top' };
			editActivity(state.selectedActivityId);
		});
	}

	function updateFieldPartialAjax(fieldId, partialData) {
		return $.ajax({
			url: sdActivityAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activity_update_form_field',
				nonce: sdActivityAdmin.nonce,
				activity_id: state.selectedActivityId,
				field_id: fieldId,
				field: partialData || {},
			},
		});
	}

	function saveFieldsOrderByIds(sectionKey, fieldIds) {
		if (!state.selectedActivityId || !sectionKey || !Array.isArray(fieldIds) || fieldIds.length < 2) {
			return;
		}

		var requests = fieldIds.map(function (fieldId, index) {
			return updateFieldPartialAjax(parseInt(fieldId, 10), {
				section_key: sectionKey,
				field_order: index + 1,
			});
		});

		$.when.apply($, requests).done(function () {
			state.pendingMessage = { type: 'success', text: 'Ordine campi aggiornato.', scrollTarget: 'top' };
			editActivity(state.selectedActivityId);
		}).fail(function () {
			showMessage('error', 'Errore nello spostamento campi.', 'top');
		});
	}

	function saveSectionsOrderByKeys(sectionKeys) {
		if (!state.selectedActivityId || !Array.isArray(sectionKeys) || sectionKeys.length < 2) {
			return;
		}

		var sections = getSectionInfo();
		var byKey = {};
		var requests = [];
		var meta = getSectionMeta();
		var metaChanged = false;

		sections.forEach(function (section) {
			byKey[section.key] = section;
		});

		var layoutOrder = [];
		sectionKeys.forEach(function (rawKey) {
			var normalizedKey = String(rawKey || '').trim();
			if (!normalizedKey) {
				return;
			}
			if (normalizedKey === 'tariffe') {
				normalizedKey = 'pricing';
			}
			if (layoutOrder.indexOf(normalizedKey) === -1) {
				layoutOrder.push(normalizedKey);
			}
		});

		var currentLayoutOrder = Array.isArray(meta.layout_order) ? meta.layout_order.slice() : [];
		if (JSON.stringify(currentLayoutOrder) !== JSON.stringify(layoutOrder)) {
			meta.layout_order = layoutOrder;
			metaChanged = true;
		}

		sectionKeys.forEach(function (key, index) {
			var section = byKey[key];
			var newOrder = (index + 1) * 10;
			if (!section) {
				return;
			}

			if (section.virtual) {
				meta[section.key] = meta[section.key] || {};
				meta[section.key].label = meta[section.key].label || section.label;
				if (parseInt(meta[section.key].order || 0, 10) !== newOrder) {
					meta[section.key].order = newOrder;
					metaChanged = true;
				}
				return;
			}

			section.fields.forEach(function (field) {
				requests.push(updateFieldPartialAjax(parseInt(field.id, 10), {
					section_order: newOrder,
				}));
			});
		});

		var fieldsPromise = requests.length ? $.when.apply($, requests) : $.Deferred().resolve().promise();

		fieldsPromise.done(function () {
			if (metaChanged) {
				saveSectionMeta(meta, 'Ordine sezioni aggiornato.', 'top');
				return;
			}
			showMessage('success', 'Ordine sezioni aggiornato.', 'top');
			editActivity(state.selectedActivityId);
		}).fail(function () {
			showMessage('error', 'Errore nello spostamento sezioni.', 'top');
		});
	}

	function initDragAndDropSortables() {
		if (typeof $.fn.sortable !== 'function') {
			return;
		}

		var $activityBlocksList = $('#sd-activity-static-order-controls .sd-mini-list');
		if ($activityBlocksList.length) {
			if ($activityBlocksList.data('ui-sortable')) {
				$activityBlocksList.sortable('destroy');
			}

			$activityBlocksList.sortable({
				items: '> li[data-activity-block-key]',
				handle: '.sd-field-list-item-static',
				cancel: '.sd-field-list-actions, .sd-field-list-actions *',
				placeholder: 'sd-sortable-placeholder sd-sortable-placeholder-field',
				tolerance: 'pointer',
				start: function (event, ui) {
					ui.placeholder.height(ui.item.outerHeight());
				},
				update: function () {
					var keys = [];
					$activityBlocksList.children('li[data-activity-block-key]').each(function () {
						var key = String($(this).data('activityBlockKey') || '');
						if (key) {
							keys.push(key);
						}
					});
					saveActivityBlocksOrderByKeys(keys);
				},
			});
		}

		var $board = $('#sd-fields-list .sd-sections-board');
		if ($board.length) {
			if ($board.data('ui-sortable')) {
				$board.sortable('destroy');
			}

			$board.sortable({
				items: '> .sd-section-card',
				handle: '.sd-section-card-head',
				placeholder: 'sd-sortable-placeholder sd-sortable-placeholder-section',
				tolerance: 'pointer',
				start: function (event, ui) {
					ui.placeholder.height(ui.item.outerHeight());
				},
				update: function () {
					var keys = [];
					$board.children('.sd-section-card').each(function () {
						var key = String($(this).data('sectionKey') || '');
						if (key) {
							keys.push(key);
						}
					});
					saveSectionsOrderByKeys(keys);
				},
			});
		}

		$('#sd-fields-list .sd-section-card-list').each(function () {
			var $list = $(this);
			var sectionKey = String($list.data('sectionKey') || '');
			var isVirtual = String($list.data('virtual') || '0') === '1';

			if (sectionKey === 'personal') {
				if ($list.data('ui-sortable')) {
					$list.sortable('destroy');
				}

				$list.sortable({
					items: '> li[data-personal-token]',
					handle: '.sd-field-list-item, .sd-field-list-item-static',
					cancel: '.sd-field-list-actions, .sd-field-list-actions *',
					placeholder: 'sd-sortable-placeholder sd-sortable-placeholder-field',
					tolerance: 'pointer',
					start: function (event, ui) {
						ui.placeholder.height(ui.item.outerHeight());
					},
					update: function () {
						var tokens = [];
						$list.children('li[data-personal-token]').each(function () {
							var token = String($(this).data('personalToken') || '');
							if (token) {
								tokens.push(token);
							}
						});
						savePersonalFieldOrderByTokens(tokens, 'Ordine campi Dati Personali aggiornato.');
					},
				});

				return;
			}

			if (!sectionKey || isVirtual) {
				return;
			}

			if ($list.data('ui-sortable')) {
				$list.sortable('destroy');
			}

			$list.sortable({
				items: '> li[data-field-id]',
				handle: '.sd-field-list-item',
				cancel: '.sd-field-list-actions, .sd-field-list-actions *',
				placeholder: 'sd-sortable-placeholder sd-sortable-placeholder-field',
				tolerance: 'pointer',
				start: function (event, ui) {
					ui.placeholder.height(ui.item.outerHeight());
				},
				update: function () {
					var ids = [];
					$list.children('li[data-field-id]').each(function () {
						ids.push(parseInt($(this).data('fieldId'), 10) || 0);
					});
					ids = ids.filter(function (id) { return id > 0; });
					saveFieldsOrderByIds(sectionKey, ids);
				},
			});
		});
	}

	function renderPricesList(prices) {
		var html = '';
		if (!prices.length) {
			html = '<li class="sd-mini-list-empty">Nessuna tariffa configurata.</li>';
		} else {
			prices.forEach(function (price) {
				var priceId = parseInt(price.id, 10) || 0;
				var isDefault = !!price.is_default;
				html += '<li class="sd-price-item' + (isDefault ? ' is-default' : '') + '">';
				html += '<div class="sd-price-item-main">';
				html += '<strong>' + esc(price.price_name) + '</strong>';
				if (isDefault) {
					html += ' <span class="sd-status-badge sd-status-published">Predefinita</span>';
				}
				html += '<div class="sd-field-list-meta"><span>CHF ' + num(price.price_chf) + ' / EUR ' + num(price.price_eur) + '</span></div>';
				html += '</div>';
				html += '<div class="sd-price-item-actions">';
				html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-price-edit" data-id="' + priceId + '">Modifica</button>';
				html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-price-set-default" data-id="' + priceId + '">Predefinita</button>';
				html += '<button type="button" class="sd-btn sd-btn-danger sd-btn-sm sd-price-delete" data-id="' + priceId + '">Elimina</button>';
				html += '</div>';
				html += '</li>';
			});
		}
		$('#sd-prices-list').html(html);
	}

	function getSectionMetaDefaults() {
		return {
			activity_data: { label: 'Dati Attivita', order: 5 },
			tariffe: { label: 'Tariffe', order: 15 },
		};
	}

	function getSectionMeta() {
		var defaults = getSectionMetaDefaults();
		var meta = {};
		var formConfig = getFormConfig();

		if (formConfig.section_meta && typeof formConfig.section_meta === 'object') {
			meta = $.extend(true, {}, formConfig.section_meta);
		}

		Object.keys(defaults).forEach(function (key) {
			if (!meta[key]) {
				meta[key] = {};
			}
			if (!meta[key].label) {
				meta[key].label = defaults[key].label;
			}
			if (!meta[key].order) {
				meta[key].order = defaults[key].order;
			}
		});

		return meta;
	}

	function getFormConfig() {
		return (state.currentActivity && state.currentActivity.form_configuration && typeof state.currentActivity.form_configuration === 'object')
			? state.currentActivity.form_configuration
			: {};
	}

	function getOrderedPersonalBaseFields() {
		var config = getFormConfig();
		var defaultOrder = Object.keys(personalBaseFieldsMap);
		var savedOrder = Array.isArray(config.personal_base_field_order) ? config.personal_base_field_order : [];
		var mergedOrder = [];

		savedOrder.forEach(function (key) {
			if (personalBaseFieldsMap[key] && mergedOrder.indexOf(key) === -1) {
				mergedOrder.push(key);
			}
		});

		defaultOrder.forEach(function (key) {
			if (mergedOrder.indexOf(key) === -1) {
				mergedOrder.push(key);
			}
		});

		return mergedOrder.map(function (key) {
			return personalBaseFieldsMap[key];
		});
	}

	function getPersonalFieldSpanMap() {
		var config = getFormConfig();
		return (config.personal_field_spans && typeof config.personal_field_spans === 'object') ? config.personal_field_spans : {};
	}

	function getPersonalFieldToken(field) {
		if (!field) {
			return '';
		}

		if (field.key && personalBaseFieldsMap[field.key]) {
			return 'base:' + field.key;
		}

		return 'field:' + parseInt(field.id, 10);
	}

	function getPersonalFieldEntries(fields) {
		var personalFields = (fields || []).filter(function (field) {
			return (field.section_key || 'additional') === 'personal' && !field._virtual;
		}).sort(function (a, b) {
			return parseInt(a.field_order || 0, 10) - parseInt(b.field_order || 0, 10);
		});
		var baseFields = getOrderedPersonalBaseFields();
		var baseTokens = baseFields.map(function (item) {
			return 'base:' + item.key;
		});
		var customTokens = personalFields.map(function (field) {
			return 'field:' + parseInt(field.id, 10);
		});
		var config = getFormConfig();
		var savedOrder = Array.isArray(config.personal_field_order) ? config.personal_field_order : [];
		var merged = [];

		savedOrder.forEach(function (token) {
			if ((baseTokens.indexOf(token) !== -1 || customTokens.indexOf(token) !== -1) && merged.indexOf(token) === -1) {
				merged.push(token);
			}
		});

		baseTokens.forEach(function (token) {
			if (merged.indexOf(token) === -1) {
				merged.push(token);
			}
		});

		customTokens.forEach(function (token) {
			if (merged.indexOf(token) === -1) {
				merged.push(token);
			}
		});

		var customById = {};
		personalFields.forEach(function (field) {
			customById[parseInt(field.id, 10)] = field;
		});

		return merged.map(function (token) {
			if (token.indexOf('base:') === 0) {
				var baseKey = token.replace(/^base:/, '');
				if (!personalBaseFieldsMap[baseKey]) {
					return null;
				}
				return {
					type: 'base',
					token: token,
					key: baseKey,
					field: personalBaseFieldsMap[baseKey],
					span: parseInt((getPersonalFieldSpanMap()[token] || 12), 10) === 6 ? 6 : 12,
				};
			}

			var fieldId = parseInt(token.replace(/^field:/, ''), 10) || 0;
			if (!fieldId || !customById[fieldId]) {
				return null;
			}

			return {
				type: 'field',
				token: token,
				field: customById[fieldId],
				span: parseInt((getPersonalFieldSpanMap()[token] || 12), 10) === 6 ? 6 : 12,
			};
		}).filter(function (item) {
			return !!item;
		});
	}

	function savePersonalFieldOrderByTokens(tokens, successMessage, scrollTarget) {
		if (!Array.isArray(tokens) || !tokens.length) {
			return;
		}

		var baseTokens = [];
		var customTokens = [];
		tokens.forEach(function (token) {
			if (String(token || '').indexOf('base:') === 0) {
				baseTokens.push(token);
			} else if (String(token || '').indexOf('field:') === 0) {
				customTokens.push(token);
			}
		});

		var formConfig = $.extend(true, {}, getFormConfig());
		formConfig.personal_field_order = tokens;
		formConfig.personal_base_field_order = baseTokens.map(function (token) {
			return token.replace(/^base:/, '');
		});
		formConfig.personal_custom_field_order = customTokens.map(function (token) {
			return parseInt(token.replace(/^field:/, ''), 10) || 0;
		}).filter(function (id) {
			return id > 0;
		});
		if (!formConfig.personal_field_spans || typeof formConfig.personal_field_spans !== 'object') {
			formConfig.personal_field_spans = {};
		}
		saveFormConfiguration(formConfig, successMessage || 'Ordine campi Dati Personali aggiornato.', { scrollTarget: scrollTarget });
	}

	function movePersonalField(token, direction) {
		if (!state.selectedActivityId || !token) {
			return;
		}

		var ordered = getPersonalFieldEntries(state.currentFields).map(function (item) {
			return item.token;
		});
		var idx = ordered.indexOf(token);
		if (idx < 0) {
			return;
		}

		var targetIdx = direction === 'up' ? idx - 1 : idx + 1;
		if (targetIdx < 0 || targetIdx >= ordered.length) {
			return;
		}

		var swap = ordered[targetIdx];
		ordered[targetIdx] = ordered[idx];
		ordered[idx] = swap;

		savePersonalFieldOrderByTokens(ordered, 'Ordine campi Dati Personali aggiornato.', 'top');
	}

	function togglePersonalFieldSpan(token) {
		if (!state.selectedActivityId || !token) {
			return;
		}

		var formConfig = $.extend(true, {}, getFormConfig());
		var spans = (formConfig.personal_field_spans && typeof formConfig.personal_field_spans === 'object') ? formConfig.personal_field_spans : {};
		var current = parseInt(spans[token] || 12, 10) === 6 ? 6 : 12;
		spans[token] = current === 6 ? 12 : 6;
		formConfig.personal_field_spans = spans;
		saveFormConfiguration(formConfig, 'Layout campo personale aggiornato.', { scrollTarget: 'top' });
	}

	function getActivityDataLayoutOrder() {
		var config = getFormConfig();
		var defaultOrder = Object.keys(activityDataBaseBlocksMap);
		var savedOrder = Array.isArray(config.activity_data_layout_order) ? config.activity_data_layout_order : [];
		var mergedOrder = [];

		savedOrder.forEach(function (key) {
			if (activityDataBaseBlocksMap[key] && mergedOrder.indexOf(key) === -1) {
				mergedOrder.push(key);
			}
		});

		defaultOrder.forEach(function (key) {
			if (mergedOrder.indexOf(key) === -1) {
				mergedOrder.push(key);
			}
		});

		return mergedOrder;
	}

	function getFieldTypeLabel(type) {
		var map = {
			text: 'Testo',
			textarea: 'Textarea',
			datetime: 'Data e ora',
			date: 'Data',
			number: 'Numero',
			select: 'Select',
			checkbox: 'Checkbox',
			radio: 'Radio',
			content: 'Contenuto formattato',
			image: 'Immagine',
			price: 'Tariffa',
			info: 'Info',
		};

		return map[String(type || 'text')] || String(type || 'text');
	}

	function getActivityDataSectionsSummary() {
		var meta = getSectionMeta();
		var activityDataFields = (state.currentFields || []).filter(function (field) {
			var sectionKey = String(field.section_key || 'additional');
			return sectionKey !== 'personal' && sectionKey !== 'activity_data';
		}).sort(function (a, b) {
			var sectionOrderA = parseInt(a.section_order || 20, 10);
			var sectionOrderB = parseInt(b.section_order || 20, 10);
			if (sectionOrderA !== sectionOrderB) {
				return sectionOrderA - sectionOrderB;
			}
			var fieldOrderA = parseInt(a.field_order || 0, 10);
			var fieldOrderB = parseInt(b.field_order || 0, 10);
			if (fieldOrderA !== fieldOrderB) {
				return fieldOrderA - fieldOrderB;
			}
			return parseInt(a.id || 0, 10) - parseInt(b.id || 0, 10);
		});

		var sectionMap = {};
		activityDataFields.forEach(function (field) {
			var sectionKey = String(field.section_key || 'additional');
			var metaKey = sectionKey === 'pricing' ? 'tariffe' : sectionKey;
			var metaEntry = (meta[metaKey] && typeof meta[metaKey] === 'object') ? meta[metaKey] : null;
			if (!sectionMap[sectionKey]) {
				sectionMap[sectionKey] = {
					key: sectionKey,
					label: String((metaEntry && metaEntry.label) || field.section_label || getDefaultSectionLabelByKey(sectionKey)),
					order: parseInt((metaEntry && metaEntry.order) || field.section_order || inferSectionOrder(sectionKey), 10),
					count: 0,
					metric: 'campi',
				};
			}
			sectionMap[sectionKey].count += 1;
		});

		var prices = (state.currentActivity && Array.isArray(state.currentActivity.prices)) ? state.currentActivity.prices : [];
		if (prices.length) {
			var pricingLabel = String((meta.tariffe && meta.tariffe.label) || getDefaultSectionLabelByKey('pricing'));
			var pricingOrder = parseInt((meta.tariffe && meta.tariffe.order) || inferSectionOrder('pricing'), 10);
			if (!sectionMap.pricing) {
				sectionMap.pricing = {
					key: 'pricing',
					label: pricingLabel,
					order: pricingOrder,
					count: prices.length,
					metric: 'tariffe',
				};
			} else {
				sectionMap.pricing.label = pricingLabel;
				sectionMap.pricing.order = pricingOrder;
				sectionMap.pricing.count = prices.length;
				sectionMap.pricing.metric = 'tariffe';
			}
		}

		return Object.keys(sectionMap).map(function (key) {
			return sectionMap[key];
		}).sort(function (a, b) {
			if (a.order !== b.order) {
				return a.order - b.order;
			}
			return String(a.label).localeCompare(String(b.label));
		});
	}

	function renderActivityDataStaticOrderControls() {
		var $wrap = $('#sd-activity-static-order-controls');
		if (!$wrap.length) {
			return;
		}

		var html = '<label class="sd-label">Ordine blocchi Dati Attivita</label><ul class="sd-mini-list">';
		var activityDataSections = getActivityDataSectionsSummary();

		html += '<li class="sd-activity-fixed-top-block"><div class="sd-field-list-item sd-field-list-item-static">';
		html += '<div><strong>Titolo, Luogo, Date e Max Partecipanti</strong><br><small style="opacity:.72; font-weight:600;">Immagine URL, Descrizione</small></div>';
		html += '<div class="sd-field-list-actions"><small style="opacity:.72; font-weight:700;">Fisso</small></div>';
		html += '</div></li>';

		activityDataSections.forEach(function (section) {
			var metric = String(section.metric || 'campi');
			var sectionMeta = '<small style="opacity:.72; font-weight:600;">' + esc(section.count) + ' ' + esc(metric) + '</small>';
			html += '<li data-activity-block-key="section:' + esc(section.key) + '" data-activity-section-key="' + esc(section.key) + '"><div class="sd-field-list-item sd-field-list-item-static">';
			html += '<div><strong>' + esc(section.label || 'Sezione') + '</strong><br>' + sectionMeta + '</div>';
			html += '<div class="sd-field-list-actions">';
			html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-static-activity-block-move" data-key="section:' + esc(section.key) + '" data-direction="up">↑</button>';
			html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-static-activity-block-move" data-key="section:' + esc(section.key) + '" data-direction="down">↓</button>';
			html += '</div></div></li>';
		});

		html += '</ul>';
		$wrap.html(html).show();
	}

	function applyActivityDataLayoutOrder() {
		var $container = $('#sd-activity-static-blocks');
		if (!$container.length) {
			return;
		}

		var order = getActivityDataLayoutOrder();
		var $cursor = null;

		order.forEach(function (key) {
			var $block = $container.children('.sd-activity-static-block[data-static-block="' + key + '"]');
			if (!$block.length) {
				return;
			}

			if ($cursor === null) {
				if (!$block.is($container.children('.sd-activity-static-block').first())) {
					$container.prepend($block);
				}
			} else if (!$block.prev().is($cursor)) {
				$block.insertAfter($cursor);
			}

			$cursor = $block;
		});
	}

	function saveFormConfiguration(formConfig, successMessage, options) {
		if (!state.selectedActivityId) {
			return $.Deferred().reject().promise();
		}
		var opts = options || {};

		return $.ajax({
			url: sdActivityAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activity_save',
				nonce: sdActivityAdmin.nonce,
				activity_id: state.selectedActivityId,
				activity: {
					form_configuration: formConfig,
				},
			},
		}).done(function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : 'Errore nel salvataggio configurazione.', opts.scrollTarget);
				return;
			}
			if (!opts.noReload) {
				if (successMessage) {
					state.pendingMessage = { type: 'success', text: successMessage, scrollTarget: opts.scrollTarget };
				}
				editActivity(state.selectedActivityId);
			}
		});
	}

	function savePersonalBaseOrderByKeys(keys) {
		if (!Array.isArray(keys) || !keys.length) {
			return;
		}

		var allowedKeys = Object.keys(personalBaseFieldsMap);
		var unique = [];

		keys.forEach(function (key) {
			if (allowedKeys.indexOf(key) !== -1 && unique.indexOf(key) === -1) {
				unique.push(key);
			}
		});

		allowedKeys.forEach(function (key) {
			if (unique.indexOf(key) === -1) {
				unique.push(key);
			}
		});

		var formConfig = $.extend(true, {}, getFormConfig());
		formConfig.personal_base_field_order = unique;
		saveFormConfiguration(formConfig, 'Ordine campi Dati Personali aggiornato.');
	}

	function saveActivityBlocksOrderByKeys(keys) {
		if (!Array.isArray(keys) || !keys.length) {
			return;
		}

		var allowedKeys = Object.keys(activityDataBaseBlocksMap);
		var unique = [];

		keys.forEach(function (key) {
			if (allowedKeys.indexOf(key) !== -1 && unique.indexOf(key) === -1) {
				unique.push(key);
			}
		});

		allowedKeys.forEach(function (key) {
			if (unique.indexOf(key) === -1) {
				unique.push(key);
			}
		});

		var formConfig = $.extend(true, {}, getFormConfig());
		formConfig.activity_data_layout_order = unique;
		saveFormConfiguration(formConfig, 'Ordine blocchi Dati Attivita aggiornato.');
	}

	function moveStaticPersonalField(fieldKey, direction) {
		if (!state.selectedActivityId || !fieldKey) {
			return;
		}

		var ordered = getOrderedPersonalBaseFields().map(function (item) { return item.key; });
		var idx = ordered.indexOf(fieldKey);
		if (idx < 0) {
			return;
		}

		var targetIdx = direction === 'up' ? idx - 1 : idx + 1;
		if (targetIdx < 0 || targetIdx >= ordered.length) {
			return;
		}

		var swap = ordered[targetIdx];
		ordered[targetIdx] = ordered[idx];
		ordered[idx] = swap;

		var formConfig = $.extend(true, {}, getFormConfig());
		formConfig.personal_base_field_order = ordered;
		saveFormConfiguration(formConfig, 'Ordine campi Dati Personali aggiornato.');
	}

	function moveActivityDataBlock(blockKey, direction) {
		if (!state.selectedActivityId || !blockKey) {
			return;
		}

		var key = String(blockKey || '');
		if (key.indexOf('section:') === 0) {
			moveActivityDataSection(key.replace(/^section:/, ''), direction);
			return;
		}

		if (key === 'core' || key === 'thumbnail' || key === 'description') {
			return;
		}

		var ordered = getActivityDataLayoutOrder().slice();
		var idx = ordered.indexOf(blockKey);
		if (idx < 0) {
			return;
		}

		var targetIdx = direction === 'up' ? idx - 1 : idx + 1;
		if (targetIdx < 0 || targetIdx >= ordered.length) {
			return;
		}

		var swap = ordered[targetIdx];
		ordered[targetIdx] = ordered[idx];
		ordered[idx] = swap;

		var formConfig = $.extend(true, {}, getFormConfig());
		formConfig.activity_data_layout_order = ordered;
		saveFormConfiguration(formConfig, 'Ordine blocchi Dati Attivita aggiornato.');
	}

	function moveActivityDataSection(sectionKey, direction) {
		if (!state.selectedActivityId || !sectionKey) {
			return;
		}

		var sections = getActivityDataSectionsSummary();
		var idx = sections.findIndex(function (section) {
			return String(section.key) === String(sectionKey);
		});
		if (idx < 0) {
			return;
		}

		var targetIdx = direction === 'up' ? idx - 1 : idx + 1;
		if (targetIdx < 0 || targetIdx >= sections.length) {
			return;
		}

		var reordered = sections.slice();
		var temp = reordered[idx];
		reordered[idx] = reordered[targetIdx];
		reordered[targetIdx] = temp;

		var requests = [];
		var meta = getSectionMeta();
		var metaChanged = false;
		meta.layout_order = reordered.map(function (section) {
			return String(section.key || '').trim();
		}).filter(function (key) {
			return !!key;
		});
		metaChanged = true;

		reordered.forEach(function (section, index) {
			if (!section || !section.key) {
				return;
			}

			var newOrder = (index + 2) * 10; // 20, 30, 40... (after personal)
			var metaKey = section.key === 'pricing' ? 'tariffe' : section.key;
			meta[metaKey] = meta[metaKey] || {};
			meta[metaKey].label = meta[metaKey].label || section.label || getDefaultSectionLabelByKey(section.key);
			if (parseInt(meta[metaKey].order || 0, 10) !== parseInt(newOrder, 10)) {
				meta[metaKey].order = parseInt(newOrder, 10);
				metaChanged = true;
			}

			if (section.key === 'pricing' || section.key === 'activity_data') {
				return;
			}

			(state.currentFields || []).forEach(function (field) {
				if (String(field.section_key || 'additional') !== String(section.key)) {
					return;
				}
				requests.push(updateFieldPartialAjax(parseInt(field.id, 10), {
					section_order: parseInt(newOrder, 10),
				}));
			});
		});

		var fieldsPromise = requests.length ? $.when.apply($, requests) : $.Deferred().resolve().promise();
		fieldsPromise.done(function () {
			if (metaChanged) {
				saveSectionMeta(meta, 'Ordine sezioni Dati Attivita aggiornato.', 'top');
				return;
			}
			showMessage('success', 'Ordine sezioni Dati Attivita aggiornato.', 'top');
			editActivity(state.selectedActivityId);
		}).fail(function () {
			if (metaChanged) {
				saveSectionMeta(meta, 'Ordine sezioni Dati Attivita aggiornato (con alcuni campi non sincronizzati).', 'top');
				return;
			}
			showMessage('error', 'Errore nello spostamento sezione.', 'top');
		});
	}

	function applyVirtualSectionMetaUI() {
		var $stack = $('#sd-modifica-sections-stack');
		if (!$stack.length) {
			return;
		}

		var $campi = $('#sd-sections-secondary-stack');
		var $activity = $('#sd-activity-form');
		var $sezioni = $('#sd-section-sezioni-modulo');

		if ($campi.length) {
			$stack.prepend($campi);
		}
		if ($activity.length) {
			$campi.after($activity);
		}
		if ($sezioni.length) {
			$activity.after($sezioni);
		}
	}

	function saveSectionMeta(meta, successMessage, scrollTarget) {
		if (!state.selectedActivityId) {
			return $.Deferred().reject().promise();
		}

		var formConfig = $.extend(true, {}, state.currentActivity && state.currentActivity.form_configuration ? state.currentActivity.form_configuration : {});
		formConfig.section_meta = meta;

		return $.ajax({
			url: sdActivityAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activity_save',
				nonce: sdActivityAdmin.nonce,
				activity_id: state.selectedActivityId,
				activity: {
					form_configuration: formConfig,
				},
			},
		}).done(function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : 'Errore nel salvataggio sezione.', scrollTarget);
				return;
			}
			if (successMessage) {
				showMessage('success', successMessage, scrollTarget);
			}
			editActivity(state.selectedActivityId);
		});
	}

	function getActivityDataVirtualFields() {
		var a = state.currentActivity || {};
		return [
			{ _virtual: true, field_label: 'Titolo', field_type: 'text', value: a.title || '-' },
			{ _virtual: true, field_label: 'Data Inizio', field_type: 'datetime', value: a.start_date || '-' },
			{ _virtual: true, field_label: 'Data Fine', field_type: 'datetime', value: a.end_date || '-' },
			{ _virtual: true, field_label: 'Luogo', field_type: 'text', value: a.location || '-' },
			{ _virtual: true, field_label: 'Max Partecipanti', field_type: 'number', value: String(a.max_participants || '-') },
			{ _virtual: true, field_label: 'Stato', field_type: 'text', value: a.event_status || '-' },
			{ _virtual: true, field_label: 'Immagine URL', field_type: 'text', value: a.thumbnail_url || '-' },
			{ _virtual: true, field_label: 'Descrizione', field_type: 'textarea', value: a.description ? 'Valorizzata' : '-' },
		];
	}

	function getTariffeVirtualFields() {
		var prices = (state.currentActivity && Array.isArray(state.currentActivity.prices)) ? state.currentActivity.prices : [];
		if (!prices.length) {
			return [{ _virtual: true, field_label: 'Nessuna tariffa configurata', field_type: 'info', value: '-' }];
		}

		return prices.map(function (price) {
			return {
				_virtual: true,
				field_label: price.price_name || 'Tariffa',
				field_type: 'price',
				value: 'CHF ' + num(price.price_chf) + ' / EUR ' + num(price.price_eur),
			};
		});
	}

	function buildSectionsModel(fields) {
		var sectionMap = {};
		var knownSections = getAllSections();
		var knownByKey = {};
		var meta = getSectionMeta();

		knownSections.forEach(function (section) {
			knownByKey[section.key] = section;
		});

		(fields || []).forEach(function (field) {
			var sectionKey = field.section_key || 'additional';
			if (sectionKey === 'activity_data') {
				return;
			}
			var known = knownByKey[sectionKey] || null;
			var metaKey = sectionKey === 'pricing' ? 'tariffe' : sectionKey;
			var metaEntry = (meta[metaKey] && typeof meta[metaKey] === 'object') ? meta[metaKey] : null;
			if (!sectionMap[sectionKey]) {
				sectionMap[sectionKey] = {
					key: sectionKey,
					label: (metaEntry && metaEntry.label) || field.section_label || (known ? known.label : sectionKey),
					order: parseInt((metaEntry && metaEntry.order) || field.section_order || (known ? known.order : 20), 10),
					fields: [],
					virtual: false,
				};
			}
			sectionMap[sectionKey].fields.push(field);
		});

		sectionMap.tariffe = {
			key: 'tariffe',
			label: meta.tariffe.label,
			order: parseInt(meta.tariffe.order, 10),
			fields: getTariffeVirtualFields(),
			virtual: true,
		};

		return Object.keys(sectionMap).map(function (key) {
			return sectionMap[key];
		}).sort(function (a, b) {
			return a.order - b.order;
		});
	}

	function renderFieldsList(fields) {
		var html = '';
		var sections = buildSectionsModel(fields);

		if (!sections.length) {
			html = '<div class="sd-mini-list-empty">Nessun campo configurato.</div>';
			$('#sd-fields-list').html(html);
			return;
		}

		html += '<div class="sd-sections-overview">';
		sections.forEach(function (section) {
			var sectionCount = section.key === 'personal' ? getPersonalFieldEntries(fields).length : section.fields.length;
			html += '<span class="sd-section-chip">' + esc(section.label) + ' <strong>(' + sectionCount + ')</strong></span>';
		});
		html += '</div>';

		html += '<div class="sd-sections-board">';
		sections.forEach(function (section) {
			var isPersonalSection = section.key === 'personal';
			var personalEntries = isPersonalSection ? getPersonalFieldEntries(fields) : [];
			html += '<div class="sd-section-card" data-section-key="' + esc(section.key) + '" data-virtual="' + (section.virtual ? '1' : '0') + '">';
			html += '<div class="sd-section-card-head">';
			html += '<span class="sd-section-card-title">' + esc(section.label) + '</span>';
			html += '<span class="sd-section-card-count">' + (isPersonalSection ? personalEntries.length : section.fields.length) + ' campi</span>';
			html += '</div>';
			html += '<div class="sd-section-card-tools">';
			html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-section-move" data-section="' + esc(section.key) + '" data-direction="up">↑</button>';
			html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-section-move" data-section="' + esc(section.key) + '" data-direction="down">↓</button>';
			html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-section-rename" data-section="' + esc(section.key) + '">Rinomina</button>';
			html += '<button type="button" class="sd-btn sd-btn-danger sd-btn-sm sd-section-delete" data-section="' + esc(section.key) + '">Elimina</button>';
			html += '</div>';

			if (!section.virtual) {
				section.fields.sort(function (a, b) {
					return parseInt(a.field_order || 0, 10) - parseInt(b.field_order || 0, 10);
				});
			}

			html += '<ul class="sd-section-card-list" data-section-key="' + esc(section.key) + '" data-virtual="' + (section.virtual ? '1' : '0') + '">';
			if (isPersonalSection) {
					personalEntries.forEach(function (entry) {
						if (entry.type === 'base') {
							html += '<li data-personal-token="' + esc(entry.token) + '" data-static-personal-key="' + esc(entry.key) + '"><div class="sd-field-list-item sd-field-list-item-static">';
							html += '<div>';
							html += '<strong>' + esc(entry.field.field_label) + '</strong> <span>(' + esc(getFieldTypeLabel(entry.field.field_type)) + ')</span>';
							html += '<div class="sd-field-list-meta"><span>Campo base modulo</span><span>Sempre visibile</span><span>' + (entry.span === 6 ? 'Metà riga' : 'Intera riga') + '</span></div>';
							html += '</div>';
							html += '<div class="sd-field-list-actions">';
							html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-personal-field-move" data-token="' + esc(entry.token) + '" data-direction="up">↑</button>';
							html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-personal-field-move" data-token="' + esc(entry.token) + '" data-direction="down">↓</button>';
							html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-personal-field-span-toggle" data-token="' + esc(entry.token) + '">' + (entry.span === 6 ? 'Intero' : 'Affianca') + '</button>';
							html += '</div>';
							html += '</div></li>';
							return;
						}

						var field = entry.field;
						var optionsCount = Array.isArray(field.options) ? field.options.length : 0;
						html += '<li data-personal-token="' + esc(entry.token) + '" data-field-id="' + parseInt(field.id, 10) + '"><div class="sd-field-list-item" data-field-id="' + parseInt(field.id, 10) + '">';
						html += '<div>';
						html += '<strong>' + esc(field.field_label) + '</strong> <span>(' + esc(getFieldTypeLabel(field.field_type)) + ')</span>';
						html += '<div class="sd-field-list-meta">';
						html += '<span>Ordine personalizzato</span>';
						if (optionsCount > 0) {
							html += '<span>Opzioni: ' + optionsCount + '</span>';
						}
						html += field.is_required ? '<span>Obbligatorio</span>' : '';
						html += '<span>' + (entry.span === 6 ? 'Metà riga' : 'Intera riga') + '</span>';
						html += '</div>';
						html += '</div>';
						html += '<div class="sd-field-list-actions">';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-personal-field-move" data-token="' + esc(entry.token) + '" data-direction="up">↑</button>';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-personal-field-move" data-token="' + esc(entry.token) + '" data-direction="down">↓</button>';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-personal-field-span-toggle" data-token="' + esc(entry.token) + '">' + (entry.span === 6 ? 'Intero' : 'Affianca') + '</button>';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-field-edit" data-id="' + parseInt(field.id, 10) + '">Modifica</button>';
						html += '<button type="button" class="sd-btn sd-btn-danger sd-btn-sm sd-field-delete" data-id="' + parseInt(field.id, 10) + '">Elimina</button>';
						html += '</div>';
						html += '</div></li>';
					});
				} else {
					section.fields.forEach(function (field) {
						if (field._virtual) {
							html += '<li><div class="sd-field-list-item sd-field-list-item-static">';
							html += '<div>';
							html += '<strong>' + esc(field.field_label) + '</strong> <span>(' + esc(getFieldTypeLabel(field.field_type)) + ')</span>';
							html += '<div class="sd-field-list-meta"><span>' + esc(field.value || '-') + '</span></div>';
							html += '</div>';
							html += '</div></li>';
							return;
						}

						var fieldType = field.field_type || 'text';
						var optionsCount = Array.isArray(field.options) ? field.options.length : 0;
						var imgPreviewHtml = '';
						
						// Add image preview for image fields
						if (fieldType === 'image' && field.options && field.options.image_url) {
							imgPreviewHtml = '<div class="sd-image-preview"><img src="' + esc(field.options.image_url) + '" alt="' + esc(field.field_label) + '"></div>';
						}
						
						html += '<li data-field-id="' + parseInt(field.id, 10) + '"><div class="sd-field-list-item" data-field-id="' + parseInt(field.id, 10) + '" data-field-type="' + esc(fieldType) + '">';
						if (imgPreviewHtml) {
							html += imgPreviewHtml;
						}
						html += '<div>';
						html += '<strong>' + esc(field.field_label) + '</strong> <span>(' + esc(getFieldTypeLabel(fieldType)) + ')</span>';
						html += '<div class="sd-field-list-meta">';
						html += '<span>Ordine: ' + parseInt(field.field_order || 0, 10) + '</span>';
						if (optionsCount > 0) {
							html += '<span>Opzioni: ' + optionsCount + '</span>';
						}
						html += field.is_required ? '<span>Obbligatorio</span>' : '';
						html += '</div>';
						html += '</div>';
						html += '<div class="sd-field-list-actions">';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-field-move" data-id="' + parseInt(field.id, 10) + '" data-direction="up">↑</button>';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-field-move" data-id="' + parseInt(field.id, 10) + '" data-direction="down">↓</button>';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-field-edit" data-id="' + parseInt(field.id, 10) + '">Modifica</button>';
						html += '<button type="button" class="sd-btn sd-btn-danger sd-btn-sm sd-field-delete" data-id="' + parseInt(field.id, 10) + '">Elimina</button>';
						html += '</div>';
						html += '</div></li>';
					});
				}
			html += '</ul>';
			html += '</div>';
		});
		html += '</div>';

		$('#sd-fields-list').html(html);
	}

	function renderActivityDataExtraFields(fields) {
		var $wrap = $('#sd-activity-data-extra-fields');
		if (!$wrap.length) {
			return;
		}

		var rows = (fields || []).filter(function (field) {
			return (field.section_key || 'additional') === 'activity_data';
		}).sort(function (a, b) {
			return parseInt(a.field_order || 0, 10) - parseInt(b.field_order || 0, 10);
		});

		if (!rows.length) {
			$wrap.hide().empty();
			return;
		}

		var html = '<div class="sd-field-group sd-field-full">';
		html += '<label class="sd-label">Campi aggiuntivi Dati Attivita</label>';
		html += '<ul class="sd-mini-list">';
		rows.forEach(function (field) {
			var fieldType = field.field_type || 'text';
			var imgPreviewHtml = '';
			
			// Add image preview for image fields
			if (fieldType === 'image' && field.options && field.options.image_url) {
				imgPreviewHtml = '<div class="sd-image-preview"><img src="' + esc(field.options.image_url) + '" alt="' + esc(field.field_label) + '"></div>';
			}
			
			html += '<li><div class="sd-field-list-item" data-field-id="' + parseInt(field.id, 10) + '" data-field-type="' + esc(fieldType) + '">';
			if (imgPreviewHtml) {
				html += imgPreviewHtml;
			}
			html += '<div>';
			html += '<strong>' + esc(field.field_label) + '</strong> <span>(' + esc(getFieldTypeLabel(fieldType)) + ')</span>';
			html += '<div class="sd-field-list-meta">';
			html += '<span>Ordine: ' + parseInt(field.field_order || 0, 10) + '</span>';
			html += field.is_required ? '<span>Obbligatorio</span>' : '';
			html += '</div></div>';
			html += '<div class="sd-field-list-actions">';
			html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-field-edit" data-id="' + parseInt(field.id, 10) + '">Modifica</button>';
			html += '<button type="button" class="sd-btn sd-btn-danger sd-btn-sm sd-field-delete" data-id="' + parseInt(field.id, 10) + '">Elimina</button>';
			html += '</div></div></li>';
		});
		html += '</ul></div>';

		$wrap.html(html).show();
	}

	function moveActivityDataField(fieldId, direction) {
		if (!fieldId || !state.selectedActivityId) {
			return;
		}

		// Sort with id as tiebreaker so order is deterministic when field_order values are equal.
		var rows = (state.currentFields || []).filter(function (field) {
			return (field.section_key || 'additional') === 'activity_data';
		}).sort(function (a, b) {
			var oa = parseInt(a.field_order || 0, 10);
			var ob = parseInt(b.field_order || 0, 10);
			if (oa !== ob) { return oa - ob; }
			return parseInt(a.id, 10) - parseInt(b.id, 10);
		});

		var idx = rows.findIndex(function (field) {
			return parseInt(field.id, 10) === parseInt(fieldId, 10);
		});

		if (idx < 0) {
			return;
		}

		var targetIdx = direction === 'up' ? idx - 1 : idx + 1;
		if (targetIdx < 0 || targetIdx >= rows.length) {
			return;
		}

		// Swap positions in the sorted array, then save ALL fields with sequential
		// field_order values. This normalises duplicate field_order values (e.g. all 0)
		// so that subsequent moves always produce a different result.
		var swapped = rows.slice();
		var tmp = swapped[idx];
		swapped[idx] = swapped[targetIdx];
		swapped[targetIdx] = tmp;

		var ajaxCalls = swapped.map(function (field, pos) {
			// Send only field_order; PHP fills the rest from the existing DB record.
			return $.ajax({
				url: sdActivityAdmin.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'sd_activity_update_form_field',
					nonce: sdActivityAdmin.nonce,
					activity_id: state.selectedActivityId,
					field_id: parseInt(field.id, 10),
					field: { field_order: pos + 1 },
				},
			});
		});

		$.when.apply($, ajaxCalls).done(function () {
			state.pendingMessage = { type: 'success', text: 'Posizione campo aggiornata.', scrollTarget: 'top' };
			editActivity(state.selectedActivityId);
		}).fail(function () {
			showMessage('error', 'Impossibile spostare il campo', 'top');
		});
	}

	function getSectionInfo(sectionKey) {
		if (sectionKey === 'activity_data') {
			var meta = getSectionMeta();
			return {
				key: 'activity_data',
				label: meta.activity_data.label || 'Dati Attivita',
				order: parseInt(meta.activity_data.order || 15, 10),
				fields: getActivityDataVirtualFields(),
				virtual: true,
			};
		}

		var ordered = buildSectionsModel(state.currentFields || []);

		if (!sectionKey) {
			return ordered;
		}

		return ordered.find(function (section) {
			return section.key === sectionKey;
		}) || null;
	}

	function getDefaultSectionLabelByKey(sectionKey) {
		var section = defaultSections.find(function (item) {
			return item.key === sectionKey;
		});
		return section ? section.label : sectionKey;
	}

	function buildFieldPayload(field, overrides) {
		var payload = {
			field_type: field.field_type,
			field_name: field.field_name,
			field_label: field.field_label,
			placeholder: field.placeholder || '',
			is_required: field.is_required ? 1 : 0,
			section_key: field.section_key || 'additional',
			section_label: field.section_label || getDefaultSectionLabelByKey(field.section_key || 'additional'),
			section_order: parseInt(field.section_order || inferSectionOrder(field.section_key || 'additional'), 10),
			field_order: parseInt(field.field_order || 1, 10),
			options: Array.isArray(field.options) ? field.options : [],
			content: field.content || '',
		};

		return $.extend({}, payload, overrides || {});
	}

	function updateFieldAjax(fieldId, payload) {
		return $.ajax({
			url: sdActivityAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activity_update_form_field',
				nonce: sdActivityAdmin.nonce,
				activity_id: state.selectedActivityId,
				field_id: fieldId,
				field: payload,
			},
		});
	}

	function deleteFieldAjax(fieldId) {
		return $.ajax({
			url: sdActivityAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activity_delete_form_field',
				nonce: sdActivityAdmin.nonce,
				activity_id: state.selectedActivityId,
				field_id: fieldId,
			},
		});
	}

	function renameSection(sectionKey) {
		var section = getSectionInfo(sectionKey);
		if (!section || !section.fields.length) {
			return;
		}

		var newLabel = window.prompt('Nuovo nome sezione:', section.label || '');
		if (!newLabel) {
			return;
		}

		var trimmed = String(newLabel).trim();
		if (!trimmed) {
			showMessage('error', 'Nome sezione non valido.');
			return;
		}

		if (section.virtual) {
			var meta = getSectionMeta();
			meta[sectionKey] = meta[sectionKey] || {};
			meta[sectionKey].label = trimmed;
			meta[sectionKey].order = parseInt(meta[sectionKey].order || section.order, 10);
			saveSectionMeta(meta, 'Sezione rinominata.');
			return;
		}

		var requests = section.fields.map(function (field) {
			return updateFieldAjax(field.id, buildFieldPayload(field, {
				section_label: trimmed,
			}));
		});

		$.when.apply($, requests).done(function () {
			showMessage('success', 'Sezione rinominata.');
			editActivity(state.selectedActivityId);
		}).fail(function () {
			showMessage('error', 'Errore nella rinomina sezione.');
		});
	}

	function moveSection(sectionKey, direction) {
		if (sectionKey === 'activity_data') {
			moveVirtualLayoutSection(sectionKey, direction);
			return;
		}

		var sections = getSectionInfo();
		var idx = sections.findIndex(function (section) {
			return section.key === sectionKey;
		});
		if (idx < 0) {
			return;
		}

		var targetIdx = direction === 'up' ? idx - 1 : idx + 1;
		if (targetIdx < 0 || targetIdx >= sections.length) {
			return;
		}

		var reorderedSections = sections.slice();
		var swap = reorderedSections[idx];
		reorderedSections[idx] = reorderedSections[targetIdx];
		reorderedSections[targetIdx] = swap;

		var current = sections[idx];
		var target = sections[targetIdx];
		var requests = [];
		var meta = getSectionMeta();
		var metaChanged = false;
		var nextLayoutOrder = [];

		reorderedSections.forEach(function (section) {
			var normalizedKey = String(section && section.key ? section.key : '').trim();
			if (!normalizedKey) {
				return;
			}
			if (normalizedKey === 'tariffe') {
				normalizedKey = 'pricing';
			}
			if (nextLayoutOrder.indexOf(normalizedKey) === -1) {
				nextLayoutOrder.push(normalizedKey);
			}
		});

		var currentLayoutOrder = Array.isArray(meta.layout_order) ? meta.layout_order.slice() : [];
		if (JSON.stringify(currentLayoutOrder) !== JSON.stringify(nextLayoutOrder)) {
			meta.layout_order = nextLayoutOrder;
			metaChanged = true;
		}

		if (current.virtual) {
			meta[current.key] = meta[current.key] || {};
			meta[current.key].label = meta[current.key].label || current.label;
			if (parseInt(meta[current.key].order || 0, 10) !== parseInt(target.order, 10)) {
				meta[current.key].order = target.order;
				metaChanged = true;
			}
		} else {
			current.fields.forEach(function (field) {
				requests.push(updateFieldAjax(field.id, buildFieldPayload(field, {
					section_order: target.order,
				}))); 
			});
		}

		if (target.virtual) {
			meta[target.key] = meta[target.key] || {};
			meta[target.key].label = meta[target.key].label || target.label;
			if (parseInt(meta[target.key].order || 0, 10) !== parseInt(current.order, 10)) {
				meta[target.key].order = current.order;
				metaChanged = true;
			}
		} else {
			target.fields.forEach(function (field) {
				requests.push(updateFieldAjax(field.id, buildFieldPayload(field, {
					section_order: current.order,
				}))); 
			});
		}

		var fieldsPromise = requests.length ? $.when.apply($, requests) : $.Deferred().resolve().promise();
		fieldsPromise.done(function () {
			if (metaChanged) {
				saveSectionMeta(meta, 'Ordine sezioni aggiornato.', 'top');
			} else {
				showMessage('success', 'Ordine sezioni aggiornato.', 'top');
				editActivity(state.selectedActivityId);
			}
		}).fail(function () {
			showMessage('error', 'Errore nello spostamento sezione.', 'top');
		});
	}

	function moveVirtualLayoutSection(sectionKey, direction) {
		var meta = getSectionMeta();
		var layout = [
			{ key: 'campi_modulo', order: 5, virtual: false },
			{ key: 'activity_data', order: parseInt(meta.activity_data.order || 15, 10), virtual: true },
			{ key: 'sezioni_modulo', order: 35, virtual: false },
		].sort(function (a, b) {
			return a.order - b.order;
		});

		var idx = layout.findIndex(function (item) {
			return item.key === sectionKey;
		});
		if (idx < 0) {
			return;
		}

		var targetIdx = direction === 'up' ? idx - 1 : idx + 1;
		if (targetIdx < 0 || targetIdx >= layout.length) {
			return;
		}

		var current = layout[idx];
		var target = layout[targetIdx];

		meta[current.key] = meta[current.key] || {};
		meta[current.key].label = meta[current.key].label || (current.key === 'activity_data' ? 'Dati Attivita' : 'Tariffe');

		if (target.virtual) {
			meta[target.key] = meta[target.key] || {};
			meta[target.key].label = meta[target.key].label || (target.key === 'activity_data' ? 'Dati Attivita' : 'Tariffe');
			var tmp = meta[current.key].order || current.order;
			meta[current.key].order = meta[target.key].order || target.order;
			meta[target.key].order = tmp;
		} else {
			meta[current.key].order = direction === 'up' ? (target.order - 1) : (target.order + 1);
		}

		saveSectionMeta(meta);
	}

	function deleteSection(sectionKey) {
		var section = getSectionInfo(sectionKey);
		if (!section || !section.fields.length) {
			return;
		}

		if (section.virtual) {
			if (sectionKey === 'tariffe') {
				if (!window.confirm('Eliminare tutte le tariffe dalla sezione Tariffe?')) {
					return;
				}

				$.ajax({
					url: sdActivityAdmin.ajaxUrl,
					method: 'POST',
					dataType: 'json',
					data: {
						action: 'sd_activity_delete_all_prices',
						nonce: sdActivityAdmin.nonce,
						activity_id: state.selectedActivityId,
					},
				}).done(function (resp) {
					if (!resp || !resp.success) {
						showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : 'Errore nell\'eliminazione tariffe.');
						return;
					}
					showMessage('success', 'Tariffe eliminate.');
					editActivity(state.selectedActivityId);
				}).fail(function () {
					showMessage('error', 'Errore nell\'eliminazione tariffe.');
				});
				return;
			}

			if (sectionKey === 'activity_data') {
				if (!window.confirm('Eliminare i dati della sezione Dati Attivita?')) {
					return;
				}

				$.ajax({
					url: sdActivityAdmin.ajaxUrl,
					method: 'POST',
					dataType: 'json',
					data: {
						action: 'sd_activity_save',
						nonce: sdActivityAdmin.nonce,
						activity_id: state.selectedActivityId,
						activity: {
							title: $('#sd-activity-title').val(),
							start_date: fromDateTimeLocal($('#sd-activity-start').val()),
							end_date: fromDateTimeLocal($('#sd-activity-end').val()),
							location: '',
							max_participants: '',
							event_status: 'draft',
							thumbnail_url: '',
							description: '',
						},
					},
				}).done(function (resp) {
					if (!resp || !resp.success) {
						showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : 'Errore nell\'eliminazione dati attivita.');
						return;
					}
					showMessage('success', 'Dati attività eliminati.');
					editActivity(state.selectedActivityId);
				}).fail(function () {
					showMessage('error', 'Errore nell\'eliminazione dati attivita.');
				});
			}

			return;
		}

		if (!window.confirm('Eliminare la sezione "' + section.label + '" e tutti i suoi campi?')) {
			return;
		}

		var requests = section.fields.map(function (field) {
			return deleteFieldAjax(field.id);
		});

		$.when.apply($, requests).done(function () {
			showMessage('success', 'Sezione eliminata.');
			editActivity(state.selectedActivityId);
		}).fail(function () {
			showMessage('error', 'Errore nell\'eliminazione sezione.');
		});
	}

	function loadRegistrations() {
		var activityId = parseInt($('#sd-reg-activity-id').val(), 10) || 0;
		if (!activityId) {
			setTableEmpty('#sd-reg-tbody', 7, 'Seleziona una attivita.');
			$('#sd-reg-minor-alert').hide().empty();
			return;
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_registration_list',
			nonce: sdActivityAdmin.nonce,
			activity_id: activityId,
			payment_status: $('#sd-reg-payment-filter').val() || '',
			search: $('#sd-reg-search').val() || '',
			per_page: 100,
			page: 1,
		}, function (resp) {
			if (!resp || !resp.success) {
				setTableError('#sd-reg-tbody', 7, (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			state.registrations = resp.data.registrations || [];
			renderRegistrationsTable();
			updatePaymentsStats();
		});
	}

	function renderRegistrationsTable() {
		var rows = state.registrations;
		if (!rows.length) {
			setTableEmpty('#sd-reg-tbody', 7, 'Nessuna registrazione trovata.');
			$('#sd-reg-minor-alert').hide().empty();
			return;
		}

		var html = '';
		var minorCount = 0;
		rows.forEach(function (r) {
			var registrationData = (r && r.registration_data && typeof r.registration_data === 'object') ? r.registration_data : {};
			var birthDate = registrationData.birth_date || '';
			var minorInfo = getMinorInfoFromBirthDate(birthDate);
			var statusCode = String(r.status || 'registered');
			var paymentStatusCode = String(r.payment_status || 'pending');
			var statusSelect = buildStatusSelect(parseInt(r.id, 10), statusCode);
			var paymentSelect = buildPaymentStatusSelect(parseInt(r.id, 10), paymentStatusCode);
			var canResendInvoice = paymentStatusCode === 'invoice_requested' || paymentStatusCode === 'invoice_error';
			var eurAmount = getPriceEurWithCurrentRate(r.price_chf, r.price_eur);
			var actionsHtml = [];
			if (canResendInvoice) {
				actionsHtml.push('<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-resend-invoice" data-id="' + parseInt(r.id, 10) + '">Reinvia fattura</button>');
			}
			if (minorInfo.isMinor) {
				minorCount += 1;
			}
			var nameHtml = esc((r.first_name || '') + ' ' + (r.last_name || ''));
			if (minorInfo.isMinor) {
				nameHtml += ' <span class="sd-status-badge sd-status-draft">MINORENNE (' + esc(String(minorInfo.age)) + ' anni)</span>';
			}
			html += '<tr>' +
				'<td>' + nameHtml + '</td>' +
				'<td>' + esc(r.email || '-') + '</td>' +
				'<td>' + statusSelect + '</td>' +
				'<td>' + paymentSelect + '</td>' +
				'<td>CHF ' + num(r.price_chf) + ' / EUR ' + num(eurAmount) + '</td>' +
				'<td>' + formatDate(r.created_at) + '</td>' +
				'<td>' + (actionsHtml.length ? actionsHtml.join(' ') : '<span class="sd-field-note">-</span>') + '</td>' +
			'</tr>';
		});

		$('#sd-reg-tbody').html(html);

		if (minorCount > 0) {
			$('#sd-reg-minor-alert').html('<strong>Attenzione:</strong> rilevate ' + esc(String(minorCount)) + ' iscrizioni di minorenni. Verifica autorizzazione genitore/tutore.').show();
		} else {
			$('#sd-reg-minor-alert').hide().empty();
		}
	}

	function buildStatusSelect(registrationId, currentStatus) {
		var options = ['registered', 'waitlist', 'cancelled'];
		var statusClass = getStatusCssClass(currentStatus);
		var html = '<select class="sd-select sd-reg-inline-select sd-reg-status-select' + statusClass + '" data-id="' + registrationId + '">';

		options.forEach(function (value) {
			html += '<option value="' + esc(value) + '"' + (value === currentStatus ? ' selected' : '') + '>' + esc(getRegistrationStatusLabel(value)) + '</option>';
		});

		html += '</select>';
		return html;
	}

	function buildPaymentStatusSelect(registrationId, currentStatus) {
		var options = ['pending', 'paid', 'invoice_requested', 'invoice_sent', 'invoice_error', 'cancelled', 'free'];
		var statusClass = getStatusCssClass(currentStatus);
		var html = '<select class="sd-select sd-reg-inline-select sd-reg-payment-select' + statusClass + '" data-id="' + registrationId + '">';

		options.forEach(function (value) {
			html += '<option value="' + esc(value) + '"' + (value === currentStatus ? ' selected' : '') + '>' + esc(getPaymentStatusLabel(value)) + '</option>';
		});

		html += '</select>';
		return html;
	}

	function getStatusCssClass(status) {
		var key = String(status || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
		return key ? ' sd-status-' + key : '';
	}

	function setSelectStatusClass($select, status) {
		if (!$select || !$select.length) {
			return;
		}

		var current = String($select.attr('class') || '');
		current = current.replace(/\bsd-status-[a-z0-9_-]+\b/g, '').replace(/\s{2,}/g, ' ').trim();
		var next = (current + getStatusCssClass(status)).replace(/\s{2,}/g, ' ').trim();
		$select.attr('class', next);
	}

	function updateRegistrationStatus(registrationId, status) {
		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_registration_update_status',
			nonce: sdActivityAdmin.nonce,
			registration_id: registrationId,
			status: status,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				loadRegistrations();
				return;
			}

			showMessage('success', (resp && resp.data && resp.data.message) ? resp.data.message : 'Stato registrazione aggiornato.');
			loadRegistrations();
		});
	}

	function updateRegistrationPaymentStatus(registrationId, paymentStatus) {
		var paymentData = {};

		if (paymentStatus === 'paid') {
			paymentData.payment_date = todayDisplay();
			paymentData.payment_method = 'manuale_admin';
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_registration_update_payment',
			nonce: sdActivityAdmin.nonce,
			registration_id: registrationId,
			payment_status: paymentStatus,
			payment_data: paymentData,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				loadRegistrations();
				return;
			}

			showMessage('success', (resp && resp.data && resp.data.message) ? resp.data.message : 'Pagamento aggiornato.');
			loadRegistrations();
		});
	}

	function resendInvoiceEmail(registrationId) {
		if (!window.confirm('Reinviare l\'email di richiesta fattura a questo partecipante?')) {
			return;
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_resend_invoice_email',
			nonce: sdActivityAdmin.nonce,
			registration_id: registrationId,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			showMessage('success', (resp && resp.data && resp.data.message) ? resp.data.message : 'Email fattura inviata.');
			loadRegistrations();
		});
	}

	function updatePaymentsStats() {
		var total = state.registrations.length;
		var paid = 0;
		var pending = 0;

		state.registrations.forEach(function (r) {
			if (String(r.payment_status) === 'paid') {
				paid++;
			} else {
				pending++;
			}
		});

		$('#sd-pay-total').text(total);
		$('#sd-pay-paid').text(paid);
		$('#sd-pay-pending').text(pending);
	}

	function resetActivityForm() {
		if (state.descriptionRefreshTimer) {
			window.clearTimeout(state.descriptionRefreshTimer);
			state.descriptionRefreshTimer = null;
		}

		state.selectedActivityId = 0;
		state.currentFields = [];
		state.currentActivity = null;
		$('#sd-activity-id').val('0');
		$('#sd-activity-form')[0].reset();
		setActivityDescriptionValue('');
		updateActivityThumbnailPreview();
		updateActivityShortcodeHint(0);
		$('#sd-activity-data-extra-fields').hide().empty();
		$('#sd-activity-static-order-controls').hide().empty();
		resetPriceForm();
		$('#sd-prices-list').html('<li class="sd-mini-list-empty">Salva l\'attivita per aggiungere tariffe.</li>');
		$('#sd-fields-list').html('<div class="sd-mini-list-empty">Salva l\'attivita per aggiungere campi.</div>');
		populateFieldSectionSelect([]);
		resetFieldForm(false);
	}

	function resetFieldForm(keepSections) {
		destroyFieldContentEditor();
		$('#sd-field-id').val('0');
		$('#sd-field-label').val('');
		$('#sd-field-type').val('text');
		$('#sd-field-required').prop('checked', false);
		$('#sd-condition-mode').val('and');
		$('#sd-condition-rules').empty();
		fieldOptions = [];
		renderOptionsPreview();
		$('#sd-custom-section-label').val('');
		$('#sd-custom-section-key').val('');
		// Reset image fields
		$('input[name="sd-image-type"]').val(['display']);
		$('#sd-image-url').val('');
		$('#sd-image-width').val('');
		$('#sd-image-height').val('');
		$('#sd-image-aspect-ratio').prop('checked', true);
		$('input[name="sd-image-align-h"]').val(['left']);
		$('input[name="sd-image-align-v"]').val(['top']);
		$('#sd-image-alt-text').val('');
		$('#sd-field-content-editor').val('');
		$('#sd-field-submit-btn').text('Aggiungi Campo');
		$('#sd-field-cancel-edit, #sd-field-cancel-edit-secondary').hide();
		if (!keepSections) {
			$('#sd-field-section').val('additional');
			$('#sd-field-section-order').val('20');
			populateConditionSourceFieldSelect(0);
		}
		toggleFieldOptions();
		toggleFieldSectionInputs();
		updateImagePreview();
	}

	function switchTab(name) {
		var options = arguments.length > 1 && arguments[1] ? arguments[1] : {};
		$('.sd-admin-tab[data-tab="' + name + '"]').trigger('click');
		if (name === 'modifica') {
			window.setTimeout(function () {
				initActivityDescriptionEditor();
			}, 0);
		}
		if (name === 'modifica' && !options.skipDescriptionRefresh) {
			scheduleActivityDescriptionRefresh('', 80, false);
		}
	}

	function showMessage(type, message, scrollTarget) {
		var $msg = $('#sd-activity-admin-message');
		var cssClass = 'sd-notice-info';
		if (type === 'error')   { cssClass = 'sd-notice-error'; }
		else if (type === 'success') { cssClass = 'sd-notice-success'; }
		else if (type === 'warning') { cssClass = 'sd-notice-warning'; }
		$msg.removeClass('sd-notice-error sd-notice-success sd-notice-warning sd-notice-info').addClass(cssClass).text(message).show();
		
		if (scrollTarget === 'top') {
			setTimeout(function () {
				$('html, body').animate({ scrollTop: 0 }, 220);
			}, 50);
			return;
		}
		setTimeout(function () {
			$('html, body').animate({ scrollTop: $msg.offset().top - 80 }, 220);
		}, 50);
	}

	function destroyActivityDescriptionEditor() {
		var $textarea = $('#sd-activity-description');
		var preservedValue = state.descriptionPendingValue !== null
			? String(state.descriptionPendingValue)
			: String($textarea.val() || '');

		var editor = getActivityDescriptionEditor();
		if (editor) {
			try {
				if (typeof editor.getContent === 'function') {
					var editorContent = normalizeActivityDescriptionHtml(editor.getContent() || '');
					if (editorContent) {
						preservedValue = editorContent;
					}
				}
				if (typeof editor.save === 'function') {
					editor.save();
				}
				if (typeof editor.remove === 'function') {
					editor.remove();
				}
			} catch (err) {
				// Continue with alternate removal path.
			}
		}

		if (window.wp && window.wp.editor && typeof window.wp.editor.remove === 'function') {
			try {
				window.wp.editor.remove('sd-activity-description');
			} catch (err2) {
				// Ignore removal errors.
			}
		}

		$textarea = $('#sd-activity-description');
		if ($textarea.length) {
			$textarea.val(preservedValue);
		}
		state.descriptionPendingValue = preservedValue;

		cleanupActivityDescriptionEditorUi();
	}

	function rebuildActivityDescriptionEditor() {
		if (!visualDescriptionEditorEnabled) {
			forceActivityDescriptionHtmlMode();
			return;
		}

		var $textarea = $('#sd-activity-description');
		if (!$textarea.length) {
			return;
		}

		var currentValue = state.descriptionPendingValue !== null
			? String(state.descriptionPendingValue)
			: String($textarea.val() || '');

		destroyActivityDescriptionEditor();
		$textarea.val(currentValue);
		state.descriptionPendingValue = currentValue;

		window.setTimeout(function () {
			initActivityDescriptionEditor();
			window.setTimeout(function () {
				refreshActivityDescriptionVisualEditor();
				cleanupActivityDescriptionEditorUi();
			}, 120);
		}, 40);
	}

	function cleanupActivityDescriptionEditorUi() {
		var $wrap = $('#wp-sd-activity-description-wrap');
		if (!$wrap.length) {
			return;
		}

		$('#sd-activity-description').prop('readonly', false).prop('disabled', false);
	}

	function applyActivityDescriptionContentToNativeEditor(html, preferVisual) {
		var normalized = normalizeActivityDescriptionHtml(String(html || ''));
		var $textarea = $('#sd-activity-description');
		var textareaValue = normalizeActivityDescriptionHtml(String($textarea.val() || ''));
		debugDescriptionLog('apply:start', {
			inputLen: String(html || '').length,
			normalizedLen: normalized.length,
			preferVisual: !!preferVisual,
			textareaLenBefore: textareaValue.length,
		});

		if (!normalized && textareaValue) {
			normalized = textareaValue;
		}

		if ($textarea.length) {
			$textarea.val(normalized).prop('readonly', false).prop('disabled', false);
		}

		state.descriptionPendingValue = normalized;
		state.descriptionLastKnownHtml = normalized;
		if (state.currentActivity && typeof state.currentActivity === 'object') {
			state.currentActivity.description = normalized;
		}

		if (preferVisual && window.switchEditors && typeof window.switchEditors.go === 'function') {
			try {
				window.switchEditors.go('sd-activity-description', 'tmce');
			} catch (err2) {
				// Ignore mode-switch errors.
			}
		}

		var tries = 0;
		var maxTries = 40;

		function syncVisual() {
			tries += 1;
			var editor = getActivityDescriptionEditor();
			if (!isActivityDescriptionEditorHealthy(editor)) {
				if (tries === 1 || tries % 5 === 0 || tries === maxTries) {
					debugDescriptionLog('apply:wait-editor-healthy', {
						tryIndex: tries,
						maxTries: maxTries,
					});
				}
				if (tries < maxTries) {
					window.setTimeout(syncVisual, 80);
				}
				return;
			}

			try {
				var currentBefore = '';
				var bodyBefore = '';
				var body = (typeof editor.getBody === 'function') ? editor.getBody() : null;
				try {
					currentBefore = normalizeActivityDescriptionHtml(editor.getContent() || '');
				} catch (readContentErr) {
					currentBefore = '';
				}
				bodyBefore = body ? normalizeActivityDescriptionHtml(body.innerHTML || '') : '';
				var contentMatches = compareActivityDescriptionHtml(currentBefore, normalized);
				var bodyMatches = compareActivityDescriptionHtml(bodyBefore, normalized);

				// TinyMCE can report content in the model while iframe body is still empty on first open.
				// In that case, do not exit early: force a load/setContent pass.
				if (contentMatches && !bodyMatches && normalized) {
					try {
						if (typeof editor.load === 'function') {
							editor.load();
						}
					} catch (loadErr) {
						// Ignore and continue with setContent path below.
					}
					body = (typeof editor.getBody === 'function') ? editor.getBody() : null;
					bodyBefore = body ? normalizeActivityDescriptionHtml(body.innerHTML || '') : '';
					bodyMatches = compareActivityDescriptionHtml(bodyBefore, normalized);
				}

				if (bodyMatches || (contentMatches && bodyBefore)) {
					debugDescriptionLog('apply:already-matched', {
						tryIndex: tries,
						targetLen: normalized.length,
						currentLen: currentBefore.length,
						bodyLen: bodyBefore.length,
						contentMatches: contentMatches,
						bodyMatches: bodyMatches,
					});
					if (typeof editor.setMode === 'function') {
						editor.setMode('design');
					}
					if (typeof editor.show === 'function') {
						editor.show();
					}
					if (typeof editor.execCommand === 'function') {
						editor.execCommand('mceRepaint');
					}
					if (body) {
						body.setAttribute('contenteditable', 'true');
						body.style.pointerEvents = 'auto';
						body.style.userSelect = 'text';
						body.style.cursor = 'text';
					}
					return;
				}

				editor.setContent(normalized || '');
				editor.save();
				if (typeof editor.setMode === 'function') {
					editor.setMode('design');
				}
				if (typeof editor.show === 'function') {
					editor.show();
				}
				if (typeof editor.execCommand === 'function') {
					editor.execCommand('mceRepaint');
				}

				body = (typeof editor.getBody === 'function') ? editor.getBody() : null;
				if (body) {
					body.setAttribute('contenteditable', 'true');
					body.style.pointerEvents = 'auto';
					body.style.userSelect = 'text';
					body.style.cursor = 'text';
				}

				var bodyHtml = body ? normalizeActivityDescriptionHtml(body.innerHTML || '') : '';
				var current = normalizeActivityDescriptionHtml(editor.getContent() || '');
				if (tries === 1 || tries % 5 === 0 || current === normalized || bodyHtml === normalized || tries === maxTries) {
					debugDescriptionLog('apply:sync-try', {
						tryIndex: tries,
						maxTries: maxTries,
						targetLen: normalized.length,
						currentLen: current.length,
						bodyLen: bodyHtml.length,
						contentMatch: current === normalized,
						bodyMatch: bodyHtml === normalized,
					});
				}
				if (!compareActivityDescriptionHtml(current, normalized) && !compareActivityDescriptionHtml(bodyHtml, normalized) && tries < maxTries) {
					window.setTimeout(syncVisual, 80);
				}
			} catch (err3) {
				debugDescriptionLog('apply:sync-error', {
					tryIndex: tries,
					error: String((err3 && err3.message) ? err3.message : err3),
				});
				if (tries < maxTries) {
					window.setTimeout(syncVisual, 80);
				}
			}
		}

		window.setTimeout(syncVisual, 40);
	}

	// Stable native lifecycle override for Description editor.
	function initActivityDescriptionEditor() {
		var $textarea = $('#sd-activity-description');
		if (!$textarea.length) {
			return;
		}

		debugDescriptionLog('init:start', {
			textareaLen: String($textarea.val() || '').length,
			hasCurrentActivity: !!state.currentActivity,
		});

		if (!$(document).data('sdDescriptionNativeEditorInitBound')) {
			$(document).on('tinymce-editor-init.sdDescriptionNativeInit', function (event, editor) {
				if (!editor || String(editor.id || '') !== 'sd-activity-description') {
					return;
				}

				debugDescriptionLog('init:tinymce-editor-init', {
					editorId: String(editor.id || ''),
				});

				var sourceOnInit = String(
					$('#sd-activity-description').val()
					|| state.descriptionPendingValue
					|| state.descriptionLastKnownHtml
					|| (state.currentActivity && state.currentActivity.description)
					|| ''
				);

				applyActivityDescriptionContentToNativeEditor(sourceOnInit, true);
				window.setTimeout(function () {
					debugDescriptionLog('init:post-init-refresh-140ms', { delayMs: 140 });
					refreshActivityDescriptionVisualEditor();
				}, 140);
				window.setTimeout(function () {
					debugDescriptionLog('init:post-init-refresh-420ms', { delayMs: 420 });
					refreshActivityDescriptionVisualEditor();
				}, 420);
			});
			$(document).data('sdDescriptionNativeEditorInitBound', true);
		}

		if (!$(document).data('sdDescriptionNativeTabsBound')) {
			$(document)
				.on('click.sdDescriptionNativeTabsStable', '#sd-activity-description-html', function () {
					if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
						try {
							window.tinymce.triggerSave();
						} catch (err) {
							// Keep textarea value.
						}
					}
					syncActivityDescriptionHtmlTextareaFromEditor();
				})
				.on('click.sdDescriptionNativeTabsStable', '#sd-activity-description-tmce', function () {
					var source = String($('#sd-activity-description').val() || state.descriptionPendingValue || state.descriptionLastKnownHtml || '');
					window.setTimeout(function () {
						applyActivityDescriptionContentToNativeEditor(source, true);
					}, 30);
				});
			$(document).data('sdDescriptionNativeTabsBound', true);
		}

		var initial = String($textarea.val() || state.descriptionPendingValue || state.descriptionLastKnownHtml || (state.currentActivity && state.currentActivity.description) || '');
		debugDescriptionLog('init:initial-source', { initialLen: initial.length });
		window.setTimeout(function () {
			debugDescriptionLog('init:apply-initial-30ms', { delayMs: 30 });
			applyActivityDescriptionContentToNativeEditor(initial, true);
			window.setTimeout(function () {
				debugDescriptionLog('init:refresh-120ms', { delayMs: 120 });
				refreshActivityDescriptionVisualEditor();
			}, 120);
			window.setTimeout(function () {
				debugDescriptionLog('init:refresh-420ms', { delayMs: 420 });
				refreshActivityDescriptionVisualEditor();
			}, 420);
		}, 30);
	}

	function syncActivityDescriptionMode(mode) {
		if (mode === 'html') {
			if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
				try {
					window.tinymce.triggerSave();
				} catch (err) {
					// Keep fallback behavior.
				}
			}
			syncActivityDescriptionHtmlTextareaFromEditor();
			return;
		}

		var source = String($('#sd-activity-description').val() || state.descriptionPendingValue || state.descriptionLastKnownHtml || '');
		applyActivityDescriptionContentToNativeEditor(source, true);
	}

	function refreshActivityDescriptionVisualEditor() {
		debugDescriptionLog('refresh:visual-only', {
			sourceLen: String($('#sd-activity-description').val() || state.descriptionPendingValue || state.descriptionLastKnownHtml || '').length,
		});
		ensureActivityDescriptionVisualTabActive();
		ensureActivityDescriptionEditable();
		window.setTimeout(function () {
			var editor = getActivityDescriptionEditor();
			if (!editor || typeof editor.execCommand !== 'function') {
				return;
			}
			try {
				editor.execCommand('mceRepaint');
			} catch (err) {
				// Ignore repaint errors.
			}
		}, 40);
	}

	function scheduleActivityDescriptionRefresh(expectedHtml, delayMs, enforceContent) {
		if (state.descriptionRefreshTimer) {
			window.clearTimeout(state.descriptionRefreshTimer);
			state.descriptionRefreshTimer = null;
		}

		var delay = parseInt(delayMs, 10);
		if (!delay || delay < 0) {
			delay = 80;
		}

		state.descriptionRefreshTimer = window.setTimeout(function () {
			state.descriptionRefreshTimer = null;
			var source = enforceContent ? String(expectedHtml || '') : String($('#sd-activity-description').val() || state.descriptionPendingValue || state.descriptionLastKnownHtml || '');
			debugDescriptionLog('schedule:run', {
				delayMs: delay,
				enforceContent: !!enforceContent,
				sourceLen: source.length,
			});
			applyActivityDescriptionContentToNativeEditor(source, true);
			window.setTimeout(function () {
				debugDescriptionLog('schedule:refresh-120ms', { delayMs: 120 });
				refreshActivityDescriptionVisualEditor();
			}, 120);
			syncActivityDescriptionHtmlTextareaFromEditor();
		}, delay);
	}

	function waitForActivityDescriptionEditor() {
		window.setTimeout(function () {
			ensureActivityDescriptionEditable();
		}, 60);
	}

	function ensureActivityDescriptionEditable() {
		var $textarea = $('#sd-activity-description');
		if ($textarea.length) {
			$textarea.prop('readonly', false).prop('disabled', false);
		}

		var editor = getActivityDescriptionEditor();
		if (!editor) {
			return true;
		}

		try {
			if (typeof editor.setMode === 'function') {
				editor.setMode('design');
			}
			if (typeof editor.show === 'function') {
				editor.show();
			}
			if (typeof editor.execCommand === 'function') {
				editor.execCommand('mceRepaint');
			}
		} catch (err) {
			// Keep going.
		}

		return true;
	}

	function setTableLoading(selector, cols) {
		$(selector).html('<tr><td colspan="' + cols + '" class="sd-table-empty">' + esc(sdActivityAdmin.strings.loading) + '</td></tr>');
	}

	function setTableEmpty(selector, cols, text) {
		$(selector).html('<tr><td colspan="' + cols + '" class="sd-table-empty">' + esc(text) + '</td></tr>');
	}

	function setTableError(selector, cols, text) {
		$(selector).html('<tr><td colspan="' + cols + '" class="sd-table-empty">' + esc(text) + '</td></tr>');
	}

	function formatDate(mysqlDate) {
		if (!mysqlDate) {
			return '-';
		}
		var d = new Date(String(mysqlDate).replace(' ', 'T'));
		if (isNaN(d.getTime())) {
			return '-';
		}
		return ('0' + d.getDate()).slice(-2) + '.' + ('0' + (d.getMonth() + 1)).slice(-2) + '.' + d.getFullYear();
	}

	function toDateTimeLocal(mysqlDate) {
		if (!mysqlDate) {
			return '';
		}
		return String(mysqlDate).replace(' ', 'T').slice(0, 16);
	}

	function fromDateTimeLocal(localDate) {
		if (!localDate) {
			return '';
		}
		return localDate.replace('T', ' ') + ':00';
	}

	function todayDisplay() {
		var d = new Date();
		return ('0' + d.getDate()).slice(-2) + '.' + ('0' + (d.getMonth() + 1)).slice(-2) + '.' + d.getFullYear();
	}

	function todayYmd() {
		var d = new Date();
		return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
	}

	function updateActivityShortcodeHint(activityId) {
		var id = parseInt(activityId, 10) || 0;
		var $hint = $('#sd-activity-shortcode-hint');
		var $copyBtn = $('#sd-copy-shortcode-btn');
		if (!$hint.length) {
			return;
		}

		if (id > 0) {
			var shortcode = '[sd_iscrizione_attivita activity_id="' + id + '"]';
			$hint.html('Shortcode: <code>' + esc(shortcode) + '</code>');
			if ($copyBtn.length) {
				$copyBtn.data('shortcode', shortcode).show();
			}
		} else {
			$hint.text('Salva l\'attivita per ottenere lo shortcode.');
			if ($copyBtn.length) {
				$copyBtn.data('shortcode', '').hide();
			}
		}
	}

	function copyShortcodeToClipboard() {
		var shortcode = String($('#sd-copy-shortcode-btn').data('shortcode') || '');
		if (!shortcode) {
			return;
		}

		copyShortcodeText(shortcode, 'top');
	}

	function copyInlineShortcodeToClipboard() {
		var shortcode = String($(this).data('shortcode') || '');
		if (!shortcode) {
			var activityId = parseInt($(this).data('id'), 10) || 0;
			if (activityId > 0) {
				shortcode = '[sd_iscrizione_attivita activity_id="' + activityId + '"]';
			}
		}

		if (!shortcode) {
			return;
		}

		copyShortcodeText(shortcode);
	}

	function copyShortcodeText(shortcode, scrollTarget) {
		var messageScrollTarget = scrollTarget || null;

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(shortcode).then(function () {
				showMessage('success', 'Shortcode copiato negli appunti.', messageScrollTarget);
			}).catch(function () {
				showMessage('warning', 'Copia automatica non disponibile.', messageScrollTarget);
			});
			return;
		}

		var $tmp = $('<textarea readonly></textarea>').val(shortcode).appendTo('body');
		$tmp[0].select();
		try {
			document.execCommand('copy');
			showMessage('success', 'Shortcode copiato negli appunti.', messageScrollTarget);
		} catch (err) {
			showMessage('warning', 'Copia automatica non disponibile.', messageScrollTarget);
		}
		$tmp.remove();
	}

	function getRegistrationStatusLabel(status) {
		switch (String(status || '').toLowerCase()) {
			case 'registered':
				return 'Registrato';
			case 'waitlist':
				return 'Lista d\'attesa';
			case 'cancelled':
				return 'Annullato';
			default:
				return status || '-';
		}
	}

	function getEventStatusLabel(status) {
		switch (String(status || '').toLowerCase()) {
			case 'draft':
				return 'Bozza';
			case 'published':
				return 'Pubblicata';
			case 'closed':
				return 'Conclusa';
			case 'archived':
				return 'Archiviata';
			default:
				return status || '-';
		}
	}

	function getPaymentStatusLabel(status) {
		switch (String(status || '').toLowerCase()) {
			case 'pending':
				return 'In attesa';
			case 'paid':
				return 'Pagato';
			case 'invoice_requested':
				return 'Fattura richiesta';
			case 'invoice_sent':
				return 'Fattura inviata';
			case 'invoice_error':
				return 'Errore invio fattura';
			case 'cancelled':
				return 'Annullato';
			case 'free':
				return 'Gratuito';
			default:
				return status || '-';
		}
	}

	function getPriceEurWithCurrentRate(priceChf, priceEur) {
		var eur = parseFloat(priceEur || 0);
		if (eur > 0) {
			return eur;
		}

		var chf = parseFloat(priceChf || 0);
		if (chf <= 0) {
			return 0;
		}

		var rate = parseFloat((window.sdActivityAdmin && window.sdActivityAdmin.currentChfEurRate) || 0);
		if (rate <= 0) {
			return 0;
		}

		return chf * rate;
	}

	function getMinorInfoFromBirthDate(value) {
		var raw = String(value || '').trim();
		if (!raw) {
			return { isMinor: false, age: null };
		}

		var birth = new Date(raw + 'T00:00:00');
		if (isNaN(birth.getTime())) {
			return { isMinor: false, age: null };
		}

		var now = new Date();
		var age = now.getFullYear() - birth.getFullYear();
		var monthDiff = now.getMonth() - birth.getMonth();
		if (monthDiff < 0 || (monthDiff === 0 && now.getDate() < birth.getDate())) {
			age -= 1;
		}

		return {
			isMinor: age >= 0 && age < 18,
			age: age,
		};
	}

	function slugify(text) {
		return String(text || '')
			.toLowerCase()
			.trim()
			.replace(/[^a-z0-9\s_]/g, '')
			.replace(/\s+/g, '_')
			.replace(/_+/g, '_')
			.substring(0, 50);
	}

	function num(value) {
		var n = parseFloat(value || 0);
		return n.toFixed(2);
	}

	function esc(text) {
		return String(text === null || text === undefined ? '' : text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

})(jQuery);
