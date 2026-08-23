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
            root.innerHTML = '<p class="text-muted">Account information is not available right now.</p>';
        });
}());
</script>
{/literal}
