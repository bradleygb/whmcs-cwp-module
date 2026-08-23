# CWP API key permissions

Create the key in **CWP → Settings → API Manager → Create New API Key**.

## Response format

Set the key to **JSON**. XML is detected and reported as a configuration error, but is
not supported.

## IP whitelist

Enter the address the WHMCS server **connects from**, which is not always the address you
expect:

- WHMCS on a routed LAN reaches CWP from its **private** address. CWP sees that, not your
  public IP.
- WHMCS behind NAT elsewhere reaches CWP from the **public** address of its own network.

If in doubt, attempt a connection and read `/var/log/cwp/cwp_api.log` on the CWP server —
it records the source address. (Set `'debug' => true` in `config.php` for full request
detail, and turn it off afterwards.)

## Grants

The permissions grid is per **function** and per **action**. An action left off produces
`Unauthorized action` even when the function looks enabled, so check both axes.

| Function | Actions | Needed for |
|---|---|---|
| Account | `add`, `del`, **`list`**, `susp`, `unsp` | The provisioning lifecycle. `list` also drives usage import and Server Sync. |
| Account Details | `list` | Account detail on the admin service page, and verifying a package change applied. |
| Account pack change | `upd` | Package changes on upgrade/downgrade. |
| Change of password | `upd` | Password changes. |
| Autologin | `list` | Single sign-on to the control panel. |
| Type Server | `list` | Optional. Test Connection's first probe; it falls back to `Account`/`list`. |
| Account | `upd` | Optional. Applies the product's inode, open-file and process limits when a package changes. Without it the package still moves; only those three limits are skipped. **Some servers refuse this call with the grid switched on** — CWP's own debug log confirms it resolves the request to `accout_upd`, the grant that is set, and refuses it anyway with `Unauthorized2 action`. If yours does, set `apply_resource_limits => false` in `config.php` so the module stops attempting it. |
| Packages | `list` | Optional but strongly recommended. Resolves the product's package to this server's id, which `changepack` requires — set the package by name without it and every package change fails. It also rejects an unknown id before anything changes: `changepack` answers OK for an id that does not exist and leaves the account with no package at all. Without this grant the product's setting is sent verbatim, so it must be a valid id on every server in the group. |
| Packages | `add`, `upd` | Only if `push_packages_on_product_save` is enabled, which creates and updates CWP packages from WHMCS products. |
| Emails | `list` | Optional. Lists the account's mailboxes in the client area. Without it the list is simply absent. |
| Emails | `add`, `upd`, `del` | Only if `mailbox_management` is enabled, which lets customers create, re-password and delete their own mailboxes. |

`Account`/`upd` is deliberately listed as optional. It is a full account update — CWP
checks it as `accout_upd`, separately from "Account pack change" — so a key that changes
packages does not have to carry it.

**Grant nothing else.** In particular the module makes no AutoSSL, MySQL, FTP, DNS
Cluster or Cluster call, so those permissions add exposure without adding function. This
key is administrative over every account on the server — keep it narrow.

The `Emails` grants are the first that a **customer** can reach, through the client area,
rather than only WHMCS itself. `list` alone is read-only. Before granting `add`, `upd` or
`del`, read the `mailbox_management` note in `config.sample.php`.

API Manager also offers **Enable Functions for: WHMCS**, a preset that fills the grid in
one click. It is quicker, but grants more than the six functions above; the table is the
least-privilege option.

## Firewall

Port **2304** must be open from the WHMCS server to CWP:

- **Inbound on CWP** — `TCP_IN` in `/etc/csf/csf.conf` if you run CSF.
- **Outbound on the WHMCS server** — `TCP_OUT` in its own `/etc/csf/csf.conf`. CSF's
  `DROP_OUT` default is `REJECT`, so a missing entry here shows up as an immediate
  "Connection refused" rather than a timeout. This one is easy to miss.

## Verifying

Press **Test Connection** on the server entry. If it fails, the message distinguishes
name resolution, a refused connection, a timeout and a certificate fault, and names the
address that was actually dialled.
