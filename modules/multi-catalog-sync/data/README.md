# Google Product Taxonomy

Este directorio contiene la taxonomía de productos de Google para mapeo de categorías.

## Estado Actual

El archivo `google-taxonomy.json` incluido es una **muestra básica** con las categorías más comunes (~100 categorías). Para un funcionamiento completo, debes importar la taxonomía oficial de Google con más de 6,000 categorías.

## Cómo Actualizar a la Taxonomía Completa

### Opción 1: Descarga Manual (Recomendado)

1. **Descargar el archivo oficial de Google:**
   - URL: https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt
   - Si el enlace no funciona directamente, busca "Google Product Taxonomy" en Google y descarga desde la página oficial de Google Merchant Center

2. **Importar desde el Admin de WordPress:**
   - Ve a: `MAD Plugins → Catalog Sync → Configuración`
   - Busca la sección "Taxonomía de Google"
   - Haz clic en "Importar Taxonomía"
   - Sube el archivo `taxonomy-with-ids.en-US.txt` descargado
   - El plugin lo convertirá automáticamente a JSON

### Opción 2: Conversión Manual (Avanzado)

Si prefieres hacerlo manualmente:

1. Descarga el archivo `taxonomy-with-ids.en-US.txt` del enlace arriba

2. Usa este script PHP para convertirlo a JSON:

```php
<?php
// convert-taxonomy.php

$input_file = 'taxonomy-with-ids.en-US.txt';
$output_file = 'google-taxonomy.json';

$taxonomy = [];
$lines = file($input_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    // Skip comments
    if (empty($line) || $line[0] === '#') {
        continue;
    }

    // Format: "ID - Category > Subcategory > Item"
    if (preg_match('/^(\d+)\s+-\s+(.+)$/', $line, $matches)) {
        $id = $matches[1];
        $path = $matches[2];
        $taxonomy[$id] = $path;
    }
}

file_put_contents($output_file, json_encode($taxonomy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Convertidas " . count($taxonomy) . " categorías\n";
```

3. Ejecuta: `php convert-taxonomy.php`

4. Reemplaza el archivo `google-taxonomy.json` en este directorio con el nuevo archivo generado

### Opción 3: Importar desde el Admin (Próximamente)

En futuras versiones del plugin, habrá una función de descarga automática directamente desde el admin.

## Alerta de Actualización

El plugin te alertará automáticamente si:
- El archivo de taxonomía tiene menos de 5,000 categorías (incompleto)
- No se ha verificado la actualización en más de 90 días

## Recursos

- [Taxonomía Oficial de Google](https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt)
- [Documentación Google Merchant Center](https://support.google.com/merchants/answer/6324436)
- [Guía de Categorías de Productos](https://www.webtoffee.com/blog/google-product-taxonomy/)

## Notas

- La taxonomía de Google se actualiza ocasionalmente (última actualización: 2021-09-21)
- No es necesario actualizarla constantemente; las categorías son bastante estables
- El archivo JSON completo pesa aproximadamente 400KB
