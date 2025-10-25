<?php
/**
 * CSV Importer - Versión Simple (Sin JavaScript)
 * 
 * Importador standalone para asignar rol VIP a usuarios existentes
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

if (!defined('ABSPATH')) {
    exit;
}

use MAD_Suite\Modules\PrivateStore\UserRole;
use MAD_Suite\Modules\PrivateStore\Logger;

// Procesar formulario de importación
if (isset($_POST['import_csv_submit']) && isset($_FILES['csv_file'])) {
    check_admin_referer('mads_ps_import_csv');
    
    $logger = new Logger('private-store-import');
    $file = $_FILES['csv_file'];
    
    // Validar archivo
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_ext !== 'csv') {
        $import_error = __('Solo se permiten archivos CSV', 'mad-suite');
    } else {
        // Procesar CSV
        $handle = fopen($file['tmp_name'], 'r');
        
        if ($handle) {
            $results = [
                'success' => 0,
                'errors' => 0,
                'skipped' => 0,
                'details' => []
            ];
            
            $row = 0;
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                $row++;
                
                // Saltar encabezado
                if ($row === 1) {
                    continue;
                }
                
                // Obtener email o username (primera columna)
                $identifier = trim($data[0] ?? '');
                
                if (empty($identifier)) {
                    $results['skipped']++;
                    $results['details'][] = "Fila {$row}: Vacía, omitida";
                    continue;
                }
                
                // Buscar usuario por email o username
                $user = false;
                if (is_email($identifier)) {
                    $user = get_user_by('email', $identifier);
                } else {
                    $user = get_user_by('login', $identifier);
                }
                
                if (!$user) {
                    $results['errors']++;
                    $results['details'][] = "Fila {$row}: Usuario '{$identifier}' no encontrado";
                    $logger->warning("Usuario no encontrado: {$identifier}");
                    continue;
                }
                
                // Verificar si ya es VIP
                if (UserRole::instance()->is_vip_user($user->ID)) {
                    $results['skipped']++;
                    $results['details'][] = "Fila {$row}: {$identifier} ya es VIP";
                    continue;
                }
                
                // Asignar rol VIP
                $success = UserRole::instance()->add_vip_access($user->ID);
                
                if ($success) {
                    $results['success']++;
                    $results['details'][] = "Fila {$row}: ✓ {$identifier} convertido a VIP";
                    $logger->info("Usuario convertido a VIP via CSV: {$identifier} (ID: {$user->ID})");
                } else {
                    $results['errors']++;
                    $results['details'][] = "Fila {$row}: ✗ Error al convertir {$identifier}";
                    $logger->error("Error al convertir usuario: {$identifier}");
                }
            }
            
            fclose($handle);
            
            $import_results = $results;
            $logger->info("Importación CSV completada", [
                'success' => $results['success'],
                'errors' => $results['errors'],
                'skipped' => $results['skipped']
            ]);
        } else {
            $import_error = __('No se pudo leer el archivo', 'mad-suite');
        }
    }
}

?>

<div class="wrap">
    <h1><?php _e('Importar Usuarios VIP desde CSV', 'mad-suite'); ?></h1>
    
    <?php if (isset($import_error)): ?>
        <div class="notice notice-error">
            <p><strong><?php _e('Error:', 'mad-suite'); ?></strong> <?php echo esc_html($import_error); ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($import_results)): ?>
        <div class="notice notice-success">
            <h3><?php _e('Importación Completada', 'mad-suite'); ?></h3>
            <ul style="list-style: disc; padding-left: 20px;">
                <li style="color: #27ae60;"><strong><?php echo $import_results['success']; ?></strong> usuarios convertidos a VIP</li>
                <li style="color: #e74c3c;"><strong><?php echo $import_results['errors']; ?></strong> errores</li>
                <li style="color: #f39c12;"><strong><?php echo $import_results['skipped']; ?></strong> omitidos (ya eran VIP o vacíos)</li>
            </ul>
            
            <?php if (!empty($import_results['details'])): ?>
                <details style="margin-top: 15px;">
                    <summary style="cursor: pointer; font-weight: 600;">Ver detalles completos</summary>
                    <div style="max-height: 400px; overflow-y: auto; background: #fff; padding: 15px; border: 1px solid #ddd; margin-top: 10px;">
                        <ul style="list-style: none; padding: 0; font-size: 13px; font-family: monospace;">
                            <?php foreach ($import_results['details'] as $detail): ?>
                                <li style="margin: 3px 0;"><?php echo esc_html($detail); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2><?php _e('Formato del Archivo CSV', 'mad-suite'); ?></h2>
        
        <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #2271b1; margin: 15px 0;">
            <h3 style="margin-top: 0;"><?php _e('Instrucciones:', 'mad-suite'); ?></h3>
            <ol style="line-height: 1.8;">
                <li>El archivo debe ser <strong>CSV</strong> (separado por comas)</li>
                <li>La <strong>primera columna</strong> debe contener el <strong>email</strong> o <strong>nombre de usuario</strong></li>
                <li>La <strong>primera fila</strong> se considera encabezado y se ignora</li>
                <li>Solo se procesarán <strong>usuarios que ya existan</strong> en WordPress</li>
                <li>Los usuarios que ya sean VIP serán omitidos</li>
            </ol>
        </div>
        
        <div style="background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin: 15px 0;">
            <h4 style="margin-top: 0;"><?php _e('Ejemplo de archivo CSV:', 'mad-suite'); ?></h4>
            <pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 13px;">email
usuario1@ejemplo.com
usuario2@ejemplo.com
nombre_usuario_3
usuario4@ejemplo.com</pre>
        </div>
        
        <div style="margin: 20px 0;">
            <a href="#" onclick="downloadCSVTemplate(); return false;" class="button button-secondary">
                <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                <?php _e('Descargar Plantilla CSV', 'mad-suite'); ?>
            </a>
        </div>
        
        <hr style="margin: 30px 0;">
        
        <h2><?php _e('Subir Archivo CSV', 'mad-suite'); ?></h2>
        
        <form method="post" enctype="multipart/form-data" style="margin-top: 20px;">
            <?php wp_nonce_field('mads_ps_import_csv'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="csv_file"><?php _e('Archivo CSV:', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="file" 
                               name="csv_file" 
                               id="csv_file" 
                               accept=".csv" 
                               required 
                               style="margin-bottom: 10px;">
                        <p class="description">
                            <?php _e('Selecciona un archivo CSV con los emails o nombres de usuario', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" name="import_csv_submit" class="button button-primary button-hero">
                    <span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
                    <?php _e('Importar Usuarios VIP', 'mad-suite'); ?>
                </button>
            </p>
        </form>
    </div>
    
    <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2><?php _e('Exportar Usuarios VIP Actuales', 'mad-suite'); ?></h2>
        <p><?php _e('Descarga un CSV con todos los usuarios VIP actuales (útil como backup o referencia)', 'mad-suite'); ?></p>
        
        <form method="post" action="<?php echo admin_url('admin-ajax.php'); ?>" style="margin-top: 15px;">
            <input type="hidden" name="action" value="mads_ps_export_vip_users">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('mads_private_store'); ?>">
            
            <button type="submit" class="button button-secondary">
                <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                <?php _e('Exportar Usuarios VIP', 'mad-suite'); ?>
            </button>
        </form>
    </div>
</div>

<script>
function downloadCSVTemplate() {
    const csvContent = 'email\nusuario1@ejemplo.com\nusuario2@ejemplo.com\nnombre_usuario_3\n';
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'plantilla-usuarios-vip.csv');
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<style>
.card {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    padding: 20px;
    margin-bottom: 20px;
}

.card h2 {
    margin-top: 0;
    font-size: 18px;
}

details summary {
    padding: 10px;
    background: #f0f0f1;
    border: 1px solid #ddd;
    border-radius: 4px;
}

details[open] summary {
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
}
</style>
