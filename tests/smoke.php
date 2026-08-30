<?php
/**
 * Standalone smoke tests. No framework, no network, no WHMCS.
 *
 *   php tests/smoke.php
 *
 * Covers the parts of the module that are pure logic: host normalisation, config
 * validation, the URL-injection guards, response interpretation, autologin URL
 * extraction and constraint, username normalisation and usage coercion. Everything
 * that needs a live CWP box belongs in the Phase 0 probes instead — see
 * docs/cwp-api-map.md.
 *
 * PHP 7.4 compatible, like the module itself, so it can also be run on the WHMCS host.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This file runs from the command line only.\n");
}

require_once __DIR__ . '/../lib/CwpException.php';
require_once __DIR__ . '/../lib/CwpClient.php';
require_once __DIR__ . '/../lib/ClientRequest.php';
require_once __DIR__ . '/../lib/Validate.php';
require_once __DIR__ . '/../lib/Actions/Account.php';
require_once __DIR__ . '/../lib/Actions/Dashboard.php';
require_once __DIR__ . '/../lib/Actions/Mailbox.php';
require_once __DIR__ . '/../lib/Actions/Package.php';
require_once __DIR__ . '/../lib/Actions/Session.php';
require_once __DIR__ . '/../lib/Actions/Usage.php';

use Cwp7\Actions\Account;
use Cwp7\Actions\Dashboard;
use Cwp7\Actions\Mailbox;
use Cwp7\Actions\Package;
use Cwp7\Actions\Session;
use Cwp7\Actions\Usage;
use Cwp7\ClientRequest;
use Cwp7\CwpClient;
use Cwp7\CwpException;
use Cwp7\Validate;

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

function throwsKind(string $label, callable $fn, string $expectedKind): void
{
    try {
        $fn();
        ok($label . ' (expected ' . $expectedKind . ', nothing thrown)', false);
    } catch (CwpException $e) {
        ok($label . ' -> ' . $e->getKind(), $e->getKind() === $expectedKind);
    }
}

/**
 * Reach a private method for testing.
 *
 * @param object|string     $target
 * @param array<int,mixed>  $args
 *
 * @return mixed
 */
function invokePrivate($target, string $method, array $args)
{
    $class = is_object($target) ? get_class($target) : $target;
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs(is_object($target) ? $target : null, $args);
}

/**
 * @param array<string,mixed> $overrides
 */
function makeClient(array $overrides = []): CwpClient
{
    return new CwpClient(array_merge([
        'host' => 'cwp.example.com',
        'key' => 'test-key-abc123',
        'api_port' => 2304,
        'panel_port' => 2083,
        'admin_port' => 2031,
        'verify_tls' => true,
        'connect_timeout' => 5,
        'timeout' => 20,
        'debug' => false,
    ], $overrides));
}

// ---------------------------------------------------------------------------

echo "\nHost normalisation\n";

$hostCases = [
    ['cwp.example.com', 'cwp.example.com'],
    ['  cwp.example.com  ', 'cwp.example.com'],
    ['https://cwp.example.com', 'cwp.example.com'],
    ['https://cwp.example.com:2304/', 'cwp.example.com'],
    ['http://cwp.example.com/path/here', 'cwp.example.com'],
    ['203.0.113.10', '203.0.113.10'],
    ['https://203.0.113.10:2304', '203.0.113.10'],
    ['', null],
    ['   ', null],
    ['not a host!', null],
];

foreach ($hostCases as $case) {
    $actual = invokePrivate(CwpClient::class, 'normaliseHost', [$case[0]]);
    ok(
        sprintf('%-34s -> %s', var_export($case[0], true), var_export($case[1], true)),
        $actual === $case[1]
    );
}

echo "\nConstructor validation\n";

throwsKind('empty API key rejected', function () { makeClient(['key' => '']); }, CwpException::KIND_CONFIG);
throwsKind('whitespace API key rejected', function () { makeClient(['key' => '   ']); }, CwpException::KIND_CONFIG);
throwsKind('empty host rejected', function () { makeClient(['host' => '']); }, CwpException::KIND_CONFIG);
throwsKind('malformed host rejected', function () { makeClient(['host' => 'not a host!']); }, CwpException::KIND_CONFIG);
throwsKind('port 0 rejected', function () { makeClient(['api_port' => 0]); }, CwpException::KIND_CONFIG);
throwsKind('port 70000 rejected', function () { makeClient(['api_port' => 70000]); }, CwpException::KIND_CONFIG);

$client = makeClient();
ok('valid config constructs', $client->getHost() === 'cwp.example.com');
ok('panel URL uses the panel port', $client->getPanelUrl() === 'https://cwp.example.com:2083');
ok('admin URL uses the admin port', $client->getAdminUrl() === 'https://cwp.example.com:2031');
ok('getOption reads merged settings', $client->getOption('timeout') === 20);
ok('getOption falls back', $client->getOption('nope', 'dflt') === 'dflt');

echo "\nURL-injection guards (must reject before any socket opens)\n";

$badFunctions = [
    '../account',
    'account/../../etc/passwd',
    'account?key=x',
    'Account',
    'account name',
    '',
    'account#frag',
    'account&action=del',
];

foreach ($badFunctions as $fn) {
    throwsKind(
        sprintf('function %-26s rejected', var_export($fn, true)),
        function () use ($client, $fn) { $client->call($fn, 'list'); },
        CwpException::KIND_CONFIG
    );
}

foreach (['li st', 'LIST', 'list;del', '', 'list1'] as $action) {
    throwsKind(
        sprintf('action %-28s rejected', var_export($action, true)),
        function () use ($client, $action) { $client->call('account', $action); },
        CwpException::KIND_CONFIG
    );
}

echo "\nResponse interpretation\n";

$ctx = ['function' => 'test', 'action' => 'list'];

// Numeric libcurl codes, not CURLE_* constants — the constant set varies by build and
// CURLE_PEER_FAILED_VERIFICATION is undefined on PHP 8.3. See TLS_VERIFY_ERRORS.
throwsKind(
    'cURL timeout (28) -> transport',
    function () use ($client, $ctx) { invokePrivate($client, 'interpret', ['', 0, 28, 'timed out', $ctx]); },
    CwpException::KIND_TRANSPORT
);

throwsKind(
    'could not connect (7) -> transport',
    function () use ($client, $ctx) { invokePrivate($client, 'interpret', ['', 0, 7, 'refused', $ctx]); },
    CwpException::KIND_TRANSPORT
);

foreach ([51, 60, 77, 83] as $tlsErrno) {
    $thrown = null;
    try {
        invokePrivate($client, 'interpret', ['', 0, $tlsErrno, 'cert problem', $ctx]);
    } catch (CwpException $e) {
        $thrown = $e;
    }

    ok(
        "TLS error {$tlsErrno} -> transport, and names ca_bundle as the fix",
        $thrown !== null
            && $thrown->getKind() === CwpException::KIND_TRANSPORT
            && strpos($thrown->getMessage(), 'ca_bundle') !== false
    );
}

echo "\nTimeout budgets\n";

$timed = makeClient(['timeout' => 20, 'provision_timeout' => 180]);
ok('reads use the short budget', $timed->timeoutFor('list') === 20);
foreach (['add', 'udp', 'del', 'susp', 'unsp'] as $writeAction) {
    ok("'{$writeAction}' uses the provisioning budget", $timed->timeoutFor($writeAction) === 180);
}
$defaults = new CwpClient(['host' => 'cwp.example.com', 'key' => 'k']);
ok('provision timeout defaults to 180', $defaults->timeoutFor('add') === 180);
ok('read timeout defaults to 20', $defaults->timeoutFor('list') === 20);

