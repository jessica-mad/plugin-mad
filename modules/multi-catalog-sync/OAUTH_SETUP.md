# Google Merchant Center - OAuth2 Setup Guide

Este documento explica cómo configurar la autenticación OAuth2 para Google Merchant Center en el plugin Multi-Catalog Sync.

## 📋 Tres Métodos de Autenticación Disponibles

El plugin soporta 3 métodos de autenticación con Google Merchant Center:

### 1. Service Account (JSON) - Método Tradicional
- ✅ Para usuarios de Gmail sin restricciones
- ✅ Funciona bien para cuentas personales
- ⚠️ Puede tener problemas con organizaciones que tienen IAM bloqueado

### 2. OAuth2 - MAD Suite App (RECOMENDADO)
- ✅ Más fácil de configurar
- ✅ Ideal para organizaciones con IAM
- ✅ No requiere permisos especiales
- ✅ Solo hacer clic en "Conectar"

### 3. OAuth2 - App Personalizada (AVANZADO)
- ✅ Control total para usuarios técnicos
- ✅ Usa tu propia OAuth App
- ⚠️ Requiere configuración en Google Cloud Console

---

## 🚀 Opción 2: Configurar MAD Suite OAuth App

### Para el Desarrollador (Una sola vez):

Si eres el desarrollador del plugin, necesitas crear la OAuth App central que todos los usuarios utilizarán.

#### Paso 1: Crear Proyecto en Google Cloud Console

