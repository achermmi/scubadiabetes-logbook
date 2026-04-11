/**
 * ScubaDiabetes - Importazione Immersioni
 * Upload file → anteprima → selezione → conferma import
 */
(function ($) {
    'use strict';

    var previewData = [];

    $(document).ready(function () {

        // ============================================================
        // DRAG & DROP on upload zone
        // ============================================================
        var $zone = $('#sd-upload-zone');

        $zone.on('dragover', function (e) {
            e.preventDefault();
            $zone.addClass('sd-drag-over');
        });
        $zone.on('dragleave', function () {
            $zone.removeClass('sd-drag-over');
        });
        $zone.on('drop', function (e) {
            e.preventDefault();
            $zone.removeClass('sd-drag-over');
            var files = e.originalEvent.dataTransfer.files;
            if (files.length) handleFile(files[0]);
        });

        // Prevent file input's own click from bubbling back to the zone (infinite loop)
        $('#sd-import-file-input').on('click', function (e) {
            e.stopPropagation();
        });

        // Click on the zone itself opens the file dialog
        $zone.on('click', function () {
            document.getElementById('sd-import-file-input').click();
        });

        // Dedicated button: stop propagation to zone, then open dialog
        $('#sd-btn-choose-file').on('click', function (e) {
            e.stopPropagation();
            document.getElementById('sd-import-file-input').click();
        });

        $('#sd-import-file-input').on('change', function () {
            if (this.files.length) handleFile(this.files[0]);
        });

        // ============================================================
        // Handle file: upload → preview
        // ============================================================
        function handleFile(file) {
            var ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'ssrf' && ext !== 'db') {
                showMessage('error', 'Formato non supportato. Usa .ssrf (Subsurface) o .db (Shearwater Cloud).');
                return;
            }
            if (file.size > 50 * 1024 * 1024) {
                showMessage('error', 'File troppo grande (max 50 MB).');
                return;
            }

            showStep('progress');

            var formData = new FormData();
            formData.append('action', 'sd_import_preview');
            formData.append('nonce', sdImport.nonce);
            formData.append('import_file', file);

            $.ajax({
                url: sdImport.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (resp) {
                    if (resp.success) {
                        previewData = resp.data.dives;
                        renderPreview(resp.data);
                        showStep('preview');
                    } else {
                        showStep('upload');
                        showMessage('error', resp.data.message || 'Errore durante la lettura del file.');
                    }
                },
                error: function () {
                    showStep('upload');
                    showMessage('error', 'Errore di connessione. Riprova.');
                }
            });
        }

        // ============================================================
        // Render preview table
        // ============================================================
        function renderPreview(data) {
            // Summary bar
            $('#sd-stat-total').text(data.total);
            $('#sd-stat-new').text(data.new);
            $('#sd-stat-dup').text(data.duplicate);
            $('#sd-import-source').text(data.source);

            var $tbody = $('#sd-preview-tbody');
            $tbody.empty();

            data.dives.forEach(function (dive, idx) {
                var isDup = dive.is_duplicate;
                var checked = !isDup ? 'checked' : '';
                var rowClass = isDup ? 'sd-row-duplicate' : '';
                var statusBadge = isDup
                    ? '<span class="sd-dup-badge">Duplicato</span>'
                    : '<span class="sd-new-badge">Nuovo</span>';

                var depthStr = dive.max_depth ? parseFloat(dive.max_depth).toFixed(1) + ' m' : '—';
                var timeStr  = dive.dive_time ? dive.dive_time + ' min' : '—';
                var tempStr  = dive.temp_water ? parseFloat(dive.temp_water).toFixed(1) + '°C' : '—';
                var pressStr = dive.pressure_start ? dive.pressure_start + '→' + (dive.pressure_end || '?') + ' bar' : '—';
                var dateStr  = formatDate(dive.dive_date);
                var timeIn   = dive.time_in ? dive.time_in : '';

                var brandStr = esc(dive.computer_brand || '—');
                var modelStr = esc(dive.computer_model || '—');

                $tbody.append(
                    '<tr class="' + rowClass + '" data-idx="' + idx + '">' +
                    '<td><input type="checkbox" class="sd-dive-check" ' + checked + '></td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td><strong>' + (dive.dive_number || '—') + '</strong></td>' +
                    '<td>' + brandStr + '</td>' +
                    '<td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;">' + modelStr + '</td>' +
                    '<td>' + dateStr + (timeIn ? '<br><small style="color:#94A3B8;">' + timeIn + '</small>' : '') + '</td>' +
                    '<td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;">' + esc(dive.site_name) + '</td>' +
                    '<td class="sd-depth-val">' + depthStr + '</td>' +
                    '<td class="sd-time-val">' + timeStr + '</td>' +
                    '<td>' + tempStr + '</td>' +
                    '<td>' + pressStr + '</td>' +
                    '<td>' + esc(dive.buddy_name || '—') + '</td>' +
                    '</tr>'
                );
            });

            updateSelectedCount();
        }

        // ============================================================
        // Select helpers
        // ============================================================
        $(document).on('change', '.sd-dive-check', updateSelectedCount);

        $('#sd-btn-select-all').on('click', function () {
            $('.sd-dive-check').prop('checked', true);
            updateSelectedCount();
        });

        $('#sd-btn-select-new').on('click', function () {
            $('.sd-dive-check').each(function () {
                var idx = $(this).closest('tr').data('idx');
                var isDup = previewData[idx] && previewData[idx].is_duplicate;
                $(this).prop('checked', !isDup);
            });
            updateSelectedCount();
        });

        $('#sd-btn-deselect-all').on('click', function () {
            $('.sd-dive-check').prop('checked', false);
            updateSelectedCount();
        });

        function updateSelectedCount() {
            var n = $('.sd-dive-check:checked').length;
            $('#sd-selected-count').text(n + ' selezionate');
            $('#sd-btn-confirm-import').prop('disabled', n === 0);
        }

        // ============================================================
        // Confirm import
        // ============================================================
        $('#sd-btn-confirm-import').on('click', function () {
            var selected = [];
            $('.sd-dive-check:checked').each(function () {
                var idx = $(this).closest('tr').data('idx');
                if (previewData[idx]) selected.push(previewData[idx]);
            });

            if (selected.length === 0) {
                showMessage('error', 'Seleziona almeno un\'immersione da importare.');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Importazione in corso…');

            // action + nonce must be in the URL so WordPress can route the request
            // and verify the nonce via $_REQUEST even with a JSON body.
            var confirmUrl = sdImport.ajaxUrl
                + '?action=sd_import_confirm&nonce=' + encodeURIComponent(sdImport.nonce);

            $.ajax({
                url: confirmUrl,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ dives: selected }),
                success: function (resp) {
                    if (resp.success) {
                        showResult(resp.data);
                        showStep('result');
                    } else {
                        showMessage('error', resp.data.message || 'Errore durante l\'importazione.');
                        $btn.prop('disabled', false).text('Importa le immersioni selezionate');
                    }
                },
                error: function () {
                    showMessage('error', 'Errore di connessione.');
                    $btn.prop('disabled', false).text('Importa le immersioni selezionate');
                }
            });
        });

        // ============================================================
        // Reset (preview step + result step)
        // ============================================================
        function doReset() {
            previewData = [];
            $('#sd-import-file-input').val('');
            $('#sd-import-messages').hide().html('');
            showStep('upload');
        }

        $('#sd-btn-reset-import').on('click', doReset);
        $('#sd-btn-reset-import-result').on('click', doReset);

        // ============================================================
        // Show result
        // ============================================================
        function showResult(data) {
            var errors = data.errors && data.errors.length > 0
                ? '<p style="font-size:12px;color:#DC2626;margin-top:6px;">Errori: ' + data.errors.join(', ') + '</p>'
                : '';
            $('#sd-result-icon').text(data.imported > 0 ? '🎉' : '⚠️');
            $('#sd-result-title').text(
                data.imported > 0
                    ? data.imported + ' immersion' + (data.imported === 1 ? 'e importata' : 'i importate') + ' con successo!'
                    : 'Nessuna immersione importata.'
            );
            $('#sd-result-sub').html(
                (data.skipped > 0 ? data.skipped + ' già presenti (saltate). ' : '') + errors
            );
            $('#sd-result-panel').toggleClass('sd-result-success', data.imported > 0)
                                  .toggleClass('sd-result-error', data.imported === 0);
        }

        // ============================================================
        // Step navigation
        // ============================================================
        function showStep(step) {
            $('#sd-step-upload, #sd-step-progress, #sd-step-preview, #sd-step-result').hide();
            $('#sd-step-' + step).show();
        }

        // ============================================================
        // Messages
        // ============================================================
        function showMessage(type, text) {
            var $el = $('#sd-import-messages');
            $el.removeClass('sd-msg-success sd-msg-error sd-notice-warning')
               .addClass(type === 'error' ? 'sd-msg-error' : 'sd-msg-success')
               .html(text)
               .fadeIn(200);
            setTimeout(function () { $el.fadeOut(400); }, 5000);
        }

        // ============================================================
        // Helpers
        // ============================================================
        function formatDate(d) {
            if (!d) return '—';
            var parts = d.split('-');
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }

        function esc(str) {
            if (!str) return '';
            var el = document.createElement('div');
            el.appendChild(document.createTextNode(str));
            return el.innerHTML;
        }

        // ============================================================
        // Schema debug (admin only)
        // ============================================================
        $('#sd-btn-schema-dump').on('click', function () {
            var fileInput = document.getElementById('sd-schema-file-input');
            if (!fileInput || !fileInput.files.length) {
                alert('Seleziona un file .db prima.');
                return;
            }
            var $btn = $(this);
            var $out = $('#sd-schema-output');
            $btn.prop('disabled', true).text('Analisi in corso…');
            $out.hide().text('');

            var formData = new FormData();
            formData.append('action', 'sd_import_schema');
            formData.append('nonce', sdImport.nonce);
            formData.append('import_file', fileInput.files[0]);

            $.ajax({
                url: sdImport.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (resp) {
                    $btn.prop('disabled', false).text('Analizza schema');
                    if (resp.success) {
                        $out.text(JSON.stringify(resp.data.schema, null, 2)).show();
                    } else {
                        $out.text('Errore: ' + (resp.data && resp.data.message ? resp.data.message : 'sconosciuto')).show();
                    }
                },
                error: function () {
                    $btn.prop('disabled', false).text('Analizza schema');
                    $out.text('Errore di connessione.').show();
                }
            });
        });

    });

})(jQuery);
