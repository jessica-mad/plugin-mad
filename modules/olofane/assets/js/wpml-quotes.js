jQuery(function ($) {
    'use strict';

    var $box = $('#mad-olofane-reply-box');
    if (!$box.length) return;

    var orderId = $box.data('order-id');
    var nonce   = $box.data('nonce');
    var lang    = $box.data('lang');

    // ── Translate ─────────────────────────────────────────────────────────────
    $('#mad-olofane-btn-translate').on('click', function () {
        var text = $('#mad-olofane-reply-es').val().trim();
        if (!text) return;

        var $btn    = $(this);
        var $status = $('#mad-olofane-translate-status');

        $btn.prop('disabled', true);
        $status.text(madOlofaneWpml.translating);
        $('#mad-olofane-translation-preview').hide();

        $.post(madOlofaneWpml.ajaxUrl, {
            action:   'mad_olofane_translate_reply',
            order_id: orderId,
            nonce:    nonce,
            text:     text,
            lang:     lang,
        }, function (res) {
            if (res.success) {
                $('#mad-olofane-reply-translated').val(res.data.translated);
                $('#mad-olofane-translation-preview').show();
                $status.text('');
            } else {
                $status.text((res.data && res.data.message) ? res.data.message : madOlofaneWpml.errorGeneric);
            }
        }).fail(function () {
            $status.text(madOlofaneWpml.errorGeneric);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Send ──────────────────────────────────────────────────────────────────
    $('#mad-olofane-btn-send').on('click', function () {
        var text = $('#mad-olofane-reply-translated').val().trim();
        if (!text) return;

        var $btn    = $(this);
        var $status = $('#mad-olofane-send-status');

        $btn.prop('disabled', true);
        $status.text(madOlofaneWpml.sending);

        $.post(madOlofaneWpml.ajaxUrl, {
            action:   'mad_olofane_send_reply',
            order_id: orderId,
            nonce:    nonce,
            text:     text,
        }, function (res) {
            if (res.success) {
                $status.text(madOlofaneWpml.sent);
                $('#mad-olofane-reply-es').val('');
                $('#mad-olofane-reply-translated').val('');
                $('#mad-olofane-translation-preview').hide();
            } else {
                $status.text((res.data && res.data.message) ? res.data.message : madOlofaneWpml.errorGeneric);
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            $status.text(madOlofaneWpml.errorGeneric);
            $btn.prop('disabled', false);
        });
    });
});
