<?php
/*
 * ocicertsync.inc
 *
 * Core logic for the OCI Cert Sync pfSense add-on: pulls a certificate out
 * of the pfSense Certificate Manager and rotates it into OCI Certificate
 * Manager (Certificates Management service) as a new certificate version.
 *
 * DO NOT require guiconfig.inc from this file -- it gets pulled in during
 * filter rule generation (discover_pkg_rules()) and that hangs pf reload,
 * same reason pkg_ocicertsync_tabs.inc avoids it. Only certs.inc / config
 * access and PHP's openssl/curl extensions are used here.
 */

namespace pfsense_pkg\ocicertsync;

require_once('certs.inc');

const OCICERTSYNC_CRON_CMD = '/usr/local/pkg/ocicertsync/ocicertsync_command.sh "syncall" | /usr/bin/logger -t ocicertsync 2>&1';
const OCICERTSYNC_API_VERSION = '20210224';
const OCICERTSYNC_LOG_FILE = '/var/log/ocicertsync.log';
const OCICERTSYNC_LOG_MAX_BYTES = 2097152; // 2 MiB, rotated to .0 past this

/* ---------------------------------------------------------------------
 * Logging -- a dedicated, detailed log so a specific sync attempt can be
 * traced back to exactly which certificate (by subject/serial/fingerprint)
 * was pushed where, independent of the one-line status kept in config.xml.
 * ------------------------------------------------------------------ */

/*
 * Format a message as a standard BSD syslog (RFC 3164) line -- the same
 * "Mmm dd hh:mm:ss host tag[pid]: message" shape used by pfSense's own
 * /var/log/*.log files, so ocicertsync.log can be tailed, rotated, or
 * shipped by any ordinary syslog-aware tool without special-casing it.
 */
function ocicertsync_syslog_line($message) {
    $hostname = php_uname('n');
    $dotPos = strpos($hostname, '.');
    if ($dotPos !== false) {
        $hostname = substr($hostname, 0, $dotPos);
    }

    $now = time();
    // Day-of-month is space-padded (not zero-padded) per RFC 3164.
    $timestamp = date('M', $now) . sprintf('%3d', (int)date('j', $now)) . ' ' . date('H:i:s', $now);
    $pid = getmypid();

    return "{$timestamp} {$hostname} ocicertsync[{$pid}]: {$message}";
}

function ocicertsync_log($message) {
    if (file_exists(OCICERTSYNC_LOG_FILE) && filesize(OCICERTSYNC_LOG_FILE) > OCICERTSYNC_LOG_MAX_BYTES) {
        @rename(OCICERTSYNC_LOG_FILE, OCICERTSYNC_LOG_FILE . '.0');
    }

    $line = ocicertsync_syslog_line($message) . "\n";
    $fh = @fopen(OCICERTSYNC_LOG_FILE, 'a');
    if ($fh) {
        flock($fh, LOCK_EX);
        fwrite($fh, $line);
        flock($fh, LOCK_UN);
        fclose($fh);
        @chmod(OCICERTSYNC_LOG_FILE, 0600);
    }

    if (function_exists('log_error')) {
        log_error('[ocicertsync] ' . $message);
    }
}

function ocicertsync_log_tail($maxLines = 300) {
    if (!file_exists(OCICERTSYNC_LOG_FILE)) {
        return array();
    }
    $lines = file(OCICERTSYNC_LOG_FILE, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return array();
    }
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }
    return array_reverse($lines);
}

/* ---------------------------------------------------------------------
 * Config helpers
 * ------------------------------------------------------------------ */

function ocicertsync_init_config() {
    global $config;
    if (!is_array($config['installedpackages']['ocicertsync'])) {
        $config['installedpackages']['ocicertsync'] = array();
    }
    if (!is_array($config['installedpackages']['ocicertsync']['mappings'])) {
        $config['installedpackages']['ocicertsync']['mappings'] = array('item' => array());
    }
    if (!is_array($config['installedpackages']['ocicertsync']['mappings']['item'])) {
        $config['installedpackages']['ocicertsync']['mappings']['item'] = array();
    }
    if (!is_array($config['installedpackages']['ocicertsync']['accounts'])) {
        $config['installedpackages']['ocicertsync']['accounts'] = array('item' => array());
    }
    if (!is_array($config['installedpackages']['ocicertsync']['accounts']['item'])) {
        $config['installedpackages']['ocicertsync']['accounts']['item'] = array();
    }
    ocicertsync_migrate_to_accounts();
}