echo "\nTransport diagnostics (the resolved address must be named)\n";

$refused = null;
try {
    invokePrivate($client, 'interpret', [
        '', 0, 7, 'Failed to connect',
        array_merge($ctx, ['curl_errno' => 7, 'resolved_ip' => '10.0.0.201']),
    ]);
} catch (CwpException $e) {
    $refused = $e;
}
ok('refusal names the address actually dialled', $refused !== null && strpos($refused->getMessage(), '10.0.0.201') !== false);
ok('refusal flags an RFC1918 address', $refused !== null && strpos($refused->getMessage(), 'private (RFC1918)') !== false);

// On a timeout the socket opened, so where the name pointed is not the problem.
$timedOut = null;
try {
    invokePrivate($client, 'interpret', [
        '', 0, 28, 'Operation timed out',
        array_merge($ctx, ['curl_errno' => 28, 'resolved_ip' => '10.0.0.201']),
    ]);
} catch (CwpException $e) {
    $timedOut = $e;
}
ok('timeout still names the address', $timedOut !== null && strpos($timedOut->getMessage(), '10.0.0.201') !== false);
ok('timeout omits the RFC1918 advice', $timedOut !== null && strpos($timedOut->getMessage(), 'RFC1918') === false);

$public = null;
try {
    invokePrivate($client, 'interpret', [
        '', 0, 7, 'Failed to connect',
        array_merge($ctx, ['curl_errno' => 7, 'resolved_ip' => '198.51.100.7']),
    ]);
} catch (CwpException $e) {
    $public = $e;
}
ok('public address is named but not flagged', $public !== null
    && strpos($public->getMessage(), '198.51.100.7') !== false
    && strpos($public->getMessage(), 'RFC1918') === false);

ok('isPrivateIp: 10.0.0.201', CwpClient::isPrivateIp('10.0.0.201'));
ok('isPrivateIp: 10.0.0.5', CwpClient::isPrivateIp('10.0.0.5'));
ok('isPrivateIp: 172.16.0.1', CwpClient::isPrivateIp('172.16.0.1'));
ok('isPrivateIp: 127.0.0.1', CwpClient::isPrivateIp('127.0.0.1'));
ok('isPrivateIp: 198.51.100.7 is public', !CwpClient::isPrivateIp('198.51.100.7'));
ok('isPrivateIp: not an IP', !CwpClient::isPrivateIp('cwp.example.com'));

throwsKind(
    'empty body -> protocol',
    function () use ($client, $ctx) { invokePrivate($client, 'interpret', ['', 200, 0, '', $ctx]); },
    CwpException::KIND_PROTOCOL
);

throwsKind(
    'HTTP 404 -> protocol (drives the ping() fallback)',
    function () use ($client, $ctx) { invokePrivate($client, 'interpret', ['not found', 404, 0, '', $ctx]); },
    CwpException::KIND_PROTOCOL
);

throwsKind(
    'non-JSON body -> protocol',
    function () use ($client, $ctx) { invokePrivate($client, 'interpret', ['garbage not json', 200, 0, '', $ctx]); },
    CwpException::KIND_PROTOCOL
);

throwsKind(
    'XML body -> config (key set to XML)',
    function () use ($client, $ctx) { invokePrivate($client, 'interpret', ['<?xml version="1.0"?><r/>', 200, 0, '', $ctx]); },
    CwpException::KIND_CONFIG
);

throwsKind(
    'status=Error -> api',
    function () use ($client, $ctx) {
        invokePrivate($client, 'interpret', ['{"status":"Error","msg":"Account does not exist"}', 200, 0, '', $ctx]);
    },
    CwpException::KIND_API
);

// The whole point of MESSAGE_KEYS: the 2020 field name must still surface.
$legacyErr = null;
try {
    invokePrivate($client, 'interpret', ['{"status":"Error","msj":"Account does not exist"}', 200, 0, '', $ctx]);
} catch (CwpException $e) {
    $legacyErr = $e;
}
ok(
    'status=Error with legacy "msj" still reports the reason',
    $legacyErr !== null && strpos($legacyErr->getMessage(), 'Account does not exist') !== false
);

// CWP quotes the submitted key back in "Unauthorized action <key>". That text lands on
// the admin's screen, so the key must never survive the trip.
$echoed = null;
try {
    invokePrivate($client, 'interpret', [
        '{"status":"Error","msg":"Unauthorized action test-key-abc123"}', 200, 0, '', $ctx,
    ]);
} catch (CwpException $e) {
    $echoed = $e;
}
ok('an echoed API key is redacted from the error', $echoed !== null
    && strpos($echoed->getMessage(), 'test-key-abc123') === false);
ok('the rest of the error survives redaction', $echoed !== null
    && strpos($echoed->getMessage(), 'Unauthorized action') !== false);
ok('redaction is visible, not silent', $echoed !== null
    && strpos($echoed->getMessage(), '[api key redacted]') !== false);
ok('the refused function/action pair is named', $echoed !== null
    && strpos($echoed->getMessage(), '[test/list]') !== false);

$echoedBody = null;
try {
    invokePrivate($client, 'interpret', ['plain text with test-key-abc123 inside', 200, 0, '', $ctx]);
} catch (CwpException $e) {
    $echoedBody = $e;
}
ok('a key echoed in a non-JSON body is redacted too', $echoedBody !== null
    && strpos($echoedBody->getMessage(), 'test-key-abc123') === false);

throwsKind(
    'missing status field -> api',
    function () use ($client, $ctx) { invokePrivate($client, 'interpret', ['{"data":1}', 200, 0, '', $ctx]); },
    CwpException::KIND_API
);

$okResponse = invokePrivate($client, 'interpret', ['{"status":"OK","emailadmin":"a@b.com"}', 200, 0, '', $ctx]);
ok('status=OK returns the decoded array', $okResponse['emailadmin'] === 'a@b.com');

$lowerOk = invokePrivate($client, 'interpret', ['{"status":"ok","x":1}', 200, 0, '', $ctx]);
ok('status is matched case-insensitively', $lowerOk['x'] === 1);

echo "\nPayload extraction (msg / msj)\n";

ok('payload prefers result', CwpClient::payload(['result' => 'r', 'msg' => 'a', 'msj' => 'b']) === 'r');
ok('payload falls back to msg', CwpClient::payload(['msg' => 'a', 'msj' => 'b']) === 'a');
ok('payload falls back to msj', CwpClient::payload(['msj' => 'b']) === 'b');
ok('payload absent -> null', CwpClient::payload(['status' => 'OK']) === null);

// Current CWP builds return data under `result`; missing this key silently emptied
// UsageUpdate and ListAccounts.
$accountDetail = [
    'status' => 'OK',
    'result' => [
        'domains' => [['domain' => 'example.com']],
        'account_info' => [
            'package_name' => 'Regular',
            'space_usage' => 480715.59765625,
            'space_disk' => 700000,
            'bandwidth' => -1,
            'bandwidth_used' => 2477,
            'state' => 'active',
        ],
    ],
];
$unwrapped = CwpClient::payload($accountDetail);
ok('result payload is unwrapped', is_array($unwrapped) && isset($unwrapped['account_info']));
ok('nested account_info survives', $unwrapped['account_info']['package_name'] === 'Regular');
ok('disk usage read from nested account_info', Usage::diskUsageFrom($unwrapped) === 480715.59765625);
ok('diskUsageFrom on a non-array', Usage::diskUsageFrom('nope') === null);
ok('diskUsageFrom when the key is absent', Usage::diskUsageFrom(['account_info' => []]) === null);

