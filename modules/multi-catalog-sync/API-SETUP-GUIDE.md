# Guía de Configuración de APIs

Esta guía te ayudará a configurar las credenciales para Google Merchant Center, Facebook Catalog y Pinterest Catalog.

---

## 📦 Google Merchant Center

### Requisitos Previos
1. Cuenta de Google Merchant Center activa
2. Proyecto en Google Cloud Platform
3. API de Content API for Shopping habilitada

### Paso 1: Crear Proyecto en Google Cloud

1. Ve a [Google Cloud Console](https://console.cloud.google.com/)
2. Crea un nuevo proyecto o selecciona uno existente
3. Anota el nombre del proyecto

### Paso 2: Habilitar Content API for Shopping

1. En Google Cloud Console, ve a **APIs & Services → Library**
2. Busca "Content API for Shopping"
3. Haz clic en **Enable** (Habilitar)

### Paso 3: Crear Service Account

1. Ve a **APIs & Services → Credentials**
2. Haz clic en **Create Credentials → Service Account**
3. Configura:
   - **Service account name**: `woocommerce-catalog-sync`
   - **Service account ID**: (se genera automáticamente)
   - **Role**: Básico → Editor (o Content API Admin)
4. Haz clic en **Done**

### Paso 4: Generar Clave JSON

1. En la lista de Service Accounts, encuentra el que acabas de crear
2. Haz clic en los 3 puntos (⋮) → **Manage keys**
3. Haz clic en **Add Key → Create new key**
4. Selecciona **JSON**
5. Haz clic en **Create**
6. Se descargará un archivo JSON automáticamente
7. **GUARDA ESTE ARCHIVO DE FORMA SEGURA**

### Paso 5: Vincular Service Account con Merchant Center

1. Ve a [Google Merchant Center](https://merchants.google.com/)
2. Ve a **Settings (⚙️) → Account access**
3. Haz clic en el ícono **+** (Agregar usuario)
4. Ingresa el **email del Service Account** (formato: `nombre@proyecto.iam.gserviceaccount.com`)
   - Lo encuentras en el archivo JSON descargado, campo `client_email`
5. Asigna permisos: **Admin** o **Standard**
6. Guarda

### Paso 6: Configurar en WordPress

1. Ve a **MAD Plugins → Catalog Sync → Google Merchant**
2. Pega el **Merchant ID**:
   - Lo encuentras en Merchant Center, esquina superior derecha
   - Formato: números de 9-12 dígitos (ej: `123456789`)
3. Pega el **contenido completo del archivo JSON** en el campo "Service Account JSON"
   - Abre el archivo JSON con un editor de texto
   - Copia TODO el contenido (desde `{` hasta `}`)
   - Pega en el campo
4. Marca **"Habilitar sincronización"**
5. Guarda cambios

### Verificación

1. Ve al tab **Dashboard**
2. La tarjeta de Google Merchant Center debería mostrar **"Conectado"** ✅
3. Haz clic en **"Sincronizar Ahora"** para probar

---

## 📘 Facebook Catalog

### Requisitos Previos
1. Facebook Business Manager activo
2. Catálogo de productos creado en Commerce Manager
3. Aplicación de Facebook configurada (opcional pero recomendado)

### Opción A: Token de Usuario (Más Simple)

#### Paso 1: Obtener Access Token

1. Ve a [Facebook Graph API Explorer](https://developers.facebook.com/tools/explorer/)
2. Selecciona tu aplicación (o usa "Graph API Explorer" por defecto)
3. En **Permissions**, agrega:
   - `catalog_management`
   - `business_management`
4. Haz clic en **Generate Access Token**
5. Autoriza los permisos
6. **COPIA EL TOKEN** (empieza con `EAA...`)

⚠️ **Importante**: Este token expira en ~1-2 horas.

#### Paso 2: Convertir a Long-Lived Token (60 días)

Usa esta URL (reemplaza los valores):
```
https://graph.facebook.com/v18.0/oauth/access_token?grant_type=fb_exchange_token&client_id=TU_APP_ID&client_secret=TU_APP_SECRET&fb_exchange_token=TOKEN_CORTO
```

El response tendrá el `access_token` de larga duración.

### Opción B: System User Token (Recomendado para Producción)

#### Paso 1: Crear System User

1. Ve a [Business Settings](https://business.facebook.com/settings/)
2. Ve a **Users → System Users**
3. Haz clic en **Add**
4. Nombre: `WooCommerce Catalog Sync`
5. Rol: **Admin**

#### Paso 2: Generar Token

1. Haz clic en **Generate New Token**
2. Selecciona tu App
3. Permisos:
   - `catalog_management`
   - `business_management`
4. Copia el token (no expira)

### Paso 3: Obtener Catalog ID

1. Ve a [Commerce Manager](https://business.facebook.com/commerce/)
2. Selecciona tu catálogo
3. El **Catalog ID** está en la URL:
   - `https://business.facebook.com/commerce/catalogs/XXXXXXXXXX/`
   - Copia los números `XXXXXXXXXX`

### Paso 4: Configurar en WordPress

1. Ve a **MAD Plugins → Catalog Sync → Facebook**
2. Pega el **Catalog ID**
3. Pega el **Access Token**
4. Marca **"Habilitar sincronización"**
5. Guarda cambios

### Verificación

1. Ve al tab **Dashboard**
2. La tarjeta de Facebook debería mostrar **"Conectado"** ✅
3. Haz clic en **"Sincronizar Ahora"** para probar

---

## 📌 Pinterest Catalog

### Requisitos Previos
1. Cuenta de Pinterest Business
2. Catálogo creado en Pinterest
3. App de Pinterest creada

### Paso 1: Crear Pinterest App

1. Ve a [Pinterest Developers](https://developers.pinterest.com/)
2. Haz clic en **My Apps**
3. Crea una nueva app o usa una existente
4. Anota el **App ID** y **App Secret**

### Paso 2: Generar Access Token

#### Método Manual (OAuth2)

1. Ve a tu app en Pinterest Developers
2. Ve a **OAuth**
3. Construye esta URL (reemplaza valores):
```
https://www.pinterest.com/oauth/?client_id=TU_APP_ID&redirect_uri=https://localhost/&response_type=code&scope=catalogs:read,catalogs:write
```

4. Pega en el navegador y autoriza
5. Te redirigirá a una URL con `code=XXXX`
6. Usa este código para obtener el token:

**Request POST:**
```
curl -X POST https://api.pinterest.com/v5/oauth/token \
  -d "grant_type=authorization_code" \
  -d "code=CODIGO_AQUI" \
  -d "redirect_uri=https://localhost/" \
  -d "client_id=TU_APP_ID" \
  -d "client_secret=TU_APP_SECRET"
```

El response incluirá el `access_token`.

### Paso 3: Obtener Catalog ID

**Opción 1: Via API**
```bash
curl https://api.pinterest.com/v5/catalogs \
  -H "Authorization: Bearer TU_ACCESS_TOKEN"
```

Busca el `id` de tu catálogo en el response.

**Opción 2: Via Pinterest Business Hub**
1. Ve a [Pinterest Business Hub](https://www.pinterest.com/business/catalogs/)
2. Selecciona tu catálogo
3. El Catalog ID está en la URL

### Paso 4: Configurar en WordPress

1. Ve a **MAD Plugins → Catalog Sync → Pinterest**
2. Pega el **Catalog ID**
3. Pega el **Access Token** (comienza con `pina_`)
4. Marca **"Habilitar sincronización"**
5. Guarda cambios

### Verificación

1. Ve al tab **Dashboard**
2. La tarjeta de Pinterest debería mostrar **"Conectado"** ✅
3. Haz clic en **"Sincronizar Ahora"** para probar

---

## ⚠️ Problemas Comunes

### Google: "Invalid credentials"
- Verifica que el Service Account esté agregado en Merchant Center
- Verifica que el JSON esté completo (desde `{` hasta `}`)
- Asegúrate de que Content API esté habilitada

### Facebook: "Invalid OAuth access token"
- El token expiró (si usaste token de usuario)
- Usa System User token para tokens permanentes
- Verifica que los permisos incluyan `catalog_management`

### Pinterest: "Unauthorized"
- El access token no tiene los scopes correctos
- Regenera el token con scopes: `catalogs:read,catalogs:write`

### "Falta categoría de Google"
- Asegúrate de haber asignado categorías de Google a tus categorías de WooCommerce
- Ve a **Productos → Categorías** y edita cada categoría

---

## 🔐 Seguridad

### Mejores Prácticas

1. **Nunca compartas tus tokens/credentials**
2. **No subas el archivo JSON de Google a repositorios públicos**
3. **Usa tokens de System User en producción (Facebook)**
4. **Regenera tokens si se comprometen**
5. **Limita permisos al mínimo necesario**

### Rotación de Credenciales

**Google:**
- Crea un nuevo Service Account
- Descarga nueva clave JSON
- Actualiza en WordPress
- Elimina la clave antigua

**Facebook:**
- Genera nuevo token en System User
- Actualiza en WordPress
- Revoca el token anterior

**Pinterest:**
- Genera nuevo access token
- Actualiza en WordPress

---

## 📚 Referencias

- [Google Content API Documentation](https://developers.google.com/shopping-content/guides/quickstart)
- [Facebook Catalog API](https://developers.facebook.com/docs/marketing-api/catalog/)
- [Pinterest Catalogs API](https://developers.pinterest.com/docs/api/v5/#tag/catalogs)

---

## 💡 Tips Adicionales

### Testing en Modo Sandbox

**Google:** Usa una cuenta de Merchant Center de prueba

**Facebook:** Crea un catálogo de prueba en Commerce Manager

**Pinterest:** Usa un catálogo separado para pruebas

### Monitoreo

1. Revisa el **Dashboard** regularmente para ver errores
2. Consulta los **Logs** en WordPress:
   - `WooCommerce → Status → Logs`
   - Busca archivos: `multi-catalog-sync-YYYY-MM-DD-*.log`

3. Verifica en las plataformas:
   - [Google Merchant Center - Diagnósticos](https://merchants.google.com/)
   - [Facebook Commerce Manager - Problemas](https://business.facebook.com/commerce/)
   - Pinterest Business Hub

### Límites de API

- **Google**: ~10 requests/segundo
- **Facebook**: ~200 requests/segundo
- **Pinterest**: ~10 requests/segundo

El plugin maneja estos límites automáticamente con batch requests.

---

¿Necesitas ayuda? Consulta los logs o contacta al desarrollador del plugin.
