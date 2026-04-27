/**
 * ScubaDiabetes Logbook - Pannello Medico JS
 */
(function($) {
    'use strict';

    var currentDiverId = null;
    var currentDiverData = {};
    var selectedNoteDiveId = 0;
    var currentModalDiveId = 0;

    // ============================================================
    // FILTER DIVERS
    // ============================================================
    function applyDiverFilter(filter) {
        $('.sd-diver-card').each(function() {
            var isDiabetic = $(this).data('diabetic') == 1;
            if (filter === 'all') $(this).removeClass('sd-hidden');
            else if (filter === 'diabetic') $(this).toggleClass('sd-hidden', !isDiabetic);
            else $(this).toggleClass('sd-hidden', isDiabetic);
        });
        $('#sd-filter-role').val(filter);
        $('.sd-stat-card--filter').removeClass('sd-stat-card--active');
        $('.sd-stat-card--filter[data-filter="' + filter + '"]').addClass('sd-stat-card--active');
    }

    $('#sd-filter-role').on('change', function() {
        applyDiverFilter($(this).val());
    });

    $(document).on('click', '.sd-stat-card--filter', function() {
        applyDiverFilter($(this).data('filter'));
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

    function diabetesLabel(value) {
        var map = {
            tipo_1: 'Tipo 1',
            tipo_2: 'Tipo 2',
            tipo_3c: 'Tipo 3c (pancreasectomia, pancreatite)',
            lada: 'LADA',
            mody: 'MODY',
            midd: 'MIDD',
            altro: 'Altro',
            non_specificato: 'Non specificato'
        };
        return map[value] || value;
    }

    function therapyLabel(value) {
        var map = {
            mdi: 'MDI',
            csii: 'CSII',
            ahcl: 'AHCL',
            ipoglicemizzante_orale: 'Ipoglicemizzante orale',
            iniettiva_non_insulinica: 'Iniettiva non insulinica',
            orale: 'Ipoglicemizzante orale',
            mista: 'Iniettiva non insulinica'
        };
        return map[value] || value;
    }

    function therapyDetailLabel(value) {
        var map = {
            mdi_basale_toujeo: 'MDI - Basale: Toujeo',
            mdi_basale_tresiba: 'MDI - Basale: Tresiba',
            mdi_basale_lantus: 'MDI - Basale: Lantus',
            mdi_basale_abasaglar: 'MDI - Basale: Abasaglar',
            mdi_basale_levemir: 'MDI - Basale: Levemir',
            mdi_basale_insulatard: 'MDI - Basale: Insulatard',
            mdi_basale_altro: 'MDI - Basale: Altro',
            mdi_rapida_novorapid: 'MDI - Rapida: Novorapid',
            mdi_rapida_humalog: 'MDI - Rapida: Humalog',
            mdi_rapida_fiasp: 'MDI - Rapida: FiAsp',
            mdi_rapida_lyumjev: 'MDI - Rapida: Lyumjev',
            mdi_rapida_apidra: 'MDI - Rapida: Apidra',
            pump_novorapid: 'CSII/AHCL - Novorapid',
            pump_humalog: 'CSII/AHCL - Humalog',
            pump_fiasp: 'CSII/AHCL - FiAsp',
            pump_lyumjev: 'CSII/AHCL - Lyumjev',
            pump_apidra: 'CSII/AHCL - Apidra',
            glp1ra_ozempic: 'GLP1ra: Ozempic',
            glp1ra_trulicity: 'GLP1ra: Trulicity',
            gip_glp1ra_mounjaro: 'GIP/GLP1ra: Mounjaro',
            sglt2i_jardiance: 'SGLT2i: Jardiance',
            sglt2i_forxiga: 'SGLT2i: Forxiga',
            sglt2i_invokana: 'SGLT2i: Invokana',
            sglt2i_altro: 'SGLT2i: Altro',
            dpp4i_januvia: 'DPP4i: Januvia',
            dpp4i_trajenta: 'DPP4i: Trajenta',
            dpp4i_altro: 'DPP4i: Altro',
            metformina: 'Metformina',
            sulfanilurea_diamicron: 'Sulfanilurea/Repaglinide: Diamicron',
            repaglinide_novonorm: 'Sulfanilurea/Repaglinide: NovoNorm',
            sulfanilurea_altro: 'Sulfanilurea/Repaglinide: Altro',
            glitazone_actos: 'Glitazone: Actos',
            glitazone_altro: 'Glitazone: Altro'
        };
        return map[value] || value;
    }

    function supervisionStatusLabel(value) {
        var map = {
            in_revisione: 'In revisione',
            approvata: 'Approvata',
            sospesa: 'Sospesa',
            annullata: 'Annullata'
        };
        return map[value] || value;
    }

    function getDiveId(dive) {
        if (!dive) return 0;
        return parseInt(dive.dive_id || dive.id || dive.dive_base_id || dive.dd_dive_id, 10) || 0;
    }

    function hba1cUnitLabel(value) {
        return value === 'mmol_mol' ? 'mmol/mol' : '%';
    }

    function formatHumanDate(dateStr) {
        if (!dateStr || !/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return dateStr || '';
        return formatDate(dateStr);
    }

    function isFilled(value) {
        return value !== null && value !== undefined && String(value).trim() !== '';
    }

    function displayValue(value, formatter, emptyLabel) {
        if (!isFilled(value)) return emptyLabel || 'Non compilato';
        return formatter ? formatter(value) : String(value);
    }

    function renderPanel(data) {
        currentDiverData = data; // Store diver data for use in modals
        if (!currentDiverId && data && data.diver_id) {
            currentDiverId = parseInt(data.diver_id, 10) || null;
        }
        selectedNoteDiveId = (data.dives && data.dives.length > 0) ? getDiveId(data.dives[0]) : 0;
        $('#sd-panel-title').text(data.name);
        var html = '';

        // === PROFILO ===
        if (data.is_diabetic && data.profile) {
            var p = data.profile;
            html += '<div class="sd-panel-section">';
            html += '<div class="sd-panel-section-title">Profilo diabete</div>';
            html += '<div class="sd-record-list" id="sd-diabetes-profile-list">';
            html += '<div class="sd-record-card sd-record-clickable" data-index="0">';
            html += '<div class="sd-record-main">';
            html += '<div class="sd-record-title">Profilo diabetologico completo</div>';
            html += '<div class="sd-record-sub">';
            var profileMeta = [];
            if (data.role_label) profileMeta.push(esc(data.role_label));
            if (p.diabetes_type && p.diabetes_type !== 'none' && p.diabetes_type !== 'non_diabetico') profileMeta.push(esc(diabetesLabel(p.diabetes_type)));
            if (p.therapy_type && p.therapy_type !== 'none') profileMeta.push('Terapia: ' + esc(therapyLabel(p.therapy_type)));
            if (p.hba1c_last) profileMeta.push('HbA1c: ' + esc(p.hba1c_last + ' ' + hba1cUnitLabel(p.hba1c_unit)));
            html += profileMeta.join(' · ') || 'Apri per visualizzare tutti i dettagli diabetologici';
            html += '</div>';
            html += '</div>';
            html += '<div class="sd-record-actions">';
            html += '<span class="sd-panel-open-icon" aria-hidden="true">→</span>';
            html += '</div>';
            html += '</div>';
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
        if (data.can_access_supervision) {
            html += '<div class="sd-dive-status-legend">';
            html += '<span class="sd-dive-status-legend-item"><span class="sd-dive-status-dot sd-dive-status-dot-approvata"></span>Approvata</span>';
            html += '<span class="sd-dive-status-legend-item"><span class="sd-dive-status-dot sd-dive-status-dot-sospesa"></span>Sospesa</span>';
            html += '<span class="sd-dive-status-legend-item"><span class="sd-dive-status-dot sd-dive-status-dot-annullata"></span>Annullata</span>';
            html += '<span class="sd-dive-status-legend-item"><span class="sd-dive-status-dot sd-dive-status-dot-in_revisione"></span>In revisione</span>';
            html += '</div>';
        }

        // Stato supervisione piu recente per immersione.
        var latestSupervisionByDive = {};
        if (data.can_access_supervision && data.notes && data.notes.length > 0) {
            data.notes.forEach(function(note) {
                var diveId = parseInt(note.dive_id, 10) || 0;
                if (!diveId) return;

                var key = String(diveId);
                var existing = latestSupervisionByDive[key];
                if (!existing || String(note.created_at || '') > String(existing.created_at || '')) {
                    latestSupervisionByDive[key] = note;
                }
            });
        }

        if (data.dives.length === 0) {
            html += '<p style="color:#94A3B8;font-size:13px;">Nessuna immersione registrata.</p>';
        } else {
            html += '<div class="sd-record-list" id="sd-dive-list">';
            data.dives.forEach(function(dive, idx) {
                var meta = [];
                if (dive.max_depth) meta.push(dive.max_depth + 'm');
                if (dive.dive_time) meta.push(dive.dive_time + "'");
                if (dive.temp_water) meta.push(dive.temp_water + '°C');

                var diveId = getDiveId(dive);
                var supervisionNote = latestSupervisionByDive[String(diveId)] || null;
                var diveStatusClass = '';
                var noReviewClass = '';
                if (supervisionNote && supervisionNote.status) {
                    diveStatusClass = ' sd-dive-status-' + supervisionNote.status;
                } else {
                    noReviewClass = ' sd-dive-no-review';
                }

                html += '<div class="sd-record-card sd-dive-card' + diveStatusClass + noReviewClass + '" data-index="' + idx + '">';
                html += '<div class="sd-record-main">';
                html += '<div class="sd-record-title">#' + (dive.dive_number || diveId) + ' ' + esc(dive.site_name) + '</div>';
                html += '<div class="sd-record-sub">' + formatDate(dive.dive_date);
                if (meta.length) html += ' · ' + meta.join(' · ');
                html += '</div>';
                html += '</div>';
                html += '<div class="sd-record-actions">';
                if (supervisionNote && supervisionNote.status) {
                    html += '<span class="sd-note-status sd-note-status-' + supervisionNote.status + ' sd-dive-note-status">' + esc(supervisionStatusLabel(supervisionNote.status)) + '</span>';
                }
                if (dive.site_latitude && dive.site_longitude) {
                    html += '<button type="button" class="sd-btn-map-card" data-lat="' + parseFloat(dive.site_latitude) + '" data-lng="' + parseFloat(dive.site_longitude) + '" data-title="' + esc(dive.site_name) + '" title="Vedi mappa"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg></button>';
                }
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
        }
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
    // SAVE SUPERVISION (PER DIVE FROM MODAL)
    // ============================================================
    $(document).on('click', '#sd-btn-save-dive-review', function() {
        var modalDiveId = parseInt($('#sd-dive-review-dive-id').val(), 10) || 0;
        var attrDiveId = parseInt($(this).attr('data-dive-id'), 10) || 0;
        var stateDiveId = parseInt(currentModalDiveId, 10) || 0;
        var recordDiveId = parseInt(currentRecordData && currentRecordData.diveId, 10) || 0;
        var indexDiveId = 0;
        if (!modalDiveId && !attrDiveId && !stateDiveId && !recordDiveId && currentRecordData && typeof currentRecordData.index !== 'undefined') {
            var idx = parseInt(currentRecordData.index, 10);
            if (!isNaN(idx) && currentDiverData.dives && currentDiverData.dives[idx]) {
                indexDiveId = getDiveId(currentDiverData.dives[idx]);
            }
        }
        var diveId = modalDiveId || attrDiveId || stateDiveId || recordDiveId || indexDiveId || 0;

        var diverId = parseInt(currentDiverId, 10) || parseInt(currentDiverData.diver_id, 10) || 0;
        if (!diverId && currentDiverData.dives && currentDiverData.dives.length > 0) {
            diverId = parseInt(currentDiverData.dives[0].dive_user_id || currentDiverData.dives[0].user_id, 10) || 0;
        }
        if (!diverId || !diveId) {
            alert('Impossibile identificare immersione o subacqueo. Ricarica la pagina e riprova.');
            return;
        }

        var text = $('#sd-dive-review-note').val().trim();
        var noteType = $('#sd-dive-review-type').val() || 'review';
        var noteStatus = $('#sd-dive-review-status').val() || 'in_revisione';
        var reviewId = parseInt($('#sd-dive-review-id').val(), 10) || 0;

        // Prevent accidental duplicate insert when no effective change is made.
        if (!reviewId) {
            var latestReview = getLatestReviewForDive(diveId);
            if (latestReview) {
                var latestText = String(latestReview.notes || '').trim();
                var latestType = String(latestReview.supervision_type || 'review');
                var latestStatus = String(latestReview.status || 'in_revisione');
                if (latestText === text && latestType === noteType && latestStatus === noteStatus) {
                    alert('Revisione identica all\'ultima gia salvata: nessun duplicato creato.');
                    return;
                }
            }
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Salvataggio...');

        $.post(sdMedical.ajaxUrl, {
            action: 'sd_medical_save_note',
            nonce: sdMedical.nonce,
            diver_id: diverId,
            dive_id: diveId,
            review_id: reviewId,
            note_type: noteType,
            note_status: noteStatus,
            note_text: text
        }, function(resp) {
            if (resp.success) {
                if (!currentDiverData.notes) currentDiverData.notes = [];

                var savedReview = resp.data && resp.data.review ? resp.data.review : null;
                if (savedReview) {
                    var savedId = parseInt(savedReview.id, 10) || reviewId || 0;
                    var replaced = false;
                    if (savedId) {
                        currentDiverData.notes = currentDiverData.notes.map(function(note) {
                            if ((parseInt(note.id, 10) || 0) === savedId) {
                                replaced = true;
                                return savedReview;
                            }
                            return note;
                        });
                    }
                    if (!replaced) {
                        currentDiverData.notes.unshift(savedReview);
                    }
                } else {
                    currentDiverData.notes.unshift({
                        id: reviewId || Date.now(),
                        dive_id: diveId,
                        supervision_type: noteType,
                        status: noteStatus,
                        notes: text,
                        created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                        supervisor: ''
                    });
                }

                $('#sd-dive-review-id').val('');
                $('#sd-dive-review-note').val('');
                $('#sd-btn-save-dive-review').text('Salva revisione');
                $('#sd-btn-cancel-edit-review').hide();

                var saveModalIndex = (currentRecordData && typeof currentRecordData.index !== 'undefined') ? parseInt(currentRecordData.index, 10) : NaN;
                applyDiveCardStatusLive(diveId, saveModalIndex);
                rerenderCurrentDiveModal();
            } else {
                alert(resp.data.message || 'Errore');
                $btn.prop('disabled', false).text('Salva revisione');
            }
        });
    });

    $(document).on('click', '.sd-btn-edit-review', function(e) {
        e.preventDefault();
        var reviewId = parseInt($(this).attr('data-review-id'), 10) || 0;
        if (!reviewId || !currentRecordData || !currentRecordData.reviewsById || !currentRecordData.reviewsById[reviewId]) {
            return;
        }

        var review = currentRecordData.reviewsById[reviewId];
        $('#sd-dive-review-id').val(reviewId);
        $('#sd-dive-review-note').val(review.notes || '');
        $('#sd-dive-review-type').val(review.supervision_type || 'review');
        $('#sd-dive-review-status').val(review.status || 'in_revisione');
        $('#sd-btn-save-dive-review').text('Aggiorna revisione');
        $('#sd-btn-cancel-edit-review').show();
    });

    $(document).on('click', '.sd-btn-delete-review', function(e) {
        e.preventDefault();

        var reviewId = parseInt($(this).attr('data-review-id'), 10) || 0;
        if (!reviewId) return;

        var diveId = parseInt($('#sd-dive-review-dive-id').val(), 10) || parseInt(currentModalDiveId, 10) || 0;
        var diverId = parseInt(currentDiverId, 10) || parseInt(currentDiverData.diver_id, 10) || 0;
        if (!diverId && currentDiverData.dives && currentDiverData.dives.length > 0) {
            diverId = parseInt(currentDiverData.dives[0].dive_user_id || currentDiverData.dives[0].user_id, 10) || 0;
        }
        if (!diverId || !diveId) {
            alert('Impossibile eliminare: dati immersione non validi.');
            return;
        }

        if (!window.confirm('Confermi eliminazione della revisione selezionata?')) {
            return;
        }

        $.post(sdMedical.ajaxUrl, {
            action: 'sd_medical_delete_note',
            nonce: sdMedical.nonce,
            review_id: reviewId,
            diver_id: diverId,
            dive_id: diveId
        }, function(resp) {
            if (resp.success) {
                // Aggiorna solo stato locale e modal senza chiudere/riaprire il pannello.
                if (currentDiverData.notes && currentDiverData.notes.length > 0) {
                    currentDiverData.notes = currentDiverData.notes.filter(function(note) {
                        return parseInt(note.id, 10) !== reviewId;
                    });
                }

                if (currentRecordData && currentRecordData.reviewsById) {
                    delete currentRecordData.reviewsById[reviewId];
                }

                var deleteModalIndex = (currentRecordData && typeof currentRecordData.index !== 'undefined') ? parseInt(currentRecordData.index, 10) : NaN;
                applyDiveCardStatusLive(diveId, deleteModalIndex);
                rerenderCurrentDiveModal();
            } else {
                alert((resp.data && resp.data.message) ? resp.data.message : 'Errore durante eliminazione');
            }
        });
    });

    $(document).on('click', '#sd-btn-cancel-edit-review', function(e) {
        e.preventDefault();
        $('#sd-dive-review-id').val('');
        $('#sd-dive-review-note').val('');
        var defaultType = $('#sd-dive-review-type').attr('data-default') || 'review';
        var defaultStatus = $('#sd-dive-review-status').attr('data-default') || 'in_revisione';
        $('#sd-dive-review-type').val(defaultType);
        $('#sd-dive-review-status').val(defaultStatus);
        $('#sd-btn-save-dive-review').text('Salva revisione');
        $(this).hide();
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
        currentModalDiveId = 0;
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
    // DIABETES PROFILE MODAL
    // ============================================================
    function openDiabetesProfileModal() {
        if (!currentDiverData.profile) return;

        var p = currentDiverData.profile;
        var html = '<div class="sd-record-detail-grid">';

        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Ruolo</span><span class="sd-record-detail-value">' + esc(displayValue(currentDiverData.role_label)) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Stato diabetico</span><span class="sd-record-detail-value">' + (parseInt(p.is_diabetic, 10) === 1 ? 'Diabetico' : 'Non diabetico') + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Tipo diabete</span><span class="sd-record-detail-value">' + esc(displayValue(p.diabetes_type, diabetesLabel, 'Non specificato')) + '</span></div>';
        html += '<div class="sd-record-detail-item sd-record-detail-full"><span class="sd-record-detail-label">Centro diabetologico</span><span class="sd-record-detail-value">' + esc(displayValue(p.diabetology_center)) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Terapia</span><span class="sd-record-detail-value">' + esc(displayValue(p.therapy_type, function(v){ return v === 'none' ? 'Non specificata' : therapyLabel(v); }, 'Non specificata')) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Dettaglio terapia</span><span class="sd-record-detail-value">' + esc(displayValue(p.therapy_detail, therapyDetailLabel)) + '</span></div>';
        html += '<div class="sd-record-detail-item sd-record-detail-full"><span class="sd-record-detail-label">Specifica dettaglio terapia</span><span class="sd-record-detail-value">' + esc(displayValue(p.therapy_detail_other)) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">HbA1c ultimo valore</span><span class="sd-record-detail-value">' + esc(displayValue(p.hba1c_last, function(v){ return v + ' ' + hba1cUnitLabel(p.hba1c_unit); })) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Data HbA1c</span><span class="sd-record-detail-value">' + esc(displayValue(p.hba1c_date, formatHumanDate)) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Unità HbA1c</span><span class="sd-record-detail-value">' + esc(displayValue(p.hba1c_unit, hba1cUnitLabel, '%')) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">CGM</span><span class="sd-record-detail-value">' + (parseInt(p.uses_cgm, 10) === 1 ? 'Sì' : 'No') + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Dispositivo CGM</span><span class="sd-record-detail-value">' + esc(displayValue(p.cgm_device)) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Microinfusore</span><span class="sd-record-detail-value">' + esc(displayValue(p.insulin_pump_model)) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Altro microinfusore</span><span class="sd-record-detail-value">' + esc(displayValue(p.insulin_pump_model_other)) + '</span></div>';
        html += '<div class="sd-record-detail-item"><span class="sd-record-detail-label">Unità glicemia</span><span class="sd-record-detail-value">' + esc(displayValue(p.glycemia_unit, null, 'mg/dl')) + '</span></div>';
        html += '<div class="sd-record-detail-item sd-record-detail-full"><span class="sd-record-detail-label">Note diabetologiche</span><span class="sd-record-detail-value">' + esc(displayValue(p.notes)) + '</span></div>';

        html += '</div>';

        $modalTitle.text('Profilo diabetologico');
        $modalBody.html(html);
        $modalOverlay.fadeIn(200);
    }

    $(document).on('click', '#sd-diabetes-profile-list .sd-record-card', function() {
        openDiabetesProfileModal();
    });

    // ============================================================
    // DIVES MODAL
    // ============================================================
    function openDiveModal(index, $card) {
        if (!currentDiverData.dives || !currentDiverData.dives[index]) return;

        var dive = currentDiverData.dives[index];
        var diveId = getDiveId(dive);
        currentModalDiveId = diveId;
        currentRecordData = {
            type: 'dive_panel',
            index: index,
            diveId: diveId
        };
        var diveReviews = [];
        if (currentDiverData.notes && currentDiverData.notes.length > 0) {
            diveReviews = currentDiverData.notes.filter(function(note) {
                return parseInt(note.dive_id, 10) === diveId;
            });
            diveReviews.sort(function(a, b) {
                return String(b.created_at || '').localeCompare(String(a.created_at || ''));
            });
        }
        currentRecordData.reviewsById = {};
        diveReviews.forEach(function(note) {
            var reviewId = parseInt(note.id, 10) || 0;
            if (reviewId) currentRecordData.reviewsById[reviewId] = note;
        });
        var diveReview = diveReviews.length ? diveReviews[0] : null;
        var title = '';
        if ($card && $card.length) {
            title = $card.find('.sd-record-title').text();
        }
        if (!title) {
            title = '#' + (dive.dive_number || diveId) + ' ' + (dive.site_name || 'Immersione');
        }

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

        if (currentDiverData.can_access_supervision) {
            var selectedType = diveReview && diveReview.supervision_type ? diveReview.supervision_type : 'review';
            var selectedStatus = diveReview && diveReview.status ? diveReview.status : 'in_revisione';
            var reviewNote = '';

            html += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #E2E8F0;">';
            html += '<div class="sd-detail-section-title" style="margin-top:0;">Revisione medica immersione</div>';
            html += '<input type="hidden" id="sd-dive-review-dive-id" value="' + diveId + '">';
            html += '<input type="hidden" id="sd-dive-review-id" value="">';
            html += '<textarea id="sd-dive-review-note" placeholder="Aggiungi nota di revisione (opzionale)..." style="width:100%;border:1px solid #CBD5E1;border-radius:8px;padding:10px;font-size:13px;min-height:72px;resize:vertical;">' + esc(reviewNote) + '</textarea>';
            html += '<div class="sd-add-note-row" style="margin-top:10px;">';
            html += '<select id="sd-dive-review-type" data-default="' + esc(selectedType) + '">';
            html += '<option value="note"' + (selectedType === 'note' ? ' selected' : '') + '>Nota</option>';
            html += '<option value="pre_dive"' + (selectedType === 'pre_dive' ? ' selected' : '') + '>Pre-immersione</option>';
            html += '<option value="post_dive"' + (selectedType === 'post_dive' ? ' selected' : '') + '>Post-immersione</option>';
            html += '<option value="review"' + (selectedType === 'review' ? ' selected' : '') + '>Revisione</option>';
            html += '</select>';
            html += '<select id="sd-dive-review-status" data-default="' + esc(selectedStatus) + '">';
            html += '<option value="in_revisione"' + (selectedStatus === 'in_revisione' ? ' selected' : '') + '>In revisione</option>';
            html += '<option value="approvata"' + (selectedStatus === 'approvata' ? ' selected' : '') + '>Approvata</option>';
            html += '<option value="sospesa"' + (selectedStatus === 'sospesa' ? ' selected' : '') + '>Sospesa</option>';
            html += '<option value="annullata"' + (selectedStatus === 'annullata' ? ' selected' : '') + '>Annullata</option>';
            html += '</select>';
            html += '<button type="button" class="sd-btn-add-note" id="sd-btn-save-dive-review" data-dive-id="' + diveId + '">Salva revisione</button>';
            html += '<button type="button" class="sd-btn-add-note" id="sd-btn-cancel-edit-review" style="display:none;background:#64748B;">Annulla modifica</button>';
            html += '</div>';
            if (diveReview && diveReview.created_at) {
                html += '<div style="margin-top:8px;font-size:11px;color:#64748B;">Ultimo aggiornamento: ' + esc(diveReview.created_at) + '</div>';
            }
            html += '<div style="margin-top:14px;">';
            html += '<div style="font-size:12px;font-weight:600;margin-bottom:8px;color:#475569;">Storico revisioni immersione</div>';
            if (diveReviews.length > 0) {
                diveReviews.forEach(function(note) {
                    var reviewId = parseInt(note.id, 10) || 0;
                    html += '<div class="sd-note-item" style="margin-bottom:8px;">';
                    html += '<div class="sd-note-header">';
                    html += '<span>' + esc(note.supervisor || 'Medico') + '</span>';
                    html += '<span>' + esc(note.created_at || '') + ' <span class="sd-note-status sd-note-status-' + (note.status || 'in_revisione') + '">' + esc(supervisionStatusLabel(note.status || 'in_revisione')) + '</span></span>';
                    html += '</div>';
                    html += '<div style="font-size:11px;color:#64748B;margin-bottom:4px;">Tipo: ' + esc(note.supervision_type || 'review') + '</div>';
                    if (note.notes && String(note.notes).trim() !== '') {
                        html += '<div class="sd-note-text">' + esc(note.notes) + '</div>';
                    } else {
                        html += '<div class="sd-note-text" style="color:#94A3B8;">Nessuna nota testuale</div>';
                    }
                    if (reviewId) {
                        html += '<div style="margin-top:6px;display:flex;gap:12px;align-items:center;">';
                        html += '<button type="button" class="sd-btn-edit-review" data-review-id="' + reviewId + '" style="border:none;background:transparent;color:#0F766E;font-size:12px;cursor:pointer;padding:0;">Modifica revisione</button>';
                        html += '<button type="button" class="sd-btn-delete-review" data-review-id="' + reviewId + '" style="border:none;background:transparent;color:#DC2626;font-size:12px;cursor:pointer;padding:0;">Elimina</button>';
                        html += '</div>';
                    }
                    html += '</div>';
                });
            } else {
                html += '<div style="font-size:12px;color:#94A3B8;">Nessuna revisione registrata per questa immersione.</div>';
            }
            html += '</div>';
            html += '</div>';
        }

        $modalTitle.text(title);
        $modalBody.html(html);
        $modalOverlay.fadeIn(200);
    }

    function rerenderCurrentDiveModal() {
        var modalIndex = (currentRecordData && typeof currentRecordData.index !== 'undefined') ? parseInt(currentRecordData.index, 10) : NaN;
        if (isNaN(modalIndex) || !currentDiverData.dives || !currentDiverData.dives[modalIndex]) {
            return;
        }
        var $card = $('#sd-dive-list .sd-record-card[data-index="' + modalIndex + '"]');
        openDiveModal(modalIndex, $card);
    }

    function getLatestReviewForDive(diveId) {
        if (!currentDiverData.notes || !currentDiverData.notes.length) return null;
        var reviews = currentDiverData.notes.filter(function(note) {
            return parseInt(note.dive_id, 10) === parseInt(diveId, 10);
        });
        if (!reviews.length) return null;
        reviews.sort(function(a, b) {
            return String(b.created_at || '').localeCompare(String(a.created_at || ''));
        });
        return reviews[0];
    }

    function applyDiveCardStatusLive(diveId, modalIndex) {
        var idx = parseInt(modalIndex, 10);
        if (isNaN(idx) && currentDiverData.dives && currentDiverData.dives.length) {
            idx = currentDiverData.dives.findIndex(function(dive) {
                return parseInt(getDiveId(dive), 10) === parseInt(diveId, 10);
            });
        }
        if (isNaN(idx) || idx < 0) return;

        var $card = $('#sd-dive-list .sd-record-card[data-index="' + idx + '"]');
        if (!$card.length) return;

        $card.removeClass('sd-dive-status-approvata sd-dive-status-sospesa sd-dive-status-annullata sd-dive-status-in_revisione sd-dive-no-review');
        $card.find('.sd-dive-note-status').remove();

        var latest = getLatestReviewForDive(diveId);
        if (latest && latest.status) {
            $card.addClass('sd-dive-status-' + latest.status);
            var badge = '<span class="sd-note-status sd-note-status-' + latest.status + ' sd-dive-note-status">' + esc(supervisionStatusLabel(latest.status)) + '</span>';
            $card.find('.sd-record-actions').prepend(badge);
        } else {
            $card.addClass('sd-dive-no-review');
        }
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
