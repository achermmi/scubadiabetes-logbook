/**
 * ScubaDiabetes Logbook - Pannello Medico JS
 */
(function($) {
    'use strict';

    var currentDiverId = null;

    // ============================================================
    // FILTER DIVERS
    // ============================================================
    $('#sd-filter-role').on('change', function() {
        var filter = $(this).val();
        $('.sd-diver-card').each(function() {
            var isDiabetic = $(this).data('diabetic') == 1;
            if (filter === 'all') $(this).removeClass('sd-hidden');
            else if (filter === 'diabetic') $(this).toggleClass('sd-hidden', !isDiabetic);
            else $(this).toggleClass('sd-hidden', isDiabetic);
        });
    });

    // ============================================================
    // OPEN DIVER PANEL
    // ============================================================
    $(document).on('click', '.sd-diver-card', function() {
        var diverId = $(this).data('diver-id');
        if (!diverId) return;
        currentDiverId = diverId;
        openPanel(diverId);
    });

    function openPanel(diverId) {
        var $overlay = $('#sd-panel-overlay');
        var $body = $('#sd-panel-body');

        $body.html('<div class="sd-loading">Caricamento...</div>');
        $overlay.fadeIn(200);

        $.post(sdMedical.ajaxUrl, {
            action: 'sd_medical_get_diver',
            nonce: sdMedical.nonce,
            diver_id: diverId
        }, function(resp) {
            if (resp.success) {
                renderPanel(resp.data);
            } else {
                $body.html('<p style="color:#DC2626;">Errore</p>');
            }
        });
    }

    function renderPanel(data) {
        $('#sd-panel-title').text(data.name);
        var html = '';

        // === PROFILO ===
        if (data.is_diabetic && data.profile) {
            var p = data.profile;
            html += '<div class="sd-panel-section">';
            html += '<div class="sd-panel-section-title">Profilo diabete</div>';
            html += '<div class="sd-panel-profile-row">';
            if (p.diabetes_type && p.diabetes_type !== 'none') html += tag(p.diabetes_type.replace('tipo','Tipo '), 'diabetes');
            if (p.therapy_type && p.therapy_type !== 'none') html += tag(p.therapy_type.toUpperCase());
            if (p.hba1c_last) html += tag('HbA1c: ' + p.hba1c_last + '%');
            if (p.uses_cgm == 1) html += tag('CGM: ' + (p.cgm_device || 'Sì'));
            if (p.insulin_pump_model) html += tag('Pump: ' + p.insulin_pump_model);
            html += '</div>';
            html += '</div>';
        }

        // === CERTIFICAZIONI ===
        if (data.certs && data.certs.length > 0) {
            html += '<div class="sd-panel-section">';
            html += '<div class="sd-panel-section-title">Certificazioni</div>';
            data.certs.forEach(function(c) {
                html += '<div style="font-size:12px;margin-bottom:3px;">' + esc(c.agency) + ' — ' + esc(c.level);
                if (c.date) html += ' <span style="color:#94A3B8;">(' + formatDate(c.date) + ')</span>';
                html += '</div>';
            });
            html += '</div>';
        }

        // === IDONEITÀ ===
        if (data.clearances && data.clearances.length > 0) {
            html += '<div class="sd-panel-section">';
            html += '<div class="sd-panel-section-title">Idoneità medica</div>';
            data.clearances.forEach(function(cl) {
                var expDays = cl.expiry ? Math.round((new Date(cl.expiry) - new Date()) / 86400000) : null;
                var statusHtml = '';
                if (expDays !== null) {
                    if (expDays < 0) statusHtml = ' <span class="sd-status-tag sd-tag-expired">SCADUTA</span>';
                    else if (expDays <= 30) statusHtml = ' <span class="sd-status-tag sd-tag-expiring">' + expDays + ' gg</span>';
                    else statusHtml = ' <span class="sd-status-tag sd-tag-valid">VALIDA</span>';
                }
                html += '<div style="font-size:12px;margin-bottom:3px;">' + formatDate(cl.date);
                if (cl.expiry) html += ' → ' + formatDate(cl.expiry);
                html += statusHtml;
                if (cl.type) html += ' · ' + esc(cl.type);
                if (cl.doc) html += ' · <a href="' + cl.doc.url + '" target="_blank" style="color:var(--sd-blue);">📎</a>';
                html += '</div>';
            });
            html += '</div>';
        }

        // === IMMERSIONI ===
        html += '<div class="sd-panel-section">';
        html += '<div class="sd-panel-section-title">Immersioni (' + data.dives.length + ')</div>';

        if (data.dives.length === 0) {
            html += '<p style="color:#94A3B8;font-size:13px;">Nessuna immersione registrata.</p>';
        } else {
            data.dives.forEach(function(dive) {
                html += '<div class="sd-panel-dive">';
                html += '<div class="sd-panel-dive-header">';
                html += '<span class="sd-panel-dive-title">#' + (dive.dive_number || dive.id) + ' ' + esc(dive.site_name) + '</span>';
                html += '<span class="sd-panel-dive-date">' + formatDate(dive.dive_date) + '</span>';
                html += '</div>';

                var meta = [];
                if (dive.max_depth) meta.push(dive.max_depth + 'm');
                if (dive.dive_time) meta.push(dive.dive_time + "'");
                if (dive.temp_water) meta.push(dive.temp_water + '°C');
                if (meta.length) html += '<div class="sd-panel-dive-meta">' + meta.join(' · ') + '</div>';

                // Glicemie inline
                if (dive.glic_60_value || dive.glic_30_value || dive.glic_10_value || dive.glic_post_value) {
                    html += '<div class="sd-panel-dive-glic">';
                    var checkpoints = [
                        { key: '60', label: '-60' },
                        { key: '30', label: '-30' },
                        { key: '10', label: '-10' },
                        { key: 'post', label: 'POST' }
                    ];
                    checkpoints.forEach(function(cp) {
                        var val = dive['glic_' + cp.key + '_value'];
                        var method = dive['glic_' + cp.key + '_method'];
                        if (val) {
                            var color = val < 120 ? '#DC2626' : (val > 250 ? '#D97706' : '#16A34A');
                            var badge = method ? '<span class="sd-glic-method sd-glic-method-' + method + '">' + method + '</span>' : '';
                            html += '<span class="sd-panel-dive-glic-item" style="border-left:2px solid ' + color + ';">' + cp.label + ': <strong>' + val + '</strong> ' + badge + '</span>';
                        }
                    });
                    html += '</div>';

                    // Decision
                    if (dive.dive_decision) {
                        html += '<span class="sd-decision-badge sd-decision-' + dive.dive_decision + '" style="margin-top:4px;">' + dive.dive_decision.toUpperCase() + '</span>';
                    }
                }

                html += '</div>'; // end dive
            });
        }
        html += '</div>';

        // === NOTE SUPERVISIONE ===
        html += '<div class="sd-panel-section">';
        html += '<div class="sd-panel-section-title">Note di supervisione</div>';

        if (data.notes && data.notes.length > 0) {
            data.notes.forEach(function(note) {
                html += '<div class="sd-note-item">';
                html += '<div class="sd-note-header">';
                html += '<span>' + esc(note.supervisor) + '</span>';
                html += '<span>' + esc(note.created_at) + ' <span class="sd-note-status sd-note-status-' + note.status + '">' + note.status + '</span></span>';
                html += '</div>';
                html += '<div class="sd-note-text">' + esc(note.notes) + '</div>';
                html += '</div>';
            });
        } else {
            html += '<p style="color:#94A3B8;font-size:12px;">Nessuna nota.</p>';
        }

        // Form per aggiungere nota
        html += '<div class="sd-add-note-form">';
        html += '<textarea id="sd-note-text" placeholder="Aggiungi nota di supervisione..."></textarea>';
        html += '<div class="sd-add-note-row">';
        html += '<select id="sd-note-type"><option value="note">Nota</option><option value="pre_dive">Pre-immersione</option><option value="post_dive">Post-immersione</option><option value="review">Revisione</option></select>';
        html += '<select id="sd-note-status"><option value="in_revisione">In revisione</option><option value="approvata">Approvata</option><option value="sospesa">Sospesa</option><option value="annullata">Annullata</option></select>';
        html += '<button type="button" class="sd-btn-add-note" id="sd-btn-add-note">Salva nota</button>';
        html += '</div></div>';

        html += '</div>';

        $('#sd-panel-body').html(html);
    }

    // ============================================================
    // CLOSE PANEL
    // ============================================================
    $('#sd-panel-back, #sd-panel-overlay').on('click', function(e) {
        if (e.target === this) {
            $('#sd-panel-overlay').fadeOut(200);
            currentDiverId = null;
        }
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#sd-panel-overlay').fadeOut(200);
            currentDiverId = null;
        }
    });

    // ============================================================
    // ADD SUPERVISION NOTE
    // ============================================================
    $(document).on('click', '#sd-btn-add-note', function() {
        var text = $('#sd-note-text').val().trim();
        if (!text || !currentDiverId) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Salvataggio...');

        $.post(sdMedical.ajaxUrl, {
            action: 'sd_medical_save_note',
            nonce: sdMedical.nonce,
            diver_id: currentDiverId,
            dive_id: 0,
            note_type: $('#sd-note-type').val(),
            note_status: $('#sd-note-status').val(),
            note_text: text
        }, function(resp) {
            if (resp.success) {
                // Ricarica pannello
                openPanel(currentDiverId);
            } else {
                alert(resp.data.message || 'Errore');
                $btn.prop('disabled', false).text('Salva nota');
            }
        });
    });

    // ============================================================
    // EXPORT RESEARCH DATA
    // ============================================================
    $('#sd-btn-research-export').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Esportazione...');

        $.post(sdMedical.ajaxUrl, {
            action: 'sd_medical_export',
            nonce: sdMedical.nonce
        }, function(resp) {
            if (resp.success) {
                var link = document.createElement('a');
                link.href = resp.data.url;
                link.download = resp.data.filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                alert('Esportati ' + resp.data.count + ' record.');
            } else {
                alert(resp.data.message || 'Nessun dato');
            }
            $btn.prop('disabled', false).html('<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export ricerca');
        });
    });

    // ============================================================
    // HELPERS
    // ============================================================
    function tag(text, type) {
        var cls = type === 'diabetes' ? 'sd-panel-tag sd-panel-tag-diabetes' : 'sd-panel-tag';
        return '<span class="' + cls + '">' + esc(text) + '</span>';
    }

    function formatDate(str) {
        if (!str) return '';
        var p = str.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function esc(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

})(jQuery);
