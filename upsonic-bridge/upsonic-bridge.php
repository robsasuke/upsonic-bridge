<?php
/**
 * Plugin Name: Upsonic Bridge
 * Description: Conector seguro de Upsonic con WordPress y PowerPress. Descubre podcasts y administra episodios, estados y papelera.
 * Version: 0.7.5
 * Author: Robert Sasuke
 */

if (!defined('ABSPATH')) exit;

define('BOOSTSN_BRIDGE_OPTION', 'boostsn_bridge_token');
define('BOOSTSN_BRIDGE_VERSION', '0.7.5');
// v0.7.5: maintenance release to verify unattended GitHub updates.
require_once __DIR__ . '/updater.php';

function boostsn_bridge_make_token() {
    $token = get_option(BOOSTSN_BRIDGE_OPTION);
    if (!$token) {
        $token = wp_generate_password(48, false, false);
        update_option(BOOSTSN_BRIDGE_OPTION, $token, false);
    }
    return $token;
}
register_activation_hook(__FILE__, 'boostsn_bridge_make_token');

add_action('admin_menu', function () {
    add_options_page('Upsonic Bridge', 'Upsonic Bridge', 'manage_options', 'boostsn-bridge', 'boostsn_bridge_settings_page');
});

function boostsn_bridge_settings_page() {
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['boostsn_regenerate']) && check_admin_referer('boostsn_regenerate_token')) {
        update_option(BOOSTSN_BRIDGE_OPTION, wp_generate_password(48, false, false), false);
        echo '<div class="notice notice-success"><p>Token regenerado.</p></div>';
    }
    if (isset($_POST['upsonic_category_save']) && check_admin_referer('upsonic_category_save')) {
        $id = isset($_POST['upsonic_podcast_id']) ? sanitize_text_field(wp_unslash($_POST['upsonic_podcast_id'])) : '';
        $term_id = isset($_POST['upsonic_term_id']) ? absint($_POST['upsonic_term_id']) : 0;
        $catalog = upsonic_bridge_catalog();
        $valid = false;
        foreach ($catalog as $item) {
            if ($item['id'] !== $id) continue;
            if (!$term_id) { $valid = true; break; }
            foreach ($item['terms'] as $term) {
                if ($term['taxonomy'] === 'category' && $term['term_id'] === $term_id) { $valid = true; break 2; }
            }
        }
        if ($valid) {
            $map = get_option('upsonic_bridge_category_map', array());
            if (!is_array($map)) $map = array();
            if ($term_id) $map[$id] = $term_id; else unset($map[$id]);
            update_option('upsonic_bridge_category_map', $map, false);
            echo '<div class="notice notice-success"><p>Asociación de categoría guardada.</p></div>';
        } else echo '<div class="notice notice-error"><p>Podcast o categoría no válida.</p></div>';
    }
    $token = esc_attr(boostsn_bridge_make_token());
    echo '<div class="wrap"><h1>Upsonic Bridge</h1>';
    echo '<p><strong>Versión '.esc_html(BOOSTSN_BRIDGE_VERSION).'</strong></p>';
    echo '<p>Copia este token en Upsonic → Configuración → Token Upsonic Bridge.</p>';
    echo '<input type="text" readonly style="width:620px;max-width:100%;font-family:monospace" value="'.$token.'">';
    echo '<p>Upsonic utiliza este conector para descubrir podcasts, consultar, actualizar, publicar y enviar episodios a la papelera. Conserva el mismo token al actualizar desde BoostSN Bridge 0.6.3.</p>';
    echo '<h2>Podcasts reconocidos por PowerPress</h2>';
    if (!upsonic_bridge_powerpress_active()) echo '<p><strong>PowerPress no está activo.</strong></p>';
    else {
        $catalog = upsonic_bridge_catalog();
        if (!$catalog) echo '<p>No se encontraron podcasts compatibles.</p>';
        else {
            echo '<table class="widefat striped"><thead><tr><th>Podcast</th><th>CPT</th><th>Feed</th><th>Origen</th><th>Categoría sugerida</th><th>Term ID</th><th>Confianza</th></tr></thead><tbody>';
            foreach ($catalog as $podcast) echo '<tr><td>' . esc_html($podcast['name']) . '</td><td><code>' . esc_html($podcast['post_type']) . '</code></td><td><code>' . esc_html($podcast['feed_slug']) . '</code></td><td>' . esc_html($podcast['source']) . '</td><td>' . esc_html(isset($podcast['category_match']['name']) ? $podcast['category_match']['name'] : 'Sin determinar') . '</td><td>' . esc_html(isset($podcast['category_match']['term_id']) ? $podcast['category_match']['term_id'] : '—') . '</td><td>' . esc_html(isset($podcast['category_match']['method']) ? $podcast['category_match']['method'] : '—') . '</td></tr>';
            echo '</tbody></table>';
            echo '<h3>Confirmar categoría de cada podcast</h3><p>Si la inferencia no es correcta, confirma una categoría. Esta asociación prevalece sobre las sugerencias y se publica en la API.</p>';
            foreach ($catalog as $item) {
                $current = isset($item['category_match']['confirmed']) && $item['category_match']['confirmed'] ? (int) $item['category_match']['term_id'] : 0;
                echo '<form method="post" style="margin:8px 0">';
                wp_nonce_field('upsonic_category_save');
                echo '<input type="hidden" name="upsonic_podcast_id" value="'.esc_attr($item['id']).'">';
                echo '<label>'.esc_html($item['name'].' ('.$item['id'].')').' <select name="upsonic_term_id">';
                echo '<option value="0">Automática / sin confirmar</option>';
                foreach ($item['terms'] as $term) {
                    if ($term['taxonomy'] !== 'category') continue;
                    echo '<option value="'.esc_attr($term['term_id']).'" '.selected($current, $term['term_id'], false).'>'.esc_html($term['name'].' (#'.$term['term_id'].')').'</option>';
                }
                echo '</select></label> <button class="button" name="upsonic_category_save" value="1">Guardar</button></form>';
            }
        }
    }
    echo '<p><strong>API:</strong> <code>' . esc_html(rest_url('upsonic/v1/podcasts')) . '</code> (token requerido)</p>';
    echo '<form method="post" style="margin-top:18px">';
    wp_nonce_field('boostsn_regenerate_token');
    echo '<button class="button" name="boostsn_regenerate" value="1">Regenerar token</button></form></div>';
}

