/**
 * ScubaDiabetes Logbook - Pannello Medico JS
 */
(function($) {
    'use strict';

    var currentDiverId = null;
    var currentDiverData = {};

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
        currentDiverData = data; // Store diver data for use in modals
        $('#sd-panel-title').text(data.name);
        var html = '';

        // === PROFILO ===
        if (data.is_diabetic && data.profile) {
            var p = data.profile;
            html += '<div class="sd-panel-section">';
            html += '<div class="sd-panel-section-title">Profilo diabete</div>';
            html += '<div class="sd-panel-profile-row">';
            if (data.role_label) html += tag(data.role_label, 'diabetes');
            if (p.diabetes_type && p.diabetes_type !== 'none' && p.diabetes_type !== 'non_diabetico') html += tag(p.diabetes_type.replace('tipo','Tipo '), 'diabetes');
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
            html += '<div class="sd-record-list" id="sd-cert-list">';
            data.certs.forEach(function(c, idx) {
                html += '<div class="sd-record-card" data-index="' + idx + '"' +
                        (c.doc ? ' data-doc-name="' + esc(c.doc.name || '') + '" data-doc-url="' + esc(c.doc.url || '') + '"' : '') + '>';
                html += '<div class="sd-record-main">';
                html += '<div class="sd-record-title">' + esc(c.agency) + ' — ' + esc(c.level) + '</div>';
                html += '<div class="sd-record-sub">';
                if (c.date) html += formatDate(c.date);
                if (c.number) html += ' · N° ' + esc(c.number);
                if (c.doc) html += ' · <a href="' + esc(c.doc.url) + '" target="_blank" class="sd-doc-link">📎 ' + esc(c.doc.name || 'Documento') + '</a>';
                html += '</div>';
                html += '</div>';
                html += '<div class="sd-record-actions"></div>';
                html += '</div>';
            });
            html += '</div>';
            html += '</div>';
        }

        // === IDONEITÀ ===
        if (data.clearances && data.clearances.length > 0) {
            html += '<div class="sd-panel-section">';
            html += '<div class="sd-panel-section-title">Idoneità medica</div>';
            html += '<div class="sd-record-list" id="sd-clearance-list">';
            data.clearances.forEach(function(cl, idx) {
                var expDays = cl.expiry ? Math.round((new Date(cl.expiry) - new Date()) / 86400000) : null;
                var statusHtml = '';
                var statusClass = '';
                if (expDays !== null) {
                    if (expDays < 0) { statusHtml = '<span class="sd-status-tag sd-tag-expired">SCADUTA</span>'; statusClass = 'sd-expired'; }
                    else if (expDays <= 30) { statusHtml = '<span class="sd-status-tag sd-tag-expiring">' + expDays + ' gg</span>'; statusClass = 'sd-expiring'; }
                    else { statusHtml = '<span class="sd-status-tag sd-tag-valid">VALIDA</span>'; statusClass = 'sd-valid'; }
                }

                html += '<div class="sd-record-card ' + statusClass + '" data-index="' + idx + '"' +
                        (cl.doc ? ' data-doc-name="' + esc(cl.doc.name || '') + '" data-doc-url="' + esc(cl.doc.url || '') + '"' : '') + '>';
                html += '<div class="sd-record-main">';
                html += '<div class="sd-record-title">' + formatDate(cl.date);
                if (cl.expiry) html += ' → ' + formatDate(cl.expiry);
                html += '</div>';
                html += '<div class="sd-record-sub">' + (cl.type ? esc(cl.type) : '');
                if (cl.doctor) html += ' · Dr. ' + esc(cl.doctor);
                if (cl.doc) html += ' · <a href="' + esc(cl.doc.url) + '" target="_blank" class="sd-doc-link">📎 ' + esc(cl.doc.name || 'Documento') + '</a>';
                html += '</div>';
                html += '</div>';
                html += '<div class="sd-record-actions">' + statusHtml + '</div>';
                html += '</div>';
            });
            html += '</div>';
            html += '</div>';
        }

        // === IMMERSIONI ===
        html += '<div class="sd-panel-section">';
        html += '<div class="sd-panel-section-title">Immersioni (' + data.dives.length + ')</div>';

        if (data.dives.length === 0) {
            html += '<p style="color:#94A3B8;font-size:13px;">Nessuna immersione registrata.</p>';
        } else {
            html += '<div class="sd-record-list" id="sd-dive-list">';
            data.dives.forEach(function(dive, idx) {
                var meta = [];
                if (dive.max_depth) meta.push(dive.max_depth + 'm');
                if (dive.dive_time) meta.push(dive.dive_time + "'");
                if (dive.temp_water) meta.push(dive.temp_water + '°C');

                html += '<div class="sd-record-card" data-index="' + idx + '">';
                html += '<div class="sd-record-main">';
                html += '<div class="sd-record-title">#' + (dive.dive_number || dive.id) + ' ' + esc(dive.site_name) + '</div>';
                html += '<div class="sd-record-sub">' + formatDate(dive.dive_date);
                if (meta.length) html += ' · ' + meta.join(' · ');
                html += '</div>';
                html += '</div>';
                html += '<div class="sd-record-actions">';
                if (dive.site_latitude && dive.site_longitude) {
                    html += '<button type="button" class="sd-btn-map-card" data-lat="' + parseFloat(dive.site_latitude) + '" data-lng="' + parseFloat(dive.site_longitude) + '" data-title="' + esc(dive.site_name) + '" title="Vedi mappa"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg></button>';
                }
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
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
    // RECORD DETAIL MODAL (Medical Clearance Details)
    // ============================================================
    var $modalOverlay = $('#sd-record-modal-overlay');
    var $modalBody = $('#sd-record-modal-body');
    var $modalTitle = $('#sd-record-modal-title');
    var currentRecordData = {};

    function openClearanceModal(index, $card) {
        currentRecordData = {
            type: 'medical_clearance_panel',
            index: index,
            $card: $card
        };

        var title = $card.find('.sd-record-title').text();
        var subText = $card.find('.sd-record-sub').text();

        var dates = title.split('→');
        var dateStart = dates[0] ? dates[0].trim() : '';
        var dateEnd = dates[1] ? dates[1].trim() : '';

        var typeMatch = subText.match(/^([^·]+)/);
        var doctorMatch = subText.match(/Dr\. (.+?)(?:\s·|$)/);
        var docName = $card.data('doc-name');
        var docUrl = $card.data('doc-url');

        var html = '<div class="sd-record-detail-grid">' +
            (dateStart ? '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Data rilascio</span><span class="sd-record-detail-value">' + esc(dateStart) + '</span></div>' : '') +
            (dateEnd ? '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Scadenza</span><span class="sd-record-detail-value">' + esc(dateEnd) + '</span></div>' : '') +
            (typeMatch ? '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Tipo visita</span><span class="sd-record-detail-value">' + esc(typeMatch[1].trim()) + '</span></div>' : '') +
            (doctorMatch ? '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Medico</span><span class="sd-record-detail-value">Dr. ' + esc(doctorMatch[1]) + '</span></div>' : '');

        if (docName && docUrl) {
            html += '<div class="sd-record-detail-item sd-record-detail-full"><span class="sd-record-detail-label">Documento</span><span class="sd-record-detail-value"><a href="' + esc(docUrl) + '" target="_blank">📎 ' + esc(docName) + '</a></span></div>';
        }
        html += '</div>';

        $modalTitle.text(title);
        $modalBody.html(html);
        $modalOverlay.fadeIn(200);
    }

    function closeClearanceModal() {
        closeRecordModal();
    }

    // Click on medical clearance card opens modal
    $(document).on('click', '#sd-clearance-list .sd-record-card', function(e) {
        var $card = $(this);
        var index = $card.data('index');
        openClearanceModal(index, $card);
    });

    // Close modal on overlay click (only if clicking directly on overlay)
    $modalOverlay.on('click', function(e) {
        if (e.target === this) {
            closeRecordModal();
        }
    });

    // Close modal on close button click
    $('#sd-record-modal-close, .sd-btn-record-modal-close').on('click', function() {
        closeRecordModal();
    });

    // Close modal on Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $modalOverlay.is(':visible')) {
            closeRecordModal();
        }
    });

    function closeRecordModal() {
        $modalOverlay.fadeOut(200);
        currentRecordData = {};
    }

    // ============================================================
    // CERTIFICATIONS MODAL
    // ============================================================
    function openCertificationModal(index, $card) {
        var agency = $card.find('.sd-record-title').text().split(' — ')[0];
        var level = $card.find('.sd-record-title').text().split(' — ')[1];
        var title = agency + ' — ' + level;

        var subText = $card.find('.sd-record-sub').text();
        var dateMatch = subText.match(/(\d{2}\/\d{2}\/\d{4})/);
        var numberMatch = subText.match(/N° (.+?)(?:\s·|$)/);

        var docName = $card.data('doc-name');
        var docUrl = $card.data('doc-url');

        var html = '<div class="sd-record-detail-grid">' +
            '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Agenzia</span><span class="sd-record-detail-value">' + esc(agency) + '</span></div>' +
            '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Livello</span><span class="sd-record-detail-value">' + esc(level) + '</span></div>' +
            (dateMatch ? '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Data</span><span class="sd-record-detail-value">' + esc(dateMatch[0]) + '</span></div>' : '') +
            (numberMatch ? '<div class="sd-record-detail-item"><span class="sd-record-detail-label">N° Brevetto</span><span class="sd-record-detail-value">' + esc(numberMatch[1]) + '</span></div>' : '');

        if (docName && docUrl) {
            html += '<div class="sd-record-detail-item sd-record-detail-full"><span class="sd-record-detail-label">Documento</span><span class="sd-record-detail-value"><a href="' + esc(docUrl) + '" target="_blank">📎 ' + esc(docName) + '</a></span></div>';
        }
        html += '</div>';

        $modalTitle.text(title);
        $modalBody.html(html);
        $modalOverlay.fadeIn(200);
    }

    // Click on certification card opens modal
    $(document).on('click', '#sd-cert-list .sd-record-card', function(e) {
        var $card = $(this);
        var index = $card.data('index');
        openCertificationModal(index, $card);
    });

    // ============================================================
    // DIVES MODAL
    // ============================================================
    function openDiveModal(index, $card) {
        if (!currentDiverData.dives || !currentDiverData.dives[index]) return;

        var dive = currentDiverData.dives[index];
        var title = $card.find('.sd-record-title').text();

        var html = '';

        // === DATI IMMERSIONE ===
        html += '<div class="sd-record-detail-grid">';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Data</span><span class="sd-record-detail-value">' + esc(formatDate(dive.dive_date)) + '</span></div>';
        if (dive.time_in) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Ora inizio</span><span class="sd-record-detail-value">' + esc(dive.time_in) + '</span></div>';
        if (dive.time_out) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Ora fine</span><span class="sd-record-detail-value">' + esc(dive.time_out) + '</span></div>';
        if (dive.max_depth) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Profondità max</span><span class="sd-record-detail-value">' + esc(dive.max_depth) + ' m</span></div>';
        if (dive.avg_depth) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Profondità media</span><span class="sd-record-detail-value">' + esc(dive.avg_depth) + ' m</span></div>';
        if (dive.dive_time) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Durata</span><span class="sd-record-detail-value">' + esc(dive.dive_time) + " '</span></div>";
        if (dive.temp_water) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Temperatura acqua</span><span class="sd-record-detail-value">' + esc(dive.temp_water) + '°C</span></div>';
        if (dive.temp_air) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Temperatura aria</span><span class="sd-record-detail-value">' + esc(dive.temp_air) + '°C</span></div>';
        html += '</div>';

        // === LOCALIZZAZIONE ===
        html += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #E2E8F0;">';
        html += '<div style="font-size:12px;font-weight:600;margin-bottom:8px;color:#475569;">Localizzazione</div>';

        if (dive.site_latitude && dive.site_longitude) {
            html += '<div class="sd-record-detail-grid">';
            html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Latitudine</span><span class="sd-record-detail-value">' + esc(dive.site_latitude) + '</span></div>';
            html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Longitudine</span><span class="sd-record-detail-value">' + esc(dive.site_longitude) + '</span></div>';
            html += '</div>';
            html += '<div style="margin-top:12px;">' +
                '<button type="button" class="sd-btn-form-map sd-btn-form-map--active sd-btn-map-modal" data-lat="' + parseFloat(dive.site_latitude) + '" data-lng="' + parseFloat(dive.site_longitude) + '" data-title="' + esc(dive.site_name) + '">' +
                '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>' +
                '<span class="sd-form-map-label"> Apri mappa</span>' +
                '</button></div>';
        } else {
            html += '<p style="color:#94A3B8;font-size:13px;margin:0;">Coordinate non disponibili</p>';
            html += '<div style="margin-top:12px;">' +
                '<button type="button" class="sd-btn-form-map" disabled>' +
                '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>' +
                '<span class="sd-form-map-label"> Coordinate non disponibili</span>' +
                '</button></div>';
        }
        html += '</div>';

        // === GAS E ATTREZZATURA ===
        if (dive.gas_mix || dive.nitrox_percentage || dive.tank_count || dive.tank_capacity || dive.pressure_start || dive.pressure_end || dive.ballast_kg || dive.suit_type) {
            html += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #E2E8F0;">';
            html += '<div style="font-size:12px;font-weight:600;margin-bottom:8px;color:#475569;">Gas e Attrezzatura</div>';
            html += '<div class="sd-record-detail-grid">';
            if (dive.gas_mix) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Mix gas</span><span class="sd-record-detail-value">' + esc(dive.gas_mix) + '</span></div>';
            if (dive.nitrox_percentage) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Nitrox</span><span class="sd-record-detail-value">' + esc(dive.nitrox_percentage) + '%</span></div>';
            if (dive.tank_count) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">N° Bottiglie</span><span class="sd-record-detail-value">' + esc(dive.tank_count) + '</span></div>';
            if (dive.tank_capacity) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Capacità bottiglia</span><span class="sd-record-detail-value">' + esc(dive.tank_capacity) + ' L</span></div>';
            if (dive.pressure_start) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Pressione inizio</span><span class="sd-record-detail-value">' + esc(dive.pressure_start) + ' bar</span></div>';
            if (dive.pressure_end) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Pressione fine</span><span class="sd-record-detail-value">' + esc(dive.pressure_end) + ' bar</span></div>';
            if (dive.ballast_kg) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Zavorra</span><span class="sd-record-detail-value">' + esc(dive.ballast_kg) + ' kg</span></div>';
            if (dive.suit_type) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Protezione</span><span class="sd-record-detail-value">' + esc(dive.suit_type) + '</span></div>';
            html += '</div></div>';
        }

        // === CONDIZIONI E DECOMPRESSIONE ===
        if (dive.dive_type || dive.weather || dive.visibility || dive.entry_type || dive.safety_stop_depth || dive.deco_stop_depth || dive.sea_condition || dive.current_strength) {
            html += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #E2E8F0;">';
            html += '<div style="font-size:12px;font-weight:600;margin-bottom:8px;color:#475569;">Condizioni e Decompressione</div>';
            html += '<div class="sd-record-detail-grid">';
            if (dive.dive_type) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Tipo immersione</span><span class="sd-record-detail-value">' + esc(dive.dive_type) + '</span></div>';
            if (dive.entry_type) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Entrata</span><span class="sd-record-detail-value">' + esc(dive.entry_type) + '</span></div>';
            if (dive.weather) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Meteo</span><span class="sd-record-detail-value">' + esc(dive.weather) + '</span></div>';
            if (dive.sea_condition) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Condizione mare</span><span class="sd-record-detail-value">' + esc(dive.sea_condition) + '</span></div>';
            if (dive.current_strength) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Corrente</span><span class="sd-record-detail-value">' + esc(dive.current_strength) + '</span></div>';
            if (dive.visibility) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Visibilità</span><span class="sd-record-detail-value">' + esc(dive.visibility) + '</span></div>';
            if (dive.safety_stop_depth) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Stop sicurezza</span><span class="sd-record-detail-value">' + esc(dive.safety_stop_depth) + ' m @ ' + esc(dive.safety_stop_time || '-') + ' min</span></div>';
            if (dive.deco_stop_depth) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Stop deco</span><span class="sd-record-detail-value">' + esc(dive.deco_stop_depth) + ' m @ ' + esc(dive.deco_stop_time || '-') + ' min</span></div>';
            if (dive.deep_stop_depth) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Deep stop</span><span class="sd-record-detail-value">' + esc(dive.deep_stop_depth) + ' m @ ' + esc(dive.deep_stop_time || '-') + ' min</span></div>';
            html += '</div></div>';
        }

        // === DATI FISIOLOGICI E SICUREZZA ===
        if (dive.thermal_comfort || dive.workload || dive.problems || dive.malfunctions || dive.symptoms || dive.exposure_to_altitude) {
            html += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #E2E8F0;">';
            html += '<div style="font-size:12px;font-weight:600;margin-bottom:8px;color:#475569;">Dati Fisiologici e Sicurezza</div>';
            html += '<div class="sd-record-detail-grid">';
            if (dive.thermal_comfort) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Comfort termico</span><span class="sd-record-detail-value">' + esc(dive.thermal_comfort) + '</span></div>';
            if (dive.workload) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Carico di lavoro</span><span class="sd-record-detail-value">' + esc(dive.workload) + '</span></div>';
            if (dive.problems) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Problemi</span><span class="sd-record-detail-value">' + esc(dive.problems) + '</span></div>';
            if (dive.malfunctions) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Malfunzionamenti</span><span class="sd-record-detail-value">' + esc(dive.malfunctions) + '</span></div>';
            if (dive.symptoms) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Sintomi</span><span class="sd-record-detail-value">' + esc(dive.symptoms) + '</span></div>';
            if (dive.exposure_to_altitude) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Esposizione all\'altitudine</span><span class="sd-record-detail-value">' + esc(dive.exposure_to_altitude) + '</span></div>';
            html += '</div></div>';
        }

        // === NOTE E COMPAGNI ===
        if (dive.buddy_name || dive.guide_name || dive.sightings || dive.other_equipment || dive.gear_notes || dive.notes) {
            html += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #E2E8F0;">';
            html += '<div style="font-size:12px;font-weight:600;margin-bottom:8px;color:#475569;">Note e Compagni</div>';
            html += '<div class="sd-record-detail-grid">';
            if (dive.buddy_name) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Compagno</span><span class="sd-record-detail-value">' + esc(dive.buddy_name) + '</span></div>';
            if (dive.guide_name) html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Guida</span><span class="sd-record-detail-value">' + esc(dive.guide_name) + '</span></div>';
            if (dive.sightings) html += '<div class="sd-record-detail-item sd-record-detail-full"><span class="sd-record-detail-label">Avvistamenti</span><span class="sd-record-detail-value">' + esc(dive.sightings) + '</span></div>';
            if (dive.other_equipment) html += '<div class="sd-record-detail-item sd-record-detail-full"><span class="sd-record-detail-label">Altro equipaggiamento</span><span class="sd-record-detail-value">' + esc(dive.other_equipment) + '</span></div>';
            if (dive.gear_notes) html += '<div class="sd-record-detail-item sd-record-detail-full"><span class="sd-record-detail-label">Note attrezzatura</span><span class="sd-record-detail-value">' + esc(dive.gear_notes) + '</span></div>';
            if (dive.notes) html += '<div class="sd-record-detail-item sd-record-detail-full"><span class="sd-record-detail-label">Note</span><span class="sd-record-detail-value">' + esc(dive.notes) + '</span></div>';
            html += '</div></div>';
        }

        // === NOTE IMMERSIONE ===
        if (dive.dive_notes) {
            html += '<div style="margin-top:16px;padding:12px;background:#F8FAFC;border-radius:4px;border-left:3px solid #3B82F6;">';
            html += '<div style="font-size:12px;font-weight:600;color:#1E40AF;margin-bottom:6px;">Note Immersione</div>';
            html += '<div style="font-size:13px;color:#475569;line-height:1.5;">' + esc(dive.dive_notes) + '</div>';
            html += '</div>';
        }

        // === GLICEMIE STANDARD ===
        if (currentDiverData.is_diabetic) {
            if (dive.glic_60_cap || dive.glic_30_cap || dive.glic_10_cap || dive.glic_post_cap) {
                html += '<div class="sd-detail-section-title">Glicemie e Provvedimenti</div>';

                var checkpoints = [
                    { key: '60',   label: '-60 min' },
                    { key: '30',   label: '-30 min' },
                    { key: '10',   label: '-10 min' },
                    { key: 'post', label: 'POST' }
                ];
                var trendArrows = { salita_rapida: '↑↑', salita: '↑', stabile: '→', discesa: '↓', discesa_rapida: '↓↓' };

                checkpoints.forEach(function(cp) {
                    var cap   = dive['glic_' + cp.key + '_cap'];
                    var sens  = dive['glic_' + cp.key + '_sens'];
                    var trend = dive['glic_' + cp.key + '_trend'];
                    var cho_r = dive['glic_' + cp.key + '_cho_rapidi'];
                    var cho_l = dive['glic_' + cp.key + '_cho_lenti'];
                    var ins   = dive['glic_' + cp.key + '_insulin'];
                    var notes = dive['glic_' + cp.key + '_notes'];
                    if (!cap && !sens) return;

                    var trendArrow = (trend && sens) ? (trendArrows[trend] || '') : '';
                    var extras = [];
                    if (cho_r && parseFloat(cho_r) > 0) extras.push('CHOr: ' + cho_r + 'g');
                    if (cho_l && parseFloat(cho_l) > 0) extras.push('CHOl: ' + cho_l + 'g');
                    if (ins   && parseFloat(ins)   > 0) extras.push('INS: ' + ins + 'U');
                    if (notes) extras.push(notes);

                    html += '<div class="sd-detail-glic-row">';
                    html += '<span class="sd-detail-glic-label">' + cp.label + '</span>';
                    if (cap)  html += '<span class="sd-detail-glic-value"><span class="sd-detail-method-badge sd-method-C">C</span> ' + parseInt(cap) + '</span>';
                    if (sens) html += '<span class="sd-detail-glic-value"><span class="sd-detail-method-badge sd-method-S">S</span> ' + parseInt(sens) + (trendArrow ? ' ' + trendArrow : '') + '</span>';
                    if (extras.length) html += '<span style="font-size:11px;color:#64748B;">' + extras.join(' · ') + '</span>';
                    html += '</div>';
                });

                // === GLICEMIE EXTRA 1-4 ===
                var extraKeys = [
                    { key: 'extra1', label: 'Extra 1' },
                    { key: 'extra2', label: 'Extra 2' },
                    { key: 'extra3', label: 'Extra 3' },
                    { key: 'extra4', label: 'Extra 4' }
                ];
                var whenLabels = { prima_60: '-90 min', prima_30: '-45 min', prima_10: '-20 min', prima_post: '-5 min', dopo_post: '+30 min' };
                extraKeys.forEach(function(ex) {
                    var exCap  = dive['glic_' + ex.key + '_cap'];
                    var exSens = dive['glic_' + ex.key + '_sens'];
                    if (!exCap && !exSens) return;
                    var exWhen  = dive['glic_' + ex.key + '_when'];
                    var exTrend = dive['glic_' + ex.key + '_trend'];
                    var exChoR  = dive['glic_' + ex.key + '_cho_rapidi'];
                    var exChoL  = dive['glic_' + ex.key + '_cho_lenti'];
                    var exIns   = dive['glic_' + ex.key + '_insulin'];
                    var exNotes = dive['glic_' + ex.key + '_notes'];
                    var exArrow = (exTrend && exSens) ? (trendArrows[exTrend] || '') : '';
                    var exExtras = [];
                    if (exChoR && parseFloat(exChoR) > 0) exExtras.push('CHOr: ' + exChoR + 'g');
                    if (exChoL && parseFloat(exChoL) > 0) exExtras.push('CHOl: ' + exChoL + 'g');
                    if (exIns  && parseFloat(exIns)  > 0) exExtras.push('INS: ' + exIns + 'U');
                    if (exNotes) exExtras.push(exNotes);
                    var whenStr = exWhen ? (whenLabels[exWhen] || exWhen) : '';

                    html += '<div class="sd-detail-glic-row sd-detail-glic-extra">';
                    html += '<span class="sd-detail-glic-label">' + ex.label + (whenStr ? ' (' + whenStr + ')' : '') + '</span>';
                    if (exCap)  html += '<span class="sd-detail-glic-value"><span class="sd-detail-method-badge sd-method-C">C</span> ' + parseInt(exCap)  + '</span>';
                    if (exSens) html += '<span class="sd-detail-glic-value"><span class="sd-detail-method-badge sd-method-S">S</span> ' + parseInt(exSens) + (exArrow ? ' ' + exArrow : '') + '</span>';
                    if (exExtras.length) html += '<span style="font-size:11px;color:#64748B;">' + exExtras.join(' · ') + '</span>';
                    html += '</div>';
                });

                // Decision badge
                if (dive.dive_decision) {
                    var decColors = { autorizzata: '#16A34A', sospesa: '#D97706', annullata: '#DC2626' };
                    var decisionColor = decColors[dive.dive_decision] || '#64748B';
                    html += '<div style="margin-top:10px;padding:8px 12px;border-radius:6px;background:' + decisionColor + '15;border:1px solid ' + decisionColor + '40;">';
                    html += '<strong style="color:' + decisionColor + ';">' + dive.dive_decision.toUpperCase() + '</strong>';
                    if (dive.dive_decision_reason) html += ' — <span style="font-size:12px;">' + esc(dive.dive_decision_reason) + '</span>';
                    html += '</div>';
                }
            } else {
                html += '<div style="margin-top:16px;padding:8px 12px;background:#FEF3C7;border-left:3px solid #F59E0B;border-radius:4px;font-size:12px;color:#78350F;">' +
                        'Nessun dato di glicemia registrato' +
                        '</div>';
            }
        }

        $modalTitle.text(title);
        $modalBody.html(html);
        $modalOverlay.fadeIn(200);
    }

    function getTrendIcon(trend) {
        var icons = {
            'up': '📈',
            'down': '📉',
            'stable': '➡️'
        };
        return icons[trend] || '';
    }

    function getGlicWhenLabel(when) {
        var labels = {
            'prima_60': '60\' prima immersione',
            'prima_30': '30\' prima immersione',
            'prima_10': '10\' prima immersione',
            'prima_post': 'Post immersione',
            'dopo_post': 'Dopo post immersione'
        };
        return labels[when] || when;
    }

    function openMapModal(lat, lng, siteName) {
        var url = 'https://www.google.com/maps/search/' + lat + ',' + lng + '/?api=1';
        window.open(url, 'map_window', 'width=800,height=600');
    }

    // Click on dive card opens modal
    $(document).on('click', '#sd-dive-list .sd-record-card', function(e) {
        var $card = $(this);
        var index = $card.data('index');
        openDiveModal(index, $card);
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

    // ============================================================
    // MAP MODAL WITH LEAFLET
    // ============================================================
    var mapInstance = null;

    function openMapModal(lat, lng, title) {
        var $overlay = $('#sd-map-modal-overlay');
        var $modalTitle = $('#sd-map-modal-title');
        var $container = $('#sd-map-container');

        // Clean up previous map instance
        if (mapInstance) {
            mapInstance.remove();
            mapInstance = null;
        }

        // Reset container
        $container.empty();

        $modalTitle.text(esc(title));
        $overlay.fadeIn(200);

        // Initialize map after modal is visible
        setTimeout(function() {
            if (typeof L !== 'undefined') {
                mapInstance = L.map('sd-map-container').setView([lat, lng], 13);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors',
                    maxZoom: 19
                }).addTo(mapInstance);

                L.marker([lat, lng], {
                    icon: L.icon({
                        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
                        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                        popupAnchor: [1, -34],
                        shadowSize: [41, 41]
                    })
                }).addTo(mapInstance)
                  .bindPopup('<b>' + esc(title) + '</b><br>' + lat.toFixed(5) + ', ' + lng.toFixed(5))
                  .openPopup();

                mapInstance.invalidateSize();
            }
        }, 250);
    }

    // Close map modal
    $('#sd-map-modal-close').on('click', function() {
        $('#sd-map-modal-overlay').fadeOut(200);
        if (mapInstance) {
            mapInstance.remove();
            mapInstance = null;
        }
    });

    $('#sd-map-modal-overlay').on('click', function(e) {
        if (e.target === this) {
            $('#sd-map-modal-overlay').fadeOut(200);
            if (mapInstance) {
                mapInstance.remove();
                mapInstance = null;
            }
        }
    });

    // Map button in dive card (panel left)
    $(document).on('click', '.sd-btn-map-card', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var lat = parseFloat($(this).data('lat'));
        var lng = parseFloat($(this).data('lng'));
        var title = $(this).data('title') || 'Immersione';
        if (!isNaN(lat) && !isNaN(lng)) {
            openMapModal(lat, lng, title);
        }
    });

    // Map button in modal detail (localizzazione section)
    $(document).on('click', '.sd-btn-map-modal', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var lat = parseFloat($(this).data('lat'));
        var lng = parseFloat($(this).data('lng'));
        var title = $(this).data('title') || 'Immersione';
        if (!isNaN(lat) && !isNaN(lng)) {
            openMapModal(lat, lng, title);
        }
    });

    // Close map modal on Escape
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#sd-map-modal-overlay').is(':visible')) {
            $('#sd-map-modal-overlay').fadeOut(200);
            if (mapInstance) {
                mapInstance.remove();
                mapInstance = null;
            }
        }
    });

})(jQuery);
