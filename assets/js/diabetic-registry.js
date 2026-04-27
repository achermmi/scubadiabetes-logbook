/* global jQuery, sdDiabeticRegistry */
/**
 * Registro Soci Diabetici — diabetic-registry.js
 * Filtri, rendering tabella, righe espandibili, export CSV.
 */
(function ($) {
    'use strict';

    /* =========================================================
       LABEL HELPERS (mirror di medical-panel.js)
       ========================================================= */

    function diabetesLabel(v) {
        var map = {
            tipo_1: 'Tipo 1',
            tipo_2: 'Tipo 2',
            tipo_3c: 'Tipo 3c',
            lada: 'LADA',
            mody: 'MODY',
            midd: 'MIDD',
            non_specificato: 'Non specificato',
            altro: 'Altro'
        };
        return map[v] || v || '';
    }

    function therapyLabel(v) {
        var map = {
            mdi: 'MDI',
            csii: 'CSII',
            ahcl: 'AHCL',
            ipoglicemizzante_orale: 'Orale',
            iniettiva_non_insulinica: 'Iniettiva non insulinica',
            none: 'Non specificata'
        };
        return map[v] || v || '';
    }

    function therapyDetailLabel(v) {
        var map = {
            basale_bolo: 'Basale + Bolo',
            bolo_only: 'Solo Bolo',
            basale_only: 'Solo Basale',
            metformina: 'Metformina',
            sulfanilurea: 'Sulfanilurea',
            glitazoni: 'Glitazoni',
            gliptin: 'Gliptin',
            sglt2: 'SGLT-2',
            glp1: 'GLP-1',
            glp1_insulina: 'GLP-1 + Insulina',
            altro: 'Altro'
        };
        return map[v] || v || '';
    }

    function hba1cUnitLabel(v) {
        var map = { percent: '%', mmol_mol: 'mmol/mol' };
        return map[v] || v || '';
    }

    function memberTypeLabel(v) {
        var map = {
            attivo: 'Attivo',
            attivo_capo_famiglia: 'Capofamiglia',
            attivo_famigliare: 'Famigliare',
            onorario: 'Onorario'
        };
        return map[v] || v || '';
    }

    function yesNo(v) {
        if (v === '1' || v === 1 || v === true) { return 'Sì'; }
        if (v === '0' || v === 0 || v === false) { return 'No'; }
        return '';
    }

    function isFilled(v) {
        return v !== null && v !== undefined && String(v).trim() !== '' && v !== '0000-00-00' && v !== '0000-00-00 00:00:00';
    }

    function displayValue(v) {
        return isFilled(v) ? esc(String(v)) : '<span class="sd-empty">Non compilato</span>';
    }

    function esc(s) {
        if (!s) { return ''; }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(d) {
        if (!d || d === '0000-00-00') { return ''; }
        var parts = String(d).split('T')[0].split('-');
        if (parts.length !== 3) { return d; }
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function yearFromDate(d) {
        if (!d) { return '—'; }
        return String(d).split('-')[0] || '—';
    }

    /* =========================================================
       BADGE DI TIPO DIABETE
       ========================================================= */
    function diabetesBadge(v) {
        if (!v) { return '<span class="sd-dreg-badge sd-dreg-badge-default">—</span>'; }
        return '<span class="sd-dreg-badge sd-dreg-badge-' + esc(v) + '">' + esc(diabetesLabel(v)) + '</span>';
    }

    function therapyBadge(v) {
        if (!v) { return '<span class="sd-dreg-badge sd-dreg-badge-default">—</span>'; }
        var cls = 'sd-dreg-badge-' + esc(v);
        return '<span class="sd-dreg-badge ' + cls + '">' + esc(therapyLabel(v)) + '</span>';
    }

    /* =========================================================
       RENDERING
       ========================================================= */

    /**
     * Costruisce l'HTML di una riga principale.
     */
    function buildRow(r, idx) {
        var center = isFilled(r.profile_center) ? r.profile_center : (r.member_center || '');
        var hba1c  = isFilled(r.hba1c_last)
            ? esc(r.hba1c_last) + ' ' + esc(hba1cUnitLabel(r.hba1c_unit))
            : '—';

        var cgmHtml;
        if (!isFilled(r.uses_cgm)) {
            cgmHtml = '<span class="sd-dreg-cgm-no">—</span>';
        } else if (r.uses_cgm === '1' || r.uses_cgm === 1) {
            cgmHtml = '<span class="sd-dreg-cgm-yes">✓' + (isFilled(r.cgm_device) ? ' ' + esc(r.cgm_device) : '') + '</span>';
        } else {
            cgmHtml = '<span class="sd-dreg-cgm-no">No</span>';
        }

        var diabType = isFilled(r.diabetes_type) ? r.diabetes_type : '';

        return '<tr class="sd-dreg-row" data-idx="' + idx + '">' +
            '<td><span class="sd-dreg-expand-icon">' +
                '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>' +
            '</span></td>' +
            '<td>' +
                '<div class="sd-dreg-member-name">' + esc(r.first_name) + ' ' + esc(r.last_name) + '</div>' +
                (isFilled(r.email) ? '<div class="sd-dreg-member-email">' + esc(r.email) + '</div>' : '') +
            '</td>' +
            '<td>' + diabetesBadge(diabType) + '</td>' +
            '<td>' + therapyBadge(r.therapy_type) + '</td>' +
            '<td>' + esc(center || '—') + '</td>' +
            '<td>' + hba1c + '</td>' +
            '<td>' + cgmHtml + '</td>' +
            '<td>' + esc(memberTypeLabel(r.member_type)) + '</td>' +
            '<td>' + esc(yearFromDate(r.member_since)) + '</td>' +
        '</tr>';
    }

    /**
     * Costruisce la riga di dettaglio espanso.
     */
    function buildDetailRow(r, colSpan) {
        function field(label, val) {
            return '<div class="sd-dreg-detail-field">' +
                '<span class="sd-dreg-detail-label">' + esc(label) + '</span>' +
                '<span class="sd-dreg-detail-value">' + val + '</span>' +
            '</div>';
        }

        var pumpText = '';
        if (isFilled(r.insulin_pump_model)) {
            pumpText = r.insulin_pump_model === 'altro' && isFilled(r.insulin_pump_model_other)
                ? r.insulin_pump_model_other
                : r.insulin_pump_model;
        }

        var therapyDetailText = '';
        if (isFilled(r.therapy_detail)) {
            therapyDetailText = therapyDetailLabel(r.therapy_detail);
            if (r.therapy_detail === 'altro' && isFilled(r.therapy_detail_other)) {
                therapyDetailText += ': ' + r.therapy_detail_other;
            }
        }

        var detailHtml =
            field('Tipo diabete',         isFilled(r.diabetes_type) ? esc(diabetesLabel(r.diabetes_type)) : '<span class="sd-empty">Non compilato</span>') +
            field('Centro diabetologico', displayValue(isFilled(r.profile_center) ? r.profile_center : r.member_center)) +
            field('Terapia',              isFilled(r.therapy_type) ? esc(therapyLabel(r.therapy_type)) : '<span class="sd-empty">Non compilato</span>') +
            field('Dettaglio terapia',    isFilled(therapyDetailText) ? esc(therapyDetailText) : '<span class="sd-empty">Non compilato</span>') +
            field('HbA1c',               isFilled(r.hba1c_last) ? esc(r.hba1c_last) + ' ' + esc(hba1cUnitLabel(r.hba1c_unit)) : '<span class="sd-empty">Non compilato</span>') +
            field('Data HbA1c',          isFilled(r.hba1c_date) ? esc(formatDate(r.hba1c_date)) : '<span class="sd-empty">Non compilato</span>') +
            field('CGM',                 isFilled(r.uses_cgm) ? esc(yesNo(r.uses_cgm)) : '<span class="sd-empty">Non compilato</span>') +
            field('Dispositivo CGM',     displayValue(r.cgm_device)) +
            field('Microinfusore',       isFilled(pumpText) ? esc(pumpText) : '<span class="sd-empty">Non compilato</span>') +
            field('Unità glicemia',      displayValue(r.glycemia_unit)) +
            field('Tipo socio',          esc(memberTypeLabel(r.member_type))) +
            field('Iscritto dal',        isFilled(r.member_since) ? esc(formatDate(r.member_since)) : '<span class="sd-empty">Non compilato</span>') +
            field('Scadenza tesseramento', isFilled(r.membership_expiry) ? esc(formatDate(r.membership_expiry)) : '<span class="sd-empty">Non compilato</span>') +
            field('Note diabetologiche', isFilled(r.notes) ? esc(r.notes) : '<span class="sd-empty">Non compilato</span>');

        return '<tr class="sd-dreg-detail-row" style="display:none;">' +
            '<td colspan="' + colSpan + '">' +
                '<div class="sd-dreg-detail-inner">' +
                    '<div class="sd-dreg-detail-grid">' + detailHtml + '</div>' +
                '</div>' +
            '</td>' +
        '</tr>';
    }

    /**
     * Aggiorna il tbody con l'array di record.
     */
    function renderTable(rows) {
        var $tbody = $('#sd-dreg-tbody');
        var $table = $('#sd-dreg-table');
        var $empty = $('#sd-dreg-empty');
        var $stats = $('#sd-dreg-stats');

        $tbody.empty();

        if (!rows || rows.length === 0) {
            $table.hide();
            $empty.show();
            $stats.hide();
            return;
        }

        var colSpan = $table.find('thead th').length;
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            html += buildRow(rows[i], i);
            html += buildDetailRow(rows[i], colSpan);
        }
        $tbody.html(html);
        $table.show();
        $empty.hide();

        $('#sd-dreg-count-label').text(
            rows.length === 1
                ? '1 socio diabetico trovato'
                : rows.length + ' soci diabetici trovati'
        );
        $stats.show();
    }

    /* =========================================================
       FETCH DATI
       ========================================================= */
    var fetchXhr = null;

    function fetchData() {
        if (fetchXhr) { fetchXhr.abort(); }

        var params = {
            action: 'sd_diabetic_registry_data',
            nonce:  sdDiabeticRegistry.nonce,
            search:       $.trim($('#dreg-search').val()),
            diabetes_type:$.trim($('#dreg-diabetes-type').val()),
            therapy_type: $.trim($('#dreg-therapy').val()),
            uses_cgm:     $.trim($('#dreg-cgm').val()),
            member_type:  $.trim($('#dreg-member-type').val()),
            year:         $.trim($('#dreg-year').val())
        };

        $('#sd-dreg-loading').show();
        $('#sd-dreg-table').hide();
        $('#sd-dreg-empty').hide();
        $('#sd-dreg-stats').hide();

        fetchXhr = $.post(sdDiabeticRegistry.ajaxUrl, params, function (res) {
            $('#sd-dreg-loading').hide();
            if (res && res.success && res.data && res.data.rows) {
                renderTable(res.data.rows);
            } else {
                renderTable([]);
            }
        }).fail(function () {
            $('#sd-dreg-loading').hide();
            renderTable([]);
        });
    }

    /* =========================================================
       EXPORT CSV (client-side)
       ========================================================= */
    function buildCsv(rows) {
        var cols = [
            'Cognome', 'Nome', 'Email',
            'Tipo diabete', 'Centro diabetologico', 'Terapia', 'Dettaglio terapia',
            'HbA1c', 'Unità HbA1c', 'Data HbA1c',
            'CGM', 'Dispositivo CGM', 'Microinfusore',
            'Unità glicemia', 'Tipo socio', 'Anno iscrizione', 'Note'
        ];

        function csvCell(v) {
            v = v == null ? '' : String(v).replace(/"/g, '""');
            return '"' + v + '"';
        }

        var lines = [cols.map(csvCell).join(',')];

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var center = isFilled(r.profile_center) ? r.profile_center : (r.member_center || '');
            var pump = isFilled(r.insulin_pump_model)
                ? (r.insulin_pump_model === 'altro' && isFilled(r.insulin_pump_model_other)
                    ? r.insulin_pump_model_other : r.insulin_pump_model)
                : '';
            lines.push([
                r.last_name, r.first_name, r.email,
                diabetesLabel(r.diabetes_type), center, therapyLabel(r.therapy_type),
                isFilled(r.therapy_detail) ? therapyDetailLabel(r.therapy_detail) : '',
                r.hba1c_last || '', hba1cUnitLabel(r.hba1c_unit),
                formatDate(r.hba1c_date),
                yesNo(r.uses_cgm), r.cgm_device || '', pump,
                r.glycemia_unit || '', memberTypeLabel(r.member_type),
                yearFromDate(r.member_since), r.notes || ''
            ].map(csvCell).join(','));
        }

        return '\uFEFF' + lines.join('\r\n'); // BOM per Excel
    }

    function exportCsv() {
        // raccoglie le righe già caricate
        var rows = [];
        $('#sd-dreg-tbody tr.sd-dreg-row').each(function () {
            var idx = $(this).data('idx');
            if (idx !== undefined) { rows.push(window._sdDregRows[idx]); }
        });
        if (!rows.length) { return; }

        var csv  = buildCsv(rows);
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href     = url;
        a.download = 'registro_diabetici.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /* =========================================================
       CACHE ROWS PER EXPORT
       ========================================================= */
    // Sovrascriviamo renderTable per salvare i dati
    var _origRender = renderTable;
    renderTable = function (rows) {
        window._sdDregRows = rows || [];
        _origRender(rows);
    };

    /* =========================================================
       INIT
       ========================================================= */
    $(function () {
        // caricamento iniziale
        fetchData();

        // Filtro su click "Filtra"
        $('#sd-dreg-btn-search').on('click', function () {
            fetchData();
        });

        // Filtro su Enter nel campo testo
        $('#dreg-search').on('keypress', function (e) {
            if (e.which === 13) { fetchData(); }
        });

        // Auto-fetch su cambio select (con piccolo debounce)
        var autoTimer;
        $('#dreg-diabetes-type, #dreg-therapy, #dreg-cgm, #dreg-member-type, #dreg-year')
            .on('change', function () {
                clearTimeout(autoTimer);
                autoTimer = setTimeout(fetchData, 250);
            });

        // Reset
        $('#sd-dreg-btn-reset').on('click', function () {
            $('#dreg-search').val('');
            $('#dreg-diabetes-type, #dreg-therapy, #dreg-cgm, #dreg-member-type, #dreg-year').val('');
            fetchData();
        });

        // Export
        $('#sd-dreg-btn-export').on('click', exportCsv);

        // Toggle dettaglio su click riga
        $(document).on('click', '#sd-dreg-tbody tr.sd-dreg-row', function () {
            var $row    = $(this);
            var $detail = $row.next('tr.sd-dreg-detail-row');

            if ($row.hasClass('sd-dreg-expanded')) {
                $row.removeClass('sd-dreg-expanded');
                $detail.hide();
            } else {
                // chiudi altri aperti
                $('#sd-dreg-tbody tr.sd-dreg-row.sd-dreg-expanded')
                    .removeClass('sd-dreg-expanded')
                    .next('tr.sd-dreg-detail-row').hide();
                $row.addClass('sd-dreg-expanded');
                $detail.show();
            }
        });
    });

}(jQuery));
