{* CWP Hosting Module for WHMCS - client area overview output. *}

<div class="cwp-account-details">

    <h3>Hosting Account</h3>

    <table class="table table-condensed">
        <tbody>
            {if $domain}
                <tr>
                    <td width="25%"><strong>Primary Domain</strong></td>
                    <td>{$domain|escape:'html'}</td>
                </tr>
            {/if}
            {if $username}
                <tr>
                    <td><strong>Control Panel Username</strong></td>
                    <td><code>{$username|escape:'html'}</code></td>
                </tr>
            {/if}
            {if $serverHostname}
                <tr>
                    <td><strong>Server</strong></td>
                    <td>{$serverHostname|escape:'html'}</td>
                </tr>
            {/if}
        </tbody>
    </table>

    {if $ssoUrl}
        <a href="{$ssoUrl|escape:'html'}" class="btn btn-primary">
            Log in to Control Panel
        </a>
    {/if}

    {if $panelUrl}
        <a href="{$panelUrl|escape:'html'}" class="btn btn-default" target="_blank" rel="noopener noreferrer">
            Open Panel Login Page
        </a>
    {/if}

    {if $dataUrl}
        <div id="cwp-dashboard" data-url="{$dataUrl|escape:'html'}" style="margin-top:25px;">
            <p class="text-muted">Loading account information&hellip;</p>
        </div>

        {* literal, or Smarty reads every JavaScript brace as one of its own tags. *}
        {literal}
        <script>
        (function () {
            var root = document.getElementById('cwp-dashboard');

            if (!root || !window.jQuery) {
                return;
            }

            function esc(value) {
                return jQuery('<div>').text(value === null || value === undefined ? '' : value).html();
            }

            function gauge(row) {
                var bar = '';

                if (row.percent !== null) {
                    bar = '<div class="progress" style="height:8px;margin:4px 0 0;">'
                        + '<div class="progress-bar' + (row.over ? ' progress-bar-danger' : '')
                        + '" style="width:' + row.percent + '%;"></div></div>';
                }

                return '<div style="margin-bottom:12px;">'
                    + '<strong>' + esc(row.label) + '</strong>'
                    + '<span class="pull-right text-muted">' + esc(row.text)
                    + (row.over ? ' <span class="label label-warning">over</span>' : '')
                    + '</span>' + bar + '</div>';
            }

            function table(title, columns, rows, keys) {
                if (!rows.length) {
                    return '';
                }

                var head = columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('');
                var body = rows.map(function (row) {
                    return '<tr>' + keys.map(function (k) {
                        return '<td>' + esc(row[k]) + '</td>';
                    }).join('') + '</tr>';
                }).join('');

                return '<h4 style="margin-top:20px;">' + esc(title) + '</h4>'
                    + '<div style="overflow-x:auto;"><table class="table table-condensed">'
                    + '<thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>';
            }

            function render(data) {
                var html = '<h3>Account Overview</h3>';

                if (data.package) {
                    html += '<p><strong>Package:</strong> ' + esc(data.package)
                        + (data.state ? ' <span class="text-muted">(' + esc(data.state) + ')</span>' : '')
                        + '</p>';
                }

                html += '<div class="row"><div class="col-sm-6">'
                    + data.meters.map(gauge).join('')
                    + '</div><div class="col-sm-6">'
                    + data.allowances.map(gauge).join('')
                    + '</div></div>';

                html += table('Domains', ['Domain', 'Document Root'], data.domains, ['domain', 'path']);
                html += table('Subdomains', ['Subdomain', 'Document Root'], data.subdomains, ['name', 'path']);
                html += table('Databases', ['Database', 'User', 'Host'], data.databases, ['database', 'user', 'host']);

                root.innerHTML = html;
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
                    root.innerHTML = '<p class="text-muted">'
                        + 'Account information is not available right now.</p>';
                });
        }());
        </script>
        {/literal}
    {/if}

</div>
