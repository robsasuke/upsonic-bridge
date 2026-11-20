<?php
/** Upsonic Bridge 0.8.0: diagnostics, validation, duplicate prevention, audit. */
if (!defined('ABSPATH')) exit;

function upsonic_bridge_check_payload($request, $existing_id = 0) {
    $status = sanitize_key((string) $request->get_param('post_status'));
    $title = trim((string) $request->get_param('post_title'));
    $media = trim((string) $request->get_param('media_url'));
    $feed = sanitize_key((string) $request->get_param('feed_slug'));
    if ($existing_id) {
        $post = get_post($existing_id);
        if (!$post) return new WP_Error('upsonic_missing_post', 'Episodio no encontrado.', array('status'=>404));
        if (!$title) $title = $post->post_title;
        if (!$media) {
            $key = (!$feed || $feed === 'podcast') ? 'enclosure' : '_' . $feed . ':enclosure';
            $parts = explode("\n", (string) get_post_meta($existing_id, $key, true));
            $media = $parts[0] ?? '';
        }
        if (!$status) $status = $post->post_status;
    }
    if ($status !== 'publish') return true;
    if (!$title) return new WP_Error('upsonic_publish_title_required', 'Para publicar es obligatorio el título.', array('status'=>422));
    if (!$media || !wp_http_validate_url($media) || !in_array(strtolower((string)parse_url($media, PHP_URL_SCHEME)), array('http','https'), true))
        return new WP_Error('upsonic_publish_audio_required', 'Para publicar se requiere una URL de audio HTTP(S) válida.', array('status'=>422));
    return true;
}

function upsonic_bridge_find_duplicate($request) {
    $type = sanitize_key((string)$request->get_param('post_type')) ?: 'post';
    $feed = sanitize_key((string)$request->get_param('feed_slug')) ?: 'podcast';
    $production = sanitize_text_field((string)$request->get_param('production_id'));
    $media = esc_url_raw((string)$request->get_param('media_url'));
    $args = array('post_type'=>$type,'post_status'=>array('publish','future','draft','pending','private','trash'), 'fields'=>'ids', 'posts_per_page'=>1, 'no_found_rows'=>true);
    if ($production !== '') {
        $args['meta_query'] = array(array('key'=>'_upsonic_production_id','value'=>$production));
        $ids = get_posts($args);
        if ($ids) return (int)$ids[0];
    }
    if ($media !== '') {
        $key = $feed === 'podcast' ? 'enclosure' : '_' . $feed . ':enclosure';
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type=%s AND p.post_status IN ('publish','future','draft','pending','private','trash') AND m.meta_key=%s AND (m.meta_value=%s OR m.meta_value LIKE %s) ORDER BY p.ID DESC LIMIT 1",
            $type, $key, $media, $wpdb->esc_like($media) . "\n%"
        ));
        if ($id) return (int)$id;
    }
    return 0;
}

function upsonic_bridge_diagnostics() {
    $pp = function_exists('upsonic_bridge_powerpress_active') && upsonic_bridge_powerpress_active();
    $routes = rest_get_server()->get_routes();
    $release = function_exists('upsonic_bridge_release_info') ? upsonic_bridge_release_info() : false;
    return rest_ensure_response(array('ok'=>true,'version'=>BOOSTSN_BRIDGE_VERSION,
        'checks'=>array(
            'wordpress'=>array('ok'=>true,'version'=>get_bloginfo('version')),
            'powerpress'=>array('ok'=>(bool)$pp),
            'rest_api'=>array('ok'=>isset($routes['/upsonic/v1/podcasts'])),
            'token_configured'=>array('ok'=>(bool)get_option(BOOSTSN_BRIDGE_OPTION)),
            'github_releases'=>array('ok'=>(bool)$release,'latest_version'=>$release['version'] ?? null),
            'https'=>array('ok'=>is_ssl())
        )
    ));
}

function upsonic_bridge_audit($response, $handler, $request) {
    $route = $request->get_route();
    if (strpos($route, '/upsonic/v1/') !== 0 && strpos($route, '/boostsn/v1/') !== 0) return $response;
    $method = $request->get_method();
    if (!in_array($method, array('POST','PUT','PATCH','DELETE'), true) || preg_match('#/(ping|diagnostics|audit)$#', $route)) return $response;
    if (is_wp_error($response)) $status = (int)($response->get_error_data()['status'] ?? 500);
    else $status = method_exists($response, 'get_status') ? (int)$response->get_status() : 200;
    $body = is_wp_error($response) ? array() : (method_exists($response, 'get_data') ? $response->get_data() : array());
    if (!is_array($body)) $body = array();
    $log = get_option('upsonic_bridge_audit', array());
    if (!is_array($log)) $log = array();
    $log[] = array('time'=>gmdate('c'),'method'=>$method,'route'=>sanitize_text_field($route),
        'post_id'=>absint($body['post_id'] ?? ($body['episode']['post_id'] ?? $request->get_param('post_id'))),
        'status'=>$status,'ok'=>$status < 400);
    if (count($log) > 150) $log = array_slice($log, -150);
    update_option('upsonic_bridge_audit', $log, false);
    return $response;
}
add_filter('rest_request_after_callbacks','upsonic_bridge_audit',10,3);

add_action('rest_api_init', function () {
    foreach (array('upsonic/v1','boostsn/v1') as $ns) {
        register_rest_route($ns,'/diagnostics',array('methods'=>'GET','callback'=>'upsonic_bridge_diagnostics','permission_callback'=>'boostsn_bridge_permission'));
        register_rest_route($ns,'/audit',array('methods'=>'GET','callback'=>function($request) {
            $log=get_option('upsonic_bridge_audit',array());
            if (!is_array($log)) $log=array();
            $limit=min(150,max(1,absint($request->get_param('limit') ?: 30)));
            return rest_ensure_response(array('ok'=>true,'entries'=>array_reverse(array_slice($log,-$limit))));
        },'permission_callback'=>'boostsn_bridge_permission'));
    }
});
