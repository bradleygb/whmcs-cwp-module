# Changelog

All notable changes to this module are documented here.

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
