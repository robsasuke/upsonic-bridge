<?php
/** Administrator-only updater health screen. */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_management_page('Upsonic Bridge - Actualizaciones', 'Upsonic Bridge', 'manage_options',
        'upsonic-bridge-updates', 'upsonic_bridge_render_update_page');
});

add_action('admin_post_upsonic_bridge_refresh_updates', function () {
    if (!current_user_can('update_plugins')) wp_die('Permiso insuficiente.');
    check_admin_referer('upsonic_bridge_refresh_updates');
    delete_transient('upsonic_bridge_github_release');
    $info = upsonic_bridge_release_info(true);
    if ($info) {
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }
    wp_safe_redirect(admin_url('tools.php?page=upsonic-bridge-updates&checked=1'));
    exit;
});

function upsonic_bridge_render_update_page() {
    if (!current_user_can('manage_options')) return;
    $info = upsonic_bridge_release_info();
    $last = get_option('upsonic_bridge_last_successful_check', array());
    $log = get_option('upsonic_bridge_update_log', array());
    $basename = 'upsonic-bridge/upsonic-bridge.php';
    $auto = in_array($basename, (array)get_site_option('auto_update_plugins', array()), true);
    $pending = get_site_transient('update_plugins');
    $detected = is_object($pending) && isset($pending->response[$basename]) ? $pending->response[$basename]->new_version : '';
    echo '<div class="wrap"><h1>Upsonic Bridge - Estado del actualizador</h1>';
    echo '<p>Versión instalada: <strong>' . esc_html(BOOSTSN_BRIDGE_VERSION) . '</strong></p>';
    echo '<p>Última versión en GitHub: <strong>' . esc_html($info['version'] ?? 'No disponible') . '</strong></p>';
    echo '<p>Actualización detectada por WordPress: <strong>' . esc_html($detected ?: 'Ninguna') . '</strong></p>';
    echo '<p>Actualizaciones automáticas: <strong>' . ($auto ? 'Habilitadas' : 'Deshabilitadas') . '</strong></p>';
    echo '<p>Última consulta correcta: <strong>' . esc_html($last['time'] ?? 'Sin registro') . ' (UTC)</strong></p>';
    echo '<p>Próxima comprobación programada: <strong>';
    $next = wp_next_scheduled('upsonic_bridge_check_updates');
    echo $next ? esc_html(gmdate('Y-m-d H:i:s', $next) . ' UTC') : 'No programada';
    echo '</strong></p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="upsonic_bridge_refresh_updates">';
    wp_nonce_field('upsonic_bridge_refresh_updates');
    submit_button('Comprobar actualizaciones');
    echo '</form><h2>Historial del actualizador</h2><table class="widefat striped"><thead><tr><th>Fecha UTC</th><th>Evento</th><th>Detalle</th></tr></thead><tbody>';
    if (is_array($log)) foreach (array_reverse(array_slice($log, -20)) as $item) {
        echo '<tr><td>' . esc_html($item['time'] ?? '') . '</td><td>' . esc_html($item['event'] ?? '') . '</td><td>' . esc_html($item['detail'] ?? '') . '</td></tr>';
    }
    echo '</tbody></table><p>Las actualizaciones dependen de la conectividad, permisos de archivos y tareas programadas del servidor. WordPress administra la instalación y recuperación cuando están disponibles.</p></div>';
}
