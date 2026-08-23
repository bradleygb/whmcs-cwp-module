# Changelog

All notable changes to this module are documented here.

## 2.4.0

### Added

- **Email accounts in the client area.** The dashboard now lists the account's mailboxes
  with their sizes, from `email`/`list`. No configuration, and no permission beyond
  `LIST` on `Emails`. A key without that grant simply shows no list.

- **`mailbox_management` in `config.php`** (default **off**). Turned on, customers can
  create mailboxes, change their passwords and delete them, without leaving WHMCS.

  **It is off deliberately, and should stay off until you have confirmed the contract on
  your own server.** The field names CWP expects on `email`/`add`, `udp` and `del` are
  taken from its conventions on other endpoints, not from its Interactive Documentation,
  which we have never seen for this endpoint. They are gathered in `Mailbox::FIELDS` so a
  correction is a single edit.

  Listing does not depend on the setting and is always available.

  Requires `ADD`, `UPD` and `DEL` on `Emails` in addition to `LIST`.

- **`tools/email-probe.php`** — prints what `email`/`list` actually returns for one
  account, and which fields the module recognises in it. Read-only: it calls nothing but
  `list`, so it cannot create or delete anything. Use it before enabling the setting.

- **A mailbox's size can be changed**, not only its password. Each row has an Edit action
  covering both; leaving either blank leaves that one alone.

### Fixed

- **CWP builds the address itself.** Its `email` field takes the part before the @, and it
  appends the domain — sending a whole address produced
  `testexample.co.za@example.co.za` from `test` and `example.co.za`. Every write now sends
  the local part and the domain apart.
- **A quota of `0` is no limit**, as it is everywhere else in CWP, and no longer renders
  as a zero-megabyte mailbox.

### Security

- Every mailbox request is checked against what the account actually holds before
  anything is sent. The hosting account comes from WHMCS, never the request; a named
  domain must appear in that account's own `accountdetail`; and an address named for a
  password change or deletion must already be one of that account's mailboxes. The API
  key reaches every mailbox on the server, so none of these is optional.
- Mailbox passwords are masked in the Module Log. They arrive in the request rather than
  in WHMCS's parameters, so the existing masking would not have caught them.

## 2.3.0

### Added

- **An account dashboard in the client area.** The service's product details page now
  shows the account as CWP sees it: package and state, disk and bandwidth against their
  limits, the email, FTP, database, subdomain and addon-domain allowances with what is
  used of each, and the full domain, subdomain and database lists.

  It costs one `accountdetail` call, made **after** the page renders rather than during
  it, so the details and the login button appear immediately and an unreachable panel
  leaves a short message instead of a stalled page. The module still opens no socket
  while the client area is being drawn.

  Nothing to configure, and no new permission: `Account Details`/`list` is already
  required for the admin service tab.

### Changed

- **The control panel shortcut tiles are gone**, one version after arriving. Every tile
  opened CWP's dashboard rather than its own section, and the dashboard above replaces
  what they were for. The single **Log in to Control Panel** button remains.

## 2.2.0

### Added

- **Control panel shortcuts in the client area.** Sixteen tiles below the account details
  — email accounts, forwarders, autoresponders, filters, FTP, backups, disk usage, cron,
  MySQL, phpMyAdmin, domains, subdomains, DNS, SSL, error log and statistics — each of
  which signs the customer in and opens that section of CWP directly.

  Every tile goes through WHMCS single sign-on. **No session is created while the page is
  drawn and no login token, URL or credential is written into it**; the session is minted
  when a tile is clicked, exactly as the existing Log In button does.

  The shortcut name arrives as a request parameter, so it is resolved through an
  allow-list of CWP module names in `lib/Actions/PanelApp.php`. Anything not on that list
  opens the panel dashboard rather than redirecting anywhere.

  The list is confined to features present on every CWP installation; paid add-ons such as
  Softaculous, SitePad, SpamExperts and Cloudflare are deliberately excluded, because a
  tile for something the server does not have is worse than no tile. A feature the
  account's package does not include opens CWP's own message saying so.

