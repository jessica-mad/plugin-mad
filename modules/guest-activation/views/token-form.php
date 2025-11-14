<?php
/**
 * Vista del formulario para establecer contraseña
 */

if (!defined('ABSPATH')) exit;
?>

<div class="mad-guest-activation-form">
    <div class="mad-activation-container">
        <h3><?php esc_html_e('Establecer Contraseña', 'mad-suite'); ?></h3>

        <p class="form-description">
            <?php
            printf(
                esc_html__('Crea una contraseña para tu cuenta: %s', 'mad-suite'),
                '<strong>' . esc_html($email) . '</strong>'
            );
            ?>
        </p>

        <div id="mad-create-account-message" class="mad-message" style="display: none;"></div>

        <form id="mad-create-account-form" class="mad-form">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

            <div class="form-field">
                <label for="mad-password">
                    <?php esc_html_e('Contraseña', 'mad-suite'); ?>
                    <span class="required">*</span>
                </label>
                <input type="password"
                       id="mad-password"
                       name="password"
                       required
                       minlength="8"
                       placeholder="<?php esc_attr_e('Mínimo 8 caracteres', 'mad-suite'); ?>">
            </div>

            <div class="form-field">
                <label for="mad-password-confirm">
                    <?php esc_html_e('Confirmar Contraseña', 'mad-suite'); ?>
                    <span class="required">*</span>
                </label>
                <input type="password"
                       id="mad-password-confirm"
                       name="password_confirm"
                       required
                       minlength="8"
                       placeholder="<?php esc_attr_e('Repetir contraseña', 'mad-suite'); ?>">
            </div>

            <div class="form-actions">
                <button type="submit" class="button mad-submit-btn">
                    <?php esc_html_e('Crear Cuenta', 'mad-suite'); ?>
                </button>
            </div>

            <div class="mad-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <?php esc_html_e('Creando cuenta...', 'mad-suite'); ?>
            </div>
        </form>
    </div>
</div>
