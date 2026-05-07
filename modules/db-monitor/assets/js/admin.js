/* MAD DB Monitor — Admin JS v1.1 */
/* global madDBM, jQuery */
(function ($) {
    'use strict';

    var cfg     = window.madDBM || {};
    var ajaxUrl = cfg.ajax_url || '';
    var i18n    = cfg.i18n    || {};

    // ── Toast notification system (replaces native alert) ─────────────────────

    var $toast = null;
    var toastTimer = null;

    function toast(type, msg, duration) {
        if (!$toast) {
            $toast = $('<div class="mad-dbm-toast"></div>').appendTo('body');
            $toast.on('click', function () { $toast.stop(true).fadeOut(200); });
        }
        if (toastTimer) clearTimeout(toastTimer);

        $toast
            .removeClass('mad-dbm-toast-success mad-dbm-toast-error mad-dbm-toast-info')
            .addClass('mad-dbm-toast-' + (type || 'info'))
            .text(msg)
            .stop(true)
            .fadeIn(200);

        toastTimer = setTimeout(function () {
            $toast.fadeOut(400);
        }, duration || 4000);
    }

    // ── Spinner helper ────────────────────────────────────────────────────────

    function spinner() {
        return '<span class="mad-dbm-spinner"></span>';
    }

    function noticeHtml(type, msg) {
        return '<div class="notice notice-' + type + ' inline" style="margin:10px 0"><p>'
            + $('<span>').text(msg).html()
            + '</p></div>';
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────

    function showModal(id) {
        $('#' + id + ', .mad-dbm-modal-overlay').fadeIn(150);
        $('body').css('overflow', 'hidden');
    }

    function hideAllModals() {
        $('.mad-dbm-modal, .mad-dbm-modal-overlay').fadeOut(150);
        $('body').css('overflow', '');
    }

    $(document).on('click', '.mad-dbm-modal-overlay, .mad-dbm-modal-close, .mad-dbm-modal-close-btn', hideAllModals);
    $(document).on('keydown', function (e) { if (e.key === 'Escape') hideAllModals(); });

    // ── Table search / filter ─────────────────────────────────────────────────

    $(document).on('input', '#mad-dbm-table-search', function () {
        var q    = $(this).val().toLowerCase().trim();
        var $rows = $('#mad-dbm-table-list tbody tr');
        var visible = 0;

        $rows.each(function () {
            var name = $(this).find('.mad-dbm-table-name').text().toLowerCase();
            var show = !q || name.indexOf(q) !== -1;
            $(this).toggle(show);
            if (show) visible++;
        });

        var total = $rows.length;
        $('#mad-dbm-search-count').text(
            q ? (visible + ' / ' + total + ' tablas') : ''
        );
        $('#mad-dbm-no-results').toggle(visible === 0 && q !== '');
    });

    // ── EXPORT via AJAX (no page refresh) ─────────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-export', function () {
        var table = $(this).data('table');
        var $btn  = $(this);

        $btn.prop('disabled', true).text('…').after(spinner());
        toast('info', i18n.exporting || 'Exportando…');

        $.ajax({
            url:     ajaxUrl,
            type:    'POST',
            timeout: 180000, // 3 min — large tables need time
            data: {
                action:    'mad_dbm_export_ajax',
                mad_table: table,
                mad_nonce: cfg.nonce,
            },
            success: function (res) {
                $btn.prop('disabled', false).text('Exportar');
                $('.mad-dbm-spinner').remove();

                if (res.success) {
                    toast('success', (i18n.export_ok || 'Exportación completada.') + ' ' + res.data.file_name);
                } else {
                    toast('error', (i18n.export_fail || 'Error:') + ' ' + (res.data || ''));
                }
            },
            error: function (xhr, status) {
                $btn.prop('disabled', false).text('Exportar');
                $('.mad-dbm-spinner').remove();
                var msg = status === 'timeout'
                    ? 'Tiempo de espera agotado. La tabla puede ser muy grande.'
                    : (i18n.conn_error || 'Error de conexión.');
                toast('error', msg, 6000);
            },
        });
    });

    // ── CLEAN OLD RECORDS modal ────────────────────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-clean', function () {
        var table       = $(this).data('table');
        var actionType  = $(this).data('action')        || 'clean_old';
        var actionLabel = $(this).data('action-label')  || 'Registros antiguos';
        var defaultDays = parseInt($(this).data('default-days'), 10) || 30;

        $('#mad-dbm-clean-table-name').text(table);
        $('#mad-dbm-clean-action-label').text(actionLabel);
        $('#mad-dbm-clean-days').val(defaultDays);
        $('#mad-dbm-clean-preview').hide().html('');
        $('#mad-dbm-clean-result').hide().html('');
        $('#mad-dbm-clean-confirm-btn').prop('disabled', true).data('action', actionType);

        showModal('mad-dbm-clean-modal');
    });

    // Preview
    $(document).on('click', '#mad-dbm-preview-btn', function () {
        var table     = $('#mad-dbm-clean-table-name').text();
        var action    = $('#mad-dbm-clean-confirm-btn').data('action') || 'clean_old';
        var days      = parseInt($('#mad-dbm-clean-days').val(), 10) || 30;
        var $btn      = $(this);

        $btn.prop('disabled', true).after(spinner());

        $.post(ajaxUrl, {
            action:     'mad_dbm_preview_cleanup',
            mad_table:  table,
            mad_action: action,
            mad_days:   days,
            mad_nonce:  cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();

            if (res.success) {
                var count = res.data.rows_to_delete || 0;
                $('#mad-dbm-clean-preview')
                    .html('<strong>' + count.toLocaleString() + '</strong> registros serían eliminados (de más de ' + days + ' días).')
                    .show();
                $('#mad-dbm-clean-confirm-btn').prop('disabled', count === 0);
            } else {
                $('#mad-dbm-clean-preview').html(noticeHtml('error', res.data || 'Error al calcular.')).show();
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            toast('error', i18n.conn_error || 'Error de conexión.');
        });
    });

    // Confirm clean
    $(document).on('click', '#mad-dbm-clean-confirm-btn', function () {
        var table  = $('#mad-dbm-clean-table-name').text();
        var action = $(this).data('action') || 'clean_old';
        var days   = parseInt($('#mad-dbm-clean-days').val(), 10) || 30;
        var $btn   = $(this);

        $btn.prop('disabled', true).after(spinner());
        $('#mad-dbm-clean-result').hide();

        $.ajax({
            url:     ajaxUrl,
            type:    'POST',
            timeout: 180000,
            data: {
                action:     'mad_dbm_cleanup',
                mad_table:  table,
                mad_action: action,
                mad_days:   days,
                mad_nonce:  cfg.nonce,
            },
            success: function (res) {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();

                if (res.success) {
                    var deleted = (res.data.deleted || 0).toLocaleString();
                    var msg = '✔ Limpieza completada. Registros eliminados: ' + deleted
                        + '. Backup automático: ' + (res.data.backup_file || '—');
                    $('#mad-dbm-clean-result').html(noticeHtml('success', msg)).show();
                    toast('success', 'Limpieza completada — ' + deleted + ' registros eliminados.');
                } else {
                    $('#mad-dbm-clean-result').html(noticeHtml('error', res.data || 'Error al limpiar.')).show();
                    toast('error', res.data || 'Error al limpiar.');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();
                toast('error', i18n.conn_error || 'Error de conexión.');
            },
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

        $.ajax({
            url:     ajaxUrl,
            type:    'POST',
            timeout: 120000,
            data: {
                action:     'mad_dbm_cleanup',
                mad_table:  table,
                mad_action: 'truncate',
                mad_nonce:  cfg.nonce,
            },
            success: function (res) {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();

                if (res.success) {
                    var msg = '✔ Tabla vaciada. Backup automático: ' + (res.data.backup_file || '—');
                    $('#mad-dbm-truncate-result').html(noticeHtml('success', msg)).show();
                    toast('success', 'Tabla vaciada correctamente.');
                } else {
                    $('#mad-dbm-truncate-result').html(noticeHtml('error', res.data || 'Error.')).show();
                    toast('error', res.data || 'Error al vaciar.');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();
                toast('error', i18n.conn_error || 'Error de conexión.');
            },
        });
    });

    // ── CLEAN TRANSIENTS (dashboard quick action) ──────────────────────────────

    $(document).on('click', '#mad-dbm-btn-clean-transients', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).after(spinner());
        $('#mad-dbm-transients-result').hide();

        $.post(ajaxUrl, {
            action:    'mad_dbm_clean_transients',
            mad_nonce: cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();

            if (res.success) {
                var count = res.data.deleted || 0;
                $('#mad-dbm-transients-result')
                    .html('<span style="color:#46b450">✔ ' + (i18n.transients_ok || 'Eliminados:') + ' <strong>' + count.toLocaleString() + '</strong></span>')
                    .show();
                toast('success', 'Transients eliminados: ' + count.toLocaleString());
            } else {
                toast('error', res.data || 'Error al limpiar transients.');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            toast('error', i18n.conn_error || 'Error de conexión.');
        });
    });

    // ── EXPORTS: get download token + countdown ────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-get-token', function () {
        var exportId = $(this).data('export-id');
        var ttl      = parseInt(cfg.token_ttl, 10) || 300;
        var $btn     = $(this);
        var $row     = $('#mad-dbm-token-row-' + exportId);

        $btn.prop('disabled', true).after(spinner());

        $.post(ajaxUrl, {
            action:    'mad_dbm_get_token',
            export_id: exportId,
            mad_nonce: cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();

            if (!res.success) {
                toast('error', res.data || 'Error al generar el token.');
                return;
            }

            var url         = res.data.download_url;
            var $urlInput   = $row.find('.mad-dbm-token-url');
            var $countdown  = $row.find('.mad-dbm-countdown');
            var $expiredMsg = $row.find('.mad-dbm-expired-msg');
            var $dlBtn      = $row.find('.mad-dbm-btn-download-now');
            var $copyBtn    = $row.find('.mad-dbm-btn-copy-link');

            $urlInput.val(url);
            $dlBtn.attr('href', url).css('opacity', 1).off('click');
            $expiredMsg.hide();
            $countdown.text(ttl).removeClass('expired');
            $row.show();

            // Countdown
            var remaining = ttl;
            if ($row.data('timer')) clearInterval($row.data('timer'));

            var timer = setInterval(function () {
                remaining--;
                $countdown.text(remaining);

                if (remaining <= 0) {
                    clearInterval(timer);
                    $countdown.addClass('expired');
                    $urlInput.val(i18n.link_expired || 'ENLACE EXPIRADO');
                    $dlBtn.removeAttr('href').css('opacity', .4).on('click', function (e) { e.preventDefault(); });
                    $copyBtn.prop('disabled', true);
                    $expiredMsg.show();
                }
            }, 1000);

            $row.data('timer', timer);

        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            toast('error', i18n.conn_error || 'Error de conexión.');
        });
    });

    // Copy link — use toast instead of alert
    $(document).on('click', '.mad-dbm-btn-copy-link', function () {
        var url = $(this).closest('.mad-dbm-token-box').find('.mad-dbm-token-url').val();
        if (!url || url === (i18n.link_expired || 'ENLACE EXPIRADO')) return;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () {
                toast('success', i18n.copied || 'Enlace copiado.');
            });
        } else {
            // Fallback
            var $tmp = $('<input>').val(url).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
            toast('success', i18n.copied || 'Enlace copiado.');
        }
    });

    // ── EXPORTS: send email ────────────────────────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-send-email', function () {
        if (!confirm(i18n.confirm_email || '¿Enviar enlace temporal por email al administrador?')) return;

        var exportId = $(this).data('export-id');
        var $btn     = $(this);

        $btn.prop('disabled', true).after(spinner());

        $.post(ajaxUrl, {
            action:    'mad_dbm_send_email',
            export_id: exportId,
            mad_nonce: cfg.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            toast(
                res.success ? 'success' : 'error',
                res.success ? (i18n.email_ok || 'Email enviado.') : (res.data || i18n.email_fail || 'Error.')
            );
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            toast('error', i18n.conn_error || 'Error de conexión.');
        });
    });

    // ── EXPORTS: delete ────────────────────────────────────────────────────────

    $(document).on('click', '.mad-dbm-btn-delete-export', function () {
        if (!confirm(i18n.confirm_delete || '¿Eliminar esta exportación?')) return;

        var exportId = $(this).data('export-id');
        var $btn     = $(this);

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
                $('#mad-dbm-token-row-' + exportId).remove();
                toast('success', 'Exportación eliminada.');
            } else {
                toast('error', res.data || 'Error al eliminar.');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $('.mad-dbm-spinner').remove();
            toast('error', i18n.conn_error || 'Error de conexión.');
        });
    });

    // ── RESTORE: upload (AJAX multipart) ──────────────────────────────────────

    $(document).on('submit', '#mad-dbm-restore-form', function (e) {
        e.preventDefault();

        var formData = new FormData(this);
        formData.append('action', 'mad_dbm_restore_upload');

        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).after(spinner());

        $.ajax({
            url:         ajaxUrl,
            type:        'POST',
            data:        formData,
            processData: false,
            contentType: false,
            timeout:     60000,
            success: function (res) {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();

                if (!res.success) {
                    toast('error', res.data || 'Error al subir el archivo.');
                    return;
                }

                var meta = res.data.meta || {};
                $('#mad-dbm-restore-table-name').text(meta.table || '—');
                $('#mad-dbm-restore-date').text(meta.date || '—');
                $('#mad-dbm-restore-action').text(meta.action || '—');
                $('#mad-dbm-restore-user').text(meta.user || '—');
                $('#mad-dbm-restore-rows').text(meta.rows || '—');

                // Store server-side token (NOT the file path)
                $('#mad-dbm-restore-upload-token').val(res.data.upload_token || '');
                $('#mad-dbm-restore-expected-table').val(meta.table || '');

                $('#mad-dbm-restore-confirm-check').prop('checked', false);
                $('#mad-dbm-restore-execute-btn').prop('disabled', true);
                $('#mad-dbm-restore-step-1').hide();
                $('#mad-dbm-restore-step-2').show();
            },
            error: function () {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();
                toast('error', i18n.conn_error || 'Error de conexión.');
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
        $('#mad-dbm-restore-form')[0].reset();
    });

    // Restore again
    $(document).on('click', '#mad-dbm-restore-again-btn', function () {
        $('#mad-dbm-restore-step-3').hide();
        $('#mad-dbm-restore-step-1').show();
        $('#mad-dbm-restore-form')[0].reset();
    });

    // Execute restore
    $(document).on('click', '#mad-dbm-restore-execute-btn', function () {
        var uploadToken = $('#mad-dbm-restore-upload-token').val();
        var table       = $('#mad-dbm-restore-expected-table').val();
        var nonce       = $('[name="mad_dbm_restore_execute_nonce"]').val();
        var $btn        = $(this);

        $btn.prop('disabled', true).after(spinner());

        $.ajax({
            url:     ajaxUrl,
            type:    'POST',
            timeout: 180000,
            data: {
                action:                     'mad_dbm_restore_execute',
                mad_upload_token:           uploadToken,
                mad_table:                  table,
                mad_dbm_restore_execute_nonce: nonce,
                mad_nonce:                  cfg.nonce,
            },
            success: function (res) {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();

                var html;
                if (res.success) {
                    html = '<div class="notice notice-success inline"><p>✔ Restauración completada. '
                         + 'Sentencias ejecutadas: <strong>' + (res.data.executed || 0) + '</strong>.</p>';
                    if (res.data.errors && res.data.errors.length) {
                        html += '<p><em>Advertencias:</em> ' + $('<span>').text(res.data.errors.join(' | ')).html() + '</p>';
                    }
                    html += '</div>';
                    toast('success', 'Restauración completada.');
                } else {
                    html = '<div class="notice notice-error inline"><p>' + $('<span>').text(res.data || 'Error.').html() + '</p></div>';
                    toast('error', res.data || 'Error en la restauración.');
                }

                $('#mad-dbm-restore-result').html(html);
                $('#mad-dbm-restore-step-2').hide();
                $('#mad-dbm-restore-step-3').show();
            },
            error: function () {
                $btn.prop('disabled', false);
                $('.mad-dbm-spinner').remove();
                toast('error', i18n.conn_error || 'Error de conexión.');
            },
        });
    });

}(jQuery));
