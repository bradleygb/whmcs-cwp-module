<?php
/**
 * CWP Hosting Module for WHMCS
 *
 * Provisioning module for Control Web Panel (CWP), driving the CWP external API on
 * port 2304. Dispatcher only: API access lives in lib/CwpClient.php and behaviour in
 * lib/Actions/.
 *
 * Supports WHMCS 8.5 to 9.0 on PHP 7.4 to 8.3.
 *
 * @package cwp7
 * @version 2.2.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('CWP7_MODULE_VERSION')) {
    define('CWP7_MODULE_VERSION', '2.2.0');
}

require_once __DIR__ . '/lib/CwpException.php';
require_once __DIR__ . '/lib/CwpClient.php';
require_once __DIR__ . '/lib/Actions/Account.php';
require_once __DIR__ . '/lib/Actions/Package.php';
require_once __DIR__ . '/lib/Actions/PanelApp.php';
require_once __DIR__ . '/lib/Actions/Session.php';
require_once __DIR__ . '/lib/Actions/Usage.php';

use Cwp7\Actions\Account;
use Cwp7\Actions\PanelApp;
use Cwp7\Actions\Session;
use Cwp7\Actions\Usage;
use Cwp7\CwpClient;
use Cwp7\CwpException;

// ---------------------------------------------------------------------------
// Module definition
// ---------------------------------------------------------------------------

/**
 * @return array<string,string|bool>
 */
function cwp7_MetaData()
{
    return [
        'DisplayName' => 'Control Web Panel (CWP) - Community Edition',
        'APIVersion' => '1.1',
        'RequiresServer' => true,

        // CWP's external API is HTTPS-only on 2304; there is no cleartext equivalent.
        'DefaultNonSSLPort' => '2304',
        'DefaultSSLPort' => '2304',

        'ServiceSingleSignOnLabel' => 'Log in to Control Panel',

        // Server Sync (WHMCS 7.10+).
        'ListAccountsUniqueIdentifierDisplayName' => 'Domain',
        'ListAccountsUniqueIdentifierField' => 'domain',
        'ListAccountsProductField' => 'configoption1',
    ];
}

/**
 * Product-scoped settings.
 *
 * ORDER IS FIXED. WHMCS stores these by position in tblproducts.configoption1..24, so
 * reordering or inserting repoints the stored values of every existing product. New
 * options are appended.
 *
 * Server-scoped settings (TLS policy, timeouts, ports) live in config.php.
 *
 * @return array<string,array<string,string>>
 */