function boostsn_bridge_permission(WP_REST_Request $request) {
    $sent = (string) $request->get_header('x-boostsn-token');
    $stored = (string) boostsn_bridge_make_token();
    return $sent !== '' && hash_equals($stored, $sent);
}

add_action('rest_api_init', function () {
    register_rest_route('boostsn/v1', '/ping', array(
        'methods' => 'POST',
        'callback' => function () {
            return rest_ensure_response(array('ok' => true, 'version' => BOOSTSN_BRIDGE_VERSION));
        },
        'permission_callback' => 'boostsn_bridge_permission',
    ));

    register_rest_route('boostsn/v1', '/powerpress', array(
        'methods' => 'POST',
        'callback' => 'boostsn_bridge_powerpress',
        'permission_callback' => 'boostsn_bridge_permission',
    ));

    register_rest_route('boostsn/v1', '/draft', array(
        'methods' => 'POST',
        'callback' => 'boostsn_bridge_create_draft',
        'permission_callback' => 'boostsn_bridge_permission',
    ));

    // Endpoints nuevos: se mantiene boostsn/v1 para clientes anteriores.
    // Los clientes nuevos pueden utilizar el namespace Upsonic sin perder
    // compatibilidad con los anteriores.
    register_rest_route('upsonic/v1', '/ping', array('methods' => 'POST', 'callback' => function () {
        return rest_ensure_response(array('ok' => true, 'version' => BOOSTSN_BRIDGE_VERSION, 'plugin' => 'Upsonic Bridge'));
    }, 'permission_callback' => 'boostsn_bridge_permission'));
    register_rest_route('upsonic/v1', '/draft', array('methods' => 'POST', 'callback' => 'upsonic_bridge_create_episode', 'permission_callback' => 'boostsn_bridge_permission'));
    register_rest_route('upsonic/v1', '/powerpress', array('methods' => 'POST', 'callback' => 'boostsn_bridge_powerpress', 'permission_callback' => 'boostsn_bridge_permission'));
    foreach (array('boostsn/v1', 'upsonic/v1') as $ns) {
        register_rest_route($ns, '/podcasts', array('methods' => 'GET', 'callback' => 'upsonic_bridge_podcasts', 'permission_callback' => 'boostsn_bridge_permission'));
        register_rest_route($ns, '/episodes', array('methods' => 'GET', 'callback' => 'upsonic_bridge_episodes', 'permission_callback' => 'boostsn_bridge_permission'));
        register_rest_route($ns, '/episodes/(?P<post_id>\d+)', array('methods' => 'GET', 'callback' => 'upsonic_bridge_get_episode', 'permission_callback' => 'boostsn_bridge_permission'));
        register_rest_route($ns, '/episodes/(?P<post_id>\d+)', array('methods' => 'PATCH, PUT', 'callback' => 'upsonic_bridge_update_episode', 'permission_callback' => 'boostsn_bridge_permission'));
        register_rest_route($ns, '/episodes', array('methods' => 'POST', 'callback' => 'upsonic_bridge_create_episode', 'permission_callback' => 'boostsn_bridge_permission'));
        register_rest_route($ns, '/episodes/(?P<post_id>\d+)', array('methods' => 'DELETE', 'callback' => 'upsonic_bridge_delete_episode', 'permission_callback' => 'boostsn_bridge_permission'));
        register_rest_route($ns, '/episodes/(?P<post_id>\d+)/restore', array('methods' => 'POST', 'callback' => 'upsonic_bridge_restore_episode', 'permission_callback' => 'boostsn_bridge_permission'));
    }

});

function boostsn_bridge_media_info($media_url) {
    $length = 0;
    $mime = 'audio/mpeg';
    $head = wp_remote_head($media_url, array('timeout' => 15, 'redirection' => 5));
    if (!is_wp_error($head)) {
        $len = wp_remote_retrieve_header($head, 'content-length');
        $ctype = wp_remote_retrieve_header($head, 'content-type');
        if ($len !== '') $length = absint($len);
        if ($ctype !== '') $mime = sanitize_text_field(explode(';', $ctype)[0]);
    }
    return array($length, $mime);
}

function boostsn_bridge_plain_show_notes($html) {
    $text = (string) $html;
    // Conserva saltos de línea legibles en el campo Show Notes de PowerPress.
    $text = preg_replace('#<br\s*/?>#i', "\n", $text);
    $text = preg_replace('#</(p|div|h[1-6]|blockquote)>#i', "\n\n", $text);
    $text = preg_replace('#<li[^>]*>#i', "• ", $text);
    $text = preg_replace('#</li>#i', "\n", $text);
    $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
    $text = preg_replace("/\r\n?|\n{3,}/", "\n\n", $text);
    return trim($text);
}