/*
 * One-time migration: older versions of this package kept a single global
 * OCI identity (tenancy/user/fingerprint/key/region/realm/stage) directly
 * under top-level settings. Multiple accounts are now supported, each
 * mapping picks one -- so on first load after upgrading, turn the old
 * global identity into an account named "Default (migrated)" and point any
 * mapping that doesn't already specify one at it. Idempotent: only acts
 * (and only writes config) the first time it finds leftover legacy fields.
 */
function ocicertsync_migrate_to_accounts() {
    global $config;
    $settings = &$config['installedpackages']['ocicertsync'];

    $hasLegacyCreds = !empty($settings['tenancyocid']) || !empty($settings['privatekey']);
    if (!$hasLegacyCreds) {
        return;
    }

    $migratedId = 'acct_migrated';
    $exists = false;
    foreach ($settings['accounts']['item'] as $acct) {
        if (isset($acct['id']) && $acct['id'] === $migratedId) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $settings['accounts']['item'][] = array(
            'id'             => $migratedId,
            'name'           => 'Default (migrated)',
            'tenancyocid'    => $settings['tenancyocid'] ?? '',
            'userocid'       => $settings['userocid'] ?? '',
            'fingerprint'    => $settings['fingerprint'] ?? '',
            'privatekey'     => $settings['privatekey'] ?? '',
            'privatekeypass' => $settings['privatekeypass'] ?? '',
            'region'         => $settings['region'] ?? '',
            'realmdomain'    => $settings['realmdomain'] ?? '',
            'stage'          => !empty($settings['stage']) ? $settings['stage'] : 'CURRENT',
        );
    }

    if (is_array($settings['mappings']['item'])) {
        foreach ($settings['mappings']['item'] as &$mapping) {
            if (empty($mapping['account'])) {
                $mapping['account'] = $migratedId;
            }
        }
        unset($mapping);
    }

    unset(
        $settings['tenancyocid'], $settings['userocid'], $settings['fingerprint'],
        $settings['privatekey'], $settings['privatekeypass'], $settings['region'],
        $settings['realmdomain'], $settings['stage']
    );

    write_config('OCI Cert Sync: migrated single OCI identity into an account.');
    ocicertsync_log('MIGRATION action=migrate_to_accounts created account id=' . $migratedId . ' name="Default (migrated)"');
}

function &ocicertsync_settings() {
    global $config;
    ocicertsync_init_config();
    return $config['installedpackages']['ocicertsync'];
}

function &ocicertsync_mappings() {
    global $config;
    ocicertsync_init_config();
    return $config['installedpackages']['ocicertsync']['mappings']['item'];
}

function &ocicertsync_accounts() {
    global $config;
    ocicertsync_init_config();
    return $config['installedpackages']['ocicertsync']['accounts']['item'];
}

function ocicertsync_get_account($id) {
    global $config;
    ocicertsync_init_config();
    if (empty($id)) {
        return null;
    }
    foreach ($config['installedpackages']['ocicertsync']['accounts']['item'] as $acct) {
        if (isset($acct['id']) && $acct['id'] === $id) {
            return $acct;
        }
    }
    return null;
}

/* Is this account referenced by any certificate mapping? Used to block
 * deleting an account still in use, instead of silently orphaning mappings. */
function ocicertsync_account_in_use($id) {
    global $config;
    ocicertsync_init_config();
    $using = array();
    foreach ($config['installedpackages']['ocicertsync']['mappings']['item'] as $mapping) {
        if (isset($mapping['account']) && $mapping['account'] === $id) {
            $using[] = $mapping['certref'];
        }
    }
    return $using;
}

/* ---------------------------------------------------------------------
 * Cron scheduling
 * ------------------------------------------------------------------ */

