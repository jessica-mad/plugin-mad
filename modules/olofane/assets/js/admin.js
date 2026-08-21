jQuery(function ($) {
    'use strict';

    var $btn    = $('#mad-olofane-generate-btn');
    var $status = $('#mad-olofane-status');

    if (!$btn.length) return;

    $btn.on('click', function () {
        var productId = $btn.data('product-id');
        var nonce     = $btn.data('nonce');

        $btn.prop('disabled', true);
        $status.text(madOlofane.generating).show();

        $.post(madOlofane.ajaxUrl, {
            action:     'mad_olofane_generate_description',
            product_id: productId,
            nonce:      nonce,
        }, function (res) {
            if (res.success) {
                // Update the TinyMCE / classic editor
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
                    tinyMCE.get('content').setContent(res.data.description);
                } else {
                    $('#content').val(res.data.description);
                }
                $status.text(madOlofane.done);
            } else {
                $status.text((res.data && res.data.message) ? res.data.message : madOlofane.error);
            }
        }).fail(function () {
            $status.text(madOlofane.error);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
});