function boostsn_bridge_set_powerpress($post_id, $media_url, $feed_slug, $episode_title = '', $episode_image = '', $show_notes = '', $episode_number = '') {
    if (!$post_id || !get_post($post_id)) {
        return new WP_Error('boostsn_invalid_post', 'Post no válido.', array('status' => 400));
    }
    if (!$media_url || !preg_match('#^https?://#i', $media_url)) {
        return new WP_Error('boostsn_invalid_media', 'URL de audio no válida.', array('status' => 400));
    }
    if (!$feed_slug) $feed_slug = 'podcast';
    $meta_key = ($feed_slug === 'podcast') ? 'enclosure' : '_' . $feed_slug . ':enclosure';
    list($length, $mime) = boostsn_bridge_media_info($media_url);

    // PowerPress lee la cuarta línea del enclosure como un array PHP serializado.
    // Se guardan las claves tradicionales y las variantes utilizadas por
    // versiones recientes para mantener compatibilidad con PowerPress 11.x.
    $extra = array();
    if ($episode_title !== '') {
        // PowerPress históricamente usa feed_title para el campo visible
        // “Título del episodio”. Conservamos también alias para versiones
        // nuevas/anteriores y para consumidores externos del enclosure.
        $extra['feed_title'] = $episode_title;
        $extra['title'] = $episode_title;
        $extra['itunes_title'] = $episode_title;
        $extra['episode_title'] = $episode_title;
    }
    $plain_show_notes = boostsn_bridge_plain_show_notes($show_notes);
    if ($plain_show_notes !== '') {
        $extra['summary'] = $plain_show_notes;
        $extra['shownotes'] = $plain_show_notes;
        $extra['show_notes'] = $plain_show_notes;
    }
    if ($episode_image !== '') {
        // PowerPress usa 'image' para la ilustración específica del episodio.
        // Conservamos también alias para compatibilidad entre versiones.
        $extra['image'] = $episode_image;
        $extra['itunes_image'] = $episode_image;
        $extra['episode_image'] = $episode_image;
    }
    $episode_number = trim((string) $episode_number);
    if ($episode_number !== '' && ctype_digit($episode_number) && intval($episode_number) > 0) {
        // PowerPress usa "episode" para el campo Episodio # / <itunes:episode>.
        // Los alias se conservan para compatibilidad entre versiones.
        $extra['episode'] = intval($episode_number);
        $extra['itunes_episode'] = intval($episode_number);
        $extra['episode_number'] = intval($episode_number);
        $extra['episode_no'] = intval($episode_number);
    }

    $enclosure = $media_url . "\n" . $length . "\n" . $mime . "\n" . serialize($extra);
    update_post_meta($post_id, $meta_key, $enclosure);
    clean_post_cache($post_id);

    // Verificación interna: devuelve exactamente lo que se escribió para que
    // BoostSN pueda diagnosticar instalaciones de PowerPress distintas.
    $saved = get_post_meta($post_id, $meta_key, true);
    $saved_extra = array();
    if (is_string($saved)) {
        $parts = explode("\n", $saved, 4);
        if (isset($parts[3]) && $parts[3] !== '') {
            $maybe = @unserialize($parts[3]);
            if (is_array($maybe)) $saved_extra = $maybe;
        }
    }

    // Verifica también usando el propio lector de PowerPress cuando está disponible.
    // Así detectamos inmediatamente si el canal/meta_key no corresponde al módulo visible.
    $pp_readback = array();
    if (function_exists('powerpress_get_enclosure_data')) {
        $read = powerpress_get_enclosure_data($post_id, $feed_slug);
        if (is_array($read)) $pp_readback = $read;
    }

    return array(
        'post_id' => $post_id,
        'feed_slug' => $feed_slug,
        'meta_key' => $meta_key,
        'media_url' => $media_url,
        'length' => $length,
        'mime' => $mime,
        'episode_title' => $episode_title,
        'show_notes' => $plain_show_notes,
        'episode_image' => $episode_image,
        'episode_number' => $episode_number,
        'saved_extra' => $saved_extra,
        'powerpress_readback' => $pp_readback,
    );
}

function boostsn_bridge_powerpress(WP_REST_Request $request) {
    $post_id = absint($request->get_param('post_id'));
    $media_url = esc_url_raw((string) $request->get_param('media_url'));
    $feed_slug = sanitize_key((string) $request->get_param('feed_slug'));
    $episode_title = sanitize_text_field((string) $request->get_param('episode_title'));
    $episode_image = esc_url_raw((string) $request->get_param('episode_image'));
    $show_notes = wp_kses_post((string) $request->get_param('show_notes'));
    $episode_number = sanitize_text_field((string) $request->get_param('episode_number'));
    $result = boostsn_bridge_set_powerpress($post_id, $media_url, $feed_slug, $episode_title, $episode_image, $show_notes, $episode_number);
    if (is_wp_error($result)) return $result;
    return rest_ensure_response(array_merge(array('ok' => true), $result));
}

