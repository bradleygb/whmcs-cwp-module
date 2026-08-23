<?php
/**
 * CWP Hosting Module for WHMCS — build the release archive.
 *
 * Run from anywhere:
 *
 *     php tools/build-release.php [output-directory]
 *
 * The archive is everything in the module directory except the entries listed in
 * EXCLUDE — an exclusion list rather than an inclusion one, so a new class file is
 * shipped by default and a new development-only file is the thing that needs a decision.
 *
 * Not shipped in the release archive.
 *
 * @package cwp7
 * @license MIT
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** Development-only paths, relative to the module root. */
const EXCLUDE = [
    '.git',
    '.gitattributes',
    '.gitignore',
    '.github',
    'docs',
    'tests',
    'tools',
    'config.php',
];

$root = dirname(__DIR__);
$outputDir = isset($argv[1]) ? rtrim($argv[1], '/\\') : dirname($root);

$source = file_get_contents($root . '/cwp7.php');
if ($source === false || preg_match("/CWP7_MODULE_VERSION',\s*'([^']+)'/", $source, $m) !== 1) {
    fwrite(STDERR, "could not read the version from cwp7.php\n");
    exit(1);
}
$version = $m[1];

$archive = $outputDir . '/cwp7-' . $version . '.zip';

if (file_exists($archive)) {
    fwrite(STDERR, $archive . " already exists — bump the version or remove it\n");
    exit(1);
}

$files = [];
collect($root, '', $files);
sort($files);

if ($files === []) {
    fwrite(STDERR, "nothing to package\n");
    exit(1);
}

$zip = new ZipArchive();

if ($zip->open($archive, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, 'could not create ' . $archive . "\n");
    exit(1);
}

foreach ($files as $relative) {
    $zip->addFile($root . '/' . $relative, 'cwp7/' . $relative);
    echo '  cwp7/', $relative, "\n";
}

$zip->close();

echo "\n", count($files), ' files -> ', $archive, "\n";

/**
 * @param array<int,string> $files
 */
function collect(string $dir, string $prefix, array &$files): void
{
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;

        if (in_array($relative, EXCLUDE, true) || substr($entry, -4) === '.zip') {
            continue;
        }

        if (is_dir($dir . '/' . $entry)) {
            collect($dir . '/' . $entry, $relative, $files);
            continue;
        }

        $files[] = $relative;
    }
}
