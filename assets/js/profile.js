/**
 * ScubaDiabetes Logbook - Profilo JS (multi-record)
 *
 * Gestisce: certificazioni, idoneità medica, contatti emergenza
 * Ogni sezione ha: lista cards, form inline, aggiungi/elimina via AJAX
 */
(function($) {
    'use strict';

    // ============================================================
    // COLLAPSIBLE SECTIONS
    // ============================================================
    // Initialize: hide bodies of collapsed sections
    $('.sd-section-collapsible.sd-section-collapsed .sd-section-body').hide();

    $(document).on('click', '.sd-section-toggle', function() {
        var $section = $(this).closest('.sd-section-collapsible');
        var $body    = $section.find('.sd-section-body').first();
        $section.toggleClass('sd-section-collapsed');
        $body.slideToggle(200);
    });

    // ============================================================
    // ALLERGIE / MEDICAMENTI — item list management
    // ============================================================

    // Add allergy
    function addAllergyItem(name) {
        if (!name.trim()) return;
        var $item = $('<div class="sd-list-item"></div>');
        $item.append($('<span class="sd-item-name"></span>').text(name.trim()));
        $item.append('<button type="button" class="sd-item-delete" title="Elimina">✕</button>');
        $('#sd-allergies-list').append($item);
    }

    $(document).on('click', '#sd-add-allergy-btn', function() {
        var $input = $('#sd-allergy-input');
        addAllergyItem($input.val());
        $input.val('').focus();
    });

    $(document).on('keydown', '#sd-allergy-input', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); $('#sd-add-allergy-btn').trigger('click'); }
    });

    // Add medication
    function addMedicationItem(name, sospeso) {
        if (!name.trim()) return;
        var $item = $('<div class="sd-list-item sd-med-item"></div>');
        $item.append($('<span class="sd-item-name"></span>').text(name.trim()));
        var $cb = $('<input type="checkbox" class="sd-sospeso-cb">');
        if (sospeso) $cb.prop('checked', true);
        $item.append($('<label class="sd-sospeso-label"></label>').append($cb));
        $item.append('<button type="button" class="sd-item-delete" title="Elimina">✕</button>');
        $('#sd-medications-list').append($item);
    }

    $(document).on('click', '#sd-add-medication-btn', function() {
        var $input = $('#sd-medication-input');
        addMedicationItem($input.val(), false);
        $input.val('').focus();
    });

    $(document).on('keydown', '#sd-medication-input', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); $('#sd-add-medication-btn').trigger('click'); }
    });

    // Delete item (both lists)
    $(document).on('click', '#sd-allergies-list .sd-item-delete, #sd-medications-list .sd-item-delete', function() {
        $(this).closest('.sd-list-item').remove();
    });

    // Serialize lists to hidden JSON inputs before submit
    function serializeLists() {
        var allergies = [];
        $('#sd-allergies-list .sd-list-item').each(function() {
            allergies.push($(this).find('.sd-item-name').text().trim());
        });
        $('#sd-allergies-json').val(JSON.stringify(allergies));

        var medications = [];
        $('#sd-medications-list .sd-list-item').each(function() {
            medications.push({
                name:    $(this).find('.sd-item-name').text().trim(),
                sospeso: $(this).find('.sd-sospeso-cb').is(':checked')
            });
        });
        $('#sd-medications-json').val(JSON.stringify(medications));
    }

    // ============================================================
    // SAVE PERSONAL DATA
    // ============================================================
    $('#sd-personal-form').on('submit', function(e) {
        e.preventDefault();
        serializeLists();
        var $btn = $('#sd-btn-save-personal');
        $btn.prop('disabled', true).text('Salvataggio...');

        $.post(sdProfile.ajaxUrl, $(this).serialize(), function(resp) {
            if (resp.success) {
                showMsg(resp.data.message, 'success');
            } else {
                showMsg(resp.data?.message || 'Errore', 'error');
            }
            $btn.prop('disabled', false).text('Salva dati personali');
        });
    });

    // ============================================================
    // UNIT TOGGLE (mg/dL ↔ mmol/L)
    // ============================================================
    $(document).on('click', '.sd-unit-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var value = $btn.data('value');
        $btn.siblings('.sd-unit-btn').removeClass('active');
        $btn.addClass('active');
        $btn.closest('.sd-field').find('input[name="glycemia_unit"]').val(value);
    });

    // ============================================================
    // SHOW / HIDE INLINE FORMS & EDIT FUNCTIONALITY
    // ============================================================
    $(document).on('click', '.sd-btn-add-record', function() {
        var formId = $(this).data('form');
        var $form = $('#' + formId);
        $form.slideDown(200);
        $form.find('input, select, textarea').first().focus();
        $(this).hide();
    });

    $(document).on('click', '.sd-rec-edit', function() {
        var type = $(this).data('type');
        var index = $(this).data('index');
        var $card = $(this).closest('.sd-record-card');

        var formMap = {
            'certification': 'sd-cert-form',
            'medical_clearance': 'sd-clearance-form',
            'emergency_contact': 'sd-contact-form'
        };

        var formId = formMap[type];
        if (!formId) return;

        var $form = $('#' + formId);

        // Populate form with card data
        if (type === 'certification') {
            $form.find('[name="cert_agency"]').val($card.find('.sd-record-title').text().split(' — ')[0]);
            $form.find('[name="cert_level"]').val($card.find('.sd-record-title').text().split(' — ')[1]);
            var subText = $card.find('.sd-record-sub').text();
            var dateMatch = subText.match(/(\d{2}\/\d{2}\/\d{4})/);
            if (dateMatch) {
                var parts = dateMatch[0].split('/');
                $form.find('[name="cert_date"]').val(parts[2] + '-' + parts[1] + '-' + parts[0]);
            }
            var numberMatch = subText.match(/N° (.+?)(?:\s·|$)/);
            $form.find('[name="cert_number"]').val(numberMatch ? numberMatch[1] : '');
            // Show existing document name if present
            var docName = $card.data('doc-name');
            var docUrl  = $card.data('doc-url');
            var $docInfo = $form.find('.sd-cert-doc-current');
            if (docName) {
                $docInfo.html('Documento attuale: <a href="' + docUrl + '" target="_blank">📎 ' + $('<span>').text(docName).html() + '</a> (lascia il campo vuoto per mantenerlo)').show();
            } else {
                $docInfo.hide().html('');
            }
        } else if (type === 'medical_clearance') {
            var title = $card.find('.sd-record-title').text();
            var dates = title.split('→');
            if (dates[0]) {
                var dateParts = dates[0].trim().split('/');
                $form.find('[name="clearance_date"]').val(dateParts[2] + '-' + dateParts[1] + '-' + dateParts[0]);
            }
            if (dates[1]) {
                var expiryParts = dates[1].trim().split('/');
                $form.find('[name="clearance_expiry"]').val(expiryParts[2] + '-' + expiryParts[1] + '-' + expiryParts[0]);
            }
            var subText = $card.find('.sd-record-sub').text();
            var typeMatch = subText.match(/^([^ ·]+)/);
            $form.find('[name="clearance_type"]').val(typeMatch ? typeMatch[1] : '');
            var doctorMatch = subText.match(/Dr\. (.+?)(?:\s·|$)/);
            $form.find('[name="clearance_doctor"]').val(doctorMatch ? doctorMatch[1] : '');
        } else if (type === 'emergency_contact') {
            var title = $card.find('.sd-record-title').text();
            $form.find('[name="contact_name"]').val(title);
            var subText = $card.find('.sd-record-sub').text();

            // Split by separator to get individual fields
            var parts = subText.split('·').map(function(s) { return s.trim(); });

            // Extract phone (first part)
            if (parts[0]) {
                $form.find('[name="contact_phone"]').val(parts[0]);
            }

            // Extract email (second part if exists)
            if (parts.length > 1 && parts[1]) {
                var emailStr = parts[1];
                // Check if it looks like an email
                if (emailStr.includes('@')) {
                    $form.find('[name="contact_email"]').val(emailStr.toLowerCase());
                }
            }

            // Extract relationship (last part)
            if (parts.length > 1) {
                var relStr = parts[parts.length - 1];
                var validRels = ['coniuge', 'genitore', 'figlio', 'fratello', 'amico', 'medico', 'altro'];
                if (validRels.includes(relStr.toLowerCase())) {
                    $form.find('[name="contact_relationship"]').val(relStr.toLowerCase());
                }
            }
        }

        // Store edit index
        $form.data('edit-index', index);

        // Show form
        $form.slideDown(200);
        $form.find('input, select, textarea').first().focus();
        $('[data-form="' + formId + '"].sd-btn-add-record').hide();
    });

    $(document).on('click', '.sd-btn-cancel-record', function() {
        var formId = $(this).data('form');
        var $form = $('#' + formId);
        // Reset fields
        $form.find('input:not([type=hidden]), select, textarea').val('');
        $form.find('input[type=file]').val('');
        $form.find('.sd-cert-doc-current').hide().html('');
        $form.removeData('edit-index');
        $form.slideUp(200);
        // Show add button again
        $('[data-form="' + formId + '"].sd-btn-add-record').show();
    });

    // ============================================================
    // SAVE CERTIFICATION
    // ============================================================
    $(document).on('click', '.sd-btn-save-record[data-type="certification"]', function() {
        var $btn = $(this);
        var $form = $btn.closest('.sd-add-form');

        var agency = $form.find('[name="cert_agency"]').val();
        var level  = $form.find('[name="cert_level"]').val();

        if (!agency || !level) {
            showMsg('Agenzia e livello sono obbligatori.', 'error');
            return;
        }

        // Validate file if selected
        var fileInput = $form.find('[name="cert_doc"]')[0];
        if (fileInput && fileInput.files.length > 0) {
            var file = fileInput.files[0];
            var allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            var ext = file.name.split('.').pop().toLowerCase();
            if (allowed.indexOf(ext) === -1) {
                showMsg('Formato non valido. Usa PDF, JPG o PNG.', 'error');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                showMsg('Il file supera i 5 MB.', 'error');
                return;
            }
        }

        $btn.prop('disabled', true).text('Salvataggio...');

        var formData = new FormData();
        formData.append('action', 'sd_save_certification');
        formData.append('nonce', sdProfile.nonce);
        formData.append('agency', agency);
        formData.append('level', level);
        formData.append('cert_date', $form.find('[name="cert_date"]').val());
        formData.append('cert_number', $form.find('[name="cert_number"]').val());

        var editIndex = $form.data('edit-index');
        if (editIndex !== undefined) {
            formData.append('edit_index', editIndex);
        }

        if (fileInput && fileInput.files.length > 0) {
            formData.append('cert_doc', fileInput.files[0]);
        }

        $.ajax({
            url: sdProfile.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(resp) {
                if (resp.success) {
                    showMsg('Certificazione salvata!', 'success');
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    showMsg(resp.data.message, 'error');
                    $btn.prop('disabled', false).text('Salva');
                }
            }
        });
    });

    // ============================================================
    // SAVE MEDICAL CLEARANCE (with file upload via FormData)
    // ============================================================
    $(document).on('click', '.sd-btn-save-record[data-type="medical_clearance"]', function() {
        var $btn = $(this);
        var $form = $btn.closest('.sd-add-form');

        var date = $form.find('[name="clearance_date"]').val();
        if (!date) {
            showMsg('La data di rilascio è obbligatoria.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Salvataggio...');

        var formData = new FormData();
        formData.append('action', 'sd_save_medical_clearance');
        formData.append('nonce', sdProfile.nonce);
        formData.append('clearance_date', date);
        formData.append('clearance_expiry', $form.find('[name="clearance_expiry"]').val());
        formData.append('clearance_type', $form.find('[name="clearance_type"]').val());
        formData.append('clearance_doctor', $form.find('[name="clearance_doctor"]').val());
        formData.append('clearance_notes', $form.find('[name="clearance_notes"]').val());

        var editIndex = $form.data('edit-index');
        if (editIndex !== undefined) {
            formData.append('edit_index', editIndex);
        }

        var fileInput = $form.find('[name="clearance_doc"]')[0];
        if (fileInput && fileInput.files.length > 0) {
            var file = fileInput.files[0];
            if (file.size > 5 * 1024 * 1024) {
                showMsg('Il file supera i 5 MB.', 'error');
                $btn.prop('disabled', false).text('Salva');
                return;
            }
            formData.append('clearance_doc', file);
        }

        $.ajax({
            url: sdProfile.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(resp) {
                if (resp.success) {
                    showMsg('Idoneità salvata!', 'success');
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    showMsg(resp.data.message, 'error');
                    $btn.prop('disabled', false).text('Salva');
                }
            }
        });
    });

    // ============================================================
    // SAVE EMERGENCY CONTACT
    // ============================================================
    $(document).on('click', '.sd-btn-save-record[data-type="emergency_contact"]', function() {
        var $btn = $(this);
        var $form = $btn.closest('.sd-add-form');

        var name  = $form.find('[name="contact_name"]').val();
        var phone = $form.find('[name="contact_phone"]').val();
        var email = $form.find('[name="contact_email"]').val().toLowerCase(); // Convert to lowercase
        var rel   = $form.find('[name="contact_relationship"]').val();
        var notes = $form.find('[name="contact_notes"]').val();

        if (!name || !phone) {
            showMsg('Nome e telefono sono obbligatori.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Salvataggio...');

        var postData = {
            action: 'sd_save_emergency_contact',
            nonce: sdProfile.nonce,
            contact_name: name,
            contact_phone: phone,
            contact_email: email,
            contact_relationship: rel,
            contact_notes: notes
        };

        var editIndex = $form.data('edit-index');
        if (editIndex !== undefined) {
            postData.edit_index = editIndex;
        }

        $.post(sdProfile.ajaxUrl, postData, function(resp) {
            if (resp.success) {
                showMsg('Contatto salvato!', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showMsg(resp.data.message, 'error');
                $btn.prop('disabled', false).text('Salva');
            }
        });
    });

    // ============================================================
    // DELETE RECORD (generic for all types)
    // ============================================================
    $(document).on('click', '.sd-rec-delete', function() {
        var type = $(this).data('type');
        var index = $(this).data('index');
        var $card = $(this).closest('.sd-record-card');

        var labels = {
            'certification': 'questa certificazione',
            'medical_clearance': 'questa idoneità medica',
            'emergency_contact': 'questo contatto'
        };

        if (!confirm('Eliminare ' + (labels[type] || 'questo record') + '?')) return;

        $card.addClass('sd-removing');

        $.post(sdProfile.ajaxUrl, {
            action: 'sd_delete_' + type,
            nonce: sdProfile.nonce,
            index: index
        }, function(resp) {
            if (resp.success) {
                $card.slideUp(300, function() { $(this).remove(); });
            } else {
                $card.removeClass('sd-removing');
            }
        });
    });

    // ============================================================
    // SAVE SHARING PREFERENCE
    // ============================================================
    $('#sd-sharing-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#sd-btn-save-sharing');
        $btn.prop('disabled', true).text('Salvataggio...');

        var formData = $(this).serialize();
        // Ensure unchecked checkbox sends 0
        if (!$(this).find('input[name="default_shared_for_research"]').is(':checked')) {
            formData += '&default_shared_for_research=0';
        }

        $.post(sdProfile.ajaxUrl, formData, function(resp) {
            if (resp.success) {
                showMsg(resp.data.message, 'success');
            } else {
                showMsg(resp.data?.message || 'Errore', 'error');
            }
            $btn.prop('disabled', false).text('Salva preferenza');
        });
    });

    // ============================================================
    // SAVE DIABETES PROFILE
    // ============================================================
    $('#sd-diabetes-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#sd-btn-save-diabetes');
        $btn.prop('disabled', true).text('Salvataggio...');

        $.post(sdProfile.ajaxUrl, $(this).serialize(), function(resp) {
            if (resp.success) {
                showMsg(resp.data.message, 'success');
            } else {
                showMsg(resp.data.message, 'error');
            }
            $btn.prop('disabled', false).text('Salva dati diabete');
        });
    });

    // ============================================================
    // TOAST MESSAGE
    // ============================================================
    function showMsg(text, type) {
        var $msg = $('#sd-profile-messages');
        $msg.stop(true).removeClass('sd-msg-success sd-msg-error')
            .addClass(type === 'error' ? 'sd-msg-error' : 'sd-msg-success')
            .html(text).fadeIn(200);

        setTimeout(function() {
            $msg.fadeOut(400);
        }, 3000);
    }

})(jQuery);
