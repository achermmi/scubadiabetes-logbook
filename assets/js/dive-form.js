/**
 * ScubaDiabetes Logbook - Form Immersione JS
 * Gestisce: icon buttons, mostra/nascondi nitrox, salvataggio AJAX
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // ============================================================
        // DEFAULT CONDIVISIONE RICERCA (dal profilo utente)
        // ============================================================
        if (typeof sdLogbook !== 'undefined' && sdLogbook.defaultShared !== undefined) {
            $('#sd-shared-for-research').prop('checked', parseInt(sdLogbook.defaultShared) === 1);
        }

        // ============================================================
        // ICON SELECT BUTTONS
        // Click su un bottone icona per selezionarlo (toggle)
        // ============================================================
        $(document).on('click', '.sd-icon-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $group = $btn.closest('.sd-icon-select');
            var fieldName = $group.data('name');
            var value = $btn.data('value');

            // Se già attivo, deseleziona
            if ($btn.hasClass('active')) {
                $btn.removeClass('active');
                $group.siblings('input[name="' + fieldName + '"]').val('');
            } else {
                // Deseleziona tutti nel gruppo, seleziona questo
                $group.find('.sd-icon-btn').removeClass('active');
                $btn.addClass('active');
                $group.siblings('input[name="' + fieldName + '"]').val(value);
            }
        });

        // ============================================================
        // MOSTRA/NASCONDI CAMPO NITROX %
        // ============================================================
        function toggleNitroxField() {
            var val = $('#sd-gas-mix').val();
            if (val === 'nitrox' || val === 'trimix') {
                $('.sd-field-nitrox').slideDown(200);
            } else {
                $('.sd-field-nitrox').slideUp(200);
                $('#sd-nitrox-pct').val('');
            }
        }

        $('#sd-gas-mix').on('change', toggleNitroxField);

        // ============================================================
        // PROFILI ATTREZZATURA RIUSABILI
        // ============================================================
        var gearProfiles = [];

        var $gearSelect = $('#sd-gear-profile-select');
        var $gearName = $('#sd-gear-profile-name');
        var $gearMsg = $('#sd-gear-profile-message');

        function fetchGearProfiles() {
            $.ajax({
                url: sdLogbook.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'sd_gear_profiles_list',
                    nonce: sdLogbook.nonce
                },
                success: function(response) {
                    if (!response.success || !response.data || !Array.isArray(response.data.profiles)) {
                        showGearMessage('error', sdLogbook.strings.gearProfilesLoadError);
                        return;
                    }
                    gearProfiles = response.data.profiles;
                    renderGearProfileOptions();
                },
                error: function() {
                    showGearMessage('error', sdLogbook.strings.gearProfilesLoadError);
                }
            });
        }

        function renderGearProfileOptions(selectedId) {
            selectedId = selectedId || '';
            $gearSelect.empty();
            $gearSelect.append('<option value="">Seleziona un profilo</option>');

            gearProfiles.forEach(function(profile) {
                var $opt = $('<option></option>')
                    .val(profile.id)
                    .text(profile.name || 'Profilo');
                if (selectedId && profile.id === selectedId) {
                    $opt.prop('selected', true);
                }
                $gearSelect.append($opt);
            });
        }

        function getProfileById(profileId) {
            return gearProfiles.find(function(profile) {
                return profile.id === profileId;
            });
        }

        function getCurrentGearData() {
            return {
                tank_count: $('[name="tank_count"]').val() || '1',
                gas_mix: $('[name="gas_mix"]').val() || 'aria',
                tank_capacity: $('[name="tank_capacity"]').val() || '',
                nitrox_percentage: $('[name="nitrox_percentage"]').val() || '',
                ballast_kg: $('[name="ballast_kg"]').val() || '',
                suit_type: $('[name="suit_type"]').val() || '',
                gear_notes: $('[name="gear_notes"]').val() || ''
            };
        }

        function setIconSelectValue(fieldName, value) {
            var $hidden = $('[name="' + fieldName + '"]');
            var $group = $('.sd-icon-select[data-name="' + fieldName + '"]');
            $group.find('.sd-icon-btn').removeClass('active');
            if (value) {
                $group.find('.sd-icon-btn[data-value="' + value + '"]').addClass('active');
            }
            $hidden.val(value || '');
        }

        function applyGearData(data) {
            if (!data) {
                return;
            }
            $('[name="tank_count"]').val(data.tank_count || '1');
            $('[name="gas_mix"]').val(data.gas_mix || 'aria');
            $('[name="tank_capacity"]').val(data.tank_capacity || '');
            $('[name="nitrox_percentage"]').val(data.nitrox_percentage || '');
            $('[name="ballast_kg"]').val(data.ballast_kg || '');
            $('[name="gear_notes"]').val(data.gear_notes || '');
            setIconSelectValue('suit_type', data.suit_type || '');
            toggleNitroxField();
        }

        function saveGearProfile() {
            var selectedId = $gearSelect.val() || '';
            var profileName = ($gearName.val() || '').trim();

            if (!profileName && selectedId) {
                var selectedProfile = getProfileById(selectedId);
                profileName = selectedProfile && selectedProfile.name ? selectedProfile.name : '';
            }

            if (!profileName) {
                showGearMessage('error', sdLogbook.strings.gearProfileNameRequired);
                $gearName.focus();
                return;
            }

            var payload = getCurrentGearData();

            $.ajax({
                url: sdLogbook.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'sd_gear_profile_save',
                    nonce: sdLogbook.nonce,
                    profile_id: selectedId,
                    profile_name: profileName,
                    profile: payload
                },
                success: function(response) {
                    if (!response.success || !response.data || !Array.isArray(response.data.profiles)) {
                        showGearMessage('error', sdLogbook.strings.gearProfilesSaveError);
                        return;
                    }
                    gearProfiles = response.data.profiles;
                    renderGearProfileOptions(response.data.profile_id || selectedId);
                    showGearMessage('success', sdLogbook.strings.gearProfileSaved);
                },
                error: function() {
                    showGearMessage('error', sdLogbook.strings.gearProfilesSaveError);
                }
            });
        }

        function deleteGearProfile() {
            var selectedId = $gearSelect.val() || '';
            if (!selectedId) {
                showGearMessage('error', sdLogbook.strings.gearProfileSelectRequired);
                return;
            }

            if (!window.confirm(sdLogbook.strings.gearProfileDeleteConfirm)) {
                return;
            }

            $.ajax({
                url: sdLogbook.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'sd_gear_profile_delete',
                    nonce: sdLogbook.nonce,
                    profile_id: selectedId
                },
                success: function(response) {
                    if (!response.success || !response.data || !Array.isArray(response.data.profiles)) {
                        showGearMessage('error', sdLogbook.strings.gearProfilesDeleteError);
                        return;
                    }
                    gearProfiles = response.data.profiles;
                    renderGearProfileOptions('');
                    $gearName.val('');
                    showGearMessage('success', sdLogbook.strings.gearProfileDeleted);
                },
                error: function() {
                    showGearMessage('error', sdLogbook.strings.gearProfilesDeleteError);
                }
            });
        }

        function duplicateGearProfile() {
            var selectedId = $gearSelect.val() || '';
            if (!selectedId) {
                showGearMessage('error', sdLogbook.strings.gearProfileSelectRequired);
                return;
            }

            $.ajax({
                url: sdLogbook.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'sd_gear_profile_duplicate',
                    nonce: sdLogbook.nonce,
                    profile_id: selectedId
                },
                success: function(response) {
                    if (!response.success || !response.data || !Array.isArray(response.data.profiles)) {
                        showGearMessage('error', sdLogbook.strings.gearProfileDuplicateError);
                        return;
                    }
                    gearProfiles = response.data.profiles;
                    renderGearProfileOptions(response.data.profile_id || '');
                    var duplicated = getProfileById(response.data.profile_id || '');
                    $gearName.val(duplicated && duplicated.name ? duplicated.name : '');
                    showGearMessage('success', sdLogbook.strings.gearProfileDuplicated);
                },
                error: function() {
                    showGearMessage('error', sdLogbook.strings.gearProfileDuplicateError);
                }
            });
        }

        function reorderGearProfiles(direction) {
            var selectedId = $gearSelect.val() || '';
            if (!selectedId) {
                showGearMessage('error', sdLogbook.strings.gearProfileSelectRequired);
                return;
            }

            if (gearProfiles.length < 2) {
                showGearMessage('error', sdLogbook.strings.gearProfilesReorderNeedTwo);
                return;
            }

            var currentIndex = -1;
            for (var i = 0; i < gearProfiles.length; i++) {
                if (gearProfiles[i].id === selectedId) {
                    currentIndex = i;
                    break;
                }
            }

            if (currentIndex < 0) {
                showGearMessage('error', sdLogbook.strings.gearProfilesLoadError);
                return;
            }

            var targetIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1;
            if (targetIndex < 0 || targetIndex >= gearProfiles.length) {
                showGearMessage('error', sdLogbook.strings.gearProfilesReorderAtBoundary);
                return;
            }

            var moved = gearProfiles.splice(currentIndex, 1)[0];
            gearProfiles.splice(targetIndex, 0, moved);

            var orderedIds = gearProfiles.map(function(profile) {
                return profile.id;
            });

            $.ajax({
                url: sdLogbook.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'sd_gear_profiles_reorder',
                    nonce: sdLogbook.nonce,
                    'ordered_ids[]': orderedIds
                },
                success: function(response) {
                    if (!response.success || !response.data || !Array.isArray(response.data.profiles)) {
                        showGearMessage('error', sdLogbook.strings.gearProfilesReorderError);
                        fetchGearProfiles();
                        return;
                    }
                    gearProfiles = response.data.profiles;
                    renderGearProfileOptions(selectedId);
                    showGearMessage('success', sdLogbook.strings.gearProfilesOrderSaved);
                },
                error: function() {
                    showGearMessage('error', sdLogbook.strings.gearProfilesReorderError);
                    fetchGearProfiles();
                }
            });
        }

        function applySelectedGearProfile() {
            var selectedId = $gearSelect.val() || '';
            if (!selectedId) {
                showGearMessage('error', sdLogbook.strings.gearProfileSelectRequired);
                return;
            }

            var profile = getProfileById(selectedId);
            if (!profile || !profile.fields) {
                showGearMessage('error', sdLogbook.strings.gearProfilesLoadError);
                return;
            }

            applyGearData(profile.fields);
            $gearName.val(profile.name || '');
            showGearMessage('success', sdLogbook.strings.gearProfileApplied);
        }

        function showGearMessage(type, msg) {
            $gearMsg
                .removeClass('sd-gear-msg-success sd-gear-msg-error')
                .addClass(type === 'success' ? 'sd-gear-msg-success' : 'sd-gear-msg-error')
                .text(msg)
                .stop(true, true)
                .slideDown(120);
        }

        $gearSelect.on('change', function() {
            var profile = getProfileById($(this).val() || '');
            $gearName.val(profile && profile.name ? profile.name : '');
        });

        $('#sd-gear-profile-apply').on('click', applySelectedGearProfile);
        $('#sd-gear-profile-save').on('click', saveGearProfile);
        $('#sd-gear-profile-duplicate').on('click', duplicateGearProfile);
        $('#sd-gear-profile-up').on('click', function() { reorderGearProfiles('up'); });
        $('#sd-gear-profile-down').on('click', function() { reorderGearProfiles('down'); });
        $('#sd-gear-profile-delete').on('click', deleteGearProfile);

        fetchGearProfiles();
        toggleNitroxField();

        // ============================================================
        // CALCOLO AUTOMATICO TEMPO IMMERSIONE
        // ============================================================
        var _diveTimeTimer = null;
        $('#sd-time-out').on('change blur input', function() {
            clearTimeout(_diveTimeTimer);
            var $out = $(this);
            _diveTimeTimer = setTimeout(function() {
                var timeIn  = $('#sd-time-in').val();
                var timeOut = $out.val();
                if (timeIn && timeOut && /^\d{2}:\d{2}$/.test(timeOut)) {
                    var start    = timeToMinutes(timeIn);
                    var end      = timeToMinutes(timeOut);
                    var $diveTime = $('#sd-dive-time');
                    if (end > start) {
                        $diveTime.val(end - start);
                    }
                }
            }, 800);
        });

        function timeToMinutes(timeStr) {
            var parts = timeStr.split(':');
            return parseInt(parts[0]) * 60 + parseInt(parts[1]);
        }

        // ============================================================
        // SALVATAGGIO AJAX
        // ============================================================
        $('#sd-dive-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $('#sd-btn-save');
            var $messages = $('.sd-form-messages');

            // Validazione base
            var date = $form.find('[name="dive_date"]').val();
            var site = $form.find('[name="site_name"]').val();

            if (!date) {
                showMessage($messages, 'error', sdLogbook.strings.required + ': Data');
                $form.find('[name="dive_date"]').focus();
                return;
            }
            if (!site) {
                showMessage($messages, 'error', sdLogbook.strings.required + ': Sito di immersione');
                $form.find('[name="site_name"]').focus();
                return;
            }

            // Disabilita pulsante
            $btn.prop('disabled', true);
            $btn.find('span').text(sdLogbook.strings.saving);

            // Invia
            $.ajax({
                url: sdLogbook.ajaxUrl,
                type: 'POST',
                data: $form.serialize(),
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showMessage($messages, 'success', response.data.message);

                        // Aggiorna/imposta saved_dive_id per trasformare i save successivi in UPDATE
                        var $existingId = $form.find('[name="saved_dive_id"]');
                        if ($existingId.length) {
                            $existingId.val(response.data.dive_id);
                        } else {
                            $form.append('<input type="hidden" name="saved_dive_id" value="' + response.data.dive_id + '">');
                        }

                        // Mostra pulsante Chiudi → Dashboard
                        if (!$('#sd-btn-close').length) {
                            $btn.after('<a id="sd-btn-close" href="' + sdLogbook.dashboardUrl + '" class="sd-btn-close-form">✕ Chiudi</a>');
                        }

                        // Se diabetico, controlla se i 4 checkpoint capillari obbligatori sono compilati
                        if (response.data.is_diabetic) {
                            var requiredCaps = ['glic_60_cap', 'glic_30_cap', 'glic_10_cap', 'glic_post_cap'];
                            var missing = [];
                            requiredCaps.forEach(function(name) {
                                var $field = $form.find('[name="' + name + '"]');
                                if (!$field.val() || $field.val().trim() === '') {
                                    missing.push(name);
                                    $field.addClass('sd-field-required-missing');
                                } else {
                                    $field.removeClass('sd-field-required-missing');
                                }
                            });

                            if (missing.length > 0) {
                                showMessage($messages, 'success',
                                    response.data.message + '<br><strong>Ora compila i dati glicemici →</strong>');
                            }
                        }

                        // Scroll al messaggio
                        $('html, body').animate({
                            scrollTop: $messages.offset().top - 80
                        }, 300);

                        // Reset pulsante
                        $btn.find('span').text('✓ ' + sdLogbook.strings.saved);
                        setTimeout(function() {
                            $btn.prop('disabled', false);
                            $btn.find('span').text('Salva Immersione');
                        }, 3000);

                    } else {
                        showMessage($messages, 'error', response.data.message);
                        $btn.prop('disabled', false);
                        $btn.find('span').text('Salva Immersione');
                    }
                },
                error: function() {
                    showMessage($messages, 'error', sdLogbook.strings.error);
                    $btn.prop('disabled', false);
                    $btn.find('span').text('Salva Immersione');
                }
            });
        });

        function showMessage($el, type, msg) {
            $el.removeClass('sd-msg-success sd-msg-error')
               .addClass(type === 'success' ? 'sd-msg-success' : 'sd-msg-error')
               .html(msg)
               .slideDown(200);
        }

    });
})(jQuery);
