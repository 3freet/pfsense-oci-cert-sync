<?php
/*
 * ocicertsync_accounts_edit.php
 * OCI Cert Sync -- add or edit a single OCI API signing identity ("account").
 */

namespace pfsense_pkg\ocicertsync;

$shortcut_section = 'ocicertsync';
require_once('guiconfig.inc');
require_once('certs.inc');
require_once('/usr/local/pkg/ocicertsync/ocicertsync.inc');
require_once('/usr/local/pkg/ocicertsync/pkg_ocicertsync_tabs.inc');

$changedesc = 'Services: OCI Cert Sync: OCI Accounts';

ocicertsync_init_config();
$a_accounts = &$config['installedpackages']['ocicertsync']['accounts']['item'];

$id = isset($_GET['id']) ? $_GET['id'] : null;
if (isset($_POST['id']) && $_POST['id'] !== '') {
    $id = $_POST['id'];
}

$existingIdx = null;
if ($id !== null) {
    foreach ($a_accounts as $idx => $acct) {
        if ($acct['id'] === $id) {
            $existingIdx = $idx;
            break;
        }
    }
}

$stage_options = array('CURRENT' => 'CURRENT (activate immediately)', 'PENDING' => 'PENDING (stage only, promote manually)');

if ($_POST) {
    unset($input_errors);
    $pconfig = $_POST;

    if (empty($pconfig['name'])) {
        $input_errors[] = 'A name is required (used to identify this account in dropdowns).';
    }
    foreach (array('tenancyocid', 'userocid', 'fingerprint', 'region') as $req) {
        if (empty($pconfig[$req])) {
            $input_errors[] = "The field \"{$req}\" is required.";
        }
    }
    $hasExistingKey = ($existingIdx !== null) && !empty($a_accounts[$existingIdx]['privatekey']);
    if (empty($pconfig['privatekey']) && !$hasExistingKey) {
        $input_errors[] = 'An OCI API private key (PEM) is required.';
    }

    if (!$input_errors) {
        $row = ($existingIdx !== null) ? $a_accounts[$existingIdx] : array(
            'id' => 'acct_' . bin2hex(random_bytes(6)),
        );

        $row['name'] = trim($pconfig['name']);
        $row['tenancyocid'] = trim($pconfig['tenancyocid']);
        $row['userocid'] = trim($pconfig['userocid']);
        $row['fingerprint'] = trim($pconfig['fingerprint']);
        $row['region'] = trim($pconfig['region']);
        $row['realmdomain'] = !empty($pconfig['realmdomain']) ? trim($pconfig['realmdomain']) : 'oraclecloud.com';
        $row['stage'] = $pconfig['stage'];

        if (!empty($pconfig['clearkey'])) {
            $row['privatekey'] = '';
            $row['privatekeypass'] = '';
        } else {
            if (!empty($pconfig['privatekey'])) {
                $row['privatekey'] = base64_encode(trim($pconfig['privatekey']) . "\n");
            }
            if (!empty($pconfig['privatekeypass'])) {
                $row['privatekeypass'] = $pconfig['privatekeypass'];
            }
        }

        if ($existingIdx !== null) {
            $a_accounts[$existingIdx] = $row;
        } else {
            $a_accounts[] = $row;
        }

        write_config($changedesc);
        header('Location: ocicertsync_accounts.php');
        exit;
    }
}

if (!$_POST) {
    $pconfig = array(
        'name' => '', 'tenancyocid' => '', 'userocid' => '', 'fingerprint' => '',
        'region' => '', 'realmdomain' => 'oraclecloud.com', 'stage' => 'CURRENT',
    );
    $existingHasKey = false;
    if ($existingIdx !== null) {
        $acct = $a_accounts[$existingIdx];
        $pconfig['name'] = $acct['name'];
        $pconfig['tenancyocid'] = $acct['tenancyocid'];
        $pconfig['userocid'] = $acct['userocid'];
        $pconfig['fingerprint'] = $acct['fingerprint'];
        $pconfig['region'] = $acct['region'];
        $pconfig['realmdomain'] = !empty($acct['realmdomain']) ? $acct['realmdomain'] : 'oraclecloud.com';
        $pconfig['stage'] = !empty($acct['stage']) ? $acct['stage'] : 'CURRENT';
        $existingHasKey = !empty($acct['privatekey']);
    }
    // Private key material is intentionally never redisplayed once saved.
    $pconfig['privatekey'] = '';
    $pconfig['privatekeypass'] = '';
}

