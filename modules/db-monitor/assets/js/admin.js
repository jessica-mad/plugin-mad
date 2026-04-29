/* MAD DB Monitor — Admin JS */
/* global madDBM, jQuery */
(function ($) {
    'use strict';

    var cfg = window.madDBM || {};
    var ajaxUrl = cfg.ajax_url || '';

    // ── Utilities ──────────────────────────────────────────────────────────────

    function showModal(id) {
        $('#' + id + ', .mad-dbm-modal-overlay').fadeIn(150);
        $('body').css('overflow', 'hidden');
    }

    function hideAllModals() {
        $('.mad-dbm-modal, .mad-dbm-modal-overlay').fadeOut(150);
        $('body').css('overflow', '');
    }

    function spinner() {
        return '<span class="mad-dbm-spinner"></span>';
    }

    function notice(type, msg) {
        return '<div class="notice notice-' + type + ' inline" style="margin:10px 0"><p>' + $('<span>').text(msg).html() + '</p></div>';
    }

    // ── Close modals ───────────────────────────────────────────────────────────

    $(document).on('click', '.mad-dbm-modal-overlay', hideAllModals);
    $(document).on('click', '#mad-dbm-clean-cancel-btn, #mad-dbm-truncate-cancel-btn', hideAllModals);
    $(document).on('keydown', function (e) { if (e.key === 'Escape') hideAllModals(); });

    // ── CLEAN OLD RECORDS modal ────────────────────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-clean', function () {
        var table = $(this).data('table');
        $('#mad-dbm-clean-table-name').text(table);
        $('#mad-dbm-clean-preview').hide().html('');
        $('#mad-dbm-clean-result').hide().html('');
        $('#mad-dbm-clean-confirm-btn').prop('disabled', true);
        $('#mad-dbm-clean-days').val(30);
        showModal('mad-dbm-clean-modal');
    });

    // Preview
    $(document).on('click', '#mad-dbm-preview-btn', function () {
        var table = $('#mad-dbm-clean-table-name').text();
        var days  = parseInt($('#mad-dbm-clean-days').val(), 10) || 30;
        var $btn  = $(this);

        $btn.prop('disabled', true).after(spinner());

        $.post(ajaxUrl, {
            action:       'mad_dbm_preview_cleanup',
            mad_table:    table,
            mad_action:   'clean_old',
            mad_days:     days,
            mad_nonce:    cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();

            if (res.success) {
                var count = res.data.rows_to_delete || 0;
                $('#mad-dbm-clean-preview')
                    .html('<strong>' + count + '</strong> registros serían eliminados (de más de ' + days + ' días).')
                    .show();
                $('#mad-dbm-clean-confirm-btn').prop('disabled', count === 0);
            } else {
                $('#mad-dbm-clean-preview').html(notice('error', res.data || 'Error al calcular.')).show();
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            $('#mad-dbm-clean-preview').html(notice('error', 'Error de conexión.')).show();
        });
    });

    // Confirm clean
    $(document).on('click', '#mad-dbm-clean-confirm-btn', function () {
        var table = $('#mad-dbm-clean-table-name').text();
        var days  = parseInt($('#mad-dbm-clean-days').val(), 10) || 30;
        var $btn  = $(this);

        $btn.prop('disabled', true).after(spinner());
        $('#mad-dbm-clean-result').hide();

        $.post(ajaxUrl, {
            action:       'mad_dbm_cleanup',
            mad_table:    table,
            mad_action:   'clean_old',
            mad_days:     days,
            mad_nonce:    cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();

            if (res.success) {
                var msg = '✔ Limpieza completada. Registros eliminados: ' + res.data.deleted
                    + '. Backup automático: ' + res.data.backup_file;
                $('#mad-dbm-clean-result').html(notice('success', msg)).show();
                setTimeout(function () { location.reload(); }, 3000);
            } else {
                $('#mad-dbm-clean-result').html(notice('error', res.data || 'Error al limpiar.')).show();
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            $('#mad-dbm-clean-result').html(notice('error', 'Error de conexión.')).show();
        });
    });

    // ── TRUNCATE modal ─────────────────────────────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-truncate', function () {
        var table = $(this).data('table');
        $('#mad-dbm-truncate-table-name').text(table);
        $('#mad-dbm-truncate-result').hide().html('');
        $('#mad-dbm-truncate-confirm-check').prop('checked', false);
        $('#mad-dbm-truncate-confirm-btn').prop('disabled', true);
        showModal('mad-dbm-truncate-modal');
    });

    $(document).on('change', '#mad-dbm-truncate-confirm-check', function () {
        $('#mad-dbm-truncate-confirm-btn').prop('disabled', !$(this).is(':checked'));
    });

    $(document).on('click', '#mad-dbm-truncate-confirm-btn', function () {
        var table = $('#mad-dbm-truncate-table-name').text();
        var $btn  = $(this);

        $btn.prop('disabled', true).after(spinner());
        $('#mad-dbm-truncate-result').hide();

        $.post(ajaxUrl, {
            action:     'mad_dbm_cleanup',
            mad_table:  table,
            mad_action: 'truncate',
            mad_nonce:  cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();

            if (res.success) {
                var msg = '✔ Tabla vaciada. Backup automático: ' + res.data.backup_file;
                $('#mad-dbm-truncate-result').html(notice('success', msg)).show();
                setTimeout(function () { location.reload(); }, 3000);
            } else {
                $('#mad-dbm-truncate-result').html(notice('error', res.data || 'Error al vaciar tabla.')).show();
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            $('#mad-dbm-truncate-result').html(notice('error', 'Error de conexión.')).show();
        });
    });

    // ── EXPORTS: get download token ────────────────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-get-token', function () {
        var exportId = $(this).data('export-id');
        var ttl      = parseInt($(this).data('ttl'), 10) || 300;
        var $btn     = $(this);
        var $row     = $('#mad-dbm-token-row-' + exportId);

        $btn.prop('disabled', true).after(spinner());

        $.post(ajaxUrl, {
            action:     'mad_dbm_get_token',
            export_id:  exportId,
            mad_nonce:  cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();

            if (!res.success) {
                alert(res.data || 'Error al generar el token.');
                return;
            }

            var downloadUrl  = res.data.download_url;
            var $tokenUrl    = $row.find('.mad-dbm-token-url');
            var $countdown   = $row.find('.mad-dbm-countdown');
            var $expiredMsg  = $row.find('.mad-dbm-expired-msg');
            var $dlBtn       = $row.find('.mad-dbm-btn-download-now');
            var $copyBtn     = $row.find('.mad-dbm-btn-copy-link');

            $tokenUrl.val(downloadUrl);
            $dlBtn.attr('href', downloadUrl);
            $expiredMsg.hide();
            $countdown.text(ttl).removeClass('expired');
            $row.show();

            // Countdown timer
            var remaining = ttl;
            var timer = setInterval(function () {
                remaining--;
                $countdown.text(remaining);

                if (remaining <= 0) {
                    clearInterval(timer);
                    $countdown.addClass('expired');
                    $tokenUrl.val('ENLACE EXPIRADO');
                    $dlBtn.removeAttr('href').css('opacity', .4).off('click').on('click', function (e) { e.preventDefault(); });
                    $copyBtn.prop('disabled', true);
                    $expiredMsg.show();
                }
            }, 1000);

            // Store timer reference so a new click stops the previous one
            if ($row.data('timer')) clearInterval($row.data('timer'));
            $row.data('timer', timer);

        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            alert('Error de conexión.');
        });
    });

    // Copy link
    $(document).on('click', '.mad-dbm-btn-copy-link', function () {
        var url = $(this).closest('.mad-dbm-token-box').find('.mad-dbm-token-url').val();
        if (!url || url === 'ENLACE EXPIRADO') return;
        navigator.clipboard.writeText(url).then(function () {
            alert('Enlace copiado al portapapeles.');
        });
    });

    // ── EXPORTS: send email ────────────────────────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-send-email', function () {
        var exportId = $(this).data('export-id');
        var $btn     = $(this);

        if (!confirm('Se enviará un enlace temporal (5 minutos) al email del administrador. ¿Continuar?')) return;

        $btn.prop('disabled', true).after(spinner());

        $.post(ajaxUrl, {
            action:    'mad_dbm_send_email',
            export_id: exportId,
            mad_nonce: cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            alert(res.success ? '✔ Email enviado correctamente.' : (res.data || 'Error al enviar email.'));
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            alert('Error de conexión.');
        });
    });

    // ── EXPORTS: delete ────────────────────────────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-delete-export', function () {
        var exportId = $(this).data('export-id');
        var $btn     = $(this);

        if (!confirm('¿Eliminar este archivo de exportación? Esta acción no se puede deshacer.')) return;

        $btn.prop('disabled', true).after(spinner());

        $.post(ajaxUrl, {
            action:    'mad_dbm_delete_export',
            export_id: exportId,
            mad_nonce: cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();

            if (res.success) {
                $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
                // Also remove token row
                $('#mad-dbm-token-row-' + exportId).remove();
            } else {
                alert(res.data || 'Error al eliminar.');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            alert('Error de conexión.');
        });
    });

    // ── RESTORE: upload form (AJAX multipart) ──────────────────────────────────

    $(document).on('submit', '#mad-dbm-restore-form', function (e) {
        e.preventDefault();

        var $form    = $(this);
        var formData = new FormData(this);
        formData.append('action', 'mad_dbm_restore_upload');

        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).after(spinner());

        $.ajax({
            url:         ajaxUrl,
            type:        'POST',
            data:        formData,
            processData: false,
            contentType: false,
            success: function (res) {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();

                if (!res.success) {
                    alert(res.data || 'Error al subir el archivo.');
                    return;
                }

                var meta = res.data.meta;
                $('#mad-dbm-restore-table-name').text(meta.table || '—');
                $('#mad-dbm-restore-date').text(meta.date || '—');
                $('#mad-dbm-restore-action').text(meta.action || '—');
                $('#mad-dbm-restore-user').text(meta.user || '—');
                $('#mad-dbm-restore-rows').text(meta.rows || '—');
                $('#mad-dbm-restore-tmp-path').val(res.data.tmp_path);
                $('#mad-dbm-restore-expected-table').val(meta.table || '');

                $('#mad-dbm-restore-step-1').hide();
                $('#mad-dbm-restore-step-2').show();
            },
            error: function () {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();
                alert('Error de conexión.');
            },
        });
    });

    // Restore confirm checkbox
    $(document).on('change', '#mad-dbm-restore-confirm-check', function () {
        $('#mad-dbm-restore-execute-btn').prop('disabled', !$(this).is(':checked'));
    });

    // Restore cancel
    $(document).on('click', '#mad-dbm-restore-cancel-btn', function () {
        $('#mad-dbm-restore-step-2').hide();
        $('#mad-dbm-restore-step-1').show();
        $('#mad-dbm-restore-confirm-check').prop('checked', false);
        $('#mad-dbm-restore-execute-btn').prop('disabled', true);
    });

    // Execute restore
    $(document).on('click', '#mad-dbm-restore-execute-btn', function () {
        var tmpPath = $('#mad-dbm-restore-tmp-path').val();
        var table   = $('#mad-dbm-restore-expected-table').val();
        var nonce   = $('[name="mad_dbm_restore_execute_nonce"]').val();
        var $btn    = $(this);

        $btn.prop('disabled', true).after(spinner());

        $.post(ajaxUrl, {
            action:          'mad_dbm_restore_execute',
            mad_tmp_path:    tmpPath,
            mad_table:       table,
            mad_restore_nonce: nonce,
            mad_nonce:       cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();

            var html;
            if (res.success) {
                html = '<div class="notice notice-success inline"><p>✔ Restauración completada. '
                     + 'Sentencias ejecutadas: ' + res.data.executed + '.</p>';
                if (res.data.errors && res.data.errors.length) {
                    html += '<p>Advertencias: ' + $('<span>').text(res.data.errors.join('; ')).html() + '</p>';
                }
                html += '</div>';
            } else {
                html = '<div class="notice notice-error inline"><p>' + $('<span>').text(res.data || 'Error.').html() + '</p></div>';
            }

            $('#mad-dbm-restore-result').html(html);
            $('#mad-dbm-restore-step-2').hide();
            $('#mad-dbm-restore-step-3').show();
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            alert('Error de conexión.');
        });
    });

}(jQuery));
