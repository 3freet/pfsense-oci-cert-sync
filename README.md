# OCI Cert Sync for pfSense

A pfSense GUI add-on that keeps certificates from the pfSense **Certificate
Manager** (including ones renewed automatically by the **ACME** package via
Let's Encrypt) in sync with **OCI Certificate Manager** (Certificates
Management service), across as many OCI tenancies/accounts as you need.

Adds a **Services > OCI Cert Sync** menu entry with four tabs:

- **Settings** — global enable switch + sync schedule (cron interval).
- **OCI Accounts** — one entry per OCI tenancy/credential set (tenancy/user
  OCID, API signing key, region, realm domain, default rotation stage).
  Supports standard commercial OCI, government/sovereign realms, and
  Dedicated Region Cloud@Customer deployments (different realm second-level
  domain, e.g. `oraclecloud9.com`).
- **Certificate Mappings** — pick a pfSense certificate, an OCI account, and
  the target OCI certificate OCID. Manual **Sync Now** / **Force Resync**
  actions per row, plus bulk **Sync All Now**.
- **Sync Log** — a readable, syslog-formatted history of every sync attempt
  (skipped/rotated/failed), so a specific push can be traced back to exactly
  which certificate (subject, serial, SHA-256 fingerprint) went where.

## Why this exists

pfSense's ACME package renews certificates locally, but doesn't push them
anywhere else. This add-on closes that gap for OCI: it watches the
certificates you map, and whenever the certificate **or its CA chain**
changes, it pushes a new certificate version to OCI Certificate Manager.
Unchanged certificates are skipped automatically — normal syncs never mint
a needless new OCI certificate version.

## How it works

- Reads the certificate, private key, and full CA chain straight out of
  pfSense's own Certificate Manager (`$config['cert']` / `$config['ca']`),
  walking the chain all the way to a self-signed root via pfSense's native
  `ca_chain()` helper — this correctly handles multi-level CA hierarchies,
  not just a certificate's immediate parent.
- Signs OCI REST API requests itself (RSA-SHA256, OCI's HTTP signature
  scheme) using PHP's built-in `openssl`/`curl` extensions. No OCI CLI,
  Python, or other non-base dependency required.
- Calls OCI's Certificates Management `UpdateCertificate` API
  (`PUT /20210224/certificates/{certificateId}`) to add a new certificate
  version with `configType: IMPORTED`.

## Not a real pfSense package (and that's on purpose)

This isn't distributed as an installable `.pkg` through Netgate's package
repository — that requires their review/signing process and isn't a fit for
a personal/internal tool. Instead, it uses pfSense's built-in **ext-menu**
mechanism: any XML file under `/usr/local/share/pfSense/menu/` containing a
`<menu>` block is automatically picked up on every page load and added to
the navbar, no package database registration required. `install.sh` copies
the PHP/XML files into the same locations a real package would use
(`/usr/local/pkg/ocicertsync/`, `/usr/local/www/ocicertsync/`) and registers
the initial config, but there's no dependency on FreeBSD's `pkg` database.

## Requirements

- pfSense CE 2.7.x (developed/tested against 2.7.2, PHP 8.2). Should work
  on nearby versions since it only uses long-standing pfSense internals
  (`certs.inc`, `guiconfig.inc`, `Form`/`Form_Section` classes,
  `install_cron_job()`), but hasn't been verified across versions.
- An OCI tenancy with the Certificates service, and for each certificate you
  want to sync, that certificate resource must already exist in OCI
  Certificate Manager (create it once via the console or
  `oci certs-mgmt certificate create-by-importing-config`). This tool only
  **rotates** existing OCI certificates — it doesn't create them.
- An OCI IAM user (ideally a dedicated automation/service account) with an
  API signing key pair, and a policy granting it permission to manage
  certificates in the target compartment, e.g.:
  ```
  Allow group <your-automation-group> to manage leaf-certificate-family in compartment <compartment>
  ```
  Scope this down further once you've confirmed it works.

## Install

### 1. Enable SSH access on pfSense (if not already on)

**System > Advanced > Admin Access > Secure Shell** — check "Enable Secure
Shell", Save. Key-based login is recommended over password auth: on the
same page (or **System > User Manager > Users > <your admin user> >
Authorized SSH Keys**) paste a public key so you can `ssh`/`scp` in without
typing a password each time.

### 2. Copy the files to pfSense and run the installer

```sh
scp -r pfsense-oci-cert-sync-pkg admin@<pfsense-host>:/root/
ssh admin@<pfsense-host> "/root/pfsense-oci-cert-sync-pkg/install.sh"
```

(Use whichever admin-privileged account you SSH in as — `root` or
`admin`, both work identically on pfSense.)

`install.sh` copies the `.inc`/`.php` files into
`/usr/local/pkg/ocicertsync/` and `/usr/local/www/ocicertsync/`, drops the
menu-registration XML into `/usr/local/share/pfSense/menu/`, and seeds an
empty config section — it does **not** touch any existing certificates,
OCI credentials, or config.xml sections other than its own.

You can re-run `install.sh` any time (e.g. after pulling an update) — it
overwrites the package's own files in place and leaves your saved
Settings/Accounts/Mappings untouched.

### 3. Confirm it installed

Reload the pfSense GUI (a hard refresh if the navbar was already open) and
look for **Services > OCI Cert Sync**. If it's missing:

```sh
ssh admin@<pfsense-host> "ls -la /usr/local/share/pfSense/menu/ocicertsync.xml /usr/local/pkg/ocicertsync/ /usr/local/www/ocicertsync/"
ssh admin@<pfsense-host> "php -l /usr/local/pkg/ocicertsync/ocicertsync.inc"
```

confirms the files landed and the core logic file is syntactically valid.

### 4. Configure

1. **OCI Accounts** tab — Add an account: name, tenancy/user OCID,
   fingerprint, private key (PEM, pasted directly — never redisplayed once
   saved), region, realm domain (leave as `oraclecloud.com` unless you're on
   a government/sovereign/dedicated realm), and a default rotation stage.
2. **Certificate Mappings** tab — Add a mapping: pick the pfSense
   certificate, the OCI account to use, and the target OCI certificate
   OCID.
3. Click **Sync Now** on that mapping and confirm it reports success before
   relying on the schedule.
4. **Settings** tab — enable scheduled sync and pick an interval.
5. **Sync Log** tab — check here (or `/var/log/ocicertsync.log` over SSH,
   already in standard syslog format) any time you need to see exactly
   what happened on a given run.

### Uninstall

```sh
ssh root@<pfsense-host> "/root/pfsense-oci-cert-sync-pkg/uninstall.sh"
```

Removes the installed files and cron job. Saved Settings/Accounts/Mappings
stay in `config.xml` (harmless once the files are gone) — reinstalling
picks them back up as-is.

## Caveats

- If your OCI Load Balancer listener references this certificate by a
  **pinned version number** rather than "current", a rotation here won't
  take effect on the LB without an additional step (not currently handled
  by this tool — it only manages the Certificates service resource).
- The private key for each OCI account is stored base64-encoded in
  `config.xml`, the same way pfSense stores its own certificate private
  keys — protect your config backups accordingly.
- Only tested against a single pfSense CE 2.7.2 dev instance (including one
  OCI Dedicated Region Cloud@Customer tenancy). Please verify carefully
  before relying on this for anything production-critical.

