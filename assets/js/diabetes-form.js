/**
 * ScubaDiabetes Logbook - Sezione Diabete JS
 * Toggle C/S, frecce trend, grafico glicemico, decisione protocollo
 *
 * Supporto unità: mg/dL ↔ mmol/L con switch live nel form
 * - DB salva SEMPRE in mg/dL
 * - Il JS converte al volo quando l'utente cambia unità
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // ============================================================
        // UNIT STATE
        // ============================================================
        var FACTOR = 18.018;
        var currentUnit = $('#sd-glycemia-input-unit').val() || 'mg/dl';

        function isMmol() { return currentUnit === 'mmol/l'; }

        function toMgDl(val) {
            if (!val || isNaN(val)) return 0;
            return isMmol() ? Math.round(parseFloat(val) * FACTOR) : parseInt(val);
        }

        function fromMgDl(val) {
            return isMmol() ? (val / FACTOR).toFixed(1) : String(val);
        }

        function unitLabel() { return isMmol() ? 'mmol/L' : 'mg/dL'; }

        // Unit-specific config
        function unitConfig() {
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
        function applyUnitToUI() {
            var cfg = unitConfig();
            var label = unitLabel();

            // Update checkpoint labels (capillare e sensore)
            $('.sd-glic-label-cap').each(function() {
                $(this).text('Capillare (' + label.toUpperCase() + ')');
            });
            $('.sd-glic-label-sens').each(function() {
                $(this).text('Sensore (' + label.toUpperCase() + ')');
            });

            // Update input attributes for both cap and sens
            $('.sd-glic-cap, .sd-glic-sens').each(function() {
                $(this).attr({
                    min: cfg.min,
                    max: cfg.max,
                    step: cfg.step,
                    placeholder: cfg.placeholder,
                    inputmode: cfg.inputmode
                });
            });

            // Update chart legend
            updateLegend();

            // Redraw chart
            drawChart();
        }

        function updateLegend() {
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
        $(document).on('click', '.sd-unit-btn-inline', function(e) {
            e.preventDefault();
            var newUnit = $(this).data('unit');
            if (newUnit === currentUnit) return;

            var oldIsMmol = isMmol();

            // Convert existing values
            $('.sd-glic-cap, .sd-glic-sens').each(function() {
                var raw = parseFloat($(this).val());
                if (!raw || isNaN(raw)) return;

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
            });

            // Update state
            currentUnit = newUnit;
            $('#sd-glycemia-input-unit').val(newUnit);

            // Update toggle buttons
            $('.sd-unit-btn-inline').removeClass('active');
            $(this).addClass('active');

            // Save preference to profile via AJAX (fire and forget)
            if (typeof sdLogbook !== 'undefined') {
                $.post(sdLogbook.ajaxUrl, {
                    action: 'sd_save_glycemia_unit',
                    nonce: sdLogbook.nonce,
                    glycemia_unit: newUnit
                });
            }

            // Refresh all UI
            applyUnitToUI();
            evaluateDecision();
        });

        // ============================================================
        // TREND ARROW BUTTONS
        // ============================================================
        $(document).on('click', '.sd-trend-btn', function(e) {
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
        });

        // ============================================================
        // GLYCEMIA CHART (Canvas) — unit-aware, reads current state
        // ============================================================
        var chartCanvas = document.getElementById('sd-glycemia-chart');
        var ctx = chartCanvas ? chartCanvas.getContext('2d') : null;
        var hitPoints = [];

        var momentoLabelMap = {
            '60':        '-60 MIN',
            '30':        '-30 MIN',
            '10':        '-10 MIN',
            'post':      'POST',
            'prima_60':  '- 90 MIN',
            'prima_30':  '- 45 MIN',
            'prima_10':  '- 20 MIN',
            'prima_post':'- 5 MIN',
            'dopo_post': '+ 30 MIN',
        };

        function drawChart() {
            if (!chartCanvas || !ctx) return;

            var cfg = unitConfig();
            var dpr = window.devicePixelRatio || 1;
            var rect = chartCanvas.getBoundingClientRect();
            chartCanvas.width = rect.width * dpr;
            chartCanvas.height = rect.height * dpr;
            ctx.scale(dpr, dpr);

            var W = rect.width;
            var H = rect.height;
            var padding = { top: 15, right: 65, bottom: 25, left: isMmol() ? 52 : 45 };
            var chartW = W - padding.left - padding.right;
            var chartH = H - padding.top - padding.bottom;

            ctx.clearRect(0, 0, W, H);
            hitPoints = [];

            var minG = cfg.chartMin;
            var maxG = cfg.chartMax;

            function yFor(val) {
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
            zones.forEach(function(z) {
                ctx.fillStyle = z.color;
                ctx.fillRect(padding.left, yFor(z.max), chartW, yFor(z.min) - yFor(z.max));
            });

            // Reference lines
            var refLines = [
                { val: t300, color: '#DC2626', dash: [4, 4] },
                { val: t250, color: '#D97706', dash: [4, 4] },
                { val: t150, color: '#16A34A', dash: [6, 3] },
                { val: t120, color: '#2563EB', dash: [6, 3] },
                { val: t100, color: '#DC2626', dash: [2, 4] }
            ];
            refLines.forEach(function(l) {
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
            });

            // X axis — 6 posizioni su griglia a 5 slot con margine interno
            // xMargin: buffer in px per P.-60 e D.Post (non finiscono ai bordi assoluti)
            var totalSlots = 5;
            var xMargin    = 18;
            function slotX(s) {
                var usable = chartW - 2 * xMargin;
                return padding.left + xMargin + (s / totalSlots) * usable;
            }

            var xMainSlots  = [1, 2, 3, 4];
            var xLabels     = ['-60', '-30', '-10', 'POST'];
            var xKeys       = ['60', '30', '10', 'post'];
            var xPositions  = xMainSlots.map(slotX);

            var extraXMap = {
                prima_60:   slotX(0),
                prima_30:   slotX(1.5),
                prima_10:   slotX(2.5),
                prima_post: slotX(3.5),
                dopo_post:  slotX(5),
            };

            // Etichette asse X: principali + bordi extra
            var allXLabels = [
                { x: slotX(0),  label: '-90',   small: true  },
                { x: slotX(1),  label: '-60',   small: false },
                { x: slotX(2),  label: '-30',   small: false },
                { x: slotX(3),  label: '-10',   small: false },
                { x: slotX(4),  label: 'POST',  small: false },
                { x: slotX(5),  label: '+30',   small: true  },
            ];
            allXLabels.forEach(function(item) {
                ctx.fillStyle = item.small ? '#A0AEC0' : '#64748B';
                ctx.font = item.small ? '9px -apple-system, sans-serif' : 'bold 11px -apple-system, sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(item.label, item.x, H - 6);
            });

            // Vertical grid (solo punti principali)
            xPositions.forEach(function(x) {
                ctx.beginPath();
                ctx.strokeStyle = '#E2E8F0';
                ctx.lineWidth = 1;
                ctx.moveTo(x, padding.top);
                ctx.lineTo(x, H - padding.bottom);
                ctx.stroke();
            });

            // Linee verticali punteggiate per posizioni extra
            [slotX(0), slotX(5)].forEach(function(x) {
                ctx.beginPath();
                ctx.strokeStyle = '#E9D5A0';
                ctx.lineWidth = 1;
                ctx.setLineDash([3, 4]);
                ctx.moveTo(x, padding.top);
                ctx.lineTo(x, H - padding.bottom);
                ctx.stroke();
                ctx.setLineDash([]);
            });

            // Build cap and sens data arrays
            var capData = [];
            var sensData = [];
            var hasAnyCap = false, hasAnySens = false;
            xKeys.forEach(function(key, i) {
                var capVal = parseFloat($('input[name="glic_' + key + '_cap"]').val());
                var sensVal = parseFloat($('input[name="glic_' + key + '_sens"]').val());
                if (capVal && capVal >= minG && capVal <= cfg.max) {
                    capData.push({ x: xPositions[i], y: yFor(Math.min(capVal, maxG)), val: isMmol() ? capVal.toFixed(1) : Math.round(capVal), key: key });
                    hasAnyCap = true;
                } else { capData.push(null); }
                if (sensVal && sensVal >= minG && sensVal <= cfg.max) {
                    sensData.push({ x: xPositions[i], y: yFor(Math.min(sensVal, maxG)), val: isMmol() ? sensVal.toFixed(1) : Math.round(sensVal), key: key });
                    hasAnySens = true;
                } else { sensData.push(null); }
            });

            // Controlla se ci sono valori anche sugli extra prima di rinunciare a disegnare
            var hasAnyExtra = false;
            ['extra1','extra2','extra3','extra4'].forEach(function(k) {
                var m = $('select[name="glic_' + k + '_when"]').val();
                if (!m) return;
                var cv = parseFloat($('input[name="glic_' + k + '_cap"]').val());
                var sv = parseFloat($('input[name="glic_' + k + '_sens"]').val());
                if ((cv && cv >= minG) || (sv && sv >= minG)) hasAnyExtra = true;
            });
            if (!hasAnyCap && !hasAnySens && !hasAnyExtra) return;

            // Draw capillare line (blue, solid)
            if (hasAnyCap) {
                var validCap = capData.filter(function(v) { return v !== null; });
                if (validCap.length > 1) {
                    ctx.beginPath();
                    ctx.strokeStyle = '#0B3D6E';
                    ctx.lineWidth = 2.5;
                    ctx.lineJoin = 'round';
                    ctx.setLineDash([]);
                    validCap.forEach(function(p, i) {
                        if (i === 0) ctx.moveTo(p.x, p.y); else ctx.lineTo(p.x, p.y);
                    });
                    ctx.stroke();
                }
            }

            // Draw sensore line (teal, dashed)
            if (hasAnySens) {
                var validSens = sensData.filter(function(v) { return v !== null; });
                if (validSens.length > 1) {
                    ctx.beginPath();
                    ctx.strokeStyle = '#0097A7';
                    ctx.lineWidth = 2;
                    ctx.lineJoin = 'round';
                    ctx.setLineDash([5, 3]);
                    validSens.forEach(function(p, i) {
                        if (i === 0) ctx.moveTo(p.x, p.y); else ctx.lineTo(p.x, p.y);
                    });
                    ctx.stroke();
                    ctx.setLineDash([]);
                }
            }

            // Draw main checkpoint points
            xKeys.forEach(function(key, i) {
                var cp = capData[i];
                var sp = sensData[i];
                if (cp) {
                    // Filled circle — capillare
                    ctx.beginPath();
                    ctx.fillStyle = '#0B3D6E';
                    ctx.arc(cp.x, cp.y, 7, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.beginPath();
                    ctx.fillStyle = '#FFF';
                    ctx.arc(cp.x, cp.y, 3, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.fillStyle = '#0B3D6E';
                    ctx.font = 'bold 11px -apple-system, sans-serif';
                    ctx.textAlign = 'center';
                    var capLY = cp.y - 14;
                    if (capLY < padding.top + 10) capLY = cp.y + 19;
                    ctx.fillText(cp.val, cp.x, capLY);
                    hitPoints.push({ x: cp.x, y: cp.y, r: 10, val: cp.val, tipo: 'Capillare', momento: momentoLabelMap[key] || key, color: '#0B3D6E' });
                }
                if (sp) {
                    // Hollow circle — sensore
                    ctx.beginPath();
                    ctx.strokeStyle = '#0097A7';
                    ctx.fillStyle = '#EEF9FA';
                    ctx.lineWidth = 2.5;
                    ctx.arc(sp.x, sp.y, 6, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.stroke();
                    ctx.fillStyle = '#0097A7';
                    ctx.font = 'bold 11px -apple-system, sans-serif';
                    ctx.textAlign = 'center';
                    var sensLY = cp ? sp.y + 20 : sp.y - 14;
                    if (sensLY > H - padding.bottom - 5) sensLY = sp.y - 14;
                    if (sensLY < padding.top + 10)       sensLY = sp.y + 20;
                    ctx.fillText(sp.val, sp.x, sensLY);
                    hitPoints.push({ x: sp.x, y: sp.y, r: 9, val: sp.val, tipo: 'Sensore', momento: momentoLabelMap[key] || key, color: '#0097A7' });
                }
            });

            // ---- Punti EXTRA (diamanti) ----
            var extraList = [
                { key: 'extra1', color: '#92400E', label: 'Extra 1' },
                { key: 'extra2', color: '#B45309', label: 'Extra 2' },
                { key: 'extra3', color: '#D97706', label: 'Extra 3' },
                { key: 'extra4', color: '#F59E0B', label: 'Extra 4' },
            ];

            function drawDiamond(x, y, size, fillColor, strokeColor) {
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(x, y - size);
                ctx.lineTo(x + size * 0.7, y);
                ctx.lineTo(x, y + size);
                ctx.lineTo(x - size * 0.7, y);
                ctx.closePath();
                if (fillColor)   { ctx.fillStyle   = fillColor;   ctx.fill(); }
                if (strokeColor) { ctx.strokeStyle = strokeColor; ctx.lineWidth = 1.5; ctx.stroke(); }
                ctx.restore();
            }

            extraList.forEach(function(ex) {
                var momento = $('select[name="glic_' + ex.key + '_when"]').val();
                if (!momento) return;
                var xE = extraXMap[momento];
                if (xE === undefined) return;
                var momentoLabel = momentoLabelMap[momento] || momento;

                var capVal  = parseFloat($('input[name="glic_' + ex.key + '_cap"]').val());
                var sensVal = parseFloat($('input[name="glic_' + ex.key + '_sens"]').val());

                if (capVal && capVal >= minG && capVal <= cfg.max) {
                    var yC = yFor(Math.min(capVal, maxG));
                    var xC = xE - 8;
                    drawDiamond(xC, yC, 8, ex.color, '#FFF');
                    var capLbl = isMmol() ? capVal.toFixed(1) : Math.round(capVal);
                    var capLY  = yC - 13;
                    if (capLY < padding.top + 8) capLY = yC + 18;
                    ctx.fillStyle = ex.color;
                    ctx.font = 'bold 11px -apple-system, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText(capLbl, xC, capLY);
                    hitPoints.push({ x: xC, y: yC, r: 11, val: capLbl, tipo: 'Capillare', momento: ex.label + ' · ' + momentoLabel, color: ex.color });
                }

                if (sensVal && sensVal >= minG && sensVal <= cfg.max) {
                    var yS = yFor(Math.min(sensVal, maxG));
                    var xS = xE + 8;
                    drawDiamond(xS, yS, 8, '#FFF', ex.color);
                    var sensLbl = isMmol() ? sensVal.toFixed(1) : Math.round(sensVal);
                    var sensLY  = yS + 18;
                    if (sensLY > H - padding.bottom - 5) sensLY = yS - 13;
                    if (sensLY < padding.top + 8)        sensLY = yS + 18;
                    ctx.fillStyle = ex.color;
                    ctx.font = 'bold 11px -apple-system, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText(sensLbl, xS, sensLY);
                    hitPoints.push({ x: xS, y: yS, r: 11, val: sensLbl, tipo: 'Sensore', momento: ex.label + ' · ' + momentoLabel, color: ex.color });
                }
            });
        }

        // Chart event bindings
        $(document).on('input change', '.sd-glic-cap, .sd-glic-sens, .sd-extra-when-select', function() {
            drawChart();
            evaluateDecision();
        });
        $(window).on('resize', drawChart);

        // ===== CHART TOOLTIP =====
        var $tooltip = $('#sd-glycemia-tooltip');
        if (chartCanvas) {
            chartCanvas.addEventListener('mousemove', function(e) {
                if (!hitPoints.length) { $tooltip.hide(); return; }
                var rect = chartCanvas.getBoundingClientRect();
                var scaleX = chartCanvas.width  / (rect.width  * (window.devicePixelRatio || 1));
                var scaleY = chartCanvas.height / (rect.height * (window.devicePixelRatio || 1));
                var mx = (e.clientX - rect.left);
                var my = (e.clientY - rect.top);
                var found = null, minDist = 22;
                hitPoints.forEach(function(p) {
                    var dx = p.x - mx, dy = p.y - my;
                    var d  = Math.sqrt(dx * dx + dy * dy);
                    if (d < minDist) { minDist = d; found = p; }
                });
                if (found) {
                    var unit = unitLabel();
                    var tooltipLeft = mx + 14;
                    if (tooltipLeft + 180 > rect.width) tooltipLeft = mx - 190;
                    $tooltip
                        .html('<strong style="color:' + found.color + ';">' + found.tipo + '</strong> &nbsp; <strong>' + found.val + ' ' + unit + '</strong><br>' +
                              '<span style="opacity:0.75;font-size:11px;">' + found.momento + '</span>')
                        .css({ left: tooltipLeft + 'px', top: my + 'px', display: 'block',
                               'border-left-color': found.color });
                    chartCanvas.style.cursor = 'crosshair';
                } else {
                    $tooltip.hide();
                    chartCanvas.style.cursor = 'default';
                }
            });
            chartCanvas.addEventListener('mouseleave', function() {
                $tooltip.hide();
                chartCanvas.style.cursor = 'default';
            });
        }

        // ============================================================
        // DECISIONE IMMERSIONE (Protocollo DS)
        // Soglie sempre in mg/dL
        // ============================================================
        function evaluateDecision() {
            // Usa capillare come valore primario, fallback al sensore
            var g60  = toMgDl($('input[name="glic_60_cap"]').val())  || toMgDl($('input[name="glic_60_sens"]').val());
            var g30  = toMgDl($('input[name="glic_30_cap"]').val())  || toMgDl($('input[name="glic_30_sens"]').val());
            var g10  = toMgDl($('input[name="glic_10_cap"]').val())  || toMgDl($('input[name="glic_10_sens"]').val());

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
                if (diff > 0 && pct > 15) trend = 'salita';
                else if (diff < 0 && pct > 15) trend = 'discesa';
            }
            if (g60 && g30 && g10) {
                if ((g30 - g60) < 0 && (g10 - g30) < 0) trend = 'discesa';
                else if ((g30 - g60) > 0 && (g10 - g30) > 0) trend = 'salita';
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

    });
})(jQuery);
