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
		}

		// Pagina modifica socio
		if ($('#sd-edit-page').length) {
			bindTabs();
			bindEditForm();
		}
	});

	// ===== CARICAMENTO LISTA =====
	function loadMembers() {
		var $tbody   = $('#sd-members-tbody');
		var $loading = $('#sd-members-loading');
		var $msg     = $('#sd-members-message');

		$loading.show();
		$tbody.html('<tr><td colspan="11" class="sd-table-empty">Caricamento...</td></tr>');
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
					$tbody.html('<tr><td colspan="11" class="sd-table-empty">Nessun socio trovato.</td></tr>');
					$('#sd-pagination').hide();
					return;
				}

				renderRows(data.rows);
				renderPagination(data.total, data.per_page, data.paged);
			},
			error: function () {
				$loading.hide();
				$tbody.html('<tr><td colspan="11" class="sd-table-empty">Errore di rete.</td></tr>');
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

			var scubaBadge = m.is_scuba == 1 ? '✓' : '—';

			var payDate = (function() {
				if ( ! m.payment_date ) { return '—'; }
				var parts = m.payment_date.substr(0, 10).split('-');
				return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : m.payment_date.substr(0, 10);
			})();

			html += '<tr data-member-id="' + escapeAttr(m.id) + '">' +
				'<td><strong>' + escapeHtml(m.last_name) + '</strong>, ' + escapeHtml(m.first_name) + '</td>' +
				'<td>' + escapeHtml(m.email) + '</td>' +
				'<td>' + formatDate(m.date_of_birth) + '</td>' +
				'<td>CHF ' + parseFloat(m.fee_amount || 0).toFixed(2) + '</td>' +
				'<td>' + paidBadge + '</td>' +
				'<td>' + escapeHtml(payDate) + '</td>' +
				'<td>' + formatMemberType(m.member_type) + '</td>' +
				'<td>' + scubaBadge + '</td>' +
				'<td>' + escapeHtml(m.taglia_maglietta || '—') + '</td>' +
				'<td>' + escapeHtml(m.wp_role_label || '—') + '</td>' +
				'<td>' +
					'<a href="' + sdMembAdmin.editUrl + '?member_id=' + escapeAttr(m.id) + '" class="sd-btn sd-btn-secondary sd-btn-sm">Modifica</a>' +
				'</td>' +
				'</tr>';
		});

		$tbody.html(html);

		// Click riga → apre pagina modifica
		$('#sd-members-table tbody tr').on('click', function (e) {
			if ($(e.target).is('a, button')) return;
			var id = $(this).data('member-id');
			if (id) {
				window.location.href = sdMembAdmin.editUrl + '?member_id=' + id;
			}
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

		// Pulsante elimina (solo admin)
		$('#sd-edit-delete').on('click', function () {
			if (!confirm('Sei sicuro di voler eliminare questo socio? L\'operazione non può essere annullata.')) {
				return;
			}
			alert('Funzione eliminazione: contatta l\'amministratore per eliminare un socio.');
		});
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
			'attivo':              'Attivo',
			'attivo_famigliare':   'Attivo famigliare',
			'attivo_capo_famiglia':'Attivo capo famiglia',
			'sostenitore':         'Sostenitore',
			'onorario':            'Onorario',
		};
		return escapeHtml(labels[type] || type || '—');
	}

	function escapeHtml(str) {
		return $('<div>').text(String(str || '')).html();
	}

	function escapeAttr(str) {
		return String(str || '').replace(/"/g, '&quot;');
	}

})(jQuery);