// account/list wraps under `msj` and names disk usage `diskused` — a different key and
// a different wrapper from accountdetail on the same server.
$accountList = [
    'status' => 'OK',
    'msj' => [
        [
            'package_name' => 'Regular', 'idpackage' => 2, 'username' => 'connect',
            'domain' => 'example.com', 'ip_address' => '198.51.100.7',
            'setup_date' => '2026-03-05 15:15:57',
            'diskused' => 1, 'disklimit' => 700000,
            'bandwidth' => 5, 'bwlimit' => -1, 'status' => 'active',
        ],
        [
            'package_name' => 'Small', 'idpackage' => 6, 'username' => 'second',
            'domain' => 'example.net', 'ip_address' => '198.51.100.7',
            'diskused' => 1, 'disklimit' => 3000,
            'bandwidth' => 56, 'bwlimit' => 5000, 'status' => 'suspended',
        ],
    ],
];
$listRows = CwpClient::rows($accountList);
ok('msj payload yields rows', count($listRows) === 2);
ok('diskused resolves through the candidates',
    Usage::numeric(Usage::pick($listRows[0], Usage::FIELD_CANDIDATES['diskusage'])) === 1.0);
ok('disklimit resolves',
    Usage::numeric(Usage::pick($listRows[0], Usage::FIELD_CANDIDATES['disklimit'])) === 700000.0);
ok('bandwidth is read as usage, not a limit',
    Usage::numeric(Usage::pick($listRows[0], Usage::FIELD_CANDIDATES['bwusage'])) === 5.0);
ok('bwlimit -1 becomes 0 (unlimited)',
    Usage::numeric(Usage::pick($listRows[0], Usage::FIELD_CANDIDATES['bwlimit'])) === 0.0);
ok('a real bwlimit survives',
    Usage::numeric(Usage::pick($listRows[1], Usage::FIELD_CANDIDATES['bwlimit'])) === 5000.0);

echo "\nService matching\n";

$services = [
    'domain' => ['example.com' => 11, 'example.net' => 12],
    'username' => ['connect' => 11, 'legacy' => 13],
];
ok('matches on domain', Usage::matchService($services, 'example.com', 'connect') === 11);
ok('domain wins over username', Usage::matchService($services, 'example.net', 'connect') === 12);
ok('falls back to username', Usage::matchService($services, '', 'legacy') === 13);
ok('username match is case-insensitive', Usage::matchService($services, '', 'LEGACY') === 13);
ok('no match returns null', Usage::matchService($services, 'nowhere.com', 'nobody') === null);

$rows = CwpClient::rows(['msj' => [['user' => 'bob'], ['user' => 'sue']]]);
ok('rows returns a list', count($rows) === 2 && $rows[1]['user'] === 'sue');

$single = CwpClient::rows(['msg' => ['user' => 'bob']]);
ok('rows promotes a bare row to a list', count($single) === 1 && $single[0]['user'] === 'bob');

ok('rows tolerates a string payload', CwpClient::rows(['msg' => 'nope']) === []);
ok('rows tolerates a missing payload', CwpClient::rows(['status' => 'OK']) === []);

echo "\nError message flattening\n";

ok('string msg', CwpClient::flattenMessage('plain') === 'plain');
ok('int msg', CwpClient::flattenMessage(42) === '42');
ok('array msg -> JSON', CwpClient::flattenMessage(['a' => 1]) === '{"a":1}');
ok('null msg', CwpClient::flattenMessage(null) === 'unknown error');

echo "\nAutologin URL extraction\n";

$shapes = [
    'details wrapper (2020 shape)' => [['details' => [['url' => 'https://h:2083/l?t=1']]], 'https://h:2083/l?t=1'],
    'bare list of rows' => [[['url' => 'https://h:2083/l?t=2']], 'https://h:2083/l?t=2'],
    'single row' => [['url' => 'https://h:2083/l?t=3'], 'https://h:2083/l?t=3'],
    'plain string' => ['https://h:2083/l?t=4', 'https://h:2083/l?t=4'],
    'alternate key' => [['link' => 'https://h:2083/l?t=5'], 'https://h:2083/l?t=5'],
];

foreach ($shapes as $label => $case) {
    ok('extractUrl: ' . $label, Session::extractUrl($case[0]) === $case[1]);
}

ok('extractUrl rejects a non-URL string', Session::extractUrl('Account does not exist') === null);
ok('extractUrl on an empty payload', Session::extractUrl([]) === null);
ok('extractUrl on null', Session::extractUrl(null) === null);

echo "\nAutologin host constraint (open-redirect guard)\n";

$session = new Session($client, 'bob', false);

$same = invokePrivate($session, 'constrainToConfiguredHost', ['https://cwp.example.com:2083/login?t=abc']);
ok('matching host passes through untouched', $same === 'https://cwp.example.com:2083/login?t=abc');

$foreign = invokePrivate($session, 'constrainToConfiguredHost', ['https://evil.example.net:2083/login?t=abc']);
ok(
    'foreign host is rewritten to the configured host, token intact',
    $foreign === 'https://cwp.example.com:2083/login?t=abc'
);

$cleartext = invokePrivate($session, 'constrainToConfiguredHost', ['http://cwp.example.com:2083/login?t=abc']);
ok('cleartext is upgraded to https', strpos($cleartext, 'https://') === 0);

$trusting = new Session($client, 'bob', true);
$kept = invokePrivate($trusting, 'constrainToConfiguredHost', ['https://cwp-real.example.com:2083/l?t=1']);
ok('trust_returned_host keeps CWP\'s host', $kept === 'https://cwp-real.example.com:2083/l?t=1');


echo "\nCWP field contracts (add and udp disagree; both differ from the 2020 module)\n";

$serviceParams = [
    'username' => 'demo',
    'password' => 'servicepw',
    'domain' => 'example.com',
    'serverip' => '198.51.100.7',
    'clientsdetails' => ['email' => 'owner@example.com'],
    'configoption1' => '10',
    'configoption2' => '0',
    'configoption3' => '150',
    'configoption4' => '40',
];
$account = new Account(makeClient(), $serviceParams);

$add = $account->createFields('demo', 'example.com', '10');
ok('add: limit_nofile carries the open-file limit', $add['limit_nofile'] === '150');
ok('add: limit_nproc carries the process limit', $add['limit_nproc'] === '40');
ok('add: package is sent bare, no @', $add['package'] === '10');
ok('add: email included', $add['email'] === 'owner@example.com');
ok('add: no nofile/nproc (neither endpoint accepts them)', !isset($add['nofile']) && !isset($add['nproc']));
ok('add: no openfiles/processes (those are udp names)', !isset($add['openfiles']) && !isset($add['processes']));

// Three endpoints, three package formats. changepack takes the bare ID.
$pack = $account->changePackFields('demo', '10');
ok('changepack: package is the bare ID, no @', $pack['package'] === '10');
ok('changepack: sends only user and package', array_keys($pack) === ['user', 'package']);

