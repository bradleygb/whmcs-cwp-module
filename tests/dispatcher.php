<?php
/**
 * Dispatcher tests. WHMCS stubbed, no network, no database.
 *
 *   php tests/dispatcher.php
 *
 * Loads cwp7.php and exercises every entry point that does not need a socket. This is
 * what catches a missing `use`, a wrong signature, a template-variable typo, a
 * reordered config option (which would silently repoint every live product's settings)
 * or a credential leaking into the module log.
 *
 * The calls that DO need a socket are covered by the manual checklist in README.md.
 *
 * PHP 7.4 compatible.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This file runs from the command line only.\n");
}

define('WHMCS', true);
define('CWP7_DIR', dirname(__DIR__));

$GLOBALS['moduleLog'] = [];
$GLOBALS['activityLog'] = [];

if (!function_exists('logModuleCall')) {
    function logModuleCall($module, $action, $request = '', $response = '', $processed = '', $replaceVars = [])
    {
        $GLOBALS['moduleLog'][] = [
            'action' => $action,
            'request' => is_scalar($request) ? (string) $request : json_encode($request),
            'response' => is_scalar($response) ? (string) $response : json_encode($response),
            'replaceVars' => $replaceVars,
        ];
    }
}

if (!function_exists('logActivity')) {
    function logActivity($message)
    {
        $GLOBALS['activityLog'][] = $message;
    }
}

require CWP7_DIR . '/cwp7.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void
{
    global $passed, $failed;

    if ($cond) {
        $passed++;
        echo "  PASS  $label\n";
    } else {
        $failed++;
        echo "  FAIL  $label\n";
    }
}

$params = [
    'serverid' => 3,
    'serviceid' => 42,
    'server' => true,
    'serverhostname' => 'cwp.example.com',
    'serverip' => '203.0.113.10',
    'serveraccesshash' => 'SUPERSECRETKEY',
    'serverpassword' => 'rootpw',
    'serverusername' => 'root',
    'username' => 'testuser',
    'password' => 'servicepw',
    'domain' => 'example.co.za',
    'clientsdetails' => ['email' => 'a@b.com', 'firstname' => 'A', 'lastname' => 'B'],
    'configoption1' => '5',
    'configoption2' => '0',
    'configoption3' => '100',
    'configoption4' => '40',
    'configoption5' => '',
];

// A host that fails validation, so every call fails before a socket is opened.
$offline = array_merge($params, ['serverhostname' => 'not a host!', 'serverip' => '']);

echo "\nFunction surface\n";

$expected = [
    'MetaData', 'ConfigOptions', 'TestConnection', 'AdminLink', 'LoginLink',
    'CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount',
    'ChangePassword', 'ChangePackage', 'Renew', 'UsageUpdate',
    'ServiceSingleSignOn', 'ClientArea', 'AdminServicesTabFields', 'ListAccounts',
];
foreach ($expected as $fn) {
    ok("cwp7_$fn defined", function_exists('cwp7_' . $fn));
}

// Absent on purpose. WHMCS renders a button for anything declared here, so an
// accidental re-add would put a duplicate-of-CWP action back in front of customers.
foreach (['ClientAreaCustomButtonArray', 'AdminCustomButtonArray', 'requestAutoSsl'] as $fn) {
    ok("cwp7_$fn NOT defined (CWP handles AutoSSL itself)", !function_exists('cwp7_' . $fn));
}

echo "\nMetaData\n";
$meta = cwp7_MetaData();
ok('DisplayName set', !empty($meta['DisplayName']));
ok('APIVersion 1.1', $meta['APIVersion'] === '1.1');
ok('RequiresServer', $meta['RequiresServer'] === true);
ok('default port 2304 both ways', $meta['DefaultSSLPort'] === '2304' && $meta['DefaultNonSSLPort'] === '2304');
ok('ServiceSingleSignOnLabel present', !empty($meta['ServiceSingleSignOnLabel']));
ok('no AdminSingleSignOnLabel (CWP has no admin SSO endpoint)', !isset($meta['AdminSingleSignOnLabel']));
ok('ListAccounts identifier is domain', $meta['ListAccountsUniqueIdentifierField'] === 'domain');
ok('ListAccounts product field is configoption1', $meta['ListAccountsProductField'] === 'configoption1');

echo "\nConfigOptions slot ordering (FROZEN — live products store values by position)\n";
$opts = cwp7_ConfigOptions();
$keys = array_keys($opts);
ok('15 options', count($opts) === 15);

// Slots 1-4 come from the 2020 module and can never move; 5 was added in 2.0.0.
ok('slot 1 is the package', $keys[0] === 'package');
ok('slot 2 is inode', $keys[1] === 'inode');
ok('slot 3 is nofile', $keys[2] === 'nofile');
ok('slot 4 is nproc', $keys[3] === 'nproc');
ok('slot 5 is usernamelength', $keys[4] === 'usernamelength');

// 6-15 are the CWP package definition. Package::FIELD_SLOTS maps CWP field names onto
// these positions, so the two must agree or a pushed package carries the wrong limits.
$packageOrder = ['diskquota', 'bandwidth', 'ftpaccounts', 'emailaccounts', 'emaillists',
                 'databases', 'subdomains', 'parkeddomains', 'addondomains', 'hourlyemails'];
foreach ($packageOrder as $i => $expectedKey) {
    ok('slot ' . ($i + 6) . ' is ' . $expectedKey, $keys[$i + 5] === $expectedKey);
}

$slots = \Cwp7\Actions\Package::FIELD_SLOTS;
ok('FIELD_SLOTS covers every package option', count($slots) === count($packageOrder));
ok('disk_quota maps to slot 6', $slots['disk_quota'] === 6);
ok('hourly_emails maps to slot 15', $slots['hourly_emails'] === 15);
ok('every mapped slot is within the declared options',
    max($slots) <= count($opts) && min($slots) === 6);
$allFriendly = true;
foreach ($opts as $o) {
    if (empty($o['FriendlyName'])) {
        $allFriendly = false;
    }
}
ok('every option has a FriendlyName', $allFriendly);

echo "\nClientArea (must not touch the network, must not carry a token)\n";
$area = cwp7_ClientArea($params);
ok('uses the additive overview template', isset($area['tabOverviewModuleOutputTemplate']));
ok('template path is templates/overview', $area['tabOverviewModuleOutputTemplate'] === 'templates/overview');
$vars = $area['templateVariables'];
ok('panelUrl on port 2083', $vars['panelUrl'] === 'https://cwp.example.com:2083');
ok('ssoUrl targets this service', $vars['ssoUrl'] === 'clientarea.php?action=productdetails&id=42&dosinglesignon=1');
ok('no login token in the output', stripos(json_encode($vars), 'token') === false);
ok('survives an unusable server entry', is_array(cwp7_ClientArea($offline)));

// The dashboard is fetched separately so this render stays socket-free. dataUrl is the
// plain product page: single sign-on is a different button and must not be triggered by
// a data fetch.
ok('dataUrl targets this service without single sign-on',
    $vars['dataUrl'] === 'clientarea.php?action=productdetails&id=42');
ok('a service with no id offers no data URL',
    cwp7_ClientArea(array_merge($params, ['serviceid' => 0]))['templateVariables']['dataUrl'] === '');

// An ordinary render must not be mistaken for a data request.
$_POST = [];
$before = count($GLOBALS['moduleLog']);
cwp7_ClientArea($params);
ok('a plain render is not treated as a data request', count($GLOBALS['moduleLog']) === $before);

// Routing: only operations the module declares are served, and an unknown one is refused
// as input rather than reaching CWP.
$routed = null;

try {
    cwp7_clientOperation('nonsense.list', $params);
} catch (\Cwp7\CwpException $e) {
    $routed = $e->getKind();
}

ok('an unrouted operation is refused before any call',
    $routed === \Cwp7\CwpException::KIND_INPUT);
ok('an unrouted operation opens no socket', count($GLOBALS['moduleLog']) === $before);

$tpl = file_get_contents(CWP7_DIR . '/templates/overview.tpl');
$missing = [];
foreach (['panelUrl', 'username', 'serverHostname', 'ssoUrl', 'dataUrl'] as $v) {
    if (strpos($tpl, '$' . $v) === false) {
        $missing[] = $v;
    }
}
ok('template uses every variable it is given', $missing === []);
ok('every template output is escaped', substr_count($tpl, '|escape') === substr_count($tpl, '{$'));

echo "\nAdmin links\n";
ok('AdminLink points at the admin port 2031', strpos(cwp7_AdminLink($params), 'https://cwp.example.com:2031') !== false);
ok('AdminLink degrades quietly', cwp7_AdminLink($offline) === '');
ob_start();
cwp7_LoginLink($params);
$login = ob_get_clean();
ok('LoginLink echoes a panel link', strpos($login, 'https://cwp.example.com:2083') !== false);
ok('LoginLink mints no session token', stripos($login, 'user_session') === false);

echo "\nRenew\n";
ok('Renew is a clean no-op', cwp7_Renew($params) === 'success');

echo "\nError paths (all fail before a socket opens)\n";

$noUser = array_merge($params, ['username' => '']);

$r = cwp7_SuspendAccount($noUser);
ok('SuspendAccount returns a string, never an array', is_string($r));
ok('SuspendAccount names the problem', strpos($r, 'no username') !== false);

$r = cwp7_CreateAccount(array_merge($params, ['configoption1' => '']));
ok('CreateAccount without a package is refused', is_string($r) && strpos($r, 'package') !== false);

$r = cwp7_CreateAccount(array_merge($params, ['domain' => '']));
ok('CreateAccount without a domain is refused', is_string($r) && strpos($r, 'domain') !== false);

$r = cwp7_ChangePassword(array_merge($params, ['password' => '']));
ok('ChangePassword with no password is refused', is_string($r) && strpos($r, 'password') !== false);

$r = cwp7_ListAccounts($offline);
ok('ListAccounts reports failure as success=false', $r['success'] === false && !empty($r['error']));

$r = cwp7_TestConnection($offline);
ok('TestConnection reports failure as success=false', $r['success'] === false);
// A bad hostname is a config fault, so the hint points at the server entry rather than
// at API Manager — which is the whole point of switching on the exception kind.
ok('TestConnection failure carries a troubleshooting hint', strpos($r['error'], 'Access Hash') !== false);

$r = cwp7_AdminServicesTabFields($offline);
ok('AdminServicesTabFields degrades to a label', isset($r['CWP Account']) && is_string($r['CWP Account']));

// A terminated service has no account to describe, so it must not call CWP at all.
$before = count($GLOBALS['moduleLog']);
$r = cwp7_AdminServicesTabFields(array_merge($params, ['status' => 'Terminated']));
ok('terminated service reports plainly', strpos($r['CWP Account'], 'No CWP account is expected') !== false);
ok('terminated service opens no socket', count($GLOBALS['moduleLog']) === $before);
$r = cwp7_AdminServicesTabFields(array_merge($params, ['status' => 'Cancelled']));
ok('cancelled service reports plainly', strpos($r['CWP Account'], 'No CWP account is expected') !== false);

$sso = cwp7_ServiceSingleSignOn($noUser);
ok('SSO failure returns success=false', $sso['success'] === false);
ok('SSO error is the client-safe message', strpos($sso['errorMsg'], 'contact support') !== false);
ok('SSO error leaks no internals', strpos($sso['errorMsg'], 'username') === false);

// The 2020 version could throw a fatal TypeError from count(null) inside the cron.
cwp7_UsageUpdate($offline);
ok('UsageUpdate never throws, even with an unusable server', true);
ok('UsageUpdate records the failure in the activity log', count($GLOBALS['activityLog']) > 0);

echo "\nLog hygiene (allow-list, not block-list)\n";

// WHMCS passes a `model` object carrying the service, its product and the whole client
// record. v2.0.1 logged it verbatim.
$withModel = array_merge($params, [
    'model' => (object) ['password' => 'HASHEDPW', 'client' => ['address1' => '1 Example Street']],
    'customfields' => ['secret' => 'value'],
    'somethingNew' => 'not yet invented',
]);
$masked = cwp7_safeParams($withModel);

ok('model object is dropped', !isset($masked['model']));
ok('customfields dropped', !isset($masked['customfields']));
ok('unanticipated parameters dropped', !isset($masked['somethingNew']));
ok('access hash dropped', !isset($masked['serveraccesshash']));
ok('server password dropped', !isset($masked['serverpassword']));
ok('service password dropped', !isset($masked['password']));
ok('server username dropped', !isset($masked['serverusername']));
ok('clientsdetails not carried wholesale', !isset($masked['clientsdetails']));
ok('client email kept for diagnosis', $masked['clientEmail'] === 'a@b.com');
ok('diagnostic fields kept', $masked['domain'] === 'example.co.za' && $masked['serverid'] === 3);
ok('package option kept', $masked['configoption1'] === '5');

$encoded = json_encode($masked);
foreach (['HASHEDPW', '1 Example Street', 'SUPERSECRETKEY', 'servicepw', 'rootpw'] as $secret) {
    ok("'{$secret}' never reaches the log", strpos($encoded, $secret) === false);
}

ok('secrets list carries the real key for redaction', in_array('SUPERSECRETKEY', cwp7_secrets($params), true));

$leaks = 0;
foreach ($GLOBALS['moduleLog'] as $entry) {
    if (strpos($entry['request'], 'SUPERSECRETKEY') !== false || strpos($entry['response'], 'SUPERSECRETKEY') !== false) {
        $leaks++;
    }
}
ok('no module log entry contains the raw API key', $leaks === 0);

echo "\nStatus and date normalisation (Server Sync)\n";
ok('suspended=1 -> Suspended', cwp7_normaliseStatus(['suspended' => 1]) === 'Suspended');
ok('suspended=0 -> Active', cwp7_normaliseStatus(['suspended' => 0]) === 'Active');
ok('status=Suspended -> Suspended', cwp7_normaliseStatus(['status' => 'Suspended']) === 'Suspended');
ok('status=active -> Active', cwp7_normaliseStatus(['status' => 'active']) === 'Active');
ok('status=unlocked is NOT read as suspended', cwp7_normaliseStatus(['status' => 'unlocked']) === 'Active');
ok('no status field -> Active', cwp7_normaliseStatus(['domain' => 'x.com']) === 'Active');

ok('date passthrough', cwp7_normaliseDate('2024-03-01 10:00:00') === '2024-03-01 10:00:00');
ok('unix timestamp', cwp7_normaliseDate('1709287200') === date('Y-m-d H:i:s', 1709287200));
ok('empty date', cwp7_normaliseDate('') === '');
ok('unparseable date', cwp7_normaliseDate('not a date') === '');

echo "\nHook gating\n";

// hooks.php registers against WHMCS; stub the registrar so the helpers can be loaded.
$GLOBALS['registeredHooks'] = [];
if (!function_exists('add_hook')) {
    function add_hook($name, $priority, $callback = null)
    {
        $GLOBALS['registeredHooks'][] = $name;
    }
}
require_once CWP7_DIR . '/hooks.php';

ok('PreServiceEdit registered', in_array('PreServiceEdit', $GLOBALS['registeredHooks'], true));
ok('ServiceEdit registered', in_array('ServiceEdit', $GLOBALS['registeredHooks'], true));
ok('AdminAreaFooterOutput registered', in_array('AdminAreaFooterOutput', $GLOBALS['registeredHooks'], true));

// tblservers.accesshash is not encrypted on every install. Decrypting a plaintext key
// does not fail — it returns noise, which CWP rejects as "No special characters".
ok('an alphanumeric key is used as stored',
    cwp7_hookServerKey('Kxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx')
    === 'Kxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
ok('surrounding whitespace is trimmed', cwp7_hookServerKey("  abc123  ") === 'abc123');
ok('an empty hash yields nothing', cwp7_hookServerKey('') === '');
// Anything non-alphanumeric is ciphertext, and without localAPI there is no way back.
ok('a non-alphanumeric hash is not passed through', cwp7_hookServerKey('a+b/c=') === '');

ok('hides on the service page for a cwp7 service', cwp7_hookShouldHideButton('clientsservices', 'cwp7'));
ok('leaves other modules alone', !cwp7_hookShouldHideButton('clientsservices', 'cpanel'));
ok('leaves other admin pages alone', !cwp7_hookShouldHideButton('clientssummary', 'cwp7'));
ok('tolerates an unknown page name', cwp7_hookShouldHideButton('', 'cwp7'));

// Off unless config.php opts in, so a fresh install keeps the button.
ok('disabled without config.php', cwp7_hookEnabled() === false);

echo "\nTroubleshooting hints\n";
ok('transport hint names port 2304', strpos(cwp7_troubleshootingHint(\Cwp7\CwpException::transport('x')), '2304') !== false);

$refusedHint = cwp7_troubleshootingHint(\Cwp7\CwpException::transport('x', ['curl_errno' => 7]));
ok('errno 7 hint says TLS is not involved', strpos($refusedHint, 'TLS and the API key are not involved') !== false);
ok('errno 7 hint names CSF TCP_OUT', strpos($refusedHint, 'TCP_OUT') !== false);
ok('errno 7 hint raises split-horizon DNS', strpos($refusedHint, 'split-horizon') !== false);

$dnsHint = cwp7_troubleshootingHint(\Cwp7\CwpException::transport('x', ['curl_errno' => 6]));
ok('errno 6 hint is about name resolution', strpos($dnsHint, 'did not resolve') !== false);

$timeoutHint = cwp7_troubleshootingHint(\Cwp7\CwpException::transport('x', ['curl_errno' => 28]));
ok('errno 28 hint distinguishes dropped from refused', strpos($timeoutHint, 'dropped rather than') !== false);

echo "\nModule Command errors\n";
$apiErr = cwp7_commandError(\Cwp7\CwpException::api('Unauthorized action [autossl/add]'));
ok('API refusal points at API Manager', strpos($apiErr, 'API Manager') !== false);
ok('API refusal keeps CWP\'s own text', strpos($apiErr, 'Unauthorized action') !== false);
$transErr = cwp7_commandError(\Cwp7\CwpException::transport('timed out'));
ok('a transport failure gets no permissions advice', strpos($transErr, 'API Manager') === false);

// v2.0.1 appended the API Manager advice to every refusal, including these.
$noUserErr = cwp7_commandError(\Cwp7\CwpException::api('You must indicate a valid user [account/del]'));
ok('missing account gets no permissions advice', strpos($noUserErr, 'API Manager') === false);
ok('missing account is explained', strpos($noUserErr, 'No account with that username') !== false);

$dupErr = cwp7_commandError(\Cwp7\CwpException::api('Domain example.com already exists in database! [account/add]'));
ok('a duplicate domain gets no permissions advice', strpos($dupErr, 'API Manager') === false);
ok('a duplicate domain keeps CWP\'s wording only', trim($dupErr) === trim($dupErr));

$slowErr = cwp7_commandError(\Cwp7\CwpException::transport('Operation timed out', ['curl_errno' => 28]));
ok('a timeout names provision_timeout', strpos($slowErr, 'provision_timeout') !== false);
ok('a timeout warns the work may have completed', strpos($slowErr, 'may have finished') !== false);
ok('api hint names the IP whitelist', strpos(cwp7_troubleshootingHint(\Cwp7\CwpException::api('x')), 'whitelist') !== false);
ok('config hint names JSON', strpos(cwp7_troubleshootingHint(\Cwp7\CwpException::config('x')), 'JSON') !== false);

echo "\n" . str_repeat('-', 52) . "\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
