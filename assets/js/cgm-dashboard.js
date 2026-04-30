/**
 * ScubaDiabetes Logbook — Dashboard CGM Paziente
 * Shortcode: [sd_cgm_dashboard]
 *
 * Gestisce: grafico canvas, tabella paginata, toggle periodo/unità.
 * Tutti i valori in DB sono in mg/dL; la conversione mmol/L è client-side.
 */
(function ($) {
    'use strict';

    if (typeof sdCgmDash === 'undefined') { return; }

    /* ================================================================
       STATE
       ================================================================ */
    var state = {
        period:   '24h',
        page:     1,
        unit:     sdCgmDash.unit || 'mg/dl',
        dateFrom: '',
        dateTo:   '',
        total:    0
    };

    var FACTOR = 18;

    /* ================================================================
       DOM refs
       ================================================================ */
    var $tableWrap  = $('#sd-cgm-table-wrap');
    var $pagination = $('#sd-cgm-pagination');
    var $overlay    = $('#sd-cgm-chart-overlay');
    var $totalLabel = $('#sd-cgm-total-label');

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

    function isMmol() { return state.unit === 'mmol/l'; }

    function fmtVal(mg) {
        if (mg === null || mg === undefined) { return '—'; }
        return isMmol() ? parseFloat(mg / FACTOR).toFixed(1) : Math.round(mg);
    }

    function fmtUnit() { return isMmol() ? 'mmol/L' : 'mg/dL'; }

    function glucClass(v) {
        if (v < 54)  { return 'sd-gluc-very-low'; }
        if (v < 70)  { return 'sd-gluc-low'; }
        if (v <= 180){ return 'sd-gluc-normal'; }
        if (v <= 250){ return 'sd-gluc-high'; }
        return 'sd-gluc-very-high';
    }

    /* ================================================================
       TABELLA
       ================================================================ */
    var currentRows = [];

    function renderTable(rows) {
        if (!rows || rows.length === 0) {
            $tableWrap.html('<p class="sd-cgm-empty">Nessuna lettura nel periodo selezionato.</p>');
            return;
        }

        var html = '<table class="sd-cgm-table">';
        html += '<thead><tr>';
        html += '<th>Data e ora</th>';
        html += '<th>Glicemia</th>';
        html += '<th>Trend</th>';
        html += '<th>CGM</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function (r) {
            var cls = glucClass(r.value);
            html += '<tr>';
            html += '<td class="sd-cgm-td-time">' + r.time + '</td>';
            html += '<td class="sd-cgm-td-val ' + cls + '">'
                  + fmtVal(r.value)
                  + ' <span class="sd-cgm-unit-small">' + fmtUnit() + '</span>'
                  + '</td>';
            html += '<td class="sd-cgm-td-dir">' + (ARROWS[r.direction] || '—') + '</td>';
            html += '<td><span class="sd-cgm-src-badge sd-cgm-src-'
                  + (r.source || '').toLowerCase() + '">'
                  + (r.source || '—')
                  + '</span></td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        $tableWrap.html(html);
    }

    /* ================================================================
       PAGINAZIONE
       ================================================================ */
    function renderPagination(total, perPage, current) {
        if (total <= perPage) { $pagination.empty(); return; }

        var pages = Math.ceil(total / perPage);
        var html  = '<div class="sd-cgm-page-wrap">';

        if (current > 1) {
            html += '<button class="sd-cgm-page-btn" data-p="' + (current - 1) + '">‹</button>';
        }

        for (var i = 1; i <= pages; i++) {
            if (pages > 10 && Math.abs(i - current) > 2 && i !== 1 && i !== pages) {
                if (i === current - 3 || i === current + 3) {
                    html += '<span class="sd-cgm-page-ellipsis">…</span>';
                }
                continue;
            }
            html += '<button class="sd-cgm-page-btn' + (i === current ? ' active' : '')
                  + '" data-p="' + i + '">' + i + '</button>';
        }

        if (current < pages) {
            html += '<button class="sd-cgm-page-btn" data-p="' + (current + 1) + '">›</button>';
        }

        html += '</div>';
        $pagination.html(html);
    }

    /* ================================================================
       GRAFICO (canvas)
       ================================================================ */
    var canvas    = document.getElementById('sd-cgm-chart');
    var ctx       = canvas ? canvas.getContext('2d') : null;
    var chartData = [];

    function drawChart(data) {
        if (!canvas || !ctx) { return; }

        var dpr  = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        if (rect.width === 0) { return; }

        canvas.width  = rect.width  * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);

        var W  = rect.width;
        var H  = rect.height;
        var F  = isMmol() ? FACTOR : 1;
        var pad = { top: 14, right: 18, bottom: 26, left: isMmol() ? 42 : 36 };
        var cW = W - pad.left - pad.right;
        var cH = H - pad.top  - pad.bottom;

        ctx.clearRect(0, 0, W, H);
        $overlay.hide();

        var minY = 40  / F;
        var maxY = 400 / F;

        function yp(mg) {
            var v = isMmol() ? mg / F : mg;
            return pad.top + cH - ((v - minY) / (maxY - minY)) * cH;
        }

        function xp(i) {
            if (!data || data.length <= 1) { return pad.left + cW / 2; }
            return pad.left + (i / (data.length - 1)) * cW;
        }

        /* Zone sfondo */
        var zones = [
            { min: 250, max: 400, c: 'rgba(220,38,38,0.07)' },
            { min: 180, max: 250, c: 'rgba(234,88,12,0.05)'  },
            { min: 70,  max: 180, c: 'rgba(22,163,74,0.06)'  },
            { min: 40,  max: 70,  c: 'rgba(220,38,38,0.07)'  }
        ];
        zones.forEach(function (z) {
            var y1 = yp(z.max), y2 = yp(z.min);
            ctx.fillStyle = z.c;
            ctx.fillRect(pad.left, y1, cW, Math.max(0, y2 - y1));
        });

        /* Linee di riferimento + etichette Y */
        [[70, '#DC2626'], [180, '#16A34A'], [250, '#D97706']].forEach(function (ref) {
            var yy = yp(ref[0]);
            ctx.beginPath();
            ctx.strokeStyle = ref[1];
            ctx.lineWidth   = 1;
            ctx.setLineDash([5, 4]);
            ctx.moveTo(pad.left, yy);
            ctx.lineTo(W - pad.right, yy);
            ctx.stroke();
            ctx.setLineDash([]);

            ctx.fillStyle  = ref[1];
            ctx.font       = '9px -apple-system,sans-serif';
            ctx.textAlign  = 'right';
            var lbl = isMmol() ? (ref[0] / FACTOR).toFixed(1) : ref[0];
            ctx.fillText(lbl, pad.left - 3, yy + 3);
        });

        /* Nessun dato */
        if (!data || data.length === 0) {
            ctx.fillStyle = '#94a3b8';
            ctx.font      = '12px -apple-system,sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('Nessun dato nel periodo selezionato', W / 2, H / 2);
            return;
        }

        /* Gradiente riempimento */
        var grad = ctx.createLinearGradient(0, pad.top, 0, H - pad.bottom);
        grad.addColorStop(0, 'rgba(21,101,192,0.13)');
        grad.addColorStop(1, 'rgba(21,101,192,0)');

        ctx.beginPath();
        data.forEach(function (pt, i) {
            if (i === 0) { ctx.moveTo(xp(i), yp(pt[1])); }
            else         { ctx.lineTo(xp(i), yp(pt[1])); }
        });
        ctx.lineTo(xp(data.length - 1), H - pad.bottom);
        ctx.lineTo(pad.left, H - pad.bottom);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        /* Linea CGM */
        ctx.beginPath();
        ctx.strokeStyle = '#1565C0';
        ctx.lineWidth   = 1.5;
        ctx.lineJoin    = 'round';
        ctx.setLineDash([]);
        data.forEach(function (pt, i) {
            if (i === 0) { ctx.moveTo(xp(i), yp(pt[1])); }
            else         { ctx.lineTo(xp(i), yp(pt[1])); }
        });
        ctx.stroke();

        /* Punti colorati (solo se non troppi) */
        if (data.length <= 150) {
            data.forEach(function (pt, i) {
                var v = pt[1];
                var c = v < 54  ? '#7f1d1d'
                      : v < 70  ? '#DC2626'
                      : v <= 180 ? '#16A34A'
                      : v <= 250 ? '#D97706'
                      : '#9A3412';
                ctx.beginPath();
                ctx.arc(xp(i), yp(v), 2, 0, Math.PI * 2);
                ctx.fillStyle = c;
                ctx.fill();
            });
        }

        /* Etichette X: orari */
        var step = Math.max(1, Math.floor(data.length / 7));
        for (var i = 0; i < data.length; i += step) {
            var d   = new Date(data[i][0]);
            var lbl = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
            ctx.fillStyle = '#64748B';
            ctx.font      = '9px -apple-system,sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(lbl, xp(i), H - 5);
        }
    }

    /* ================================================================
       AJAX
       ================================================================ */
    function fetchData(page) {
        page        = page || 1;
        state.page  = page;

        $overlay.text('Caricamento…').show();
        $tableWrap.html('<div class="sd-cgm-loading">Caricamento…</div>');
        $pagination.empty();

        $.post(
            sdCgmDash.ajaxUrl,
            {
                action:    'sd_cgm_patient_fetch',
                nonce:     sdCgmDash.nonce,
                period:    state.period,
                page:      page,
                date_from: state.dateFrom,
                date_to:   state.dateTo
            },
            function (resp) {
                if (!resp.success) {
                    $tableWrap.html(
                        '<p class="sd-cgm-empty sd-cgm-error">'
                        + (resp.data ? resp.data.message : 'Errore.')
                        + '</p>'
                    );
                    $overlay.hide();
                    return;
                }

                var data = resp.data;
                state.total = data.total;

                /* Grafico (solo pagina 1) */
                if (page === 1) {
                    chartData = data.chart_data || [];
                    drawChart(chartData);
                }

                /* Badge totale */
                $totalLabel.text(data.total + ' letture');

                /* Tabella */
                currentRows = data.rows;
                renderTable(data.rows);

                /* Paginazione */
                renderPagination(data.total, data.per_page, page);
            }
        ).fail(function () {
            $tableWrap.html('<p class="sd-cgm-empty sd-cgm-error">Errore di rete.</p>');
            $overlay.hide();
        });
    }

    /* ================================================================
       EVENT HANDLERS
       ================================================================ */

    /* Periodo */
    $(document).on('click', '.sd-cgm-period-btn', function () {
        var period = $(this).data('period');
        $('.sd-cgm-period-btn').removeClass('active');
        $(this).addClass('active');

        if (period === 'custom') {
            $('#sd-cgm-custom-range').show();
        } else {
            $('#sd-cgm-custom-range').hide();
            state.period   = period;
            state.dateFrom = '';
            state.dateTo   = '';
            fetchData(1);
        }
    });

    $('#sd-cgm-apply-custom').on('click', function () {
        var from = $('#sd-cgm-from').val();
        var to   = $('#sd-cgm-to').val();
        if (!from || !to) {
            alert('Seleziona entrambe le date.');
            return;
        }
        state.period   = 'custom';
        state.dateFrom = from;
        state.dateTo   = to;
        fetchData(1);
    });

    /* Unità */
    $(document).on('click', '.sd-cgm-unit-btn', function () {
        var unit = $(this).data('unit');
        if (unit === state.unit) { return; }
        state.unit = unit;
        $('.sd-cgm-unit-btn').removeClass('active');
        $(this).addClass('active');
        drawChart(chartData);
        if (currentRows.length > 0) { renderTable(currentRows); }
    });

    /* Paginazione */
    $(document).on('click', '.sd-cgm-page-btn', function () {
        var p = parseInt($(this).data('p'), 10);
        if (p !== state.page) { fetchData(p); }
    });

    /* Ridimensionamento finestra */
    $(window).on('resize', function () {
        if (chartData.length > 0) { drawChart(chartData); }
    });

    /* ================================================================
       INIT
       ================================================================ */
    fetchData(1);

})(jQuery);
