<?php
if(!defined('WP_UNINSTALL_PLUGIN'))exit;
foreach(['viljandi-linn','viljandi-vald','mulgi','pohja-sakala'] as $id){
    wp_clear_scheduled_hook('voluk_refresh_source',[$id]);
    wp_clear_scheduled_hook('voluk_refresh_source_once',[$id]);
    delete_option('voluk_source_'.$id);
    delete_option('voluk_lock_'.$id);
}
delete_option('voluk_settings');
// Artiklite lühiajalised transiendid aeguvad WordPressi tavapärase koristuse käigus.
