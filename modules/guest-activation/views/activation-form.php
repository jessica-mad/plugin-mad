<?php
/**
 * Vista del formulario de activación inicial
 */

if (!defined('ABSPATH')) exit;
?>

<div class="mad-guest-activation-form">
    <div class="mad-activation-container">
        <h3><?php esc_html_e('Activar Cuenta de Invitado', 'mad-suite'); ?></h3>

        <div id="mad-activation-message" class="mad-message" style="display: none;"></div>

        <form id="mad-activation-form" class="mad-form">
            <p class="form-description">
                <?php esc_html_e('Si compraste como invitado, ingresa tu email para activar tu cuenta y acceder a tus pedidos.', 'mad-suite'); ?>
            </p>

            <div class="form-field">
                <label for="mad-activation-email">
                    <?php esc_html_e('Email', 'mad-suite'); ?>
                    <span class="required">*</span>
                </label>
                <input type="email"
                       id="mad-activation-email"
                       name="email"
                       required
                       placeholder="<?php esc_attr_e('tu@email.com', 'mad-suite'); ?>">
            </div>

            <?php if (!empty($recaptcha_site_key)): ?>
                <div class="form-field">
                    <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($recaptcha_site_key); ?>"></div>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="button mad-submit-btn">
                    <?php esc_html_e('Activar Cuenta', 'mad-suite'); ?>
                </button>
            </div>

            <div class="mad-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <?php esc_html_e('Procesando...', 'mad-suite'); ?>
            </div>
        </form>
    </div>
</div>
