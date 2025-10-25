<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wrap">
<h1><?php echo esc_html( $this->title() ); ?></h1>
<form method="post" action="options.php">
<?php settings_fields( $this->menu_slug() ); ?>
<?php do_settings_sections( $this->menu_slug() ); ?>
<?php submit_button(); ?>
</form>
<hr/>
<h2><?php esc_html_e('Shortcode','mad-suite'); ?></h2>
<p><code>[product_note_field]</code> — id, label, required (0/1), maxlength.</p>
<p><strong><?php esc_html_e('ACF (opcional):','mad-suite'); ?></strong> enable_note, note_label, note_required, note_maxlength.</p>
</div>