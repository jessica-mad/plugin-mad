<?php
/**
 * Vista del metabox de FedEx Returns en pedidos
 */

if (!defined('ABSPATH')) exit;
?>

<div class="fedex-returns-metabox">
    <?php if ($has_return): ?>
        <!-- Devolución existente -->
        <div class="fedex-return-info">
            <h4><?php echo esc_html__('Información de Devolución', 'mad-suite'); ?></h4>

            <p>
                <strong><?php echo esc_html__('Tracking Number:', 'mad-suite'); ?></strong><br>
                <code><?php echo esc_html($return_data['tracking_number']); ?></code>
            </p>

            <?php if (!empty($return_data['label_url'])): ?>
                <p>
                    <strong><?php echo esc_html__('Etiqueta de Envío:', 'mad-suite'); ?></strong><br>
                    <a href="<?php echo esc_url($return_data['label_url']); ?>" target="_blank" class="button button-small">
                        <?php echo esc_html__('Descargar Etiqueta', 'mad-suite'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if (!empty($return_data['invoice_url'])): ?>
                <p>
                    <strong><?php echo esc_html__('Factura de Devolución:', 'mad-suite'); ?></strong><br>
                    <a href="<?php echo esc_url($return_data['invoice_url']); ?>" target="_blank" class="button button-small">
                        <?php echo esc_html__('Ver Factura', 'mad-suite'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <p>
                <strong><?php echo esc_html__('Estado:', 'mad-suite'); ?></strong><br>
                <span class="fedex-status fedex-status-<?php echo esc_attr($return_data['status']); ?>">
                    <?php echo esc_html(ucfirst($return_data['status'])); ?>
                </span>
            </p>

            <?php if (!empty($return_data['return_reason'])): ?>
                <p>
                    <strong><?php echo esc_html__('Motivo de Devolución:', 'mad-suite'); ?></strong><br>
                    <?php echo nl2br(esc_html($return_data['return_reason'])); ?>
                </p>
            <?php endif; ?>

            <p>
                <strong><?php echo esc_html__('Creado:', 'mad-suite'); ?></strong><br>
                <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($return_data['created_at']))); ?>
            </p>

            <div class="fedex-return-actions">
                <button type="button" class="button button-secondary check-status-btn"
                        data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php echo esc_html__('Actualizar Estado', 'mad-suite'); ?>
                </button>
            </div>
        </div>

    <?php else: ?>
        <!-- Crear nueva devolución -->
        <div class="fedex-return-form">
            <p><?php echo esc_html__('Crear devolución en FedEx para este pedido.', 'mad-suite'); ?></p>

            <?php if ($settings['allow_partial_returns']): ?>
                <div class="return-items-section">
                    <h4><?php echo esc_html__('Productos a Devolver', 'mad-suite'); ?></h4>
                    <?php
                    $items = $order->get_items();
                    if (!empty($items)):
                    ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th style="width: 30px;">
                                        <input type="checkbox" id="select-all-items">
                                    </th>
                                    <th><?php echo esc_html__('Producto', 'mad-suite'); ?></th>
                                    <th style="width: 100px;"><?php echo esc_html__('Cantidad', 'mad-suite'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item_id => $item): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="return-item-checkbox"
                                                   name="return_items[]"
                                                   value="<?php echo esc_attr($item_id); ?>"
                                                   data-item-id="<?php echo esc_attr($item_id); ?>"
                                                   data-max-qty="<?php echo esc_attr($item->get_quantity()); ?>">
                                        </td>
                                        <td><?php echo esc_html($item->get_name()); ?></td>
                                        <td>
                                            <input type="number" class="return-item-qty small-text"
                                                   data-item-id="<?php echo esc_attr($item_id); ?>"
                                                   min="1"
                                                   max="<?php echo esc_attr($item->get_quantity()); ?>"
                                                   value="<?php echo esc_attr($item->get_quantity()); ?>"
                                                   disabled>
                                            / <?php echo esc_html($item->get_quantity()); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="return-details-section" style="margin-top: 15px;">
                <h4><?php echo esc_html__('Detalles del Paquete', 'mad-suite'); ?></h4>

                <p>
                    <label for="return-weight">
                        <?php echo esc_html__('Peso', 'mad-suite'); ?>
                        (<?php echo esc_html($settings['default_weight_unit'] ?? 'KG'); ?>):
                    </label>
                    <input type="number" id="return-weight" class="small-text" step="0.01" min="0.1" value="1">
                </p>

                <p>
                    <label><?php echo esc_html__('Dimensiones', 'mad-suite'); ?>
                        (<?php echo esc_html($settings['default_dimension_unit'] ?? 'CM'); ?>):
                    </label>
                    <br>
                    <input type="number" id="return-length" class="small-text" placeholder="<?php echo esc_attr__('Largo', 'mad-suite'); ?>"
                           value="30" min="1"> x
                    <input type="number" id="return-width" class="small-text" placeholder="<?php echo esc_attr__('Ancho', 'mad-suite'); ?>"
                           value="30" min="1"> x
                    <input type="number" id="return-height" class="small-text" placeholder="<?php echo esc_attr__('Alto', 'mad-suite'); ?>"
                           value="30" min="1">
                </p>
            </div>

            <div class="original-shipment-section" style="margin-top: 15px;">
                <h4><?php echo esc_html__('Información del Envío Original', 'mad-suite'); ?></h4>

                <p>
                    <label for="original-tracking-code">
                        <?php echo esc_html__('Tracking Code (Envío Original):', 'mad-suite'); ?>
                    </label>
                    <input type="text" id="original-tracking-code" class="regular-text"
                           placeholder="<?php echo esc_attr__('Ej: 123456789012', 'mad-suite'); ?>">
                    <span class="description"><?php echo esc_html__('Número de tracking del envío original', 'mad-suite'); ?></span>
                </p>

                <p>
                    <label for="original-dated">
                        <?php echo esc_html__('Fecha del Envío Original:', 'mad-suite'); ?>
                    </label>
                    <input type="date" id="original-dated" class="regular-text">
                </p>

                <p>
                    <label for="original-dua-number">
                        <?php echo esc_html__('Número DUA:', 'mad-suite'); ?>
                    </label>
                    <input type="text" id="original-dua-number" class="regular-text"
                           placeholder="<?php echo esc_attr__('Declaración Única Aduanera', 'mad-suite'); ?>">
                    <span class="description"><?php echo esc_html__('Número de declaración aduanera (si aplica)', 'mad-suite'); ?></span>
                </p>
            </div>

            <?php if ($settings['require_return_reason']): ?>
                <div class="return-reason-section" style="margin-top: 15px;">
                    <h4><?php echo esc_html__('Motivo de Devolución', 'mad-suite'); ?> <span style="color: red;">*</span></h4>
                    <textarea id="return-reason" rows="4" style="width: 100%;"
                              placeholder="<?php echo esc_attr__('Describe el motivo de la devolución...', 'mad-suite'); ?>"></textarea>
                </div>
            <?php else: ?>
                <div class="return-reason-section" style="margin-top: 15px;">
                    <h4><?php echo esc_html__('Motivo de Devolución', 'mad-suite'); ?></h4>
                    <textarea id="return-reason" rows="4" style="width: 100%;"
                              placeholder="<?php echo esc_attr__('Describe el motivo de la devolución (opcional)...', 'mad-suite'); ?>"></textarea>
                </div>
            <?php endif; ?>

            <div class="fedex-return-actions" style="margin-top: 15px;">
                <button type="button" class="button button-primary create-return-btn"
                        data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php echo esc_html__('Crear Devolución en FedEx', 'mad-suite'); ?>
                </button>
            </div>

            <div id="fedex-return-message" style="margin-top: 10px;"></div>
        </div>
    <?php endif; ?>