function boostsn_bridge_create_attachment($base64, $mime, $filename, $post_id) {
    if ($base64 === '') return array(0, '');

    $bytes = base64_decode($base64, true);
    if ($bytes === false || strlen($bytes) === 0) {
        return new WP_Error('boostsn_bad_image', 'No se pudo decodificar la portada.', array('status' => 400));
    }
    if (strlen($bytes) > 12 * 1024 * 1024) {
        return new WP_Error('boostsn_image_too_large', 'La portada excede 12 MB.', array('status' => 400));
    }

    // No confiamos en el Content-Type recibido de Auphonic. Algunos endpoints
    // devuelven application/octet-stream aunque el contenido sea un JPEG/PNG.
    // Detectamos el formato real a partir de los bytes para evitar que
    // wp_upload_bits() rechace una extensión no permitida al no existir un
    // usuario de WordPress autenticado en esta petición por token.
    $info = @getimagesizefromstring($bytes);
    if ($info === false || empty($info[2])) {
        return new WP_Error('boostsn_invalid_image', 'La portada recibida no es una imagen válida.', array('status' => 400));
    }

    $type = (int) $info[2];
    if ($type === IMAGETYPE_JPEG) {
        $mime = 'image/jpeg';
        $ext = 'jpg';
    } elseif ($type === IMAGETYPE_PNG) {
        $mime = 'image/png';
        $ext = 'png';
    } elseif (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP) {
        $mime = 'image/webp';
        $ext = 'webp';
    } else {
        return new WP_Error(
            'boostsn_unsupported_image',
            'La portada debe ser JPG, PNG o WebP.',
            array('status' => 400)
        );
    }

    // Fuerza una extensión coherente con los bytes reales. Esto evita casos
    // como .bin/.octet-stream que WordPress bloquea por seguridad.
    $stem = sanitize_file_name(pathinfo((string) $filename, PATHINFO_FILENAME));
    if ($stem === '') $stem = 'boostsn-cover-' . time();
    $filename = $stem . '.' . $ext;

    // Escribimos el archivo validado directamente en uploads. El endpoint ya
    // está protegido por X-BoostSN-Token, y así no dependemos de la capacidad
    // unfiltered_upload del usuario actual (que no existe en auth por token).
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) {
        return new WP_Error('boostsn_upload_dir_error', $uploads['error'], array('status' => 500));
    }
    if (!wp_mkdir_p($uploads['path'])) {
        return new WP_Error('boostsn_upload_dir_create_error', 'No se pudo crear la carpeta de subidas.', array('status' => 500));
    }

    $filename = wp_unique_filename($uploads['path'], $filename);
    $filepath = trailingslashit($uploads['path']) . $filename;
    $written = @file_put_contents($filepath, $bytes, LOCK_EX);
    if ($written === false || $written !== strlen($bytes)) {
        @unlink($filepath);
        return new WP_Error('boostsn_upload_write_error', 'No se pudo guardar la portada en WordPress.', array('status' => 500));
    }

    $stat = @stat(dirname($filepath));
    if ($stat) @chmod($filepath, $stat['mode'] & 0000666);

    $image_url = trailingslashit($uploads['url']) . rawurlencode($filename);
    $attachment = array(
        'post_mime_type' => $mime,
        'post_title' => sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME)),
        'post_content' => '',
        'post_status' => 'inherit',
        'guid' => $image_url,
    );

    $attachment_id = wp_insert_attachment($attachment, $filepath, $post_id, true);
    if (is_wp_error($attachment_id)) {
        @unlink($filepath);
        return $attachment_id;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata($attachment_id, $filepath);
    if (!is_wp_error($metadata) && !empty($metadata)) {
        wp_update_attachment_metadata($attachment_id, $metadata);
    }

    return array($attachment_id, $image_url);
}
function boostsn_bridge_assign_term($post_id, $post_type, $term_id) {
    $term_id = absint($term_id);
    if ($term_id <= 0) return array('term_id' => 0, 'taxonomy' => '');

    $taxonomies = get_object_taxonomies($post_type, 'objects');
    if (empty($taxonomies)) {
        return new WP_Error('boostsn_no_taxonomies', 'El CPT seleccionado no tiene taxonomías asociadas.', array('status' => 400));
    }

    // Prioriza la categoría estándar, luego cualquier taxonomía pública asociada al CPT
    // que contenga exactamente el término indicado.
    $ordered = array();
    if (isset($taxonomies['category'])) $ordered['category'] = $taxonomies['category'];
    foreach ($taxonomies as $slug => $obj) {
        if ($slug !== 'category') $ordered[$slug] = $obj;
    }

    foreach ($ordered as $taxonomy => $obj) {
        $exists = term_exists($term_id, $taxonomy);
        if (!$exists) continue;
        $result = wp_set_object_terms($post_id, array($term_id), $taxonomy, false);
        if (is_wp_error($result)) return $result;
        return array('term_id' => $term_id, 'taxonomy' => $taxonomy);
    }

    return new WP_Error(
        'boostsn_term_not_found',
        'El término ID ' . $term_id . ' no existe en ninguna taxonomía asociada al CPT ' . $post_type . '.',
        array('status' => 400)
    );
}

function boostsn_bridge_create_draft(WP_REST_Request $request) {
    $post_title = sanitize_text_field((string) $request->get_param('post_title'));
    $episode_title = sanitize_text_field((string) $request->get_param('episode_title'));
    $episode_number = sanitize_text_field((string) $request->get_param('episode_number'));
    $excerpt = wp_kses_post((string) $request->get_param('excerpt'));
    $content = wp_kses_post((string) $request->get_param('content'));
    $term_id = absint($request->get_param('term_id'));
    // Compatibilidad con BoostSN <= 0.4
    if (!$term_id) $term_id = absint($request->get_param('category_id'));
    $post_type = sanitize_key((string) $request->get_param('post_type'));
    if ($post_type === '') $post_type = 'post';
    $feed_slug = sanitize_key(ltrim((string) $request->get_param('feed_slug'), '/'));
    $media_url = esc_url_raw((string) $request->get_param('media_url'));
    $tags = $request->get_param('tags');
    $image_base64 = (string) $request->get_param('image_base64');
    $image_mime = sanitize_text_field((string) $request->get_param('image_mime'));
    $image_filename = sanitize_file_name((string) $request->get_param('image_filename'));
    $post_status = sanitize_key((string) $request->get_param('post_status'));
    if ($post_status !== 'publish') $post_status = 'draft';

    if ($post_title === '') return new WP_Error('boostsn_missing_title', 'Falta el título del post.', array('status' => 400));
    if ($episode_title === '') $episode_title = $post_title;
    if ($feed_slug === '') $feed_slug = 'podcast';
    if (!$media_url) return new WP_Error('boostsn_missing_media', 'Falta la URL del MP3.', array('status' => 400));
    if (!post_type_exists($post_type)) {
        return new WP_Error('boostsn_invalid_post_type', 'El CPT ' . $post_type . ' no existe en WordPress.', array('status' => 400));
    }

    $postarr = array(
        'post_type' => $post_type,
        'post_status' => $post_status,
        'post_title' => $post_title,
        'post_excerpt' => $excerpt,
        'post_content' => $content,
    );

    $post_id = wp_insert_post(wp_slash($postarr), true);
    if (is_wp_error($post_id)) return $post_id;

    $taxonomy = sanitize_key((string) $request->get_param('taxonomy'));
    $term_assignment = ($term_id && $taxonomy)
        ? upsonic_bridge_assign_exact_term($post_id, $post_type, $taxonomy, $term_id)
        : boostsn_bridge_assign_term($post_id, $post_type, $term_id);
    if (is_wp_error($term_assignment)) {
        wp_delete_post($post_id, true);
        return $term_assignment;
    }

    if (is_array($tags) && !empty($tags) && is_object_in_taxonomy($post_type, 'post_tag')) {
        $clean = array();
        foreach ($tags as $tag) {
            $tag = sanitize_text_field((string) $tag);
            if ($tag !== '') $clean[] = $tag;
        }
        if (!empty($clean)) wp_set_post_tags($post_id, $clean, false);
    }

    $attachment_id = 0;
    $image_url = '';
    if ($image_base64 !== '') {
        $img = boostsn_bridge_create_attachment($image_base64, $image_mime, $image_filename, $post_id);
        if (is_wp_error($img)) {
            wp_delete_post($post_id, true);
            return $img;
        }
        list($attachment_id, $image_url) = $img;
        if ($attachment_id) set_post_thumbnail($post_id, $attachment_id);
    }

    $pp = boostsn_bridge_set_powerpress($post_id, $media_url, $feed_slug, $episode_title, $image_url, $excerpt, $episode_number);
    if (is_wp_error($pp)) {
        wp_delete_post($post_id, true);
        return $pp;
    }

    return rest_ensure_response(array(
        'ok' => true,
        'version' => BOOSTSN_BRIDGE_VERSION,
        'post_id' => $post_id,
        'post_type' => $post_type,
        'post_status' => $post_status,
        'term' => $term_assignment,
        'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
        'featured_media_id' => $attachment_id,
        'episode_image' => $image_url,
        'powerpress' => $pp,
    ));
}

