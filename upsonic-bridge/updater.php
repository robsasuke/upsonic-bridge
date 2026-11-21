<?php
/** GitHub Releases update provider for Upsonic Bridge. */
if (!defined('ABSPATH')) exit;

function upsonic_bridge_update_log($event, $detail = '') {
    $items = get_option('upsonic_bridge_update_log', array());
    if (!is_array($items)) $items = array();
    $items[] = array('time' => gmdate('c'), 'event' => sanitize_key($event), 'detail' => sanitize_text_field(substr((string)$detail, 0, 250)));
    update_option('upsonic_bridge_update_log', array_slice($items, -50), false);
}

function upsonic_bridge_release_info($refresh = false) {
    if (!$refresh) {
        $cached = get_transient('upsonic_bridge_github_release');
        if (is_array($cached) && !empty($cached['version']) && !empty($cached['package'])) return $cached;
    }
    $response = wp_remote_get('https://api.github.com/repos/robsasuke/upsonic-bridge/releases/latest', array(
        'timeout' => 12,
        'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'Upsonic-Bridge-WordPress-Updater'),
    ));
    if (is_wp_error($response)) {
        upsonic_bridge_update_log('github_error', $response->get_error_message());
        return false;
    }
    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        upsonic_bridge_update_log('github_http_error', (string)$status);
        return false;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['tag_name']) || empty($data['assets'])) {
        upsonic_bridge_update_log('invalid_release', 'Missing tag or release assets');
        return false;
    }
    $version = ltrim((string)$data['tag_name'], 'vV');
    if (!preg_match('/^\\d+\\.\\d+\\.\\d+$/', $version)) return false;
    $package = '';
    foreach ($data['assets'] as $asset) {
        if (isset($asset['name'], $asset['browser_download_url']) && $asset['name'] === 'Upsonic-Bridge-v' . $version . '.zip') {
            $url = $asset['browser_download_url'];
            if (wp_http_validate_url($url) && parse_url($url, PHP_URL_HOST) === 'github.com') $package = $url;
            break;
        }
    }
    if (!$package) {
        upsonic_bridge_update_log('missing_package', $version);
        return false;
    }
    $info = array('version' => $version, 'package' => $package, 'url' => 'https://github.com/robsasuke/upsonic-bridge/releases/latest');
    set_transient('upsonic_bridge_github_release', $info, 30 * MINUTE_IN_SECONDS);
    update_option('upsonic_bridge_last_successful_check', array('time'=>gmdate('c'), 'version'=>$version), false);
    return $info;
}

add_filter('update_plugins_github.com', function ($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== 'upsonic-bridge/upsonic-bridge.php') return $update;
    $info = upsonic_bridge_release_info();
    if (!$info) return false;
    return array('id'=>'https://github.com/robsasuke/upsonic-bridge', 'slug'=>'upsonic-bridge',
        'version'=>$info['version'], 'url'=>$info['url'], 'package'=>$info['package']);
}, 10, 3);

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'upsonic-bridge') return $result;
    $info = upsonic_bridge_release_info();
    if (!$info) return $result;
    return (object) array('name'=>'Upsonic Bridge', 'slug'=>'upsonic-bridge', 'version'=>$info['version'],
        'author'=>'Robert Sasuke', 'homepage'=>$info['url'], 'requires'=>'5.8', 'requires_php'=>'7.4',
        'sections'=>array('description'=>'Conector de Upsonic con WordPress y PowerPress.'),
        'download_link'=>$info['package']);
}, 10, 3);

// Respect administrator settings and WordPress policies. Opt in once at activation.
function upsonic_bridge_enable_auto_updates() {
    $plugins = (array)get_site_option('auto_update_plugins', array());
    $basename = 'upsonic-bridge/upsonic-bridge.php';
    if (!in_array($basename, $plugins, true)) {
        $plugins[] = $basename;
        update_site_option('auto_update_plugins', $plugins);
    }
}
register_activation_hook(__DIR__ . '/upsonic-bridge.php', 'upsonic_bridge_enable_auto_updates');

add_action('upsonic_bridge_check_updates', function () {
    delete_transient('upsonic_bridge_github_release');
    $info = upsonic_bridge_release_info(true);
    if ($info && version_compare($info['version'], BOOSTSN_BRIDGE_VERSION, '>')) {
        delete_site_transient('update_plugins');
        if (!function_exists('wp_update_plugins')) require_once ABSPATH . 'wp-includes/update.php';
        wp_update_plugins();
    }
});
add_action('init', function () {
    if (!wp_next_scheduled('upsonic_bridge_check_updates')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', 'upsonic_bridge_check_updates');
    }
});
register_deactivation_hook(__DIR__ . '/upsonic-bridge.php', function () {
    wp_clear_scheduled_hook('upsonic_bridge_check_updates');
});
add_action('upgrader_process_complete', function ($upgrader, $options) {
    if (!empty($options['type']) && $options['type'] === 'plugin') {
        delete_transient('upsonic_bridge_github_release');
        if (!empty($options['plugins']) && in_array('upsonic-bridge/upsonic-bridge.php', (array)$options['plugins'], true)) {
            upsonic_bridge_update_log('upgrade_complete', BOOSTSN_BRIDGE_VERSION);
        }
    }
}, 10, 2);
add_action('automatic_updates_complete', function ($results) {
    if (!empty($results['plugin'])) {
        foreach ($results['plugin'] as $item) {
            if (isset($item->item->plugin) && $item->item->plugin === 'upsonic-bridge/upsonic-bridge.php') {
                upsonic_bridge_update_log('automatic_update', empty($item->result) ? 'failed' : 'completed');
            }
        }
    }
});
