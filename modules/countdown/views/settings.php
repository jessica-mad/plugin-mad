<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
<h1><?php echo esc_html( $this->title() ); ?></h1>
<form method="post" action="options.php">
<?php settings_fields( $this->menu_slug() ); ?>
<?php do_settings_sections( $this->menu_slug() ); ?>
<?php submit_button(); ?>
</form>
</div>