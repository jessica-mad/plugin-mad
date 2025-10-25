<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
  <h1><?php echo esc_html( $this->title() ); ?></h1>
  
  <!-- Sección de configuración -->
  <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccd0d4;box-shadow:0 1px 1px rgba(0,0,0,.04);">
    <h2><?php _e('Configuración','mad-suite'); ?></h2>
    <form method="post" action="options.php">
      <?php settings_fields( $this->menu_slug() ); ?>
      <?php do_settings_sections( $this->menu_slug() ); ?>
      <?php submit_button(); ?>
    </form>
  </div>

  <!-- Sección de gestión de incentivos -->
  <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccd0d4;box-shadow:0 1px 1px rgba(0,0,0,.04);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h2 style="margin:0;"><?php _e('Gestión de Incentivos','mad-suite'); ?></h2>
      <a href="<?php echo esc_url(admin_url('post-new.php?post_type='.self::CPT)); ?>" class="button button-primary">
        <?php _e('Añadir nuevo incentivo','mad-suite'); ?>
      </a>
    </div>

    <?php
    // Obtener todos los incentivos
    $incentives = new WP_Query([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => -1,
      'orderby' => ['menu_order' => 'ASC', 'date' => 'ASC'],
      'no_found_rows' => true
    ]);

    if($incentives->have_posts()): ?>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th style="width:40px;"><?php _e('Estado','mad-suite'); ?></th>
            <th><?php _e('Título','mad-suite'); ?></th>
            <th style="width:120px;"><?php _e('Mínimo','mad-suite'); ?></th>
            <th style="width:100px;"><?php _e('Orden','mad-suite'); ?></th>
            <th style="width:80px;"><?php _e('Tipo','mad-suite'); ?></th>
            <th style="width:150px;"><?php _e('Acciones','mad-suite'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php while($incentives->have_posts()): $incentives->the_post(); 
            $post_id = get_the_ID();
            $active = (bool)get_post_meta($post_id, '_active', true);
            $min = (float)get_post_meta($post_id, '_min', true);
            $usep = (bool)get_post_meta($post_id, '_usep', true);
            $order = get_post_field('menu_order', $post_id);
          ?>
            <tr>
              <td style="text-align:center;">
                <?php if($active): ?>
                  <span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="<?php esc_attr_e('Activo','mad-suite'); ?>"></span>
                <?php else: ?>
                  <span class="dashicons dashicons-dismiss" style="color:#dc3232;" title="<?php esc_attr_e('Inactivo','mad-suite'); ?>"></span>
                <?php endif; ?>
              </td>
              <td><strong><?php the_title(); ?></strong></td>
              <td><?php echo wc_price($min); ?></td>
              <td><?php echo esc_html($order); ?></td>
              <td>
                <?php if($usep): ?>
                  <span class="dashicons dashicons-cart" title="<?php esc_attr_e('Producto','mad-suite'); ?>"></span>
                  <?php _e('Producto','mad-suite'); ?>
                <?php else: ?>
                  <span class="dashicons dashicons-format-image" title="<?php esc_attr_e('Imagen','mad-suite'); ?>"></span>
                  <?php _e('Imagen','mad-suite'); ?>
                <?php endif; ?>
              </td>
              <td>
                <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" class="button button-small">
                  <?php _e('Editar','mad-suite'); ?>
                </a>
                <a href="<?php echo esc_url(get_delete_post_link($post_id)); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e('¿Estás seguro de eliminar este incentivo?','mad-suite'); ?>');">
                  <?php _e('Eliminar','mad-suite'); ?>
                </a>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p><?php _e('No hay incentivos creados todavía.','mad-suite'); ?></p>
    <?php endif; 
    wp_reset_postdata(); ?>
  </div>

  <!-- Información de ayuda -->
  <div style="background:#fff;padding:20px;margin:20px 0;border:1px solid #ccd0d4;box-shadow:0 1px 1px rgba(0,0,0,.04);">
    <h3><?php _e('¿Cómo funciona?','mad-suite'); ?></h3>
    <ol>
      <li><?php _e('Crea incentivos con diferentes umbrales de importe mínimo.','mad-suite'); ?></li>
      <li><?php _e('Define el orden de prioridad (menor número = mayor prioridad).','mad-suite'); ?></li>
      <li><?php _e('El sistema mostrará automáticamente el siguiente incentivo alcanzable según el subtotal del carrito.','mad-suite'); ?></li>
      <li><?php _e('Puedes usar imágenes personalizadas o asociar un producto que se añadirá automáticamente como regalo.','mad-suite'); ?></li>
    </ol>
    
    <h4><?php _e('Variables disponibles en mensajes:','mad-suite'); ?></h4>
    <ul>
      <li><code>{{missing}}</code> - <?php _e('Muestra el importe restante para alcanzar el incentivo','mad-suite'); ?></li>
    </ul>
  </div>
</div>