## 2.1.1

### Added

- **`apply_resource_limits` in `config.php`** (default on). The product's inode, open-file
  and process limits are applied through `account`/`udp` after a package change, and a
  refusal there has always been non-fatal — the package still moves. But some servers
  refuse that call **with Account/UPD granted in API Manager**, which put a failed call
  and a warning in the Module Log on every package change, for a grant that could not be
  obtained.

  Set it to false on such a server and the call is not made. The three limits then come
  from the CWP package alone and must be set in the panel if they matter.

## 2.1.0

### Added

- **Products can create their CWP packages.** Ten new product options describe the
  package — disk quota, bandwidth, and the FTP, email, email-list, database, subdomain,
  parked-domain, addon-domain and hourly-email limits. Saving the product creates the
  package on **every CWP server in that product's server group**, or updates it if one of
  that name already exists. No more building the same package by hand on each server.

  The product's **CWP Package** field is the package name. CWP's update endpoint
  identifies packages by name and each server assigns its own local id, so a name is the
  only identifier that stays stable across a group.

  Off by default — `push_packages_on_product_save` in `config.php`. Enabling it decides
  ownership: WHMCS becomes the source of truth, and a package edited in CWP is
  overwritten the next time its product is saved. Needs `ADD` and `UPD` on `Packages`.

  A limit left blank keeps CWP's own default; set it to `0` to mean none allowed.

- **The CWP Package field may be left blank**, in which case the product's own name is
  used — both when pushing the package and when provisioning against it. Setting it
  explicitly still overrides, for servers whose package names differ from your product
  names.

### Fixed

- **A package change failed whenever the package was set by name.** CWP's `changepack`
  takes a package id and nothing else — given a name it answers a bare `Error` with no
  explanation. Whatever the product holds, name or id, is now resolved against the
  server's own package list before anything is sent, so a name works and each server in a
  group can assign that package its own local id.

  This replaces the existence check added in 2.0.3, which confirmed the package was real
  and then sent the unusable form of it anyway. Resolution still fails open on a key
  without `list` on `Packages`, and an unknown package is still refused with the
  available ones named.

- **`tblservers.accesshash` is not encrypted on every install.** Where it holds the API
  key verbatim, running it through `DecryptPassword` does not fail — it reports success
  and returns binary noise, which CWP rejects as "No special characters are allowed!".
  A stored value that already looks like a key is now used as-is.

## 2.0.3

### Fixed

- **An unknown package ID no longer leaves an account with no package.** CWP's
  `changepack` answers `status OK` for an ID that does not exist, silently detaching the
  account from any package. The requested ID is now checked against the server's package
  list before anything changes, and a mismatch is refused with the valid IDs listed.
  Applies to account creation as well.

  The check needs `Packages`/`list` and fails open without it, so it never blocks a
  package change that would otherwise succeed.

### Changed

- **The Change Package button is hidden while `apply_package_on_service_save` is on**,
  since the Product/Service dropdown then does the same job. Hidden, not removed: WHMCS
  draws the button because `cwp7_ChangePackage` exists, and that function must stay —
  a paid upgrade or downgrade order calls it, and dropping it would bill a customer for a
  package the server never applies. Turning the setting off brings the button back.

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
- **Package changes used the wrong endpoint entirely.** CWP has a dedicated
  `/v1/changepack` for this, gated by the narrow "Account pack change" permission. The
  module posted to `/v1/account` with `action=udp` — a full account update, checked as a
  broader grant — and with the package suffixed `12@` where that endpoint documents a
  `@12` prefix. Package changes now go to `changepack`, which takes the bare ID.
- **`Account`/`upd` is no longer required.** The product's inode, open-file and process
  limits are applied through it after the package moves, but a refusal is non-fatal, so a
  key holding only "Account pack change" changes packages successfully.
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
