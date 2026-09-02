<?php
/**
 * CWP usage-unit probe.
 *
 * Prints the raw disk and bandwidth values CWP returns from account/list, so they can be
 * compared against the CWP panel. WHMCS stores these figures in megabytes; if CWP reports
 * something else, the conversion belongs in Usage::numeric().
 *
 * The API key is read from the CWP_API_KEY environment variable, not an argument, to
 * keep it out of shell history and the process list.
 *
 *   Linux/macOS:  CWP_API_KEY='...' php tools/usage-probe.php cwp.example.com
 *   PowerShell:   $env:CWP_API_KEY='...'; php tools\usage-probe.php cwp.example.com
 *
 * Requires only LIST on Account — the same grant the module already needs.
 *
 * @package cwp7
 * @version 2.5.0
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This file runs from the command line only.\n");
}

require_once __DIR__ . '/../lib/CwpException.php';
require_once __DIR__ . '/../lib/CwpClient.php';

use Cwp7\CwpClient;
use Cwp7\CwpException;
use Cwp7\Actions\Usage;

require_once __DIR__ . '/../lib/Actions/Usage.php';

$host = isset($argv[1]) ? trim((string) $argv[1]) : '';
$port = isset($argv[2]) && $argv[2] !== '' ? (int) $argv[2] : 2304;

/**
 * getenv() alone is not enough: some CLI builds are configured with a variables_order
 * that leaves the environment out, so check every place PHP may have put it.
 */
function readKey(): string
{
    $key = (string) getenv('CWP_API_KEY');

    if ($key === '' && isset($_SERVER['CWP_API_KEY'])) {
        $key = (string) $_SERVER['CWP_API_KEY'];
    }

    if ($key === '' && isset($_ENV['CWP_API_KEY'])) {
        $key = (string) $_ENV['CWP_API_KEY'];
    }

    return trim($key);
}

$key = readKey();

// Last resort: prompt for it. Keeps the key out of the argument list either way.
if ($key === '' && $host !== '' && function_exists('readline')) {
    echo "CWP API key (input is visible): ";
    $key = trim((string) readline());
}

if ($host === '' || $key === '') {
    echo "\nUsage:  CWP_API_KEY='<key>' php usage-probe.php <cwp-hostname> [port]\n\n";

    if ($host === '') {
        echo "  No hostname given.\n";
    }

    if ($key === '') {
        echo "  No API key found in CWP_API_KEY.\n\n";
        echo "  If you set it with `read`, confirm it actually captured something:\n";
        echo "      echo \"length: \${#CWP_API_KEY}\"\n\n";
        echo "  `read -rs` prints no prompt, so it is easy to press Enter before pasting.\n";
        echo "  Use a prompt and export in one step:\n\n";
        echo "      read -rsp 'CWP API key: ' CWP_API_KEY && echo && export CWP_API_KEY\n";
    }

    echo "\n";
    exit(1);
}

/**
 * Sanity-check the key's shape before spending a request on it.
 *
 * CWP answers a malformed key with "No special characters are allowed!", which says
 * nothing about what was wrong. A pasted key picks up whitespace, line breaks or a
 * second copy of itself often enough to be worth catching here.
 *
 * Reports shape only — never the key.
 *
 * @return array<int,string> Problems found, worst first.
 */
function inspectKey(string $key): array
{
    $problems = [];
    $length = strlen($key);

    if (preg_match('/\s/', $key)) {
        $problems[] = 'contains whitespace or a line break — the paste picked up more than the key';
    }

    $stripped = preg_replace('/[A-Za-z0-9]/', '', $key);
    if (is_string($stripped) && $stripped !== '') {
        // Show the distinct offenders escaped. CWP keys are alphanumeric, so anything
        // listed here came from the paste rather than the key, and naming it usually
        // identifies the cause outright.
        $distinct = count_chars($stripped, 3);
        $shown = '';
        for ($i = 0, $len = strlen($distinct); $i < $len; $i++) {
            $c = $distinct[$i];
            $shown .= (ord($c) < 32 || ord($c) > 126)
                ? sprintf('\x%02X', ord($c))
                : $c;
        }

        $problems[] = sprintf(
            'contains %d character(s) outside A-Z a-z 0-9 — CWP keys are alphanumeric. Found: %s',
            strlen($stripped),
            $shown
        );

        if (strpos($distinct, "\x1b") !== false) {
            $problems[] = 'includes escape characters (\x1B) — the terminal sent bracketed-paste '
                . 'markers into the variable. Disable it first: printf \'\\e[?2004l\'';
        }

        if (strpos($distinct, ':') !== false && strpos($distinct, '/') !== false) {
            $problems[] = 'looks like a URL rather than a bare key';
        }

        if (strpos($distinct, '{') !== false || strpos($distinct, '"') !== false) {
            $problems[] = 'looks like JSON rather than a bare key';
        }
    }

    if ($length > 0 && $length % 2 === 0) {
        $half = intdiv($length, 2);
        if (substr($key, 0, $half) === substr($key, $half)) {
            $problems[] = 'the first half is identical to the second — it was pasted twice';
        }
    }

    return $problems;
}

