# Design notes

Background for anyone maintaining this module. Not shipped in the release archive.

---

## Structure

`cwp7.php` is a dispatcher. It holds the WHMCS entry points and nothing else: every API
call goes through `lib/CwpClient.php`, and behaviour lives in `lib/Actions/`. Failures
are `CwpException` until the dispatcher converts them into whatever WHMCS expects at that
particular seam — a string for module commands, an array for `TestConnection` and SSO,
`echo` for `LoginLink`. Keeping that conversion in one place is why a change in an
undocumented CWP endpoint touches one adapter instead of the whole module.

## PHP floor

PHP 7.4, because WHMCS 8.5 runs on it. That rules out `str_contains`, `str_starts_with`,
`match`, union types, `mixed`, constructor promotion, nullsafe operators and
non-capturing `catch`. `declare(strict_types=1)` and typed properties are fine. The test
suites run on 7.4 through 8.3 for exactly this reason.

## Config option ordering is fixed

WHMCS stores product module settings by **position** in `tblproducts.configoption1..24`.
Renaming an option key or its label is safe; reordering or inserting silently repoints
every existing product's stored values. Slots 1–4 (package, inode, nofile, nproc) come
from the original module and can never move. `tests/dispatcher.php` asserts the order so
a mistake fails a test rather than a customer's account.

## CWP API quirks

**`result` vs `msg` vs `msj`.** The original module read `msj` (Spanish *mensaje*) and
community libraries agree. Current builds return **success payloads under `result`** and
errors under `msg` — confirmed against CWP on 17 August 2026 by reading an
`accountdetail/list` response in the WHMCS Module Log. CWP 0.9.8.1170 (September 2023)
is the release that changed response fields on "some endpoints".

`CwpClient::MESSAGE_KEYS` is ordered `result`, `msg`, `msj`. Errors carry no `result`, so
putting it first is safe. Missing this cost real function: `payload()` returned null for
every current-build response, so `rows()` returned an empty list, and both `UsageUpdate`
and `ListAccounts` silently saw zero accounts.

**`accountdetail` is nested, and misspells one key.** The payload is
`result.account_info.*` for the figures and `result.domains[0].domain` for the domain.
Subdomains arrive under **`subdomins`** — CWP's own typo — so both spellings are
accepted.

**The two endpoints use different wrappers on the same server.** Confirmed 17 August 2026
against a live CWP box:

| Endpoint | Wrapper | Disk usage field | Bandwidth field |
|---|---|---|---|
| `account/list` | `msj` | `diskused` — **placeholder** | `bandwidth` = consumed |
| `accountdetail/list` | `result` | `account_info.space_usage` — real | `bandwidth_used` |

`diskused` came back as `1` for all seven accounts on that server while `accountdetail`
reported `480715` and `1227` for two of them, so the list figure carries no information.
`UsageUpdate` therefore takes limits and bandwidth from the one list call and fetches
disk usage per account from `accountdetail` — but only for accounts that match a WHMCS
service, so an unused account on the box costs nothing. `usage_detail_lookup` turns the
extra calls off.

**Usage units are megabytes**, confirmed from the same responses:
`space_usage + space_available = space_disk` exactly, and a package advertised as
"Large Web Hosting" reports `space_disk => 10000`. That is what WHMCS stores, so no
conversion is applied.

**CWP uses `-1` for unlimited.** WHMCS reads `0` that way, and a negative limit renders
as a negative bar, so `Usage::numeric()` clamps negatives to zero.

**`bandwidth` means different things on different endpoints.** On `account/list` it is
bandwidth consumed; on `accountdetail` it is the limit. The specific names
(`bandwidth_used`, `bwusage`) are checked first and the ambiguous one is only ever a
fallback.

**Autologin endpoint.** `Session::ENDPOINTS` tries `user_session` then `autologin`. The
original module used `user_session` in production, and CWP's changelog records that path
being improved on 30 March 2020, so it demonstrably worked. `autologin` is the name the
current API Manager permissions grid uses. Which one a given build exposes is not
settled, so both are tried and the Module Log records the winner.

**`changepack`/`udp` — how a package is actually changed.**

```
POST /v1/changepack
key, action=udp, user, package   (package = bare ID, no @), debug
```

This is the dedicated endpoint, gated by the narrow **Account pack change** permission.
It is what `ChangePackage` calls.