$udp = $account->packageFields('demo', '10');
ok('udp: openfiles carries the open-file limit', $udp['openfiles'] === '150');
ok('udp: processes carries the process limit', $udp['processes'] === '40');
ok('udp: package is @-prefixed, not suffixed', $udp['package'] === '@10');
ok('udp: email included (CWP requires it)', $udp['email'] === 'owner@example.com');
ok('udp: no limit_nofile/limit_nproc (those are add names)',
    !isset($udp['limit_nofile']) && !isset($udp['limit_nproc']));

// Some servers refuse account/udp with Account/UPD granted, so the call can be turned
// off rather than failing on every package change. Off means no socket is opened at all.
$limitsOff = new Account(makeClient(['apply_resource_limits' => false]), $serviceParams);
$reached = true;
try {
    invokePrivate($limitsOff, 'applyResourceLimits', ['demo', '10']);
} catch (Throwable $e) {
    $reached = false;
}
ok('apply_resource_limits off skips the call entirely', $reached);
ok('apply_resource_limits defaults to on',
    makeClient()->getOption('apply_resource_limits', true) === true);

echo "\nPackage definitions pushed to CWP\n";

$productOptions = [
    'configoption1' => 'Small Web Hosting',
    'configoption6' => '5000',   // disk_quota
    'configoption7' => '5000',   // bandwidth
    'configoption8' => '2',      // ftp_accounts
    'configoption9' => '500',    // email_accounts
    'configoption11' => '25',    // databases
    'configoption12' => '2',     // sub_domains
];
$definition = Package::definition('Small Web Hosting', $productOptions);

ok('name carried through', $definition['package_name'] === 'Small Web Hosting');
ok('disk_quota mapped from slot 6', $definition['disk_quota'] === '5000');
ok('bandwidth mapped from slot 7', $definition['bandwidth'] === '5000');
ok('databases mapped from slot 11', $definition['databases'] === '25');
// An absent limit keeps CWP's default; sending 0 would mean none allowed.
ok('blank options are omitted, not zeroed',
    !isset($definition['email_lists']) && !isset($definition['parked_domains']));

throwsKind(
    'a package with no disk quota is refused',
    function () { Package::definition('No Disk', ['configoption1' => 'No Disk']); },
    CwpException::KIND_CONFIG
);
throwsKind(
    'a package with no name is refused',
    function () use ($productOptions) { Package::definition('   ', $productOptions); },
    CwpException::KIND_CONFIG
);

// push() decides create-vs-update from the package list, not from CWP's error text.
$serverPackages = [
    ['id' => '2', 'package_name' => 'Regular'],
    ['id' => '3', 'package_name' => 'Linux Medium Web Hosting'],
];
ok('an existing package is recognised', Package::has($serverPackages, 'Regular'));
ok('matching ignores case', Package::has($serverPackages, 'regular'));
ok('surrounding space is ignored', Package::has($serverPackages, '  Regular  '));
ok('an absent package is not claimed', !Package::has($serverPackages, 'Small Web Hosting'));
ok('an empty server list is not claimed', !Package::has([], 'Regular'));

echo "\nPackage resolution\n";

// changepack takes an id and nothing else — a name comes back as a bare "Error" — and
// each server assigns its own id to the same package, so whatever the product holds is
// resolved against the server's list before anything is sent.
$packageRows = [
    ['id' => '2', 'package_name' => 'Regular'],
    ['id' => '3', 'package_name' => 'Linux Medium Web Hosting'],
    ['id' => '8', 'package_name' => 'Large Web Hosting'],
];
$known = Account::packageIdentifiers($packageRows);
ok('ids collected', $known['ids'] === ['2', '3', '8']);
ok('names collected', $known['names'][1] === 'Linux Medium Web Hosting');

$alt = Account::packageIdentifiers([['idpackage' => 9, 'name' => 'Mail'], ['package_id' => 4]]);
ok('alternate id keys are accepted', $alt['ids'] === ['9', '4']);
ok('alternate name keys are accepted', $alt['names'] === ['Mail']);

$unknown = Account::packageIdentifiers([['something' => 'else'], 'not an array']);
ok('an unreadable shape yields nothing, so the check can fail open',
    $unknown['ids'] === [] && $unknown['names'] === []);

ok('a name resolves to the id this server gave it',
    Account::matchPackage($packageRows, 'Linux Medium Web Hosting')['id'] === '3');
ok('resolution ignores case and surrounding space',
    Account::matchPackage($packageRows, '  large web hosting ')['id'] === '8');
ok('an id resolves to itself', Account::matchPackage($packageRows, '8')['id'] === '8');
ok('a name is never mistaken for an id', Account::matchPackage($packageRows, 'Regular')['id'] === '2');

$missed = Account::matchPackage($packageRows, 'Enormous');
ok('an unknown package resolves to nothing', $missed['id'] === '');
ok('a miss names the alternatives', $missed['known'] === [
    'Regular (#2)', 'Linux Medium Web Hosting (#3)', 'Large Web Hosting (#8)',
]);
ok('an unreadable list offers no alternatives, so resolution can fail open',
    Account::matchPackage([['something' => 'else'], 'not an array'], 'Regular')
        === ['id' => '', 'known' => []]);

$noEmail = new Account(makeClient(), array_merge($serviceParams, ['clientsdetails' => []]));
throwsKind(
    'udp without a client email is refused before the call',
    function () use ($noEmail) { $noEmail->packageFields('demo', '10'); },
    CwpException::KIND_CONFIG
);

$noPackage = new Account(makeClient(), array_merge($serviceParams, ['configoption1' => '']));
throwsKind(
    'a product with neither a package nor a name is refused before any call',
    function () use ($noPackage) { invokePrivate($noPackage, 'packageValue', []); },
    CwpException::KIND_CONFIG
);

// A name is as valid a setting as an id — resolution turns either into an id.
$byName = new Account(makeClient(), array_merge($serviceParams, [
    'configoption1' => '  Linux Medium Web Hosting  ',
]));
ok('a package name is taken as set, trimmed',
    invokePrivate($byName, 'packageValue', []) === 'Linux Medium Web Hosting');

echo "\nCustomer input rules (allow-lists: the key behind them is server-wide)\n";

ok('a mailbox name is lowercased and trimmed', Validate::localPart('  Sales  ') === 'sales');
ok('dots, hyphens and underscores are allowed',
    Validate::localPart('first.last-2_x') === 'first.last-2_x');
throwsKind('an empty mailbox name is refused',
    function () { Validate::localPart(''); }, CwpException::KIND_INPUT);
throwsKind('a leading dot is refused',
    function () { Validate::localPart('.sales'); }, CwpException::KIND_INPUT);
throwsKind('a trailing dot is refused',
    function () { Validate::localPart('sales.'); }, CwpException::KIND_INPUT);
throwsKind('consecutive dots are refused',
    function () { Validate::localPart('a..b'); }, CwpException::KIND_INPUT);
throwsKind('an @ cannot be smuggled into the local part',
    function () { Validate::localPart('sales@elsewhere.com'); }, CwpException::KIND_INPUT);
throwsKind('a space is refused',
    function () { Validate::localPart('two words'); }, CwpException::KIND_INPUT);
throwsKind('an over-long mailbox name is refused',
    function () { Validate::localPart(str_repeat('a', 65)); }, CwpException::KIND_INPUT);

ok('an account name is lowercased', Validate::accountName('Backups') === 'backups');
throwsKind('an account name may not start with a digit',
    function () { Validate::accountName('2nd'); }, CwpException::KIND_INPUT);
