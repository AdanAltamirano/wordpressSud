# Instrucciones para solucionar el error "Cannot redeclare function"

El error que estás viendo en tu sitio web es:

`Fatal error: Cannot redeclare function sudcalifornios_seccion_posts_shortcode() ...`

Esto significa que la función `sudcalifornios_seccion_posts_shortcode` está definida dos veces. Esto suele ocurrir cuando el archivo del plugin se incluye varias veces o si la función está duplicada dentro del mismo archivo `wp-content/plugins/sudcalifornios-authors-newspaper.php`.

## Cómo solucionarlo

Para solucionar este error, debes editar el archivo `wp-content/plugins/sudcalifornios-authors-newspaper.php` y envolver la declaración de la función en una comprobación `function_exists`.

### Paso a paso:

1.  Abre el archivo `wp-content/plugins/sudcalifornios-authors-newspaper.php` en un editor de texto o código.
2.  Busca la línea donde se define la función `sudcalifornios_seccion_posts_shortcode`. El error indica que puede estar alrededor de la línea 113 o 567.
3.  Envuelve la función con el siguiente código:

```php
if (!function_exists('sudcalifornios_seccion_posts_shortcode')) {
    function sudcalifornios_seccion_posts_shortcode($atts) {
        // ... (contenido original de la función) ...
    }
}
```

Esto asegurará que la función solo se declare si no existe previamente, evitando el error fatal.

**Nota:** Dado que el archivo original no estaba disponible en el repositorio, he creado estas instrucciones para que puedas aplicar la solución manualmente.

---

# Solución para Elementos Duplicados en Sidebar

El problema que observas (videos duplicados, banners duplicados, comentarios duplicados) se debe a que el archivo del plugin `wp-content/plugins/sudcalifornios-authors-newspaper.php` se está ejecutando **dos veces**.

Esto puede ocurrir si:
1.  El plugin está activo en WordPress.
2.  Y ADEMÁS, el tema (o otro plugin) incluye manualmente este archivo usando `include` o `require`.

La solución anterior (envolver funciones en `function_exists`) evitó que el sitio web se rompiera con un "Fatal Error", pero no evitó que el código que registra los widgets (`add_action`, `register_sidebar`, etc.) se ejecutara dos veces. Al ejecutarse dos veces, los widgets se añaden dos veces.

## Cómo solucionarlo definitivamente

Para evitar que el archivo se ejecute más de una vez, debes agregar un "Guard Clause" (cláusula de protección) al **principio absoluto** del archivo.

### Paso a paso:

1.  Abre el archivo `wp-content/plugins/sudcalifornios-authors-newspaper.php`.
2.  Ve a la **línea 1**. Debería empezar con `<?php`.
3.  Inmediatamente después de `<?php`, agrega el siguiente código:

```php
<?php
// EVITAR DUPLICADOS: Si este archivo ya se cargó, no hacer nada.
if (defined('SUDCALIFORNIOS_PLUGIN_LOADED')) {
    return;
}
define('SUDCALIFORNIOS_PLUGIN_LOADED', true);

// ... resto del código del plugin ...
```

### Ejemplo visual:

El inicio de tu archivo debería quedar así:

```php
<?php
if (defined('SUDCALIFORNIOS_PLUGIN_LOADED')) {
    return;
}
define('SUDCALIFORNIOS_PLUGIN_LOADED', true);

/*
Plugin Name: Sudcalifornios Authors Newspaper
...
*/

// Resto del código...
```

Con este cambio, la segunda vez que WordPress (o el tema) intente cargar el archivo, detectará que `SUDCALIFORNIOS_PLUGIN_LOADED` ya está definido y se detendrá inmediatamente, evitando duplicar los elementos.