function ocicertsync_interval_to_cron($interval) {
    switch ($interval) {
        case '15min':
            return array('minute' => '0,15,30,45', 'hour' => '*');
        case '30min':
            return array('minute' => '0,30', 'hour' => '*');
        case 'hourly':
            return array('minute' => '0', 'hour' => '*');
        case 'daily':
        default:
            return array('minute' => '17', 'hour' => '3');
    }
}

function ocicertsync_set_cronjob() {
    $settings = ocicertsync_settings();
    $enabled = !empty($settings['enable']);
    $cron = ocicertsync_interval_to_cron(!empty($settings['interval']) ? $settings['interval'] : 'daily');
    install_cron_job(OCICERTSYNC_CRON_CMD, $enabled, $cron['minute'], $cron['hour']);
}

function ocicertsync_remove_cronjob() {
    install_cron_job(OCICERTSYNC_CRON_CMD, false);
}

/* ---------------------------------------------------------------------
 * Certificate export (pfSense Cert Manager -> leaf/chain/key PEM)
 * ------------------------------------------------------------------ */

function ocicertsync_export_cert($certref) {
    global $config;

    if (empty($config['cert']) || !is_array($config['cert'])) {
        return array(false, 'No certificates found in the pfSense Certificate Manager.');
    }

    $found = null;
    foreach ($config['cert'] as $cert) {
        if (isset($cert['refid']) && $cert['refid'] === $certref) {
            $found = $cert;
            break;
        }
    }

    if ($found === null) {
        return array(false, "Certificate (refid {$certref}) no longer exists in Cert Manager.");
    }
    if (empty($found['crt']) || empty($found['prv'])) {
        return array(false, "Certificate '{$found['descr']}' is missing its public certificate or private key.");
    }

    $crtDecoded = base64_decode($found['crt']);
    $keyDecoded = base64_decode($found['prv']);

    // ACME-issued certs typically store the full chain (leaf + intermediates)
    // concatenated in the 'crt' field. Split it: block 0 is the leaf, the
    // rest form the chain OCI expects separately.
    preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $crtDecoded, $matches);
    $blocks = $matches[0];

    if (count($blocks) < 1) {
        return array(false, "Could not parse PEM certificate data for '{$found['descr']}'.");
    }

    $leafPem = trim($blocks[0]) . "\n";
    $chainBlocks = array_slice($blocks, 1);

    // Collect every distinct chain certificate from both possible sources:
    // 1) intermediates concatenated directly into 'crt' (typical of ACME's
    //    fullchain.pem), and 2) pfSense's own CA Manager, walked all the way
    // up through nested 'caref' links via the native ca_chain() helper --
    // this is what actually reaches a self-signed root in a multi-level
    // hierarchy (leaf -> intermediate -> intermediate -> root), rather than
    // stopping at the certificate's immediate parent.
    $chainParts = array();
    $seen = array();

    foreach ($chainBlocks as $block) {
        $norm = trim($block);
        if ($norm !== '' && !isset($seen[$norm])) {
            $chainParts[] = $norm;
            $seen[$norm] = true;
        }
    }

    if (!empty($found['caref'])) {
        $caChainPem = ca_chain($found);
        if (!empty($caChainPem)) {
            preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $caChainPem, $caMatches);
            foreach ($caMatches[0] as $block) {
                $norm = trim($block);
                if ($norm !== '' && !isset($seen[$norm])) {
                    $chainParts[] = $norm;
                    $seen[$norm] = true;
                }
            }
        }
    }

    if (empty($chainParts)) {
        return array(false, "No intermediate/root certificate found for '{$found['descr']}'. " .
            "OCI's Certificates service requires a chain for imported certificates. Import the " .
            "issuing CA chain under System > Cert. Manager > Certificate Authorities and link it " .
            "to this certificate.");
    }

    $chainPem = implode("\n", $chainParts) . "\n";

    return array(true, array(
        'descr'      => $found['descr'],
        'leafPem'    => $leafPem,
        'chainPem'   => $chainPem,
        'chainParts' => $chainParts,
        'keyPem'     => trim($keyDecoded) . "\n",
        // Hash covers leaf + chain so a chain-only change (e.g. Let's
        // Encrypt rotating its intermediate CA) is still detected as a
        // change, even if the leaf certificate itself is unchanged.
        'hash'       => hash('sha256', $leafPem . $chainPem),
    ));
}