$pgtitle = array('Services', 'OCI Cert Sync', ($existingIdx !== null ? 'Edit' : 'Add') . ' OCI Account');
include('head.inc');

if ($input_errors) {
    print_input_errors($input_errors);
}

display_top_tabs_active($ocicertsync_tab_array['ocicertsync'], 'accounts');

$form = new \Form;
$section = new \Form_Section(($existingIdx !== null ? 'Edit' : 'Add') . ' OCI Account');

$section->addInput(new \Form_Input(
    'name',
    'Name',
    'text',
    $pconfig['name']
))->setHelp('A label to identify this account, e.g. "Production Tenancy" or "Muscat DCC".');

$section->addInput(new \Form_Input(
    'tenancyocid',
    'Tenancy OCID',
    'text',
    $pconfig['tenancyocid']
))->setHelp('OCID of the OCI tenancy.');

$section->addInput(new \Form_Input(
    'userocid',
    'User OCID',
    'text',
    $pconfig['userocid']
))->setHelp('OCID of the IAM user (ideally a dedicated automation/service account) used to sign API requests.');

$section->addInput(new \Form_Input(
    'fingerprint',
    'API Key Fingerprint',
    'text',
    $pconfig['fingerprint']
))->setHelp('Fingerprint of the API signing key uploaded to that user in OCI IAM, e.g. aa:bb:cc:...');

$section->addInput(new \Form_Input(
    'region',
    'Region',
    'text',
    $pconfig['region']
))->setHelp('Region hosting this tenancy\'s OCI Certificates service instance, e.g. us-ashburn-1.');

$section->addInput(new \Form_Input(
    'realmdomain',
    'Realm Second-Level Domain',
    'text',
    $pconfig['realmdomain']
))->setHelp('Leave as %1$soraclecloud.com%2$s for Oracle\'s standard commercial realm (OC1). ' .
    'Dedicated Region Cloud@Customer, government, and sovereign realms use a different domain -- ' .
    'e.g. %1$soraclecloud9.com%2$s for realm OC9.',
    '<code>', '</code>');

$keyAlreadyStored = ($existingIdx !== null) && !empty($a_accounts[$existingIdx]['privatekey']);

$section->addInput(new \Form_Textarea(
    'privatekey',
    'API Private Key (PEM)',
    $pconfig['privatekey']
))->setNoWrap()
    ->setAttribute('placeholder', $keyAlreadyStored
        ? '(a key is already stored -- leave blank to keep it)'
        : "-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----")
    ->setHelp('Paste the PEM private key half of this account\'s API signing key. %1$sNever redisplayed once saved%2$s -- leave blank on future saves to keep the stored key.',
        '<strong>', '</strong>');

$section->addInput(new \Form_Input(
    'privatekeypass',
    'Private Key Passphrase',
    'password',
    ''
))->setHelp('Only needed if the private key above is passphrase-protected. Leave blank to keep the stored passphrase (or if the key has none).');

$section->addInput(new \Form_Checkbox(
    'clearkey',
    'Clear Stored Key',
    'Remove the stored private key and passphrase (check this and Save to reset both, e.g. before pasting a replacement).',
    false
));

$section->addInput(new \Form_Select(
    'stage',
    'Default Stage',
    $pconfig['stage'],
    $stage_options
))->setHelp('CURRENT activates each new certificate version immediately for mappings using this account. PENDING creates the version without activating it. Can be overridden per certificate mapping.');

$form->add($section);
print $form;
?>
<?php if ($existingIdx !== null): ?>
<input name="id" type="hidden" value="<?= htmlspecialchars($id) ?>" />
<?php endif; ?>
<nav class="action-buttons">
    <a href="ocicertsync_accounts.php" class="btn btn-default btn-sm">
        <i class="fa fa-undo icon-embed-btn"></i><?= gettext('Cancel') ?>
    </a>
</nav>
<?php
include('foot.inc');
