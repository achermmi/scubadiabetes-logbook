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
        $('#sd-gas-mix').on('change', function() {
            var val = $(this).val();
            if (val === 'nitrox' || val === 'trimix') {
                $('.sd-field-nitrox').slideDown(200);
            } else {
                $('.sd-field-nitrox').slideUp(200);
                $('#sd-nitrox-pct').val('');
            }
        });

        // ============================================================
        // CALCOLO AUTOMATICO TEMPO IMMERSIONE
        // ============================================================
        $('#sd-time-in, #sd-time-out').on('change', function() {
            var timeIn = $('#sd-time-in').val();
            var timeOut = $('#sd-time-out').val();
            if (timeIn && timeOut) {
                var start = timeToMinutes(timeIn);
                var end = timeToMinutes(timeOut);
                if (end > start) {
                    var duration = end - start;
                    var $diveTime = $('#sd-dive-time');
                    // Solo se il campo è vuoto
                    if (!$diveTime.val()) {
                        $diveTime.val(duration);
                    }
                }
            }
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

                        // Se diabetico, prepara per step dati glicemici
                        if (response.data.is_diabetic) {
                            // Salva dive_id per il form glicemie (Step 4)
                            $form.append('<input type="hidden" name="saved_dive_id" value="' + response.data.dive_id + '">');
                            // Mostra messaggio specifico
                            showMessage($messages, 'success',
                                response.data.message + '<br><strong>Ora compila i dati glicemici →</strong>');
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
