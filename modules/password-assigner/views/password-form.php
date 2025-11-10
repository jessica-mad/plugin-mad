<?php
/**
 * Vista: Formulario de contraseña
 *
 * @var array  $settings
 * @var string $custom_message
 * @var bool   $error
 */

if (!defined('ABSPATH')) exit;

// Detectar idioma si WPML está activado
$is_english = false;
if (!empty($settings['enable_wpml'])) {
    $current_url = $_SERVER['REQUEST_URI'] ?? '';
    $is_english = (strpos($current_url, '/en/') !== false);
}

// Obtener mensaje según idioma
if (!empty($custom_message)) {
    $message = $custom_message;
} elseif ($is_english && !empty($settings['custom_message_en'])) {
    $message = $settings['custom_message_en'];
} else {
    $message = $settings['custom_message'];
}

// Obtener texto de introducción según idioma
$form_intro = '';
if ($is_english && !empty($settings['custom_form_intro_en'])) {
    $form_intro = $settings['custom_form_intro_en'];
} elseif (!empty($settings['custom_form_intro'])) {
    $form_intro = $settings['custom_form_intro'];
}

// Obtener placeholder según idioma
$placeholder = '';
if ($is_english && !empty($settings['custom_placeholder_en'])) {
    $placeholder = $settings['custom_placeholder_en'];
} elseif (!empty($settings['custom_placeholder'])) {
    $placeholder = $settings['custom_placeholder'];
}

// Obtener texto del botón según idioma
$button_text = '';
if ($is_english && !empty($settings['custom_button_text_en'])) {
    $button_text = $settings['custom_button_text_en'];
} elseif (!empty($settings['custom_button_text'])) {
    $button_text = $settings['custom_button_text'];
}

// Decidir si usar estilos del tema o propios
$use_theme_styles = !empty($settings['enable_theme_styles']);

// Clases CSS según configuración
$container_class = $use_theme_styles ? 'mads-password-form-container theme-styles' : 'mads-password-form-container';
$wrapper_class = $use_theme_styles ? 'entry-content' : 'mads-password-form-wrapper';
?>

<div class="<?php echo esc_attr($container_class); ?>">
    <div class="<?php echo esc_attr($wrapper_class); ?>">
        <?php if ($error): ?>
            <div class="mads-password-error">
                <p><?php $is_english ? _e('❌ Incorrect password. Please try again.', 'mad-suite') : _e('❌ Contraseña incorrecta. Por favor, intenta de nuevo.', 'mad-suite'); ?></p>
            </div>
        <?php endif; ?>

        <div class="mads-password-form-content">
            <?php if (!$use_theme_styles): ?>
                <div class="mads-password-icon">
                    🔒
                </div>

                <h2 class="mads-password-title">
                    <?php $is_english ? _e('Restricted Access', 'mad-suite') : _e('Acceso Restringido', 'mad-suite'); ?>
                </h2>
            <?php endif; ?>

            <?php if (!empty($form_intro)): ?>
                <div class="mads-password-intro">
                    <?php echo wp_kses_post($form_intro); ?>
                </div>
            <?php endif; ?>

            <p class="mads-password-message">
                <?php echo esc_html($message); ?>
            </p>

            <form method="post" action="" class="mads-password-form">
                <?php wp_nonce_field('mads_password_form', 'mads_password_nonce'); ?>

                <div class="mads-password-field">
                    <label for="mads_password" class="screen-reader-text">
                        <?php echo esc_html($placeholder); ?>
                    </label>
                    <input type="password"
                           name="mads_password"
                           id="mads_password"
                           class="<?php echo $use_theme_styles ? 'input' : 'mads-password-input'; ?>"
                           placeholder="<?php echo esc_attr($placeholder); ?>"
                           required
                           autofocus>
                </div>

                <button type="submit"
                        name="mads_password_submit"
                        class="<?php echo $use_theme_styles ? 'button btn btn-primary' : 'mads-password-submit'; ?>">
                    <?php echo esc_html($button_text); ?>
                </button>
            </form>
        </div>
    </div>
</div>
