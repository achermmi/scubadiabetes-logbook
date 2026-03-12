/**
 * ScubaDiabetes Logbook - Sezione Diabete JS
 * Toggle C/S, frecce trend, grafico glicemico, decisione protocollo
 *
 * Supporto unità: mg/dL ↔ mmol/L con switch live nel form
 * - DB salva SEMPRE in mg/dL
 * - Il JS converte al volo quando l'utente cambia unità
 */
(function ($) {
    'use strict';

    $(document).ready(
        function () {

            // ============================================================
            // UNIT STATE
            // ============================================================
            var FACTOR = 18.018;
            var currentUnit = $('#sd-glycemia-input-unit').val() || 'mg/dl';

            function isMmol()
            {
                return currentUnit === 'mmol/l'; }

            function toMgDl(val)
            {
                if (!val || isNaN(val)) { return 0;
                }
                return isMmol() ? Math.round(parseFloat(val) * FACTOR) : parseInt(val);
            }

            function fromMgDl(val)
            {
                return isMmol() ? (val / FACTOR).toFixed(1) : String(val);
            }

            function unitLabel()
            {
                return isMmol() ? 'mmol/L' : 'mg/dL'; }

            // Unit-specific config
            function unitConfig()
            {
                if (isMmol()) {
                    return { min: 1.0, max: 28.0, step: 0.1, placeholder: '8.3', inputmode: 'decimal',
                        chartMin: 2.8, chartMax: 19.4 };
                }
                return { min: 20, max: 500, step: 1, placeholder: '150', inputmode: 'numeric',
                    chartMin: 50, chartMax: 350 };
            }

            // ============================================================
            // APPLY UNIT to all UI elements
            // ============================================================
            function applyUnitToUI()
            {
                var cfg = unitConfig();
                var label = unitLabel();

                // Update checkpoint labels
                $('.sd-glic-label').each(
                    function () {
                        $(this).text('GLIC (' + label.toUpperCase() + ')');
                    }
                );

                // Update input attributes
                $('.sd-glic-input').each(
                    function () {
                        $(this).attr(
                            {
                                min: cfg.min,
                                max: cfg.max,
                                step: cfg.step,
                                placeholder: cfg.placeholder,
                                inputmode: cfg.inputmode
                            }
                        );
                    }
                );

                // Update chart legend
                updateLegend();

                // Redraw chart
                drawChart();
            }

            function updateLegend()
            {
                var $legend = $('#sd-chart-legend');
                if (isMmol()) {
                    $legend.html(
                        '<span class="sd-chart-zone sd-zone-high">&gt;16.7</span>' +
                        '<span class="sd-chart-zone sd-zone-warn">13.9</span>' +
                        '<span class="sd-chart-zone sd-zone-ok">8.3-13.9</span>' +
                        '<span class="sd-chart-zone sd-zone-low-ok">6.7-8.3</span>' +
                        '<span class="sd-chart-zone sd-zone-danger">&lt;6.7</span>'
                    );
                } else {
                    $legend.html(
                        '<span class="sd-chart-zone sd-zone-high">&gt;300</span>' +
                        '<span class="sd-chart-zone sd-zone-warn">250</span>' +
                        '<span class="sd-chart-zone sd-zone-ok">150-250</span>' +
                        '<span class="sd-chart-zone sd-zone-low-ok">120-150</span>' +
                        '<span class="sd-chart-zone sd-zone-danger">&lt;120</span>'
                    );
                }
            }

            // ============================================================
            // UNIT TOGGLE click handler
            // Converts existing values when switching
            // ============================================================
            $(document).on(
                'click', '.sd-unit-btn-inline', function (e) {
                    e.preventDefault();
                    var newUnit = $(this).data('unit');
                    if (newUnit === currentUnit) { return;
                    }

                    var oldIsMmol = isMmol();

                    // Convert existing values
                    $('.sd-glic-input').each(
                        function () {
                            var raw = parseFloat($(this).val());
                            if (!raw || isNaN(raw)) { return;
                            }

                            var mgdl;
                            if (oldIsMmol) {
                                mgdl = raw * FACTOR;
                            } else {
                                mgdl = raw;
                            }

                            // Convert to new unit
                            if (newUnit === 'mmol/l') {
                                $(this).val((mgdl / FACTOR).toFixed(1));
                            } else {
                                $(this).val(Math.round(mgdl));
                            }
                        }
                    );

                    // Update state
                    currentUnit = newUnit;
                    $('#sd-glycemia-input-unit').val(newUnit);

                    // Update toggle buttons
                    $('.sd-unit-btn-inline').removeClass('active');
                    $(this).addClass('active');

                    // Save preference to profile via AJAX (fire and forget)
                    if (typeof sdLogbook !== 'undefined') {
                        $.post(
                            sdLogbook.ajaxUrl, {
                                action: 'sd_save_glycemia_unit',
                                nonce: sdLogbook.nonce,
                                glycemia_unit: newUnit
                            }
                        );
                    }

                    // Refresh all UI
                    applyUnitToUI();
                    evaluateDecision();
                }
            );

            // ============================================================
            // METHOD TOGGLE C / S
            // ============================================================
            $(document).on(
                'click', '.sd-method-btn', function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $toggle = $btn.closest('.sd-method-toggle');
                    var cp = $toggle.data('cp');
                    var value = $btn.data('value');
                    var fieldName = $btn.data('name');

                    if ($btn.hasClass('active')) {
                        $btn.removeClass('active');
                        $('input[name="' + fieldName + '"]').val('');
                        $('.sd-trend-field[data-cp="' + cp + '"]').slideUp(150);
                    } else {
                        $toggle.find('.sd-method-btn').removeClass('active');
                        $btn.addClass('active');
                        $('input[name="' + fieldName + '"]').val(value);

                        if (value === 'S') {
                            $('.sd-trend-field[data-cp="' + cp + '"]').slideDown(150);
                        } else {
                            $('.sd-trend-field[data-cp="' + cp + '"]').slideUp(150);
                            $('input[name="glic_' + cp + '_trend"]').val('');
                            $('.sd-trend-select[data-cp="' + cp + '"] .sd-trend-btn').removeClass('active');
                        }
                    }
                }
            );

            // ============================================================
            // TREND ARROW BUTTONS
            // ============================================================
            $(document).on(
                'click', '.sd-trend-btn', function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $group = $btn.closest('.sd-trend-select');
                    var cp = $group.data('cp');
                    var value = $btn.data('value');

                    if ($btn.hasClass('active')) {
                        $btn.removeClass('active');
                        $('input[name="glic_' + cp + '_trend"]').val('');
                    } else {
                        $group.find('.sd-trend-btn').removeClass('active');
                        $btn.addClass('active');
                        $('input[name="glic_' + cp + '_trend"]').val(value);
                    }
                }
            );

            // ============================================================
            // GLYCEMIA CHART (Canvas) — unit-aware, reads current state
            // ============================================================
            var chartCanvas = document.getElementById('sd-glycemia-chart');
            var ctx = chartCanvas ? chartCanvas.getContext('2d') : null;

            function drawChart()
            {
                if (!chartCanvas || !ctx) { return;
                }

                var cfg = unitConfig();
                var dpr = window.devicePixelRatio || 1;
                var rect = chartCanvas.getBoundingClientRect();
                chartCanvas.width = rect.width * dpr;
                chartCanvas.height = rect.height * dpr;
                ctx.scale(dpr, dpr);

                var W = rect.width;
                var H = rect.height;
                var padding = { top: 15, right: 20, bottom: 25, left: isMmol() ? 52 : 45 };
                var chartW = W - padding.left - padding.right;
                var chartH = H - padding.top - padding.bottom;

                ctx.clearRect(0, 0, W, H);

                var minG = cfg.chartMin;
                var maxG = cfg.chartMax;

                function yFor(val)
                {
                    return padding.top + chartH - ((val - minG) / (maxG - minG)) * chartH;
                }

                // Threshold values in current unit
                var t300 = isMmol() ? 300 / FACTOR : 300;
                var t250 = isMmol() ? 250 / FACTOR : 250;
                var t150 = isMmol() ? 150 / FACTOR : 150;
                var t120 = isMmol() ? 120 / FACTOR : 120;
                var t100 = isMmol() ? 100 / FACTOR : 100;

                // Background zones
                var zones = [
                { min: t300, max: maxG, color: 'rgba(220,38,38,0.08)' },
                { min: t250, max: t300, color: 'rgba(234,88,12,0.06)' },
                { min: t150, max: t250, color: 'rgba(22,163,74,0.08)' },
                { min: t120, max: t150, color: 'rgba(37,99,235,0.08)' },
                { min: minG, max: t120, color: 'rgba(220,38,38,0.08)' }
                ];
                zones.forEach(
                    function (z) {
                        ctx.fillStyle = z.color;
                        ctx.fillRect(padding.left, yFor(z.max), chartW, yFor(z.min) - yFor(z.max));
                    }
                );

                // Reference lines
                var refLines = [
                    { val: t300, color: '#DC2626', dash: [4, 4] },
                    { val: t250, color: '#D97706', dash: [4, 4] },
                    { val: t150, color: '#16A34A', dash: [6, 3] },
                    { val: t120, color: '#2563EB', dash: [6, 3] },
                    { val: t100, color: '#DC2626', dash: [2, 4] }
                ];
                refLines.forEach(
                    function (l) {
                        var y = yFor(l.val);
                        ctx.beginPath();
                        ctx.setLineDash(l.dash);
                        ctx.strokeStyle = l.color;
                        ctx.lineWidth = 1;
                        ctx.moveTo(padding.left, y);
                        ctx.lineTo(W - padding.right, y);
                        ctx.stroke();
                        ctx.setLineDash([]);

                        ctx.fillStyle = l.color;
                        ctx.font = '10px -apple-system, sans-serif';
                        ctx.textAlign = 'right';
                        var lbl = isMmol() ? (l.val).toFixed(1) : Math.round(l.val);
                        ctx.fillText(lbl, padding.left - 4, y + 3);
                    }
                );

                // X axis
                var xLabels = ['-60', '-30', '-10', 'POST'];
                var xKeys = ['60', '30', '10', 'post'];
                var xPositions = xLabels.map(
                    function (_, i) {
                        return padding.left + (i / (xLabels.length - 1)) * chartW;
                    }
                );

                ctx.fillStyle = '#64748B';
                ctx.font = 'bold 11px -apple-system, sans-serif';
                ctx.textAlign = 'center';
                xLabels.forEach(
                    function (label, i) {
                        ctx.fillText(label, xPositions[i], H - 6);
                    }
                );

                // Vertical grid
                xPositions.forEach(
                    function (x) {
                        ctx.beginPath();
                        ctx.strokeStyle = '#E2E8F0';
                        ctx.lineWidth = 1;
                        ctx.moveTo(x, padding.top);
                        ctx.lineTo(x, H - padding.bottom);
                        ctx.stroke();
                    }
                );

                // Plot data points
                var values = [];
                var hasAny = false;
                xKeys.forEach(
                    function (key, i) {
                        var rawVal = parseFloat($('input[name="glic_' + key + '_value"]').val());
                        if (rawVal && rawVal >= minG && rawVal <= cfg.max) {
                            var displayVal = isMmol() ? rawVal.toFixed(1) : Math.round(rawVal);
                            values.push({ x: xPositions[i], y: yFor(Math.min(rawVal, maxG)), val: displayVal, key: key });
                            hasAny = true;
                        } else {
                            values.push(null);
                        }
                    }
                );

                if (!hasAny) { return;
                }

                var validPoints = values.filter(
                    function (v) {
                        return v !== null; }
                );
                if (validPoints.length > 1) {
                    ctx.beginPath();
                    ctx.strokeStyle = '#0B3D6E';
                    ctx.lineWidth = 2.5;
                    ctx.lineJoin = 'round';
                    validPoints.forEach(
                        function (p, i) {
                            if (i === 0) { ctx.moveTo(p.x, p.y);
                            } else { ctx.lineTo(p.x, p.y);
                            }
                        }
                    );
                    ctx.stroke();
                }

                validPoints.forEach(
                    function (p) {
                        ctx.beginPath();
                        ctx.fillStyle = '#0B3D6E';
                        ctx.arc(p.x, p.y, 5, 0, Math.PI * 2);
                        ctx.fill();
                        ctx.beginPath();
                        ctx.fillStyle = '#FFF';
                        ctx.arc(p.x, p.y, 2.5, 0, Math.PI * 2);
                        ctx.fill();

                        ctx.fillStyle = '#0B3D6E';
                        ctx.font = 'bold 12px -apple-system, sans-serif';
                        ctx.textAlign = 'center';
                        var labelY = p.y - 10;
                        if (labelY < padding.top + 10) { labelY = p.y + 18;
                        }
                        ctx.fillText(p.val, p.x, labelY);

                        var method = $('input[name="glic_' + p.key + '_method"]').val();
                        if (method) {
                            ctx.font = 'bold 9px -apple-system, sans-serif';
                            ctx.fillStyle = method === 'C' ? '#DC2626' : '#0097A7';
                            ctx.fillText(method, p.x + 14, p.y + 4);
                        }
                    }
                );
            }

            // Chart event bindings
            $(document).on(
                'input change', '.sd-glic-input', function () {
                    drawChart();
                    evaluateDecision();
                }
            );
            $(document).on(
                'click', '.sd-method-btn', function () {
                    setTimeout(drawChart, 50);
                }
            );
            $(window).on('resize', drawChart);

            // ============================================================
            // DECISIONE IMMERSIONE (Protocollo DS)
            // Soglie sempre in mg/dL
            // ============================================================
            function evaluateDecision()
            {
                var g60  = toMgDl($('input[name="glic_60_value"]').val());
                var g30  = toMgDl($('input[name="glic_30_value"]').val());
                var g10  = toMgDl($('input[name="glic_10_value"]').val());

                if (!g10) {
                    $('#sd-decision-bar').slideUp(150);
                    $('#sd-dive-decision').val('');
                    return;
                }

                var decision = '', reason = '', cssClass = '', icon = '';
                var uL = unitLabel();
                var v120 = fromMgDl(120), v150 = fromMgDl(150);
                var v250 = fromMgDl(250), v300 = fromMgDl(300);

                // Trend
                var trend = 'stabile';
                if (g30 && g10) {
                    var diff = g10 - g30;
                    var pct = Math.abs(diff) / g30 * 100;
                    if (diff > 0 && pct > 15) { trend = 'salita';
                    } else if (diff < 0 && pct > 15) { trend = 'discesa';
                    }
                }
                if (g60 && g30 && g10) {
                    if ((g30 - g60) < 0 && (g10 - g30) < 0) { trend = 'discesa';
                    } else if ((g30 - g60) > 0 && (g10 - g30) > 0) { trend = 'salita';
                    }
                }

                if (g10 < 120) {
                    decision = 'annullata';
                    reason = 'Glicemia <' + v120 + ' ' + uL + ': immersione NON consentita';
                    cssClass = 'sd-decision-stop'; icon = '⛔';
                } else if (trend === 'discesa') {
                    decision = 'sospesa';
                    reason = 'Glicemia in discesa: immersione sospesa';
                    cssClass = 'sd-decision-stop'; icon = '🛑';
                } else if (g10 > 300 && trend === 'salita') {
                    decision = 'sospesa';
                    reason = 'Glicemia >' + v300 + ' in salita: rinvio immersione';
                    cssClass = 'sd-decision-stop'; icon = '🛑';
                } else if (g10 >= 250 && g10 <= 300 && trend !== 'salita') {
                    decision = 'autorizzata';
                    reason = 'Glicemia ' + v250 + '-' + v300 + ' stabile, no chetonemia: OK';
                    cssClass = 'sd-decision-caution'; icon = '⚠️';
                } else if (trend === 'salita' && g10 >= 120) {
                    decision = 'autorizzata';
                    reason = 'Glicemia ≥' + v120 + ' in salita: OK';
                    cssClass = 'sd-decision-ok'; icon = '✅';
                } else if (trend === 'stabile' && g10 >= 150) {
                    decision = 'autorizzata';
                    reason = 'Glicemia ≥' + v150 + ' stabile: OK';
                    cssClass = 'sd-decision-ok'; icon = '✅';
                } else if (trend === 'stabile' && g10 >= 120 && g10 < 150) {
                    decision = 'autorizzata';
                    reason = 'Glicemia ' + v120 + '-' + v150 + ' stabile: attenzione, considerare snack';
                    cssClass = 'sd-decision-caution'; icon = '⚠️';
                } else {
                    decision = 'autorizzata';
                    reason = 'Valori nei range consentiti';
                    cssClass = 'sd-decision-ok'; icon = '✅';
                }

                $('#sd-dive-decision').val(decision);
                $('#sd-dive-decision-reason').val(reason);
                $('#sd-decision-icon').text(icon);
                $('#sd-decision-text').text(reason);
                $('#sd-decision-bar')
                .removeClass('sd-decision-ok sd-decision-caution sd-decision-stop')
                .addClass(cssClass).slideDown(200);
            }

            // ============================================================
            // INIT: apply unit UI on load
            // ============================================================
            applyUnitToUI();
            setTimeout(drawChart, 100);

        }
    );
})(jQuery);
