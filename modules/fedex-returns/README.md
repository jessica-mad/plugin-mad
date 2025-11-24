# FedEx Returns Integration

Módulo de integración con FedEx para gestionar devoluciones de pedidos de WooCommerce.

## Características

- **Integración con API de FedEx**: Conexión directa con la API de FedEx para crear envíos de devolución
- **Gestión de Devoluciones**: Crear devoluciones desde la interfaz de administración de pedidos
- **Facturas de Devolución**: Adjunta automáticamente las facturas existentes generadas por tu plugin de facturas
- **Seguimiento**: Tracking de envíos de devolución directamente desde WooCommerce
- **Borradores en FedEx**: Las devoluciones se crean como borradores en FedEx para revisión
- **Logs Detallados**: Sistema de logging para auditoría y debugging

## Instalación

1. El módulo ya está incluido en la estructura del plugin MAD Suite
2. Ve a **MAD Plugins** en el panel de WordPress
3. Activa el módulo **FedEx Returns**
4. Configura las credenciales de FedEx en **MAD Plugins > FedEx Returns**

## Configuración

### 1. Credenciales de FedEx API

Ve a **MAD Plugins > FedEx Returns > Credenciales FedEx** y completa:

- **Ambiente**: Selecciona Test (Sandbox) o Producción
- **API Key**: Tu clave API de FedEx
- **API Secret**: Tu secreto API de FedEx
- **Número de Cuenta**: Tu número de cuenta FedEx
- **Meter Number**: Tu Meter Number de FedEx

**Nota**: Para obtener credenciales, regístrate en [FedEx Developer Portal](https://developer.fedex.com/)

### 2. Información del Remitente

Configura la dirección de tu almacén donde llegarán las devoluciones:

- Nombre de contacto
- Nombre de empresa
- Teléfono
- Dirección completa
- Ciudad, Estado, Código Postal
- País (código de 2 letras, ej: MX)

### 3. Valores por Defecto

- **Tipo de Servicio**: FedEx Ground, 2Day, Express, etc.
- **Tipo de Empaque**: Tu empaque, FedEx Box, etc.
- **Unidades**: KG/LB para peso, CM/IN para dimensiones

### 4. Opciones Generales

- ✅ Adjuntar Factura al Envío (usa facturas existentes generadas por tu plugin de facturas)
- ✅ Permitir Devoluciones Parciales
- ✅ Requerir Motivo de Devolución
- ✅ Habilitar Logs

## Uso

### Crear una Devolución

1. Ve a **WooCommerce > Pedidos**
2. Abre el pedido que deseas procesar
3. En el metabox **FedEx Returns** en la barra lateral:
   - Selecciona los productos a devolver (si están habilitadas las devoluciones parciales)
   - Ingresa el peso del paquete
   - Ingresa las dimensiones (largo x ancho x alto)
   - Escribe el motivo de la devolución
   - Haz clic en **Crear Devolución en FedEx**

4. El sistema:
   - Busca y adjunta la factura existente del pedido (si está habilitado)
   - Crea el envío en FedEx como borrador
   - Obtiene el tracking number
   - Descarga la etiqueta de envío
   - Guarda toda la información en el pedido

### Revisar Estado de Devolución

1. En el pedido, dentro del metabox **FedEx Returns**
2. Haz clic en **Actualizar Estado**
3. El sistema consultará el estado actual en FedEx

### Ver Información en Lista de Pedidos

La columna **FedEx Return** muestra:
- Estado de la devolución
- Tracking number
- Icono si no hay devolución

## API de FedEx

Este módulo utiliza la API REST v1 de FedEx. Endpoints utilizados:

- **POST /oauth/token**: Autenticación OAuth 2.0
- **POST /ship/v1/shipments**: Crear envío de devolución
- **POST /track/v1/trackingnumbers**: Rastrear envío

### Autenticación

El módulo maneja automáticamente:
- Obtención de tokens de acceso OAuth
- Renovación de tokens expirados
- Caché de tokens válidos

### Modo Sandbox vs Producción

- **Sandbox**: Para pruebas (apis-sandbox.fedex.com)
- **Producción**: Para operaciones reales (apis.fedex.com)

## Facturas de Devolución

El módulo utiliza las facturas existentes generadas por tu plugin de facturas instalado (como **PDF Invoices & Packing Slips for WooCommerce**).

### Búsqueda Inteligente de Facturas

El sistema busca automáticamente las facturas en:
- **Adjuntos del pedido**: PDFs adjuntos que contengan "invoice" o "factura" en el nombre
- **Meta del pedido**: Rutas guardadas por plugins de facturas
- **Directorios comunes**: `/woocommerce_pdf_invoices`, `/invoices`, `/wpo_wcpdf`

### Compatibilidad

Compatible con los principales plugins de facturas:
- PDF Invoices & Packing Slips for WooCommerce
- WooCommerce PDF Invoices & Packing Slips
- Otros plugins que adjuntan PDFs al pedido

Las facturas encontradas se adjuntan automáticamente a la devolución de FedEx.

## Logs y Debugging

### Ver Logs

1. Ve a **MAD Plugins > FedEx Returns > Logs**
2. Lista de archivos de log por fecha
3. Haz clic en **Ver** para ver el contenido

### Logs Incluyen

- ✅ Creación de devoluciones
- ✅ Llamadas a la API (si está habilitado)
- ✅ Errores y excepciones
- ✅ Cambios de estado
- ✅ Generación de facturas

### Limpiar Logs

Elimina logs antiguos (más de 30 días) para ahorrar espacio.

## Estados de Devolución

- **draft**: Borrador creado en FedEx (pendiente de activación)
- **pending**: Pendiente de recolección
- **in_transit**: En tránsito
- **delivered**: Entregado
- **cancelled**: Cancelado

## Estructura de Archivos

```
modules/fedex-returns/
├── Module.php                      # Clase principal del módulo
├── classes/
│   ├── FedExAPI.php               # Cliente API de FedEx
│   ├── ReturnManager.php          # Gestor de devoluciones
│   ├── InvoiceHandler.php         # Generador de facturas
│   └── Logger.php                 # Sistema de logging
├── views/
│   ├── settings.php               # Página de configuración
│   └── order-metabox.php          # Metabox en pedidos
├── assets/
│   ├── css/
│   │   └── admin.css              # Estilos del admin
│   └── js/
│       └── admin.js               # Scripts del admin
└── README.md                      # Esta documentación
```

## Requisitos

- WordPress 6.0+
- WooCommerce 5.0+
- PHP 7.4+
- Cuenta de FedEx con acceso a API

## Permisos

- Solo usuarios con capacidad `manage_options` pueden configurar el módulo
- Solo usuarios con capacidad `edit_shop_orders` pueden crear devoluciones

## Seguridad

- ✅ Validación de nonces en todas las peticiones AJAX
- ✅ Verificación de permisos
- ✅ Sanitización de inputs
- ✅ Protección de archivos de log con .htaccess
- ✅ Validación de rutas de archivos

## Soporte

Para reportar problemas o solicitar nuevas características, contacta con el equipo de desarrollo.

## Changelog

### Versión 1.0.1
- Cambio a uso de facturas existentes generadas por plugins externos
- Búsqueda inteligente de facturas en múltiples ubicaciones
- Compatibilidad con plugins de facturas populares

### Versión 1.0.0
- Lanzamiento inicial
- Integración con FedEx API REST v1
- Creación de devoluciones
- Sistema de tracking
- Logs y debugging

## Licencia

Este módulo es parte del plugin MAD Suite.