/* ---------------------------------------------------------------------
 * Certificate identification (for logging -- "which physical cert was
 * this?", independent of pfSense's internal refid).
 * ------------------------------------------------------------------ */

function ocicertsync_describe_pem($pem) {
    $parsed = @openssl_x509_parse($pem);
    if ($parsed === false) {
        return array('subject' => 'unparseable', 'serial' => '', 'fingerprint' => '', 'expires' => '');
    }

    $subject = !empty($parsed['subject']['CN']) ? ('CN=' . $parsed['subject']['CN']) : ($parsed['name'] ?? 'unknown');
    $serial = isset($parsed['serialNumberHex']) ? $parsed['serialNumberHex'] : (isset($parsed['serialNumber']) ? (string)$parsed['serialNumber'] : '');
    $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');
    $expires = isset($parsed['validTo_time_t']) ? gmdate('Y-m-d', $parsed['validTo_time_t']) : '';

    return array(
        'subject'     => $subject,
        'serial'      => $serial,
        'fingerprint' => $fingerprint !== false ? $fingerprint : '',
        'expires'     => $expires,
    );
}

/* ---------------------------------------------------------------------
 * OCI request signing (RSA-SHA256, OCI's HTTP signature scheme) + call
 * ------------------------------------------------------------------ */

function ocicertsync_sign_request($method, $path, $host, $bodyBytes, $settings) {
    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $hasBody = ($bodyBytes !== null);

    $lines = array();
    $lines[] = '(request-target): ' . strtolower($method) . ' ' . $path;
    $lines[] = 'date: ' . $date;
    $lines[] = 'host: ' . $host;
    $headerNames = '(request-target) date host';

    $xcs256 = null;
    $contentLen = null;
    if ($hasBody) {
        $xcs256 = base64_encode(hash('sha256', $bodyBytes, true));
        $contentLen = strlen($bodyBytes);
        $lines[] = 'x-content-sha256: ' . $xcs256;
        $lines[] = 'content-type: application/json';
        $lines[] = 'content-length: ' . $contentLen;
        $headerNames .= ' x-content-sha256 content-type content-length';
    }
    $signingString = implode("\n", $lines);

    $privateKeyPem = base64_decode($settings['privatekey']);
    $passphrase = !empty($settings['privatekeypass']) ? $settings['privatekeypass'] : null;
    $pkey = openssl_pkey_get_private($privateKeyPem, $passphrase);
    if ($pkey === false) {
        return array(false, 'Failed to load the OCI API private key (check the key and passphrase in Settings). ' .
            openssl_error_string());
    }

    $signature = '';
    $signOk = openssl_sign($signingString, $signature, $pkey, OPENSSL_ALGO_SHA256);
    if (!$signOk) {
        return array(false, 'Failed to sign the OCI API request. ' . openssl_error_string());
    }
    $sigB64 = base64_encode($signature);

    $keyId = $settings['tenancyocid'] . '/' . $settings['userocid'] . '/' . $settings['fingerprint'];
    $authHeader = 'Signature version="1",keyId="' . $keyId . '",algorithm="rsa-sha256",headers="' .
        $headerNames . '",signature="' . $sigB64 . '"';

    $headers = array(
        'Authorization: ' . $authHeader,
        'date: ' . $date,
        'host: ' . $host,
    );
    if ($hasBody) {
        $headers[] = 'x-content-sha256: ' . $xcs256;
        $headers[] = 'content-type: application/json';
        $headers[] = 'content-length: ' . $contentLen;
    }

    return array(true, $headers);
}