/** Upsonic 0.7.0 — descubrimiento de configuraciones PowerPress existentes. */
function upsonic_bridge_powerpress_active() {
    return defined('POWERPRESS_VERSION') || function_exists('powerpress_get_enclosure_data');
}

function upsonic_bridge_catalog() {
    if (!upsonic_bridge_powerpress_active()) return array();
    $general = get_option('powerpress_general', array());
    $general = is_array($general) ? $general : array();
    $catalog = array();
    // Feed predeterminado del tipo post cuando PowerPress está activo.
    if (post_type_exists('post')) {
        $catalog[] = upsonic_bridge_catalog_item('post', 'podcast', 'Podcast principal', 'default');
    }
    // Canales PowerPress, configurados explícitamente para entradas estándar.
    $feeds = isset($general['custom_feeds']) && is_array($general['custom_feeds']) ? $general['custom_feeds'] : array();
    foreach ($feeds as $slug => $label) {
        $slug = sanitize_key($slug);
        if ($slug === '' || $slug === 'podcast') continue;
        $catalog[] = upsonic_bridge_catalog_item('post', $slug, is_string($label) ? $label : $slug, 'channel');
    }
    // Configuraciones heredadas de Post Type Podcasting. Solo incluir un CPT
    // cuando PowerPress tenga almacenada una asociación explícita con sus feeds.
    foreach (get_post_types(array('public' => true), 'objects') as $type => $obj) {
        if ($type === 'post' || $type === 'attachment') continue;
        $settings = get_option('powerpress_posttype_' . $type, false);
        if (!is_array($settings) || !$settings) continue;
        foreach ($settings as $slug => $feed) {
            if (!is_string($slug) || !sanitize_key($slug)) continue;
            if (is_array($feed) && isset($feed['enabled']) && !$feed['enabled']) continue;
            $label = is_string($feed) ? $feed : (is_array($feed) && !empty($feed['title']) ? $feed['title'] : $slug);
            $catalog[] = upsonic_bridge_catalog_item($type, sanitize_key($slug), $label, 'legacy_post_type');
        }
    }
    $unique = array();
    foreach ($catalog as $podcast) $unique[$podcast['post_type'] . ':' . $podcast['feed_slug']] = $podcast;
    return array_values($unique);
}

/** Taxonomías públicas del CPT, con términos reales (incluidos los vacíos).
 *  La respuesta es de solo lectura y permite a Upsonic escoger taxonomy + term_id.
 */
function upsonic_bridge_terms_for_type($type) {
    $result = array();
    foreach (get_object_taxonomies($type, 'objects') as $taxonomy => $tax_obj) {
        if (empty($tax_obj->public) || $taxonomy === 'post_format') continue;
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 500));
        if (is_wp_error($terms)) continue;
        foreach ($terms as $term) {
            $result[] = array('term_id' => (int) $term->term_id, 'name' => $term->name,
                'slug' => $term->slug, 'taxonomy' => $taxonomy,
                'parent' => (int) $term->parent, 'count' => (int) $term->count);
        }
    }
    return $result;
}

/** Determina la categoría usando evidencia de episodios del feed exacto.
 *  Nunca presenta como certeza una inferencia basada en frecuencia.
 *  Los feeds sin episodios no permiten inferir una categoría por sí solos.
 */
function upsonic_bridge_match_category($type, $feed, $terms) {
    $map = get_option('upsonic_bridge_category_map', array());
    $id = $type . ':' . $feed;
    if (is_array($map) && !empty($map[$id])) {
        foreach ($terms as $term) {
            if ($term['taxonomy'] === 'category' && $term['term_id'] === (int) $map[$id]) {
                return array('term_id' => $term['term_id'], 'name' => $term['name'],
                    'slug' => $term['slug'], 'taxonomy' => 'category',
                    'method' => 'administrator_confirmed', 'confirmed' => true);
            }
        }
    }
    $category_terms = array_values(array_filter($terms, function ($t) {
        return $t['taxonomy'] === 'category';
    }));
    if (!$category_terms) return null;
    $key = $feed === 'podcast' ? 'enclosure' : '_' . $feed . ':enclosure';
    $posts = get_posts(array('post_type' => $type, 'post_status' => array('publish','private','draft','pending','future','trash'),
        'posts_per_page' => 100, 'fields' => 'ids', 'suppress_filters' => false,
        'meta_query' => array(array('key' => $key, 'compare' => 'EXISTS'))));
    if (!$posts) return null;
    $counts = array();
    foreach ($posts as $post_id) {
        $ids = wp_get_object_terms($post_id, 'category', array('fields' => 'ids'));
        if (is_wp_error($ids)) continue;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $counts[$id] = isset($counts[$id]) ? $counts[$id] + 1 : 1;
        }
    }
    if (!$counts) return null;
    arsort($counts, SORT_NUMERIC);
    $winner = (int) key($counts);
    $frequency = (int) current($counts);
    foreach ($category_terms as $term) {
        if ($term['term_id'] !== $winner) continue;
        return array('term_id' => $winner, 'name' => $term['name'], 'slug' => $term['slug'],
            'taxonomy' => 'category', 'method' => 'inferred_from_episodes',
            'confirmed' => false, 'episodes_matching' => $frequency,
            'episodes_sampled' => count($posts),
            'confidence_ratio' => round($frequency / count($posts), 3));
    }
    return null;
}

