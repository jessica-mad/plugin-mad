# Google Product Taxonomy - Archivo Completo

## ⚠️ IMPORTANTE

El archivo `google-taxonomy.json` incluido actualmente es solo una **muestra de 85 categorías** para fines de demostración. Para uso en producción, debes descargar la taxonomía completa de Google que contiene **más de 6000 categorías** con hasta 7 niveles de jerarquía.

## 🔍 Problema Actual

Si solo encuentras categorías básicas (hasta 3 niveles) en el buscador del módulo, es porque estás usando el archivo de muestra limitado.

**Archivo actual (muestra)**: 85 categorías
**Archivo completo necesario**: ~6000 categorías

## 📥 Cómo Descargar la Taxonomía Completa

### Opción 1: Descarga Directa (TXT)

1. Descarga el archivo oficial de Google:
   ```
   https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt
   ```

2. El archivo TXT tiene este formato:
   ```
   # Google_Product_Taxonomy_Version: 2021-09-21
   1 - Apparel & Accessories
   166 - Apparel & Accessories > Clothing
   212 - Apparel & Accessories > Clothing > Shirts & Tops
   ```

### Opción 2: Convertir TXT a JSON

Necesitas convertir el archivo TXT al formato JSON que usa el módulo.

#### Script PHP para Convertir:

Crea un archivo temporal `convert-taxonomy.php` en el directorio raíz de WordPress:

```php
<?php
// convert-taxonomy.php
// Ejecuta este archivo una sola vez desde el navegador o CLI

$txt_file = 'taxonomy-with-ids.en-US.txt';
$json_file = 'wp-content/plugins/plugin-mad/modules/multi-catalog-sync/data/google-taxonomy.json';

if (!file_exists($txt_file)) {
    die("ERROR: Descarga primero el archivo TXT de Google y colócalo en el directorio raíz\n");
}

$lines = file($txt_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$taxonomy = [];

foreach ($lines as $line) {
    // Skip comments
    if (strpos($line, '#') === 0) {
        continue;
    }

    // Parse: "ID - Category > Subcategory > Item"
    if (preg_match('/^(\d+)\s*-\s*(.+)$/', $line, $matches)) {
        $id = $matches[1];
        $path = $matches[2];
        $taxonomy[$id] = $path;
    }
}

// Sort by ID
ksort($taxonomy);

// Save as JSON
$json = json_encode($taxonomy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents($json_file, $json);

echo "✅ Taxonomía convertida exitosamente!\n";
echo "Total de categorías: " . count($taxonomy) . "\n";
echo "Archivo guardado en: $json_file\n";
echo "\n⚠️ IMPORTANTE: Elimina este archivo (convert-taxonomy.php) después de usarlo.\n";
```

#### Pasos:

1. Descarga `taxonomy-with-ids.en-US.txt` de Google
2. Coloca el archivo TXT en el directorio raíz de WordPress
3. Crea el archivo `convert-taxonomy.php` con el código anterior
4. Ejecuta desde navegador: `https://tu-sitio.com/convert-taxonomy.php`
   O desde CLI: `php convert-taxonomy.php`
5. Elimina ambos archivos temporales después de la conversión

### Opción 3: Comando rápido (CLI Linux/Mac)

Si tienes acceso SSH:

```bash
# Descargar taxonomía
cd /tmp
wget https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt

# Convertir con awk/sed
grep -v '^#' taxonomy-with-ids.en-US.txt | \
awk -F ' - ' '{gsub(/"/, "\\\"", $2); printf "\"%s\": \"%s\",\n", $1, $2}' | \
sed '1s/^/{\"/' | sed '$s/,$/}/' > taxonomy-temp.json

# Mover al directorio correcto
mv taxonomy-temp.json /ruta/a/plugin-mad/modules/multi-catalog-sync/data/google-taxonomy.json

# Limpiar
rm taxonomy-with-ids.en-US.txt
```

## ✅ Verificación

Después de instalar la taxonomía completa:

1. Ve a **Productos → Categorías**
2. Edita cualquier categoría
3. En el campo "Google Product Category", busca términos específicos como:
   - "Baby Strollers" (debería aparecer)
   - "Laptops" → debería mostrar múltiples resultados con diferentes jerarquías
   - Categorías con 5-6 niveles de profundidad

Si ves cientos de resultados y categorías muy específicas, ¡la taxonomía completa está instalada correctamente!

## 📊 Diferencias

| Característica | Archivo Muestra | Archivo Completo |
|----------------|-----------------|------------------|
| **Categorías** | 85 | ~6,000 |
| **Niveles máximos** | 3-4 | 7 |
| **Idioma** | EN-US | EN-US |
| **Versión** | Sample | 2024-xx-xx |
| **Especificidad** | Baja | Alta |

## 🌐 Idiomas Disponibles

Google ofrece la taxonomía en varios idiomas:
- `taxonomy-with-ids.en-US.txt` - Inglés (recomendado)
- `taxonomy-with-ids.es-ES.txt` - Español
- `taxonomy-with-ids.fr-FR.txt` - Francés
- `taxonomy-with-ids.de-DE.txt` - Alemán
- Y más...

**Nota**: El módulo actualmente solo soporta la versión EN-US. Si necesitas otro idioma, modifica la URL de descarga.

## 🔄 Actualización

Google actualiza la taxonomía cada 6-12 meses. Verifica la versión en la primera línea del archivo TXT descargado.

## ❓ Soporte

Si tienes problemas con la conversión o la descarga, revisa:
1. Que el archivo JSON tenga el formato correcto: `{"ID": "Path", "ID2": "Path2"}`
2. Que los caracteres especiales estén correctamente escapados
3. Que el archivo JSON sea válido (usa un validador JSON online)

## 📝 Notas

- El archivo de muestra (85 categorías) es suficiente para pruebas iniciales
- Para producción, **DEBES** usar la taxonomía completa
- El archivo JSON completo pesa aproximadamente 500KB
- No necesitas reiniciar el servidor después de reemplazar el archivo
