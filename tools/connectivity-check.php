<?php
/**
 * CWP connectivity checker — run this from the WHMCS server.
 *
 * Answers what Test Connection cannot: what this machine resolves the CWP hostname to,
 * whether it can open a socket, and whether TLS verifies. Needs no WHMCS, no database
 * and no API key.
 *
 *   CLI:  php tools/connectivity-check.php cwp.example.com [port]
 *   Web:  set $token below, then open
 *         https://your-whmcs/modules/servers/cwp7/tools/connectivity-check.php?token=YOURTOKEN&host=cwp.example.com
 *
 * Delete this file when finished. Its output reveals internal addressing.
 *
 * @package cwp7
 * @version 2.5.0
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

// Set to any random string before using this over the web. Web requests are refused
// while it is empty, so an unmodified copy left on a server does nothing.
$token = '';

$isCli = (PHP_SAPI === 'cli');
$usage = "Usage:\n  CLI: php connectivity-check.php <cwp-hostname> [port]\n"
    . "  Web: ?token=<token>&host=<cwp-hostname>[&port=2304]\n";

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    if ($token === '' || !isset($_GET['token']) || !hash_equals($token, (string) $_GET['token'])) {
        http_response_code(403);
        echo "Refused.\n\nEdit \$token near the top of this file, then call it with ?token=...\n";
        exit(1);
    }
}

$host = '';
$port = 2304;

if ($isCli) {
    if (isset($argv[1])) { $host = trim((string) $argv[1]); }
    if (isset($argv[2]) && $argv[2] !== '') { $port = (int) $argv[2]; }
} else {
    if (isset($_GET['host'])) { $host = (string) preg_replace('/[^A-Za-z0-9.\-]/', '', (string) $_GET['host']); }
    if (isset($_GET['port']) && $_GET['port'] !== '') { $port = (int) $_GET['port']; }
}

// No default target: this tool opens connections, so it must never guess where.
if ($host === '') {
    if (!$isCli) {
        http_response_code(400);
    }
    echo "\nNo CWP hostname given.\n\n" . $usage . "\n";
    exit(1);
}

if ($port < 1 || $port > 65535) {
    echo "\nInvalid port: {$port}\n\n" . $usage . "\n";
    exit(1);
}

function line($label, $value = null)
{
    if ($value === null) {
        echo $label . "\n";
        return;
    }
    printf("  %-22s %s\n", $label, $value);
}

function isPrivateIp($ip)
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

echo "\nCWP connectivity check\n";
echo str_repeat('=', 62) . "\n\n";

line('Target', $host . ':' . $port);
line('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')');
line('cURL', function_exists('curl_version') ? curl_version()['version'] : 'MISSING — required');
echo "\n";

// ---------------------------------------------------------------------------
echo "1. DNS — what does THIS machine resolve?\n";

$resolved = gethostbyname($host);

if ($resolved === $host) {
    line('Result', 'FAILED to resolve');
    line('', 'This server cannot resolve the hostname at all. Check /etc/resolv.conf.');
    $resolved = '';
} else {
    line('Resolves to', $resolved);

    if (isPrivateIp($resolved)) {
        line('Address type', 'PRIVATE (RFC1918)');
        line('', 'Fine only if this WHMCS server is on the same network as CWP.');
    } else {
        line('Address type', 'public');
        line('', 'If CWP actually lives on a private LAN, this is the WAN/NAT address.');
        line('', 'A WHMCS box INSIDE that LAN dialling it needs NAT reflection,');
        line('', 'which is off by default on pfSense — that is an instant refusal.');
    }
}
echo "\n";

// ---------------------------------------------------------------------------
echo "2. TCP — can a socket be opened?\n";

$errno = 0;
$errstr = '';
$started = microtime(true);
$sock = @fsockopen('tcp://' . $host, $port, $errno, $errstr, 8);
$elapsed = round((microtime(true) - $started) * 1000);

if ($sock) {
    fclose($sock);
    line('Result', 'CONNECTED in ' . $elapsed . ' ms');
} else {
    line('Result', 'FAILED in ' . $elapsed . ' ms');
    line('Error', trim($errstr) . ' (errno ' . $errno . ')');

    if (stripos($errstr, 'refused') !== false) {
        line('', 'REFUSED means something sent a TCP reset — it is not a silent block.');
        line('', 'Typical causes, in order:');
        line('', '  a) hairpin NAT: this box is inside the LAN, dialling the WAN IP');
        line('', '  b) outbound ' . $port . ' rejected here (CSF TCP_OUT; DROP_OUT defaults to REJECT)');
        line('', '  c) nothing listening on the far side');
    } elseif ($elapsed >= 7000) {
        line('', 'A timeout means packets are being DROPPED, not refused —');
        line('', 'usually an inbound firewall on the far side or in front of it.');
    }

    // Control test. Reaching the same host on a port that is normally open separates
    // "this host is unreachable" from "this PORT is filtered" — which is the difference
    // between a routing problem and a firewall port list.
    echo "\n";
    line('Control test', 'same host, port 443');

    $cErrno = 0;
    $cErrstr = '';
    $ctl = @fsockopen('tcp://' . $host, 443, $cErrno, $cErrstr, 8);

    if ($ctl) {
        fclose($ctl);
        line('  443', 'CONNECTED');
        line('', 'The host is reachable and routing is fine. Port ' . $port . ' specifically');
        line('', 'is being filtered. Check the port lists at BOTH ends:');
        line('', '  - this server, outbound:  TCP_OUT in /etc/csf/csf.conf');
        line('', '  - the CWP server, inbound: TCP_IN in /etc/csf/csf.conf');
        line('', 'CSF ships neither list containing ' . $port . ', and its DROP_OUT');
        line('', 'default is REJECT — which is exactly this instant refusal.');
    } else {
        line('  443', 'FAILED — ' . trim($cErrstr));
        line('', 'The host is not reachable on a normally-open port either, so this');
        line('', 'is routing or a blanket block rather than a per-port rule.');
    }
}
echo "\n";

// ---------------------------------------------------------------------------
echo "3. HTTPS — does the API answer?\n";

if (!function_exists('curl_init')) {
    line('Result', 'SKIPPED — cURL missing');
} else {
    $url = 'https://' . $host . ':' . $port . '/v1/typeserver';

    // Verification OFF first: this separates "cannot reach it" from "cert not trusted".
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    $cErrno = curl_errno($ch);
    $cError = curl_error($ch);
    curl_close($ch);

    line('Dialled', $ip !== '' ? $ip : '(never connected)');

    if ($cErrno !== 0) {
        line('Result', 'FAILED — ' . $cError . ' (errno ' . $cErrno . ')');
    } else {
        line('HTTP status', (string) $code);

        if ($code === 404) {
            line('Verdict', 'HEALTHY. The API is POST-only, so 404 to a GET proves the');
            line('', 'full path works: forward, TCP, TLS and cwpsrv.');
        } elseif ($code === 200) {
            line('Verdict', 'Reachable.');
        } else {
            line('Verdict', 'Reachable, but ' . $code . ' is unexpected here.');
        }
    }

    // Now with verification ON — this is what the module actually does.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
    $vErrno = curl_errno($ch);
    $vError = curl_error($ch);
    curl_close($ch);

    echo "\n";
    line('TLS verification', $vErrno === 0 ? 'PASSES — leave verify_tls => true, no ca_bundle needed'
        : 'FAILS — ' . $vError);

    if (in_array($vErrno, [51, 60, 77, 83], true)) {
        // Two very different faults produce the identical error, and guessing wrong
        // sends you to pin a certificate that was never the problem. Read the issuer
        // before doing anything.
        line('', 'This means one of two things. Find out which before acting:');
        line('', '');
        line('', "  echo | openssl s_client -connect {$host}:{$port} \\");
        line('', "    -servername {$host} 2>/dev/null | openssl x509 -noout -subject -issuer");
        line('', '');
        line('', 'If the issuer is a real CA (Let\'s Encrypt, etc.), the certificate is');
        line('', 'fine and THIS machine cannot verify it — its CA store is missing or');
        line('', 'unconfigured. Install ca-certificates, or set curl.cainfo/openssl.cafile');
        line('', 'in php.ini. Do NOT pin the certificate; you would be papering over it.');
        line('', '');
        line('', 'If it is self-signed, export and pin it:');
        line('', "  openssl s_client -connect {$host}:{$port} -showcerts </dev/null \\");
        line('', '    2>/dev/null | openssl x509 -outform PEM > /etc/ssl/certs/cwp-pinned.pem');
        line('', 'then set ca_bundle to that path in config.php.');
    }
}

echo "\n" . str_repeat('=', 62) . "\n";
echo "Delete this file when you are finished.\n\n";