function upsonic_bridge_catalog_item($type, $slug, $label, $source) {
    $obj = get_post_type_object($type);
    $taxonomies = array();
    foreach (get_object_taxonomies($type, 'objects') as $taxonomy => $tax_obj) {
        if (!empty($tax_obj->public)) $taxonomies[] = $taxonomy;
    }
    $terms = upsonic_bridge_terms_for_type($type);
    $match = upsonic_bridge_match_category($type, $slug, $terms);
    $feed_url = ($type === 'post') ? get_feed_link($slug === 'podcast' ? 'podcast' : $slug) : get_post_type_archive_feed_link($type, $slug);
    return array('id' => $type . ':' . $slug,
        'name' => sanitize_text_field((string) $label),
        'post_type' => $type, 'post_type_label' => $obj ? $obj->labels->name : $type,
        'feed_slug' => $slug, 'feed_url' => $feed_url ?: '',
        'taxonomies' => $taxonomies, 'terms' => $terms, 'category_match' => $match, 'source' => $source,
        'can_create' => true, 'can_update' => true, 'can_trash' => true, 'can_restore' => true);
}

function upsonic_bridge_target($type, $feed) {
    $type = sanitize_key($type ?: 'post');
    $feed = sanitize_key($feed ?: 'podcast');
    foreach (upsonic_bridge_catalog() as $item) {
        if ($item['post_type'] === $type && $item['feed_slug'] === $feed) return $item;
    }
    return new WP_Error('upsonic_unknown_podcast', 'El CPT/feed no está asociado a una configuración reconocida de PowerPress.', array('status' => 400));
}

function upsonic_bridge_podcasts() {
    if (!upsonic_bridge_powerpress_active()) return new WP_Error('upsonic_no_powerpress', 'PowerPress no está activo.', array('status' => 409));
    return rest_ensure_response(array('ok' => true, 'version' => BOOSTSN_BRIDGE_VERSION, 'podcasts' => upsonic_bridge_catalog()));
}

/** Todos los estados gestionables. "trash" significa papelera recuperable, no borrado físico. */
function upsonic_bridge_statuses() {
    return array('publish', 'private', 'draft', 'pending', 'future', 'trash');
}

function upsonic_bridge_validate_episode($post_id, $feed) {
    $post = get_post(absint($post_id));
    if (!$post || in_array($post->post_status, array('auto-draft', 'inherit'), true)) {
        return new WP_Error('upsonic_missing_episode', 'Entrada inexistente o no gestionable.', array('status' => 404));
    }
    $feed = sanitize_key($feed ?: 'podcast');
    $target = upsonic_bridge_target($post->post_type, $feed);
    if (is_wp_error($target)) return $target;
    $key = $feed === 'podcast' ? 'enclosure' : '_' . $feed . ':enclosure';
    if (!metadata_exists('post', $post->ID, $key)) {
        return new WP_Error('upsonic_wrong_feed', 'La entrada no pertenece a un feed PowerPress autorizado.', array('status' => 403));
    }
    return $post;
}

function upsonic_bridge_episodes(WP_REST_Request $request) {
    $type = sanitize_key((string) $request->get_param('post_type'));
    $feed = sanitize_key((string) $request->get_param('feed_slug'));
    $target = upsonic_bridge_target($type, $feed);
    if (is_wp_error($target)) return $target;
    $limit = min(100, max(1, absint($request->get_param('per_page') ?: 20)));
    $page = max(1, absint($request->get_param('page') ?: 1));
    $status = sanitize_key((string) $request->get_param('status'));
    if ($status !== '' && $status !== 'all' && !in_array($status, upsonic_bridge_statuses(), true)) {
        return new WP_Error('upsonic_bad_status', 'Filtro de estado no permitido.', array('status' => 400));
    }
    $statuses = ($status === '' || $status === 'all') ? upsonic_bridge_statuses() : array($status);
    $key = $target['feed_slug'] === 'podcast' ? 'enclosure' : '_' . $target['feed_slug'] . ':enclosure';
    $query_args = array('post_type' => $target['post_type'], 'post_status' => $statuses,
        'posts_per_page' => $limit, 'paged' => $page, 'orderby' => 'date', 'order' => 'DESC',
        'meta_query' => array(array('key' => $key, 'compare' => 'EXISTS')));
    $search = sanitize_text_field((string) $request->get_param('search'));
    if ($search !== '') $query_args['s'] = $search;
    $query = new WP_Query($query_args);
    $episodes = array();
    foreach ($query->posts as $post) $episodes[] = upsonic_bridge_episode_data($post, $target['feed_slug']);
    return rest_ensure_response(array('ok' => true, 'episodes' => $episodes,
        'total' => intval($query->found_posts), 'pages' => intval($query->max_num_pages),
        'status' => $status === '' ? 'all' : $status,
        'statuses' => upsonic_bridge_statuses()));
}

function upsonic_bridge_episode_terms($post) {
    $result = array();
    foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy => $tax_obj) {
        if (empty($tax_obj->public) || $taxonomy === 'post_format') continue;
        $terms = wp_get_object_terms($post->ID, $taxonomy);
        if (is_wp_error($terms)) continue;
        foreach ($terms as $term) $result[] = array('term_id' => (int) $term->term_id,
            'name' => $term->name, 'slug' => $term->slug, 'taxonomy' => $taxonomy);
    }
    return $result;
}