function cwp7_ConfigOptions()
{
    return [
        'package' => [
            'FriendlyName' => 'CWP Package',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '1',
            'Description' => 'Package ID or name exactly as it exists in CWP. '
                . 'Leave blank to use this product\'s name.',
        ],
        'inode' => [
            'FriendlyName' => 'Inode Limit',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0',
            'Description' => '0 for unlimited.',
        ],
        'nofile' => [
            'FriendlyName' => 'Open File Limit',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '100',
            'Description' => 'Maximum open file descriptors.',
        ],
        'nproc' => [
            'FriendlyName' => 'Process Limit',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '40',
            'Description' => 'Maximum concurrent processes.',
        ],
        'usernamelength' => [
            'FriendlyName' => 'Max Username Length',
            'Type' => 'text',
            'Size' => '5',
            'Default' => '8',
            'Description' => 'Applied to new accounts only. Set 0 to send the username unchanged.',
        ],

        // Slots 6-15 describe the CWP package itself, and are only read when package
        // pushing is enabled in config.php. Left blank, each limit keeps CWP's own
        // default; set to 0 it means none allowed.
        'diskquota' => [
            'FriendlyName' => 'Disk Quota (MB)',
            'Type' => 'text',
            'Size' => '10',
            'Description' => 'Required to push this product to CWP as a package.',
        ],
        'bandwidth' => [
            'FriendlyName' => 'Bandwidth (MB)',
            'Type' => 'text',
            'Size' => '10',
            'Description' => 'Monthly transfer allowance.',
        ],
        'ftpaccounts' => [
            'FriendlyName' => 'FTP Accounts',
            'Type' => 'text',
            'Size' => '10',
        ],
        'emailaccounts' => [
            'FriendlyName' => 'Email Accounts',
            'Type' => 'text',
            'Size' => '10',
        ],
        'emaillists' => [
            'FriendlyName' => 'Email Lists',
            'Type' => 'text',
            'Size' => '10',
        ],
        'databases' => [
            'FriendlyName' => 'Databases',
            'Type' => 'text',
            'Size' => '10',
        ],
        'subdomains' => [
            'FriendlyName' => 'Sub Domains',
            'Type' => 'text',
            'Size' => '10',
        ],
        'parkeddomains' => [
            'FriendlyName' => 'Parked Domains',
            'Type' => 'text',
            'Size' => '10',
        ],
        'addondomains' => [
            'FriendlyName' => 'Addon Domains',
            'Type' => 'text',
            'Size' => '10',
        ],
        'hourlyemails' => [
            'FriendlyName' => 'Hourly Email Limit',
            'Type' => 'text',
            'Size' => '10',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Admin: connectivity
// ---------------------------------------------------------------------------

/**
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function cwp7_TestConnection(array $params)
{
    try {
        $result = CwpClient::fromParams($params)->ping();

        if ($result['ok']) {
            return ['success' => true];
        }

        $error = $result['error'];

        return [
            'success' => false,
            'error' => $error->getMessage() . "\n\n" . cwp7_troubleshootingHint($error),
        ];
    } catch (CwpException $e) {
        return [
            'success' => false,
            'error' => $e->getMessage() . "\n\n" . cwp7_troubleshootingHint($e),
        ];
    } catch (\Throwable $e) {
        cwp7_logFailure('TestConnection', $params, $e);

        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Link to the CWP admin panel from the server configuration page.
 *
 * @param array<string,mixed> $params
 *
 * @return string
 */
function cwp7_AdminLink(array $params)
{
    try {
        $url = CwpClient::fromParams($params)->getAdminUrl();
    } catch (CwpException $e) {
        return '';
    }

    return sprintf(
        '<a href="%s" target="_blank" rel="noopener noreferrer">Open CWP Admin Panel</a>',
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
    );
}

/**
 * Link shown on the admin's view of a service.
 *
 * Not an autologin URL — a session token would then be present in the page source of
 * every render. Use the Single Sign-On button, which mints one on click.
 *
 * @param array<string,mixed> $params
 *
 * @return void
 */
function cwp7_LoginLink(array $params)
{
    try {
        $url = CwpClient::fromParams($params)->getPanelUrl();
    } catch (CwpException $e) {
        return;
    }

    echo sprintf(
        '<a href="%s" target="_blank" rel="noopener noreferrer">CWP User Panel</a>',
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
    );
}

// ---------------------------------------------------------------------------
// Provisioning lifecycle
// ---------------------------------------------------------------------------

/**
 * @param array<string,mixed> $params
 *
 * @return string
 */
function cwp7_CreateAccount(array $params)
{
    return cwp7_perform('CreateAccount', $params, function (Account $account) {
        $account->create();
    });
}

/**
 * @param array<string,mixed> $params
 *
 * @return string
 */
function cwp7_SuspendAccount(array $params)
{
    return cwp7_perform('SuspendAccount', $params, function (Account $account) {
        $account->suspend();
    });
}

/**
 * @param array<string,mixed> $params
 *
 * @return string
 */
function cwp7_UnsuspendAccount(array $params)
{
    return cwp7_perform('UnsuspendAccount', $params, function (Account $account) {
        $account->unsuspend();
    });
}

/**
 * @param array<string,mixed> $params
 *
 * @return string
 */
function cwp7_TerminateAccount(array $params)
{
    return cwp7_perform('TerminateAccount', $params, function (Account $account) {
        $account->terminate();
    });
}

/**
 * @param array<string,mixed> $params
 *
 * @return string
 */
function cwp7_ChangePassword(array $params)
{
    $password = isset($params['password']) ? (string) $params['password'] : '';

    return cwp7_perform('ChangePassword', $params, function (Account $account) use ($password) {
        $account->changePassword($password);
    });
}

/**
 * @param array<string,mixed> $params
 *
 * @return string
 */
function cwp7_ChangePackage(array $params)
{
    return cwp7_perform('ChangePackage', $params, function (Account $account) {
        $account->changePackage();
    });
}

/**
 * Runs when a renewal invoice is paid. CWP has no notion of a term, so this is a no-op.
 *
 * @param array<string,mixed> $params
 *
 * @return string
 */
function cwp7_Renew(array $params)
{
    return 'success';
}

// ---------------------------------------------------------------------------
// Usage
// ---------------------------------------------------------------------------

/**
 * Daily disk and bandwidth import. Runs once per server, not per service.
 *
 * @param array<string,mixed> $params
 *
 * @return void
 */
function cwp7_UsageUpdate(array $params)
{
    $serverId = isset($params['serverid']) ? (int) $params['serverid'] : 0;

    try {
        $stats = Usage::apply(CwpClient::fromParams($params), $serverId);

        if (function_exists('logModuleCall')) {
            logModuleCall('cwp7', 'UsageUpdate', ['serverid' => $serverId], $stats);
        }
    } catch (\Throwable $e) {
        // Must not escape: this runs inside the daily cron.
        cwp7_logFailure('UsageUpdate', $params, $e);
        logActivity('CWP UsageUpdate failed for server ' . $serverId . ': ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Single sign-on
// ---------------------------------------------------------------------------

/**
 * Mint a panel session and return the URL for WHMCS to redirect to.
 *
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function cwp7_ServiceSingleSignOn(array $params)
{
    try {
        $client = CwpClient::fromParams($params);
        $account = new Account($client, $params);

        $session = new Session(
            $client,
            $account->resolveUsername(),
            (bool) $client->getOption('autologin_trust_returned_host', false)
        );

        // The shortcut the customer clicked, if any. Resolved through the allow-list,
        // so an unknown or forged value opens the dashboard rather than an arbitrary
        // panel URL.
        $requested = isset($_REQUEST['cwpapp']) ? (string) $_REQUEST['cwpapp'] : '';

        return ['success' => true, 'redirectTo' => $session->url(PanelApp::moduleFor($requested))];
    } catch (CwpException $e) {
        cwp7_logFailure('ServiceSingleSignOn', $params, $e);

        return ['success' => false, 'errorMsg' => $e->getClientMessage()];
    } catch (\Throwable $e) {
        cwp7_logFailure('ServiceSingleSignOn', $params, $e);

        return [
            'success' => false,
            'errorMsg' => 'We could not open the control panel just now. Please try again shortly.',
        ];
    }
}

// ---------------------------------------------------------------------------
// Client area
// ---------------------------------------------------------------------------

/**
 * Extra output on the client's service page. Makes no API call.
 *
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function cwp7_ClientArea(array $params)
{
    $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;

    try {
        $panelUrl = CwpClient::fromParams($params)->getPanelUrl();
    } catch (CwpException $e) {
        $panelUrl = '';
    }

    $ssoUrl = $serviceId > 0
        ? 'clientarea.php?action=productdetails&id=' . $serviceId . '&dosinglesignon=1'
        : '';

    return [
        'tabOverviewModuleOutputTemplate' => 'templates/overview',
        'templateVariables' => [
            'panelUrl' => $panelUrl,
            'username' => isset($params['username']) ? (string) $params['username'] : '',
            'domain' => isset($params['domain']) ? (string) $params['domain'] : '',
            'serverHostname' => isset($params['serverhostname']) ? (string) $params['serverhostname'] : '',
            'ssoUrl' => $ssoUrl,
            'apps' => $ssoUrl === '' ? [] : cwp7_panelShortcuts($ssoUrl),
        ],
    ];
}

/**
 * The panel shortcuts shown in the client area, each pointing at single sign-on.
 *
 * Every link goes through WHMCS single sign-on rather than the panel directly, so no
 * session is minted until one is clicked and none is written into the page.
 *
 * @return array<int,array{label:string, icon:string, url:string}>
 */
function cwp7_panelShortcuts(string $ssoUrl)
{
    $shortcuts = [];

    foreach (PanelApp::all() as $app) {
        $shortcuts[] = [
            'label' => $app['label'],
            'icon' => $app['icon'],
            'url' => $ssoUrl . '&cwpapp=' . rawurlencode($app['key']),
        ];
    }

    return $shortcuts;
}

/**
 * Account detail on the admin service page. Calls CWP on render; a failure degrades to
 * a single line rather than an exception.
 *
 * @param array<string,mixed> $params
 *
 * @return array<string,string>
 */
function cwp7_AdminServicesTabFields(array $params)
{
    // A terminated service has no account to describe, so the call would fail every
    // time this page is opened.
    $status = strtolower(trim((string) (isset($params['status']) ? $params['status'] : '')));

    if (in_array($status, ['terminated', 'cancelled', 'canceled', 'fraud'], true)) {
        return ['CWP Account' => '<em>Service is ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8')
            . '. No CWP account is expected.</em>'];
    }

    try {
        $detail = Account::fromParams($params)->detail();
    } catch (CwpException $e) {
        // A missing account is an ordinary state, not a fault worth a red banner.
        if (stripos($e->getMessage(), 'does not exist') !== false) {
            return ['CWP Account' => '<em>No account with this username exists on the server.</em>'];
        }

        return ['CWP Account' => '<span class="label label-danger">'
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</span>'];
    } catch (\Throwable $e) {
        cwp7_logFailure('AdminServicesTabFields', $params, $e);

        return ['CWP Account' => '<em>Unavailable.</em>'];
    }

    // accountdetail nests the figures under account_info; older shapes are flat.
    $info = (isset($detail['account_info']) && is_array($detail['account_info']))
        ? $detail['account_info']
        : $detail;

    $lines = [];

    $domain = '';
    if (isset($detail['domains'][0]['domain'])) {
        $domain = (string) $detail['domains'][0]['domain'];
    } elseif (isset($detail['domain'])) {
        $domain = (string) $detail['domain'];
    }

    if ($domain !== '') {
        $lines[] = '<strong>Domain:</strong> ' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8');
    }

    $package = (string) Usage::pick($info, ['package_name', 'package', 'plan']);
    if ($package !== '') {
        $id = (string) Usage::pick($info, ['package_id']);
        $lines[] = '<strong>Package:</strong> ' . htmlspecialchars($package, ENT_QUOTES, 'UTF-8')
            . ($id !== '' ? ' (#' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . ')' : '');
    }

    foreach (['state' => 'State', 'directory' => 'Home', 'email' => 'Contact', 'ip_address' => 'IP'] as $key => $label) {
        $value = (string) Usage::pick($info, [$key]);
        if ($value !== '') {
            $lines[] = '<strong>' . $label . ':</strong> ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }

    $fields = [];
    $fields['CWP Account'] = $lines === [] ? '<em>No detail returned.</em>' : implode('<br>', $lines);

    $diskUsed = Usage::pick($info, Usage::FIELD_CANDIDATES['diskusage']);
    $diskLimit = Usage::pick($info, Usage::FIELD_CANDIDATES['disklimit']);
    if ($diskUsed !== null || $diskLimit !== null) {
        $fields['Disk'] = cwp7_formatUsage($diskUsed, $diskLimit);
    }

    $bwUsed = Usage::pick($info, ['bandwidth_used', 'bwusage', 'bw_used']);
    $bwLimit = Usage::pick($info, ['bwlimit', 'bandwidth_limit', 'bandwidth']);
    if ($bwUsed !== null || $bwLimit !== null) {
        $fields['Bandwidth'] = cwp7_formatUsage($bwUsed, $bwLimit);
    }

    // CWP spells this key "subdomins" on accountdetail; accept both.
    $counts = [
        'Domains' => ['domains'],
        'Subdomains' => ['subdomains', 'subdomins'],
        'Databases' => ['databases'],
    ];

    foreach ($counts as $label => $keys) {
        foreach ($keys as $key) {
            if (isset($detail[$key]) && is_array($detail[$key])) {
                $fields[$label] = (string) count($detail[$key]);
                break;
            }
        }
    }

    return $fields;
}

/**
 * Render "used of limit" in megabytes, treating CWP's -1 as unlimited.
 *
 * @param mixed $used
 * @param mixed $limit
 *
 * @return string
 */
function cwp7_formatUsage($used, $limit)
{
    $usedMb = $used === null ? null : Usage::numeric($used);
    $rawLimit = $limit === null ? null : (float) Usage::numeric($limit);
    $unlimited = ($limit !== null && (float) $limit < 0);

    $parts = [];
    $parts[] = $usedMb === null ? '?' : number_format($usedMb, 0) . ' MB';
    $parts[] = 'of';
    $parts[] = $unlimited || $rawLimit === null || $rawLimit == 0.0
        ? 'unlimited'
        : number_format($rawLimit, 0) . ' MB';

    return htmlspecialchars(implode(' ', $parts), ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Server Sync
// ---------------------------------------------------------------------------

/**
 * Every account CWP knows about, for WHMCS to compare against its own services.
 *
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function cwp7_ListAccounts(array $params)
{
    try {
        $rows = Account::fromParams($params)->listAll();
    } catch (\Throwable $e) {
        cwp7_logFailure('ListAccounts', $params, $e);

        return ['success' => false, 'error' => $e->getMessage()];
    }

    $accounts = [];

    foreach ($rows as $row) {
        $domain = trim((string) Usage::pick($row, ['domain', 'main_domain', 'primary_domain']));
        $username = trim((string) Usage::pick($row, ['username', 'user', 'account']));

        if ($domain === '' && $username === '') {
            continue;
        }

        $accounts[] = [
            'email' => (string) Usage::pick($row, ['email', 'contact_email']),
            'username' => $username,
            'domain' => $domain,
            'uniqueIdentifier' => $domain !== '' ? $domain : $username,
            'product' => (string) Usage::pick($row, ['package', 'package_name', 'plan']),
            'primaryip' => (string) Usage::pick($row, ['ip_address', 'ip', 'server_ips']),
            'created' => cwp7_normaliseDate(Usage::pick($row, ['setup_date', 'created', 'date'])),
            'status' => cwp7_normaliseStatus($row),
        ];
    }

    return ['success' => true, 'accounts' => $accounts];
}

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Run one lifecycle operation and return WHMCS's contract: 'success', or an error
 * message. These surfaces are admin- and cron-facing, so they carry the technical
 * message; client-facing surfaces use CwpException::getClientMessage().
 *
 * @param array<string,mixed> $params
 * @param callable            $work
 *
 * @return string
 */
function cwp7_perform(string $action, array $params, $work)
{
    try {
        $work(Account::fromParams($params));

        return 'success';
    } catch (CwpException $e) {
        cwp7_logFailure($action, $params, $e);

        return cwp7_commandError($e);
    } catch (\Throwable $e) {
        cwp7_logFailure($action, $params, $e);

        return 'Unexpected error: ' . $e->getMessage();
    }
}

/**
 * Turn a failure into the string a Module Command shows the admin.
 *
 * @param CwpException $e
 *
 * @return string
 */
function cwp7_commandError($e)
{
    $message = $e->getMessage();
    $context = $e->getContext();
    $errno = isset($context['curl_errno']) ? (int) $context['curl_errno'] : 0;

    if ($e->getKind() === CwpException::KIND_API) {
        if (stripos($message, 'unauthorized') !== false) {
            return $message . "\n\nThe API key is missing the function/action pair shown in "
                . "brackets. Enable it in CWP -> Settings -> API Manager -> edit the key.";
        }

        if (stripos($message, 'valid user') !== false || stripos($message, 'does not exist') !== false) {
            return $message . "\n\nNo account with that username exists on the server.";
        }

        return $message;
    }

    if ($e->getKind() === CwpException::KIND_TRANSPORT && $errno === 28) {
        return $message . "\n\nCWP was reached but did not reply in time. Account creation "
            . "can take minutes on a busy server; raise 'provision_timeout' in config.php. "
            . "Check the server before retrying — CWP may have finished the work anyway.";
    }

    return $message;
}

/**
 * @param array<string,mixed> $params
 * @param \Throwable          $e
 */
function cwp7_logFailure(string $action, array $params, $e)
{
    if (!function_exists('logModuleCall')) {
        return;
    }

    $context = ($e instanceof CwpException) ? $e->getContext() : ['trace' => $e->getTraceAsString()];
    $context['module_version'] = CWP7_MODULE_VERSION;

    logModuleCall(
        'cwp7',
        $action . ' FAILED',
        cwp7_safeParams($params),
        $e->getMessage(),
        $context,
        cwp7_secrets($params)
    );
}

/**
 * The subset of the module parameters that is safe and useful to log.
 *
 * An allow-list, not a block-list. WHMCS passes a `model` parameter holding a whole
 * Eloquent object — the service, its product and the full client record, including the
 * stored password and the customer's postal address — and WHMCS is free to add more
 * parameters in future. Naming what may be logged is the only approach that stays
 * correct as that set grows.
 *
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function cwp7_safeParams(array $params)
{
    $allowed = [
        'serviceid', 'serverid', 'serverhostname', 'serverip', 'serverport',
        'domain', 'username', 'moduletype', 'action', 'status', 'packageid',
        'configoption1', 'configoption2', 'configoption3', 'configoption4', 'configoption5',
    ];

    $safe = [];

    foreach ($allowed as $key) {
        if (isset($params[$key]) && is_scalar($params[$key])) {
            $safe[$key] = $params[$key];
        }
    }

    if (isset($params['clientsdetails']['email'])) {
        $safe['clientEmail'] = $params['clientsdetails']['email'];
    }

    return $safe;
}

/**
 * Values WHMCS should redact anywhere else they appear in a log entry.
 *
 * @param array<string,mixed> $params
 *
 * @return array<int,string>
 */
function cwp7_secrets(array $params)
{
    $secrets = [];

    foreach (['serveraccesshash', 'serverpassword', 'password'] as $key) {
        if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
            $secrets[] = $params[$key];
        }
    }

    return $secrets;
}

/**
 * Map a failure to its most likely cause.
 *
 * @param CwpException $e
 *
 * @return string
 */
function cwp7_troubleshootingHint($e)
{
    $context = $e->getContext();
    $errno = isset($context['curl_errno']) ? (int) $context['curl_errno'] : 0;

    switch ($e->getKind()) {
        case CwpException::KIND_TRANSPORT:
            if ($errno === 6) {
                return "The hostname did not resolve. Check the spelling on the server entry, "
                    . "and that this WHMCS server can resolve it — DNS here may differ from DNS "
                    . "on your workstation.";
            }

            if ($errno === 7) {
                return "The connection was refused or unreachable — nothing answered on 2304, "
                    . "so TLS and the API key are not involved yet.\n"
                    . "Most likely causes:\n"
                    . "  1. This hostname resolves to a different address from the WHMCS server "
                    . "than it does elsewhere (split-horizon DNS, or a NAT gateway with no port "
                    . "forward). The resolved address is named in the error above.\n"
                    . "  2. Outbound 2304 is blocked on the WHMCS server itself. On CSF, add 2304 "
                    . "to TCP_OUT — its DROP_OUT default is REJECT, which produces exactly this "
                    . "instant refusal.\n"
                    . "  3. Inbound 2304 is not open on the CWP server, or cwpsrv is not listening.";
            }

            if ($errno === 28) {
                return "The connection timed out — packets are being dropped rather than refused, "
                    . "which usually means a firewall between here and CWP is discarding them. "
                    . "Check inbound 2304 on the CWP server and any firewall in front of it.";
            }

            return "Most likely causes:\n"
                . "  1. Port 2304 is not open from this WHMCS server's IP.\n"
                . "  2. The CWP firewall is blocking this IP.\n"
                . "  3. TLS verification is failing — check the certificate on the API port.";

        case CwpException::KIND_API:
            return "Most likely causes:\n"
                . "  1. This WHMCS server's IP is not in the API key's IP whitelist "
                . "(CWP -> Settings -> API Manager). On a routed LAN, CWP sees the private "
                . "address, not your public IP.\n"
                . "  2. The Access Hash does not match the CWP API key.\n"
                . "  3. The key lacks LIST permission on Type Server and Account.";

        case CwpException::KIND_CONFIG:
            return "Check the server entry's hostname and Access Hash, and confirm the API key's "
                . "response format is set to JSON.";

        default:
            return "Confirm the API key exists, is active, and has this WHMCS server's IP "
                . "whitelisted in CWP -> Settings -> API Manager.";
    }
}

/**
 * @param mixed $value
 *
 * @return string Y-m-d H:i:s, or '' when nothing parseable was supplied.
 */
function cwp7_normaliseDate($value)
{
    if ($value === null || $value === '') {
        return '';
    }

    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', (int) $value);
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false ? '' : date('Y-m-d H:i:s', $timestamp);
}

/**
 * Map CWP's status vocabulary onto WHMCS's.
 *
 * @param array<string,mixed> $row
 *
 * @return string
 */
function cwp7_normaliseStatus(array $row)
{
    $active = class_exists('\WHMCS\Service\Status') ? \WHMCS\Service\Status::ACTIVE : 'Active';
    $suspended = class_exists('\WHMCS\Service\Status') ? \WHMCS\Service\Status::SUSPENDED : 'Suspended';

    // An explicit flag wins. array_key_exists, not isset: "0" means active.
    foreach (['suspended', 'is_suspended', 'susp'] as $key) {
        if (array_key_exists($key, $row)) {
            $flag = strtolower(trim((string) $row[$key]));

            return in_array($flag, ['1', 'yes', 'true', 'on', 'suspended'], true) ? $suspended : $active;
        }
    }

    $status = strtolower(trim((string) Usage::pick($row, ['status', 'state', 'account_status'])));

    if ($status === '') {
        return $active;
    }

    // Specific stems only: a loose substring test reads "unlocked" as suspended.
    foreach (['susp', 'disab', 'inactive'] as $stem) {
        if (strpos($status, $stem) !== false) {
            return $suspended;
        }
    }

    return $active;
}
