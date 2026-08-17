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
 * @version 2.0.0
 * @author  Booysen Logistics <bradley@booysenlogistics.co.za>
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('CWP7_MODULE_VERSION')) {
    define('CWP7_MODULE_VERSION', '2.0.0');
}

require_once __DIR__ . '/lib/CwpException.php';
require_once __DIR__ . '/lib/CwpClient.php';
require_once __DIR__ . '/lib/Actions/Account.php';
require_once __DIR__ . '/lib/Actions/Session.php';
require_once __DIR__ . '/lib/Actions/Usage.php';

use Cwp7\Actions\Account;
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
            'Description' => 'Package ID (or name) exactly as it exists in CWP.',
        ],
        'inode' => [
            'FriendlyName' => 'Inode Limit',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0',
            'Description' => '0 for unlimited.',
        ],
        'nofile' => [
            'FriendlyName' => 'Open File Limit (nofile)',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '100',
            'Description' => 'Maximum open file descriptors.',
        ],
        'nproc' => [
            'FriendlyName' => 'Process Limit (nproc)',
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

        return ['success' => true, 'redirectTo' => $session->url()];
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

    return [
        'tabOverviewModuleOutputTemplate' => 'templates/overview',
        'templateVariables' => [
            'panelUrl' => $panelUrl,
            'username' => isset($params['username']) ? (string) $params['username'] : '',
            'domain' => isset($params['domain']) ? (string) $params['domain'] : '',
            'serverHostname' => isset($params['serverhostname']) ? (string) $params['serverhostname'] : '',
            'ssoUrl' => $serviceId > 0
                ? 'clientarea.php?action=productdetails&id=' . $serviceId . '&dosinglesignon=1'
                : '',
        ],
    ];
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
    try {
        $detail = Account::fromParams($params)->detail();
    } catch (CwpException $e) {
        return ['CWP Account' => '<span class="label label-danger">'
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</span>'];
    } catch (\Throwable $e) {
        cwp7_logFailure('AdminServicesTabFields', $params, $e);

        return ['CWP Account' => '<em>Unavailable.</em>'];
    }

    $fields = [];
    $lines = [];

    foreach (['domain', 'username', 'user', 'package', 'ip_address', 'setup_date', 'status'] as $key) {
        if (isset($detail[$key]) && is_scalar($detail[$key]) && (string) $detail[$key] !== '') {
            $lines[] = '<strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ':</strong> '
                . htmlspecialchars((string) $detail[$key], ENT_QUOTES, 'UTF-8');
        }
    }

    $fields['CWP Account'] = $lines === [] ? '<em>No detail returned.</em>' : implode('<br>', $lines);

    foreach (['subdomains' => 'Subdomains', 'databases' => 'Databases', 'database_users' => 'Database Users'] as $key => $label) {
        if (isset($detail[$key]) && is_array($detail[$key])) {
            $fields[$label] = (string) count($detail[$key]);
        }
    }

    return $fields;
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

    if ($e->getKind() === CwpException::KIND_API) {
        $message .= "\n\nIf that reads \"Unauthorized action\", the API key is missing "
            . "the function/action pair shown in brackets. Enable it in "
            . "CWP -> Settings -> API Manager -> edit the key.";
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
 * Params with every credential removed, for logging.
 *
 * @param array<string,mixed> $params
 *
 * @return array<string,mixed>
 */
function cwp7_safeParams(array $params)
{
    foreach (['serveraccesshash', 'serverpassword', 'password', 'serverusername'] as $key) {
        if (isset($params[$key])) {
            $params[$key] = '***';
        }
    }

    if (isset($params['clientsdetails']) && is_array($params['clientsdetails'])) {
        $params['clientsdetails'] = [
            'email' => isset($params['clientsdetails']['email']) ? $params['clientsdetails']['email'] : '',
        ];
    }

    return $params;
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