/** Acepta taxonomy + term_id para evitar colisiones entre taxonomías. */
function upsonic_bridge_assign_exact_term($post_id, $post_type, $taxonomy, $term_id) {
    $taxonomy = sanitize_key($taxonomy);
    $term_id = absint($term_id);
    if (!$taxonomy || !$term_id || !is_object_in_taxonomy($post_type, $taxonomy) || !term_exists($term_id, $taxonomy)) {
        return new WP_Error('upsonic_invalid_term', 'Taxonomía o Term ID no asociado al CPT.', array('status' => 400));
    }
    $result = wp_set_object_terms($post_id, array($term_id), $taxonomy, false);
    if (is_wp_error($result)) return $result;
    return array('term_id' => $term_id, 'taxonomy' => $taxonomy);
}

function upsonic_bridge_episode_data($post, $feed) {
    $key = $feed === 'podcast' ? 'enclosure' : '_' . $feed . ':enclosure';
    $raw = (string) get_post_meta($post->ID, $key, true);
    $parts = explode("\n", $raw, 4);
    $extra = isset($parts[3]) ? maybe_unserialize($parts[3]) : array();
    if (!is_array($extra)) $extra = array();
    return array('post_id' => intval($post->ID), 'post_type' => $post->post_type, 'feed_slug' => $feed,
        'post_title' => get_the_title($post), 'content' => $post->post_content,
        'excerpt' => $post->post_excerpt, 'post_status' => $post->post_status,
        'media_url' => isset($parts[0]) ? $parts[0] : '',
        'episode_title' => isset($extra['feed_title']) ? $extra['feed_title'] : '',
        'episode_number' => isset($extra['episode']) ? $extra['episode'] : '',
        'episode_image' => isset($extra['image']) ? $extra['image'] : '',
        'featured_media_id' => get_post_thumbnail_id($post->ID),
        'terms' => upsonic_bridge_episode_terms($post),
        'tags' => is_object_in_taxonomy($post->post_type, 'post_tag') ? wp_get_post_tags($post->ID, array('fields' => 'names')) : array(),
        'permalink' => get_permalink($post->ID),
        'can_restore' => $post->post_status === 'trash',
        'edit_url' => admin_url('post.php?post=' . $post->ID . '&action=edit'));
}

function upsonic_bridge_get_episode(WP_REST_Request $request) {
    $feed = sanitize_key((string) $request->get_param('feed_slug'));
    $post = upsonic_bridge_validate_episode($request['post_id'], $feed);
    if (is_wp_error($post)) return $post;
    return rest_ensure_response(array('ok' => true, 'episode' => upsonic_bridge_episode_data($post, $feed ?: 'podcast')));
}

/** Borrado reversible; eliminación irreversible solo desde la papelera y con confirmación. */
function upsonic_bridge_delete_episode(WP_REST_Request $request) {
    $feed = sanitize_key((string) $request->get_param('feed_slug'));
    $post = upsonic_bridge_validate_episode($request['post_id'], $feed);
    if (is_wp_error($post)) return $post;
    $force = filter_var($request->get_param('force'), FILTER_VALIDATE_BOOLEAN);
    if ($force) {
        if ($post->post_status !== 'trash') return new WP_Error('upsonic_not_in_trash', 'Primero envía la entrada a la papelera.', array('status' => 409));
        if ($request->get_header('x-upsonic-confirm-delete') !== 'DELETE_PERMANENTLY') {
            return new WP_Error('upsonic_confirmation_required', 'Confirma el borrado irreversible mediante X-Upsonic-Confirm-Delete.', array('status' => 400));
        }
        $deleted = wp_delete_post($post->ID, true);
        if (!$deleted) return new WP_Error('upsonic_delete_failed', 'No se pudo eliminar definitivamente.', array('status' => 500));
        return rest_ensure_response(array('ok' => true, 'post_id' => intval($post->ID), 'deleted_permanently' => true));
    }
    if ($post->post_status === 'trash') return rest_ensure_response(array('ok' => true, 'episode' => upsonic_bridge_episode_data($post, $feed ?: 'podcast'), 'already_trashed' => true));
    $trashed = wp_trash_post($post->ID);
    if (!$trashed) return new WP_Error('upsonic_trash_failed', 'No se pudo enviar a la papelera. Comprueba que WordPress tenga la papelera habilitada.', array('status' => 500));
    return rest_ensure_response(array('ok' => true, 'episode' => upsonic_bridge_episode_data(get_post($post->ID), $feed ?: 'podcast')));
}

function upsonic_bridge_restore_episode(WP_REST_Request $request) {
    $feed = sanitize_key((string) $request->get_param('feed_slug'));
    $post = upsonic_bridge_validate_episode($request['post_id'], $feed);
    if (is_wp_error($post)) return $post;
    if ($post->post_status !== 'trash') return new WP_Error('upsonic_not_in_trash', 'La entrada no está en la papelera.', array('status' => 409));
    $status = sanitize_key((string) $request->get_param('post_status'));
    if ($status !== '' && !in_array($status, array('draft', 'publish', 'private', 'pending'), true)) {
        return new WP_Error('upsonic_bad_status', 'Estado de recuperación no permitido.', array('status' => 400));
    }
    $restored = wp_untrash_post($post->ID);
    if (!$restored) return new WP_Error('upsonic_restore_failed', 'No se pudo recuperar la entrada.', array('status' => 500));
    if ($status !== '') {
        $result = wp_update_post(array('ID' => $post->ID, 'post_status' => $status), true);
        if (is_wp_error($result)) return $result;
    }
    return rest_ensure_response(array('ok' => true, 'episode' => upsonic_bridge_episode_data(get_post($post->ID), $feed ?: 'podcast')));
}