/** Rough unit guess from magnitude. The panel comparison is what actually decides. */
function guessUnit(array $values): string
{
    $values = array_filter($values, function ($v) {
        return $v > 0;
    });

    if ($values === []) {
        return 'no non-zero values to judge by';
    }

    $max = max($values);

    if ($max > 100000000) {
        return 'looks like BYTES (values are enormous)';
    }

    if ($max > 20000) {
        return 'looks like MB or KB';
    }

    if ($max > 100) {
        return 'looks like MB — matches what WHMCS expects';
    }

    return 'looks like GB (values are small)';
}

$keyProblems = inspectKey($key);

if ($keyProblems !== []) {
    echo "\nThe API key does not look right (" . strlen($key) . " characters):\n\n";
    foreach ($keyProblems as $problem) {
        echo "  - " . $problem . "\n";
    }
    echo "\nRe-copy it from CWP -> Settings -> API Manager and try again.\n\n";
    exit(1);
}

try {
    $client = new CwpClient([
        'host' => $host,
        'key' => $key,
        'api_port' => $port,
    ]);

    $rows = CwpClient::rows($client->call('account', 'list'));
} catch (CwpException $e) {
    echo "\nFailed: " . $e->getMessage() . "\n";

    if (strpos($e->getMessage(), 'special characters') !== false) {
        echo "\nThat message comes from CWP's own validation of the key. This one is "
            . strlen($key) . " characters\nand alphanumeric, so compare it against the key "
            . "shown in API Manager.\n";
    }

    echo "\n";
    exit(1);
}

if ($rows === []) {
    echo "\nCWP returned no accounts.\n\n";
    exit(1);
}

echo "\nRaw values from CWP account/list on {$host}\n";
echo str_repeat('=', 96) . "\n\n";
printf("  %-26s %-14s %-14s %-14s %-14s\n", 'DOMAIN', 'diskusage', 'disklimit', 'bandwidth', 'bwlimit');
echo '  ' . str_repeat('-', 88) . "\n";

$collect = ['diskusage' => [], 'disklimit' => [], 'bwusage' => [], 'bwlimit' => []];

foreach ($rows as $row) {
    $domain = (string) Usage::pick($row, ['domain', 'main_domain', 'primary_domain']);
    $cells = [];

    foreach (Usage::FIELD_CANDIDATES as $target => $keys) {
        $raw = Usage::pick($row, $keys);
        $cells[] = $raw === null ? '-' : (string) $raw;
        $collect[$target][] = Usage::numeric($raw);
    }

    printf(
        "  %-26s %-14s %-14s %-14s %-14s\n",
        substr($domain !== '' ? $domain : '(no domain)', 0, 26),
        $cells[0],
        $cells[1],
        $cells[2],
        $cells[3]
    );
}

echo "\n" . str_repeat('=', 96) . "\n";
echo "Magnitude check (indicative only)\n\n";

foreach ($collect as $field => $values) {
    printf("  %-12s %s\n", $field, guessUnit($values));
}

echo "\nNow open the CWP panel and compare one account's disk usage against the\n";
echo "diskusage column above.\n\n";
echo "  Panel says 1.2 GB, column says ~1200   -> megabytes. Nothing to change.\n";
echo "  Panel says 1.2 GB, column says ~1.2    -> gigabytes. Multiply by 1024.\n";
echo "  Panel says 1.2 GB, column says 1.2e9   -> bytes. Divide by 1048576.\n\n";
echo "Any conversion belongs in Usage::numeric() in lib/Actions/Usage.php, which is\n";
echo "the single place these figures pass through.\n\n";
