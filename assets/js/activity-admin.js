/**
 * Dashboard Admin Attivita - ScubaDiabetes
 * v: 2026-05-29a
 */
(function ($) {
	'use strict';
	var __ = (window.wp && window.wp.i18n) ? window.wp.i18n.__ : function (s) { return s; };

	/**
	 * Helper condiviso: esegue una richiesta AJAX che ritorna un blob PDF e lo scarica.
	 * @param {Object}   data      Dati POST (devono includere action e nonce)
	 * @param {string}   filename  Nome file suggerito per il download
	 * @param {Function} onDone    Callback invocata al termine (successo o errore)
	 */
	function sdDownloadPdfBlob(data, filename, onDone) {
		$.ajax({
			url: sdActivityAdmin.ajaxUrl,
			method: 'POST',
			data: data,
			xhrFields: { responseType: 'blob' },
			success: function (blob, status, xhr) {
				var contentType = xhr.getResponseHeader('Content-Type') || '';
				if (contentType.indexOf('application/pdf') === -1) {
					// Potrebbe essere un JSON di errore: leggiamolo
					var reader = new FileReader();
					reader.onload = function () {
						try {
							var json = JSON.parse(reader.result);
							alert((json.data && json.data.message) || 'Errore durante la generazione del PDF.');
						} catch (e) {
							alert('Errore durante la generazione del PDF.');
						}
					};
					reader.readAsText(blob);
					if (typeof onDone === 'function') { onDone(false); }
					return;
				}
				var link = document.createElement('a');
				link.href = window.URL.createObjectURL(blob);
				link.download = filename;
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);
				if (typeof onDone === 'function') { onDone(true); }
			},
			error: function () {
				alert('Errore durante la generazione del PDF.');
				if (typeof onDone === 'function') { onDone(false); }
			}
		});
	}

	// Inizializza sempre sdActivityAdmin.strings e ajaxUrl per evitare errori JS/AJAX
	if (!window.sdActivityAdmin) window.sdActivityAdmin = {};
	if (!sdActivityAdmin.strings) {
		sdActivityAdmin.strings = {
			loading: typeof __ === 'function' ? __('Caricamento...', 'sd-logbook') : 'Caricamento...',
			error: typeof __ === 'function' ? __('Errore', 'sd-logbook') : 'Errore',
			saveFirst: typeof __ === 'function' ? __('Salva prima l\'attività', 'sd-logbook') : 'Salva prima l\'attività',
			confirmDelete: typeof __ === 'function' ? __('Sei sicuro di voler eliminare?', 'sd-logbook') : 'Sei sicuro di voler eliminare?',
		};
	}
	// ajaxUrl viene ora sempre passato da PHP come assoluto
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
		descriptionHardRecoveryKey: '',
		descriptionModeFlipKey: '',
		descriptionModeFlipDone: false,
	};

	// Editor visuali in stile email-template: init tramite wp.editor.initialize con guard anti re-init.
	// Questo mantiene toolbar/menubar/plugins coerenti con "Corpo email (HTML)" e "Firma (HTML)".
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

		var headline = '[SD Desc #' + snapshot.seq + '] ' + eventName
			+ ' | mode=' + (editorMode || '?')
			+ ' init=' + (initialized ? 'Y' : 'N')
			+ ' healthy=' + (healthy ? 'Y' : 'N')
			+ ' ce=' + (bodyContentEditable || '?')
			+ ' cont=' + (containerDisplay || 'def') + '/' + (containerVisibility || 'def')
			+ ' size=' + (iframeWidth || '?') + 'x' + (iframeHeight || '?')
			+ ' txt=' + snapshot.textareaLen + ' pend=' + snapshot.pendingLen;
		if (extra && typeof extra === 'object') {
			try {
				var extraKeys = Object.keys(extra);
				for (var ei = 0; ei < extraKeys.length; ei++) {
					var ek = extraKeys[ei];
					var ev = extra[ek];
					if (ev === null || typeof ev === 'undefined') { continue; }
					if (typeof ev === 'object') { ev = JSON.stringify(ev).slice(0, 80); }
					headline += ' ' + ek + '=' + String(ev).slice(0, 80);
				}
			} catch (extraStrErr) {
				// Ignore stringify errors.
			}
		}
		console.log(headline, snapshot);
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
			   $('#sd-activities-table-wrap').hide();
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
			   // Mostra/nasconde la tabella attività in base al tab attivo
			   if (tab === 'modifica') {
				   $('#sd-activities-table-wrap').hide();
			   } else {
				   $('#sd-activities-table-wrap').show();
			   }
			if (tab === 'pagamenti' && typeof updatePaymentsStats === 'function') {
				updatePaymentsStats();
			}
			if (tab === 'registrazioni' && typeof loadRegistrations === 'function') {
				var $regSel = $('#sd-reg-activity-id');
				if (!$regSel.val()) {
					// Preferisce l'attività in stato (da modifica), altrimenti la prima disponibile
					if (state.selectedActivityId) {
						$regSel.val(String(state.selectedActivityId));
					} else if (state.activities && state.activities.length > 0) {
						$regSel.val(String(parseInt(state.activities[0].id, 10)));
					}
				}
				loadRegistrations();
				if (typeof loadPdfTemplates === 'function') { loadPdfTemplates(); }
			}
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
		$(document).on('change', '.sd-condition-operator', function () {
			var $row = $(this).closest('.sd-condition-rule-row');
			updateConditionValueInputState($row);
		});

		$('#sd-reg-filter-btn').on('click', loadRegistrations);
		$('#sd-reg-activity-id').on('change', loadRegistrations);
		$('#sd-reg-activity-id').on('change', loadPdfTemplates);
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

		$(document).on('click', '.sd-field-span-toggle', function () {
			toggleCustomFieldSpan(parseInt($(this).data('id'), 10) || 0);
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

		// PDF singola registrazione
		$(document).on('click', '.sd-reg-pdf-single', function () {
			var $btn = $(this);
			var registrationId = parseInt($btn.data('id'), 10) || 0;
			if (!registrationId) { return; }
			var origHtml = $btn.html();
			$btn.prop('disabled', true).text('...');
			sdDownloadPdfBlob({
				action: 'sd_activity_pdf_single_registration',
				nonce: sdActivityAdmin.nonce,
				registration_id: registrationId
			}, 'registrazione_' + registrationId + '.pdf', function () {
				$btn.prop('disabled', false).html(origHtml);
			});
		});

		$(document).on('click', '.sd-reg-delete', function () {
			var registrationId = parseInt($(this).data('id'), 10) || 0;
			if (!registrationId) {
				return;
			}
			var name = String($(this).data('name') || '').trim();
			var msg = 'Eliminare definitivamente l\'iscrizione' + (name ? ' di ' + name : '') + '?\nL\'azione non puo essere annullata.';
			if (!window.confirm(msg)) {
				return;
			}
			deleteRegistration(registrationId);
		});

		$(document).on('click', '.sd-reg-edit', function () {
			var registrationId = parseInt($(this).data('id'), 10) || 0;
			if (!registrationId) {
				return;
			}
			openRegistrationEditModal(registrationId);
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
				   action: 'sd_activity_get_activities_list',
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

		// Se il tab Registrazioni è attivo, assicurati che ci sia un'attività selezionata e carica
		if ($('.sd-admin-panel[data-panel="registrazioni"]').hasClass('is-active') && typeof loadRegistrations === 'function') {
			if (!$regSelect.val()) {
				if (state.selectedActivityId) {
					$regSelect.val(String(state.selectedActivityId));
				} else if (state.activities && state.activities.length > 0) {
					$regSelect.val(String(parseInt(state.activities[0].id, 10)));
				}
			}
			if ($regSelect.val()) {
				loadRegistrations();
			}
		}
	}

	function preventActivityDescriptionAutoFocusAndScrollTop() {
		var active = document.activeElement;
		if (active) {
			var $active = $(active);
			if (
				$active.is('#sd-activity-description')
				|| $active.closest('#wp-sd-activity-description-wrap').length
			) {
				try {
					active.blur();
				} catch (blurErr) {
					// Ignore blur errors.
				}
			}
		}

		try {
			if (typeof window.scrollTo === 'function') {
				window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
			} else {
				window.scrollTo(0, 0);
			}
		} catch (scrollErr) {
			$('html, body').stop(true).animate({ scrollTop: 0 }, 0);
		}
	}

	function editActivity(activityId) {
		if (!activityId) {
			return;
		}

		state.descriptionHardRecoveryKey = '';
		state.descriptionModeFlipKey = '';
		state.descriptionModeFlipDone = false;
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

			// Hide the description wrap immediately to avoid any flash of the previous/empty editor
			// during switchTab + setContent + destroy+reinit. Lock height to prevent layout jump.
			var $descWrap = $('#wp-sd-activity-description-wrap');
			var lockedDescHeight = 0;
			if ($descWrap.length) {
				lockedDescHeight = $descWrap.outerHeight() || 0;
				$descWrap.css({
					'min-height': lockedDescHeight ? (lockedDescHeight + 'px') : '',
					'visibility': 'hidden'
				});
			}
			var revealDescWrap = function () {
				if (!$descWrap.length) { return; }
				$descWrap.css({ 'visibility': '', 'min-height': '' });
			};

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
			preventActivityDescriptionAutoFocusAndScrollTop();
			scheduleActivityDescriptionRefresh(activityDescription, 140, true);
			debugDescriptionLog('editActivity:after-schedule-refresh', { delayMs: 140, enforceContent: true });
			window.setTimeout(function () {
				preventActivityDescriptionAutoFocusAndScrollTop();
			}, 90);
			window.setTimeout(function () {
				preventActivityDescriptionAutoFocusAndScrollTop();
			}, 300);

			// Mantieni l'istanza editor stabile: niente destroy/reinit forzato.
			window.setTimeout(function () {
				revealDescWrap();
			}, 180);

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

	function buildEmailTemplateTinyMceConfig(height) {
		var tadvUrl = (window.sdActivityAdmin && window.sdActivityAdmin.tinymceAdvancedMceUrl) || '';
		return {
			wpautop: false,
			height: height,
			menubar: true,
			branding: false,
			external_plugins: tadvUrl ? {
				table: tadvUrl + 'table/plugin.min.js',
				code: tadvUrl + 'code/plugin.min.js'
			} : {},
			toolbar1: 'formatselect styleselect | bold italic underline strikethrough forecolor backcolor | alignleft aligncenter alignright alignjustify',
			toolbar2: 'bullist numlist outdent indent | blockquote hr | link unlink image media table code | removeformat',
			toolbar3: 'undo redo | pastetext charmap | fullscreen',
			fontsize_formats: '8pt 10pt 11pt 12pt 14pt 16pt 18pt 24pt 30pt 36pt',
		};
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

		if (window.wp && window.wp.editor && typeof window.wp.editor.remove === 'function') {
			try {
				window.wp.editor.remove('sd-field-content-editor');
			} catch (err2) {
				// Ignore removal errors.
			}
		}

		window.sdFieldContentEditorInited = false;

		$('#sd-field-content-editor').prop('readonly', false).prop('disabled', false).show();
	}

	function ensureFieldContentEditorEditable() {
		var $textarea = $('#sd-field-content-editor');
		if (!$textarea.length) {
			return true;
		}

		$textarea.prop('readonly', false).prop('disabled', false);
		$textarea.css({
			'pointer-events': 'auto',
			'user-select': 'text',
			'cursor': 'text',
		});

		var editor = getFieldContentEditor();
		if (!editor) {
			$textarea.show();
			return false;
		}

		forceFieldContentTmceUiState();

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

	function forceFieldContentTmceUiState() {
		var $wrap = $('#wp-sd-field-content-editor-wrap');
		if (!$wrap.length) {
			return;
		}

		$wrap.removeClass('html-active').addClass('tmce-active');
		$wrap.find('.wp-editor-area').hide();
		$wrap.find('.quicktags-toolbar').hide();
		$wrap.find('.mce-tinymce').css({ display: '', visibility: 'visible' }).show();
		$wrap.find('.mce-edit-area').css({ display: '', visibility: 'visible' }).show();

		$('#sd-field-content-editor-html').addClass('wp-switch-editor switch-html');
		$('#sd-field-content-editor-tmce').addClass('wp-switch-editor switch-tmce');
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
		var target = document.getElementById('sd-field-content-editor');
		if (!target) {
			return;
		}

		var existingEditor = getFieldContentEditor();
		if (existingEditor) {
			window.sdFieldContentEditorInited = true;
			ensureFieldContentEditorEditable();
			return;
		}

		if ($('#wp-sd-field-content-editor-wrap').length) {
			window.sdFieldContentEditorInited = true;
			ensureFieldContentEditorEditable();
			return;
		}

		if (!window.wp || !window.wp.editor || typeof window.wp.editor.initialize !== 'function') {
			return;
		}

		if (window.sdFieldContentEditorInited) {
			return;
		}

		var tinyConfig = buildEmailTemplateTinyMceConfig(260);
		tinyConfig.setup = function (editor) {
			editor.on('init', function () {
				ensureFieldContentEditorEditable();
				editor.save();
			});
			editor.on('change keyup input undo redo SetContent', function () {
				editor.save();
			});
		};

		window.sdFieldContentEditorInited = true;
		window.wp.editor.initialize('sd-field-content-editor', {
			mediaButtons: true,
			quicktags: true,
			tinymce: tinyConfig,
		});

		window.setTimeout(forceFieldContentTmceUiState, 80);
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

		if (!window.confirm(__('Eliminare questa tariffa?', 'sd-logbook'))) {
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
	var editingOptionIdx = -1;

	function resetOptionEditing() {
		editingOptionIdx = -1;
		$('#sd-option-label').val('');
		$('#sd-option-value').val('');
		var $btn = $('#sd-add-option-btn');
		if ($btn.length) {
			if (!$btn.data('sdDefaultLabel')) {
				$btn.data('sdDefaultLabel', $btn.text());
			}
			$btn.text($btn.data('sdDefaultLabel'));
		}
		$('#sd-cancel-option-edit-btn').remove();
	}

	function startOptionEdit(idx) {
		if (idx < 0 || idx >= fieldOptions.length) {
			return;
		}
		editingOptionIdx = idx;
		var opt = fieldOptions[idx];
		$('#sd-option-label').val(opt.label || '').focus();
		$('#sd-option-value').val(opt.value || '');
		var $btn = $('#sd-add-option-btn');
		if ($btn.length) {
			if (!$btn.data('sdDefaultLabel')) {
				$btn.data('sdDefaultLabel', $btn.text());
			}
			$btn.text('Salva modifica');
			if (!$('#sd-cancel-option-edit-btn').length) {
				$('<button type="button" id="sd-cancel-option-edit-btn" class="sd-btn sd-btn-link" style="margin-left:6px;">Annulla</button>')
					.insertAfter($btn)
					.on('click', function () {
						resetOptionEditing();
						renderOptionsPreview();
					});
			}
		}
		renderOptionsPreview();
	}

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
			resetOptionEditing();
			renderOptionsPreview();
		}
		
		// Inizializza editor stile email-template per "Contenuto Formattato".
		if (isContent) {
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

		// Campi base sempre presenti nel modulo iscrizione.
		html += '<option value="-101">Nome</option>';
		html += '<option value="-102">Cognome</option>';
		html += '<option value="-103">Email</option>';
		html += '<option value="-104">Data di nascita</option>';

		(state.currentFields || []).forEach(function (field) {
			var sourceId = parseInt(field.id, 10) || 0;
			var sourceFieldName = String(field && field.field_name ? field.field_name : '').toLowerCase();
			// I campi base hanno gia' voci virtuali dedicate (-101..-104): evita duplicati ambigui.
			if (sourceFieldName === 'first_name' || sourceFieldName === 'last_name' || sourceFieldName === 'email' || sourceFieldName === 'birth_date') {
				return;
			}
			if (!sourceId || sourceId === targetId || field.field_type === 'content') {
				return;
			}
			html += '<option value="' + sourceId + '">' + esc(field.field_label || ('Campo #' + sourceId)) + '</option>';
		});

		return html;
	}

	function normalizeConditionOperator(operator) {
		var op = String(operator || '').trim().toLowerCase();
		if (op === 'not_equals' || op === '!=' || op === 'diverso') {
			return 'not_equals';
		}
		if (op === 'greater' || op === 'maggiore' || op === 'gt' || op === '>') {
			return 'greater';
		}
		if (op === 'less' || op === 'minore' || op === 'lt' || op === '<') {
			return 'less';
		}
		if (op === 'contains' || op === 'contiene' || op === 'contiente') {
			return 'contains';
		}
		if (op === 'true' || op === 'vero') {
			return 'true';
		}
		if (op === 'false' || op === 'falso') {
			return 'false';
		}
		if (op === 'minorenne') {
			return 'minorenne';
		}
		if (op === 'maggiorenne') {
			return 'maggiorenne';
		}
		if (op === 'empty' || op === 'vuoto' || op === 'null') {
			return 'empty';
		}
		if (op === 'not_empty' || op === 'non_vuoto' || op === 'not_null') {
			return 'not_empty';
		}
		return 'equals';
	}

	function conditionOperatorRequiresValue(operator) {
		var op = normalizeConditionOperator(operator);
		return op === 'equals' || op === 'not_equals' || op === 'greater' || op === 'less' || op === 'contains';
	}

	function getConditionValuePlaceholder(operator) {
		var op = normalizeConditionOperator(operator);
		if (op === 'greater') {
			return 'Valore soglia (es. 18)';
		}
		if (op === 'less') {
			return 'Valore soglia (es. 65)';
		}
		if (op === 'contains') {
			return 'Testo da contenere';
		}
		return 'Valore atteso (es. Non diabetico)';
	}

	function getConditionOperatorOptionsHtml() {
		var html = '';
		html += '<option value="equals">È uguale a</option>';
		html += '<option value="not_equals">È diverso da</option>';
		html += '<option value="greater">Maggiore</option>';
		html += '<option value="less">Minore</option>';
		html += '<option value="contains">Contiene</option>';
		html += '<option value="true">Vero</option>';
		html += '<option value="false">Falso</option>';
		html += '<option value="minorenne">Minorenne (data di nascita)</option>';
		html += '<option value="maggiorenne">Maggiorenne (data di nascita)</option>';
		html += '<option value="empty">Vuoto (null)</option>';
		html += '<option value="not_empty">Non vuoto (not null)</option>';
		return html;
	}

	function updateConditionValueInputState($row) {
		if (!$row || !$row.length) {
			return;
		}

		var operator = normalizeConditionOperator($row.find('.sd-condition-operator').val());
		var requiresValue = conditionOperatorRequiresValue(operator);
		var $valueInput = $row.find('.sd-condition-value');

		$valueInput.attr('placeholder', getConditionValuePlaceholder(operator));
		if (!requiresValue) {
			$valueInput.val('');
			$valueInput.prop('disabled', true).hide();
			return;
		}

		$valueInput.prop('disabled', false).show();
	}

	function addConditionRuleRow(rule) {
		var targetId = parseInt($('#sd-field-id').val(), 10) || 0;
		var sourceId = rule && rule.source_field_id ? parseInt(rule.source_field_id, 10) : 0;
		var operator = normalizeConditionOperator(rule && rule.operator ? rule.operator : 'equals');
		var value = rule && rule.value ? String(rule.value) : '';
		var sourceOptions = getConditionSourceOptionsHtml(targetId);
		var html = '';

		html += '<div class="sd-field-builder-meta sd-condition-rule-row">';
		html += '<select class="sd-select sd-condition-source-field">' + sourceOptions + '</select>';
		html += '<select class="sd-select sd-condition-operator">' + getConditionOperatorOptionsHtml() + '</select>';
		html += '<input type="text" class="sd-input sd-condition-value" placeholder="Valore atteso (es. Non diabetico)">';
		html += '<button type="button" class="sd-btn sd-btn-danger sd-condition-rule-remove">Rimuovi</button>';
		html += '</div>';

		$('#sd-condition-rules').append(html);
		var $row = $('#sd-condition-rules .sd-condition-rule-row').last();
		$row.find('.sd-condition-source-field').val(sourceId ? String(sourceId) : '');
		$row.find('.sd-condition-operator').val(operator);
		$row.find('.sd-condition-value').val(value);
		updateConditionValueInputState($row);
	}

	function collectConditionRulesFromForm() {
		var rules = [];
		$('#sd-condition-rules .sd-condition-rule-row').each(function () {
			var $row = $(this);
			var sourceId = parseInt($row.find('.sd-condition-source-field').val(), 10) || 0;
			var operator = normalizeConditionOperator($row.find('.sd-condition-operator').val());
			var value = String($row.find('.sd-condition-value').val() || '').trim();
			var requiresValue = conditionOperatorRequiresValue(operator);

			if (!sourceId || (requiresValue && value === '')) {
				return;
			}

			rules.push({
				source_field_id: sourceId,
				operator: operator,
				value: requiresValue ? value : '',
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
		if (editingOptionIdx >= 0 && editingOptionIdx < fieldOptions.length) {
			fieldOptions[editingOptionIdx] = { label: label, value: value };
			resetOptionEditing();
		} else {
			fieldOptions.push({ label: label, value: value });
			$('#sd-option-label').val('');
			$('#sd-option-value').val('');
		}
		renderOptionsPreview();
	}

	function commitPendingOptionEditIfAny() {
		if (editingOptionIdx < 0) {
			return true;
		}

		var label = $('#sd-option-label').val().trim();
		var value = $('#sd-option-value').val().trim() || slugify(label);

		if (!label) {
			showMessage('error', 'Completa l\'etichetta dell\'opzione in modifica prima di salvare il campo.');
			return false;
		}

		if (editingOptionIdx >= fieldOptions.length) {
			editingOptionIdx = fieldOptions.length - 1;
		}

		if (editingOptionIdx < 0) {
			fieldOptions.push({ label: label, value: value });
		} else {
			fieldOptions[editingOptionIdx] = { label: label, value: value };
		}

		resetOptionEditing();
		renderOptionsPreview();
		return true;
	}

	function renderOptionsPreview() {
		var html = '';
		if (!fieldOptions.length) {
			html = '<li class="sd-mini-list-empty">Nessuna opzione aggiunta.</li>';
		} else {
			fieldOptions.forEach(function (opt, idx) {
				var isEditing = (idx === editingOptionIdx);
				html += '<li class="sd-option-item' + (isEditing ? ' sd-option-item-editing' : '') + '">';
				html += '<span>' + esc(opt.label) + ' <small>(' + esc(opt.value) + ')</small></span>';
				html += '<span class="sd-option-actions">';
				html += '<button type="button" class="sd-btn-edit-option" data-idx="' + idx + '" title="Modifica">&#9998;</button>';
				html += '<button type="button" class="sd-btn-remove-option" data-idx="' + idx + '" title="Rimuovi">&#x2715;</button>';
				html += '</span>';
				html += '</li>';
			});
		}
		var $preview = $('#sd-options-preview');
		$preview.html(html);
		$preview.off('click', '.sd-btn-remove-option').on('click', '.sd-btn-remove-option', function () {
			var idx = parseInt($(this).data('idx'), 10);
			fieldOptions.splice(idx, 1);
			if (editingOptionIdx === idx) {
				resetOptionEditing();
			} else if (editingOptionIdx > idx) {
				editingOptionIdx--;
			}
			renderOptionsPreview();
		});
		$preview.off('click', '.sd-btn-edit-option').on('click', '.sd-btn-edit-option', function () {
			startOptionEdit(parseInt($(this).data('idx'), 10));
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

		if (needsOptions && !commitPendingOptionEditIfAny()) {
			return;
		}
		
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
		resetOptionEditing();
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
		if (field.field_type === 'content') {
			window.setTimeout(function () {
				initFieldContentEditor();
				if (!setFieldContentEditorValue(field.content || '', { focus: true })) {
					$('#sd-field-content-editor').val(field.content || '');
				}
				window.setTimeout(function () {
					ensureFieldContentEditorEditable();
					setFieldContentEditorValue(field.content || '', { focus: false });
				}, 180);
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
		if (!fieldId || !window.confirm(__('Eliminare questo campo?', 'sd-logbook'))) {
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
					var baseKeys = [];
					var sectionKeys = [];
					$activityBlocksList.children('li[data-activity-block-key]').each(function () {
						var key = String($(this).data('activityBlockKey') || '');
						if (!key) { return; }
						if (key.indexOf('section:') === 0) {
							sectionKeys.push(key.replace(/^section:/, ''));
						} else {
							baseKeys.push(key);
						}
					});
					if (sectionKeys.length) {
						saveActivityDataSectionsOrderByKeys(sectionKeys);
					} else if (baseKeys.length) {
						saveActivityBlocksOrderByKeys(baseKeys);
					}
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

	function getCustomFieldSpan(fieldId) {
		var id = parseInt(fieldId, 10) || 0;
		if (!id) { return 12; }
		var map = getPersonalFieldSpanMap();
		return parseInt(map['field:' + id] || 12, 10) === 6 ? 6 : 12;
	}

	function toggleCustomFieldSpan(fieldId) {
		var id = parseInt(fieldId, 10) || 0;
		if (!state.selectedActivityId || !id) {
			return;
		}

		var formConfig = $.extend(true, {}, getFormConfig());
		var spans = (formConfig.personal_field_spans && typeof formConfig.personal_field_spans === 'object') ? formConfig.personal_field_spans : {};
		var token = 'field:' + id;
		var current = parseInt(spans[token] || 12, 10) === 6 ? 6 : 12;
		spans[token] = current === 6 ? 12 : 6;
		formConfig.personal_field_spans = spans;
		saveFormConfiguration(formConfig, 'Layout campo aggiornato.', { scrollTarget: 'top' });
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

		persistActivityDataSectionsOrder(reordered);
	}

	// Persist the reordered activity-data sections (used by both move-arrow buttons and DnD).
	function persistActivityDataSectionsOrder(reordered) {
		if (!Array.isArray(reordered) || !reordered.length) {
			return;
		}

		var requests = [];
		var meta = getSectionMeta();
		var metaChanged = false;
		meta.layout_order = reordered.map(function (section) {
			return String((section && section.key) || '').trim();
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

	// Save activity-data sections order from a list of section keys (used by DnD).
	function saveActivityDataSectionsOrderByKeys(sectionKeys) {
		if (!state.selectedActivityId || !Array.isArray(sectionKeys) || !sectionKeys.length) {
			return;
		}

		var current = getActivityDataSectionsSummary();
		var byKey = {};
		current.forEach(function (section) {
			byKey[String(section.key)] = section;
		});

		var reordered = [];
		var seen = {};
		sectionKeys.forEach(function (key) {
			var k = String(key || '').trim();
			if (!k || seen[k] || !byKey[k]) { return; }
			seen[k] = true;
			reordered.push(byKey[k]);
		});
		// Append any sections missing from the DnD payload to keep them stable at the end.
		current.forEach(function (section) {
			if (!seen[String(section.key)]) {
				reordered.push(section);
			}
		});

		persistActivityDataSectionsOrder(reordered);
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
						
						var customSpan = getCustomFieldSpan(field.id);
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
						html += '<span>' + (customSpan === 6 ? 'Metà riga' : 'Intera riga') + '</span>';
						html += '</div>';
						html += '</div>';
						html += '<div class="sd-field-list-actions">';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-field-move" data-id="' + parseInt(field.id, 10) + '" data-direction="up">↑</button>';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-field-move" data-id="' + parseInt(field.id, 10) + '" data-direction="down">↓</button>';
						html += '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-field-span-toggle" data-id="' + parseInt(field.id, 10) + '">' + (customSpan === 6 ? 'Intero' : 'Affianca') + '</button>';
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
				if (!window.confirm(__('Eliminare tutte le tariffe dalla sezione Tariffe?', 'sd-logbook'))) {
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
				if (!window.confirm(__('Eliminare i dati della sezione Dati Attivita?', 'sd-logbook'))) {
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

		if (!window.confirm(__('Eliminare la sezione "%s" e tutti i suoi campi?', 'sd-logbook').replace('%s', section.label))) {
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

	function loadPdfTemplates() {
		var activityId = parseInt($('#sd-reg-activity-id').val(), 10) || 0;
		var $sel = $('#sd-reg-tpl-select');
		if (!$sel.length) { return; }
		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_pdf_tpl_list',
			nonce: sdActivityAdmin.nonce
		}, function (resp) {
			if (!resp || !resp.success) {
				$sel.html('');
				return;
			}
			var templates = resp.data.templates || [];
			var filtered = templates.filter(function (t) {
				return !parseInt(t.activity_id, 10) || parseInt(t.activity_id, 10) === activityId;
			});
			var html = '';
			filtered.forEach(function (t) {
				html += '<option value="' + parseInt(t.id, 10) + '">' + esc(t.name) + '</option>';
			});
			$sel.html(html);
		}).fail(function () {
			$sel.html('');
		});
	}

	function loadRegistrations() {
		var activityId = parseInt($('#sd-reg-activity-id').val(), 10) || 0;
		if (!activityId) {
			setTableEmpty('#sd-reg-dashboard-tbody', 8, sdActivityAdmin.regSelectActivity);
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
				setTableError('#sd-reg-dashboard-tbody', 8, (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}

			state.registrations = resp.data.registrations || [];
			// Sync to regDashboardState.rows so filter buttons work
			regDashboardState.rows = (state.registrations || []).map(function (r) {
				var em = String(r.email || '');
				var ln = String(r.last_name || '');
				var fn = String(r.first_name || '');
				return {
					id: parseInt(r.id, 10) || 0,
					first_name: fn,
					last_name: ln,
					name: (ln && fn ? ln + ', ' + fn : (ln || fn)).trim(),
					email: em,
					status: String(r.status || ''),
					payment_status: String(r.payment_status || ''),
					price_chf: parseFloat(r.price_chf) || 0,
					price_eur: parseFloat(r.price_eur) || 0,
					created_at: String(r.created_at || ''),
					can_remind: !!(em && em.indexOf('@') > 0 && em.indexOf('.') > em.indexOf('@')),
					last_email_at: '',
					last_email_subject: '',
				};
			});
			renderRegistrationsTable();
			updatePaymentsStats();
		});
	}

	function renderRegistrationsTable() {
		var rows = state.registrations;
		if (!rows.length) {
			setTableEmpty('#sd-reg-dashboard-tbody', 8, 'Nessuna registrazione trovata.');
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
			var canSendPaymentConfirmation = paymentStatusCode === 'paid' && !!r.email;
			var statusSelect = buildStatusSelect(parseInt(r.id, 10), statusCode);
			var paymentSelect = buildPaymentStatusSelect(parseInt(r.id, 10), paymentStatusCode);
			var canResendInvoice = paymentStatusCode === 'invoice_requested' || paymentStatusCode === 'invoice_error';
			var eurAmount = getPriceEurWithCurrentRate(r.price_chf, r.price_eur);
			var actionsHtml = [];
			actionsHtml.push('<button type="button" class="sd-btn sd-btn-outline sd-btn-sm sd-btn-icon-only sd-activity-preview-email" data-reg-id="' + parseInt(r.id, 10) + '" title="Anteprima e-mail" aria-label="Anteprima e-mail">\uD83D\uDD0D</button>');
			actionsHtml.push('<button type="button" class="sd-btn sd-btn-primary sd-btn-sm sd-btn-icon-only sd-send-reg-email" data-reg-id="' + parseInt(r.id, 10) + '" title="Invia e-mail" aria-label="Invia e-mail"' + (r.email ? '' : ' disabled') + '>\u2709</button>');
			actionsHtml.push('<button type="button" class="sd-btn sd-btn-primary sd-btn-sm sd-reg-send-payment-confirmation" data-id="' + parseInt(r.id, 10) + '"' + (canSendPaymentConfirmation ? '' : ' disabled') + '>Invia conferma pagamento</button>');
			if (canResendInvoice) {
				actionsHtml.push('<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-resend-invoice" data-id="' + parseInt(r.id, 10) + '">Reinvia fattura</button>');
			}
			actionsHtml.push('<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-edit" data-id="' + parseInt(r.id, 10) + '" title="Modifica iscrizione">Modifica</button>');
			actionsHtml.push('<button type="button" class="sd-btn sd-btn-info sd-btn-sm sd-reg-tpl-pdf-row" data-id="' + parseInt(r.id, 10) + '" title="PDF con template selezionato">📑 PDF</button>');
			actionsHtml.push('<button type="button" class="sd-btn sd-btn-danger sd-btn-sm sd-reg-delete" data-id="' + parseInt(r.id, 10) + '" data-name="' + esc((r.first_name || '') + ' ' + (r.last_name || '')) + '" title="Elimina iscrizione">Elimina</button>');
			if (minorInfo.isMinor) {
				minorCount += 1;
			}
			var nameHtml = esc((r.first_name || '') + ' ' + (r.last_name || ''));
			if (minorInfo.isMinor) {
				nameHtml += ' <span class="sd-status-badge sd-status-draft">MINORENNE (' + esc(String(minorInfo.age)) + ' anni)</span>';
			}
			var lastEmailHtml = r.last_email_at
				? '<span title="' + esc(r.last_email_subject || '') + '">' + formatDate(r.last_email_at) + '</span>'
				: '-';
			html += '<tr>' +
				'<td>' + nameHtml + '</td>' +
				'<td>' + esc(r.email || '-') + '</td>' +
				'<td>' + statusSelect + '</td>' +
				'<td>' + paymentSelect + '</td>' +
				'<td>' + formatDate(r.created_at) + '</td>' +
				'<td>CHF ' + num(r.price_chf) + ' / EUR ' + num(eurAmount) + '</td>' +
				'<td>' + lastEmailHtml + '</td>' +
				'<td>' + (actionsHtml.length ? actionsHtml.join(' ') : '<span class="sd-field-note">-</span>') + '</td>' +
			'</tr>';
		});

		$('#sd-reg-dashboard-tbody').html(html);

		if (minorCount > 0) {
			$('#sd-reg-minor-alert').html('<strong>Attenzione:</strong> rilevate ' + esc(String(minorCount)) + ' iscrizioni di minorenni. Verifica autorizzazione genitore/tutore.').show();
		} else {
			$('#sd-reg-minor-alert').hide().empty();
		}
	}

	function buildStatusSelect(registrationId, currentStatus) {
		var options = ['registered', 'waitlist', 'cancelled'];
		var statusClass = getStatusCssClass(currentStatus);
		var label = getRegistrationStatusLabel(currentStatus);
		var optHtml = '';
		options.forEach(function (value) {
			optHtml += '<option value="' + esc(value) + '"' + (value === currentStatus ? ' selected' : '') + '>' + esc(getRegistrationStatusLabel(value)) + '</option>';
		});
		return '<span class="sd-status-pill-wrap">' +
			'<span class="sd-renewal-status' + statusClass + '">' + esc(label) + '</span>' +
			'<select class="sd-status-pill-select sd-reg-status-select" data-id="' + registrationId + '">' + optHtml + '</select>' +
			'</span>';
	}

	function buildPaymentStatusSelect(registrationId, currentStatus) {
		var options = ['pending', 'paid', 'invoice_requested', 'invoice_sent', 'invoice_error', 'cancelled', 'free'];
		var statusClass = getStatusCssClass(currentStatus);
		var label = getPaymentStatusLabel(currentStatus);
		var optHtml = '';
		options.forEach(function (value) {
			optHtml += '<option value="' + esc(value) + '"' + (value === currentStatus ? ' selected' : '') + '>' + esc(getPaymentStatusLabel(value)) + '</option>';
		});
		return '<span class="sd-status-pill-wrap">' +
			'<span class="sd-renewal-status' + statusClass + '">' + esc(label) + '</span>' +
			'<select class="sd-status-pill-select sd-reg-payment-select" data-id="' + registrationId + '">' + optHtml + '</select>' +
			'</span>';
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

	function deleteRegistration(registrationId) {
		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_registration_delete',
			nonce: sdActivityAdmin.nonce,
			registration_id: registrationId,
		}, function (resp) {
			if (!resp || !resp.success) {
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}
			showMessage('success', (resp && resp.data && resp.data.message) ? resp.data.message : __('Registrazione eliminata.', 'sd-logbook'), 'top');
			loadRegistrations();
			loadRegDashboard();
		}).fail(function (xhr) {
			var msg = sdActivityAdmin.strings.error || 'Errore di comunicazione con il server.';
			try {
				var body = xhr.responseJSON || JSON.parse(xhr.responseText || '{}');
				if (body && body.data && body.data.message) { msg = body.data.message; }
			} catch (e) {}
			showMessage('error', msg, 'top');
		});
	}

	// =====================================================================
	// EDIT REGISTRATION MODAL
	// =====================================================================
	var registrationEditCache = {};

	function openRegistrationEditModal(registrationId) {
		var reg = null;
		(state.registrations || []).some(function (r) {
			if (parseInt(r.id, 10) === parseInt(registrationId, 10)) { reg = r; return true; }
			return false;
		});
		if (!reg) {
			// Fallback multilanguage: recupera la registrazione via AJAX se non presente in state.registrations
			$.post(sdActivityAdmin.ajaxUrl, {
				action: 'sd_activity_registration_get',
				nonce: sdActivityAdmin.nonce,
				registration_id: registrationId
			}, function (resp) {
				if (resp && resp.success && resp.data) {
					reg = resp.data;
					buildRegistrationEditModal(reg, null);
					var activityId = parseInt(reg.activity_id, 10) || 0;
					if (activityId && registrationEditCache[activityId]) {
						renderRegistrationEditFields(reg, registrationEditCache[activityId]);
						return;
					}
					if (!activityId) {
						renderRegistrationEditFields(reg, { prices: [], form_fields: [] });
						return;
					}
					$.post(sdActivityAdmin.ajaxUrl, {
						action: 'sd_activity_get',
						activity_id: activityId,
						nonce: sdActivityAdmin.nonce,
					}, function (resp2) {
						if (resp2 && resp2.success) {
							registrationEditCache[activityId] = {
								prices: resp2.data.prices || [],
								form_fields: resp2.data.form_fields || [],
							};
							renderRegistrationEditFields(reg, registrationEditCache[activityId]);
						} else {
							renderRegistrationEditFields(reg, { prices: [], form_fields: [] });
						}
					});
				} else {
					var msg = (resp && resp.data && resp.data.message) ? resp.data.message : (window.sdActivityAdmin && sdActivityAdmin.i18n && sdActivityAdmin.i18n.registration_not_found) ? sdActivityAdmin.i18n.registration_not_found : 'Registrazione non trovata';
					alert(msg);
				}
			});
			return;
		}
		buildRegistrationEditModal(reg, null);
		var activityId = parseInt(reg.activity_id, 10) || 0;
		if (activityId && registrationEditCache[activityId]) {
			renderRegistrationEditFields(reg, registrationEditCache[activityId]);
			return;
		}
		if (!activityId) {
			renderRegistrationEditFields(reg, { prices: [], form_fields: [] });
			return;
		}
		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_get',
			activity_id: activityId,
			nonce: sdActivityAdmin.nonce,
		}, function (resp) {
			if (resp && resp.success) {
				registrationEditCache[activityId] = {
					prices: resp.data.prices || [],
					form_fields: resp.data.form_fields || [],
				};
				renderRegistrationEditFields(reg, registrationEditCache[activityId]);
			} else {
				renderRegistrationEditFields(reg, { prices: [], form_fields: [] });
			}
		});
	}

	function buildRegistrationEditModal(reg, activityCtx) {
		closeRegistrationEditModal();
		var html = '' +
			'<div class="sd-modal-backdrop sd-reg-edit-backdrop">' +
				'<div class="sd-modal sd-reg-edit-modal" role="dialog" aria-modal="true">' +
					'<div class="sd-modal-header">' +
						'<h3>Modifica iscrizione #' + parseInt(reg.id, 10) + '</h3>' +
						'<button type="button" class="sd-modal-close" aria-label="Chiudi">&times;</button>' +
					'</div>' +
					'<div class="sd-modal-body">' +
						'<div class="sd-reg-edit-loading">Caricamento dati attivita\u2026</div>' +
						'<form id="sd-reg-edit-form" style="display:none;">' +
							'<input type="hidden" name="registration_id" value="' + parseInt(reg.id, 10) + '">' +
							'<div class="sd-reg-edit-base"></div>' +
							'<h4 class="sd-reg-edit-section-title">Dati modulo</h4>' +
							'<div class="sd-reg-edit-dynamic"></div>' +
						'</form>' +
					'</div>' +
					'<div class="sd-modal-footer">' +
						'<button type="button" class="sd-btn sd-btn-outline sd-modal-close">Annulla</button>' +
						'<button type="button" class="sd-btn sd-btn-primary" id="sd-reg-edit-save" disabled>Salva</button>' +
					'</div>' +
				'</div>' +
			'</div>';
		$('body').append(html);
		$('.sd-reg-edit-backdrop').on('click', function (e) {
			if (e.target === this) { closeRegistrationEditModal(); }
		});
		$('.sd-reg-edit-backdrop .sd-modal-close').on('click', closeRegistrationEditModal);
		$(document).on('keydown.sdRegEdit', function (e) {
			if (e.key === 'Escape') { closeRegistrationEditModal(); }
		});
	}

	function closeRegistrationEditModal() {
		$('.sd-reg-edit-backdrop').remove();
		$(document).off('keydown.sdRegEdit');
		$('#sd-reg-edit-save').off('click');
	}

	// Etichette amichevoli per chiavi interne / di sistema usate in registration_data.
	var REG_FIELD_LABELS = {
		birth_date: 'Data di nascita',
		is_minor: 'Minorenne',
		luogo_di_nascita: 'Luogo di nascita',
		diabete_tipo: 'Tipo di diabete',
		celiachia: 'Celiachia',
		telefono_cellulare: 'Telefono cellulare',
		selected_price_ids: 'Tariffa selezionata (ID)',
		selected_price_names: 'Tariffa selezionata (nome)',
		selected_price_count: 'Numero tariffe selezionate'
	};
	// Chiavi calcolate/di sistema: mostrate in sola lettura (gestite dal select Tariffa).
	var REG_FIELD_READONLY = {
		selected_price_ids: true,
		selected_price_names: true,
		selected_price_count: true
	};

	function regFieldLabel(key, def) {
		if (def && def.field_label) { return def.field_label; }
		if (REG_FIELD_LABELS[key]) { return REG_FIELD_LABELS[key]; }
		// Fallback: snake_case → "Snake Case"
		return String(key).replace(/[_\-]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}

	function regFieldDisplayValue(key, value) {
		if (key === 'is_minor') {
			var v = String(value);
			if (v === '1' || v === 'true' || v === 'yes') { return 'Si'; }
			if (v === '0' || v === 'false' || v === 'no' || v === '') { return 'No'; }
		}
		if (Array.isArray(value)) { return value.join(', '); }
		return value == null ? '' : String(value);
	}

	function renderRegistrationEditFields(reg, ctx) {
		var prices = (ctx && Array.isArray(ctx.prices)) ? ctx.prices : [];
		var formFields = (ctx && Array.isArray(ctx.form_fields)) ? ctx.form_fields : [];
		var regData = (reg.registration_data && typeof reg.registration_data === 'object') ? reg.registration_data : {};

		// --- Base fields ---
		var priceOptions = '<option value="0">-- Nessuna --</option>';
		prices.forEach(function (p) {
			var sel = (parseInt(p.id, 10) === parseInt(reg.price_id, 10)) ? ' selected' : '';
			priceOptions += '<option value="' + parseInt(p.id, 10) + '"' + sel + '>'
				+ esc(p.price_name || ('Tariffa #' + p.id))
				+ ' (CHF ' + num(p.price_chf) + ' / EUR ' + num(p.price_eur) + ')</option>';
		});
		var regStatusOptions = [
			{ v: 'registered', l: 'Iscritto' },
			{ v: 'waitlist', l: 'Lista d\'attesa' },
			{ v: 'cancelled', l: 'Annullato' }
		];
		var payStatusOptions = [
			{ v: 'pending', l: 'In attesa' },
			{ v: 'paid', l: 'Pagato' },
			{ v: 'refunded', l: 'Rimborsato' },
			{ v: 'invoice_requested', l: 'Fattura richiesta' },
			{ v: 'invoice_sent', l: 'Fattura inviata' },
			{ v: 'invoice_error', l: 'Errore invio fattura' },
			{ v: 'cancelled', l: 'Annullato' },
			{ v: 'free', l: 'Gratuito' }
		];
		var currentRegStatus = String(reg.status || 'registered');
		var currentPayStatus = String(reg.payment_status || 'pending');
		var regStatusOpts = regStatusOptions.map(function (o) {
			return '<option value="' + esc(o.v) + '"' + (o.v === currentRegStatus ? ' selected' : '') + '>' + esc(o.l) + '</option>';
		}).join('');
		var payStatusOpts = payStatusOptions.map(function (o) {
			return '<option value="' + esc(o.v) + '"' + (o.v === currentPayStatus ? ' selected' : '') + '>' + esc(o.l) + '</option>';
		}).join('');
		// Includi anche il valore corrente se non standard, per non perderlo.
		if (currentPayStatus && !payStatusOptions.some(function (o) { return o.v === currentPayStatus; })) {
			payStatusOpts = '<option value="' + esc(currentPayStatus) + '" selected>' + esc(currentPayStatus) + '</option>' + payStatusOpts;
		}
		if (currentRegStatus && !regStatusOptions.some(function (o) { return o.v === currentRegStatus; })) {
			regStatusOpts = '<option value="' + esc(currentRegStatus) + '" selected>' + esc(currentRegStatus) + '</option>' + regStatusOpts;
		}
		var baseHtml = '' +
			'<div class="sd-form-row sd-form-row-2col">' +
				'<label><span>Nome</span><input type="text" name="first_name" class="sd-input" value="' + esc(reg.first_name || '') + '"></label>' +
				'<label><span>Cognome</span><input type="text" name="last_name" class="sd-input" value="' + esc(reg.last_name || '') + '"></label>' +
			'</div>' +
			'<div class="sd-form-row sd-form-row-2col">' +
				'<label><span>Email</span><input type="email" name="email" class="sd-input" value="' + esc(reg.email || '') + '"></label>' +
				'<label><span>Tariffa</span><select name="price_id" class="sd-select">' + priceOptions + '</select></label>' +
			'</div>' +
			'<div class="sd-form-row sd-form-row-2col">' +
				'<label><span>Stato iscrizione</span><select name="status" class="sd-select">' + regStatusOpts + '</select></label>' +
				'<label><span>Stato pagamento</span><select name="payment_status" class="sd-select">' + payStatusOpts + '</select></label>' +
			'</div>';
		$('.sd-reg-edit-modal .sd-reg-edit-base').html(baseHtml);

		// --- Dynamic registration_data fields ---
		var fieldsByName = {};
		formFields.forEach(function (f) {
			var key = String(f.field_name || f.field_key || '').trim();
			if (key) { fieldsByName[key] = f; }
		});

		// Union of keys: form_fields + registration_data
		var allKeys = [];
		var seen = {};
		formFields.forEach(function (f) {
			var key = String(f.field_name || f.field_key || '').trim();
			if (key && !seen[key]) { seen[key] = true; allKeys.push(key); }
		});
		Object.keys(regData).forEach(function (k) {
			if (!seen[k]) { seen[k] = true; allKeys.push(k); }
		});

		var dynHtml = '';
		if (!allKeys.length) {
			dynHtml = '<p class="sd-field-note">Nessun campo dinamico.</p>';
		} else {
			// Tipi di campo solo-informativi (non input): non hanno senso nella modifica
			var INFO_TYPES = { content: 1, paragraph: 1, html: 1, heading: 1, info: 1, separator: 1, divider: 1 };
			allKeys.forEach(function (key) {
				var def = fieldsByName[key] || null;
				var type = def ? String(def.field_type || 'text') : 'text';
				// Salta campi puramente informativi
				if (INFO_TYPES[type]) { return; }
				var label = regFieldLabel(key, def);
				// Forza tipo specifico per chiavi note senza definizione
				if (!def) {
					if (key === 'birth_date') { type = 'date'; }
					else if (key === 'is_minor') { type = 'minor_yesno'; }
				}
				var value = regData.hasOwnProperty(key) ? regData[key] : '';
				var readonly = !!REG_FIELD_READONLY[key];
				dynHtml += renderRegistrationEditField(key, label, type, value, def, readonly);
			});
		}
		$('.sd-reg-edit-modal .sd-reg-edit-dynamic').html(dynHtml);

		$('.sd-reg-edit-loading').hide();
		$('#sd-reg-edit-form').show();
		$('#sd-reg-edit-save').prop('disabled', false).on('click', function () {
			submitRegistrationEdit(reg.id);
		});
	}

	function renderRegistrationEditField(key, label, type, value, def, readonly) {
		var name = 'rd[' + key + ']';
		var safeLabel = esc(label);
		var inputHtml = '';
		var ro = readonly ? ' readonly disabled' : '';
		var roClass = readonly ? ' sd-input-readonly' : '';
		if (readonly) {
			var displayVal = regFieldDisplayValue(key, value);
			inputHtml = '<input type="text" class="sd-input' + roClass + '" value="' + esc(displayVal) + '" readonly disabled>';
			// Manteniamo il valore originale in un hidden per non perderlo nel salvataggio
			var hiddenVal = Array.isArray(value) ? value.join(',') : String(value == null ? '' : value);
			inputHtml += '<input type="hidden" name="' + esc(name) + '" value="' + esc(hiddenVal) + '">';
			return '<label class="sd-form-row-stacked"><span>' + safeLabel + '</span>' + inputHtml + '</label>';
		}
		if (type === 'minor_yesno') {
			var v = String(value);
			var isYes = (v === '1' || v === 'true' || v === 'yes');
			inputHtml = '<select name="' + esc(name) + '" class="sd-select">' +
				'<option value="0"' + (!isYes ? ' selected' : '') + '>No</option>' +
				'<option value="1"' + (isYes ? ' selected' : '') + '>Si</option>' +
				'</select>';
		} else if (type === 'textarea' || type === 'content') {
			inputHtml = '<textarea name="' + esc(name) + '" class="sd-input" rows="3"' + ro + '>' + esc(String(value == null ? '' : value)) + '</textarea>';
		} else if ((type === 'select' || type === 'radio') && def && Array.isArray(def.options)) {
			inputHtml = '<select name="' + esc(name) + '" class="sd-select"' + ro + '>';
			inputHtml += '<option value="">--</option>';
			def.options.forEach(function (opt) {
				var ov = String(opt.value || '');
				var sel = (String(value) === ov) ? ' selected' : '';
				inputHtml += '<option value="' + esc(ov) + '"' + sel + '>' + esc(opt.label || ov) + '</option>';
			});
			inputHtml += '</select>';
		} else if (type === 'checkbox' && def && Array.isArray(def.options)) {
			var arrVals = Array.isArray(value) ? value.map(String) : (value ? String(value).split(',').map(function (s) { return s.trim(); }) : []);
			inputHtml = '<div class="sd-reg-edit-checks">';
			def.options.forEach(function (opt) {
				var ov = String(opt.value || '');
				var chk = (arrVals.indexOf(ov) !== -1) ? ' checked' : '';
				inputHtml += '<label class="sd-reg-edit-check"><input type="checkbox" name="' + esc(name) + '[]" value="' + esc(ov) + '"' + chk + '> ' + esc(opt.label || ov) + '</label>';
			});
			inputHtml += '</div>';
		} else if (type === 'date') {
			inputHtml = '<input type="date" name="' + esc(name) + '" class="sd-input" value="' + esc(String(value == null ? '' : value)) + '"' + ro + '>';
		} else if (type === 'number') {
			inputHtml = '<input type="number" name="' + esc(name) + '" class="sd-input" value="' + esc(String(value == null ? '' : value)) + '"' + ro + '>';
		} else if (type === 'email') {
			inputHtml = '<input type="email" name="' + esc(name) + '" class="sd-input" value="' + esc(String(value == null ? '' : value)) + '"' + ro + '>';
		} else {
			var displayValTxt = Array.isArray(value) ? value.join(', ') : String(value == null ? '' : value);
			inputHtml = '<input type="text" name="' + esc(name) + '" class="sd-input" value="' + esc(displayValTxt) + '"' + ro + '>';
		}
		return '<label class="sd-form-row-stacked"><span>' + safeLabel + '</span>' + inputHtml + '</label>';
	}

	function submitRegistrationEdit(registrationId) {
		var $form = $('#sd-reg-edit-form');
		if (!$form.length) { return; }

		var payload = {
			action: 'sd_activity_registration_update',
			nonce: sdActivityAdmin.nonce,
			registration_id: parseInt(registrationId, 10),
			first_name: $form.find('input[name="first_name"]').val() || '',
			last_name: $form.find('input[name="last_name"]').val() || '',
			email: $form.find('input[name="email"]').val() || '',
			price_id: parseInt($form.find('select[name="price_id"]').val() || 0, 10),
			status: $form.find('select[name="status"]').val() || '',
			payment_status: $form.find('select[name="payment_status"]').val() || '',
		};

		var rd = {};
		// scalar inputs (input/textarea/select non-multiple)
		$form.find('[name^="rd["]').each(function () {
			var $el = $(this);
			var name = String($el.attr('name') || '');
			var m = name.match(/^rd\[([^\]]+)\](\[\])?$/);
			if (!m) { return; }
			var key = m[1];
			var isMulti = !!m[2];
			if (isMulti) {
				if ($el.is(':checkbox')) {
					if (!Array.isArray(rd[key])) { rd[key] = []; }
					if ($el.is(':checked')) { rd[key].push(String($el.val())); }
				}
			} else {
				rd[key] = $el.val();
			}
		});
		payload.registration_data = JSON.stringify(rd);

		var $save = $('#sd-reg-edit-save');
		$save.prop('disabled', true).text('Salvataggio\u2026');
		$.post(sdActivityAdmin.ajaxUrl, payload, function (resp) {
			if (!resp || !resp.success) {
				$save.prop('disabled', false).text('Salva');
				showMessage('error', (resp && resp.data && resp.data.message) ? resp.data.message : sdActivityAdmin.strings.error);
				return;
			}
			showMessage('success', (resp && resp.data && resp.data.message) ? resp.data.message : 'Registrazione aggiornata.');
			closeRegistrationEditModal();
			if (typeof loadRegistrations === 'function') { loadRegistrations(); }
			if (typeof loadRegDashboard === 'function') { loadRegDashboard(); }
		}).fail(function () {
			$save.prop('disabled', false).text('Salva');
			showMessage('error', sdActivityAdmin.strings.error);
		});
	}

	function resendInvoiceEmail(registrationId) {
		if (!window.confirm(__('Reinviare l\'email di richiesta fattura a questo partecipante?', 'sd-logbook'))) {
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

	function sendPaymentConfirmationEmail(registrationId, onSuccess, skipConfirm) {
		if (!skipConfirm && !window.confirm(__('Inviare la conferma pagamento con PDF allegato a questa iscrizione?', 'sd-logbook'))) {
			return false;
		}

		$.post(sdActivityAdmin.ajaxUrl, {
			action: 'sd_activity_send_payment_confirmation_email',
			nonce: sdActivityAdmin.nonce,
			registration_id: registrationId,
		}, function (resp) {
			if (!resp || !resp.success) {
				var errorMessage = (resp && resp.data && resp.data.message) ? resp.data.message : (sdActivityAdmin.strings.regPaymentConfirmationError || sdActivityAdmin.strings.error);
				showMessage('error', errorMessage);
				if (typeof onSuccess === 'function') {
					onSuccess(false, errorMessage);
				}
				return;
			}

			var successMessage = (resp && resp.data && resp.data.message) ? resp.data.message : (sdActivityAdmin.strings.regPaymentConfirmationSent || 'Conferma pagamento inviata.');
			showMessage('success', successMessage);
			if (typeof onSuccess === 'function') {
				onSuccess(true, successMessage);
			}
			loadRegistrations();
			if (typeof loadRegDashboard === 'function') {
				loadRegDashboard();
			}
		});

		return true;
	}

	function updatePaymentsStats() {
		var source = (regDashboardState && Array.isArray(regDashboardState.rows) && regDashboardState.rows.length)
			? regDashboardState.rows
			: (state.registrations || []);
		var total = source.length;
		var paid = 0;
		var pending = 0;

		source.forEach(function (r) {
			var status = String(r.payment_status || '');
			if (status === 'paid' || status === 'free') {
				paid++;
			} else if (status === 'pending' || status === 'invoice_requested' || status === 'invoice_sent' || status === 'invoice_error') {
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
		state.descriptionHardRecoveryKey = '';
		state.descriptionModeFlipKey = '';
		state.descriptionModeFlipDone = false;
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
		resetOptionEditing();
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
		// Aggiorna direttamente classi attive: il tab "modifica" non ha piu' un pulsante visibile,
		// quindi non possiamo affidarci a .trigger('click') sul pulsante.
		$('.sd-admin-tab').removeClass('is-active');
		$('.sd-admin-tab[data-tab="' + name + '"]').addClass('is-active');
		$('.sd-admin-panel').removeClass('is-active');
		$('.sd-admin-panel[data-panel="' + name + '"]').addClass('is-active');
		if (name === 'pagamenti' && typeof updatePaymentsStats === 'function') {
			updatePaymentsStats();
		}
		if (name === 'modifica') {
			// Inizializza/refresha l'editor Descrizione UNA SOLA VOLTA per apertura attivita'.
			// Re-init o setContent/getContent successivi triggerano TADV -> reload iframe
			// (violations "unload", contenuto svuotato, editor non editabile).
			var alreadyMounted = false;
			try {
				if (window.sdActivityDescriptionInited
					&& window.tinymce
					&& typeof window.tinymce.get === 'function') {
					var existingEd = window.tinymce.get('sd-activity-description');
					if (existingEd && isActivityDescriptionEditorHealthy(existingEd)) {
						alreadyMounted = true;
					}
				}
			} catch (eMounted) {}

			if (!alreadyMounted) {
				window.setTimeout(function () {
					initActivityDescriptionEditor();
				}, 0);
				if (!options.skipDescriptionRefresh) {
					scheduleActivityDescriptionRefresh('', 80, false);
				}
			}
		}
	}

	function showMessage(type, message, scrollTarget) {
		var $msg = $('#sd-global-message');
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
		var maxTries = 12;

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
					var $iframeProbe = $('#wp-sd-activity-description-wrap .wp-editor-container iframe');
					var iframeEl = $iframeProbe.get(0) || null;
					var iframeRect = iframeEl && typeof iframeEl.getBoundingClientRect === 'function'
						? iframeEl.getBoundingClientRect()
						: null;
					var iframeDoc = null;
					var iframeBodyDisplay = '';
					var iframeBodyVisibility = '';
					var iframeBodyColor = '';
					var iframeBodyFontSize = '';
					var iframeBodyOffsetH = -1;
					var iframeHeadLinks = -1;
					var iframeReadyState = '';
					var bodyChildCount = -1;
					var firstChildTag = '';
					var firstChildDisp = '';
					var firstChildVis = '';
					var firstChildFont = '';
					var firstChildColor = '';
					var firstChildOffH = -1;
					var firstChildOffW = -1;
					var firstChildText = '';
					try {
						iframeDoc = iframeEl ? (iframeEl.contentDocument || (iframeEl.contentWindow && iframeEl.contentWindow.document)) : null;
						if (iframeDoc) {
							iframeReadyState = String(iframeDoc.readyState || '');
							var headLinks = iframeDoc.head ? iframeDoc.head.querySelectorAll('link[rel="stylesheet"], style') : null;
							iframeHeadLinks = headLinks ? headLinks.length : 0;
							var ifBody = iframeDoc.body;
							if (ifBody) {
								iframeBodyOffsetH = ifBody.offsetHeight || 0;
								bodyChildCount = ifBody.children ? ifBody.children.length : 0;
								var win = iframeEl.contentWindow;
								if (win && typeof win.getComputedStyle === 'function') {
									var cs = win.getComputedStyle(ifBody);
									iframeBodyDisplay = String(cs.display || '');
									iframeBodyVisibility = String(cs.visibility || '');
									iframeBodyColor = String(cs.color || '');
									iframeBodyFontSize = String(cs.fontSize || '');
								}
								var firstEl = ifBody.firstElementChild;
								if (firstEl) {
									firstChildTag = String(firstEl.tagName || '');
									firstChildOffH = firstEl.offsetHeight || 0;
									firstChildOffW = firstEl.offsetWidth || 0;
									firstChildText = String((firstEl.textContent || '').trim()).slice(0, 40);
									if (win && typeof win.getComputedStyle === 'function') {
										var cs2 = win.getComputedStyle(firstEl);
										firstChildDisp = String(cs2.display || '');
										firstChildVis = String(cs2.visibility || '');
										firstChildFont = String(cs2.fontSize || '');
										firstChildColor = String(cs2.color || '');
									}
								}
							}
						}
					} catch (probeErr) {
						iframeBodyDisplay = '[probe-error]';
					}
					debugDescriptionLog('apply:already-matched', {
						tryIndex: tries,
						targetLen: normalized.length,
						currentLen: currentBefore.length,
						bodyLen: bodyBefore.length,
						contentMatches: contentMatches,
						bodyMatches: bodyMatches,
						iframeCount: $iframeProbe.length,
						iframeWidth: iframeRect ? Math.round(iframeRect.width) : -1,
						iframeHeight: iframeRect ? Math.round(iframeRect.height) : -1,
						iframeVisible: iframeEl ? !!(iframeEl.offsetWidth || iframeEl.offsetHeight) : false,
						ifReady: iframeReadyState,
						ifHeadCss: iframeHeadLinks,
						ifBodyDisp: iframeBodyDisplay,
						ifBodyVis: iframeBodyVisibility,
						ifBodyColor: iframeBodyColor,
						ifBodyFont: iframeBodyFontSize,
						ifBodyOffH: iframeBodyOffsetH,
						bodySnippet: body ? String(body.innerHTML || '').slice(0, 60) : '',
						childCount: bodyChildCount,
						ch1Tag: firstChildTag,
						ch1Disp: firstChildDisp,
						ch1Vis: firstChildVis,
						ch1Font: firstChildFont,
						ch1Color: firstChildColor,
						ch1Size: firstChildOffW + 'x' + firstChildOffH,
						ch1Text: firstChildText,
					});
					if (typeof editor.setMode === 'function') {
						editor.setMode('design');
					}
					if (typeof editor.show === 'function') {
						editor.show();
					}
					forceActivityDescriptionTmceUiState();
					try {
						editor.setContent(normalized || '');
						editor.save();
					} catch (reapplyErr) {
						// Ignore - editor may still be initializing.
					}
					if (typeof editor.execCommand === 'function') {
						try { editor.execCommand('mceRepaint'); } catch (repaintErr) {}
					}
					body = (typeof editor.getBody === 'function') ? editor.getBody() : null;
					if (body) {
						body.setAttribute('contenteditable', 'true');
						body.style.pointerEvents = 'auto';
						body.style.userSelect = 'text';
						body.style.cursor = 'text';
					}
					return;
				}

				// Guard: non chiamare setContent se l'iframe non ha ancora il body (causa setBaseAndExtent crash).
				if (!isActivityDescriptionEditorHealthy(editor)) {
					if (tries < maxTries) {
						window.setTimeout(syncVisual, 80);
					}
					return;
				}
				try {
					editor.setContent(normalized || '');
					editor.save();
				} catch (setContentErr) {
					// iframe doc null: rimanda.
					if (tries < maxTries) { window.setTimeout(syncVisual, 80); }
					return;
				}
				if (typeof editor.setMode === 'function') {
					editor.setMode('design');
				}
				if (typeof editor.show === 'function') {
					editor.show();
				}
				if (typeof editor.execCommand === 'function') {
					editor.execCommand('mceRepaint');
				}
				forceActivityDescriptionTmceUiState();

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
				// Do not retry on content mismatch here: TinyMCE may normalize HTML differently.
				// Additional refresh paths (schedule/init/mode-flip) handle visual stabilization.
			} catch (err3) {
				debugDescriptionLog('apply:sync-error', {
					tryIndex: tries,
					error: String((err3 && err3.message) ? err3.message : err3),
				});
				if (tries < 3) {
					window.setTimeout(syncVisual, 80);
				}
			}
		}

		window.setTimeout(syncVisual, 40);
	}

	// Stable native lifecycle override for Description editor.
	// L'editor TinyMCE viene inizializzato UNA SOLA volta (vedi flag sdActivityDescriptionInited)
	// con le stesse impostazioni dell'editor Template Email (Corpo/Firma). Le chiamate successive
	// si limitano a setContent, evitando re-init multipli.
	function initActivityDescriptionEditor() {
		var $textarea = $('#sd-activity-description');
		if (!$textarea.length) {
			return;
		}

		var pending = state.descriptionPendingValue !== null
			? String(state.descriptionPendingValue)
			: String($textarea.val() || '');
		var normalized = normalizeActivityDescriptionHtml(pending);

		if (!window.sdActivityDescriptionInited) {
			// Pre-popola la textarea PRIMA di initialize: TinyMCE legge il contenuto al primo init.
			// Evita di chiamare setContent() in seguito (TADV reagisce a setContent ricaricando l'iframe
			// e si verificano violations "unload" + crash setBaseAndExtent).
			$textarea.val(normalized);
			state.descriptionPendingValue = normalized;
			state.descriptionLastKnownHtml = normalized;

			if (window.wp && window.wp.editor && typeof window.wp.editor.initialize === 'function') {
				// Pulisci eventuale istanza TinyMCE stale dal registro globale.
				try {
					if (window.tinymce && typeof window.tinymce.get === 'function') {
						var stale = window.tinymce.get('sd-activity-description');
						if (stale && typeof stale.remove === 'function') {
							stale.remove();
						}
					}
					if (window.wp.editor && typeof window.wp.editor.remove === 'function') {
						try { window.wp.editor.remove('sd-activity-description'); } catch (eRm) {}
					}
				} catch (eClean) {}
				try {
					var tinyConfig = buildEmailTemplateTinyMceConfig(320);
					tinyConfig.setup = function (editor) {
						editor.on('change keyup input undo redo SetContent', function () {
							try { editor.save(); } catch (e2) {}
						});
					};
					window.wp.editor.initialize('sd-activity-description', {
						mediaButtons: true,
						quicktags: true,
						tinymce: tinyConfig,
					});
					window.sdActivityDescriptionInited = true;
				} catch (initErr) {
					window.sdActivityDescriptionInited = false;
				}
			} else {
				window.setTimeout(initActivityDescriptionEditor, 120);
			}
			return;
		}

		// Editor gia' inizializzato: NON chiamare ne' setContent ne' getContent
		// (entrambi triggerano TADV -> reload iframe -> contenuto sparisce).
		// Aggiornare il contenuto su un editor gia' montato per una nuova attivita'
		// va fatto altrove (es. via remove + re-init pulito), non qui.
		return;

		// Aggiorna il contenuto sull'istanza esistente (se gia' inizializzata e iframe pronto).
		if (window.tinymce && typeof window.tinymce.get === 'function') {
			var existing = window.tinymce.get('sd-activity-description');
			if (existing && typeof existing.setContent === 'function' && isActivityDescriptionEditorHealthy(existing)) {
				try {
					existing.setContent(normalized || '');
					if (typeof existing.save === 'function') {
						existing.save();
					}
				} catch (errSet) {
					// fallback: la textarea conserva il valore.
				}
			}
		}
		$textarea.val(normalized);
		state.descriptionLastKnownHtml = normalized;
		return;

		// eslint-disable-next-line no-unreachable
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

	function forceActivityDescriptionTmceUiState() {
		var $wrap = $('#wp-sd-activity-description-wrap');
		if (!$wrap.length) {
			return;
		}

		$wrap.removeClass('html-active').addClass('tmce-active');
		$wrap.find('.wp-editor-area').hide();
		$wrap.find('.quicktags-toolbar').hide();
		$wrap.find('.mce-tinymce').css({ display: '', visibility: 'visible' }).show();
		$wrap.find('.mce-edit-area').css({ display: '', visibility: 'visible' }).show();
		$wrap.find('.wp-editor-container iframe').css({
			display: 'block',
			visibility: 'visible',
			opacity: '1'
		});

		$('#sd-activity-description-html').addClass('wp-switch-editor switch-html');
		$('#sd-activity-description-tmce').addClass('wp-switch-editor switch-tmce');
	}

	function maybeForceActivityDescriptionHardRecovery(reason) {
		debugDescriptionLog('hard-recovery:disabled', { reason: reason });
		return false;

		var source = String($('#sd-activity-description').val() || state.descriptionPendingValue || state.descriptionLastKnownHtml || '');
		var normalizedSource = normalizeActivityDescriptionHtml(source);
		if (!normalizedSource) {
			debugDescriptionLog('hard-recovery:skip-empty-source', {
				reason: reason,
			});
			return false;
		}

		var editor = getActivityDescriptionEditor();
		var healthy = isActivityDescriptionEditorHealthy(editor);
		var body = healthy && typeof editor.getBody === 'function' ? editor.getBody() : null;
		var bodyHtml = body ? normalizeActivityDescriptionHtml(body.innerHTML || '') : '';

		var activityId = parseInt(state.selectedActivityId, 10) || 0;
		var recoveryMode = healthy ? 'empty-body' : 'missing-editor';
		if (healthy && bodyHtml && bodyHtml.length > 0) {
			debugDescriptionLog('hard-recovery:skip-body-not-empty', {
				reason: reason,
				bodyLen: bodyHtml.length,
			});
			return false;
		}

		var guardKey = String(activityId) + ':' + String(normalizedSource.length) + ':' + recoveryMode;
		if (state.descriptionHardRecoveryKey === guardKey) {
			debugDescriptionLog('hard-recovery:guard-hit', {
				reason: reason,
				guardKey: guardKey,
				recoveryMode: recoveryMode,
			});
			return false;
		}

		state.descriptionHardRecoveryKey = guardKey;
		debugDescriptionLog('hard-recovery:start', {
			reason: reason,
			guardKey: guardKey,
			sourceLen: normalizedSource.length,
			recoveryMode: recoveryMode,
		});

		if (editor) {
			destroyActivityDescriptionEditor();
		}
		window.setTimeout(function () {
			initActivityDescriptionEditor();
			window.setTimeout(function () {
				applyActivityDescriptionContentToNativeEditor(normalizedSource, true);
				ensureActivityDescriptionEditable();
				debugDescriptionLog('hard-recovery:applied', {
					reason: reason,
					guardKey: guardKey,
				});
			}, 140);
		}, 80);

		return true;
	}

	function maybeForceActivityDescriptionModeFlip(reason) {
		debugDescriptionLog('mode-flip:disabled', { reason: reason });
		return false;

		var source = String($('#sd-activity-description').val() || state.descriptionPendingValue || state.descriptionLastKnownHtml || '');
		var normalizedSource = normalizeActivityDescriptionHtml(source);
		if (!normalizedSource) {
			debugDescriptionLog('mode-flip:skip-empty-source', {
				reason: reason,
			});
			return false;
		}

		var activityId = parseInt(state.selectedActivityId, 10) || 0;
		var guardKey = String(activityId);
		if (state.descriptionModeFlipDone || state.descriptionModeFlipKey === guardKey) {
			debugDescriptionLog('mode-flip:guard-hit', {
				reason: reason,
				guardKey: guardKey,
				alreadyDone: !!state.descriptionModeFlipDone,
			});
			return false;
		}

		if (!window.switchEditors || typeof window.switchEditors.go !== 'function') {
			debugDescriptionLog('mode-flip:skip-no-switch-editors', {
				reason: reason,
				guardKey: guardKey,
			});
			return false;
		}

		state.descriptionModeFlipKey = guardKey;
		state.descriptionModeFlipDone = true;
		debugDescriptionLog('mode-flip:start', {
			reason: reason,
			guardKey: guardKey,
			sourceLen: normalizedSource.length,
		});

		try {
			window.switchEditors.go('sd-activity-description', 'html');
		} catch (errHtml) {
			// Continue to tmce switch attempt.
		}

		window.setTimeout(function () {
			try {
				window.switchEditors.go('sd-activity-description', 'tmce');
			} catch (errTmce) {
				// Continue with content/apply fallback.
			}

			window.setTimeout(function () {
				var editor = getActivityDescriptionEditor();
				if (editor) {
					try {
						if (typeof editor.load === 'function') {
							editor.load();
						}
						if (typeof editor.setMode === 'function') {
							editor.setMode('design');
						}
						if (typeof editor.show === 'function') {
							editor.show();
						}
						if (typeof editor.execCommand === 'function') {
							editor.execCommand('mceRepaint');
						}
					} catch (editorModeFlipErr) {
						// Keep editable fallback below.
					}
				}
				ensureActivityDescriptionEditable();
				debugDescriptionLog('mode-flip:applied', {
					reason: reason,
					guardKey: guardKey,
				});
			}, 120);
		}, 90);

		return true;
	}

	function refreshActivityDescriptionVisualEditor() {
		debugDescriptionLog('refresh:visual-only', {
			sourceLen: String($('#sd-activity-description').val() || state.descriptionPendingValue || state.descriptionLastKnownHtml || '').length,
		});
		ensureActivityDescriptionVisualTabActive();
		forceActivityDescriptionTmceUiState();
		ensureActivityDescriptionEditable();
		window.setTimeout(function () {
			forceActivityDescriptionTmceUiState();
			var editor = getActivityDescriptionEditor();
			if (editor && typeof editor.execCommand === 'function') {
				try {
					editor.execCommand('mceRepaint');
				} catch (err) {
					// Ignore repaint errors.
				}
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
			var normalizedSource = normalizeActivityDescriptionHtml(source);
			debugDescriptionLog('schedule:run', {
				delayMs: delay,
				enforceContent: !!enforceContent,
				sourceLen: source.length,
			});

			// Pre-popola la textarea con il contenuto target.
			var $ta = $('#sd-activity-description');
			if ($ta.length) {
				$ta.val(normalizedSource).prop('readonly', false).prop('disabled', false);
			}
			state.descriptionPendingValue = normalizedSource;
			state.descriptionLastKnownHtml = normalizedSource;

			// Se l'editor TinyMCE e' gia' montato con un contenuto diverso (es. cambio attivita'),
			// rimuovilo completamente e re-inizializza: TinyMCE leggera' la textarea preimpostata.
			// NON usare setContent: TADV reagisce ricaricando l'iframe -> contenuto sparisce.
			if (window.sdActivityDescriptionInited
				&& window.tinymce
				&& typeof window.tinymce.get === 'function') {
				var existingForRefresh = window.tinymce.get('sd-activity-description');
				if (existingForRefresh) {
					try {
						if (typeof existingForRefresh.remove === 'function') {
							existingForRefresh.remove();
						}
					} catch (eRem) {}
					try {
						if (window.wp && window.wp.editor && typeof window.wp.editor.remove === 'function') {
							window.wp.editor.remove('sd-activity-description');
						}
					} catch (eRem2) {}
					window.sdActivityDescriptionInited = false;
					// Ripristina la textarea (puo' essere stata svuotata dal remove).
					if ($ta.length) {
						$ta.val(normalizedSource);
					}
				}
			}

			initActivityDescriptionEditor();
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

	// =========================================================================
	// CRUSCOTTO REGISTRAZIONI (parallelo a Cruscotto Rinnovi Soci)
	// =========================================================================
	var regDashboardState = {
		rows: [],
		quickFilter: 'all',
		searchTerm: '',
		activityId: 0
	};

	function regStrings() {
		return (window.sdActivityAdmin && window.sdActivityAdmin.strings) || {};
	}

	$(document).ready(function () {
		if (!$('#sd-reg-dashboard').length) {
			return;
		}
		bindRegDashboardEvents();
		bindRegEmailPreviewModal();
		// Carica una volta all'avvio se c'è già un'attività selezionata
		setTimeout(loadRegDashboard, 300);
	});

	function bindRegDashboardEvents() {
		$('#sd-reg-activity-id').on('change.regDashboard', function () {
			loadRegDashboard();
		});

		$(document).on('click', '.sd-reg-filter', function () {
			var $btn = $(this);
			var mode = String($btn.data('reg-filter') || 'all');
			regDashboardState.quickFilter = mode;
			$('.sd-reg-filter').removeClass('is-active');
			$btn.addClass('is-active');
			renderRegDashboardRows(getFilteredRegRows());
			updateRegBulkButton();
		});

		$(document).on('click', '.sd-send-reg-email', function () {
			var $btn = $(this);
			var regId = parseInt($btn.data('reg-id'), 10) || 0;
			if (!regId) { return; }
			sendRegEmailSingle($btn, regId);
		});

		$(document).on('click', '.sd-reg-send-payment-confirmation', function () {
			var $btn = $(this);
			var regId = parseInt($btn.data('id') || $btn.data('reg-id'), 10) || 0;
			if (!regId || $btn.prop('disabled')) { return; }
			if (!window.confirm(__('Inviare la conferma pagamento con PDF allegato a questa iscrizione?', 'sd-logbook'))) { return; }

			var originalLabel = $btn.text();
			$btn.prop('disabled', true).text(regStrings().regPaymentConfirmationSending || 'Invio conferma...');
			sendPaymentConfirmationEmail(regId, function (success, message) {
				if (typeof showRegDashboardMessage === 'function') {
					showRegDashboardMessage(success ? 'success' : 'error', message || (success ? (regStrings().regPaymentConfirmationSent || 'Conferma pagamento inviata.') : (regStrings().regPaymentConfirmationError || 'Invio conferma pagamento non riuscito.')));
				}
				$btn.prop('disabled', false).text(originalLabel);
			}, true);
		});

		$('#sd-reg-bulk-email').on('click', sendRegEmailsBulk);
		$('#sd-reg-email-all-paid').on('click', sendRegEmailsAllPaid);

		// Esporta Excel iscrizioni filtrate
		$('#sd-reg-export-excel').on('click', function () {
			var $btn = $(this);
			var activityId = parseInt($('#sd-reg-activity-id').val(), 10) || 0;
			var paymentStatus = $('#sd-reg-payment-filter').val() || '';
			var search = $('#sd-reg-search').val() || '';
			var filterType = $('.sd-reg-filter.is-active').data('reg-filter') || 'all';
			if (!activityId) {
				alert('Seleziona un\'attività.');
				return;
			}
			$btn.prop('disabled', true).text('Esportazione...');
			$.ajax({
				url: sdActivityAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'sd_activity_export_registrations_excel',
					nonce: sdActivityAdmin.nonce,
					activity_id: activityId,
					payment_status: paymentStatus,
					search: search,
					filter_type: filterType
				},
				xhrFields: {
					responseType: 'blob'
				},
				success: function (data, status, xhr) {
					var filename = '';
					var disposition = xhr.getResponseHeader('Content-Disposition');
					if (disposition && disposition.indexOf('attachment') !== -1) {
						var matches = /filename="?([^";]+)"?/.exec(disposition);
						if (matches != null && matches[1]) filename = matches[1];
					}
					var blob = new Blob([data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
					var link = document.createElement('a');
					link.href = window.URL.createObjectURL(blob);
					link.download = filename || 'iscrizioni.xlsx';
					document.body.appendChild(link);
					link.click();
					document.body.removeChild(link);
					$btn.prop('disabled', false).text('Esporta Excel');
				},
				error: function (xhr) {
					alert('Errore durante l\'esportazione.');
					$btn.prop('disabled', false).text('Esporta Excel');
				}
			});
		});

		// ── PDF Attività ────────────────────────────────────────────────────
		$('#sd-reg-pdf-activity').on('click', function () {
			var $btn = $(this);
			var activityId = parseInt($('#sd-reg-activity-id').val(), 10) || 0;
			if (!activityId) { alert('Seleziona un\'attività.'); return; }
			$btn.prop('disabled', true).text('Generazione...');
			sdDownloadPdfBlob({
				action: 'sd_activity_pdf_activity',
				nonce: sdActivityAdmin.nonce,
				activity_id: activityId
			}, 'attivita_' + activityId + '.pdf', function () {
				$btn.prop('disabled', false).html('📄 PDF Attività');
			});
		});

		// ── PDF Lista registrazioni ─────────────────────────────────────────
		$('#sd-reg-pdf-list').on('click', function () {
			var $btn = $(this);
			var activityId    = parseInt($('#sd-reg-activity-id').val(), 10) || 0;
			var paymentStatus = $('#sd-reg-payment-filter').val() || '';
			var search        = $('#sd-reg-search').val() || '';
			if (!activityId) { alert('Seleziona un\'attività.'); return; }
			$btn.prop('disabled', true).text('Generazione...');
			sdDownloadPdfBlob({
				action: 'sd_activity_pdf_registrations',
				nonce: sdActivityAdmin.nonce,
				activity_id: activityId,
				payment_status: paymentStatus,
				search: search
			}, 'registrazioni_' + activityId + '.pdf', function () {
				$btn.prop('disabled', false).html('📋 PDF Lista');
			});
		});

		// ── PDF Template (tutti) ────────────────────────────────────────────
		$('#sd-reg-tpl-pdf-all').on('click', function () {
			var $btn       = $(this);
			var activityId = parseInt($('#sd-reg-activity-id').val(), 10) || 0;
			var templateId = parseInt($('#sd-reg-tpl-select').val(), 10) || 0;
			if (!activityId) { alert('Seleziona un\'attività.'); return; }
			if (!templateId) { alert('Seleziona un template PDF.'); return; }
			$btn.prop('disabled', true).text('Generazione...');
			sdDownloadPdfBlob({
				action: 'sd_pdf_tpl_gen_all',
				nonce: sdActivityAdmin.nonce,
				template_id: templateId,
				activity_id: activityId,
				payment_status: $('#sd-reg-payment-filter').val() || ''
			}, 'template_' + templateId + '_attivita_' + activityId + '.pdf', function () {
				$btn.prop('disabled', false).html('📑 PDF Template (tutti)');
			});
		});

		// ── PDF Template singola registrazione ─────────────────────────────
		$(document).on('click', '.sd-reg-tpl-pdf-row', function () {
			var $btn           = $(this);
			var registrationId = parseInt($btn.data('id'), 10) || 0;
			var templateId     = parseInt($('#sd-reg-tpl-select').val(), 10) || 0;
			if (!registrationId) { return; }
			if (!templateId) { alert('Seleziona un template PDF dal menu in alto.'); return; }
			var origHtml = $btn.html();
			$btn.prop('disabled', true).text('...');
			sdDownloadPdfBlob({
				action: 'sd_pdf_tpl_generate',
				nonce: sdActivityAdmin.nonce,
				template_id: templateId,
				registration_id: registrationId,
				activity_id: parseInt($('#sd-reg-activity-id').val(), 10) || 0
			}, 'template_' + templateId + '_reg_' + registrationId + '.pdf', function () {
				$btn.prop('disabled', false).html(origHtml);
			});
		});

		$(document).on('change', '.sd-reg-status-select', function () {
			var $sel = $(this);
			var regId = parseInt($sel.data('reg-id'), 10) || 0;
			var newVal = String($sel.val() || '');
			var prevVal = String($sel.data('prev') || '');
			if (!regId || newVal === prevVal) { return; }
			$sel.prop('disabled', true);
			$.ajax({
				url: sdActivityAdmin.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'sd_activity_registration_update_status',
					nonce: sdActivityAdmin.nonce,
					registration_id: regId,
					status: newVal
				},
				success: function (resp) {
					$sel.prop('disabled', false);
					if (!resp || !resp.success) {
						$sel.val(prevVal);
						showRegDashboardMessage('error', (resp && resp.data && resp.data.message) || 'Errore aggiornamento stato.');
						return;
					}
					$sel.data('prev', newVal);
					(regDashboardState.rows || []).forEach(function (r) {
						if (parseInt(r.id, 10) === regId) { r.status = newVal; }
					});
					(state.registrations || []).forEach(function (r) {
						if (parseInt(r.id, 10) === regId) { r.status = newVal; }
					});
					showRegDashboardMessage('success', (resp.data && resp.data.message) || 'Stato aggiornato.');
					renderRegDashboardRows(getFilteredRegRows());
					updateRegBulkButton();
				},
				error: function () {
					$sel.prop('disabled', false);
					$sel.val(prevVal);
					showRegDashboardMessage('error', 'Errore di rete durante aggiornamento stato.');
				}
			});
		});

		$(document).on('change', '.sd-reg-payment-select', function () {
			var $sel = $(this);
			var regId = parseInt($sel.data('reg-id'), 10) || 0;
			var newVal = String($sel.val() || '');
			var prevVal = String($sel.data('prev') || '');
			if (!regId || newVal === prevVal) { return; }
			var payLabels = {
				'pending': 'In attesa',
				'paid': 'Pagato',
				'refunded': 'Rimborsato',
				'invoice_requested': 'Fattura richiesta',
				'invoice_sent': 'Fattura inviata',
				'invoice_error': 'Errore fattura',
				'cancelled': 'Annullato',
				'free': 'Gratuito'
			};
			var prevLabel = payLabels[prevVal] || prevVal || '—';
			var newLabel = payLabels[newVal] || newVal;
			var confirmMsg = 'Confermi il cambio dello stato pagamento da "' + prevLabel + '" a "' + newLabel + '"?';
			if (newVal === 'paid' || newVal === 'refunded') {
				confirmMsg += '\n\nVerrà inviata automaticamente un\'e-mail di conferma alla persona iscritta, con il PDF allegato.';
			}
			if (!window.confirm(confirmMsg)) {
				$sel.val(prevVal);
				return;
			}
			$sel.prop('disabled', true);
			$.ajax({
				url: sdActivityAdmin.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'sd_activity_registration_update_payment',
					nonce: sdActivityAdmin.nonce,
					registration_id: regId,
					payment_status: newVal
				},
				success: function (resp) {
					$sel.prop('disabled', false);
					if (!resp || !resp.success) {
						$sel.val(prevVal);
						showRegDashboardMessage('error', (resp && resp.data && resp.data.message) || 'Errore aggiornamento stato pagamento.');
						return;
					}
					$sel.data('prev', newVal);
					(regDashboardState.rows || []).forEach(function (r) {
						if (parseInt(r.id, 10) === regId) {
							r.payment_status = newVal;
							if (resp.data && resp.data.last_email_at) {
								r.last_email_at = resp.data.last_email_at;
								r.last_email_subject = resp.data.last_email_subject || '';
							}
						}
					});
					(state.registrations || []).forEach(function (r) {
						if (parseInt(r.id, 10) === regId) {
							r.payment_status = newVal;
							if (resp.data && resp.data.last_email_at) {
								r.last_email_at = resp.data.last_email_at;
								r.last_email_subject = resp.data.last_email_subject || '';
							}
						}
					});
					var baseMsg = (resp.data && resp.data.message) || 'Stato pagamento aggiornato.';
					if (resp.data && resp.data.email_sent) {
						baseMsg += ' (E-mail di conferma inviata)';
					} else if (resp.data && resp.data.email_error) {
						baseMsg += ' (E-mail non inviata: ' + resp.data.email_error + ')';
					}
					showRegDashboardMessage('success', baseMsg);
					renderRegDashboardRows(getFilteredRegRows());
					updateRegBulkButton();
					if (typeof updatePaymentsStats === 'function') { updatePaymentsStats(); }
				},
				error: function () {
					$sel.prop('disabled', false);
					$sel.val(prevVal);
					showRegDashboardMessage('error', 'Errore di rete durante aggiornamento pagamento.');
				}
			});
		});

		var searchTimer = null;
		$(document).on('input', '#sd-reg-search', function () {
			var val = String($(this).val() || '').trim().toLowerCase();
			regDashboardState.searchTerm = val;
			if (searchTimer) { clearTimeout(searchTimer); }
			searchTimer = setTimeout(function () {
				renderRegDashboardRows(getFilteredRegRows());
				updateRegBulkButton();
			}, 150);
		});
	}

	// ===== MODAL ANTEPRIMA EMAIL REGISTRAZIONE =====
	var regPreviewZoom = 100;

	function bindRegEmailPreviewModal() {
		var $modal = $('#sd-email-preview-modal');
		if (!$modal.length) { return; }

		$(document).on('click', '.sd-activity-preview-email', function () {
			var regId      = parseInt($(this).data('reg-id'), 10) || 0;
			var templateId = parseInt($('#sd-reg-template-id').val(), 10) || 0;
			openRegEmailPreviewModal(regId, templateId);
		});

		$modal.on('click', '#sd-email-preview-close, .sd-email-preview-backdrop', function () {
			closeRegEmailPreviewModal();
		});

		$(document).on('keydown.sdRegEmailPreview', function (e) {
			if (e.key === 'Escape' && $modal.is(':visible')) {
				closeRegEmailPreviewModal();
			}
		});

		$modal.on('click', '#sd-email-preview-zoom-in', function () { setRegPreviewZoom(regPreviewZoom + 10); });
		$modal.on('click', '#sd-email-preview-zoom-out', function () { setRegPreviewZoom(regPreviewZoom - 10); });
		$modal.on('click', '#sd-email-preview-zoom-reset', function () { setRegPreviewZoom(100); });

		$modal.on('click', '#sd-email-preview-fullscreen', function () {
			$modal.find('.sd-email-preview-dialog').toggleClass('is-fullscreen');
			var $btn = $(this);
			$btn.html($modal.find('.sd-email-preview-dialog').hasClass('is-fullscreen') ? '&#x2715;' : '&#x26F6;');
		});
	}

	function openRegEmailPreviewModal(regId, templateId) {
		var $modal = $('#sd-email-preview-modal');
		if (!$modal.length) { return; }

		$('#sd-email-preview-to').text('');
		$('#sd-email-preview-subject').text('');
		$('#sd-email-preview-body').html('');
		$('#sd-email-preview-loading').show();
		$('#sd-email-preview-error').hide();
		regPreviewZoom = 100;
		$('#sd-email-preview-body-scaler').css('transform', 'scale(1)');
		$('#sd-email-preview-zoom-label').text('100%');
		$modal.find('.sd-email-preview-dialog').removeClass('is-fullscreen');
		$('#sd-email-preview-fullscreen').html('&#x26F6;');
		$modal.fadeIn(180);
		$('body').addClass('sd-modal-open');

		$.ajax({
			url: sdActivityAdmin.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action:          'sd_activity_preview_email',
				nonce:           sdActivityAdmin.nonce,
				registration_id: regId,
				template_id:     templateId
			},
			success: function (resp) {
				$('#sd-email-preview-loading').hide();
				if (!resp.success || !resp.data) {
					$('#sd-email-preview-error').text((resp.data && resp.data.message) || (regStrings().previewError || 'Impossibile caricare l\'anteprima.')).show();
					return;
				}
				$('#sd-email-preview-to').text(resp.data.to || '');
				$('#sd-email-preview-subject').text(resp.data.subject || '');
				$('#sd-email-preview-body').html(resp.data.body || '');
			},
			error: function () {
				$('#sd-email-preview-loading').hide();
				$('#sd-email-preview-error').text(regStrings().previewError || 'Impossibile caricare l\'anteprima.').show();
			}
		});
	}

	function closeRegEmailPreviewModal() {
		$('#sd-email-preview-modal').fadeOut(150);
		$('body').removeClass('sd-modal-open');
	}

	function setRegPreviewZoom(val) {
		regPreviewZoom = Math.min(200, Math.max(40, val));
		var ratio = regPreviewZoom / 100;
		$('#sd-email-preview-body-scaler').css({
			'transform': 'scale(' + ratio + ')',
			'transform-origin': 'top center'
		});
		$('#sd-email-preview-zoom-label').text(regPreviewZoom + '%');
		var $scaler = $('#sd-email-preview-body-scaler');
		var naturalH = $scaler[0] ? $scaler[0].scrollHeight : 0;
		$('#sd-email-preview-body-wrap').css('min-height', Math.ceil(naturalH * ratio) + 'px');
	}

	function loadRegDashboard() {
		var $tbody = $('#sd-reg-dashboard-tbody');
		var $loading = $('#sd-reg-dashboard-loading');
		if (!$tbody.length) { return; }

		var activityId = parseInt($('#sd-reg-activity-id').val(), 10) || 0;
		regDashboardState.activityId = activityId;

		if (!activityId) {
			$tbody.html('<tr><td colspan="8" class="sd-table-empty">' + esc(regStrings().regSelectActivity || 'Seleziona un\'attività per vedere il cruscotto.') + '</td></tr>');
			regDashboardState.rows = [];
			updateRegBulkButton();
			return;
		}

		$loading.show();
		$('#sd-reg-dashboard-message').hide();
		$tbody.html('<tr><td colspan="8" class="sd-table-empty">' + esc(regStrings().loading || 'Caricamento...') + '</td></tr>');

		$.ajax({
			url: sdActivityAdmin.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activity_registrations_dashboard',
				nonce: sdActivityAdmin.nonce,
				activity_id: activityId
			},
			success: function (resp) {
				$loading.hide();
				if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.rows)) {
					showRegDashboardMessage('error', regStrings().regDashboardLoadError || 'Errore nel caricamento del cruscotto registrazioni.');
					$tbody.html('<tr><td colspan="8" class="sd-table-empty">Errore caricamento.</td></tr>');
					updateRegBulkButton();
					return;
				}
				regDashboardState.rows = resp.data.rows;
				renderRegDashboardRows(getFilteredRegRows());
				updateRegBulkButton();
			},
			error: function () {
				$loading.hide();
				showRegDashboardMessage('error', regStrings().regDashboardLoadError || 'Errore di rete.');
				$tbody.html('<tr><td colspan="8" class="sd-table-empty">Errore di rete.</td></tr>');
				updateRegBulkButton();
			}
		});
	}

	function getFilteredRegRows() {
		var mode = regDashboardState.quickFilter || 'all';
		var rows = Array.isArray(regDashboardState.rows) ? regDashboardState.rows : [];
		if (mode === 'pending') {
			rows = rows.filter(function (r) { return r.payment_status === 'pending'; });
		} else if (mode === 'paid') {
			rows = rows.filter(function (r) { return r.payment_status === 'paid'; });
		} else if (mode === 'invoice_requested') {
			rows = rows.filter(function (r) { return r.payment_status === 'invoice_requested'; });
		} else if (mode === 'valid_email') {
			rows = rows.filter(function (r) { return !!r.can_remind; });
		}
		var term = String(regDashboardState.searchTerm || '').toLowerCase();
		if (term) {
			rows = rows.filter(function (r) {
				var hay = ((r.first_name || '') + ' ' + (r.last_name || '') + ' ' + (r.email || '') + ' ' + (r.name || '')).toLowerCase();
				return hay.indexOf(term) !== -1;
			});
		}
		return rows;
	}

	function renderRegDashboardRows(rows) {
		var $tbody = $('#sd-reg-dashboard-tbody');
		if (!$tbody.length) { return; }
		if (!rows.length) {
			$tbody.html('<tr><td colspan="8" class="sd-table-empty">Nessuna iscrizione trovata per questo filtro.</td></tr>');
			return;
		}

		var payStatusMap = {
			'pending': { cls: 'sd-renewal-status-soon', label: 'In attesa' },
			'paid': { cls: 'sd-renewal-status-valid', label: 'Pagato' },
			'refunded': { cls: 'sd-renewal-status-expired', label: 'Rimborsato' },
			'invoice_requested': { cls: 'sd-renewal-status-soon', label: 'Fattura richiesta' },
			'invoice_sent': { cls: 'sd-renewal-status-soon', label: 'Fattura inviata' },
			'invoice_error': { cls: 'sd-renewal-status-expired', label: 'Errore fattura' },
			'cancelled': { cls: 'sd-renewal-status-expired', label: 'Annullato' },
			'free': { cls: 'sd-renewal-status-valid', label: 'Gratuito' }
		};
		var regStatusMap = {
			'registered': { cls: 'sd-renewal-status-valid', label: 'Iscritto' },
			'waitlist': { cls: 'sd-renewal-status-soon', label: 'Lista d\'attesa' },
			'cancelled': { cls: 'sd-renewal-status-expired', label: 'Annullato' }
		};

		var html = '';
		rows.forEach(function (r) {
			var rawPay = String(r.payment_status || '').trim();
			if (!rawPay || rawPay === '0') { rawPay = 'pending'; }
			var canSendPaymentConfirmation = rawPay === 'paid' && !!r.can_remind;
			var payInfo;
			if (payStatusMap[rawPay]) {
				payInfo = payStatusMap[rawPay];
			} else {
				payInfo = { cls: 'sd-renewal-status-soon', label: rawPay };
			}

			var rawReg = String(r.status || '').trim().toLowerCase();
			var regInfo = regStatusMap[rawReg] || { cls: 'sd-renewal-status-pending', label: rawReg || '—' };

			var regOptionsHtml = '';
			Object.keys(regStatusMap).forEach(function (k) {
				regOptionsHtml += '<option value="' + esc(k) + '"' + (k === rawReg ? ' selected' : '') + '>' + esc(regStatusMap[k].label) + '</option>';
			});
			if (rawReg && !regStatusMap[rawReg]) {
				regOptionsHtml += '<option value="' + esc(rawReg) + '" selected>' + esc(regInfo.label) + '</option>';
			}

			var payOptionsHtml = '';
			Object.keys(payStatusMap).forEach(function (k) {
				// "Rimborsato" è selezionabile solo se lo stato corrente è "Pagato" (o già "Rimborsato").
				if (k === 'refunded' && rawPay !== 'paid' && rawPay !== 'refunded') { return; }
				payOptionsHtml += '<option value="' + esc(k) + '"' + (k === rawPay ? ' selected' : '') + '>' + esc(payStatusMap[k].label) + '</option>';
			});
			if (rawPay && !payStatusMap[rawPay]) {
				payOptionsHtml += '<option value="' + esc(rawPay) + '" selected>' + esc(payInfo.label) + '</option>';
			}

			var chf = parseFloat(r.price_chf || 0);
			var eur = parseFloat(r.price_eur || 0);
			var amount = 'CHF ' + chf.toFixed(2) + (eur > 0 ? ' / EUR ' + eur.toFixed(2) : '');

			var actions = [];
			actions.push('<button type="button" class="sd-btn sd-btn-outline sd-btn-sm sd-btn-icon-only sd-activity-preview-email" data-reg-id="' + esc(r.id) + '" title="' + esc(regStrings().previewLabel || 'Anteprima e-mail') + '" aria-label="' + esc(regStrings().previewLabel || 'Anteprima e-mail') + '">\uD83D\uDD0D</button>');
			actions.push('<button type="button" class="sd-btn sd-btn-primary sd-btn-sm sd-btn-icon-only sd-send-reg-email" data-reg-id="' + esc(r.id) + '" title="' + esc(regStrings().regSendEmailLabel || 'Invia e-mail') + '" aria-label="' + esc(regStrings().regSendEmailLabel || 'Invia e-mail') + '"' + (r.can_remind ? '' : ' disabled') + '>\u2709</button>');
			actions.push('<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-send-payment-confirmation" data-reg-id="' + esc(r.id) + '"' + (canSendPaymentConfirmation ? '' : ' disabled') + '>' + esc(regStrings().regPaymentConfirmationLabel || 'Invia conferma pagamento') + '</button>');
			if (rawPay === 'invoice_requested' || rawPay === 'invoice_error') {
				actions.push('<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-resend-invoice" data-id="' + esc(r.id) + '" title="Reinvia fattura">Reinvia fattura</button>');
			}
			actions.push('<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-edit" data-id="' + esc(r.id) + '" title="Modifica iscrizione">Modifica</button>');
			actions.push('<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-reg-pdf-single" data-id="' + esc(r.id) + '" title="Scarica PDF scheda">📄 PDF</button>');
			actions.push('<button type="button" class="sd-btn sd-btn-danger sd-btn-sm sd-reg-delete" data-id="' + esc(r.id) + '" data-name="' + esc((r.first_name || '') + ' ' + (r.last_name || '')) + '" title="Elimina iscrizione">Elimina</button>');

			html += '<tr>' +
				'<td><strong>' + esc(r.name || '') + '</strong></td>' +
				'<td>' + esc(r.email || '—') + '</td>' +
				'<td>' +
					'<span class="sd-status-pill-wrap">' +
						'<span class="sd-renewal-status ' + regInfo.cls + '">' + esc(regInfo.label) + '</span>' +
						'<select class="sd-status-pill-select sd-reg-status-select" name="reg_status_' + esc(r.id) + '" data-reg-id="' + esc(r.id) + '" data-prev="' + esc(rawReg) + '" aria-label="Stato iscrizione">' + regOptionsHtml + '</select>' +
					'</span>' +
				'</td>' +
				'<td>' +
					'<span class="sd-status-pill-wrap">' +
						'<span class="sd-renewal-status ' + payInfo.cls + '">' + esc(payInfo.label) + '</span>' +
						'<select class="sd-status-pill-select sd-reg-payment-select" name="reg_pay_' + esc(r.id) + '" data-reg-id="' + esc(r.id) + '" data-prev="' + esc(rawPay) + '" aria-label="Stato pagamento">' + payOptionsHtml + '</select>' +
					'</span>' +
				'</td>' +
				'<td>' + formatRegDate(r.created_at) + '</td>' +
				'<td>' + esc(amount) + '</td>' +
				'<td>' + formatRegDateTime(r.last_email_at) + (r.last_email_subject ? '<div class="sd-cell-sub" title="' + esc(r.last_email_subject) + '">' + esc(r.last_email_subject) + '</div>' : '') + '</td>' +
				'<td class="sd-renewals-action-cell">' + actions.join(' ') + '</td>' +
				'</tr>';
		});
		$tbody.html(html);
	}

	function updateRegBulkButton() {
		var $btn = $('#sd-reg-bulk-email');
		var $all = $('#sd-reg-email-all-paid');
		if (!$btn.length) { return; }

		var eligible = getFilteredRegRows().filter(function (r) { return !!r.can_remind; }).length;
		var mode = regDashboardState.quickFilter || 'all';
		var labelMap = {
			'all': 'Invia e-mail massivo a tutti',
			'pending': 'Invia e-mail massivo (in attesa)',
			'paid': 'Invia e-mail massivo (pagati)',
			'invoice_requested': 'Invia e-mail massivo (fattura richiesta)',
			'valid_email': 'Invia e-mail massivo (con e-mail valida)'
		};
		$btn.text((labelMap[mode] || 'Invia e-mail massivo') + ' [' + eligible + ']');
		$btn.prop('disabled', eligible < 1 || !regDashboardState.activityId);

		var paidEligible = (regDashboardState.rows || []).filter(function (r) {
			return r.payment_status === 'paid' && r.can_remind;
		}).length;
		$all.text((regStrings().regAllPaidLabel || 'Invia e-mail a tutte le iscrizioni pagate') + ' [' + paidEligible + ']');
		$all.prop('disabled', paidEligible < 1 || !regDashboardState.activityId);
	}

	function sendRegEmailSingle($btn, regId) {
		var origLabel = $btn.text();
		$btn.prop('disabled', true).text(regStrings().regSendingLabel || 'Invio...');
		$.ajax({
			url: sdActivityAdmin.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activity_send_registration_email_single',
				nonce: sdActivityAdmin.nonce,
				registration_id: regId,
				template_id: parseInt($('#sd-reg-template-id').val(), 10) || 0,
				pdf_template_ids: $('#sd-reg-tpl-select').val() || []
			},
			success: function (resp) {
				if (!resp.success) {
					$btn.prop('disabled', false).text(origLabel);
					showRegDashboardMessage('error', (resp.data && resp.data.message) || regStrings().regEmailError || 'Invio e-mail non riuscito.');
					return;
				}
				showRegDashboardMessage('success', (resp.data && resp.data.message) || regStrings().regEmailSent || 'E-mail inviata.');
				loadRegDashboard();
			},
			error: function () {
				$btn.prop('disabled', false).text(origLabel);
				showRegDashboardMessage('error', regStrings().regEmailError || 'Invio e-mail non riuscito.');
			}
		});
	}

	function sendRegEmailsBulk() {
		var $btn = $(this);
		if ($btn.prop('disabled')) { return; }

		var mode = regDashboardState.quickFilter || 'all';
		var confirmTextMap = {
			'all': 'Inviare l\'e-mail a tutti gli iscritti dell\'attività selezionata?',
			'pending': 'Inviare l\'e-mail a tutti gli iscritti in attesa di pagamento?',
			'paid': 'Inviare l\'e-mail a tutti gli iscritti pagati?',
			'invoice_requested': 'Inviare l\'e-mail a tutti gli iscritti con fattura richiesta?',
			'valid_email': 'Inviare l\'e-mail a tutti gli iscritti con e-mail valida?'
		};
		if (!window.confirm(confirmTextMap[mode] || 'Inviare l\'e-mail massiva?')) { return; }

		var origLabel = $btn.text();
		$btn.prop('disabled', true).text(regStrings().regBulkSendingLabel || 'Invio massivo...');

		$.ajax({
			url: sdActivityAdmin.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activity_send_registration_emails_bulk',
				nonce: sdActivityAdmin.nonce,
				activity_id: regDashboardState.activityId,
				filter_type: mode,
				template_id: parseInt($('#sd-reg-template-id').val(), 10) || 0,
				pdf_template_ids: $('#sd-reg-tpl-select').val() || []
			},
			success: function (resp) {
				$btn.prop('disabled', false).text(origLabel);
				if (!resp.success) {
					showRegDashboardMessage('error', (resp.data && resp.data.message) || regStrings().regEmailError || 'Invio non riuscito.');
					return;
				}
				showRegDashboardMessage('success', (resp.data && resp.data.message) || regStrings().regBulkDone || 'Invio massivo completato.');
				loadRegDashboard();
			},
			error: function () {
				$btn.prop('disabled', false).text(origLabel);
				showRegDashboardMessage('error', regStrings().regEmailError || 'Invio non riuscito.');
			}
		});
	}

	function sendRegEmailsAllPaid() {
		var $btn = $(this);
		if ($btn.prop('disabled')) { return; }

		if (!window.confirm(regStrings().regAllPaidConfirm || 'Inviare l\'e-mail a tutte le iscrizioni pagate?')) { return; }

		var origLabel = $btn.text();
		$btn.prop('disabled', true).text(regStrings().regAllPaidSendingLabel || 'Invio in corso...');

		$.ajax({
			url: sdActivityAdmin.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_activity_send_registration_emails_all_paid',
				nonce: sdActivityAdmin.nonce,
				activity_id: regDashboardState.activityId,
				template_id: parseInt($('#sd-reg-template-id').val(), 10) || 0,
				pdf_template_ids: $('#sd-reg-tpl-select').val() || []
			},
			success: function (resp) {
				$btn.prop('disabled', false).text(origLabel);
				if (!resp.success) {
					showRegDashboardMessage('error', (resp.data && resp.data.message) || regStrings().regEmailError || 'Invio non riuscito.');
					return;
				}
				showRegDashboardMessage('success', (resp.data && resp.data.message) || 'Invio completato.');
				loadRegDashboard();
			},
			error: function () {
				$btn.prop('disabled', false).text(origLabel);
				showRegDashboardMessage('error', regStrings().regEmailError || 'Invio non riuscito.');
			}
		});
	}

	function showRegDashboardMessage(type, text) {
		var $msg = $('#sd-reg-dashboard-message');
		if (!$msg.length) { return; }
		$msg
			.removeClass('sd-notice-error sd-notice-success sd-notice-warning')
			.addClass(type === 'success' ? 'sd-notice-success' : 'sd-notice-error')
			.text(text)
			.show();
	}

	function formatRegDate(value) {
		if (!value) { return '—'; }
		var str = String(value);
		if (str.indexOf('0000-00-00') === 0) { return '—'; }
		var dt = new Date(str.replace(' ', 'T'));
		if (isNaN(dt.getTime()) || dt.getFullYear() < 1971) { return '—'; }
		var d = String(dt.getDate()).padStart(2, '0');
		var m = String(dt.getMonth() + 1).padStart(2, '0');
		var y = dt.getFullYear();
		return d + '.' + m + '.' + y;
	}

	function formatRegDateTime(value) {
		if (!value) { return '—'; }
		var str = String(value);
		if (str.indexOf('0000-00-00') === 0) { return '—'; }
		var dt = new Date(str.replace(' ', 'T'));
		if (isNaN(dt.getTime()) || dt.getFullYear() < 1971) { return '—'; }
		var d = String(dt.getDate()).padStart(2, '0');
		var m = String(dt.getMonth() + 1).padStart(2, '0');
		var y = dt.getFullYear();
		var hh = String(dt.getHours()).padStart(2, '0');
		var mm = String(dt.getMinutes()).padStart(2, '0');
		return d + '.' + m + '.' + y + ' ' + hh + ':' + mm;
	}

})(jQuery);