throwsKind('an account name may not carry a hyphen',
    function () { Validate::accountName('two-words'); }, CwpException::KIND_INPUT);
throwsKind('an over-long account name is refused, since CWP prefixes its own',
    function () { Validate::accountName(str_repeat('a', 17)); }, CwpException::KIND_INPUT);

ok('a strong password is accepted', Validate::password('Str0ng-Pass!') === 'Str0ng-Pass!');
throwsKind('a short password is refused',
    function () { Validate::password('Ab3!efg'); }, CwpException::KIND_INPUT);
throwsKind('an over-long password is refused',
    function () { Validate::password(str_repeat('Aa1!', 17)); }, CwpException::KIND_INPUT);
throwsKind('a password with a space is refused',
    function () { Validate::password('Str0ng Pass!'); }, CwpException::KIND_INPUT);
throwsKind('a password with a non-ASCII byte is refused',
    function () { Validate::password('Str0ngPass' . chr(233) . '!'); }, CwpException::KIND_INPUT);
throwsKind('two character classes are not enough',
    function () { Validate::password('lowercase123'); }, CwpException::KIND_INPUT);

// Ownership is the difference between a form for this account and a form for any
// account on the server.
$detail = [
    'domains' => [['domain' => 'Example.CO.ZA.']],
    'subdomins' => [['subdomain' => 'nhw', 'domain' => 'example.co.za']],
];
ok('domains and subdomains are collected and normalised',
    Validate::domainsIn($detail) === ['example.co.za', 'nhw.example.co.za']);
ok('a payload with neither key yields nothing', Validate::domainsIn([]) === []);
ok('an owned domain is accepted, however it is typed',
    Validate::ownedDomain($detail, '  NHW.Example.co.za. ') === 'nhw.example.co.za');
throwsKind('a domain on another account is refused',
    function () use ($detail) { Validate::ownedDomain($detail, 'someone-else.co.za'); },
    CwpException::KIND_INPUT);
throwsKind('a blank domain is refused',
    function () use ($detail) { Validate::ownedDomain($detail, ''); },
    CwpException::KIND_INPUT);
throwsKind('an unreadable detail payload refuses everything',
    function () { Validate::ownedDomain([], 'example.co.za'); },
    CwpException::KIND_INPUT);

echo "\nClient area AJAX transport\n";

ok('a request carrying cwpajax is ours', ClientRequest::wanted(['cwpajax' => '1']));
ok('an ordinary render is not', !ClientRequest::wanted([]));
ok('an empty cwpajax is not', !ClientRequest::wanted(['cwpajax' => '']));

ok('a well-formed operation is returned',
    ClientRequest::operation(['cwpop' => 'mailbox.list']) === 'mailbox.list');
ok('a path cannot be smuggled through the operation',
    ClientRequest::operation(['cwpop' => '../../etc/passwd']) === '');
ok('an operation with no verb is refused',
    ClientRequest::operation(['cwpop' => 'mailbox']) === '');
ok('capitals are refused rather than folded',
    ClientRequest::operation(['cwpop' => 'Mailbox.List']) === '');
ok('an absurd operation is refused before any lookup',
    ClientRequest::operation(['cwpop' => str_repeat('a', 41)]) === '');
ok('a missing operation is refused', ClientRequest::operation([]) === '');

ok('a field is trimmed', ClientRequest::field(['a' => '  b  '], 'a') === 'b');
ok('a missing field takes the default', ClientRequest::field([], 'a', 'z') === 'z');
ok('an array field is not accepted as a string',
    ClientRequest::field(['a' => ['x']], 'a', 'z') === 'z');

ok('a list is a read', !ClientRequest::mutates('mailbox.list'));
ok('anything else mutates', ClientRequest::mutates('mailbox.delete'));

// Ten mutations a minute per service: enough for real use, not enough to drive an
// administrative key at machine speed.
$window = [];
$allowed = 0;
for ($i = 0; $i < 12; $i++) {
    if (ClientRequest::withinRate($window, 1000)) {
        $allowed++;
    }
}
ok('ten mutations are allowed in a window', $allowed === 10);
ok('the eleventh is refused', !ClientRequest::withinRate($window, 1000));
ok('the allowance returns with the next window', ClientRequest::withinRate($window, 1060));

// No WHMCS here, so generate_token() does not exist - which must read as "cannot
// verify" rather than "no verification needed".
ok('an empty token is refused', !ClientRequest::tokenValid(''));
ok('token checking fails closed without WHMCS', !ClientRequest::tokenValid('anything'));

echo "\nDashboard model (built from a real accountdetail response)\n";

// exampleh on a live server, captured 23 August 2026. Kept verbatim: the awkward cases here are
// real, not invented - a package allowing no FTP accounts that has one, a database
// allowance of zero holding two, and a domain over its disk quota.
$exampleh = [
    'domains' => [[
        'domain' => 'example-hosting.co.za',
        'path' => '/home/exampleh/public_html',
        'email' => 'bradley@connectn.co.za',
    ]],
    'subdomins' => [
        ['subdomain' => 'nhw', 'domain' => 'example-hosting.co.za', 'path' => '/home/exampleh/public_html/nhwmobile'],
        ['subdomain' => 'test', 'domain' => 'example-hosting.co.za', 'path' => '/home/exampleh/public_html/nhwupdated'],
    ],
    'databases' => [
        ['database' => 'exampleh_mobile', 'user' => 'exampleh_scf', 'host' => 'localhost'],
        ['database' => 'exampleh_nhw', 'user' => 'exampleh_scf', 'host' => 'localhost'],
        ['database' => 'exampleh_scf', 'user' => 'exampleh_scf', 'host' => 'localhost'],
    ],
    'account_info' => [
        'directory' => '/home/exampleh/',
        'package_name' => 'Medium Email Hosting',
        'space_usage' => 1257.6171875,
        'space_disk' => '1000',
        'db_max' => '0',
        'db_used' => 2,
        'state' => 'active',
        'bandwidth' => '-1',
        'bandwidth_used' => '579',
        'ftp_accounts' => '0',
        'ftp_accounts_used' => 1,
        'email_accounts' => '25',
        'email_accounts_used' => 4,
        'addons_domains' => -1,
        'addons_domains_used' => 0,
        'sub_domains' => '0',
        'sub_domains_used' => 2,
    ],
];

$model = Dashboard::from($exampleh);

ok('the package is carried through', $model['package'] === 'Medium Email Hosting');
ok('the state is carried through', $model['state'] === 'active');

$disk = $model['meters'][0];
ok('disk usage is read', (int) $disk['used'] === 1257);
ok('an over-quota account is flagged', $disk['over'] === true);
ok('the bar is capped rather than drawn past its end', $disk['percent'] === 100);
// Megabytes throughout: CWP defines packages in MB, and scaling by magnitude once
// produced "1.23 GB of 1,000 MB".
ok('both sides of a meter use the same unit', $disk['text'] === '1,258 MB of 1,000 MB');

$bandwidth = $model['meters'][1];
ok('-1 reads as unlimited', $bandwidth['unlimited'] === true);
ok('an unlimited meter draws no bar', $bandwidth['percent'] === null);
ok('an unlimited meter still states what is used',
    $bandwidth['text'] === '579 MB used of unlimited');
ok('unlimited is never also over', $bandwidth['over'] === false);

$email = $model['allowances'][0];
ok('an ordinary allowance reads as used of limit', $email['text'] === '4 of 25');
ok('its bar is proportional', $email['percent'] === 16);

