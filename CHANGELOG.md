# Changelog

All notable changes to this module are documented here.

## 2.0.2

### Security

- **The module log no longer records the client's personal data.** WHMCS passes a `model`
  parameter carrying the service, its product and the full client record; a failed module
  command wrote the stored service password, the customer's name, postal address, phone
  number and last-login IP into the WHMCS Module Log. Logging now uses an allow-list of
  diagnostic fields, so nothing unanticipated is ever written.

### Fixed

- **Resource limits were never applied to new accounts.** CWP's `add` endpoint expects
  `limit_nofile` and `limit_nproc`; `udp` calls the same two limits `openfiles` and
  `processes`; the module sent `nofile` and `nproc`, which neither accepts. Every account
  created by earlier versions therefore carries its CWP package defaults rather than the
  product's open-file and process limits. **Run Change Package on existing services** to
  apply them.
- **Package changes were malformed and could not have worked.** CWP documents the package
  as *"name or ID with @ front"*; the module sent the value bare and then retried with the
  `@` appended. It also omitted the required `email`.
- **A package change is now verified.** `status OK` is not evidence, so the account is
  re-read afterwards and the operation fails loudly if CWP did not move it.
- **Account creation no longer times out at 20 seconds.** Reads keep the short budget;
  anything that changes the server gets `provision_timeout`, 180 seconds by default.
  Creating an account builds a user, home directory, vhost, DNS zone and mail
  configuration, and takes far longer than a read.
- **A creation that times out is reconciled.** CWP keeps working after the module gives
  up, which previously left an account on the server and a failed service in WHMCS. The
  account is now re-checked, and a creation that finished late is reported as the success
  it was.
- Error messages carry only advice that applies: the API Manager guidance appears solely
  for `Unauthorized action`, a missing account is stated plainly, and the private-address
  note no longer appears on a timeout, where the connection had in fact succeeded.

### Added

- `hooks.php` — optionally apply a package change to CWP when an admin changes a
  service's Product/Service and saves, instead of pressing Change Package afterwards.
  Off by default; enable `apply_package_on_service_save` in `config.php`.

## 2.0.1

### Fixed

- **Disk usage was imported as zero for every service.** CWP's `account/list` names the
  field `diskused`, which was not among the names the module looked for.
- **Disk usage now comes from `accountdetail`.** `account/list` reports a placeholder
  rather than real consumption — the same account shows `diskused => 1` there and
  `space_usage => 480715` on `accountdetail`. Bandwidth and all limits still come from
  the single list call, which reports them accurately.
- **Responses under `result` are read.** Current CWP builds return success payloads under
  `result`, older ones under `msj`, and errors under `msg`. All three are accepted;
  `account/list` and `accountdetail` use different keys as the same server.
- **`-1` is understood as unlimited** and stored as `0`, which is how WHMCS reads it. A
  negative limit previously rendered as a negative usage bar.
- Usage rows are matched to services by primary key rather than by a domain query, so a
  domain shared by two services can no longer have both rewritten.
- **Terminated, cancelled and fraud services no longer trigger API calls.** The admin
  service page called CWP on every view for a service whose account no longer exists,
  showing a rejection banner each time; usage import likewise treated dead services as
  candidates, letting one shadow a live service that reused the same domain.
- A missing account now reads as a plain statement rather than an error banner — that is
  an ordinary state, not a fault.

### Added

- `usage_detail_lookup` in `config.php` (default on). The accurate disk figure costs one
  extra API call per account **that matches a WHMCS service on that server** — accounts
  with no service are skipped before the call is made. Set to false to use the single
  list call and accept the placeholder figure.

## 2.0.0

A full rewrite of the CWP provisioning module. The module name, directory and function
prefix are unchanged, so this drops straight over version 1.7 with no reconfiguration:
server entries and products keep working, and no service needs re-linking.

**Requires PHP 7.4–8.3 and WHMCS 8.5–9.0.**

### Fixed

- **Usage reporting now works.** It matched `tblhosting.dedicatedip` against CWP's
  `ip_address`, but `dedicatedip` is empty for every shared-IP account, so the query
  matched no rows. It now scopes by server ID and domain, as WHMCS documents.
- **Invalid `lastupdate` timestamps.** Usage rows were written with
  `date('Y-m-d H:i:S')` — capital `S` is PHP's English ordinal suffix, producing values
  like `14:30:th`, which MySQL rejects in strict mode. Now written with `now()`.
- **`ChangePassword` sent a misspelled `acction` field**, so the action never reached
  CWP and the endpoint ran on its default behaviour.
- **Fatal `TypeError` in the daily cron.** `count()` was called on the API payload
  without checking it was an array; on PHP 8 a missing or renamed key aborted the cron
  run. All cron-facing paths are now exception-safe.
- **`curl_errno()` was checked before `curl_exec()`**, so transport errors were never
  reported.
- **Module functions could return an array** where WHMCS expects a string, producing
  "Array to string conversion" instead of a usable error.
- cURL handles are closed on every path.

### Security

- **TLS verification is enabled.** Previously `CURLOPT_SSL_VERIFYPEER` and
  `CURLOPT_SSL_VERIFYHOST` were disabled on every call, while the request body carried
  an API key with administrative scope over every account on the server.
- **The API key is no longer written to the WHMCS Module Log in plaintext.** It is
  masked in the request, passed to WHMCS's redaction, and stripped from CWP's own error
  text — CWP echoes the submitted key back inside `Unauthorized action` responses.
- **Autologin tokens are no longer rendered into page HTML.** A live session URL was
  minted on every product-details page load and printed into the page, leaving it in
  page source, browser history and referrer headers. Sessions are now minted on click
  through WHMCS single sign-on.
- Redirects are not followed and the transport is pinned to HTTPS, so a redirect cannot
  replay the API key to another host.
- Client-facing errors no longer carry raw CWP output, which can name other accounts and
  filesystem paths.

### Added

- `MetaData` — display name, API version, default port, single sign-on label.
- `TestConnection`, with failure messages that distinguish DNS, refused connections,
  timeouts and certificate faults, and name the address actually dialled.
- `ServiceSingleSignOn` for panel login from the client area and admin service page.
- `ListAccounts` for Server Sync, so existing CWP accounts can be listed and imported.
- `AdminServicesTabFields` showing live account detail on the admin service page.
- `Renew` as an explicit no-op.
- Client area overview output that makes no API call during page render.
- Product options for resource limits and a username length cap.
- Optional `config.php` for TLS policy, ports and timeouts.
- Support for both `msg` and `msj` response keys, which CWP has used at different times.

### Changed

- Usernames are corrected to CWP's rules for **new** accounts only, and the corrected
  value is written back to the service. Existing accounts are addressed exactly as
  stored, so services created by earlier versions are unaffected.
- `AdminLink` is a plain link to the CWP admin panel; it previously submitted an empty
  form with no credentials.
- Config option labels use `FriendlyName`. Option order is unchanged, so existing
  product settings carry over.

### Removed

- AutoSSL triggering. CWP issues and renews AutoSSL certificates on its own schedule, so
  the module does not call it and the API key needs no AutoSSL permission.

## 1.7 — 2020-03-30

Last release of the original module. Improved autologin to the user panel.
