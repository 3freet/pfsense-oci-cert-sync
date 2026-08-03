<?php
/*
 * ocicertsync_mappings.php
 * OCI Cert Sync -- Certificate Mappings tab: list, delete, and manually
 * trigger a sync for each pfSense-cert -> OCI-certificate-OCID mapping.
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

function ocicertsync_cert_descr($certref) {
    global $config;
    if (empty($config['cert']) || !is_array($config['cert'])) {
        return null;
    }
    foreach ($config['cert'] as $cert) {
        if (isset($cert['refid']) && $cert['refid'] === $certref) {
            return $cert['descr'];
        }
    }
    return null;
}

function ocicertsync_account_name($id) {
    $acct = ocicertsync_get_account($id);
    return $acct !== null ? $acct['name'] : null;
}

if ($_GET['act'] == 'del') {
    $id = $_GET['id'];
    if (isset($a_mappings[$id])) {
        unset($a_mappings[$id]);
        write_config($changedesc . ': removed a mapping.');
        header('Location: ocicertsync_mappings.php');
        exit;
    }
}

if ($_GET['act'] == 'syncnow' || $_GET['act'] == 'forcesync') {
    $id = $_GET['id'];
    $force = ($_GET['act'] == 'forcesync');
    if (isset($a_mappings[$id])) {
        $ok = ocicertsync_sync_one($a_mappings[$id], $force);
        write_config($changedesc . ': manual sync run.');
        $savemsg = $ok
            ? (($force ? 'Forced sync completed: ' : 'Sync completed: ') . $a_mappings[$id]['lastmessage'])
            : 'Sync failed: ' . $a_mappings[$id]['lastmessage'];
    }
}

if ($_GET['act'] == 'syncall' || $_GET['act'] == 'forcesyncall') {
    $force = ($_GET['act'] == 'forcesyncall');
    $results = ocicertsync_sync_all($force);
    $failed = count(array_filter($results, function ($r) { return !$r['ok']; }));
    $label = $force ? 'Forced sync' : 'Sync';
    $savemsg = $failed === 0
        ? "{$label} completed for all " . count($results) . ' mapping(s) (unchanged certificates were skipped).'
        : "{$label} completed with {$failed} failure(s) out of " . count($results) . ' mapping(s); see the Status column below.';
}

$pgtitle = array('Services', 'OCI Cert Sync', 'Certificate Mappings');
include('head.inc');

if ($input_errors) {
    print_input_errors($input_errors);
}
if ($savemsg) {
    print_info_box($savemsg);
}

display_top_tabs_active($ocicertsync_tab_array['ocicertsync'], 'mappings');
?>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><?= gettext('Certificate Mappings') ?></h2>
    </div>
    <div class="panel-body table-responsive">
        <table class="table table-hover table-striped table-condensed">
            <thead>
                <tr>
                    <th><?= gettext('OCI Account') ?></th>
                    <th><?= gettext('pfSense Certificate') ?></th>
                    <th><?= gettext('OCI Certificate OCID') ?></th>
                    <th><?= gettext('Stage Override') ?></th>
                    <th><?= gettext('Last Rotated') ?></th>
                    <th><?= gettext('Last Checked') ?></th>
                    <th><?= gettext('Status') ?></th>
                    <th><?= gettext('Message') ?></th>
                    <th data-sortable="false"><?= gettext('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
<?php if (empty($a_mappings)): ?>
                <tr><td colspan="9" class="text-center"><?= gettext('No certificate mappings configured yet.') ?></td></tr>
<?php else: foreach ($a_mappings as $id => $mapping):
    $descr = ocicertsync_cert_descr($mapping['certref']);
    $acctName = ocicertsync_account_name($mapping['account'] ?? '');
    $statusClass = '';
    if ($mapping['laststatus'] === 'ok') {
        $statusClass = 'text-success';
    } elseif ($mapping['laststatus'] === 'error') {
        $statusClass = 'text-danger';
    } elseif ($mapping['laststatus'] === 'skipped') {
        $statusClass = 'text-muted';
    }
?>
                <tr>
                    <td><?= $acctName !== null ? htmlspecialchars($acctName) : '<em>' . gettext('missing account') . '</em>' ?></td>
                    <td><?= $descr !== null ? htmlspecialchars($descr) : '<em>' . gettext('missing certificate') . '</em>' ?></td>
                    <td><code><?= htmlspecialchars($mapping['ociocid']) ?></code></td>
                    <td><?= !empty($mapping['stage']) ? htmlspecialchars($mapping['stage']) : gettext('(use default)') ?></td>
                    <td><?= !empty($mapping['lastsync']) ? date('Y-m-d H:i:s \U\T\C', $mapping['lastsync']) : gettext('never') ?></td>
                    <td><?= !empty($mapping['lastcheck']) ? date('Y-m-d H:i:s \U\T\C', $mapping['lastcheck']) : gettext('never') ?></td>
                    <td class="<?= $statusClass ?>"><?= !empty($mapping['laststatus']) ? htmlspecialchars($mapping['laststatus']) : '-' ?></td>
                    <td><?= htmlspecialchars((string)($mapping['lastmessage'] ?? '')) ?></td>
                    <td>
                        <a href="ocicertsync_mappings.php?act=syncnow&amp;id=<?= $id ?>" class="btn btn-xs btn-primary" title="<?= gettext('Sync Now (only pushes if the certificate or chain changed)') ?>">
                            <i class="fa fa-refresh"></i> <?= gettext('Sync Now') ?>
                        </a>
                        <a href="ocicertsync_mappings.php?act=forcesync&amp;id=<?= $id ?>" class="btn btn-xs btn-default" title="<?= gettext('Force Resync (push to OCI even if nothing changed)') ?>"
                           onclick="return confirm('<?= gettext('Force a new OCI certificate version even though nothing may have changed?') ?>');">
                            <i class="fa fa-bolt"></i>
                        </a>
                        <a href="ocicertsync_mappings_edit.php?id=<?= $id ?>" class="btn btn-xs btn-info" title="<?= gettext('Edit') ?>">
                            <i class="fa fa-pencil"></i>
                        </a>
                        <a href="ocicertsync_mappings.php?act=del&amp;id=<?= $id ?>" class="btn btn-xs btn-danger" title="<?= gettext('Delete') ?>"
                           onclick="return confirm('<?= gettext('Delete this mapping?') ?>');">
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
    <a href="ocicertsync_mappings_edit.php" class="btn btn-success btn-sm">
        <i class="fa fa-plus icon-embed-btn"></i><?= gettext('Add') ?>
    </a>
<?php if (!empty($a_mappings)): ?>
    <a href="ocicertsync_mappings.php?act=syncall" class="btn btn-primary btn-sm" title="<?= gettext('Only pushes mappings whose certificate or chain changed') ?>">
        <i class="fa fa-refresh icon-embed-btn"></i><?= gettext('Sync All Now') ?>
    </a>
    <a href="ocicertsync_mappings.php?act=forcesyncall" class="btn btn-default btn-sm" title="<?= gettext('Force a new OCI certificate version for every mapping, even unchanged ones') ?>"
       onclick="return confirm('<?= gettext('Force a new OCI certificate version for every mapping, even ones with no changes?') ?>');">
        <i class="fa fa-bolt icon-embed-btn"></i><?= gettext('Force Sync All') ?>
    </a>
<?php endif; ?>
</nav>

<?php include('foot.inc'); ?>
