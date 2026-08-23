{* CWP Hosting Module for WHMCS - client area overview output. *}

<div class="cwp-account">

    <div class="cwp-account-actions">
        <div class="cwp-account-identity">
            {if $username}<code>{$username|escape:'html'}</code>{/if}
            {if $serverHostname}<span class="text-muted"> on {$serverHostname|escape:'html'}</span>{/if}
        </div>
        <div class="cwp-account-buttons">
            {if $ssoUrl}
                <a href="{$ssoUrl|escape:'html'}" class="btn btn-primary">Log in to Control Panel</a>
            {/if}
            {if $panelUrl}
                <a href="{$panelUrl|escape:'html'}" class="btn btn-default btn-secondary" target="_blank" rel="noopener noreferrer">Open Panel Login Page</a>
            {/if}
        </div>
    </div>

    {if $dataUrl}
        <div id="cwp-dashboard" data-url="{$dataUrl|escape:'html'}">
            <p class="text-muted">Loading account information&hellip;</p>
        </div>
    {/if}

</div>

{literal}
<style>
.cwp-account-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 22px;
}
.cwp-account-buttons a { margin-left: 6px; }
.cwp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 14px;
    margin-bottom: 26px;
}
.cwp-stat {
    border: 1px solid rgba(128, 128, 128, .25);
    border-radius: 6px;
    padding: 12px 14px;
}
.cwp-stat-label {
    font-size: 11px;
    letter-spacing: .05em;
    text-transform: uppercase;
    opacity: .65;
}
.cwp-stat-value { font-size: 19px; font-weight: 600; line-height: 1.3; margin: 3px 0 9px; }
.cwp-stat-limit { font-size: 13px; font-weight: 400; opacity: .6; }
.cwp-track { background: rgba(128, 128, 128, .2); border-radius: 3px; height: 5px; overflow: hidden; }
.cwp-fill { background: #3b7ddd; height: 100%; }
.cwp-fill.cwp-over { background: #d9534f; }
.cwp-panels {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
    gap: 22px;
    align-items: start;
}
.cwp-panels h4 { margin: 0 0 8px; font-size: 15px; }
.cwp-panels table { margin-bottom: 0; width: 100%; }
.cwp-panels td, .cwp-panels th { padding: 6px 8px; font-size: 13px; word-break: break-word; }
.cwp-scroll { overflow-x: auto; }
.cwp-section { margin-top: 30px; }
.cwp-section-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.cwp-section h3 { margin: 0 0 10px; font-size: 16px; }
.cwp-mailbox-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; align-items: end; margin: 14px 0 18px; }
.cwp-mailbox-form label { display: block; font-size: 12px; margin-bottom: 4px; opacity: .75; }
.cwp-at { display: flex; align-items: center; gap: 6px; }
.cwp-at span { opacity: .6; }
.cwp-msg { margin: 10px 0 0; padding: 9px 12px; border-radius: 5px; font-size: 13px; }
.cwp-msg-ok { background: rgba(47, 158, 79, .12); color: #2f9e4f; }
.cwp-msg-bad { background: rgba(212, 59, 63, .12); color: #d43b3f; }
.cwp-row-acts { white-space: nowrap; text-align: right; }
.cwp-row-acts button { margin-left: 6px; }
.cwp-mailbox-form input::placeholder, .cwp-edit input::placeholder { opacity: .4; font-style: italic; }
.cwp-hint { font-weight: 400; opacity: .55; }
.cwp-edit { padding: 12px 14px; background: rgba(128, 128, 128, .07); }
.cwp-edit-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; align-items: end; }
@media (max-width: 480px) {
    .cwp-account-actions { flex-direction: column; align-items: stretch; }
    .cwp-account-buttons a { display: block; margin: 6px 0 0; }
}
</style>

<script>
(function () {
    var root = document.getElementById('cwp-dashboard');

    if (!root || !window.jQuery) {
        return;
    }

    function esc(value) {
        return jQuery('<div>').text(value === null || value === undefined ? '' : value).html();
    }

    function stat(row) {
        var track = '';

        if (row.percent !== null) {
            track = '<div class="cwp-track"><div class="cwp-fill' + (row.over ? ' cwp-over' : '')
                + '" style="width:' + row.percent + '%;"></div></div>';
        }

        return '<div class="cwp-stat" title="' + esc(row.text) + '">'
            + '<div class="cwp-stat-label">' + esc(row.label) + '</div>'
            + '<div class="cwp-stat-value">' + esc(row.usedText)
            + ' <span class="cwp-stat-limit">of ' + esc(row.limitText) + '</span></div>'
            + track + '</div>';
    }

    function panel(title, columns, rows, keys) {
        if (!rows.length) {
            return '';
        }

        var head = columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('');
        var body = rows.map(function (row) {
            return '<tr>' + keys.map(function (k) { return '<td>' + esc(row[k]) + '</td>'; }).join('') + '</tr>';
        }).join('');

        return '<div><h4>' + esc(title) + '</h4><div class="cwp-scroll">'
            + '<table class="table table-condensed table-sm"><thead><tr>' + head + '</tr></thead>'
            + '<tbody>' + body + '</tbody></table></div></div>';
    }

    var DEFAULT_QUOTA = '1024';

    // label is markup, not text: the callers build it. Values are escaped where they
    // are interpolated, which is what matters.
    function fieldRow(label, name, type, extra) {
        return '<div><label for="cwp-' + name + '">' + label + '</label>'
            + '<input class="form-control input-sm form-control-sm" id="cwp-' + name + '"'
            + ' name="' + name + '" type="' + type + '"' + (extra || '') + '></div>';
    }

    function mailboxes(data) {
        var rows = data.mailboxes || [];
        var html = '<div class="cwp-section"><div class="cwp-section-head">'
            + '<h3>Email Accounts</h3></div>';

        if (data.manageable) {
            html += '<div class="cwp-mailbox-form">'
                + '<div><label for="cwp-mailbox">Mailbox</label><div class="cwp-at">'
                + '<input class="form-control input-sm form-control-sm" id="cwp-mailbox" name="mailbox" type="text">'
                + '<span>@</span></div></div>'
                + '<div><label for="cwp-domain">Domain</label>'
                + '<select class="form-control input-sm form-control-sm" id="cwp-domain" name="domain">'
                + (data.domains || []).map(function (d) {
                    return '<option value="' + esc(d) + '">' + esc(d) + '</option>';
                }).join('')
                + '</select></div>'
                + fieldRow('Password', 'password', 'password', ' autocomplete="new-password"')
                + fieldRow('Size (MB) <span class="cwp-hint">optional</span>', 'quota', 'text',
                    ' placeholder="' + DEFAULT_QUOTA + ' if left blank"')
                + '<div><button type="button" class="btn btn-primary btn-block" id="cwp-create">Create Mailbox</button></div>'
                + '</div>';
        }

        if (!rows.length) {
            html += '<p class="text-muted">No email accounts yet.</p>';
        } else {
            html += '<div class="cwp-scroll"><table class="table table-condensed table-sm">'
                + '<thead><tr><th>Address</th><th>Used</th><th>Size</th>'
                + (data.manageable ? '<th></th>' : '') + '</tr></thead><tbody>'
                + rows.map(function (m, i) {
                    var address = esc(m.address);

                    return '<tr><td>' + address + '</td>'
                        + '<td>' + esc(m.used === null ? '' : m.used + ' MB') + '</td>'
                        + '<td>' + esc(m.quota === null ? 'unlimited' : m.quota + ' MB') + '</td>'
                        + (data.manageable
                            ? '<td class="cwp-row-acts">'
                                + '<button type="button" class="btn btn-default btn-xs btn-sm cwp-edit-open" data-row="' + i + '">Edit</button>'
                                + (data.deletable
                                    ? '<button type="button" class="btn btn-danger btn-xs btn-sm cwp-del" data-address="' + address + '">Delete</button>'
                                    : '')
                                + '</td>'
                            : '')
                        + '</tr>'
                        + (data.manageable
                            ? '<tr id="cwp-edit-' + i + '" style="display:none;"><td colspan="4" class="cwp-edit">'
                                + '<div class="cwp-edit-grid">'
                                + '<div><label>New password <span class="cwp-hint">leave blank to keep</span></label>'
                                + '<input class="form-control input-sm form-control-sm cwp-edit-pw" type="password" autocomplete="new-password"></div>'
                                + '<div><label>Size (MB) <span class="cwp-hint">leave blank to keep</span></label>'
                                + '<input class="form-control input-sm form-control-sm cwp-edit-quota" type="text" placeholder="'
                                + esc(m.quota === null ? 'no limit' : m.quota) + '"></div>'
                                + '<div><button type="button" class="btn btn-primary btn-block cwp-edit-save" data-address="' + address + '" data-row="' + i + '">Save</button></div>'
                                + '</div></td></tr>'
                            : '');
                }).join('')
                + '</tbody></table></div>';
        }

        if (data.manageable && !data.deletable) {
            html += '<p class="text-muted" style="margin-top:10px;font-size:12.5px;">'
                + 'To remove a mailbox, open the control panel or contact support.</p>';
        }

        return html + '<div id="cwp-mailbox-msg"></div></div>';
    }

    function say(text, good) {
        var box = document.getElementById('cwp-mailbox-msg');

        if (box) {
            box.innerHTML = '<p class="cwp-msg ' + (good ? 'cwp-msg-ok' : 'cwp-msg-bad') + '">' + esc(text) + '</p>';
        }
    }

    function send(op, fields, done) {
        fields.cwpajax = 1;
        fields.cwpop = op;
        fields.token = window.csrfToken || '';

        jQuery.post(root.getAttribute('data-url'), fields)
            .done(function (reply) {
                if (reply && reply.ok) {
                    done(reply.data);
                } else {
                    say((reply && reply.error) || 'That did not work. Please try again.', false);
                }
            })
            .fail(function (xhr) {
                var reply = xhr.responseJSON;
                say((reply && reply.error) || 'That did not work. Please try again.', false);
            });
    }

    function loadMailboxes() {
        var host = document.getElementById('cwp-mailboxes');

        if (!host) {
            return;
        }

        jQuery.post(root.getAttribute('data-url'), { cwpajax: 1, cwpop: 'mailbox.list' })
            .done(function (reply) {
                if (reply && reply.ok && reply.data) {
                    reply.data.domains = domains;
                    host.innerHTML = mailboxes(reply.data);
                } else {
                    host.innerHTML = '';
                }
            })
            .fail(function () { host.innerHTML = ''; });
    }

    jQuery(document).on('click', '#cwp-create', function () {
        send('mailbox.create', {
            mailbox: jQuery('#cwp-mailbox').val(),
            domain: jQuery('#cwp-domain').val(),
            password: jQuery('#cwp-password').val(),
            quota: jQuery('#cwp-quota').val()
        }, function (data) {
            loadMailboxes();
            setTimeout(function () { say(data.address + ' created.', true); }, 400);
        });
    });

    jQuery(document).on('click', '.cwp-edit-open', function () {
        var row = jQuery('#cwp-edit-' + jQuery(this).data('row'));
        row.toggle();
        row.find('.cwp-edit-pw').focus();
    });

    jQuery(document).on('click', '.cwp-edit-save', function () {
        var button = jQuery(this);
        var row = jQuery('#cwp-edit-' + button.data('row'));
        var address = button.data('address');

        send('mailbox.update', {
            address: address,
            password: row.find('.cwp-edit-pw').val(),
            quota: row.find('.cwp-edit-quota').val()
        }, function () {
            loadMailboxes();
            setTimeout(function () { say(address + ' updated.', true); }, 400);
        });
    });

    jQuery(document).on('click', '.cwp-del', function () {
        var address = jQuery(this).data('address');

        if (!window.confirm('Delete ' + address + ' and everything in it? This cannot be undone.')) {
            return;
        }

        send('mailbox.delete', { address: address }, function () {
            loadMailboxes();
            setTimeout(function () { say(address + ' deleted.', true); }, 400);
        });
    });

    var domains = [];

    function render(data) {
        var html = '';

        if (data.package) {
            html += '<h3 style="margin-top:0;">' + esc(data.package)
                + (data.state ? ' <small class="text-muted">' + esc(data.state) + '</small>' : '')
                + '</h3>';
        }

        html += '<div class="cwp-grid">'
            + data.meters.concat(data.allowances).map(stat).join('')
            + '</div>';

        html += '<div class="cwp-panels">'
            + panel('Domains', ['Domain', 'Document Root'], data.domains, ['domain', 'path'])
            + panel('Subdomains', ['Subdomain', 'Document Root'], data.subdomains, ['name', 'path'])
            + panel('Databases', ['Database', 'User', 'Host'], data.databases, ['database', 'user', 'host'])
            + '</div>';

        // Domains the account holds, for the create form's picker. The server checks
        // this again on every request - the list here is a convenience, not the rule.
        domains = (data.domains || []).map(function (d) { return d.domain; })
            .concat((data.subdomains || []).map(function (d) { return d.name; }));

        html += '<div id="cwp-mailboxes"></div>';

        root.innerHTML = html;

        loadMailboxes();
    }

    jQuery.post(root.getAttribute('data-url'), { cwpajax: 1, cwpop: 'dashboard.list' })
        .done(function (reply) {
            if (reply && reply.ok && reply.data) {
                render(reply.data);
            } else {
                root.innerHTML = '<p class="text-muted">'
                    + esc((reply && reply.error) || 'Account information is not available right now.')
                    + '</p>';
            }
        })
        .fail(function () {
            root.innerHTML = '<p class="text-muted">Account information is not available right now.</p>';
        });
}());
</script>
{/literal}