function ocicertsync_realm_domain($settings) {
    // Second-level domain for the OCI realm. Defaults to the public
    // commercial realm (OC1). Dedicated Region Cloud@Customer / government /
    // sovereign realms use a different domain (e.g. OC9 = oraclecloud9.com)
    // -- see https://docs.oracle.com/en-us/iaas/Content/General/Concepts/dedicatedregions.htm
    return !empty($settings['realmdomain']) ? $settings['realmdomain'] : 'oraclecloud.com';
}

function ocicertsync_oci_update_certificate($ociOcid, $leafPem, $chainPem, $keyPem, $versionName, $stage, $settings) {
    $host = 'certificatesmanagement.' . $settings['region'] . '.oci.' . ocicertsync_realm_domain($settings);
    $path = '/' . OCICERTSYNC_API_VERSION . '/certificates/' . $ociOcid;

    $body = json_encode(array(
        'certificateConfig' => array(
            'configType'     => 'IMPORTED',
            'certificatePem' => $leafPem,
            'certChainPem'   => $chainPem,
            'privateKeyPem'  => $keyPem,
            'versionName'    => $versionName,
            'stage'          => $stage,
        ),
    ), JSON_UNESCAPED_SLASHES);

    list($signOk, $headersOrError) = ocicertsync_sign_request('PUT', $path, $host, $body, $settings);
    if (!$signOk) {
        return array(false, 0, $headersOrError);
    }

    $ch = curl_init("https://{$host}{$path}");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headersOrError);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErrno !== 0) {
        return array(false, 0, "curl error ({$curlErrno}): {$curlErr}");
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return array(true, $httpCode, $response);
    }

    return array(false, $httpCode, $response);
}

/* ---------------------------------------------------------------------
 * Sync orchestration
 * ------------------------------------------------------------------ */

/*
 * $status is one of 'ok' (an actual rotation happened), 'skipped' (checked,
 * no change, nothing pushed), or 'error'. 'lastsync' only advances on a real
 * rotation; 'lastcheck' advances on every attempt, so the GUI can show both
 * "last time it actually changed something" and "last time it looked".
 */
function ocicertsync_history_set(&$mapping, $status, $message, $versionName = '') {
    $mapping['lastcheck'] = time();
    if ($status === 'ok') {
        $mapping['lastsync'] = time();
    }
    $mapping['laststatus'] = $status;
    $mapping['lastmessage'] = substr((string)$message, 0, 500);
    if ($versionName !== '') {
        $mapping['lastversion'] = $versionName;
    }
}

/*
 * Sync one mapping row. Returns true unless an error occurred (a no-op
 * "nothing changed" run counts as success). $mapping is updated in place
 * with the outcome; caller is responsible for write_config().
 *
 * $force = true pushes a new certificate version to OCI even if the
 * leaf+chain hash matches the last successful sync -- normal syncs (cron
 * and the GUI's "Sync Now") should leave this false so an unchanged
 * certificate never mints a needless new OCI certificate version.
 */
