<?php
/*
 * ocicertsync_mappings_edit.php
 * OCI Cert Sync -- add or edit a single pfSense-cert -> OCI-OCID mapping.
 */

namespace pfsense_pkg\ocicertsync;

$shortcut_section = 'ocicertsync';
require_once('guiconfig.inc');
require_once('certs.inc');
require_once('/usr/local/pkg/ocicertsync/ocicertsync.inc');
require_once('/usr/local/pkg/ocicertsync/pkg_ocicertsync_tabs.inc');

$changedesc = 'Services: OCI Cert Sync: Certificate Mappings';

ocicertsync_init_config();
$a_mappings = &$config['installedpackages']['ocicertsync']['mappings']['item'];

$id = isset($_GET['id']) ? $_GET['id'] : null;
if (isset($_POST['id']) && $_POST['id'] !== '') {
    $id = $_POST['id'];
}

$stage_options = array(
    ''         => 'Use the account\'s default stage',
    'CURRENT'  => 'CURRENT (activate immediately)',
    'PENDING'  => 'PENDING (stage only, promote manually)',
);

$cert_options = cert_build_list('cert', 'HTTPS');

$account_options = array();
foreach (ocicertsync_accounts() as $acct) {
    $account_options[$acct['id']] = $acct['name'];
}

if ($_POST) {
    unset($input_errors);
    $pconfig = $_POST;

    if (empty($pconfig['account']) || !array_key_exists($pconfig['account'], $account_options)) {
        $input_errors[] = 'Please choose a valid OCI account.';
    }
    if (empty($pconfig['certref']) || !array_key_exists($pconfig['certref'], $cert_options)) {
        $input_errors[] = 'Please choose a valid pfSense certificate.';
    }
    if (empty($pconfig['ociocid'])) {
        $input_errors[] = 'The OCI Certificate OCID is required.';
    } elseif (strpos($pconfig['ociocid'], 'ocid1.certificate.') !== 0) {
        $input_errors[] = 'That does not look like a certificate OCID (expected it to start with "ocid1.certificate.").';
    }

    if (!$input_errors) {
        $row = ($id !== null && isset($a_mappings[$id])) ? $a_mappings[$id] : array(
            'lastsync'    => 0,
            'laststatus'  => '',
            'lastmessage' => '',
            'lastversion' => '',
            'lasthash'    => '',
        );

        $row['account'] = $pconfig['account'];
        $row['certref'] = $pconfig['certref'];
        $row['ociocid'] = trim($pconfig['ociocid']);
        $row['stage'] = $pconfig['stage'];

        if ($id !== null && isset($a_mappings[$id])) {
            $a_mappings[$id] = $row;
        } else {
            $a_mappings[] = $row;
        }

        write_config($changedesc);
        header('Location: ocicertsync_mappings.php');
        exit;
    }
}

if (!$_POST) {
    $pconfig = array('account' => '', 'certref' => '', 'ociocid' => '', 'stage' => '');
    if ($id !== null && isset($a_mappings[$id])) {
        $pconfig['account'] = $a_mappings[$id]['account'] ?? '';
        $pconfig['certref'] = $a_mappings[$id]['certref'];
        $pconfig['ociocid'] = $a_mappings[$id]['ociocid'];
        $pconfig['stage'] = $a_mappings[$id]['stage'];
    }
}

$pgtitle = array('Services', 'OCI Cert Sync', ($id !== null ? 'Edit' : 'Add') . ' Mapping');
include('head.inc');

if ($input_errors) {
    print_input_errors($input_errors);
}

display_top_tabs_active($ocicertsync_tab_array['ocicertsync'], 'mappings');

$form = new \Form;
$section = new \Form_Section(($id !== null ? 'Edit' : 'Add') . ' Certificate Mapping');

if (empty($account_options)) {
    print_input_errors(array('No OCI accounts configured yet. Add one under the OCI Accounts tab first.'));
} elseif (empty($cert_options)) {
    print_input_errors(array('No compatible certificates were found in System > Cert. Manager. Create/import one there first.'));
} else {
    $section->addInput(new \Form_Select(
        'account',
        'OCI Account',
        $pconfig['account'],
        $account_options
    ))->setHelp('Which OCI tenancy/credentials (from the OCI Accounts tab) to push this certificate to.');

    $section->addInput(new \Form_Select(
        'certref',
        'pfSense Certificate',
        $pconfig['certref'],
        $cert_options
    ))->setHelp('The certificate from the pfSense Certificate Manager to keep in sync (this is where ACME writes renewed Let\'s Encrypt certificates).');

    $section->addInput(new \Form_Input(
        'ociocid',
        'OCI Certificate OCID',
        'text',
        $pconfig['ociocid']
    ))->setHelp('OCID of the %1$salready-created%2$s certificate resource in OCI Certificate Manager, under the account chosen above (import it once via the OCI console or `oci certs-mgmt certificate create-by-importing-config`). This tool only rotates existing certificates, it does not create them.',
        '<strong>', '</strong>');

    $section->addInput(new \Form_Select(
        'stage',
        'Stage Override',
        $pconfig['stage'],
        $stage_options
    ))->setHelp('Overrides the selected account\'s default Stage for this certificate only.');
}

$form->add($section);
print $form;
?>
<?php if ($id !== null && isset($a_mappings[$id])): ?>
<input name="id" type="hidden" value="<?= $id ?>" />
<?php endif; ?>
<nav class="action-buttons">
    <a href="ocicertsync_mappings.php" class="btn btn-default btn-sm">
        <i class="fa fa-undo icon-embed-btn"></i><?= gettext('Cancel') ?>
    </a>
</nav>
<?php
include('foot.inc');
