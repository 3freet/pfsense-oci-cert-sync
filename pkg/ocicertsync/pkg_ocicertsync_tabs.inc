<?php

// DO NOT REQUIRE guiconfig.inc HERE! It contains display_top_tabs(), which
// is all we need, but including guiconfig.inc from this file can hang
// filter rule generation when /usr/local/pkg/*.inc gets pulled in via
// filter.inc's discover_pkg_rules(). Same pattern the acme package uses.

namespace pfsense_pkg\ocicertsync;

global $ocicertsync_tab_array;

$ocicertsync_tab_array['ocicertsync'] = array();
$ocicertsync_tab_array['ocicertsync']['settings'] = array('name' => 'Settings', 'url' => '/ocicertsync/ocicertsync_settings.php');
$ocicertsync_tab_array['ocicertsync']['accounts'] = array('name' => 'OCI Accounts', 'url' => '/ocicertsync/ocicertsync_accounts.php');
$ocicertsync_tab_array['ocicertsync']['mappings'] = array('name' => 'Certificate Mappings', 'url' => '/ocicertsync/ocicertsync_mappings.php');
$ocicertsync_tab_array['ocicertsync']['log'] = array('name' => 'Sync Log', 'url' => '/ocicertsync/ocicertsync_log.php');

function display_top_tabs_active($top_tabs, $activetab) {
    $tab_array = array();
    foreach ($top_tabs as $key => $tab_item) {
        $tab_array[] = array($tab_item['name'], $key == $activetab, $tab_item['url']);
    }
    display_top_tabs($tab_array);
}
