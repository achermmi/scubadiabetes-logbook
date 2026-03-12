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
    // SHOW / HIDE INLINE FORMS
    // ============================================================
    $(document).on('click', '.sd-btn-add-record', function() {
        var formId = $(this).data('form');
        var $form = $('#' + formId);
        $form.slideDown(200);
        $form.find('input, select, textarea').first().focus();
        $(this).hide();
    });

    $(document).on('click', '.sd-btn-cancel-record', function() {
        var formId = $(this).data('form');
        var $form = $('#' + formId);
        // Reset fields
        $form.find('input:not([type=hidden]), select, textarea').val('');
        $form.find('input[type=file]').val('');
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

        $.post(sdProfile.ajaxUrl, {
            action: 'sd_save_certification',
            nonce: sdProfile.nonce,
            agency: agency,
            level: level,
            cert_date: date,
            cert_number: number
        }, function(resp) {
            if (resp.success) {
                showMsg('Certificazione aggiunta!', 'success');
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
                    showMsg('Idoneità aggiunta!', 'success');
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
        var rel   = $form.find('[name="contact_relationship"]').val();

        if (!name || !phone) {
            showMsg('Nome e telefono sono obbligatori.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Salvataggio...');

        $.post(sdProfile.ajaxUrl, {
            action: 'sd_save_emergency_contact',
            nonce: sdProfile.nonce,
            contact_name: name,
            contact_phone: phone,
            contact_relationship: rel
        }, function(resp) {
            if (resp.success) {
                showMsg('Contatto aggiunto!', 'success');
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