function upsonic_bridge_update_episode(WP_REST_Request $request) {
    $post = get_post(absint($request['post_id']));
    $feed = sanitize_key((string) $request->get_param('feed_slug'));
    $post = upsonic_bridge_validate_episode($request['post_id'], $feed);
    if (is_wp_error($post)) return $post;
    $key = ($feed === '' || $feed === 'podcast') ? 'enclosure' : '_' . $feed . ':enclosure';
    $data = $request->get_json_params();
    if (!is_array($data)) $data = $request->get_params();
    if (isset($data['post_type']) && sanitize_key($data['post_type']) !== $post->post_type) return new WP_Error('upsonic_type_immutable', 'No se permite cambiar el CPT de una entrada.', array('status' => 400));
    // La papelera se gestiona mediante DELETE /restore, nunca con un PATCH silencioso.
    if ($post->post_status === 'trash') {
        return new WP_Error('upsonic_restore_first', 'Recupera la entrada antes de editarla.', array('status' => 409));
    }
    $patch = array('ID' => $post->ID);
    foreach (array('post_title', 'post_content', 'post_excerpt', 'post_status') as $field) {
        if (!array_key_exists($field, $data)) continue;
        $value = (string) $data[$field];
        if ($field === 'post_status') {
            if (!in_array($value, array('draft', 'publish', 'private', 'pending'), true)) return new WP_Error('upsonic_bad_status', 'Estado no permitido.', array('status' => 400));
        } elseif ($field === 'post_title') $value = sanitize_text_field($value);
        else $value = wp_kses_post($value);
        $patch[$field] = $value;
    }
    if (count($patch) > 1) {
        $saved = wp_update_post(wp_slash($patch), true);
        if (is_wp_error($saved)) return $saved;
    }
    if (isset($data['term_id'])) {
        $terms = !empty($data['taxonomy'])
            ? upsonic_bridge_assign_exact_term($post->ID, $post->post_type, $data['taxonomy'], $data['term_id'])
            : boostsn_bridge_assign_term($post->ID, $post->post_type, absint($data['term_id']));
        if (is_wp_error($terms)) return $terms;
    }
    if (isset($data['tags']) && is_array($data['tags']) && is_object_in_taxonomy($post->post_type, 'post_tag')) {
        wp_set_post_tags($post->ID, array_map('sanitize_text_field', $data['tags']), false);
    }
    $previous = upsonic_bridge_episode_data($post, $feed ?: 'podcast');
    $image_url = $previous['episode_image'];
    if (!empty($data['image_base64'])) {
        $img = boostsn_bridge_create_attachment((string) $data['image_base64'], (string) ($data['image_mime'] ?? ''), (string) ($data['image_filename'] ?? 'upsonic-cover.jpg'), $post->ID);
        if (is_wp_error($img)) return $img;
        set_post_thumbnail($post->ID, $img[0]);
        $image_url = $img[1];
    } elseif (!empty($data['remove_image'])) {
        delete_post_thumbnail($post->ID);
        $image_url = '';
    }
    $pp_fields = array('media_url', 'episode_title', 'episode_number', 'episode_image', 'show_notes');
    $update_pp = count(array_intersect($pp_fields, array_keys($data))) > 0 || !empty($data['image_base64']) || !empty($data['remove_image']) || isset($data['post_excerpt']);
    if ($update_pp) {
        $old_enclosure = (string) get_post_meta($post->ID, $key, true);
        $chunks = explode("\n", $old_enclosure, 4);
        $old_extra = isset($chunks[3]) ? maybe_unserialize($chunks[3]) : array();
        if (!is_array($old_extra)) $old_extra = array();
        $media_url = isset($data['media_url']) ? esc_url_raw((string) $data['media_url']) : $previous['media_url'];
        if (!$media_url || !wp_http_validate_url($media_url)) return new WP_Error('upsonic_bad_media', 'La URL MP3 no es válida.', array('status' => 400));
        $title = isset($data['episode_title']) ? sanitize_text_field($data['episode_title']) : ($previous['episode_title'] ?: get_the_title($post->ID));
        $notes = isset($data['show_notes']) ? wp_kses_post($data['show_notes']) : (isset($data['post_excerpt']) ? wp_kses_post($data['post_excerpt']) : (isset($old_extra['summary']) ? $old_extra['summary'] : ''));
        $number = isset($data['episode_number']) ? sanitize_text_field($data['episode_number']) : $previous['episode_number'];
        if (isset($data['episode_image'])) $image_url = esc_url_raw($data['episode_image']);
        $pp = boostsn_bridge_set_powerpress($post->ID, $media_url, $feed, $title, $image_url, $notes, $number);
        if (is_wp_error($pp)) return $pp;
        // Conserva claves adicionales de PowerPress, incluyendo datos no administrados por Upsonic.
        $new_chunks = explode("\n", (string) get_post_meta($post->ID, $key, true), 4);
        $new_extra = isset($new_chunks[3]) ? maybe_unserialize($new_chunks[3]) : array();
        if (is_array($new_extra)) {
            if (isset($data['episode_number']) && trim((string) $data['episode_number']) === '') foreach (array('episode','itunes_episode','episode_number','episode_no') as $field) unset($old_extra[$field]);
            if (!empty($data['remove_image'])) foreach (array('image','itunes_image','episode_image') as $field) unset($old_extra[$field]);
            $merged = array_merge($old_extra, $new_extra);
            update_post_meta($post->ID, $key, $new_chunks[0] . "\n" . $new_chunks[1] . "\n" . $new_chunks[2] . "\n" . serialize($merged));
        }
    }
    return rest_ensure_response(array('ok' => true, 'version' => BOOSTSN_BRIDGE_VERSION, 'episode' => upsonic_bridge_episode_data(get_post($post->ID), $feed ?: 'podcast')));
}

function upsonic_bridge_create_episode(WP_REST_Request $request) {
    $target = upsonic_bridge_target((string) $request->get_param('post_type'), (string) $request->get_param('feed_slug'));
    if (is_wp_error($target)) return $target;
    return boostsn_bridge_create_draft($request);
}