`account`/`udp` is a *different* operation — a full account update — checked as
`accout_upd`, a broader grant. The module uses it only to apply the product's inode,
open-file and process limits after the package has moved, and treats a refusal as
non-fatal so a key holding just the narrow permission still works.

**Three endpoints, three package formats**, which is the trap:

| Endpoint | `package` |
|---|---|
| `account`/`add` | bare ID |
| `account`/`udp` | `@` **prefix** — `@12` |
| `changepack`/`udp` | bare ID |

The original module sent `12@` to `account`/`udp`: wrong end, wrong endpoint.

**`account`/`udp` — the confirmed contract.** Read out of Interactive Documentation on
17 August 2026:

| Field | Notes |
|---|---|
| `user` | account username |
| `email` | **required** — omitting it returns `You must indicate an email` |
| `package` | *"Package name or ID with @ front. Ex: @12"* — the `@` **prefixes** the value |
| `inode` | inodes |
| `openfiles` | open files — `add` calls the same thing `nofile` |
| `processes` | processes — `add` calls the same thing `nproc` |
| `backup`, `server_ips`, `debug` | optional |

Errors arrive under `msj`. The original module appended `@` instead of prefixing it,
omitted `email`, and used the `add` spellings for the two limits, so package changes could
never have worked — the same shape of latent failure as the usage import.

The separate **Account pack change** entry in the API Manager grid is a *permission*, not
a different endpoint.

**`account`/`add` — the confirmed contract.** Read the same day:

| Field | Notes |
|---|---|
| `domain`, `user`, `pass`, `email` | required |
| `package` | **bare** — the `@` prefix is `udp` only |
| `inode` | inodes |
| `limit_nofile` | open files |
| `limit_nproc` | processes |
| `server_ips`, `autossl` (0/1), `encodepass`, `reseller`, `lang`, `debug` | optional |

**Three names for the same two limits**, which is the trap:

| | Open files | Processes |
|---|---|---|
| `add` | `limit_nofile` | `limit_nproc` |
| `udp` | `openfiles` | `processes` |
| original module (both) | `nofile` | `nproc` |

`nofile`/`nproc` are accepted by neither, so every account created before 2.0.2 got CWP's
package defaults rather than the product's limits. `Account::createFields()` and
`Account::packageFields()` are public so tests assert each contract directly, rather than
the whole thing being rediscovered a fourth time.

Worth knowing for later: `add` takes an `autossl` (0/1) field, so a certificate can be
requested at creation without the separate AutoSSL function or its permission. Not used —
CWP issues and renews on its own schedule.

**Package ID or name.** The value is passed through verbatim after the `@`, so the product
option can hold whichever the server accepts.

**Response shapes.** A single result comes back as a bare row on some endpoints and a
one-element list on others. `CwpClient::rows()` normalises both to a list.

## Usage import

The original was broken twice over, which is why nobody ever saw a figure from it:

1. It matched `tblhosting.dedicatedip` against CWP's `ip_address`. `dedicatedip` is empty
   for every shared-IP account, so the `WHERE` matched no rows.
2. It wrote `lastupdate` with `date('Y-m-d H:i:S')`. Capital `S` is PHP's English ordinal
   suffix, so the value was `2026-08-17 14:30:th` — rejected by MySQL in strict mode.

Now scoped by `server` + `domain` as WHMCS documents, with `Capsule::raw('now()')`.

**Open question: units.** `Usage::numeric()` deliberately applies no conversion, matching
the original. WHMCS stores MB. Because the original never wrote a row, nobody ever
verified what CWP reports. Compare `tblhosting.diskusage` against the CWP panel after a
sync; if CWP reports GB or bytes, the conversion belongs in that one method.

## Usernames

Normalisation applies at **creation only**. WHMCS generates a username from the domain
and knows nothing about CWP's length and character rules; if CWP silently truncates it,
WHMCS is left holding a name that matches no account, and suspend, terminate and password
changes all target nothing. So `provisionUsername()` corrects it and writes the result
back to the service.

`resolveUsername()` — used by every other operation — returns the stored value verbatim.
Re-normalising an existing account would re-address services created by the old module,
or by an admin who chose a longer name by hand.

## Security decisions

**TLS verification on by default.** The connection carries an API key with administrative
scope over every account on the server. The original disabled `SSL_VERIFYPEER` and
`SSL_VERIFYHOST` on every call. Redirects are not followed and the protocol is pinned to
HTTPS, so a redirect cannot replay the key elsewhere.