// The trap: 0 is not unlimited, and an account can hold more than none anyway.
$ftp = $model['allowances'][1];
ok('a zero limit is not mistaken for unlimited', $ftp['unlimited'] === false);
ok('a zero limit reads as none included', $ftp['none'] === true);
ok('holding more than none is flagged', $ftp['over'] === true);
ok('a zero limit states the usage in words rather than a bar',
    $ftp['text'] === '1 used, none included' && $ftp['percent'] === null);

$addons = $model['allowances'][4];
ok('nothing used against unlimited still reads sensibly',
    $addons['text'] === '0 used of unlimited');

// usedText and limitText are separate so a layout can weight them differently; text
// stays one readable sentence for a tooltip. A label glued to its value was how the
// first version of this shipped - "Disk Space1,257 MB of 5,000 MB".
ok('the figure and its allowance are offered separately',
    $disk['usedText'] === '1,258 MB' && $disk['limitText'] === '1,000 MB');
ok('an unlimited allowance says so in its limit text',
    $bandwidth['usedText'] === '579 MB' && $bandwidth['limitText'] === 'unlimited');
ok('a zero limit says none included rather than 0',
    $ftp['usedText'] === '1' && $ftp['limitText'] === 'none included');
ok('a counted allowance is not given a unit',
    $email['usedText'] === '4' && $email['limitText'] === '25');

ok('domains are listed', count($model['domains']) === 1);
ok('a domain carries its document root',
    $model['domains'][0]['path'] === '/home/exampleh/public_html');
ok('subdomains are read from CWP\'s "subdomins" spelling', count($model['subdomains']) === 2);
ok('a subdomain is presented as a full name',
    $model['subdomains'][0]['name'] === 'nhw.example-hosting.co.za');
ok('databases are listed with their user', count($model['databases']) === 3
    && $model['databases'][0]['user'] === 'exampleh_scf');

// If CWP ever corrects the typo, the list must not silently empty.
$corrected = Dashboard::subdomains([
    'subdomains' => [['subdomain' => 'shop', 'domain' => 'example.com', 'path' => '/x']],
]);
ok('a corrected "subdomains" spelling is read too',
    count($corrected) === 1 && $corrected[0]['name'] === 'shop.example.com');

// An account whose detail call came back in an unexpected shape must render an empty
// dashboard, not a fatal error on a customer's page.
$empty = Dashboard::from([]);
ok('an empty payload yields a model rather than a failure',
    $empty['package'] === '' && $empty['domains'] === [] && count($empty['meters']) === 2);
ok('every meter of an empty payload is zero',
    $empty['meters'][0]['used'] === 0.0 && $empty['meters'][0]['over'] === false);

ok('dashboard.list is classified as a read, so it needs no token',
    !ClientRequest::mutates('dashboard.list'));

echo "\nMailboxes\n";

// email/list returns every mailbox's password hash in full, and CWP offers no way to ask
// it not to. Logging the response verbatim would put the hash of every customer mailbox
// password into a file any WHMCS admin can read.
$hashed = [
    'email' => 'a@b.co',
    'pass' => '{SHA512-CRYPT}$6$e36e082236190bac$oHjLVlqLQpRa9qUrd.1wNo474J4nsX9sTkw3lhbRxu0LSzuyIXtsH6SWtGW1',
];
$logged = CwpClient::redactPayload([$hashed]);
ok('a stored hash is stripped before it reaches the log',
    strpos(json_encode($logged), 'SHA512-CRYPT') === false
        && strpos(json_encode($logged), 'e36e082236190bac') === false);
ok('the address beside it survives redaction', $logged[0]['email'] === 'a@b.co');
ok('a bare crypt hash is stripped too',
    strpos(CwpClient::redactHashes('x $6$abcdefgh$ijklmnopqrstuvwx y'), 'ijklmnop') === false);
ok('ordinary money and text are left alone',
    CwpClient::redactHashes('R0.00 ZAR and $5 each') === 'R0.00 ZAR and $5 each');

// CWP calls the same thing pass on email/add and password on email/udp. Masking a named
// list of fields missed the second and put a customer's mailbox password into the Module
// Log in cleartext, so the rule is a shape now, not a list.
$secret = ['pass', 'password', 'passwd', 'newpassword', 'key', 'apikey', 'token', 'secret', 'hash'];
$plain = ['user', 'email', 'mailbox', 'domain', 'quota', 'action', 'timer'];
$maskedAll = true;
$keptAll = true;
foreach ($secret as $field) {
    if (!CwpClient::isSecretField($field)) {
        $maskedAll = false;
    }
}
foreach ($plain as $field) {
    if (CwpClient::isSecretField($field)) {
        $keptAll = false;
    }
}
ok('every password-shaped field is masked, whatever CWP calls it', $maskedAll);
ok('the fields worth reading in a log are still readable', $keptAll);
ok('the check ignores case', CwpClient::isSecretField('Password'));

// CWP names the same column differently between endpoints, so each is read from a list
// of candidates. The exact names email/list uses are not documented anywhere we have -
// tools/email-probe.php reads them off a live server.
// Captured from a live server on 23 August 2026. The units are the point: a quota arrives in
// bytes and consumption in kilobytes, in the same row.
$mailboxRows = Mailbox::rows([
    ['email' => 'Sales@Example.co.za', 'quota' => 5242880000, 'consumed' => 72487.35],
    ['email_account' => 'info@example.co.za', 'quota_mb' => 1048576],
    ['address' => 'admin@example.co.za'],
    ['something' => 'unrecognisable'],
    'not an array',
]);

ok('a mailbox is read however the address column is named', count($mailboxRows) === 3);
ok('addresses are lowercased', $mailboxRows[0]['address'] === 'admin@example.co.za');
ok('mailboxes come back sorted', $mailboxRows[1]['address'] === 'info@example.co.za'
    && $mailboxRows[2]['address'] === 'sales@example.co.za');
// 5242880000 bytes is exactly 5,000 MB, which is this account's own disk quota.
ok('a quota in bytes is shown in megabytes', $mailboxRows[2]['quota'] === 5000.0);
ok('a quota is read from any of its names', $mailboxRows[1]['quota'] === 1.0);
ok('a missing quota reads as no limit', $mailboxRows[0]['quota'] === null);
// A live mailbox came back with quota 0, which CWP means as no limit - the same as it
// does on packages. Printing "0 MB" against a working mailbox is how that first showed.
ok('a zero quota is no limit, not a zero-byte mailbox',
    Mailbox::rows([['email' => 'a@b.co', 'quota' => '0']])[0]['quota'] === null);
// 72487.35 read as megabytes would be fourteen times the mailbox's own limit.
ok('consumption in kilobytes is shown in megabytes', $mailboxRows[2]['used'] === 70.8);
ok('an unreadable row is skipped rather than fataling', Mailbox::rows(['x', 1, null]) === []);

// The account is fixed by WHMCS, but the address is not - and the key behind it reaches
// every mailbox on the server.
throwsKind('a mailbox on another account is refused',
    function () use ($mailboxRows) { Mailbox::assertOwned($mailboxRows, 'someone@elsewhere.com'); },
    CwpException::KIND_INPUT);
throwsKind('a blank address is refused',
    function () use ($mailboxRows) { Mailbox::assertOwned($mailboxRows, '   '); },
    CwpException::KIND_INPUT);
