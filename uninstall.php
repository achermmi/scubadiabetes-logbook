<?php
/**
 * Uninstall - Rimozione completa del plugin
 *
 * ATTENZIONE: Questo file viene eseguito SOLO quando il plugin viene ELIMINATO.
 * I dati scientifici verranno persi permanentemente!
 *
 * @package SD_Logbook
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-sd-database.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-sd-roles.php';

$db = new SD_Database();
$db->drop_tables();

$roles = new SD_Roles();
$roles->remove_roles();

delete_option( 'sd_logbook_db_version' );
delete_option( 'sd_logbook_version' );
delete_option( 'sd_logbook_settings' );
