#!/bin/sh
# ocicertsync_command.sh - cron entrypoint wrapper
# Usage: ocicertsync_command.sh "syncall"

set -eu

ACTION="${1:-syncall}"
PHP_BIN=$(command -v php || echo /usr/local/bin/php)

case "$ACTION" in
    syncall)
        exec "$PHP_BIN" -f /usr/local/pkg/ocicertsync/ocicertsync_cron.php
        ;;
    *)
        echo "unknown action: $ACTION" >&2
        exit 1
        ;;
esac
