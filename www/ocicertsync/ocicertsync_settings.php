<?php
/*
 * ocicertsync_settings.php
 * OCI Cert Sync -- Settings tab (global schedule only; OCI identities now
 * live under the OCI Accounts tab, one per tenancy/region).
 */

namespace pfsense_pkg\ocicertsync;

$shortcut_section = 'ocicertsync';
require_once('guiconfig.inc');
require_once('/usr/local/pkg/ocicertsync/ocicertsync.inc');
require_once('/usr/local/pkg/ocicertsync/pkg_ocicertsync_tabs.inc');

$changedesc = 'Services: OCI Cert Sync: Settings';

ocicertsync_init_config();
$settings = &$config['installedpackages']['ocicertsync'];

$interval_options = array(
    '15min'  => 'Every 15 minutes',
    '30min'  => 'Every 30 minutes',
    'hourly' => 'Hourly',
    'daily'  => 'Daily (03:17)',
);

if ($_POST) {
    unset($input_errors);
    $pconfig = $_POST;

    if (!empty($pconfig['enable']) && empty($config['installedpackages']['ocicertsync']['accounts']['item'])) {
        $input_errors[] = 'Add at least one OCI Account (see the OCI Accounts tab) before enabling scheduled sync.';
    }

    if (!$input_errors) {
        $settings['enable'] = !empty($pconfig['enable']) ? 'on' : '';
        $settings['interval'] = $pconfig['interval'];

        ocicertsync_set_cronjob();
        write_config($changedesc);
        $savemsg = 'Settings saved.';
    }
}

$pconfig = array();
$pconfig['enable'] = $settings['enable'];
$pconfig['interval'] = !empty($settings['interval']) ? $settings['interval'] : 'daily';

$pgtitle = array('Services', 'OCI Cert Sync', 'Settings');
include('head.inc');

if ($input_errors) {
    print_input_errors($input_errors);
}
if ($savemsg) {
    print_info_box($savemsg);
}

display_top_tabs_active($ocicertsync_tab_array['ocicertsync'], 'settings');

$form = new \Form;

$section = new \Form_Section('Schedule');

$section->addInput(new \Form_Checkbox(
    'enable',
    'Enable',
    'Enable scheduled certificate sync to OCI Certificate Manager.',
    $pconfig['enable']
))->setHelp('OCI credentials are configured per tenancy under the %1$sOCI Accounts%2$s tab, and assigned to each certificate under %1$sCertificate Mappings%2$s.',
    '<strong>', '</strong>');

$section->addInput(new \Form_Select(
    'interval',
    'Sync Interval',
    $pconfig['interval'],
    $interval_options
))->setHelp('How often the cron job checks configured certificate mappings for changes and pushes updates to OCI. Unchanged certificates are skipped automatically.');

$form->add($section);

print $form;

include('foot.inc');
