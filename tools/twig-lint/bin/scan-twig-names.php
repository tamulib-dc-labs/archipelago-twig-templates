#!/usr/bin/env php
<?php

/**
 * Scans PHP source for Twig extension names that modules declare.
 *
 * Usage:
 *   php bin/scan-twig-names.php <dir-or-file> [<dir-or-file> ...] > dump.json
 *
 * Emits the same JSON shape as bin/dump-twig-names.php, so compare-registry.php
 * accepts either as input.
 */

declare(strict_types=1);

use Tamu\ArchipelagoTwigLint\NameScanner;

require __DIR__ . '/../vendor/autoload.php';

$paths = array_slice($argv, 1);

if ($paths === []) {
    fwrite(STDERR, "Usage: scan-twig-names.php <dir-or-file> [...]\n");
    exit(2);
}

$scanner = new NameScanner();
foreach ($paths as $path) {
    $scanner->scanPath($path, basename($path));
}

echo json_encode($scanner->toArray('static-scan'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
