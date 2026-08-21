#!/usr/bin/env php
<?php

/**
 * Self-test for the linter itself.
 *
 * Guards the two ways this tool can quietly stop being useful:
 *
 *  1. The registry grows so permissive that nothing ever fails. The bad_*
 *     fixtures must keep failing.
 *  2. Someone trims a name out of the registry. The good_* fixture uses every
 *     non-core name we register, so it starts failing and names the casualty.
 *
 * Run before lint.php in CI. Exit 0 = the linter works.
 */

declare(strict_types=1);

use Tamu\ArchipelagoTwigLint\Linter;
use Tamu\ArchipelagoTwigLint\Registry;

require __DIR__ . '/vendor/autoload.php';

$linter = new Linter(Registry::fromFile(__DIR__ . '/registry/extensions.json'));

$fixtures = glob(__DIR__ . '/tests/fixtures/*.twig.html') ?: [];
if ($fixtures === []) {
    fwrite(STDERR, "No fixtures found.\n");
    exit(2);
}

$failures = 0;

foreach ($fixtures as $path) {
    $name = basename($path);
    $shouldFail = str_starts_with($name, 'bad_');
    $diagnostics = $linter->lintFile($path, $name);
    $didFail = $diagnostics !== [];

    if ($didFail === $shouldFail) {
        printf("  ok   %-36s %s\n", $name, $shouldFail ? 'rejected as expected' : 'accepted as expected');
        continue;
    }

    $failures++;
    if ($shouldFail) {
        printf("  FAIL %-36s expected a syntax error, got none.\n", $name);
        printf("       The linter has stopped catching this bug class.\n");
    } else {
        printf("  FAIL %-36s expected to parse, but got:\n", $name);
        foreach ($diagnostics as $diagnostic) {
            printf("       line %d: %s\n", $diagnostic->line, $diagnostic->message);
            printf("       If that name is real, it is missing from registry/extensions.json.\n");
        }
    }
}

$total = count($fixtures);
printf("\n%d fixture%s, %d failure%s\n", $total, $total === 1 ? '' : 's', $failures, $failures === 1 ? '' : 's');

exit($failures === 0 ? 0 : 1);
