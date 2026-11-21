<?php
/** GitHub Releases update provider for Upsonic Bridge. */
if (!defined('ABSPATH')) exit;

function upsonic_bridge_release_info() {
    $cache = get_transient('upsonic_bridge_github_release');
    if (is_array($cache)) return $cache;
    $response = wp_remote_get('https://api.github.com/repos/robsasuke/upsonic-bridge/releases/latest', array(
        'timeout' => 12,
        'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'Upsonic-Bridge-WordPress-Updater'),
    ));
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return false;
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['tag_name']) || empty($data['assets'])) return false;
    $version = ltrim((string) $data['tag_name'], 'vV');
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) return false;
    $package = '';
    foreach ($data['assets'] as $asset) {
        if (isset($asset['name'], $asset['browser_download_url']) && $asset['name'] === 'Upsonic-Bridge-v' . $version . '.zip') {
            $url = $asset['browser_download_url'];
            if (wp_http_validate_url($url) && parse_url($url, PHP_URL_HOST) === 'github.com') $package = $url;
            break;
        }
    }
    if (!$package) return false;
    $info = array('version' => $version, 'package' => $package, 'url' => 'https://github.com/robsasuke/upsonic-bridge/releases/latest');
    set_transient('upsonic_bridge_github_release', $info, 6 * HOUR_IN_SECONDS);
    return $info;
}

// Native update provider for plugins with an Update URI header (WordPress 5.8+).
add_filter('update_plugins_github.com', function ($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== 'upsonic-bridge/upsonic-bridge.php') return $update;
    $info = upsonic_bridge_release_info();
    if (!$info) return false;
    return array(
        'slug' => 'upsonic-bridge',
        'version' => $info['version'],
        'url' => $info['url'],
        'package' => $info['package'],
        'autoupdate' => true,
    );
}, 10, 3);

// Compatibility for installations that predate the Update URI header.
add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient) || !isset($transient->checked['upsonic-bridge/upsonic-bridge.php'])) return $transient;
    $basename = 'upsonic-bridge/upsonic-bridge.php';
    $info = upsonic_bridge_release_info();
    if (!$info) return $transient;
    if (version_compare($info['version'], BOOSTSN_BRIDGE_VERSION, '>')) {
        $transient->response[$basename] = (object) array(
            'id' => 'https://github.com/robsasuke/upsonic-bridge',
            'slug' => 'upsonic-bridge', 'plugin' => $basename,
            'new_version' => $info['version'], 'url' => $info['url'],
            'package' => $info['package'],
        );
        unset($transient->no_update[$basename]);
    }
    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'upsonic-bridge') return $result;
    $info = upsonic_bridge_release_info();
    if (!$info) return $result;
    return (object) array(
        'name' => 'Upsonic Bridge', 'slug' => 'upsonic-bridge', 'version' => $info['version'],
        'author' => 'Robert Sasuke', 'homepage' => $info['url'],
        'requires' => '5.8', 'requires_php' => '7.4',
        'sections' => array('description' => 'Conector de Upsonic con WordPress y PowerPress; actualizaciones desde GitHub Releases.'),
        'download_link' => $info['package'],
    );
}, 10, 3);

add_filter('auto_update_plugin', function ($update, $item) {
    if (isset($item->plugin) && $item->plugin === 'upsonic-bridge/upsonic-bridge.php') return true;
    return $update;
}, 10, 2);

add_action('upgrader_process_complete', function ($upgrader, $options) {
    if (!empty($options['type']) && $options['type'] === 'plugin') delete_transient('upsonic_bridge_github_release');
}, 10, 2);
