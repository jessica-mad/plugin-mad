# Checkout Monitor Module

## Descripción

Módulo de monitorización completa del proceso de checkout de WooCommerce. Rastrea todos los hooks, eventos, errores y comportamiento de plugins durante el proceso de compra para diagnosticar problemas intermitentes.

## Características

### 🎯 Monitorización Completa
- **Tracking de Hooks**: Captura todos los hooks de WooCommerce que se ejecutan durante el checkout
- **Análisis de Plugins**: Detecta qué plugins se ejecutan y en qué orden
- **Timing Preciso**: Mide el tiempo de ejecución de cada hook y callback
- **Memory Tracking**: Registra el uso de memoria en cada paso

### 🐛 Detección de Errores
- **Error Catcher**: Captura todos los errores PHP (Warnings, Notices, Fatal Errors)
- **Exception Handling**: Intercepta excepciones no manejadas
- **WooCommerce Errors**: Captura errores específicos de WooCommerce
- **Stack Traces**: Registra el stack trace completo de cada error

### 📊 Análisis de Logs
- **WooCommerce Logs**: Analiza logs de WooCommerce en tiempo real
- **Server Logs**: Captura logs de Apache/Nginx (si son accesibles)
- **Plugin Logs**: Analiza logs de plugins específicos (Mailchimp, Redsys, etc)
- **Correlación Temporal**: Asocia logs del servidor con sesiones de checkout

### 💻 Tracking del Cliente
- **Browser Detection**: Detecta navegador, versión, y características
- **Device Info**: Tipo de dispositivo (móvil, tablet, desktop)
- **Screen Resolution**: Resolución de pantalla y viewport
- **Connection Info**: Tipo de conexión y velocidad
- **Performance Timing**: Timing de carga de la página
- **JavaScript Errors**: Captura errores de JavaScript en el frontend

### 📈 Dashboard Admin
- **Tabla de Sesiones**: Vista completa de todas las sesiones de checkout
- **Filtros Avanzados**: Filtra por fecha, estado, errores, método de pago
- **Detalle de Sesión**: Vista detallada con timeline de eventos
- **Estadísticas**: Tasa de error, duración promedio, checkouts exitosos/fallidos
- **Lista de Logs**: Todos los archivos de log del servidor con ubicación y tamaño

## Estructura de Base de Datos

### Tabla: `wp_checkout_monitor_sessions`
Almacena cada sesión de checkout con información general:
- Session ID único
- Order ID (cuando se crea)
- Estado (initiated, processing, completed, failed)
- Método de pago
- Datos del navegador (JSON)
- IP del cliente
- Contadores de hooks y errores
- Duración total

### Tabla: `wp_checkout_monitor_events`
Registra cada evento/hook ejecutado:
- Hook name
- Callback ejecutado
- Plugin/archivo de origen
- Tiempo de ejecución
- Uso de memoria
- Errores capturados
- Stack trace (si hay error)

### Tabla: `wp_checkout_monitor_server_logs`
Almacena logs del servidor correlacionados:
- Fuente del log (WooCommerce, WordPress, Server, Plugins)
- Contenido del log
- Nivel (error, warning, notice, debug)
- Ruta del archivo
- Timestamp

## Uso

### Activación
1. Habilitar el módulo desde **MAD Plugins > Checkout Monitor**
2. Las tablas de base de datos se crean automáticamente
3. El tracking se activa automáticamente en todas las páginas de checkout

### Visualización
1. Ir a **MAD Plugins > Checkout Monitor**
2. Ver estadísticas generales en la parte superior
3. Tabla de sesiones muestra todos los checkouts monitorizados
4. Click en "Ver Detalle" para ver el timeline completo de una sesión

### Filtros
- **Búsqueda**: Por Session ID, Order ID, Payment Method
- **Estado**: Filtrar por initiated, processing, completed, failed
- **Errores**: Solo sesiones con errores o sin errores
- **Fechas**: Rango de fechas específico

### Mantenimiento
- **Retención de Datos**: Configurar días de retención (default: 30 días)
- **Limpieza Automática**: Cron diario elimina logs antiguos
- **Limpieza Manual**: Botón para eliminar logs inmediatamente

## Casos de Uso

### Diagnóstico de Errores Intermitentes
El problema descrito en el contexto (pedidos que no se crean, errores de Mailchimp) se puede diagnosticar así:

1. **Identificar sesiones fallidas**: Filtrar por "has_errors = 1"
2. **Ver timeline de eventos**: Identificar en qué punto exacto falla
3. **Analizar plugins**: Ver qué plugin se ejecuta antes del error
4. **Revisar logs del servidor**: Correlacionar con logs de Apache/PHP
5. **Analizar dispositivo**: Ver si el problema es específico de móvil/desktop

### Ejemplo del Caso Real
```
Session ID: cm_abc123...
Estado: failed
Error: Call to a member function get_meta() on bool

Timeline:
1. ✓ woocommerce_checkout_process (50ms)
2. ✓ woocommerce_checkout_order_created (120ms)
3. ❌ woocommerce_new_order (Error)
   Plugin: mailchimp-for-woocommerce
   Callback: MailChimp_Service::handleOrderCreate
   Error: Trying to call get_meta() on bool

Server Logs:
- woo_save_checkout_fields_safe: no valid order found for ID: 0
- PHP Fatal error: Call to a member function get_meta() on bool
```

**Diagnóstico**: Mailchimp intenta procesar el pedido antes de que esté completamente creado, causando un fatal error.

**Solución**: Aumentar la prioridad del hook de Mailchimp o validar que el pedido existe antes de procesarlo.

## Optimización de Performance

El módulo está optimizado para minimizar el impacto:
- Solo se activa en páginas de checkout
- Los logs se escriben en batch cuando es posible
- Las tablas tienen índices optimizados
- La limpieza automática previene crecimiento excesivo

## Requisitos

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+
- MySQL 5.7+ o MariaDB 10.2+

## Hooks Monitorizados

### Checkout Process
- woocommerce_checkout_process
- woocommerce_after_checkout_validation
- woocommerce_checkout_order_processed
- woocommerce_checkout_order_created

### Order Creation
- woocommerce_new_order
- woocommerce_checkout_update_order_meta
- woocommerce_create_order
- woocommerce_before_order_object_save
- woocommerce_after_order_object_save

### Payment
- woocommerce_before_pay_action
- woocommerce_payment_complete
- woocommerce_checkout_before_payment

### Y muchos más... (ver HookInterceptor.php para lista completa)

## Seguridad y Privacidad

- Los datos del navegador NO incluyen información personal
- Los datos se almacenan localmente en la base de datos del sitio
- No se envía información a servicios externos
- Los logs antiguos se eliminan automáticamente

## Troubleshooting

### Las tablas no se crean
- Verificar permisos de base de datos
- Verificar que dbDelta esté disponible
- Revisar logs de WordPress

### No se capturan eventos
- Verificar que WooCommerce esté activo
- Verificar que el módulo esté habilitado
- Verificar que estás en una página de checkout real

### Performance lento
- Reducir días de retención
- Ejecutar limpieza manual
- Verificar índices de base de datos

## Autor

MAD Suite - Módulo de monitorización avanzada para WooCommerce

## Versión

1.0.0 - Primera versión