**Key redaction.** CWP echoes the submitted key back inside some errors —
`Unauthorized action <key>` is a real response — and that text reaches the admin UI and
the Module Log. `CwpClient::redact()` strips it from CWP-originated text; WHMCS's
`replaceVars` covers the rest. Both are applied, because they cover different paths.

**Autologin URLs are minted on click.** The original called the endpoint during every
render of the product-details page and printed the resulting URL into the HTML, leaving a
live session token in page source, browser history and referrer headers. `ClientArea` now
makes no API call at all, and `ServiceSingleSignOn` mints the session on demand.

**Autologin host is constrained.** The session token is in the path and query, so
`Session::constrainToConfiguredHost()` replaces the host with the configured one. That
keeps the link working — CWP commonly returns its own FQDN where the server entry holds
an IP — while making it impossible for the module to redirect a customer to a host the
administrator never entered. `autologin_trust_returned_host` opts out.

**Client-facing messages.** `CwpException` carries a technical message for the log and a
separate client-safe one. Raw CWP output can name other accounts and filesystem paths.

## Implementation traps

**`CwpException::withClientMessage()` constructs, not clones.** PHP declares
`Exception::__clone` private and final, so `clone $this` is a fatal Error — and it would
only ever fire on an error path.

**`CwpClient::TLS_VERIFY_ERRORS` uses numeric literals.** The `CURLE_*` constant set
varies by build, and `CURLE_PEER_FAILED_VERIFICATION` is undefined on PHP 8.3 — libcurl
folded 51 into 60 in 7.62. Referencing it would throw on the one path that runs only
after a connection has already failed. The numbers are stable.

**`UsageUpdate` must never throw.** It runs inside the daily cron; the original could
raise a fatal `TypeError` from `count(null)` there and take the rest of the run with it.

**`AdminServicesTabFields` does call CWP during a render**, unlike the client area. That
is an admin-only page where the information is the reason to open it, and a failure
degrades to a single line.

## Deliberate omissions

**AutoSSL.** CWP issues and renews those certificates itself. Triggering it from the
module duplicates that, adds a failure path to provisioning, and needs a grant on a key
that already controls every account on the server — for no change in outcome. Its request
field names were never confirmed either, so anyone re-adding it starts from an unverified
contract. `autossl`/`list` is the one part that might still earn a place, as a read-only
certificate status display.

**`AdminSingleSignOn`.** That button signs an admin into the *server's* panel, and CWP's
API exposes no admin-panel session endpoint. Declaring the label would render a button
that can only fail. `AdminLink` gives a plain link instead.

**`MetricProvider`.** Requires implementing
`\WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface`, a WHMCS-internal contract that
cannot be verified across the 8.5–9.0 range this module supports. CWP exposes nothing
beyond the disk and bandwidth figures `UsageUpdate` already imports.

**Custom button arrays.** Nothing left worth a button once AutoSSL went. Panel login is
not a candidate: WHMCS renders that itself from `ServiceSingleSignOnLabel`, and a custom
button can only return a success/error string, not a redirect.

**DNS Cluster.** Despite the name, it clusters DNS *servers* between CWP installations —
not per-zone record editing. There is no client-facing DNS record management in this API.

## Tests

Neither suite needs a network, a database or WHMCS.

- `tests/smoke.php` — transport: host normalisation, URL-injection guards, response
  interpretation, redaction, autologin URL extraction and host constraint, username
  normalisation, usage coercion.
- `tests/dispatcher.php` — the WHMCS entry points with WHMCS stubbed: the function
  surface, config option ordering, that the client area opens no socket, credential
  masking, and error paths.

Run both on the oldest and newest supported PHP before releasing.

## CWP's debug log

`debug => true` makes CWP write to `/var/log/cwp/cwp_api.log`. It is the only reliable way
to see which permission a refused call actually wanted, because **the names CWP checks
internally do not match the API Manager labels**:

| API Manager row / column | Checked internally as |
|---|---|
| Account Details / LIST | `detailaccount_list` |
| Account / UPD | `accout_upd` — CWP's own typo |

The log also records **the API key in plaintext**, so it must be treated as a credential
disclosure: switch debug off, delete the log, rotate the key. The module's own logging
never writes the key; this is CWP's behaviour and cannot be prevented from this side.

## After a CWP upgrade

Re-open API Manager and diff the permissions grid against `PERMISSIONS.md`. Functions
absent from the public documentation have changed shape between releases without notice —
0.9.8.1170 did exactly that.
