/**
 * ScubaDiabetes - Research Dashboard JS
 * Filtri, tabella dati stile FOGLIO, 4 grafici Chart.js
 */
(function ($) {
    'use strict';

    var charts = {};

    // ============================================================
    // INIT: populate diver filter + auto-search current year
    // ============================================================
    $(document).ready(
        function () {
            var $sel = $('#sd-f-diver');
            sdResearch.divers.forEach(
                function (d) {
                    $sel.append('<option value="' + d.id + '">' + esc(d.name) + '</option>');
                }
            );

            // Year chips toggle date fields
            updateDatesFromYears();
            $('#sd-f-years').on('change', 'input', updateDatesFromYears);

            // Auto-search
            doSearch();
        }
    );

    function updateDatesFromYears()
    {
        var years = getSelectedYears();
        if (years.length > 0) {
            years.sort();
            $('#sd-f-date-from').val(years[0] + '-01-01');
            var lastYear = years[years.length - 1];
            var now = new Date();
            if (parseInt(lastYear) === now.getFullYear()) {
                var pad = function (n) {
                    return n < 10 ? '0' + n : n; };
                $('#sd-f-date-to').val(lastYear + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()));
            } else {
                $('#sd-f-date-to').val(lastYear + '-12-31');
            }
        }
    }

    function getSelectedYears()
    {
        var years = [];
        $('#sd-f-years input:checked').each(
            function () {
                years.push($(this).val());
            }
        );
        return years;
    }

    // ============================================================
    // SEARCH
    // ============================================================
    $('#sd-btn-search').on('click', doSearch);

    function doSearch()
    {
        var $btn = $('#sd-btn-search');
        $btn.prop('disabled', true);
        $('#sd-research-empty').hide();
        $('#sd-research-loading').show();
        $('#sd-research-stats, #sd-charts-grid, #sd-data-section').hide();

        $.post(
            sdResearch.ajaxUrl, {
                action: 'sd_research_query',
                nonce: sdResearch.nonce,
                date_from: $('#sd-f-date-from').val(),
                date_to: $('#sd-f-date-to').val(),
                diver_id: $('#sd-f-diver').val(),
                decision: $('#sd-f-decision').val(),
                glic_min: $('#sd-f-glic-min').val(),
                glic_max: $('#sd-f-glic-max').val(),
                years: getSelectedYears()
            }, function (resp) {
                $('#sd-research-loading').hide();
                $btn.prop('disabled', false);

                if (!resp.success || !resp.data.rows.length) {
                    $('#sd-research-empty').show().find('p').text('Nessun risultato con i filtri selezionati.');
                    return;
                }

                renderStats(resp.data);
                renderTable(resp.data.rows);
                renderCharts(resp.data.agg);

                $('#sd-research-stats, #sd-charts-grid, #sd-data-section').fadeIn(200);
            }
        );
    }

    // ============================================================
    // RESET
    // ============================================================
    $('#sd-btn-reset').on(
        'click', function () {
            $('#sd-f-date-from, #sd-f-date-to, #sd-f-glic-min, #sd-f-glic-max').val('');
            $('#sd-f-diver, #sd-f-decision').val('all');
            // Reset year chips to current year only
            $('#sd-f-years input').prop('checked', false);
            $('#sd-f-years input[value="' + new Date().getFullYear() + '"]').prop('checked', true);
            updateDatesFromYears();
            $('#sd-research-stats, #sd-charts-grid, #sd-data-section').hide();
            $('#sd-research-empty').show().find('p').text('Imposta i filtri e premi "Cerca" per visualizzare i dati.');
        }
    );

    // ============================================================
    // EXPORT
    // ============================================================
    $('#sd-btn-export').on(
        'click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Esportazione...');

            $.post(
                sdResearch.ajaxUrl, {
                    action: 'sd_research_export',
                    nonce: sdResearch.nonce,
                    date_from: $('#sd-f-date-from').val(),
                    date_to: $('#sd-f-date-to').val(),
                    diver_id: $('#sd-f-diver').val(),
                    decision: $('#sd-f-decision').val(),
                    glic_min: $('#sd-f-glic-min').val(),
                    glic_max: $('#sd-f-glic-max').val(),
                    years: getSelectedYears()
                }, function (resp) {
                    if (resp.success) {
                        var a = document.createElement('a');
                        a.href = resp.data.url;
                        a.download = resp.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    } else {
                        alert(resp.data.message || 'Nessun dato');
                    }
                    $btn.prop('disabled', false).html('<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export CSV');
                }
            );
        }
    );

    // ============================================================
    // RENDER STATS
    // ============================================================
    function renderStats(data)
    {
        var a = data.agg;
        $('#rs-total').text(data.count);
        $('#rs-auth').text(a.decisions.autorizzata);
        $('#rs-susp').text(a.decisions.sospesa);
        $('#rs-canc').text(a.decisions.annullata);
        $('#rs-hypo').text(a.hypo_count);
    }

    // ============================================================
    // RENDER TABLE (grouped by date like FOGLIO)
    // ============================================================
    function renderTable(rows)
    {
        var $body = $('#sd-table-body');
        $body.empty();
        $('#sd-data-count').text(rows.length + ' record');

        var lastDate = '';
        rows.forEach(
            function (r) {
                // Day separator
                if (r.dive_date !== lastDate) {
                    lastDate = r.dive_date;
                    var dayName = new Date(r.dive_date + 'T00:00:00').toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                    $body.append('<tr class="sd-day-row"><td colspan="25">' + dayName.charAt(0).toUpperCase() + dayName.slice(1) + '</td></tr>');
                }

                var html = '<tr>';
                // Sub data
                html += '<td style="text-align:left;font-weight:700;">' + esc(r.diver_name) + '</td>';
                html += '<td>' + fmtDate(r.dive_date) + '</td>';
                html += '<td style="text-align:left;">' + esc(r.site_name) + '</td>';
                html += '<td>' + (r.max_depth ? r.max_depth + 'm' : '') + (r.dive_time ? '/' + r.dive_time + '\'' : '') + '</td>';

                // 4 checkpoints
                var cps = ['60', '30', '10', 'post'];
                cps.forEach(
                    function (cp) {
                        var val = r['glic_' + cp + '_value'];
                        var met = r['glic_' + cp + '_method'];
                        var trend = r['glic_' + cp + '_trend'];
                        var choR = r['glic_' + cp + '_cho_rapidi'];
                        var choL = r['glic_' + cp + '_cho_lenti'];
                        var ins = r['glic_' + cp + '_insulin'];
                        var notes = r['glic_' + cp + '_notes'];

                        // Glic value
                        if (val) {
                            var cls = glicClass(val);
                            html += '<td class="' + cls + '">' + val + '</td>';
                        } else {
                            html += '<td></td>';
                        }

                        // Method + trend
                        if (met) {
                            var tArrow = '';
                            if (met === 'S' && trend) {
                                var arrows = { salita_rapida: '↑↑', salita: '↑', stabile: '→', discesa: '↓', discesa_rapida: '↓↓' };
                                tArrow = arrows[trend] || '';
                            }
                            html += '<td><span class="sd-tm sd-tm-' + met + '">' + met + '</span>' + (tArrow ? ' ' + tArrow : '') + '</td>';
                        } else {
                            html += '<td></td>';
                        }

                        // CHO
                        var cho = [];
                        if (choR && parseFloat(choR) > 0) { cho.push('R:' + choR);
                        }
                        if (choL && parseFloat(choL) > 0) { cho.push('L:' + choL);
                        }
                        html += '<td>' + cho.join(' ') + '</td>';

                        // INS
                        html += '<td>' + (ins && parseFloat(ins) > 0 ? ins + 'U' : '') + '</td>';

                        // Notes
                        html += '<td style="max-width:80px;overflow:hidden;text-overflow:ellipsis;" title="' + esc(notes || '') + '">' + esc(notes || '') + '</td>';
                    }
                );

                // Decision
                var dec = r.dive_decision || '';
                var dCls = dec === 'autorizzata' ? 'sd-td-aut' : (dec === 'sospesa' ? 'sd-td-sos' : (dec === 'annullata' ? 'sd-td-ann' : ''));
                html += '<td class="' + dCls + '">' + (dec ? dec.substring(0, 3).toUpperCase() : '') + '</td>';

                html += '</tr>';
                $body.append(html);
            }
        );
    }

    function glicClass(val)
    {
        val = parseInt(val);
        if (val < 70) { return 'sd-gv-danger';
        }
        if (val < 120) { return 'sd-gv-low';
        }
        if (val <= 250) { return 'sd-gv-target';
        }
        if (val <= 300) { return 'sd-gv-high';
        }
        return 'sd-gv-danger';
    }

    // ============================================================
    // RENDER CHARTS
    // ============================================================
    function renderCharts(agg)
    {

        // Destroy existing
        Object.keys(charts).forEach(
            function (k) {
                if (charts[k]) { charts[k].destroy();
                } }
        );

        var font = { family: "'Segoe UI', 'Helvetica', sans-serif", size: 11 };

        // 1) CHECKPOINT BAR
        charts.checkpoint = new Chart(
            document.getElementById('chart-checkpoint'), {
                type: 'bar',
                data: {
                    labels: ['-60 min', '-30 min', '-10 min', 'POST'],
                    datasets: [{
                        label: 'Media glicemia (mg/dl)',
                        data: [agg.by_checkpoint['-60'], agg.by_checkpoint['-30'], agg.by_checkpoint['-10'], agg.by_checkpoint['POST']],
                        backgroundColor: ['#0097A7', '#1976D2', '#7B1FA2', '#E65100'],
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: false,
                            min: 50,
                            ticks: { font: font }
                        },
                        x: { ticks: { font: font } }
                    }
                }
            }
        );

        // 2) DISTRIBUTION DOUGHNUT
        var rng = agg.glic_ranges;
        charts.distribution = new Chart(
            document.getElementById('chart-distribution'), {
                type: 'doughnut',
                data: {
                    labels: ['<70 (ipo)', '70-119', '120-149', '150-250 (target)', '251-300', '>300'],
                    datasets: [{
                        data: [rng['<70'], rng['70-119'], rng['120-149'], rng['150-250'], rng['251-300'], rng['>300']],
                        backgroundColor: ['#DC2626', '#F59E0B', '#3B82F6', '#16A34A', '#F97316', '#991B1B'],
                        borderWidth: 2,
                        borderColor: '#FFF'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'right', labels: { font: font, boxWidth: 12, padding: 6 } }
                    }
                }
            }
        );

        // 3) DECISIONS PIE
        var dec = agg.decisions;
        charts.decisions = new Chart(
            document.getElementById('chart-decisions'), {
                type: 'pie',
                data: {
                    labels: ['Autorizzata', 'Sospesa', 'Annullata'],
                    datasets: [{
                        data: [dec.autorizzata, dec.sospesa, dec.annullata],
                        backgroundColor: ['#16A34A', '#F59E0B', '#DC2626'],
                        borderWidth: 2,
                        borderColor: '#FFF'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'right', labels: { font: font, boxWidth: 12, padding: 6 } }
                    }
                }
            }
        );

        // 4) TIMELINE LINE
        if (agg.timeline.length > 0) {
            charts.timeline = new Chart(
                document.getElementById('chart-timeline'), {
                    type: 'line',
                    data: {
                        labels: agg.timeline.map(
                            function (t) {
                                return fmtDate(t.date); }
                        ),
                    datasets: [{
                        label: 'Media glicemia (mg/dl)',
                        data: agg.timeline.map(
                            function (t) {
                                return t.avg; }
                        ),
                        borderColor: '#1976D2',
                        backgroundColor: 'rgba(25,118,210,0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointBackgroundColor: '#1976D2'
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                beginAtZero: false,
                                min: 50,
                                ticks: { font: font },
                                grid: { color: function (ctx) {
                                    var v = ctx.tick.value;
                                    if (v === 120 || v === 150 || v === 250) { return '#F59E0B55';
                                    }
                                    return '#E5E7EB';
                                }}
                            },
                            x: { ticks: { font: font, maxRotation: 45 } }
                        }
                    }
                }
            );
        }

        // 5) YEAR COMPARISON - grouped bar
        var byYear = agg.by_year || {};
        var years = Object.keys(byYear).sort();
        if (years.length > 0) {
            var yearColors = ['#0097A7', '#1976D2', '#7B1FA2', '#E65100', '#16A34A', '#DC2626'];
            var yearDatasets = years.map(
                function (yr, i) {
                    var yd = byYear[yr];
                    return {
                        label: yr + ' (n=' + yd.count + ')',
                        data: [yd['-60'], yd['-30'], yd['-10'], yd['POST']],
                        backgroundColor: yearColors[i % yearColors.length] + 'CC',
                        borderColor: yearColors[i % yearColors.length],
                        borderWidth: 1,
                        borderRadius: 4
                    };
                }
            );

            charts.yearCompare = new Chart(
                document.getElementById('chart-year-compare'), {
                    type: 'bar',
                    data: {
                        labels: ['-60 min', '-30 min', '-10 min', 'POST'],
                        datasets: yearDatasets
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'top', labels: { font: font, boxWidth: 14, padding: 10 } }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                min: 50,
                                ticks: { font: font },
                                grid: { color: function (ctx) {
                                    var v = ctx.tick.value;
                                    if (v === 120 || v === 150) { return '#F59E0B44';
                                    }
                                    if (v === 250) { return '#DC262644';
                                    }
                                    return '#E5E7EB';
                                }}
                            },
                            x: { ticks: { font: font } }
                        }
                    }
                }
            );
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================
    function fmtDate(s)
    {
        if (!s) { return '';
        }
        var p = s.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function esc(s)
    {
        if (!s) { return '';
        }
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

})(jQuery);
