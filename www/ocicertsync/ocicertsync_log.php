<?php
/*
 * ocicertsync_log.php
 * OCI Cert Sync -- Sync Log tab: read-only view of the detailed per-sync
 * log (/var/log/ocicertsync.log), newest first, so a given sync attempt
 * can be traced back to exactly which certificate (subject/serial/sha256
 * fingerprint) was pushed to which OCI OCID, and with what result.
 */

namespace pfsense_pkg\ocicertsync;

$shortcut_section = 'ocicertsync';
require_once('guiconfig.inc');
require_once('certs.inc');
require_once('/usr/local/pkg/ocicertsync/ocicertsync.inc');
require_once('/usr/local/pkg/ocicertsync/pkg_ocicertsync_tabs.inc');

if ($_GET['act'] == 'clear') {
    if (file_exists(OCICERTSYNC_LOG_FILE)) {
        @unlink(OCICERTSYNC_LOG_FILE);
    }
    if (file_exists(OCICERTSYNC_LOG_FILE . '.0')) {
        @unlink(OCICERTSYNC_LOG_FILE . '.0');
    }
    header('Location: ocicertsync_log.php');
    exit;
}

$lines = ocicertsync_log_tail(500);

$pgtitle = array('Services', 'OCI Cert Sync', 'Sync Log');
include('head.inc');

display_top_tabs_active($ocicertsync_tab_array['ocicertsync'], 'log');
?>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title"><?= gettext('Sync Log') ?> <small>(<?= gettext('newest first, last 500 lines') ?>)</small></h2>
    </div>
    <div class="panel-body">
<?php if (empty($lines)): ?>
        <p class="text-muted"><?= gettext('No log entries yet -- run a sync to populate this.') ?></p>
<?php else: ?>
        <pre style="white-space: pre-wrap; word-break: break-all; max-height: 70vh; overflow-y: auto;"><?= htmlspecialchars(implode("\n", $lines)) ?></pre>
<?php endif; ?>
    </div>
</div>

<nav class="action-buttons">
    <a href="ocicertsync_log.php" class="btn btn-default btn-sm">
        <i class="fa fa-refresh icon-embed-btn"></i><?= gettext('Refresh') ?>
    </a>
    <a href="ocicertsync_log.php?act=clear" class="btn btn-danger btn-sm"
       onclick="return confirm('<?= gettext('Clear the sync log?') ?>');">
        <i class="fa fa-trash icon-embed-btn"></i><?= gettext('Clear Log') ?>
    </a>
</nav>

<?php include('foot.inc'); ?>
