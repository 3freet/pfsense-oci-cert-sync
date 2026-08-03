<?php
/*
 * ocicertsync_cron.php
 *
 * Cron entrypoint: loads pfSense's config layer, runs a sync pass over
 * every configured mapping, and prints one line per mapping (piped to
 * syslog by the cron command line set up in ocicertsync_set_cronjob()).
 */

require_once('globals.inc');
require_once('config.inc');
require_once('certs.inc');
require_once('/usr/local/pkg/ocicertsync/ocicertsync.inc');

$results = \pfsense_pkg\ocicertsync\ocicertsync_sync_all(false);

foreach ($results as $r) {
    $status = $r['ok'] ? 'OK' : 'ERROR';
    echo "[{$status}] certref={$r['certref']}: {$r['message']}\n";
}