function ocicertsync_sync_one(&$mapping, $force = false) {
    $tag = "cert={$mapping['certref']} account={$mapping['account']} ociocid={$mapping['ociocid']}";

    if (empty($mapping['account'])) {
        ocicertsync_history_set($mapping, 'error', 'No OCI account selected for this mapping -- edit it and choose one.');
        ocicertsync_log("{$tag} action=abort reason=\"no OCI account selected\"");
        return false;
    }

    $account = ocicertsync_get_account($mapping['account']);
    if ($account === null) {
        ocicertsync_history_set($mapping, 'error', "OCI account '{$mapping['account']}' no longer exists -- edit this mapping and choose another.");
        ocicertsync_log("{$tag} action=abort reason=\"account no longer exists\"");
        return false;
    }

    if (empty($account['tenancyocid']) || empty($account['userocid']) ||
        empty($account['fingerprint']) || empty($account['privatekey']) ||
        empty($account['region'])) {
        ocicertsync_history_set($mapping, 'error', "OCI account '{$account['name']}' is incomplete -- check the OCI Accounts tab.");
        ocicertsync_log("{$tag} action=abort reason=\"account '{$account['name']}' incomplete\"");
        return false;
    }

    if (empty($mapping['ociocid'])) {
        ocicertsync_history_set($mapping, 'error', 'No OCI certificate OCID configured for this mapping.');
        ocicertsync_log("{$tag} action=abort reason=\"no OCI certificate OCID configured\"");
        return false;
    }

    list($exportOk, $data) = ocicertsync_export_cert($mapping['certref']);
    if (!$exportOk) {
        ocicertsync_history_set($mapping, 'error', $data);
        ocicertsync_log("{$tag} action=abort reason=\"{$data}\"");
        return false;
    }

    $leafInfo = ocicertsync_describe_pem($data['leafPem']);
    $chainDescrs = array();
    foreach ($data['chainParts'] as $chainPart) {
        $ci = ocicertsync_describe_pem($chainPart);
        $chainDescrs[] = $ci['subject'];
    }
    $certLine = "{$tag} account_name=\"{$account['name']}\" pfsense_descr=\"{$data['descr']}\" leaf_subject=\"{$leafInfo['subject']}\" " .
        "leaf_serial={$leafInfo['serial']} leaf_sha256={$leafInfo['fingerprint']} leaf_expires={$leafInfo['expires']} " .
        'chain_count=' . count($data['chainParts']) . ' chain_subjects="' . implode(' -> ', $chainDescrs) . '"';

    if (!$force && !empty($mapping['lasthash']) && $mapping['lasthash'] === $data['hash']) {
        // Certificate + chain are unchanged since the last successful sync.
        ocicertsync_history_set($mapping, 'skipped', 'No changes detected (certificate and chain both unchanged); nothing pushed to OCI.');
        ocicertsync_log("{$certLine} action=skip reason=\"no change since last sync\"");
        return true;
    }

    $stage = !empty($mapping['stage']) ? $mapping['stage'] : (!empty($account['stage']) ? $account['stage'] : 'CURRENT');
    $versionName = 'letsencrypt-' . gmdate('Ymd\THis\Z');

    list($callOk, $httpCode, $response) = ocicertsync_oci_update_certificate(
        $mapping['ociocid'], $data['leafPem'], $data['chainPem'], $data['keyPem'],
        $versionName, $stage, $account
    );

    if ($callOk) {
        $mapping['lasthash'] = $data['hash'];
        ocicertsync_history_set($mapping, 'ok',
            "Rotated to version '{$versionName}' (stage={$stage}, http={$httpCode}).", $versionName);
        ocicertsync_log("{$certLine} action=rotate version={$versionName} stage={$stage} http={$httpCode} result=ok");
    } else {
        ocicertsync_history_set($mapping, 'error', "HTTP {$httpCode}: " . substr((string)$response, 0, 400));
        $shortResp = str_replace(array("\r", "\n"), ' ', substr((string)$response, 0, 300));
        ocicertsync_log("{$certLine} action=rotate version={$versionName} stage={$stage} http={$httpCode} result=error response=\"{$shortResp}\"");
    }

    return $callOk;
}

/* Sync every configured mapping and persist the results. */
function ocicertsync_sync_all($force = false) {
    global $config;
    ocicertsync_init_config();

    $results = array();
    $mappings = &$config['installedpackages']['ocicertsync']['mappings']['item'];
    foreach ($mappings as &$mapping) {
        $ok = ocicertsync_sync_one($mapping, $force);
        $results[] = array(
            'certref' => $mapping['certref'],
            'ok'      => $ok,
            'message' => $mapping['lastmessage'],
        );
    }
    unset($mapping);

    write_config('OCI Cert Sync: ran certificate sync.');
    return $results;
}

/* ---------------------------------------------------------------------
 * Install / deinstall (invoked directly by install.sh / uninstall.sh --
 * this package is registered via the ext-menu drop-in, not a real
 * pfSense-pkg-* install, so there is no packagegui installer to call these
 * for us).
 * ------------------------------------------------------------------ */

function ocicertsync_install() {
    ocicertsync_init_config();
    ocicertsync_set_cronjob();
    write_config('OCI Cert Sync: package files installed.');
}

function ocicertsync_deinstall() {
    ocicertsync_remove_cronjob();
    write_config('OCI Cert Sync: package removed.');
}
