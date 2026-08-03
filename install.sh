#!/bin/sh
# install.sh - installs/updates the OCI Cert Sync add-on for pfSense.
# Run this from the directory it was extracted/copied into on the pfSense box
# (it expects pkg/, www/, and menu/ subdirectories alongside it).

set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

echo "Installing OCI Cert Sync..."

install -d -m 755 /usr/local/pkg/ocicertsync
install -d -m 755 /usr/local/www/ocicertsync
install -d -m 755 /usr/local/share/pfSense/menu

install -m 644 "$SCRIPT_DIR/pkg/ocicertsync/ocicertsync.inc" /usr/local/pkg/ocicertsync/
install -m 644 "$SCRIPT_DIR/pkg/ocicertsync/pkg_ocicertsync_tabs.inc" /usr/local/pkg/ocicertsync/
install -m 644 "$SCRIPT_DIR/pkg/ocicertsync/ocicertsync_cron.php" /usr/local/pkg/ocicertsync/
install -m 755 "$SCRIPT_DIR/pkg/ocicertsync/ocicertsync_command.sh" /usr/local/pkg/ocicertsync/

install -m 644 "$SCRIPT_DIR/www/ocicertsync/ocicertsync_settings.php" /usr/local/www/ocicertsync/
install -m 644 "$SCRIPT_DIR/www/ocicertsync/ocicertsync_accounts.php" /usr/local/www/ocicertsync/
install -m 644 "$SCRIPT_DIR/www/ocicertsync/ocicertsync_accounts_edit.php" /usr/local/www/ocicertsync/
install -m 644 "$SCRIPT_DIR/www/ocicertsync/ocicertsync_mappings.php" /usr/local/www/ocicertsync/
install -m 644 "$SCRIPT_DIR/www/ocicertsync/ocicertsync_mappings_edit.php" /usr/local/www/ocicertsync/
install -m 644 "$SCRIPT_DIR/www/ocicertsync/ocicertsync_log.php" /usr/local/www/ocicertsync/

install -m 644 "$SCRIPT_DIR/menu/ocicertsync.xml" /usr/local/share/pfSense/menu/

PHP_BIN=$(command -v php || echo /usr/local/bin/php)

"$PHP_BIN" -r '
require_once("globals.inc");
require_once("config.inc");
require_once("certs.inc");
require_once("/usr/local/pkg/ocicertsync/ocicertsync.inc");
\pfsense_pkg\ocicertsync\ocicertsync_install();
echo "Config initialized and cron entry registered (enable it on the Settings tab if this is a fresh install).\n";
'

echo "Done. Open the pfSense GUI: Services > OCI Cert Sync."
