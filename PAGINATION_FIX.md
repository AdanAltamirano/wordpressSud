# Instrucciones para eliminar el texto "Page 1 of X"

Este documento detalla tres formas de eliminar el texto de paginación (por ejemplo, "Page 1 of 873") que aparece en su sitio web.

## Opción 1: Instalar el Plugin (Recomendada)

Hemos creado un plugin simple que soluciona este problema automáticamente.

1.  **Descargar**: Localice la carpeta `wp-content/plugins/remove-pagination-text` en este repositorio.
2.  **Copiar**: Copie toda la carpeta `remove-pagination-text` a la carpeta `wp-content/plugins/` de su servidor.
3.  **Activar**:
    *   Vaya al panel de administración de WordPress.
    *   Vaya a **Plugins** > **Plugins instalados**.
    *   Busque "Remove Pagination Text" y haga clic en **Activar**.

Este plugin inyecta un pequeño código CSS que oculta el texto sin modificar los archivos originales del tema.

---

## Opción 2: Usar CSS Personalizado (Sin Plugin)

Si prefiere no instalar un plugin, puede agregar el código CSS manualmente.

1.  Vaya al **Panel de Administración de WordPress**.
2.  Navegue a **Theme Panel** > **Custom CSS** (o **Apariencia** > **Personalizar** > **CSS Adicional**).
3.  Copie y pegue el siguiente código:

```css
/* Ocultar texto de paginación 'Page X of Y' */
.pages {
    display: none !important;
}
```

4.  Haga clic en **Publicar** o **Guardar**.

---

## Opción 3: Modificar el Código del Tema (Avanzado)

Si necesita eliminar el texto desde el código fuente PHP (no recomendado, ya que se pierde al actualizar el tema):

1.  Acceda a los archivos de su servidor.
2.  Busque el archivo `wp-content/themes/Newspaper/includes/wp_booster/td_page_generator.php`.
3.  Busque la línea que contiene: `$pagenavi_options['pages_text']`.
4.  Cámbiela para que quede así:

```php
// Comentado para eliminar el texto
// $pagenavi_options['pages_text'] = __("Page %s of %s", 'td_framework');
$pagenavi_options['pages_text'] = "";
```

5.  Guarde el archivo.
