/**
 * ScubaDiabetes Logbook - Dashboard JS
 * Modale dettaglio, export CSV, elimina immersione
 */
(function($) {
    'use strict';

    var currentDiveId = null;

    // ============================================================
    // UNIT HELPERS — valori dal DB sono sempre in mg/dL
    // ============================================================
    var isMmol = (typeof sdDashboard !== 'undefined' && sdDashboard.glycemiaUnit === 'mmol/l');
    var FACTOR = 18.018;

    function displayGlic(mgdlVal) {
        if (!mgdlVal) return '';
        if (isMmol) return (parseFloat(mgdlVal) / FACTOR).toFixed(1);
        return mgdlVal;
    }

    function glicUnitLabel() {
        return isMmol ? 'mmol/L' : 'mg/dL';
    }

    $(document).ready(function() {

        // ============================================================
        // OPEN DETAIL MODAL
        // ============================================================
        $(document).on('click', '.sd-dive-card, .sd-btn-detail', function(e) {
            e.stopPropagation();
            var diveId = $(this).closest('.sd-dive-card').data('dive-id') || $(this).data('dive-id');
            if (!diveId) return;
            currentDiveId = diveId;
            openDetailModal(diveId);
        });

        function openDetailModal(diveId) {
            var $overlay = $('#sd-modal-overlay');
            var $body = $('#sd-modal-body');

            $body.html('<div class="sd-loading">Caricamento...</div>');
            $overlay.fadeIn(200);

            $.ajax({
                url: sdDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sd_get_dive_detail',
                    nonce: sdDashboard.nonce,
                    dive_id: diveId
                },
                success: function(response) {
                    if (response.success) {
                        renderDetail(response.data.dive, response.data.diabetes);
                    } else {
                        $body.html('<p style="color:#DC2626;">Errore nel caricamento</p>');
                    }
                },
                error: function() {
                    $body.html('<p style="color:#DC2626;">Errore di connessione</p>');
                }
            });
        }

        function renderDetail(dive, diabetes) {
            var html = '';

            // Title
            $('#sd-modal-title').text('Immersione #' + (dive.dive_number || dive.id) + ' — ' + dive.site_name);

            // Dive data
            html += '<div class="sd-detail-grid">';
            html += detailItem('Data', formatDate(dive.dive_date));
            html += detailItem('Sito', dive.site_name);
            html += detailItem('Ora ingresso', dive.time_in || '—');
            html += detailItem('Ora uscita', dive.time_out || '—');
            html += detailItem('Prof. max', dive.max_depth ? dive.max_depth + ' m' : '—');
            html += detailItem('Prof. media', dive.avg_depth ? dive.avg_depth + ' m' : '—');
            html += detailItem('Tempo', dive.dive_time ? dive.dive_time + ' min' : '—');
            html += detailItem('Miscela', dive.gas_mix || '—');
            html += detailItem('Bar inizio', dive.pressure_start || '—');
            html += detailItem('Bar fine', dive.pressure_end || '—');
            html += detailItem('Zavorra', dive.ballast_kg ? dive.ballast_kg + ' kg' : '—');
            html += detailItem('Protezione', dive.suit_type || '—');
            html += detailItem('Ingresso', dive.entry_type || '—');
            html += detailItem('Meteo', dive.weather || '—');
            html += detailItem('Temp. aria', dive.temp_air ? dive.temp_air + '°C' : '—');
            html += detailItem('Temp. acqua', dive.temp_water ? dive.temp_water + '°C' : '—');
            html += detailItem('Mare', dive.sea_condition || '—');
            html += detailItem('Corrente', dive.current_strength || '—');
            html += detailItem('Visibilità', dive.visibility || '—');
            html += detailItem('Compagno', dive.buddy_name || '—');
            html += detailItem('Guida', dive.guide_name || '—');
            html += '</div>';

            if (dive.sightings) {
                html += '<div class="sd-detail-item" style="grid-column:1/-1;"><div class="sd-detail-label">Cosa ho visto</div><div class="sd-detail-value">' + esc(dive.sightings) + '</div></div>';
            }
            if (dive.notes) {
                html += '<div class="sd-detail-item" style="grid-column:1/-1;"><div class="sd-detail-label">Note</div><div class="sd-detail-value">' + esc(dive.notes) + '</div></div>';
            }

            // Diabetes data
            if (diabetes) {
                html += '<div class="sd-detail-section-title">Glicemie e Provvedimenti</div>';

                var checkpoints = [
                    { key: '60', label: '-60 min' },
                    { key: '30', label: '-30 min' },
                    { key: '10', label: '-10 min' },
                    { key: 'post', label: 'POST' }
                ];

                checkpoints.forEach(function(cp) {
                    var val = diabetes['glic_' + cp.key + '_value'];
                    var method = diabetes['glic_' + cp.key + '_method'];
                    var trend = diabetes['glic_' + cp.key + '_trend'];
                    var cho_r = diabetes['glic_' + cp.key + '_cho_rapidi'];
                    var cho_l = diabetes['glic_' + cp.key + '_cho_lenti'];
                    var ins = diabetes['glic_' + cp.key + '_insulin'];
                    var notes = diabetes['glic_' + cp.key + '_notes'];

                    if (val) {
                        var methodBadge = method ? '<span class="sd-detail-method-badge sd-method-' + method + '">' + method + '</span>' : '';
                        var trendArrow = '';
                        if (trend && method === 'S') {
                            var arrows = { 'salita_rapida': '↑↑', 'salita': '↑', 'stabile': '→', 'discesa': '↓', 'discesa_rapida': '↓↓' };
                            trendArrow = arrows[trend] || '';
                        }

                        var extras = [];
                        if (cho_r && parseFloat(cho_r) > 0) extras.push('CHOr: ' + cho_r + 'g');
                        if (cho_l && parseFloat(cho_l) > 0) extras.push('CHOl: ' + cho_l + 'g');
                        if (ins && parseFloat(ins) > 0) extras.push('INS: ' + ins + 'U');
                        if (notes) extras.push(notes);

                        html += '<div class="sd-detail-glic-row">';
                        html += '<span class="sd-detail-glic-label">' + cp.label + '</span>';
                        html += '<span class="sd-detail-glic-value">' + displayGlic(val) + '</span>';
                        html += '<span>' + methodBadge + ' ' + trendArrow + '</span>';
                        html += '<span style="font-size:11px;color:#64748B;">' + extras.join(' · ') + '</span>';
                        html += '</div>';
                    }
                });

                // Decision — regenerate reason in user's unit from DB values (always mg/dL)
                if (diabetes.dive_decision) {
                    var decColors = { 'autorizzata': '#16A34A', 'sospesa': '#D97706', 'annullata': '#DC2626' };
                    var decReason = generateDecisionReason(diabetes);
                    html += '<div style="margin-top:10px;padding:8px 12px;border-radius:6px;background:' + (decColors[diabetes.dive_decision] || '#64748B') + '15;border:1px solid ' + (decColors[diabetes.dive_decision] || '#64748B') + '40;">';
                    html += '<strong style="color:' + (decColors[diabetes.dive_decision] || '#64748B') + ';">' + diabetes.dive_decision.toUpperCase() + '</strong>';
                    if (decReason) {
                        html += ' — <span style="font-size:12px;">' + esc(decReason) + '</span>';
                    }
                    html += '</div>';
                }
            }

            $('#sd-modal-body').html(html);
        }

        function detailItem(label, value) {
            return '<div class="sd-detail-item"><div class="sd-detail-label">' + esc(label) + '</div><div class="sd-detail-value">' + esc(value) + '</div></div>';
        }

        function formatDate(dateStr) {
            if (!dateStr) return '—';
            var parts = dateStr.split('-');
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }

        function esc(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        // ============================================================
        // CLOSE MODAL
        // ============================================================
        $('#sd-modal-close, #sd-btn-close-modal, #sd-modal-overlay').on('click', function(e) {
            if (e.target === this) {
                $('#sd-modal-overlay').fadeOut(200);
                currentDiveId = null;
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('#sd-modal-overlay').fadeOut(200);
                currentDiveId = null;
            }
        });

        // ============================================================
        // DELETE DIVE
        // ============================================================
        $('#sd-btn-delete-dive').on('click', function() {
            if (!currentDiveId) return;
            if (!confirm('Sei sicuro di voler eliminare questa immersione? L\'azione non è reversibile.')) return;

            $.ajax({
                url: sdDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sd_delete_dive',
                    nonce: sdDashboard.nonce,
                    dive_id: currentDiveId
                },
                success: function(response) {
                    if (response.success) {
                        // Rimuovi card dalla lista
                        $('.sd-dive-card[data-dive-id="' + currentDiveId + '"]').fadeOut(300, function() {
                            $(this).remove();
                        });
                        $('#sd-modal-overlay').fadeOut(200);
                        currentDiveId = null;
                    } else {
                        alert(response.data.message || 'Errore');
                    }
                }
            });
        });

        // ============================================================
        // EXPORT CSV
        // ============================================================
        $('#sd-btn-export').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Esportazione...');

            $.ajax({
                url: sdDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sd_export_csv',
                    nonce: sdDashboard.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Download file
                        var link = document.createElement('a');
                        link.href = response.data.url;
                        link.download = response.data.filename;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    } else {
                        alert(response.data.message || 'Nessun dato da esportare');
                    }
                    $btn.prop('disabled', false).html(
                        '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export CSV'
                    );
                },
                error: function() {
                    alert('Errore di connessione');
                    $btn.prop('disabled', false);
                }
            });
        });

    });

    // ============================================================
    // Generate decision reason from DB values (always in mg/dL)
    // displayed in user's preferred unit
    // ============================================================
    function generateDecisionReason(dd) {
        var g60 = parseInt(dd.glic_60_value) || 0;
        var g30 = parseInt(dd.glic_30_value) || 0;
        var g10 = parseInt(dd.glic_10_value) || 0;

        if (!g10) return dd.dive_decision_reason || '';

        var uL = glicUnitLabel();
        var v120 = displayGlic(120), v150 = displayGlic(150);
        var v250 = displayGlic(250), v300 = displayGlic(300);

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

        if (g10 < 120) return 'Glicemia <' + v120 + ' ' + uL + ': immersione NON consentita';
        if (trend === 'discesa') return 'Glicemia in discesa: immersione sospesa';
        if (g10 > 300 && trend === 'salita') return 'Glicemia >' + v300 + ' in salita: rinvio immersione';
        if (g10 >= 250 && g10 <= 300 && trend !== 'salita') return 'Glicemia ' + v250 + '-' + v300 + ' stabile, no chetonemia: OK';
        if (trend === 'salita' && g10 >= 120) return 'Glicemia \u2265' + v120 + ' in salita: OK';
        if (trend === 'stabile' && g10 >= 150) return 'Glicemia \u2265' + v150 + ' stabile: OK';
        if (trend === 'stabile' && g10 >= 120 && g10 < 150) return 'Glicemia ' + v120 + '-' + v150 + ' ' + uL + ' stabile: attenzione, considerare snack';
        return 'Valori nei range consentiti';
    }

})(jQuery);
