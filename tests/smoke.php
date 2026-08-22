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
require_once __DIR__ . '/../lib/Actions/Account.php';
require_once __DIR__ . '/../lib/Actions/Package.php';
require_once __DIR__ . '/../lib/Actions/Session.php';
require_once __DIR__ . '/../lib/Actions/Usage.php';

use Cwp7\Actions\Account;
use Cwp7\Actions\Package;
use Cwp7\Actions\Session;
use Cwp7\Actions\Usage;
use Cwp7\CwpClient;
use Cwp7\CwpException;

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

$add = $account->createFields('demo', 'example.com');
ok('add: limit_nofile carries the open-file limit', $add['limit_nofile'] === '150');
ok('add: limit_nproc carries the process limit', $add['limit_nproc'] === '40');
ok('add: package is sent bare, no @', $add['package'] === '10');
ok('add: email included', $add['email'] === 'owner@example.com');
ok('add: no nofile/nproc (neither endpoint accepts them)', !isset($add['nofile']) && !isset($add['nproc']));
ok('add: no openfiles/processes (those are udp names)', !isset($add['openfiles']) && !isset($add['processes']));

// Three endpoints, three package formats. changepack takes the bare ID.
$pack = $account->changePackFields('demo');
ok('changepack: package is the bare ID, no @', $pack['package'] === '10');
ok('changepack: sends only user and package', array_keys($pack) === ['user', 'package']);

$udp = $account->packageFields('demo');
ok('udp: openfiles carries the open-file limit', $udp['openfiles'] === '150');
ok('udp: processes carries the process limit', $udp['processes'] === '40');
ok('udp: package is @-prefixed, not suffixed', $udp['package'] === '@10');
ok('udp: email included (CWP requires it)', $udp['email'] === 'owner@example.com');
ok('udp: no limit_nofile/limit_nproc (those are add names)',
    !isset($udp['limit_nofile']) && !isset($udp['limit_nproc']));

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

echo "\nPackage existence check\n";

// changepack answers OK for an ID that does not exist and leaves the account with no
// package, so the requested ID is checked against the server's list first.
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

$noEmail = new Account(makeClient(), array_merge($serviceParams, ['clientsdetails' => []]));
throwsKind(
    'udp without a client email is refused before the call',
    function () use ($noEmail) { $noEmail->packageFields('demo'); },
    CwpException::KIND_CONFIG
);

$noPackage = new Account(makeClient(), array_merge($serviceParams, ['configoption1' => '']));
throwsKind(
    'add without a package is refused before the call',
    function () use ($noPackage) { $noPackage->createFields('demo', 'example.com'); },
    CwpException::KIND_CONFIG
);

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

echo "\n" . str_repeat('-', 52) . "\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
