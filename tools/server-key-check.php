<?php
/**
 * Does WHMCS give a hook the decrypted server API key?
 *
 * tblservers.accesshash is stored encrypted. Module functions receive it decrypted in
 * $params, but a hook reading the table gets ciphertext and has to decrypt it itself.
 * This reports whether that decryption is working, by shape only — no key is printed.
 *
 *   php tools/server-key-check.php /path/to/whmcs <serverid>
 *
 * Delete when finished.
 *
 * @package cwp7
 * @version 2.5.1
 * @license MIT
 * @link    https://github.com/bradleygb/whmcs-cwp-module
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This file runs from the command line only.\n");
}

$whmcs = isset($argv[1]) ? rtrim((string) $argv[1], '/') : '';
$serverId = isset($argv[2]) ? (int) $argv[2] : 0;

if ($whmcs === '' || $serverId <= 0) {
    exit("\nUsage: php tools/server-key-check.php /path/to/whmcs <serverid>\n\n");
}

if (!is_readable($whmcs . '/init.php')) {
    exit("\nNo init.php under {$whmcs} — give the WHMCS root directory.\n\n");
}

require $whmcs . '/init.php';

/** Describe a string without disclosing it. */
function shape($value): string
{
    if (!is_string($value)) {
        return gettype($value);
    }

    if ($value === '') {
        return 'empty';
    }

    $odd = preg_replace('/[A-Za-z0-9]/', '', $value);
    $distinct = is_string($odd) && $odd !== '' ? count_chars($odd, 3) : '';

    return sprintf(
        '%d chars, %d non-alphanumeric%s',
        strlen($value),
        strlen((string) $distinct) === 0 ? 0 : strlen($odd),
        $distinct === '' ? '' : ' (' . $distinct . ')'
    );
}

echo "\nServer key check — server {$serverId}\n";
echo str_repeat('=', 60) . "\n\n";

$row = \WHMCS\Database\Capsule::table('tblservers')
    ->where('id', $serverId)
    ->first(['hostname', 'type', 'accesshash']);

if ($row === null) {
    exit("No server with id {$serverId}.\n\n");
}

$row = (array) $row;
$stored = (string) $row['accesshash'];

printf("  %-22s %s\n", 'hostname', $row['hostname']);
printf("  %-22s %s\n", 'module', $row['type']);
printf("  %-22s %s\n", 'stored accesshash', shape($stored));

$decrypted = '';

if (function_exists('localAPI')) {
    $result = localAPI('DecryptPassword', ['password2' => $stored]);

    printf("\n  %-22s %s\n", 'DecryptPassword result', isset($result['result']) ? $result['result'] : '(no result key)');

    if (isset($result['message'])) {
        printf("  %-22s %s\n", 'message', $result['message']);
    }

    $decrypted = isset($result['password']) ? (string) $result['password'] : '';
    printf("  %-22s %s\n", 'decrypted', shape($decrypted));
} else {
    echo "\n  localAPI unavailable\n";
}

echo "\n" . str_repeat('-', 60) . "\n";

if ($decrypted === '') {
    echo "  DecryptPassword returned nothing. A hook cannot get the key this way.\n";
} elseif ($decrypted === $stored) {
    echo "  Returned the stored value unchanged - it did not decrypt.\n";
} elseif (preg_match('/[^A-Za-z0-9]/', $decrypted)) {
    echo "  Decrypted, but the result is not alphanumeric, so it is not a CWP API key.\n";
} else {
    echo "  Looks like a usable CWP API key.\n";
}

echo "\nDelete this file when finished.\n\n";
