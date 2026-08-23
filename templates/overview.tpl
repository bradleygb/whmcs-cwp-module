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

    {if $apps}
        <h3 style="margin-top:25px;">Control Panel Shortcuts</h3>

        <div class="row cwp-shortcuts">
            {foreach from=$apps item=app}
                <div class="col-xs-6 col-sm-4 col-md-3" style="margin-bottom:12px;">
                    <a href="{$app.url|escape:'html'}"
                       class="btn btn-default btn-block"
                       style="height:100%;padding:14px 8px;white-space:normal;">
                        <i class="fa fas fa-{$app.icon|escape:'html'}" style="font-size:20px;display:block;margin-bottom:6px;"></i>
                        {$app.label|escape:'html'}
                    </a>
                </div>
            {/foreach}
        </div>

        <p class="text-muted" style="clear:both;">
            Each shortcut signs you in to the control panel and opens that section.
            A feature your hosting package does not include will say so when opened.
        </p>
    {/if}

</div>