ok('an owned mailbox is accepted, case and space ignored',
    Mailbox::assertOwned($mailboxRows, '  Sales@Example.co.za ') === 'sales@example.co.za');
throwsKind('no mailboxes means nothing is owned',
    function () { Mailbox::assertOwned([], 'sales@example.co.za'); },
    CwpException::KIND_INPUT);

$box = new Mailbox(makeClient(), 'exampleh');

$add = $box->createFields('sales', 'example.co.za', 'Str0ng-Pass!');
ok('add names the hosting account', $add[Mailbox::ACCOUNT] === 'exampleh');
// CWP builds the address itself: sending sales@example.co.za with domain=example.co.za
// produced salesexample.co.za@example.co.za on a live server.
ok('add sends only the local part, never the whole address',
    $add[Mailbox::ADD['local']] === 'sales');
ok('add sends the domain separately', $add[Mailbox::ADD['domain']] === 'example.co.za');
ok('add calls the password pass', $add[Mailbox::ADD['password']] === 'Str0ng-Pass!');
// add accepts a quota and ignores it, so none is sent - Edit sets the size instead.
ok('add sends no size at all', !isset($add['quota']));
throwsKind('a weak password is refused before the call',
    function () use ($box) { $box->createFields('a', 'b.co', 'short'); },
    CwpException::KIND_INPUT);
throwsKind('a non-numeric size is refused',
    function () { Mailbox::quota('10GB'); }, CwpException::KIND_INPUT);
throwsKind('a zero size is refused - blank is how you ask to leave it alone',
    function () { Mailbox::quota('0'); }, CwpException::KIND_INPUT);
// Typed in megabytes because that is what CWP shows elsewhere; sent in bytes because
// that is what this endpoint reports.
ok('a size is sent in bytes', Mailbox::quota('2048') === '2147483648');

// Splitting a stored address back into the two parts every write needs.
ok('an address splits into local part and domain',
    Mailbox::split('sales@example.co.za') === ['local' => 'sales', 'domain' => 'example.co.za']);
ok('only the last @ separates them',
    Mailbox::split('odd@name@example.co.za')['local'] === 'odd@name');
throwsKind('an address with no domain is refused',
    function () { Mailbox::split('sales'); }, CwpException::KIND_INPUT);
throwsKind('an address with no local part is refused',
    function () { Mailbox::split('@example.co.za'); }, CwpException::KIND_INPUT);

$edit = $box->updateFields('sales@example.co.za', 'N3w-Pass!word', '4096');
// udp and del name a mailbox differently from add: the whole address, in a field
// called mailbox, and the password called password rather than pass. Sending add's
// names instead made CWP read a field that was not there and die with
// "Undefined offset: 1" behind an HTTP 500, four times over.
ok('update names the hosting account', $edit[Mailbox::ACCOUNT] === 'exampleh');
ok('update names the mailbox by its whole address, in mailbox',
    $edit[Mailbox::MODIFY['mailbox']] === 'sales@example.co.za');
ok('update calls the password password, not pass',
    $edit[Mailbox::MODIFY['password']] === 'N3w-Pass!word');
ok('update sends a size in bytes', $edit[Mailbox::MODIFY['quota']] === '4294967296');
ok('update sends no domain - the address carries it',
    !isset($edit[Mailbox::ADD['domain']]));

ok('delete names a mailbox the same way update does',
    $box->identityFields('sales@example.co.za') === [
        Mailbox::ACCOUNT => 'exampleh',
        Mailbox::MODIFY['mailbox'] => 'sales@example.co.za',
    ]);

$sizeOnly = $box->updateFields('sales@example.co.za', '', '4096');
ok('a blank password leaves the password alone',
    !isset($sizeOnly[Mailbox::MODIFY['password']])
        && $sizeOnly[Mailbox::MODIFY['quota']] === '4294967296');

$passwordOnly = $box->updateFields('sales@example.co.za', 'N3w-Pass!word', '');
ok('a blank size leaves the size alone',
    !isset($passwordOnly[Mailbox::MODIFY['quota']])
        && $passwordOnly[Mailbox::MODIFY['password']] === 'N3w-Pass!word');

throwsKind('asking for neither change is refused rather than sent empty',
    function () use ($box) { $box->updateFields('sales@example.co.za', '', ''); },
    CwpException::KIND_INPUT);

// The two actions genuinely disagree, so each gets its own map rather than one that
// has to be right for both.
ok('add and modify name the password differently',
    Mailbox::ADD['password'] === 'pass' && Mailbox::MODIFY['password'] === 'password');
ok('mailbox.list is a read; the rest are not',
    !ClientRequest::mutates('mailbox.list')
    && ClientRequest::mutates('mailbox.create')
    && ClientRequest::mutates('mailbox.delete')
    && ClientRequest::mutates('mailbox.update'));

echo "\nUsername normalisation (creation only)\n";

$usernameCases = [
    ['mydomain.co.za', 8, 'mydomain'],
    ['MyDomain', 8, 'mydomain'],
    ['my-domain_x', 8, 'mydomain'],
    ['verylongusername', 8, 'verylong'],
    ['verylongusername', 0, 'verylongusername'],
    ['123abc', 8, 'abc'],
    ['1234', 8, ''],
    ['  bob  ', 8, 'bob'],
    ['!!!', 8, ''],
];

foreach ($usernameCases as $case) {
    $actual = Account::normaliseUsername($case[0], $case[1]);
    ok(
        sprintf('%-20s max %-2d -> %s', var_export($case[0], true), $case[1], var_export($case[2], true)),
        $actual === $case[2]
    );
}

echo "\nUsage coercion\n";

$numericCases = [
    ['1024', 1024.0],
    ['1024 MB', 1024.0],
    ['1.5', 1.5],
    ['', 0.0],
    ['unlimited', 0.0],
    [2048, 2048.0],
    [null, 0.0],
    // CWP uses -1 for unlimited; WHMCS reads 0 that way.
    [-1, 0.0],
    ['-1', 0.0],
    [480715.59765625, 480715.59765625],
];

foreach ($numericCases as $case) {
    ok(
        sprintf('numeric(%-12s) -> %s', var_export($case[0], true), var_export($case[1], true)),
        Usage::numeric($case[0]) === $case[1]
    );
}

ok('pick takes the first present key', Usage::pick(['b' => 2, 'a' => 1], ['a', 'b']) === 1);
ok('pick skips empty values', Usage::pick(['a' => '', 'b' => 2], ['a', 'b']) === 2);
ok('pick returns null when nothing matches', Usage::pick(['z' => 1], ['a', 'b']) === null);

echo "\nClient-safe messaging\n";

$apiError = CwpException::api('user bob owns /home/bob and 4 other accounts');
ok(
    'raw CWP detail stays out of the client message',
    strpos($apiError->getClientMessage(), 'bob') === false
);
ok(
    'raw CWP detail is kept for the module log',
    strpos($apiError->getMessage(), 'bob') !== false
);

$specific = $apiError->withClientMessage('That database name is already in use.');
ok('withClientMessage sets the safe message', $specific->getClientMessage() === 'That database name is already in use.');
ok('withClientMessage does not mutate the original', $apiError->getClientMessage() !== $specific->getClientMessage());
ok('withClientMessage preserves the technical message', strpos($specific->getMessage(), 'bob') !== false);

ok('transport errors are retryable', CwpException::transport('timeout')->isRetryable());
ok('api errors are not retryable', !CwpException::api('nope')->isRetryable());
ok('config errors are not retryable', !CwpException::config('bad')->isRetryable());


