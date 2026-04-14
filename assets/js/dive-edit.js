/**
 * ScubaDiabetes - Modifica Immersioni JS
 * Carica dati, form inline, salvataggio con storico, visualizzazione storia
 */
(function($) {
    'use strict';

    var currentDiveId    = null;
    var _formSnapshot    = null; // serializzazione form al momento del caricamento
    var FACTOR = 18.018;
    var isMmol = (typeof sdDiveEdit !== 'undefined' && sdDiveEdit.glycemiaUnit === 'mmol/l');

    function displayGlic(mgdlVal) {
        if (!mgdlVal) return '';
        if (isMmol) return (parseFloat(mgdlVal) / FACTOR).toFixed(1);
        return mgdlVal;
    }
    function glicUnit() { return isMmol ? 'mmol/L' : 'mg/dL'; }
    function glicPlaceholder() { return isMmol ? '8.3' : '150'; }
    function glicStep() { return isMmol ? '0.1' : '1'; }
    function glicMin() { return isMmol ? '1.0' : '20'; }
    function glicMax() { return isMmol ? '28.0' : '500'; }
    function glicInputmode() { return isMmol ? 'decimal' : 'numeric'; }

    // ============================================================
    // MAPPA DAL FORM DI MODIFICA
    // ============================================================
    $(document).on('click', '.sd-btn-form-map', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var lat   = parseFloat($(this).data('lat'));
        var lng   = parseFloat($(this).data('lng'));
        var title = $(this).data('title') || '';
        if (isNaN(lat) || isNaN(lng)) return;
        if (typeof window.sdOpenMap === 'function') {
            window.sdOpenMap(lat, lng, title);
        }
    });

    // Aggiorna il pulsante mappa nel form quando cambiano lat/lng
    $(document).on('input change', '#sd-edit-form input[name="site_latitude"], #sd-edit-form input[name="site_longitude"]', function() {
        syncFormMapButton();
    });

    function syncFormMapButton() {
        var lat   = parseFloat($('#sd-edit-form input[name="site_latitude"]').val()) || 0;
        var lng   = parseFloat($('#sd-edit-form input[name="site_longitude"]').val()) || 0;
        var title = $('input[name="site_name"]').val() || '';
        var $btn  = $('#sd-form-map-btn');
        if (!$btn.length) return;
        if (lat !== 0 && lng !== 0) {
            $btn.addClass('sd-btn-form-map--active')
                .removeAttr('disabled')
                .attr({ 'data-lat': lat, 'data-lng': lng, 'data-title': title });
            $btn.find('.sd-form-map-label').text(' Vedi posizione sulla mappa');
        } else {
            $btn.removeClass('sd-btn-form-map--active')
                .attr('disabled', 'disabled');
            $btn.find('.sd-form-map-label').text(' Inserisci lat/lng per abilitare la mappa');
        }
    }

    // ============================================================
    // EDIT: Load dive data
    // ============================================================
    $(document).on('click', '.sd-btn-edit-dive', function(e) {
        e.stopPropagation();
        var diveId = $(this).data('dive-id');
        currentDiveId = diveId;

        var $panel = $('#sd-edit-panel');
        var $body  = $('#sd-edit-panel-body');

        $body.html('<div class="sd-loading">Caricamento...</div>');
        $panel.slideDown(300);
        $('html, body').animate({ scrollTop: $panel.offset().top - 80 }, 300);

        $.post(sdDiveEdit.ajaxUrl, {
            action: 'sd_get_dive_for_edit',
            nonce: sdDiveEdit.nonce,
            dive_id: diveId
        }, function(resp) {
            if (!resp.success) {
                $body.html('<p class="sd-error">' + (resp.data?.message || 'Errore') + '</p>');
                return;
            }
            renderEditForm(resp.data.dive, resp.data.diabetes);
        });
    });

    // ============================================================
    // RENDER EDIT FORM
    // ============================================================
    function renderEditForm(dive, diabetes) {
        var html = '<form id="sd-edit-form" class="sd-dive-form sd-edit-form-inner" novalidate>';
        html += '<input type="hidden" name="dive_id" value="' + dive.id + '">';

        // ══════════════════════════════════════════════════════════
        // SEZIONE 1: DATA E SITO (identica al form registrazione)
        // ══════════════════════════════════════════════════════════
        html += sectionTitle('Data e Sito');
        html += '<div class="sd-field-row">';
        html += field('dive_date', 'Data *', 'date', dive.dive_date, '', 'half');
        html += field('dive_number', 'N° Immersione', 'number', dive.dive_number, '', 'half', '1', '1');
        html += '</div>';
        html += field('site_name', 'Sito di immersione *', 'text', dive.site_name);
        var hasCoords = dive.site_latitude && dive.site_longitude;
        html += '<div class="sd-field-row">';
        html += field('site_latitude', 'Latitudine', 'number', dive.site_latitude, 'es: 37.5667', 'half', '0.0000001');
        html += field('site_longitude', 'Longitudine', 'number', dive.site_longitude, 'es: 15.1667', 'half', '0.0000001');
        html += '</div>';
        html += '<div style="margin:-6px 0 10px;">' +
            '<button type="button" id="sd-form-map-btn" class="sd-btn-form-map' + (hasCoords ? ' sd-btn-form-map--active' : '') + '" ' +
            (hasCoords ? 'data-lat="' + esc(dive.site_latitude) + '" data-lng="' + esc(dive.site_longitude) + '" data-title="' + esc(dive.site_name) + '"' : 'disabled') + '>' +
            '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>' +
            '<span class="sd-form-map-label">' + (hasCoords ? ' Vedi posizione sulla mappa' : ' Inserisci lat/lng per abilitare la mappa') + '</span>' +
            '</button></div>';

        // ══════════════════════════════════════════════════════════
        // SEZIONE 2: EQUIPAGGIAMENTO
        // ══════════════════════════════════════════════════════════
        html += sectionTitle('Equipaggiamento');
        html += '<div class="sd-edit-subsection">Bombole</div>';
        html += '<div class="sd-field-row">';
        html += selectField('tank_count', 'N° Bombole', dive.tank_count, {'1':'1','2':'2 (bibo)','3':'3'}, 'half');
        html += '</div>';
        html += '<div class="sd-field-row sd-field-row-3">';
        html += selectField('gas_mix', 'Miscela', dive.gas_mix, {aria:'Aria',nitrox:'Nitrox',trimix:'Trimix'}, 'third');
        html += field('tank_capacity', 'Capacità (L)', 'number', dive.tank_capacity, '15', 'third', '0.1');
        html += field('nitrox_percentage', '%O₂', 'number', dive.nitrox_percentage, '32', 'third', '0.1', '21', '100');
        html += '</div>';
        html += '<div class="sd-field-row">';
        html += field('ballast_kg', 'Zavorra (kg)', 'number', dive.ballast_kg, '6', 'half', '0.5');
        html += selectField('suit_type', 'Protezione', dive.suit_type, {'':'—',umida:'Umida',semistagna:'Semistagna',stagna:'Stagna'}, 'half');
        html += '</div>';

        // ══════════════════════════════════════════════════════════
        // SEZIONE 3: INIZIO / FINE IMMERSIONE
        // ══════════════════════════════════════════════════════════
        html += sectionTitle('Inizio / Fine Immersione');
        html += '<div class="sd-field-row">';
        html += '<div class="sd-field sd-field-half"><div class="sd-edit-subsection" style="margin:0 0 6px">INIZIO</div>';
        html += '<div class="sd-field"><label>Ora</label><input type="time" name="time_in" value="' + esc(dive.time_in || '') + '"></div>';
        html += '<div class="sd-field"><label>Bar</label><input type="number" name="pressure_start" value="' + esc(dive.pressure_start || '') + '" placeholder="200" min="0" max="300"></div>';
        html += '</div>';
        html += '<div class="sd-field sd-field-half"><div class="sd-edit-subsection" style="margin:0 0 6px">FINE</div>';
        html += '<div class="sd-field"><label>Ora</label><input type="time" name="time_out" value="' + esc(dive.time_out || '') + '"></div>';
        html += '<div class="sd-field"><label>Bar</label><input type="number" name="pressure_end" value="' + esc(dive.pressure_end || '') + '" placeholder="50" min="0" max="300"></div>';
        html += '</div>';
        html += '</div>';

        html += '<div class="sd-field-row sd-field-row-3">';
        html += field('max_depth', 'Prof. max (m)', 'number', dive.max_depth, '30.6', 'third', '0.1');
        html += field('avg_depth', 'Prof. media (m)', 'number', dive.avg_depth, '', 'third', '0.1');
        html += field('dive_time', 'Tempo (min)', 'number', dive.dive_time, '54', 'third');
        html += '</div>';

        html += '<div class="sd-edit-subsection">Soste di sicurezza</div>';
        html += '<div class="sd-field-row">';
        html += field('safety_stop_depth', 'Profondità (m)', 'number', dive.safety_stop_depth, '5', 'half', '0.1');
        html += field('safety_stop_time', 'Durata (min)', 'number', dive.safety_stop_time, '3', 'half');
        html += '</div>';

        // Deco / Deep stop (avanzato)
        html += '<details class="sd-details"><summary>Deco stop / Deep stop (avanzato)</summary>';
        html += '<div class="sd-edit-subsection">Deco stop</div>';
        html += '<div class="sd-field-row">';
        html += field('deco_stop_depth', 'Prof. (m)', 'number', dive.deco_stop_depth, '', 'half', '0.1');
        html += field('deco_stop_time', 'Durata (min)', 'number', dive.deco_stop_time, '', 'half');
        html += '</div>';
        html += '<div class="sd-edit-subsection">Deep stop</div>';
        html += '<div class="sd-field-row">';
        html += field('deep_stop_depth', 'Prof. (m)', 'number', dive.deep_stop_depth, '', 'half', '0.1');
        html += field('deep_stop_time', 'Durata (min)', 'number', dive.deep_stop_time, '', 'half');
        html += '</div>';
        html += '</details>';

        // ══════════════════════════════════════════════════════════
        // SEZIONE 4: INGRESSO IN ACQUA
        // ══════════════════════════════════════════════════════════
        html += sectionTitle('Ingresso in acqua');
        html += '<div class="sd-field-row">';
        html += selectField('entry_type', 'Tipo ingresso', dive.entry_type, {'':'—',riva:'Riva',barca:'Barca',drift:'Drift',guidata:'Guidata',resort:'Resort',liveaboard:'Liveaboard',grotta:'Grotta',ghiaccio:'Ghiaccio',piattaforma:'Piattaforma'}, 'half');
        html += '</div>';

        // ══════════════════════════════════════════════════════════
        // SEZIONE 5: CONDIZIONI
        // ══════════════════════════════════════════════════════════
        html += sectionTitle('Condizioni');
        html += '<div class="sd-edit-subsection">Meteo</div>';
        html += '<div class="sd-field-row">';
        html += selectField('weather', 'Meteo', dive.weather, {'':'—',sereno:'Sereno',nuvoloso:'Nuvoloso',pioggia:'Pioggia',notturna:'Notturna'}, 'third');
        html += field('temp_air', 'Temp. aria (°C)', 'number', dive.temp_air, '33', 'third', '0.1');
        html += field('temp_water', 'Temp. acqua (°C)', 'number', dive.temp_water, '17', 'third', '0.1');
        html += '</div>';
        html += '<div class="sd-edit-subsection">Immersione</div>';
        html += '<div class="sd-field-row">';
        html += selectField('dive_type', 'Tipo immersione', dive.dive_type, {'':'—',mare:'Mare',lago:'Lago',fiume:'Fiume',piscina:'Piscina',ghiaccio:'Ghiaccio',grotta:'Grotta'}, 'half');
        html += '</div>';
        html += '<div class="sd-edit-subsection">Condizioni</div>';
        html += '<div class="sd-field-row">';
        html += selectField('sea_condition', 'Mare', dive.sea_condition, {'':'—',calmo:'Calmo',mosso:'Mosso',agitato:'Agitato'}, 'third');
        html += selectField('current_strength', 'Corrente', dive.current_strength, {'':'—',debole:'Debole',media:'Media',forte:'Forte'}, 'third');
        html += selectField('visibility', 'Visibilità', dive.visibility, {'':'—',buona:'Buona',media:'Media',scarsa:'Scarsa'}, 'third');
        html += '</div>';
        html += '<div class="sd-edit-subsection">Dati fisiologici e sicurezza</div>';
        html += '<div class="sd-field-row">';
        html += selectField('thermal_comfort', 'Comfort termico', dive.thermal_comfort, {'':'—',molto_freddo:'Molto freddo',freddo:'Freddo',confortevole:'Confortevole',caldo:'Caldo',molto_caldo:'Molto caldo'}, 'half');
        html += selectField('workload', 'Carico di lavoro', dive.workload, {'':'—',leggero:'Leggero',moderato:'Moderato',intenso:'Intenso'}, 'half');
        html += '</div>';
        html += '<div class="sd-field-row">';
        html += selectField('problems', 'Problemi', dive.problems, {'':'—',nessuno:'Nessuno',galleggiamento:'Galleggiamento',navigazione:'Navigazione',compagno_perso:'Compagno perso',aggrovigliamento:'Aggrovigliamento',attrezzatura:'Attrezzatura',visibilita:'Visibilità scarsa',altro:'Altro'}, 'half');
        html += selectField('malfunctions', 'Guasti attrezzatura', dive.malfunctions, {'':'—',nessuno:'Nessuno',maschera:'Maschera',erogatore:'Erogatore',gav:'GAV',computer:'Computer',muta:'Muta',muta_stagna:'Muta stagna',pinna:'Pinna',bombola:'Bombola',altro:'Altro'}, 'half');
        html += '</div>';
        html += '<div class="sd-field-row">';
        html += selectField('symptoms', 'Sintomi post-immersione', dive.symptoms, {'':'—',no:'No',si:'Sì'}, 'half');
        html += selectField('exposure_to_altitude', "Esposizione all'altitudine", dive.exposure_to_altitude, {'':'—',nessuno:'Nessuna',meno_6h:'Meno di 6 ore',piu_6h:'Più di 6 ore',si:'Sì (non specificato)'}, 'half');
        html += '</div>';
        html += textareaField('gear_notes', "Note sull'equipaggiamento", dive.gear_notes);

        // ══════════════════════════════════════════════════════════
        // SEZIONE 6: NOTE E COMPAGNI
        // ══════════════════════════════════════════════════════════
        html += sectionTitle('Note e Compagni');
        html += '<div class="sd-field-row">';
        html += field('buddy_name', 'Compagno', 'text', dive.buddy_name, '', 'half');
        html += field('guide_name', 'Guida', 'text', dive.guide_name, '', 'half');
        html += '</div>';
        html += textareaField('sightings', 'Cosa ho visto', dive.sightings);
        html += textareaField('other_equipment', 'Altro equipaggiamento', dive.other_equipment);
        html += textareaField('notes', 'Note', dive.notes);

        // ══════════════════════════════════════════════════════════
        // CONDIVISIONE DATI PER LA RICERCA
        // ══════════════════════════════════════════════════════════
        html += sectionTitle('Condivisione dati');
        html += checkboxField('shared_for_research', 'Condividi questa immersione per la ricerca scientifica', dive.shared_for_research);
        html += '<p class="sd-field-help" style="margin:-8px 0 12px 28px;font-size:0.85em;color:#666;">Se deselezionato, l\'immersione sarà visibile solo a te.</p>';

        // ══════════════════════════════════════════════════════════
        // SEZIONE DIABETE (identica struttura al form registrazione)
        // ══════════════════════════════════════════════════════════
        if (diabetes) {
            html += '<input type="hidden" name="glycemia_input_unit" value="' + sdDiveEdit.glycemiaUnit + '">';
            html += sectionTitle('Glicemie e Provvedimenti (' + glicUnit() + ')');

            var cps = [
                { key: '60', label: '-60 min', color: '#0097A7' },
                { key: '30', label: '-30 min', color: '#1565C0' },
                { key: '10', label: '-10 min', color: '#0B3D6E' },
                { key: 'post', label: 'POST', color: '#EA580C' }
            ];

            html += '<div class="sd-edit-checkpoints">';
            cps.forEach(function(cp) {
                var val = diabetes['glic_' + cp.key + '_value'];
                var displayVal = val ? displayGlic(val) : '';
                var met = diabetes['glic_' + cp.key + '_method'] || '';
                var trend = diabetes['glic_' + cp.key + '_trend'] || '';
                var choR = diabetes['glic_' + cp.key + '_cho_rapidi'] || '';
                var choL = diabetes['glic_' + cp.key + '_cho_lenti'] || '';
                var ins = diabetes['glic_' + cp.key + '_insulin'] || '';
                var cpNotes = diabetes['glic_' + cp.key + '_notes'] || '';

                html += '<div class="sd-edit-cp-card">';
                html += '<div class="sd-edit-cp-header" style="background:' + cp.color + '">' + cp.label + '</div>';
                html += '<div class="sd-edit-cp-body">';
                html += '<div class="sd-field-row">';
                html += field('glic_' + cp.key + '_value', 'Glic (' + glicUnit() + ')', 'number', displayVal, glicPlaceholder(), 'half', glicStep(), glicMin(), glicMax(), glicInputmode());
                html += selectField('glic_' + cp.key + '_method', 'Metodo', met, {'':'—',C:'Capillare',S:'Sensore'}, 'half');
                html += '</div>';
                html += '<div class="sd-field-row">';
                html += selectField('glic_' + cp.key + '_trend', 'Freccia sensore', trend, {'':'—',salita_rapida:'↑↑ Salita rapida',salita:'↑ Salita',stabile:'→ Stabile',discesa:'↓ Discesa',discesa_rapida:'↓↓ Discesa rapida'}, 'half');
                html += field('glic_' + cp.key + '_insulin', 'INS (U)', 'number', ins, '0', 'half', '0.1');
                html += '</div>';
                html += '<div class="sd-field-row">';
                html += field('glic_' + cp.key + '_cho_rapidi', 'CHO rapidi (gr)', 'number', choR, '0', 'half', '0.5');
                html += field('glic_' + cp.key + '_cho_lenti', 'CHO lenti (gr)', 'number', choL, '0', 'half', '0.5');
                html += '</div>';
                html += field('glic_' + cp.key + '_notes', 'Provvedimenti', 'text', cpNotes, 'es: 2 biscotti');
                html += '</div></div>';
            });
            html += '</div>';

            // Decisione immersione
            html += '<div class="sd-edit-subsection">Decisione immersione</div>';
            html += '<div class="sd-field-row">';
            html += selectField('dive_decision', 'Decisione', diabetes.dive_decision, {'':'—', autorizzata:'Autorizzata', sospesa:'Sospesa', annullata:'Annullata'}, 'half');
            html += field('dive_decision_reason', 'Motivazione', 'text', diabetes.dive_decision_reason, '');
            html += '</div>';

            // Terapia insulinica e chetonemia (avanzato)
            html += '<details class="sd-details"><summary>Terapia insulinica e chetonemia (avanzato)</summary>';
            html += '<div class="sd-edit-subsection">Chetonemia</div>';
            html += '<div class="sd-field-row">';
            html += checkboxField('ketone_checked', 'Controllata', diabetes.ketone_checked);
            html += field('ketone_value', 'Valore (mmol/L)', 'number', diabetes.ketone_value, '', 'half', '0.1', '0', '10');
            html += '</div>';
            html += '<div class="sd-edit-subsection">Riduzione insulina</div>';
            html += '<div class="sd-field-row">';
            html += checkboxField('basal_insulin_reduced', 'Basale ridotta', diabetes.basal_insulin_reduced);
            html += field('basal_reduction_pct', '%', 'number', diabetes.basal_reduction_pct, '%', 'quarter', '1', '0', '100');
            html += checkboxField('bolus_insulin_reduced', 'Bolo ridotto', diabetes.bolus_insulin_reduced);
            html += field('bolus_reduction_pct', '%', 'number', diabetes.bolus_reduction_pct, '%', 'quarter', '1', '0', '100');
            html += '</div>';
            html += '<div class="sd-edit-subsection">Microinfusore</div>';
            html += '<div class="sd-field-row">';
            html += checkboxField('pump_disconnected', 'Pump disconnesso', diabetes.pump_disconnected);
            html += field('pump_disconnect_time', 'Durata disconn. (min)', 'number', diabetes.pump_disconnect_time, '', 'half');
            html += '</div>';
            html += '<div class="sd-edit-subsection">Ipoglicemia in immersione</div>';
            html += checkboxField('hypo_during_dive', 'Episodio ipoglicemico durante immersione', diabetes.hypo_during_dive);
            html += textareaField('hypo_treatment', 'Trattamento effettuato', diabetes.hypo_treatment);
            html += textareaField('diabetes_notes', 'Note diabete', diabetes.diabetes_notes);
            html += '</details>';
        }

        html += '</form>';
        $('#sd-edit-panel-body').html(html);
        $('#sd-edit-panel-title').text('Modifica immersione #' + (dive.dive_number || dive.id));
        // Snapshot per rilevare modifiche non salvate
        _formSnapshot = $('#sd-edit-form').serialize();
    }

    // ============================================================
    // FIELD HELPERS
    // ============================================================
    function sectionTitle(t) {
        return '<div class="sd-edit-section-title">' + esc(t) + '</div>';
    }

    function field(name, label, type, value, placeholder, size, step, min, max, inputmode) {
        var cls = 'sd-field';
        if (size === 'half') cls += ' sd-field-half';
        else if (size === 'quarter') cls += ' sd-field-quarter';
        else if (size === 'third') cls += ' sd-field-third';
        var s = '<div class="' + cls + '"><label>' + esc(label) + '</label>';
        s += '<input type="' + type + '" name="' + name + '" value="' + esc(value || '') + '"';
        if (placeholder) s += ' placeholder="' + esc(placeholder) + '"';
        if (step) s += ' step="' + step + '"';
        if (min) s += ' min="' + min + '"';
        if (max) s += ' max="' + max + '"';
        if (inputmode) s += ' inputmode="' + inputmode + '"';
        s += '></div>';
        return s;
    }

    function selectField(name, label, current, options, size) {
        var cls = 'sd-field';
        if (size === 'half') cls += ' sd-field-half';
        else if (size === 'quarter') cls += ' sd-field-quarter';
        else if (size === 'third') cls += ' sd-field-third';
        var s = '<div class="' + cls + '"><label>' + esc(label) + '</label><select name="' + name + '">';
        for (var k in options) {
            var sel = ((current || '') === k) ? ' selected' : '';
            s += '<option value="' + k + '"' + sel + '>' + esc(options[k]) + '</option>';
        }
        s += '</select></div>';
        return s;
    }

    function textareaField(name, label, value) {
        return '<div class="sd-field"><label>' + esc(label) + '</label><textarea name="' + name + '" rows="2">' + esc(value || '') + '</textarea></div>';
    }

    function checkboxField(name, label, checked) {
        var chk = (checked == 1 || checked === true || checked === '1') ? ' checked' : '';
        return '<div class="sd-field sd-field-quarter"><label><input type="checkbox" name="' + name + '" value="1"' + chk + '> ' + esc(label) + '</label></div>';
    }

    // ============================================================
    // SAVE EDIT
    // ============================================================
    $(document).on('click', '#sd-btn-save-edit', function() {
        if (!currentDiveId) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('Salvataggio...');

        var formData = $('#sd-edit-form').serialize();
        formData += '&action=sd_update_dive&nonce=' + sdDiveEdit.nonce + '&dive_id=' + currentDiveId;

        // Aggiungi checkbox non selezionati come "0"
        $('#sd-edit-form input[type=checkbox]').each(function() {
            if (!$(this).is(':checked')) {
                formData += '&' + $(this).attr('name') + '=0';
            }
        });

        $.post(sdDiveEdit.ajaxUrl, formData, function(resp) {
            $btn.prop('disabled', false).html('<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Salva Modifiche');
            if (resp.success) {
                _formSnapshot = null; // nessuna modifica non salvata dopo il salvataggio
                showMsg(resp.data.message + ' (' + resp.data.changes_count + ' campi modificati)', 'success');

                var newSite = $('input[name="site_name"]').val();
                var newDate = $('input[name="dive_date"]').val();
                var fmtDate = '';
                if (newDate) {
                    var dp = newDate.split('-');
                    fmtDate = dp[2] + '/' + dp[1] + '/' + dp[0];
                }

                // Aggiorna card nella pagina modifica-logbook
                var $editCard = $('.sd-edit-card[data-dive-id="' + currentDiveId + '"]');
                if ($editCard.length) {
                    if ($editCard.find('.sd-meta-edited').length === 0) {
                        $editCard.find('.sd-edit-meta').append('<span class="sd-meta-badge sd-meta-edited">mod.</span>');
                    }
                    if (newSite) $editCard.find('.sd-edit-site').text(newSite);
                    if (fmtDate) $editCard.find('.sd-edit-date').text(fmtDate);
                }

                // Aggiorna card nella dashboard
                var $dashCard = $('.sd-dive-card[data-dive-id="' + currentDiveId + '"]');
                if ($dashCard.length) {
                    if ($dashCard.find('.sd-meta-edited').length === 0) {
                        $dashCard.find('.sd-dive-meta').append('<span class="sd-meta-badge sd-meta-edited">mod.</span>');
                    }
                    if (newSite) $dashCard.find('.sd-dive-site').text(newSite);
                    if (fmtDate) $dashCard.find('.sd-dive-date').text(fmtDate);

                    // Aggiorna pulsante mappa nella card
                    var savedLat = parseFloat($('input[name="site_latitude"]').val()) || 0;
                    var savedLng = parseFloat($('input[name="site_longitude"]').val()) || 0;
                    if (savedLat !== 0 && savedLng !== 0) {
                        var savedTitle = newSite || $dashCard.find('.sd-dive-site').text();
                        $dashCard
                            .attr({ 'data-lat': savedLat, 'data-lng': savedLng });
                        var $mapBtn = $dashCard.find('.sd-btn-card-map');
                        $mapBtn
                            .removeClass('sd-btn-card-map--disabled')
                            .addClass('sd-btn-card-map--active sd-btn-open-map')
                            .removeAttr('disabled')
                            .attr({ 'data-lat': savedLat, 'data-lng': savedLng, 'data-title': savedTitle })
                            .css({ cursor: 'pointer', opacity: '', 'pointer-events': '' })
                            .prop('title', 'Mostra posizione');
                    }
                }

                // Aggiorna pulsante mappa nel form
                syncFormMapButton();
            } else {
                showMsg(resp.data?.message || 'Errore', 'error');
            }
        });
    });

    // ============================================================
    // CLOSE EDIT — controlla modifiche non salvate, poi torna alla card
    // ============================================================
    $(document).on('click', '#sd-edit-panel-close, #sd-btn-cancel-edit', function() {
        // Controlla se ci sono modifiche non salvate
        var currentData = $('#sd-edit-form').serialize();
        if (_formSnapshot !== null && currentData !== _formSnapshot) {
            if (!confirm('Hai modifiche non salvate. Chiudendo perderai i dati inseriti.\n\nVuoi continuare?')) {
                return; // L'utente ha scelto di restare nel form
            }
        }

        var diveId = currentDiveId;
        _formSnapshot = null;
        currentDiveId = null;

        $('#sd-edit-panel').slideUp(200, function() {
            if (!diveId) return;
            var $card = $('.sd-dive-card[data-dive-id="' + diveId + '"], .sd-edit-card[data-dive-id="' + diveId + '"]').first();
            if ($card.length) {
                $('html, body').animate({ scrollTop: $card.offset().top - 100 }, 300);
            }
        });
    });

    // ============================================================
    // HISTORY
    // ============================================================
    $(document).on('click', '.sd-btn-history-dive', function(e) {
        e.stopPropagation();
        var diveId = $(this).data('dive-id');
        var $panel = $('#sd-history-panel');
        var $body  = $('#sd-history-panel-body');

        $body.html('<div class="sd-loading">Caricamento...</div>');
        $panel.slideDown(300);
        $('html, body').animate({ scrollTop: $panel.offset().top - 80 }, 300);

        $.post(sdDiveEdit.ajaxUrl, {
            action: 'sd_get_dive_history',
            nonce: sdDiveEdit.nonce,
            dive_id: diveId
        }, function(resp) {
            if (!resp.success || !resp.data.history.length) {
                $body.html('<p class="sd-empty-history">Nessuna modifica registrata per questa immersione.</p>');
                return;
            }
            renderHistory(resp.data.history);
        });
    });

    $(document).on('click', '#sd-history-panel-close', function() {
        $('#sd-history-panel').slideUp(200);
    });

    function renderHistory(items) {
        // Raggruppa per timestamp (stessa sessione di modifica)
        var groups = {};
        items.forEach(function(item) {
            var ts = item.created_at;
            if (!groups[ts]) groups[ts] = { user: item.display_name, changes: [] };
            groups[ts].changes.push(item);
        });

        var html = '<div class="sd-history-timeline">';
        for (var ts in groups) {
            var g = groups[ts];
            var dateStr = formatDateTime(ts);
            html += '<div class="sd-history-group">';
            html += '<div class="sd-history-group-header">';
            html += '<span class="sd-history-date">' + dateStr + '</span>';
            html += '<span class="sd-history-user">' + esc(g.user || 'Utente') + '</span>';
            html += '<span class="sd-history-count">' + g.changes.length + ' campi</span>';
            html += '</div>';
            html += '<div class="sd-history-changes">';
            g.changes.forEach(function(c) {
                var fieldLabel = humanizeField(c.field_name);
                var oldDisplay = c.old_value || '—';
                var newDisplay = c.new_value || '—';
                // Se è un campo glicemico, mostra nell'unità utente
                if (c.field_name.match(/^glic_.*_value$/) && isMmol) {
                    if (c.old_value) oldDisplay = (parseFloat(c.old_value) / FACTOR).toFixed(1) + ' mmol/L';
                    if (c.new_value) newDisplay = (parseFloat(c.new_value) / FACTOR).toFixed(1) + ' mmol/L';
                }
                html += '<div class="sd-history-change">';
                html += '<span class="sd-hc-field">' + esc(fieldLabel) + '</span>';
                html += '<span class="sd-hc-old">' + esc(oldDisplay) + '</span>';
                html += '<span class="sd-hc-arrow">→</span>';
                html += '<span class="sd-hc-new">' + esc(newDisplay) + '</span>';
                html += '</div>';
            });
            html += '</div></div>';
        }
        html += '</div>';
        $('#sd-history-panel-body').html(html);
    }

    // ============================================================
    // HELPERS
    // ============================================================
    function humanizeField(name) {
        var map = {
            dive_date: 'Data', site_name: 'Sito', dive_number: 'N° immersione',
            site_latitude: 'Latitudine', site_longitude: 'Longitudine',
            time_in: 'Ora ingresso', time_out: 'Ora uscita',
            max_depth: 'Prof. max', avg_depth: 'Prof. media', dive_time: 'Tempo',
            temp_water: 'Temp. acqua', temp_air: 'Temp. aria',
            pressure_start: 'Press. inizio', pressure_end: 'Press. fine',
            gas_mix: 'Miscela', nitrox_percentage: 'Nitrox%',
            tank_count: 'N° bombole', tank_capacity: 'Capacità bombola',
            safety_stop_depth: 'Tappa sicurezza (m)', safety_stop_time: 'Tappa sicurezza (min)',
            deco_stop_depth: 'Deco stop (m)', deco_stop_time: 'Deco stop (min)',
            deep_stop_depth: 'Deep stop (m)', deep_stop_time: 'Deep stop (min)',
            ballast_kg: 'Zavorra', entry_type: 'Tipo ingresso',
            weather: 'Meteo', sea_condition: 'Mare', current_strength: 'Corrente', visibility: 'Visibilità',
            suit_type: 'Muta', other_equipment: 'Altro equipaggiamento',
            dive_type: 'Tipo immersione',
            buddy_name: 'Compagno', guide_name: 'Guida', sightings: 'Avvistamenti', notes: 'Note',
            glic_60_value: 'Glic -60', glic_30_value: 'Glic -30', glic_10_value: 'Glic -10', glic_post_value: 'Glic POST',
            glic_60_method: 'Metodo -60', glic_30_method: 'Metodo -30', glic_10_method: 'Metodo -10', glic_post_method: 'Metodo POST',
            glic_60_trend: 'Trend -60', glic_30_trend: 'Trend -30', glic_10_trend: 'Trend -10', glic_post_trend: 'Trend POST',
            glic_60_cho_rapidi: 'CHOr -60', glic_30_cho_rapidi: 'CHOr -30', glic_10_cho_rapidi: 'CHOr -10', glic_post_cho_rapidi: 'CHOr POST',
            glic_60_cho_lenti: 'CHOl -60', glic_30_cho_lenti: 'CHOl -30', glic_10_cho_lenti: 'CHOl -10', glic_post_cho_lenti: 'CHOl POST',
            glic_60_insulin: 'INS -60', glic_30_insulin: 'INS -30', glic_10_insulin: 'INS -10', glic_post_insulin: 'INS POST',
            glic_60_notes: 'Note -60', glic_30_notes: 'Note -30', glic_10_notes: 'Note -10', glic_post_notes: 'Note POST',
            dive_decision: 'Decisione', dive_decision_reason: 'Motivazione',
            ketone_checked: 'Chetoni controllati', ketone_value: 'Valore chetoni',
            basal_insulin_reduced: 'Basale ridotta', basal_reduction_pct: 'Riduzione basale %',
            bolus_insulin_reduced: 'Bolo ridotto', bolus_reduction_pct: 'Riduzione bolo %',
            pump_disconnected: 'Pump disconnesso', pump_disconnect_time: 'Durata disconn. pump',
            hypo_during_dive: 'Ipo in immersione', hypo_treatment: 'Trattamento ipo',
            diabetes_notes: 'Note diabete', _all: 'Record completo'
        };
        return map[name] || name;
    }

    function formatDateTime(dt) {
        if (!dt) return '';
        var d = new Date(dt.replace(' ', 'T'));
        var pad = function(n) { return n < 10 ? '0' + n : n; };
        return pad(d.getDate()) + '/' + pad(d.getMonth()+1) + '/' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    var _toastTimer = null;

    function showMsg(text, type) {
        // Toast fisso in alto, visibile ovunque nella pagina
        var $toast = $('#sd-save-toast');
        if (!$toast.length) {
            $toast = $('<div id="sd-save-toast"></div>').appendTo('body');
        }
        var icon = type === 'error'
            ? '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'
            : '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';

        // Cancella il timer precedente per evitare fadeOut multipli
        if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }

        $toast
            .stop(true, true)
            .removeClass('sd-toast-success sd-toast-error')
            .addClass(type === 'error' ? 'sd-toast-error' : 'sd-toast-success')
            .html(icon + '<span>' + text + '</span>')
            // Imposta display:flex manualmente — non via CSS !important
            // così jQuery può sovrascriverlo con display:none nel fadeOut
            .css('display', 'flex')
            .hide()
            .fadeIn(250);

        var delay = type === 'error' ? 4500 : 2500;
        _toastTimer = setTimeout(function() {
            $toast.fadeOut(400);
            _toastTimer = null;
        }, delay);
    }

    function esc(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

})(jQuery);
