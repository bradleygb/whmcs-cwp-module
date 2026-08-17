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

</div>
