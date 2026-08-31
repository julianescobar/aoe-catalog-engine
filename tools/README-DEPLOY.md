# Notas de deploy - aoe-catalog-engine

## Slugs de fabricantes (con guiones)
- amphenol-anytek
- amphenol-conec
- amphenol-industrial
- amphenol-ltw
- amphenol-lutze
- amphenol-rf
- bulgin
- camdenboss
- edac
- medi-kabel
- mh-connectors
- panduit
- samtec
- yokowo
- bivar
- wideband

## Logo del fabricante (Avada)
- CSS ID: `aoe-manufacturer-logo` (sin #)
- Se pone en el elemento **Image Frame** → campo CSS ID
- Funciona con la plantilla base que comparten los catálogos de Amphenol
- El TemplateCache reemplaza el src del img cuando `manufacturer_logo_mode = custom`

## Comandos de import (producción)
Producción: `/home2/hosting158857eu/public_html/tc-componentes.es/wp-content/plugins/aoe-catalog-engine`
Host: `tc-componentes.es`

**IMPORTANTE:** En CGI (producción), usar `-d register_argc_argv=1` para que `getopt()` funcione.
Las rutas de CSV deben ser absolutas porque CGI no respeta el CWD.

```bash
# Import (replace o incremental)
nohup php -d register_argc_argv=1 tools/full-import.php --manufacturer=SLUG --csv=/home2/hosting158857eu/public_html/tc-componentes.es/wp-content/plugins/aoe-catalog-engine/tools/ARCHIVO.csv --mode=replace > import-LOG.log 2>&1 &

# Structure (después del import)
php /home2/hosting158857eu/public_html/tc-componentes.es/wp-content/plugins/aoe-catalog-engine/tools/import-STRUCTURE-structure.php
```

## Búsqueda (Nina)
- Tabla: `wp_aoe_catalog_search_products`
- Script: `tools/build-search-index.php`
- Formato payload:
```json
{
  "name": "...",
  "description": "...",
  "category_path": "Cat > SubCat",
  "image_url": ["..."],
  "docs": {
    "pdfs": [{"url": "...", "name": "..."}],
    "3dcad": [{"url": "...", "name": "...", "ext": "dxf"}]
  },
  "specs": {"key": "value"},
  "urls": {
    "catalog": "http://tc-componentes.local/catalogo/slug/",
    "category": "http://tc-componentes.local/catalogo/slug/categoria/",
    "product": "http://tc-componentes.local/catalogo/slug/categoria/pagina/"
  }
}
```
- Clasificación docs: extensión del archivo (.dxf, .dwg, .stp → 3dcad), fallback por nombre
- JSON paths: `$.docs."3dcad"` (las comillas son necesarias porque empieza con número)

## CSS / Z-index
- `#aoe-catalog-container`: z-index: 20
- `.fusion-fullwidth:has(#aoe-catalog-container)`: z-index: 18 (overflow visible)
- `#aoe-feedback-widget`: z-index: 25 (encima del catálogo)
- `.aoe-cta-modal`: z-index: 99999 (encima de todo)
- El popup de características funciona con z-index: 100 dentro del catálogo

## CTA Form
- Producción: `form_post_id="11535"`
- Dev/Pre: `form_post_id="10487"`
- Condición: `strpos(home_url(), 'dev.tc-componentes.es')`