1. Ve a [Google Cloud Console](https://console.cloud.google.com)
2. Crea un nuevo proyecto o selecciona uno existente
3. Nombre sugerido: "MAD Suite Multi-Catalog Sync"

#### Paso 2: Habilitar Google Merchant API

1. Ve a **APIs & Services** > **Library**
2. Busca "Merchant API"
3. Haz clic en **"Content API for Shopping"**
4. Clic en **"Enable"**

#### Paso 3: Crear OAuth 2.0 Client ID

1. Ve a **APIs & Services** > **Credentials**
2. Clic en **"Create Credentials"** > **"OAuth 2.0 Client ID"**
3. Si es tu primera vez, te pedirá configurar "OAuth consent screen":

   **OAuth Consent Screen:**
   - User Type: **External** (para que cualquier usuario de Google pueda autorizar)
   - App name: `MAD Suite - Multi-Catalog Sync`
   - User support email: tu email
   - Developer contact: tu email
   - Scopes: Añade `https://www.googleapis.com/auth/content`

4. Después de configurar consent screen, vuelve a **Credentials**
5. Clic en **"Create Credentials"** > **"OAuth 2.0 Client ID"**
6. Application type: **Web application**
7. Name: `MAD Suite OAuth`
8. **Authorized redirect URIs**: (dejar vacío por ahora, se añadirán dinámicamente)

#### Paso 4: Añadir Credenciales al Plugin

1. Copia tu **Client ID** y **Client Secret**
2. Abre `modules/multi-catalog-sync/includes/Destinations/GoogleOAuthHandler.php`
3. Reemplaza las constantes:

```php
const MAD_SUITE_CLIENT_ID = 'TU_CLIENT_ID.apps.googleusercontent.com';
const MAD_SUITE_CLIENT_SECRET = 'TU_CLIENT_SECRET';
```

#### Paso 5: Configurar Redirect URIs Dinámicos

Las redirect URIs varían por instalación:
```
https://sitio-cliente.com/wp-admin/admin.php?page=madsuite-multi-catalog-sync&action=google_oauth_callback
```

**Opciones:**

**Opción A: Wildcard (Recomendado si Google lo permite)**
```
https://**/wp-admin/admin.php?page=madsuite-multi-catalog-sync&action=google_oauth_callback
```

**Opción B: Registro manual por cliente**
Cada vez que un nuevo cliente use el plugin, añade su redirect URI a la lista.

**Opción C: Dominio de redirect centralizado** (Más complejo pero escalable)
Crear un servicio intermedio que maneje el callback y redirija al sitio del cliente.

#### Paso 6: Publicar la App (Opcional pero Recomendado)

1. Ve a **OAuth consent screen**
2. Clic en **"Publish App"**
3. Esto evita la pantalla de "App no verificada"
4. Para producción, considera hacer el proceso de verificación de Google

---

### Para los Usuarios del Plugin:

#### Configuración en WordPress

1. Ve a **Multi-Catalog Sync** > **Settings**
2. En la sección **Google Merchant Center**:
   - Método de Autenticación: Selecciona **"OAuth2"**
   - OAuth2 Configuration: Selecciona **"Usar OAuth App de MAD Suite"** (Más fácil)
3. **Guarda los cambios**
4. Haz clic en **"🔗 Conectar con Google Merchant Center"**
5. Se abrirá una ventana popup de Google
6. **Autoriza** el acceso a tu Merchant Center
7. La ventana se cerrará automáticamente
8. ¡Listo! Verás el estado **"✅ Conectado"**

---

## 🛠️ Opción 3: Configurar Tu Propia OAuth App

Para usuarios avanzados que quieren usar su propia OAuth App.

### Paso 1: Crear OAuth App

Sigue los pasos 1-3 de "Opción 2" pero crea tu propia app.

### Paso 2: Configurar Redirect URI

En **Authorized redirect URIs**, añade:
```
https://TU-SITIO.com/wp-admin/admin.php?page=madsuite-multi-catalog-sync&action=google_oauth_callback
```

(Reemplaza `TU-SITIO.com` con tu dominio real)

### Paso 3: Configurar en WordPress

1. Ve a **Multi-Catalog Sync** > **Settings**
2. En la sección **Google Merchant Center**:
   - Método de Autenticación: Selecciona **"OAuth2"**
   - OAuth2 Configuration: Selecciona **"Usar mi propia OAuth App"** (Avanzado)
3. Verás campos adicionales:
   - **Client ID**: Pega tu Client ID
   - **Client Secret**: Pega tu Client Secret
   - **Redirect URI**: Copia esta URL (es automática)
4. **Guarda los cambios**
5. Haz clic en **"🔗 Conectar con Google Merchant Center"**
6. Autoriza el acceso
7. ¡Listo!

---

## 🔒 Seguridad y Privacidad

### ¿Los datos están seguros?

**SÍ**, completamente:

1. **Tokens Encriptados**: Los refresh tokens se guardan encriptados en la base de datos usando AES-256-CBC
2. **Acceso Segmentado**: Cada usuario solo tiene acceso a SU propio Merchant Center
3. **Revocable**: Puedes desconectar en cualquier momento
4. **CSRF Protection**: Todas las solicitudes usan nonces de WordPress

### ¿Qué permisos se solicitan?

Solo un permiso:
- `https://www.googleapis.com/auth/content` - Acceso a Google Merchant Center Content API

### ¿Puedo revocar el acceso?

**Sí**, de tres formas:

1. **Desde el plugin**: Clic en "🔌 Desconectar"
2. **Desde Google**: Ve a https://myaccount.google.com/permissions y revoca "MAD Suite"
3. **Borrando la base de datos**: Los tokens están en `wp_options`

---

## 🐛 Troubleshooting

### Error: "redirect_uri_mismatch"

**Problema**: La redirect URI no coincide.

**Solución**:
1. Ve a Google Cloud Console > Credentials
2. Verifica que la redirect URI en la OAuth App sea EXACTAMENTE:
   ```
   https://TU-SITIO.com/wp-admin/admin.php?page=madsuite-multi-catalog-sync&action=google_oauth_callback
   ```
3. Incluye `https://` (no `http://`)
4. Sin trailing slash al final

### Error: "No se pudo generar la URL de autorización"

**Problema**: Client ID o Client Secret no configurados.

**Solución**:
- Si usas MAD Suite App: El desarrollador debe configurar las constantes en `GoogleOAuthHandler.php`
- Si usas tu propia app: Verifica que hayas ingresado Client ID y Client Secret correctamente

### Error: "App no verificada"

**Problema**: Google muestra advertencia de "App no verificada".

**Solución**:
- Haz clic en "Avanzado" > "Ir a MAD Suite (no seguro)"
- Esto es normal para apps en desarrollo
- Para producción, el desarrollador debe verificar la app con Google

### Popup bloqueado

**Problema**: El navegador bloquea el popup de autorización.

**Solución**:
- Permite popups para tu sitio
- O desactiva bloqueador de popups temporalmente

---

## 📚 Recursos Adicionales

- [Google Merchant API Documentation](https://developers.google.com/merchant/api)
- [Google OAuth 2.0 Documentation](https://developers.google.com/identity/protocols/oauth2)
- [WordPress Transients API](https://developer.wordpress.org/apis/transients/)

---

## 💡 FAQ

**P: ¿Cuál método debo usar?**
**R**: Si tienes problemas con IAM en tu organización, usa OAuth2. Sino, Service Account funciona bien.

**P: ¿El refresh token expira?**
**R**: No, los refresh tokens de Google no expiran (a menos que el usuario los revoque manualmente).

**P: ¿Puedo cambiar de método después?**
**R**: Sí, puedes cambiar entre Service Account y OAuth2 en cualquier momento desde Settings.

**P: ¿Qué pasa si mi token se invalida?**
**R**: El plugin intentará renovarlo automáticamente. Si falla, verás un mensaje para reconectar.

---

## ✉️ Soporte

Si tienes problemas con la configuración OAuth2, revisa:
1. Los logs del plugin en **Multi-Catalog Sync** > **Logs**
2. Este documento de troubleshooting
3. La documentación oficial de Google

---

**Última actualización**: Diciembre 2024
**Versión**: 1.0.0
