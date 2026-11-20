<?php
/**
 * Upsonic Bridge — limpieza de datos propios al eliminar el plugin.
 *
 * Se ejecuta solo cuando WordPress elimina el plugin, no al desactivarlo.
 * Mantiene intactos los posts/CPT, taxonomías, adjuntos, configuraciones
 * de PowerPress y metadatos de episodios (enclosure).
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Única opción persistente que Upsonic Bridge 0.7.2 (y sus versiones
// anteriores BoostSN Bridge) crea en la base de datos.
// NO usar borrado por prefijo: podría afectar datos de terceros.
delete_option('boostsn_bridge_token');
delete_option('upsonic_bridge_category_map');
