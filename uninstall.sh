#!/bin/sh
# uninstall.sh - removes the OCI Cert Sync add-on's files and cron job.
# Does NOT touch your saved Settings/Certificate Mappings in config.xml --
# reinstalling the files later picks them back up as-is. To wipe them,
# clear the Settings/Certificate Mappings pages first, before uninstalling.

set -eu

PHP_BIN=$(command -v php || echo /usr/local/bin/php)

if [ -f /usr/local/pkg/ocicertsync/ocicertsync.inc ]; then
    "$PHP_BIN" -r '
    require_once("globals.inc");
    require_once("config.inc");
    require_once("certs.inc");
    require_once("/usr/local/pkg/ocicertsync/ocicertsync.inc");
    \pfsense_pkg\ocicertsync\ocicertsync_deinstall();
    echo "Cron job removed.\n";
    '
fi

rm -rf /usr/local/pkg/ocicertsync
rm -rf /usr/local/www/ocicertsync
rm -f /usr/local/share/pfSense/menu/ocicertsync.xml

echo "OCI Cert Sync files removed."
