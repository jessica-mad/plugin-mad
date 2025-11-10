<?php
/**
 * Vista: Formulario de contraseña
 *
 * @var array  $settings
 * @var string $custom_message
 * @var bool   $error
 */

if (!defined('ABSPATH')) exit;

$message = !empty($custom_message) ? $custom_message : $settings['custom_message'];
?>

<div class="mads-password-form-container">
    <div class="mads-password-form-wrapper">
        <?php if ($error): ?>
            <div class="mads-password-error">
                <p><?php _e('❌ Contraseña incorrecta. Por favor, intenta de nuevo.', 'mad-suite'); ?></p>
            </div>
        <?php endif; ?>

        <div class="mads-password-form-content">
            <div class="mads-password-icon">
                🔒
            </div>

            <h2 class="mads-password-title">
                <?php _e('Acceso Restringido', 'mad-suite'); ?>
            </h2>

            <p class="mads-password-message">
                <?php echo esc_html($message); ?>
            </p>

            <form method="post" action="" class="mads-password-form">
                <?php wp_nonce_field('mads_password_form', 'mads_password_nonce'); ?>

                <div class="mads-password-field">
                    <label for="mads_password" class="screen-reader-text">
                        <?php _e('Contraseña', 'mad-suite'); ?>
                    </label>
                    <input type="password"
                           name="mads_password"
                           id="mads_password"
                           class="mads-password-input"
                           placeholder="<?php esc_attr_e('Ingresa la contraseña', 'mad-suite'); ?>"
                           required
                           autofocus>
                </div>

                <button type="submit"
                        name="mads_password_submit"
                        class="mads-password-submit">
                    <?php _e('Acceder', 'mad-suite'); ?>
                </button>
            </form>
        </div>
    </div>
</div>
