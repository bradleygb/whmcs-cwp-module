<?php
/**
 * CWP email endpoint probe.
 *
 * Prints the raw response from email/list for one hosting account, and shows which
 * fields Mailbox::rows() recognises in it. Use it to confirm the endpoint's shape before
 * turning `mailbox_management` on.
 *
 * READ ONLY. It calls nothing but list, so it cannot create, alter or delete a mailbox.
 *
 * The API key is read from the CWP_API_KEY environment variable, not an argument, to
 * keep it out of shell history and the process list.
 *
 *   Linux/macOS:  CWP_API_KEY='...' php tools/email-probe.php cwp.example.com exampleh
 *   PowerShell:   $env:CWP_API_KEY='...'; php tools\email-probe.php cwp.example.com exampleh
 *
 * Requires LIST on Emails.
 *
 * @package cwp7
 * @version 2.4.0
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
require_once __DIR__ . '/../lib/Validate.php';
require_once __DIR__ . '/../lib/Actions/Usage.php';
require_once __DIR__ . '/../lib/Actions/Mailbox.php';

use Cwp7\Actions\Mailbox;
use Cwp7\CwpClient;
use Cwp7\CwpException;

$host = isset($argv[1]) ? trim((string) $argv[1]) : '';
$account = isset($argv[2]) ? trim((string) $argv[2]) : '';
$port = isset($argv[3]) && $argv[3] !== '' ? (int) $argv[3] : 2304;

if ($host === '' || $account === '') {
    exit("Usage: CWP_API_KEY='...' php tools/email-probe.php <cwp-host> <account> [port]\n");
}

$key = trim((string) getenv('CWP_API_KEY'));

if ($key === '' && isset($_SERVER['CWP_API_KEY'])) {
    $key = trim((string) $_SERVER['CWP_API_KEY']);
}

if ($key === '') {
    exit("Set CWP_API_KEY in the environment first.\n");
}

$client = new CwpClient([
    'host' => $host,
    'api_port' => $port,
    'key' => $key,
    'verify_tls' => true,
]);

echo "email/list for {$account} on {$host}:{$port}\n\n";

try {
    $response = $client->call(Mailbox::FUNCTION, 'list', [
        Mailbox::ACCOUNT => $account,
    ]);
} catch (CwpException $e) {
    echo "  FAILED: ", $e->getMessage(), "\n\n";

    if (stripos($e->getMessage(), 'unauthorized') !== false) {
        echo "  The key is missing LIST on Emails.\n";
    } else {
        echo "  If the endpoint itself is wrong, correct Mailbox::FUNCTION.\n";
        echo "  If the account field is wrong, correct Mailbox::ACCOUNT.\n";
    }

    exit(1);
}

echo "  raw response\n  ", str_replace("\n", "\n  ", print_r($response, true)), "\n";

$rows = CwpClient::rows($response);

echo "  ", count($rows), " row(s) found under the payload key.\n\n";

if ($rows !== []) {
    $first = is_array($rows[0]) ? $rows[0] : [];
    echo "  keys on the first row: ", ($first === [] ? '(none)' : implode(', ', array_keys($first))), "\n\n";
}

$mailboxes = Mailbox::rows($rows);

echo "  Mailbox::rows() made ", count($mailboxes), " mailbox(es) of that:\n";

foreach ($mailboxes as $mailbox) {
    printf(
        "    %-40s quota=%s used=%s\n",
        $mailbox['address'],
        $mailbox['quota'] === null ? '?' : $mailbox['quota'],
        $mailbox['used'] === null ? '?' : $mailbox['used']
    );
}

if ($rows !== [] && $mailboxes === []) {
    echo "\n  Rows came back but none was recognised as a mailbox. Add the address key\n";
    echo "  from the list above to the candidates in Mailbox::rows().\n";
}

echo "\nDone. Nothing was changed.\n";
