/**
 * ScubaDiabetes Logbook — Pannello CGM Medico
 * Shortcode: [sd_cgm_medical]
 *
 * Gestisce: filtri, tabella paginata, statistiche.
 * Mostra sempre mg/dL e mmol/L in colonne separate.
 */
(function ($) {
    'use strict';

    if (typeof sdCgmMedical === 'undefined') { return; }

    /* ================================================================
       STATE
       ================================================================ */
    var state = {
        page:  1,
        total: 0
    };

    /* ================================================================
       HELPERS
       ================================================================ */
    var ARROWS = {
        TripleUp:      '↑↑↑',
        DoubleUp:      '↑↑',
        SingleUp:      '↑',
        FortyFiveUp:   '↗',
        Flat:          '→',
        FortyFiveDown: '↘',
        SingleDown:    '↓',
        DoubleDown:    '↓↓',
        TripleDown:    '↓↓↓',
        NONE:          '—'
    };

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function glucClass(v) {
        if (v < 54)  { return 'sd-gluc-very-low'; }
        if (v < 70)  { return 'sd-gluc-low'; }
        if (v <= 180){ return 'sd-gluc-normal'; }
        if (v <= 250){ return 'sd-gluc-high'; }
        return 'sd-gluc-very-high';
    }

    function getFilters() {
        return {
            search:    $('#sd-cgm-m-search').val(),
            date_from: $('#sd-cgm-m-from').val(),
            date_to:   $('#sd-cgm-m-to').val(),
            filter:    $('#sd-cgm-m-filter').val(),
            cgm_type:  $('#sd-cgm-m-cgm-type').val()
        };
    }

    /* ================================================================
       TABELLA
       ================================================================ */
    function renderTable(rows) {
        var $wrap = $('#sd-cgm-m-table-wrap');

        if (!rows || rows.length === 0) {
            $wrap.html('<p class="sd-cgm-empty">Nessuna lettura trovata con i filtri selezionati.</p>');
            return;
        }

        var html = '<table class="sd-cgm-table">';
        html += '<thead><tr>';
        html += '<th>Paziente</th>';
        html += '<th>Data e ora</th>';
        html += '<th>mg/dL</th>';
        html += '<th>mmol/L</th>';
        html += '<th>Trend</th>';
        html += '<th>CGM</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function (r) {
            var cls  = glucClass(r.value);
            var mmol = parseFloat(r.value / 18).toFixed(1);

            html += '<tr class="' + cls + '-row">';
            html += '<td class="sd-cgm-td-name"><strong>' + escHtml(r.name) + '</strong></td>';
            html += '<td class="sd-cgm-td-time">' + r.time + '</td>';
            html += '<td class="sd-cgm-td-val ' + cls + '">' + r.value + '</td>';
            html += '<td class="sd-cgm-td-val ' + cls + '">' + mmol + '</td>';
            html += '<td class="sd-cgm-td-dir">' + (ARROWS[r.direction] || '—') + '</td>';
            html += '<td><span class="sd-cgm-src-badge sd-cgm-src-'
                  + escHtml((r.source || '').toLowerCase()) + '">'
                  + escHtml(r.source || '—')
                  + '</span></td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        $wrap.html(html);
    }

    /* ================================================================
       PAGINAZIONE
       ================================================================ */
    function renderPagination(total, perPage, current) {
        var $pag = $('#sd-cgm-m-pagination');
        if (total <= perPage) { $pag.empty(); return; }

        var pages = Math.ceil(total / perPage);
        var html  = '<div class="sd-cgm-page-wrap">';

        if (current > 1) {
            html += '<button class="sd-cgm-page-btn sd-cgm-m-page-btn" data-p="' + (current - 1) + '">‹</button>';
        }

        for (var i = 1; i <= pages; i++) {
            if (pages > 10 && Math.abs(i - current) > 2 && i !== 1 && i !== pages) {
                if (i === current - 3 || i === current + 3) {
                    html += '<span class="sd-cgm-page-ellipsis">…</span>';
                }
                continue;
            }
            html += '<button class="sd-cgm-page-btn sd-cgm-m-page-btn'
                  + (i === current ? ' active' : '')
                  + '" data-p="' + i + '">' + i + '</button>';
        }

        if (current < pages) {
            html += '<button class="sd-cgm-page-btn sd-cgm-m-page-btn" data-p="' + (current + 1) + '">›</button>';
        }

        html += '</div>';
        $pag.html(html);
    }

    /* ================================================================
       AJAX
       ================================================================ */
    function fetchData(page) {
        page       = page || 1;
        state.page = page;

        var filters = getFilters();
        $('#sd-cgm-m-table-wrap').html('<div class="sd-cgm-loading">Caricamento…</div>');
        $('#sd-cgm-m-pagination').empty();

        $.post(
            sdCgmMedical.ajaxUrl,
            $.extend({ action: 'sd_cgm_medical_fetch', nonce: sdCgmMedical.nonce, page: page }, filters),
            function (resp) {
                if (!resp.success) {
                    $('#sd-cgm-m-table-wrap').html(
                        '<p class="sd-cgm-empty sd-cgm-error">'
                        + (resp.data ? escHtml(resp.data.message) : 'Errore.')
                        + '</p>'
                    );
                    return;
                }

                var data = resp.data;
                state.total = data.total;

                /* Badge contatore */
                $('#sd-cgm-m-count').text(data.total + ' letture');

                /* Statistiche (solo pagina 1) */
                if (data.stats) {
                    $('#sd-cgm-m-stat-total').text(data.stats.total);
                    $('#sd-cgm-m-stat-users').text(data.stats.users);
                    $('#sd-cgm-m-stat-anom').text(data.stats.anomalous);
                    $('#sd-cgm-m-stat-pct').text(data.stats.pct_anom + '%');
                    $('#sd-cgm-m-stats').show();
                }

                renderTable(data.rows);
                renderPagination(data.total, data.per_page, page);
            }
        ).fail(function () {
            $('#sd-cgm-m-table-wrap').html('<p class="sd-cgm-empty sd-cgm-error">Errore di rete.</p>');
        });
    }

    /* ================================================================
       EVENT HANDLERS
       ================================================================ */

    $('#sd-cgm-m-apply').on('click', function () { fetchData(1); });

    $('#sd-cgm-m-reset').on('click', function () {
        $('#sd-cgm-m-search').val('');
        $('#sd-cgm-m-from').val('');
        $('#sd-cgm-m-to').val('');
        $('#sd-cgm-m-filter').val('all');
        $('#sd-cgm-m-cgm-type').val('');
        fetchData(1);
    });

    /* Invio con tasto Enter nel campo ricerca */
    $('#sd-cgm-m-search').on('keypress', function (e) {
        if (e.which === 13) { fetchData(1); }
    });

    /* Paginazione */
    $(document).on('click', '.sd-cgm-m-page-btn', function () {
        var p = parseInt($(this).data('p'), 10);
        if (p !== state.page) { fetchData(p); }
    });

    /* ================================================================
       INIT: carica prima pagina subito
       ================================================================ */
    fetchData(1);

})(jQuery);
