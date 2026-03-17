<?php
/**
 * Plugin Name: Sudcalifornios Fast Search (Títulos)
 * Description: Optimiza la búsqueda de WordPress forzando que solo busque coincidencias en el título de las publicaciones y no en el contenido. Esto soluciona los tiempos de espera y bloqueos causados por las enormes imágenes en base64 incrustadas en el post_content durante la migración desde Joomla.
 * Version: 1.0
 * Author: Fix
 */

// Si no se está ejecutando dentro de WordPress, salir.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Modifica la consulta de búsqueda SQL para que solo busque en post_title,
 * ignorando post_content y post_excerpt.
 *
 * @param string   $search   El fragmento SQL original de búsqueda (WHERE).
 * @param WP_Query $wp_query El objeto de consulta actual.
 * @return string  El fragmento SQL modificado.
 */
function sudcalifornios_fast_search_by_title_only( $search, $wp_query ) {
    global $wpdb;

    // Si la búsqueda está vacía o no es la consulta principal en el frontend, no hacer nada.
    if ( empty( $search ) || ! is_search() || empty( $wp_query->query_vars['s'] ) ) {
        return $search;
    }

    $q = $wp_query->query_vars;

    // WordPress ya divide los términos de búsqueda en un array en query_vars['search_terms']
    $search_terms = isset( $q['search_terms'] ) ? $q['search_terms'] : array();

    if ( empty( $search_terms ) || ! is_array( $search_terms ) ) {
        return $search;
    }

    $search = '';

    // Configurar el operador lógico que el usuario seleccionó (AND/OR, generalmente AND)
    $and_or = ( isset( $q['exact'] ) && $q['exact'] ) ? '' : ( isset( $q['sentence'] ) && $q['sentence'] ? '' : ' AND ' );

    $search_parts = array();

    foreach ( $search_terms as $term ) {
        $like = '%' . $wpdb->esc_like( $term ) . '%';
        // AQUÍ ESTÁ EL CAMBIO: Originalmente WordPress busca en post_title, post_excerpt, y post_content.
        // Ahora SOLO busca en post_title.
        $search_parts[] = $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s)", $like );
    }

    if ( ! empty( $search_parts ) ) {
        $search = ' AND (' . implode( $and_or, $search_parts ) . ') ';
    }

    return $search;
}
add_filter( 'posts_search', 'sudcalifornios_fast_search_by_title_only', 500, 2 );