# CWP Hosting Module for WHMCS

A provisioning module for [Control Web Panel](https://control-webpanel.com/), driving
CWP's external API.

Drop-in replacement for the stock `cwp7` module: same directory, same module type, same
config option order. Existing server entries, products and services keep working with no
reconfiguration.

**Version 2.0.0** · MIT licensed · WHMCS 8.5–9.0 · PHP 7.4–8.3

---

## What it does

| | |
|---|---|
| Provisioning | create, suspend, unsuspend, terminate |
| Account management | change password, change package and resource limits |
| Single sign-on | one-click panel login for clients and admins |
| Usage | daily disk and bandwidth import |
| Server Sync | list and import accounts that already exist on the server |
| Admin | connection test, live account detail on the service page, panel links |

---

## Requirements

- WHMCS 8.5 or later (tested through 9.0)
- PHP 7.4 to 8.3, with `curl` and `json`

---

## Install

1. Extract the archive into `<whmcs>/modules/servers/`, giving
   `<whmcs>/modules/servers/cwp7/cwp7.php`.

2. Create an API key in **CWP → Settings → API Manager**. See
   [PERMISSIONS.md](PERMISSIONS.md) for the exact grants, the response-format setting and
   the IP whitelist — most setup problems are one of those three.

3. In WHMCS, go to **Servers → Add New Server**:
   - **Type:** `Control Web Panel (CWP) - Community Edition`
   - **Hostname:** the CWP hostname. Port defaults to 2304.
   - **Access Hash:** the CWP API key. Username and password are not used by the API.

4. Press **Test Connection**. Do not continue until it passes.

5. On the product, open **Module Settings** and set the CWP package and limits.

Optionally copy `config.sample.php` to `config.php` to change TLS policy, ports or
timeouts. Without it the module runs on the defaults in that file, which suit most
installations.

### Applying package changes from the dropdown

By default a package reaches CWP when an upgrade order is paid or an admin presses
**Change Package**; changing the Product/Service dropdown alone only updates the WHMCS
record. To make the dropdown sufficient, set in `config.php`:

```php
'apply_package_on_service_save' => true,
```

WHMCS registers a module's hook file when the module is activated, so on an existing
install open any CWP product's **Module Settings** tab and press **Save Changes** once for
`hooks.php` to be picked up. It is off by default because reconfiguring a live hosting
account as a side effect of correcting a product record is a surprise.

While it is on, the **Change Package** button is hidden on CWP services, since the
dropdown now does the same job. The button is only hidden, never removed — a paid
upgrade or downgrade order calls the same code, and dropping it would bill a customer for
a package the server never applies. Turn the setting off and the button returns.

## Upgrading from the stock module

Replace the directory contents. Nothing else changes:

- The module type stays `cwp7`, so server entries and products need no edit.
- Config option order is unchanged — package, inode, nofile, nproc — and existing values
  carry over. Max Username Length is appended and defaults to CWP's limit of 8.
- Existing accounts are addressed by the username already stored on the service, so
  services created by the old module keep working.

One change can stop a previously "working" install: **TLS verification is now on.** The
old module disabled it on every call while sending an API key with administrative scope
over every account on the server. If CWP serves a certificate from a public CA on port
2304, nothing more is needed. If it is self-signed, see below.

---

## TLS

Check what CWP presents before changing anything:

```bash
echo | openssl s_client -connect cwp.example.com:2304 -servername cwp.example.com \
  2>/dev/null | openssl x509 -noout -subject -issuer -dates -ext subjectAltName
```

Read the **issuer**. If it is a public CA, the certificate is fine — and if verification
still fails, the problem is this server's CA store (`ca-certificates`, or `curl.cainfo`
in `php.ini`), not the certificate. Both faults produce the same
`unable to get local issuer certificate` message, so check before acting.

Also confirm the **subject or SAN covers the hostname on the server entry**. Verification
checks the name as well as the chain.

For a genuinely self-signed certificate, export and pin it:

```bash
openssl s_client -connect cwp.example.com:2304 -showcerts </dev/null 2>/dev/null \
  | openssl x509 -outform PEM > /etc/ssl/certs/cwp-pinned.pem
```

```php
// config.php
'ca_bundle' => '/etc/ssl/certs/cwp-pinned.pem',
```

Per server, if you run several:

```php
'servers' => [
    'cwp1.example.com' => ['ca_bundle' => '/etc/ssl/certs/cwp1-pinned.pem'],
],
```

---

## Troubleshooting

Start with **Utilities → Logs → Module Log**. Every API call is recorded there with
credentials masked.

**"Connection refused" on Test Connection.** Nothing answered on 2304, so TLS and the API
key are not yet involved. In order of likelihood:

1. Outbound 2304 is blocked on the WHMCS server. With CSF, add 2304 to `TCP_OUT` and run
   `csf -r`; its `DROP_OUT` default is `REJECT`, which is why this appears as an instant
   refusal rather than a timeout.
2. The hostname resolves differently from the WHMCS server than it does elsewhere —
   split-horizon DNS, or a name pointing at a NAT gateway with no port forward. The error
   names the address that was actually dialled; compare it against what you expect.
3. Inbound 2304 is not open on CWP, or `cwpsrv` is not listening.

**A timeout instead** means packets are being dropped rather than refused, which points
at an inbound firewall on the CWP side or in front of it.

**"Unauthorized action".** The API key is missing a grant. The error names the
`[function/action]` pair, which maps directly onto the API Manager grid — function down
the left, action across the top.

**Usage figures are not appearing.** Usage imports once daily per server via the WHMCS
cron. Force a run with:

```bash
php -q /path/to/whmcs/crons/cron.php do --UpdateServerUsage -vvv
```

then check the results:

```sql
SELECT id, domain, diskusage, disklimit, bwusage, bwlimit, lastupdate
FROM tblhosting WHERE server = <serverid>;
```

**Anything else.** Set `'debug' => true` in `config.php`; CWP then writes full request
detail to `/var/log/cwp/cwp_api.log` **on the CWP server**, including the source address
it saw and which permission check failed.

> **That log records your API key in plaintext.** The key is administrative over every
> account on the server, and the file is outside WHMCS's access controls. Use `debug` to
> diagnose one specific call, then switch it off, delete the log, and rotate the key.

The permission names CWP checks internally do not always match the API Manager labels —
`account`/`udp` is checked as `accout_upd`, CWP's own typo — so the debug log is the only
reliable way to see which grant a refused call actually wanted.

---

## Notes

- **AutoSSL is not triggered by this module.** CWP issues and renews those certificates
  on its own schedule, so the API key needs no AutoSSL permission.
- The module makes no API call while rendering the client area, so an unreachable panel
  cannot stall a customer's page.
- **Disk usage costs one extra API call per service, once a day.** CWP's account list
  reports a placeholder rather than real consumption, so the accurate figure is read per
  account — and only for accounts that match a WHMCS service on that server. Set
  `usage_detail_lookup => false` in `config.php` to skip it on a very large server and
  accept the placeholder.

---

## Support

- **Latest release:** <https://github.com/bradleygb/whmcs-cwp-module/releases/latest>
- **Bugs and feature requests:** <https://github.com/bradleygb/whmcs-cwp-module/issues>

When reporting a problem, include your WHMCS and PHP versions, the module version from
the file header, and the relevant entry from **Utilities → Logs → Module Log**. That log
masks credentials, but read it before pasting.

If the module cannot reach CWP at all, `tools/connectivity-check.php` in the repository
separates DNS, TCP and TLS faults and produces output that is safe to paste.

---

## Licence

MIT. See [LICENSE](LICENSE).
