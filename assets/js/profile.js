/**
 * ScubaDiabetes Logbook - Profilo JS (multi-record)
 *
 * Gestisce: certificazioni, idoneità medica, contatti emergenza
 * Ogni sezione ha: lista cards, form inline, aggiungi/elimina via AJAX
 */
(function($) {
    'use strict';

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
            var numberMatch = subText.match(/N° (.+?)(?:\s|$)/);
            $form.find('[name="cert_number"]').val(numberMatch ? numberMatch[1] : '');
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
            // Extract phone, email, relationship from card
            var cardData = $(this).closest('.sd-record-card').data();
            // Fallback: extract from display text
            var phoneMatch = subText.match(/^([^·\n]+)/);
            if (phoneMatch) {
                $form.find('[name="contact_phone"]').val(phoneMatch[1].trim());
            }
            // Try to get email and relationship from data attributes if available
            var emailMatch = subText.match(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/);
            if (emailMatch) {
                $form.find('[name="contact_email"]').val(emailMatch[1].toLowerCase());
            }
            // Get relationship - look for all text after the last separator
            var parts = subText.split('·');
            if (parts.length > 1) {
                // If there are multiple parts, the last one is likely the relationship
                var rel = parts[parts.length - 1].trim();
                // Only set if it matches one of the valid options
                var validRels = ['coniuge', 'genitore', 'figlio', 'fratello', 'amico', 'medico', 'altro'];
                if (validRels.includes(rel.toLowerCase())) {
                    $form.find('[name="contact_relationship"]').val(rel.toLowerCase());
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
        var date   = $form.find('[name="cert_date"]').val();
        var number = $form.find('[name="cert_number"]').val();

        if (!agency || !level) {
            showMsg('Agenzia e livello sono obbligatori.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Salvataggio...');

        var postData = {
            action: 'sd_save_certification',
            nonce: sdProfile.nonce,
            agency: agency,
            level: level,
            cert_date: date,
            cert_number: number
        };

        var editIndex = $form.data('edit-index');
        if (editIndex !== undefined) {
            postData.edit_index = editIndex;
        }

        $.post(sdProfile.ajaxUrl, postData, function(resp) {
            if (resp.success) {
                showMsg('Certificazione salvata!', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showMsg(resp.data.message, 'error');
                $btn.prop('disabled', false).text('Salva');
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
