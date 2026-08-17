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
 * @version 2.0.0
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
$key = (string) getenv('CWP_API_KEY');

if ($host === '' || $key === '') {
    echo "\nUsage:  CWP_API_KEY='<key>' php usage-probe.php <cwp-hostname> [port]\n\n";
    if ($key === '') {
        echo "  CWP_API_KEY is not set.\n\n";
    }
    exit(1);
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

try {
    $client = new CwpClient([
        'host' => $host,
        'key' => $key,
        'api_port' => $port,
    ]);

    $rows = CwpClient::rows($client->call('account', 'list'));
} catch (CwpException $e) {
    echo "\nFailed: " . $e->getMessage() . "\n\n";
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
