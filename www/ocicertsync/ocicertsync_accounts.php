<?php
/*
 * ocicertsync_accounts.php
 * OCI Cert Sync -- OCI Accounts tab: list, add, edit, and delete OCI API
 * signing identities. Each Certificate Mapping picks one of these.
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

if ($_GET['act'] == 'del') {
    $id = $_GET['id'];
    $inUse = ocicertsync_account_in_use($id);
    if (!empty($inUse)) {
        $input_errors[] = 'Cannot delete this account -- it is still used by ' . count($inUse) .
            ' certificate mapping(s). Edit or delete those mappings first.';
    } else {
        foreach ($a_accounts as $idx => $acct) {
            if ($acct['id'] === $id) {
                unset($a_accounts[$idx]);
                write_config($changedesc . ': removed an account.');
                header('Location: ocicertsync_accounts.php');
                exit;
            }
        }
    }
}

$pgtitle = array('Services', 'OCI Cert Sync', 'OCI Accounts');
include('head.inc');

if ($input_errors) {
    print_input_errors($input_errors);
}
if ($savemsg) {
    print_info_box($savemsg);
}

display_top_tabs_active($ocicertsync_tab_array['ocicertsync'], 'accounts');
?>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><?= gettext('OCI Accounts') ?></h2>
    </div>
    <div class="panel-body table-responsive">
        <table class="table table-hover table-striped table-condensed">
            <thead>
                <tr>
                    <th><?= gettext('Name') ?></th>
                    <th><?= gettext('Tenancy OCID') ?></th>
                    <th><?= gettext('Region') ?></th>
                    <th><?= gettext('Realm Domain') ?></th>
                    <th><?= gettext('Default Stage') ?></th>
                    <th data-sortable="false"><?= gettext('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
<?php if (empty($a_accounts)): ?>
                <tr><td colspan="6" class="text-center"><?= gettext('No OCI accounts configured yet.') ?></td></tr>
<?php else: foreach ($a_accounts as $acct): ?>
                <tr>
                    <td><?= htmlspecialchars($acct['name']) ?></td>
                    <td><code><?= htmlspecialchars($acct['tenancyocid']) ?></code></td>
                    <td><?= htmlspecialchars($acct['region']) ?></td>
                    <td><?= htmlspecialchars(!empty($acct['realmdomain']) ? $acct['realmdomain'] : 'oraclecloud.com') ?></td>
                    <td><?= htmlspecialchars(!empty($acct['stage']) ? $acct['stage'] : 'CURRENT') ?></td>
                    <td>
                        <a href="ocicertsync_accounts_edit.php?id=<?= urlencode($acct['id']) ?>" class="btn btn-xs btn-info" title="<?= gettext('Edit') ?>">
                            <i class="fa fa-pencil"></i>
                        </a>
                        <a href="ocicertsync_accounts.php?act=del&amp;id=<?= urlencode($acct['id']) ?>" class="btn btn-xs btn-danger" title="<?= gettext('Delete') ?>"
                           onclick="return confirm('<?= gettext('Delete this OCI account?') ?>');">
                            <i class="fa fa-trash"></i>
                        </a>
                    </td>
                </tr>
<?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<nav class="action-buttons">
    <a href="ocicertsync_accounts_edit.php" class="btn btn-success btn-sm">
        <i class="fa fa-plus icon-embed-btn"></i><?= gettext('Add') ?>
    </a>
</nav>

<?php include('foot.inc'); ?>
