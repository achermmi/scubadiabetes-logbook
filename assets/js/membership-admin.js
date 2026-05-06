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

	// ===== INIZIALIZZAZIONE =====
	$(document).ready(function () {
		// Pagina gestione soci
		if ($('#sd-management-page').length) {
			loadMembers();
			bindFilters();
			bindExport();
			bindPagination();
			bindBulkDelete();
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

		// Quando si imposta Non pagato: azzera data e metodo
		$form.on('change', 'select[name="has_paid_fee"]', function () {
			if ($(this).val() === '0') {
				$form.find('input[name="payment_date"]').val('');
				$form.find('select[name="payment_method"]').val('');
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
			$.post(sdMembersAdmin.ajaxUrl, {
				action: 'sd_resend_invoice_email',
				nonce: sdMembersAdmin.nonce,
				member_id: memberId
			}, function (res) {
				$btn.prop('disabled', false).text('Invia Fattura');
				if (res.success) {
					showEditMessage(res.data.message, 'success');
				} else {
					showEditMessage(res.data.message || 'Errore durante l\'invio.', 'error');
				}
			}).fail(function () {
				$btn.prop('disabled', false).text('Invia Fattura');
				showEditMessage('Errore di rete durante l\'invio.', 'error');
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
		return escapeHtml(labels[type] || '—');
	}

	function escapeHtml(str) {
		return $('<div>').text(String(str || '')).html();
	}

	function escapeAttr(str) {
		return String(str || '').replace(/"/g, '&quot;');
	}

})(jQuery);