// ---------------------------------------------------------------------------------------
// A reply that is valid JSON followed by something else.
//
// CWP answers account/del with its panel's HTML confirmation appended to the JSON. The
// module used to reject the whole body, so a termination that had already happened was
// reported to WHMCS as a failure - the service stayed Suspended while the account was
// gone from the server. The first fixture is the exact reply from the 30 Aug module log.
// ---------------------------------------------------------------------------------------

$realDelReply = <<<'CWPREPLY'
{"status":"OK"}<pre><i aria-hidden='true' class='icomoon-icon-checkmark'></i> User northwin deleted from server.<br><i aria-hidden='true' class='icomoon-icon-checkmark'></i> User and domains deleted from server.</pre>
CWPREPLY;

$fromReal = invokePrivate(CwpClient::class, 'leadingJsonObject', [$realDelReply]);
ok('account/del reply with trailing HTML decodes', is_array($fromReal));
ok(
    '...and carries status OK, so the terminate reports success',
    is_array($fromReal) && isset($fromReal['status']) && $fromReal['status'] === 'OK'
);

ok(
    'a clean reply is unaffected',
    invokePrivate(CwpClient::class, 'leadingJsonObject', ['{"status":"OK"}']) === ['status' => 'OK']
);

// Why this is a brace scan and not a search for the first "}<".
$braceInValue = <<<'CWPREPLY'
{"status":"OK","msj":"deleted {user} and }<b> too"}<pre>trailing</pre>
CWPREPLY;

$fromBrace = invokePrivate(CwpClient::class, 'leadingJsonObject', [$braceInValue]);
ok(
    'a brace inside a string value does not end the object early',
    is_array($fromBrace) && $fromBrace['msj'] === 'deleted {user} and }<b> too'
);

$nested = <<<'CWPREPLY'
{"status":"OK","result":{"account_info":{"state":"active"}}}<pre>x</pre>
CWPREPLY;

$fromNested = invokePrivate(CwpClient::class, 'leadingJsonObject', [$nested]);
ok(
    'nested objects are walked to the outer closing brace',
    is_array($fromNested) && $fromNested['result']['account_info']['state'] === 'active'
);

$escapedQuote = <<<'CWPREPLY'
{"status":"OK","msj":"quote \" then }brace"}<pre>x</pre>
CWPREPLY;

$fromEscaped = invokePrivate(CwpClient::class, 'leadingJsonObject', [$escapedQuote]);
ok(
    'an escaped quote does not reopen the string',
    is_array($fromEscaped) && $fromEscaped['msj'] === 'quote " then }brace'
);

ok(
    'a truncated object stays a failure',
    invokePrivate(CwpClient::class, 'leadingJsonObject', ['{"status":"OK"']) === null
);
ok(
    'a body that is only markup stays a failure',
    invokePrivate(CwpClient::class, 'leadingJsonObject', ['<html>nope</html>']) === null
);
ok(
    'junk stays a failure',
    invokePrivate(CwpClient::class, 'leadingJsonObject', ['not json at all']) === null
);
ok(
    'leading whitespace is tolerated',
    invokePrivate(CwpClient::class, 'leadingJsonObject', ['   {"status":"OK"}x']) === ['status' => 'OK']
);


// ---------------------------------------------------------------------------------------
// Reconciling a failed deletion against account/list.
//
// terminate() accepts a CWP error when the account is no longer on the server, which is
// what makes WHMCS mark the service Terminated. That makes this the check standing between
// "the account is gone" and "we told WHMCS a live account was terminated", so it is tested
// against the real rows: the 29 Aug list held northwin, the 30 Aug list did not.
// ---------------------------------------------------------------------------------------

$listBefore = [
    ['username' => 'connect',  'domain' => 'connectn.co.za',            'status' => 'active'],
    ['username' => 'exampleh', 'domain' => 'example-hosting.co.za', 'status' => 'active'],
    ['username' => 'bluecir',  'domain' => 'bluecircle.co.za',       'status' => 'active'],
    ['username' => 'greenva',  'domain' => 'greenvalley.co.za',        'status' => 'active'],
    ['username' => 'northwin', 'domain' => 'northwind.co.za',       'status' => 'suspended'],
    ['username' => 'silverl',  'domain' => 'silverlake.co.za', 'status' => 'active'],
    ['username' => 'booysen',  'domain' => 'booysenlogistics.co.za',    'status' => 'active'],
    ['username' => 'connectn', 'domain' => 'connectn22.co.za',          'status' => 'active'],
];

// The same list after the delete CWP reported as a failure.
$listAfter = array_values(array_filter($listBefore, static function (array $row): bool {
    return $row['username'] !== 'northwin';
}));

ok(
    'a still-listed account is found, so a real failure stays a failure',
    invokePrivate(Account::class, 'listsAccount', [$listBefore, 'northwin']) === true
);
ok(
    'an account CWP has removed is not found, so the terminate is accepted',
    invokePrivate(Account::class, 'listsAccount', [$listAfter, 'northwin']) === false
);
ok(
    'the other seven accounts are untouched by that decision',
    invokePrivate(Account::class, 'listsAccount', [$listAfter, 'booysen']) === true
);

ok(
    'matching ignores case, since CWP echoes the name it stored',
    invokePrivate(Account::class, 'listsAccount', [$listBefore, 'NorthWin']) === true
);
ok(
    'matching is exact, not a prefix - northwi must not match northwin',
    invokePrivate(Account::class, 'listsAccount', [$listBefore, 'northwi']) === false
);
ok(
    'an empty username never counts as present',
    invokePrivate(Account::class, 'listsAccount', [$listBefore, '']) === false
);
ok(
    'an empty list reads as gone',
    invokePrivate(Account::class, 'listsAccount', [[], 'northwin']) === false
);
ok(
    'a row with no username is skipped, not matched',
    invokePrivate(Account::class, 'listsAccount', [[['domain' => 'x.co.za']], 'northwin']) === false
);
ok(
    'a malformed row cannot hide an account that is still there',
    invokePrivate(Account::class, 'listsAccount', [
        [['domain' => 'x.co.za'], ['username' => 'northwin']],
        'northwin',
    ]) === true
);


// The same reply driven through interpret(), which is the path call() actually takes.
// leadingJsonObject() being right is not the same as interpret() using it, and it was
// interpret() that reported the termination as a failure.
$interpreted = null;
$interpretThrew = false;

try {
    $interpreted = invokePrivate(makeClient(), 'interpret', [
        $realDelReply,
        200,
        0,
        '',
        ['function' => 'account', 'action' => 'del'],
    ]);
} catch (CwpException $e) {
    $interpretThrew = true;
}

ok('interpret() no longer throws on the account/del reply', !$interpretThrew);
ok(
    'interpret() returns the status, so terminate() returns success to WHMCS',
    is_array($interpreted) && isset($interpreted['status']) && $interpreted['status'] === 'OK'
);

// And a body with no JSON at all must still be rejected by that same path.
$junkThrew = false;

try {
    invokePrivate(makeClient(), 'interpret', [
        'not json at all',
        200,
        0,
        '',
        ['function' => 'account', 'action' => 'del'],
    ]);
} catch (CwpException $e) {
    $junkThrew = true;
}

ok('interpret() still rejects a reply carrying no JSON', $junkThrew);

echo "\n" . str_repeat('-', 52) . "\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
