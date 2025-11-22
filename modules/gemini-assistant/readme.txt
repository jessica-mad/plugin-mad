=== MAD Gemini Assistant ===
Contributors: MAD Suite
Tags: gemini, ai, chat, assistant, google
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Módulo de MAD Suite para integración con Google Gemini AI. Chat interactivo con soporte multimodal.

== Descripción ==

MAD Gemini Assistant es un módulo de MAD Suite que permite integrar Google Gemini AI en tu panel de WordPress. Proporciona una interfaz de chat completa con soporte para:

* **Chat Interactivo**: Conversaciones fluidas con la IA de Google Gemini
* **Soporte Multimodal**: Envío de imágenes, PDFs y otros documentos
* **Gestión de Conversaciones**: Historial completo de todas tus conversaciones
* **Múltiples Modelos**: Soporte para Gemini 2.5 Flash, Gemini Pro y más
* **Configuración Avanzada**: Control de temperatura, tokens, y otros parámetros
* **Interfaz Limpia**: Diseño moderno y responsive

== Características ==

= Funcionalidades Principales =

1. **Interfaz de Chat**
   - Área de conversación con scroll
   - Input multilinea con soporte para Shift+Enter
   - Renderizado de respuestas en Markdown
   - Indicador de "escribiendo" durante peticiones
   - Manejo visual de errores

2. **Upload de Archivos**
   - Soporte para imágenes: JPG, PNG, WebP, GIF, HEIC
   - Soporte para documentos: PDF, TXT
   - Preview de archivos antes de enviar
   - Múltiples archivos por mensaje
   - Validación de tipos y tamaños (máx. 20MB)

3. **Gestión de Conversaciones**
   - Crear nuevas conversaciones
   - Cargar conversaciones anteriores
   - Eliminar conversaciones
   - Títulos auto-generados
   - Historial completo con timestamps

4. **Configuración**
   - API Key encriptada
   - Selector de modelo Gemini
   - Control de temperatura (creatividad)
   - Configuración de tokens máximos
   - Test de conexión API
   - Parámetros Top P y Top K

= Seguridad =

* API Key almacenada de forma encriptada
* Sanitización de todos los inputs
* Escape de outputs
* Nonces para todas las peticiones AJAX
* Validación de archivos y tamaños

= Requisitos =

* WordPress 5.8 o superior
* PHP 7.4 o superior
* MAD Suite instalado y activado
* API Key de Google Gemini (gratuita desde Google AI Studio)

== Instalación ==

1. Asegúrate de tener MAD Suite instalado
2. El módulo viene incluido en MAD Suite
3. Ve a MAD Plugins > Módulos
4. Activa "Gemini Assistant"
5. Ve a MAD Plugins > Gemini AI > Configuración
6. Obtén tu API Key desde https://aistudio.google.com/app/apikey
7. Ingresa tu API Key y guarda
8. ¡Listo! Comienza a chatear en la pestaña Chat

== Uso ==

= Obtener API Key =

1. Visita https://aistudio.google.com/app/apikey
2. Inicia sesión con tu cuenta de Google
3. Crea una nueva API Key
4. Copia la key
5. Pégala en MAD Plugins > Gemini AI > Configuración

= Chatear con Gemini =

1. Ve a MAD Plugins > Gemini AI
2. Escribe tu mensaje en el input
3. Opcionalmente, adjunta imágenes o PDFs
4. Presiona Enviar o Enter
5. Espera la respuesta de Gemini

= Gestionar Conversaciones =

* **Nueva conversación**: Click en "Nueva conversación" en el sidebar
* **Cargar conversación**: Click en cualquier conversación del sidebar
* **Eliminar conversación**: Click en el icono de basura

= Adjuntar Archivos =

1. Click en el icono de clip
2. Selecciona uno o más archivos (máx. 20MB cada uno)
3. Verás un preview de los archivos
4. Escribe tu mensaje (opcional)
5. Envía

== Preguntas Frecuentes ==

= ¿Es gratis? =

El módulo es completamente gratuito. La API de Google Gemini tiene un tier gratuito generoso. Consulta los precios actuales en la documentación de Google AI.

= ¿Qué modelos soporta? =

Actualmente soporta:
* Gemini 2.5 Flash (Recomendado)
* Gemini 2.0 Flash Experimental
* Gemini 1.5 Pro
* Gemini 1.5 Flash
* Gemini Pro

= ¿Dónde se guardan las conversaciones? =

Las conversaciones se guardan localmente en tu base de datos de WordPress. No se comparten con terceros excepto Google para procesar las peticiones.

= ¿Qué tipos de archivos puedo enviar? =

* Imágenes: JPG, JPEG, PNG, WebP, GIF, HEIC, HEIF
* Documentos: PDF, TXT
* Tamaño máximo: 20MB por archivo

= ¿Es seguro almacenar mi API Key? =

Sí, la API Key se almacena encriptada en la base de datos usando las funciones de seguridad de WordPress.

= ¿Puedo usar esto en producción? =

Sí, el código está diseñado para producción y sigue las mejores prácticas de WordPress. Sin embargo, siempre prueba en un entorno de desarrollo primero.

== Changelog ==

= 1.0.0 - 2025-01-XX =
* Lanzamiento inicial
* Interfaz de chat completa
* Soporte multimodal (imágenes y PDFs)
* Gestión de conversaciones
* Configuración de modelos y parámetros
* Test de conexión API
* Renderizado de Markdown
* Diseño responsive

== Upgrade Notice ==

= 1.0.0 =
Primera versión del módulo.

== Soporte ==

Para soporte y documentación adicional:
* Repositorio: https://github.com/jessica-mad/plugin-mad
* Documentación de Gemini: https://ai.google.dev/gemini-api/docs

== Créditos ==

* Desarrollado para MAD Suite
* Powered by Google Gemini AI
* Markdown rendering por Marked.js