</div>

<style>
    .fedex-returns-metabox {
        padding: 10px 0;
    }
    .fedex-return-info p {
        margin: 10px 0;
    }
    .fedex-return-info code {
        background: #f5f5f5;
        padding: 2px 6px;
        border-radius: 3px;
    }
    .fedex-status {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 3px;
        font-size: 12px;
        font-weight: bold;
    }
    .fedex-status-draft {
        background: #ffeb3b;
        color: #000;
    }
    .fedex-status-pending {
        background: #ff9800;
        color: #fff;
    }
    .fedex-status-completed {
        background: #4caf50;
        color: #fff;
    }
    .fedex-status-cancelled {
        background: #f44336;
        color: #fff;
    }
    .return-items-section table {
        margin-top: 10px;
    }
    .return-items-section th,
    .return-items-section td {
        padding: 8px;
    }
    .return-item-qty {
        width: 60px;
    }
    .fedex-return-actions {
        margin-top: 15px;
    }
    .fedex-return-message {
        padding: 10px;
        border-radius: 3px;
        margin-top: 10px;
    }
    .fedex-return-message.success {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }
    .fedex-return-message.error {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }
</style>

<script>
jQuery(document).ready(function($) {
    // Habilitar/deshabilitar campos de cantidad según checkbox
    $('.return-item-checkbox').on('change', function() {
        var itemId = $(this).data('item-id');
        var qtyInput = $('.return-item-qty[data-item-id="' + itemId + '"]');

        if ($(this).is(':checked')) {
            qtyInput.prop('disabled', false);
        } else {
            qtyInput.prop('disabled', true);
        }
    });

    // Seleccionar/deseleccionar todos
    $('#select-all-items').on('change', function() {
        $('.return-item-checkbox').prop('checked', $(this).is(':checked')).trigger('change');
    });

    // Crear devolución
    $('.create-return-btn').on('click', function() {
        var $btn = $(this);
        var orderId = $btn.data('order-id');
        var $message = $('#fedex-return-message');

        // Validar productos seleccionados si está habilitado
        var returnItems = [];
        <?php if ($settings['allow_partial_returns']): ?>
            $('.return-item-checkbox:checked').each(function() {
                var itemId = $(this).data('item-id');
                var qty = parseInt($('.return-item-qty[data-item-id="' + itemId + '"]').val());

                returnItems.push({
                    item_id: itemId,
                    quantity: qty
                });
            });

            if (returnItems.length === 0) {
                alert('<?php echo esc_js(__('Debes seleccionar al menos un producto para devolver.', 'mad-suite')); ?>');
                return;
            }
        <?php endif; ?>

        // Validar motivo si es requerido
        var returnReason = $('#return-reason').val().trim();
        <?php if ($settings['require_return_reason']): ?>
            if (returnReason === '') {
                alert('<?php echo esc_js(__('El motivo de devolución es requerido.', 'mad-suite')); ?>');
                $('#return-reason').focus();
                return;
            }
        <?php endif; ?>

        // Obtener peso y dimensiones
        var weight = parseFloat($('#return-weight').val());
        var dimensions = {
            length: parseInt($('#return-length').val()),
            width: parseInt($('#return-width').val()),
            height: parseInt($('#return-height').val())
        };

        // Obtener información del envío original
        var originalShipment = {
            tracking_code: $('#original-tracking-code').val().trim(),
            dated: $('#original-dated').val(),
            dua_number: $('#original-dua-number').val().trim()
        };

        if (!confirm('<?php echo esc_js(__('¿Estás seguro de crear una devolución en FedEx?', 'mad-suite')); ?>')) {
            return;
        }

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Creando...', 'mad-suite')); ?>');
        $message.removeClass('success error').hide();

        $.ajax({
            url: madFedExReturns.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_fedex_create_return',
                nonce: madFedExReturns.create_return_nonce,
                order_id: orderId,
                return_items: JSON.stringify(returnItems),
                return_reason: returnReason,
                weight: weight,
                dimensions: JSON.stringify(dimensions),
                original_shipment: JSON.stringify(originalShipment)
            },
            success: function(response) {
                if (response.success) {
                    $message.addClass('success fedex-return-message')
                            .html('<strong><?php echo esc_js(__('Éxito:', 'mad-suite')); ?></strong> ' + response.data.message)
                            .show();

                    // Recargar página después de 2 segundos
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $message.addClass('error fedex-return-message')
                            .html('<strong><?php echo esc_js(__('Error:', 'mad-suite')); ?></strong> ' + response.data.message)
                            .show();
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Crear Devolución en FedEx', 'mad-suite')); ?>');
                }
            },
            error: function(xhr, status, error) {
                $message.addClass('error fedex-return-message')
                        .html('<strong><?php echo esc_js(__('Error:', 'mad-suite')); ?></strong> ' + error)
                        .show();
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Crear Devolución en FedEx', 'mad-suite')); ?>');
            }
        });
    });

    // Actualizar estado
    $('.check-status-btn').on('click', function() {
        var $btn = $(this);
        var orderId = $btn.data('order-id');

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Actualizando...', 'mad-suite')); ?>');

        $.ajax({
            url: madFedExReturns.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_fedex_check_return_status',
                nonce: madFedExReturns.check_status_nonce,
                order_id: orderId
            },
            success: function(response) {
                if (response.success) {
                    alert('<?php echo esc_js(__('Estado:', 'mad-suite')); ?> ' + response.data.status.status);
                    location.reload();
                } else {
                    alert('<?php echo esc_js(__('Error:', 'mad-suite')); ?> ' + response.data.message);
                }
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Actualizar Estado', 'mad-suite')); ?>');
            },
            error: function(xhr, status, error) {
                alert('<?php echo esc_js(__('Error:', 'mad-suite')); ?> ' + error);
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Actualizar Estado', 'mad-suite')); ?>');
            }
        });
    });
});
</script>
