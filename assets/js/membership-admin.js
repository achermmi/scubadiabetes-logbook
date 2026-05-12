/**
 * JavaScript Gestione Soci (Admin)
 *
 * ScubaDiabetes Logbook - Membership Admin
 */
(function ($) {
	'use strict';

	var state = {
		paged:   1,
		perPage: 25,
		total:   0,
	};
	var renewalsState = {
		rows: [],
		quickFilter: 'all'
	};

	// ===== INIZIALIZZAZIONE =====
	$(document).ready(function () {
		// Pagina gestione soci
		if ($('#sd-management-page').length) {
			loadMembers();
			loadRenewalsDashboard();
			bindFilters();
			bindStatCards();
			bindExport();
			bindPagination();
			bindBulkDelete();
			bindRenewalsActions();
			bindRenewalsQuickFilters();
			bindRenewalsBulkReminder();
			bindRenewalsAllActiveEmail();
		}

		// Pagina modifica socio
		if ($('#sd-edit-page').length) {
			bindTabs();
			bindEditForm();
			bindEditBulkDelete();
		}
	});

	// ===== CARICAMENTO LISTA =====
	function loadStats() {
		$.post(sdMembAdmin.ajaxUrl, {
			action: 'sd_members_stats',
			nonce:  sdMembAdmin.nonce,
		}, function (resp) {
			if (!resp.success) { return; }
			var s = resp.data;
			$('#sd-stat-total').text(s.total);
			$('#sd-stat-paid').text(s.paid);
			$('#sd-stat-unpaid').text(s.unpaid);
			$('#sd-stat-income').text('CHF ' + s.income);
			$('#sd-stat-expected').text('CHF ' + s.expected);
			$('#sd-stat-active-yes').text(s.active_yes);
			$('#sd-stat-active-no').text(s.active_no);
		});
	}

	function loadMembers() {
		var $tbody   = $('#sd-members-tbody');
		var $loading = $('#sd-members-loading');
		var $msg     = $('#sd-members-message');

		$loading.show();
		$tbody.html('<tr><td colspan="14" class="sd-table-empty">Caricamento...</td></tr>');
		$msg.hide();

		var filters = getFilters();

		$.ajax({
			url:  sdMembAdmin.ajaxUrl,
			type: 'POST',
			data: $.extend({}, filters, {
				action:   'sd_members_list',
				nonce:    sdMembAdmin.nonce,
				paged:    state.paged,
				per_page: state.perPage,
			}),
			success: function (resp) {
				$loading.hide();
				if (!resp.success) {
					$msg.attr('class', 'sd-notice sd-notice-error').text(resp.data.message || 'Errore caricamento.').show();
					return;
				}

				var data = resp.data;
				state.total = data.total;

				if (!data.rows || data.rows.length === 0) {
					$tbody.html('<tr><td colspan="14" class="sd-table-empty">Nessun socio trovato.</td></tr>');
					$('#sd-pagination').hide();
					return;
				}

				renderRows(data.rows);
				renderPagination(data.total, data.per_page, data.paged);
			},
			error: function () {
				$loading.hide();
				$tbody.html('<tr><td colspan="13" class="sd-table-empty">Errore di rete.</td></tr>');
			},
		});
	}

	function loadRenewalsDashboard() {
		var $tbody   = $('#sd-renewals-tbody');
		var $loading = $('#sd-renewals-loading');
		var $msg     = $('#sd-renewals-message');

		if (!$tbody.length) {
			return;
		}

		$loading.show();
		$msg.hide();
		$tbody.html('<tr><td colspan="7" class="sd-table-empty">Caricamento...</td></tr>');

		$.ajax({
			url: sdMembAdmin.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sd_members_renewals_dashboard',
				nonce: sdMembAdmin.nonce,
				anno: $('#sd-filter-anno').val() || sdMembAdmin.currentYear
			},
			success: function(resp) {
				$loading.hide();
				if (!resp.success || !resp.data || !Array.isArray(resp.data.rows)) {
					showRenewalsMessage('error', (sdMembAdmin.strings && sdMembAdmin.strings.renewalsLoadError) || 'Errore caricamento cruscotto rinnovi.');
					$tbody.html('<tr><td colspan="7" class="sd-table-empty">Errore caricamento.</td></tr>');
					updateBulkReminderButton();
					return;
				}
				renewalsState.rows = resp.data.rows;
				renderRenewalsRows(getFilteredRenewalsRows());
				updateBulkReminderButton();
			},
			error: function() {
				$loading.hide();
				showRenewalsMessage('error', (sdMembAdmin.strings && sdMembAdmin.strings.renewalsLoadError) || 'Errore caricamento cruscotto rinnovi.');
				$tbody.html('<tr><td colspan="7" class="sd-table-empty">Errore di rete.</td></tr>');
				updateBulkReminderButton();
			}
		});
	}

	function renderRenewalsRows(rows) {
		var $tbody = $('#sd-renewals-tbody');
		if (!$tbody.length) {
			return;
		}

		if (!rows.length) {
			$tbody.html('<tr><td colspan="7" class="sd-table-empty">Nessun socio trovato.</td></tr>');
			return;
		}

		var html = '';
		rows.forEach(function(r) {
			var statusClass = 'sd-renewal-status-pending';
			var statusLabel = 'Da definire';
			if (r.status === 'valida') {
				statusClass = 'sd-renewal-status-valid';
				statusLabel = 'Valida';
			} else if (r.status === 'in_scadenza') {
				statusClass = 'sd-renewal-status-soon';
				statusLabel = 'In scadenza';
			} else if (r.status === 'scaduta') {
				statusClass = 'sd-renewal-status-expired';
				statusLabel = 'Scaduta';
			}

			var due = parseFloat(r.amount_due || 0);
			var dueLabel = 'CHF ' + due.toFixed(2);
			var dueClass = due > 0 ? 'sd-renewal-due-open' : 'sd-renewal-due-clear';

			var actionHtml = '<span class="sd-renewal-disabled">—</span>';
			if (r.can_remind) {
				actionHtml = '<button type="button" class="sd-btn sd-btn-secondary sd-btn-sm sd-send-renewal-reminder" data-member-id="' + escapeAttr(r.id) + '">' +
					((sdMembAdmin.strings && sdMembAdmin.strings.sendReminderLabel) || 'Invia e-mail') +
				'</button>';
			}

			html += '<tr>' +
				'<td><strong>' + escapeHtml(r.name || '') + '</strong></td>' +
				'<td>' + escapeHtml(r.email || '—') + '</td>' +
				'<td><span class="sd-renewal-status ' + statusClass + '">' + statusLabel + '</span></td>' +
				'<td>' + formatDate(r.membership_expiry) + '</td>' +
				'<td><span class="' + dueClass + '">' + dueLabel + '</span></td>' +
				'<td>' + formatDateTime(r.last_reminder_at) + '</td>' +
				'<td>' + actionHtml + '</td>' +
			'</tr>';
		});

		$tbody.html(html);
	}

	function bindRenewalsActions() {
		$(document).on('click', '.sd-send-renewal-reminder', function() {
			var $btn = $(this);
			var memberId = parseInt($btn.data('member-id'), 10) || 0;
			if (!memberId) {
				return;
			}

			$btn.prop('disabled', true).text((sdMembAdmin.strings && sdMembAdmin.strings.sendingLabel) || 'Invio...');

			$.ajax({
				url: sdMembAdmin.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'sd_members_send_renewal_reminder',
					nonce: sdMembAdmin.nonce,
					member_id: memberId,
					template_id: parseInt($('#sd-renewals-template-id').val(), 10) || 0
				},
				success: function(resp) {
					if (!resp.success) {
						$btn.prop('disabled', false).text((sdMembAdmin.strings && sdMembAdmin.strings.sendReminderLabel) || 'Invia e-mail');
						showRenewalsMessage('error', (resp.data && resp.data.message) || ((sdMembAdmin.strings && sdMembAdmin.strings.renewalReminderError) || 'Invio e-mail non riuscito.'));
						return;
					}
					showRenewalsMessage('success', (resp.data && resp.data.message) || ((sdMembAdmin.strings && sdMembAdmin.strings.renewalReminderSent) || 'E-mail inviata con successo.'));
					loadRenewalsDashboard();
				},
				error: function() {
					$btn.prop('disabled', false).text((sdMembAdmin.strings && sdMembAdmin.strings.sendReminderLabel) || 'Invia e-mail');
					showRenewalsMessage('error', (sdMembAdmin.strings && sdMembAdmin.strings.renewalReminderError) || 'Invio e-mail non riuscito.');
				}
			});
		});

		$('#sd-filter-anno').on('change', function() {
			loadRenewalsDashboard();
		});
	}

	function bindRenewalsQuickFilters() {
		$(document).on('click', '.sd-renewals-filter', function() {
			var $btn = $(this);
			var mode = String($btn.data('renewals-filter') || 'all');
			renewalsState.quickFilter = mode;

			$('.sd-renewals-filter').removeClass('is-active');
			$btn.addClass('is-active');

			renderRenewalsRows(getFilteredRenewalsRows());
		});
	}

	function bindRenewalsBulkReminder() {
		$('#sd-renewals-bulk-remind').on('click', function() {
			var $btn = $(this);
			if ($btn.prop('disabled')) {
				return;
			}

			var confirmText = buildBulkConfirmText();
			if (!window.confirm(confirmText)) {
				return;
			}

			$btn.prop('disabled', true).text((sdMembAdmin.strings && sdMembAdmin.strings.bulkSendingLabel) || 'Invio massivo...');

			$.ajax({
				url: sdMembAdmin.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'sd_members_send_renewal_reminders_bulk',
					nonce: sdMembAdmin.nonce,
					filter_type: renewalsState.quickFilter || 'in_scadenza',
					template_id: parseInt($('#sd-renewals-template-id').val(), 10) || 0
				},
				success: function(resp) {
					if (!resp.success) {
						showRenewalsMessage('error', (resp.data && resp.data.message) || ((sdMembAdmin.strings && sdMembAdmin.strings.renewalReminderError) || 'Invio e-mail non riuscito.'));
						updateBulkReminderButton();
						return;
					}
					showRenewalsMessage('success', (resp.data && resp.data.message) || ((sdMembAdmin.strings && sdMembAdmin.strings.bulkReminderDone) || 'Invio massivo completato.'));
					loadRenewalsDashboard();
				},
				error: function() {
					showRenewalsMessage('error', (sdMembAdmin.strings && sdMembAdmin.strings.renewalReminderError) || 'Invio e-mail non riuscito.');
					updateBulkReminderButton();
				}
			});
		});
	}

	function bindRenewalsAllActiveEmail() {
		$('#sd-renewals-email-all-active').on('click', function() {
			var $btn = $(this);
			if ($btn.prop('disabled')) {
				return;
			}

			var confirmText = (sdMembAdmin.strings && sdMembAdmin.strings.allActiveEmailConfirm) || 'Inviare l\'e-mail a tutti i soci attivi con email valida?';
			if (!window.confirm(confirmText)) {
				return;
			}

			$btn.prop('disabled', true).text((sdMembAdmin.strings && sdMembAdmin.strings.allActiveSendingLabel) || 'Invio a tutti...');

			$.ajax({
				url: sdMembAdmin.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'sd_members_send_renewal_emails_all_active',
					nonce: sdMembAdmin.nonce,
					template_id: parseInt($('#sd-renewals-template-id').val(), 10) || 0
				},
				success: function(resp) {
					if (!resp.success) {
						showRenewalsMessage('error', (resp.data && resp.data.message) || ((sdMembAdmin.strings && sdMembAdmin.strings.renewalReminderError) || 'Invio e-mail non riuscito.'));
						$btn.prop('disabled', false).text((sdMembAdmin.strings && sdMembAdmin.strings.allActiveEmailLabel) || 'Invia e-mail a tutti i soci attivi');
						return;
					}
					showRenewalsMessage('success', (resp.data && resp.data.message) || 'Invio e-mail completato.');
					$btn.prop('disabled', false).text((sdMembAdmin.strings && sdMembAdmin.strings.allActiveEmailLabel) || 'Invia e-mail a tutti i soci attivi');
					loadRenewalsDashboard();
				},
				error: function() {
					showRenewalsMessage('error', (sdMembAdmin.strings && sdMembAdmin.strings.renewalReminderError) || 'Invio e-mail non riuscito.');
					$btn.prop('disabled', false).text((sdMembAdmin.strings && sdMembAdmin.strings.allActiveEmailLabel) || 'Invia e-mail a tutti i soci attivi');
				}
			});
		});
	}

	function getFilteredRenewalsRows() {
		var mode = renewalsState.quickFilter || 'all';
		var rows = Array.isArray(renewalsState.rows) ? renewalsState.rows : [];

		if (mode === 'scaduti') {
			return rows.filter(function(r) { return r.status === 'scaduta'; });
		}
		if (mode === 'in_scadenza') {
			return rows.filter(function(r) { return r.status === 'in_scadenza'; });
		}
		if (mode === 'non_pagati') {
			return rows.filter(function(r) { return parseFloat(r.amount_due || 0) > 0; });
		}
		if (mode === 'valid_email') {
			return rows.filter(function(r) { return r.can_remind; });
		}

		return rows;
	}

	function updateBulkReminderButton() {
		var $btn = $('#sd-renewals-bulk-remind');
		if (!$btn.length) {
			return;
		}

		var eligible = getBulkEligibleCount();
		var label = buildBulkButtonLabel(eligible);
		$btn.text(label);
		$btn.prop('disabled', eligible < 1);
	}

	function getBulkEligibleCount() {
		var filtered = getFilteredRenewalsRows();
		return filtered.filter(function(r) {
			return !!r.can_remind;
		}).length;
	}

	function buildBulkButtonLabel(count) {
		var mode = renewalsState.quickFilter || 'all';
		var labelMap = {
			'all':        'Invia e-mail massivo a tutti',
			'scaduti':    'Invia e-mail massivo (scaduti)',
			'in_scadenza':'Invia e-mail massivo (in scadenza)',
			'non_pagati': 'Invia e-mail massivo (non pagati)',
			'valid_email':'Invia e-mail massivo (con e-mail valida)'
		};
		var label = labelMap[mode] || 'Invia e-mail massivo';
		return label + ' [' + (count || 0) + ']';
	}

	function buildBulkConfirmText() {
		var mode = renewalsState.quickFilter || 'all';
		var msgMap = {
			'all':        'Inviare l\'e-mail a tutti i soci del cruscotto?',
			'scaduti':    'Inviare l\'e-mail a tutti i soci con iscrizione scaduta?',
			'in_scadenza':(sdMembAdmin.strings && sdMembAdmin.strings.bulkReminderConfirm) || 'Inviare l\'e-mail a tutti i soci in scadenza?',
			'non_pagati': 'Inviare l\'e-mail a tutti i soci non pagati?',
			'valid_email': 'Inviare l\'e-mail a tutti i soci con e-mail valida?'
		};
		return msgMap[mode] || 'Inviare l\'e-mail massiva?';
	}

	function showRenewalsMessage(type, text) {
		var $msg = $('#sd-renewals-message');
		if (!$msg.length) {
			return;
		}
		$msg
			.removeClass('sd-notice-error sd-notice-success sd-notice-warning')
			.addClass(type === 'success' ? 'sd-notice-success' : 'sd-notice-error')
			.text(text)
			.show();
	}

	function formatDateTime(value) {
		if (!value) {
			return '—';
		}
		var dt = new Date(String(value).replace(' ', 'T'));
		if (isNaN(dt.getTime())) {
			return escapeHtml(String(value));
		}
		var d = String(dt.getDate()).padStart(2, '0');
		var m = String(dt.getMonth() + 1).padStart(2, '0');
		var y = dt.getFullYear();
		var hh = String(dt.getHours()).padStart(2, '0');
		var mm = String(dt.getMinutes()).padStart(2, '0');
		return d + '.' + m + '.' + y + ' ' + hh + ':' + mm;
	}

	function getFilters() {
		var filters = {};
		$('#sd-member-filters').find('input, select').each(function () {
			var name = $(this).attr('name');
			var val  = $(this).val();
			if (name && val !== '') {
				filters[name] = val;
			}
		});
		return filters;
	}

	// ===== RENDER TABELLA =====
	function renderRows(rows) {
		var $tbody = $('#sd-members-tbody');
		var html   = '';

		rows.forEach(function (m) {
			var paidBadge = m.has_paid_fee == 1
				? '<span class="sd-paid-badge">Sì</span>'
				: '<span class="sd-unpaid-badge">No</span>';

			var activeBadge = m.is_active == 1
				? '<span class="sd-paid-badge">Sì</span>'
				: '<span class="sd-unpaid-badge">No</span>';

			var scubaBadge    = m.is_scuba == 1 ? '✓' : '—';
			var diabeticTypes = ['tipo_1','tipo_2','tipo_3c','lada','mody','midd','altro'];
			var diabeticBadge = (m.diabetes_type && diabeticTypes.indexOf(m.diabetes_type) !== -1) ? '✓' : '—';

			var payDate = (function() {
				if ( ! m.payment_date ) { return '—'; }
				var parts = m.payment_date.substr(0, 10).split('-');
				return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : m.payment_date.substr(0, 10);
			})();

			html += '<tr data-member-id="' + escapeAttr(m.id) + '">' +
				'<td><input type="checkbox" class="sd-member-select" value="' + escapeAttr(m.id) + '" aria-label="Seleziona iscrizione"></td>' +
				'<td><strong>' + escapeHtml(m.last_name) + '</strong>, ' + escapeHtml(m.first_name) + '</td>' +
				'<td>' + escapeHtml(m.email) + '</td>' +
				'<td>' + formatDate(m.date_of_birth) + '</td>' +
				'<td>CHF ' + parseFloat(m.fee_amount || 0).toFixed(2) + '</td>' +
				'<td>' + paidBadge + '</td>' +
				'<td>' + escapeHtml(payDate) + '</td>' +
				'<td>' + formatMemberType(m.member_type) + '</td>' +
				'<td>' + activeBadge + '</td>' +
				'<td>' + scubaBadge + '</td>' +
				'<td>' + diabeticBadge + '</td>' +
				'<td>' + escapeHtml(m.taglia_maglietta || '—') + '</td>' +
				'<td>' + escapeHtml(m.wp_role_label || '—') + '</td>' +
				'<td>' +
					'<a href="' + sdMembAdmin.editUrl + '?member_id=' + escapeAttr(m.id) + '" class="sd-btn sd-btn-secondary sd-btn-sm">Modifica</a>' +
				'</td>' +
				'</tr>';
		});

		$tbody.html(html);
		$('#sd-select-all-members').prop('checked', false);

		// Click riga → apre pagina modifica
		$('#sd-members-table tbody tr').on('click', function (e) {
			if ($(e.target).is('a, button, input, label')) return;
			var id = $(this).data('member-id');
			if (id) {
				window.location.href = sdMembAdmin.editUrl + '?member_id=' + id;
			}
		});
	}

	function bindBulkDelete() {
		$('#sd-select-all-members').on('change', function () {
			var checked = $(this).is(':checked');
			$('#sd-members-tbody .sd-member-select').prop('checked', checked);
		});

		$('#sd-members-tbody').on('change', '.sd-member-select', function () {
			var total = $('#sd-members-tbody .sd-member-select').length;
			var selected = $('#sd-members-tbody .sd-member-select:checked').length;
			$('#sd-select-all-members').prop('checked', total > 0 && total === selected);
		});

		$('#sd-delete-selected').on('click', function () {
			var ids = getSelectedIds('#sd-members-tbody .sd-member-select:checked');
			if (!ids.length) {
				showPageMessage('Seleziona almeno una iscrizione da eliminare.', 'warning');
				return;
			}

			var ok = window.confirm('ATTENZIONE: stai per eliminare in modo irreversibile le iscrizioni selezionate, inclusi utenti WordPress e dati collegati. Vuoi continuare?');
			if (!ok) {
				return;
			}

			deleteMembers(ids, function () {
				loadMembers();
				loadStats();
			});
		});
	}

	// ===== PAGINAZIONE =====
	function renderPagination(total, perPage, paged) {
		var totalPages = Math.ceil(total / perPage);
		var $pg        = $('#sd-pagination');

		if (totalPages <= 1) {
			$pg.hide();
			return;
		}

		$('#sd-page-info').text('Pagina ' + paged + ' di ' + totalPages + ' (' + total + ' soci)');
		$('#sd-prev-page').prop('disabled', paged <= 1);
		$('#sd-next-page').prop('disabled', paged >= totalPages);
		$pg.show();
	}

	function bindPagination() {
		$('#sd-prev-page').on('click', function () {
			if (state.paged > 1) {
				state.paged--;
				loadMembers();
			}
		});

		$('#sd-next-page').on('click', function () {
			var totalPages = Math.ceil(state.total / state.perPage);
			if (state.paged < totalPages) {
				state.paged++;
				loadMembers();
			}
		});
	}

	// ===== FILTRI =====
	function bindFilters() {
		$('#sd-member-filters').on('submit', function (e) {
			e.preventDefault();
			state.paged = 1;
			loadMembers();
		});

		$('#sd-btn-reset').on('click', function () {
			$('#sd-member-filters')[0].reset();
			$('.sd-stat-card').removeClass('sd-stat-active');
			state.paged = 1;
			loadMembers();
		});
	}

	// ===== STAT CARD FILTER =====
	function bindStatCards() {
		$('.sd-stat-clickable').on('click', function () {
			var $card   = $(this);
			var field   = $card.data('filter-field');
			var value   = $card.data('filter-value');
			var isReset = $card.data('filter-reset');
			var anno    = $('#sd-filter-anno').val();

			$('#sd-member-filters')[0].reset();
			$('#sd-filter-anno').val(anno);

			if (!isReset && field !== undefined) {
				$('#sd-member-filters').find('[name="' + field + '"]').val(String(value));
			}

			$('.sd-stat-card').removeClass('sd-stat-active');
			$card.addClass('sd-stat-active');

			state.paged = 1;
			loadMembers();
		});
	}

	// ===== EXPORT =====
	function bindExport() {
		$('#sd-export-csv, #sd-export-xlsx').on('click', function () {
			var format  = $(this).data('format');
			var filters = getFilters();

			var formData = $.extend({}, filters, {
				action: 'sd_members_export',
				nonce:  sdMembAdmin.nonce,
				format: format,
				anno:   filters.anno || sdMembAdmin.currentYear,
			});

			// Invia via form nascosto per scaricare il file
			var $form = $('<form method="POST" action="' + sdMembAdmin.ajaxUrl + '" style="display:none;">');
			$.each(formData, function (k, v) {
				$form.append('<input type="hidden" name="' + escapeAttr(k) + '" value="' + escapeAttr(v) + '">');
			});
			$('body').append($form);
			$form[0].submit();
			setTimeout(function () { $form.remove(); }, 5000);
		});

		$('#sd-export-pdf').on('click', function () {
			var filters = getFilters();

			var formData = $.extend({}, filters, {
				action: 'sd_members_export_pdf',
				nonce:  sdMembAdmin.nonce,
				anno:   filters.anno || sdMembAdmin.currentYear,
			});

			var $form = $('<form method="POST" action="' + sdMembAdmin.ajaxUrl + '" style="display:none;">');
			$.each(formData, function (k, v) {
				$form.append('<input type="hidden" name="' + escapeAttr(k) + '" value="' + escapeAttr(v) + '">');
			});
			$('body').append($form);
			$form[0].submit();
			setTimeout(function () { $form.remove(); }, 5000);
		});
	}

	// ===== TABS (pagina edit) =====
	function bindTabs() {
		$(document).on('click', '.sd-tab-btn', function () {
			var tab = $(this).data('tab');

			$('.sd-tab-btn').removeClass('active');
			$(this).addClass('active');

			$('.sd-tab-content').removeClass('active');
			$('#sd-tab-' + tab).addClass('active');
		});
	}

	// ===== FORM MODIFICA =====
	function bindEditForm() {
		var $form = $('#sd-edit-form');
		if (!$form.length) return;

		$form.on('submit', function (e) {
			e.preventDefault();

			var $btn = $('#sd-edit-save');
			var $msg = $('#sd-edit-message');

			$btn.prop('disabled', true).text('Salvataggio...');
			$msg.hide();

			var formData = $form.serialize();
			formData += '&action=sd_member_update&nonce=' + sdMembAdmin.nonce;

			$.ajax({
				url:  sdMembAdmin.ajaxUrl,
				type: 'POST',
				data: formData,
				success: function (resp) {
					$btn.prop('disabled', false).text('Salva modifiche');
					if (resp.success) {
						$msg.attr('class', 'sd-notice sd-notice-success')
							.text(resp.data.message || 'Salvato.')
							.show();
						window.scrollTo({ top: 0, behavior: 'smooth' });
						// Ricarica la pagina per aggiornare i ruoli e tutti i dati
						setTimeout(function () { window.location.reload(); }, 800);
					} else {
						$msg.attr('class', 'sd-notice sd-notice-error')
							.text(resp.data.message || 'Errore durante il salvataggio.')
							.show();
					}
				},
				error: function () {
					$btn.prop('disabled', false).text('Salva modifiche');
					$msg.attr('class', 'sd-notice sd-notice-error')
						.text('Errore di rete.')
						.show();
				},
			});
		});

		// Quando si cambiano i ruoli WP, aggiorna il campo Subacqueo di conseguenza
		$form.on('change', 'input[name="wp_roles[]"]', function () {
			var $diverCb = $form.find('input[name="wp_roles[]"][value="sd_diver"], input[name="wp_roles[]"][value="sd_diver_diabetic"]');
			$form.find('select[name="is_scuba"]').val($diverCb.is(':checked') ? '1' : '0');
		});

		// Comportamento automatico cambio stato pagamento
		$form.on('change', 'select[name="has_paid_fee"]', function () {
			if ($(this).val() === '0') {
				$form.find('input[name="payment_date"]').val('');
				$form.find('select[name="is_active"]').val('0');
			} else if ($(this).val() === '1') {
				var $dateField = $form.find('input[name="payment_date"]');
				if (!$dateField.val()) {
					var today = new Date();
					var yyyy = today.getFullYear();
					var mm = String(today.getMonth() + 1).padStart(2, '0');
					var dd = String(today.getDate()).padStart(2, '0');
					$dateField.val(yyyy + '-' + mm + '-' + dd);
				}
				$form.find('select[name="is_active"]').val('1');
			}
		});

		// Pulsante elimina singolo (solo admin)
		$('#sd-edit-delete').on('click', function () {
			var memberId = parseInt($(this).data('member-id'), 10) || 0;
			if (!memberId) {
				showEditMessage('ID socio non valido.', 'error');
				return;
			}

			if (!confirm('ATTENZIONE: stai per eliminare in modo irreversibile questa iscrizione, inclusi utente WordPress e dati collegati. Vuoi continuare?')) {
				return;
			}

			deleteMembers([memberId], function () {
				window.history.back();
			}, '#sd-edit-message');
		});

		$('#sd-resend-invoice-email').on('click', function () {
			var memberId = parseInt($(this).data('member-id'), 10) || 0;
			if (!memberId) { return; }
			var $btn = $(this);
			$btn.prop('disabled', true).text('Invio in corso...');
			$.ajax({
				url: sdMembAdmin.ajaxUrl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sd_resend_invoice_email',
					nonce: sdMembAdmin.nonce,
					member_id: memberId
				},
				success: function (res) {
					$btn.prop('disabled', false).text('Invia Fattura');
					if (res.success) {
						showEditMessage(res.data.message, 'success');
					} else {
						showEditMessage(res.data.message || 'Errore durante l\'invio.', 'error');
					}
				},
				error: function (xhr, status) {
					$btn.prop('disabled', false).text('Invia Fattura');
					if (status === 'timeout') {
						showEditMessage('Timeout: il server ha impiegato troppo tempo. Riprova.', 'error');
					} else {
						showEditMessage('Errore di rete durante l\'invio.', 'error');
					}
				}
			});
		});
	}

	function bindEditBulkDelete() {
		$('#sd-edit-select-all-members').on('change', function () {
			var checked = $(this).is(':checked');
			$('#sd-tab-famigliari .sd-edit-member-select').prop('checked', checked);
		});

		$('#sd-tab-famigliari').on('change', '.sd-edit-member-select', function () {
			var total = $('#sd-tab-famigliari .sd-edit-member-select').length;
			var selected = $('#sd-tab-famigliari .sd-edit-member-select:checked').length;
			$('#sd-edit-select-all-members').prop('checked', total > 0 && total === selected);
		});

		$('#sd-edit-delete-selected').on('click', function () {
			var ids = getSelectedIds('#sd-tab-famigliari .sd-edit-member-select:checked');
			if (!ids.length) {
				showEditMessage('Seleziona almeno una iscrizione da eliminare.', 'warning');
				return;
			}

			var ok = window.confirm('ATTENZIONE: stai per eliminare in modo irreversibile le iscrizioni selezionate, inclusi utenti WordPress e dati collegati. Vuoi continuare?');
			if (!ok) {
				return;
			}

			deleteMembers(ids, function () {
				window.location.reload();
			}, '#sd-edit-message');
		});
	}

	function getSelectedIds(selector) {
		var ids = [];
		$(selector).each(function () {
			var id = parseInt($(this).val(), 10);
			if (id > 0) {
				ids.push(id);
			}
		});
		return ids;
	}

	function deleteMembers(ids, onDone, messageSelector) {
		var $msg = messageSelector ? $(messageSelector) : $('#sd-members-message');

		$.ajax({
			url: sdMembAdmin.ajaxUrl,
			type: 'POST',
			data: {
				action: 'sd_members_delete',
				nonce: sdMembAdmin.nonce,
				member_ids: ids,
			},
			success: function (resp) {
				if (!resp.success) {
					var msg = (resp.data && resp.data.message) ? resp.data.message : 'Errore durante l\'eliminazione.';
					showMessage($msg, msg, 'error');
					return;
				}

				var okMsg = (resp.data && resp.data.message) ? resp.data.message : 'Iscrizioni eliminate con successo.';
				showMessage($msg, okMsg, 'success');
				if (typeof onDone === 'function') {
					onDone(resp.data || {});
				}
			},
			error: function () {
				showMessage($msg, 'Errore di rete durante l\'eliminazione.', 'error');
			},
		});
	}

	function showPageMessage(text, type) {
		showMessage($('#sd-members-message'), text, type || 'warning');
	}

	function showEditMessage(text, type) {
		showMessage($('#sd-edit-message'), text, type || 'warning');
	}

	function showMessage($el, text, type) {
		var css = 'sd-notice sd-notice-info';
		if (type === 'success') {
			css = 'sd-notice sd-notice-success';
		} else if (type === 'error') {
			css = 'sd-notice sd-notice-error';
		} else if (type === 'warning') {
			css = 'sd-notice sd-notice-warning';
		}
		$el.attr('class', css).text(text).show();
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	// ===== HELPER =====
	function formatDate(val) {
		if (!val) { return '—'; }
		var parts = String(val).split('-');
		if (parts.length !== 3) { return escapeHtml(val); }
		return parts[2] + '.' + parts[1] + '.' + parts[0];
	}

	function formatMemberType(type) {
		var labels = {
			'attivo':               'Attivo',
			'attivo_capo_famiglia': 'Attivo Capo Famiglia',
			'attivo_famigliare':    'Attivo Famigliare',
			'passivo':              'Passivo',
			'accompagnatore':       'Accompagnatore',
			'sostenitore':          'Sostenitore',
			'onorario':             'Onorario',
			'fondatore':            'Fondatore',
		};
		// Se il tipo è vuoto o non trovato, default a 'Attivo'
		if (!type || type === '') {
			return escapeHtml('Attivo');
		}
		return escapeHtml(labels[type] || 'Attivo');
	}

	function escapeHtml(str) {
		return $('<div>').text(String(str || '')).html();
	}

	function escapeAttr(str) {
		return String(str || '').replace(/"/g, '&quot;');
	}

})(jQuery);
