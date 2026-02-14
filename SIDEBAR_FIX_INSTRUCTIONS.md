# Instrucciones para solucionar widgets duplicados en las barras laterales

Has reportado que los elementos en la barra lateral derecha (como las imágenes de Loreto, Comondú y los comentarios) están duplicados. Esto probablemente se debe a que la configuración de los widgets en la base de datos tiene entradas duplicadas.

Para solucionar este problema, he creado dos scripts que puedes ejecutar en tu sitio.

## Pasos para solucionar el problema:

1.  **Verificar duplicados:**
    *   Asegúrate de que el archivo `check-sidebar-duplicates.php` esté en la carpeta raíz de tu instalación de WordPress.
    *   Abre tu navegador y visita: `http://tudominio.com/check-sidebar-duplicates.php` (reemplaza `tudominio.com` con tu dominio real).
    *   Este script te mostrará qué widgets están duplicados en cada barra lateral.

2.  **Corregir duplicados:**
    *   Si el script anterior confirma que hay duplicados, asegúrate de que el archivo `fix-sidebar-duplicates.php` esté en la carpeta raíz.
    *   Abre tu navegador y visita: `http://tudominio.com/fix-sidebar-duplicates.php`.
    *   Este script limpiará automáticamente las entradas duplicadas en la configuración de las barras laterales.
    *   Verás un mensaje de "SUCCESS" si se realizaron cambios.

    **Importante:** Debes haber iniciado sesión como **Administrador** en WordPress para que estos scripts funcionen. Si no estás logueado, verás un mensaje de "Access Denied".

3.  **Verificar el sitio:**
    *   Vuelve a visitar tu página principal o cualquier página donde veías el problema.
    *   Los elementos duplicados deberían haber desaparecido.

4.  **Eliminar los scripts:**
    *   Una vez solucionado el problema, te recomiendo eliminar estos dos archivos (`check-sidebar-duplicates.php` y `fix-sidebar-duplicates.php`) de tu servidor por seguridad.

## Nota Técnica

El problema ocurre cuando la opción `sidebars_widgets` en la base de datos contiene el mismo ID de widget varias veces en el mismo array de barra lateral. WordPress normalmente evita esto, pero puede ocurrir durante migraciones manuales o scripts de importación que no verifican duplicados antes de agregar widgets.
