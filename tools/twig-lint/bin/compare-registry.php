#!/usr/bin/env php
<?php

/**
 * Checks registry/extensions.json against what really exists.
 *
 * This closes the linter's one silent failure mode. A name listed in the
 * registry that the target install does NOT provide makes lint.php accept a
 * template Archipelago will reject -- a green build over a broken manifest.
 * A name missing from the registry only ever causes a loud false failure, so
 * it is reported for information but does not fail the job.
 *
 * Usage:
 *   php bin/compare-registry.php <dump.json> [--format=github] [--summary=<file>]
 *
 * The dump comes from either:
 *   bin/build-dump.php        upstream module sources  (automated, CI)
 *   bin/dump-twig-names.php   the deployed site        (authoritative)
 *
 * Exit code 0 = every registered name was found, 1 = at least one was not.
 */

declare(strict_types=1);

use Tamu\ArchipelagoTwigLint\Registry;

require __DIR__ . '/../vendor/autoload.php';

$registryPath = __DIR__ . '/../registry/extensions.json';
$dumpPath = null;
$format = 'pretty';
$summary = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--format=')) {
        $format = substr($arg, 9);
    } elseif (str_starts_with($arg, '--summary=')) {
        $summary = substr($arg, 10);
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    } else {
        $dumpPath = $arg;
    }
}

if ($dumpPath === null || !is_file($dumpPath)) {
    fwrite(STDERR, "Usage: compare-registry.php <dump.json> [--format=github] [--summary=<file>]\n");
    exit(2);
}

$dump = json_decode((string) file_get_contents($dumpPath), true, 512, JSON_THROW_ON_ERROR);
$registry = Registry::fromFile($registryPath);
$registryRaw = (string) file_get_contents($registryPath);

$available = [];
foreach (['filters', 'functions', 'tests'] as $kind) {
    $available[$kind] = array_flip($dump[$kind] ?? []);
}

$missing = [];   // registered here, not found in the dump -> FALSE PASS risk
$exempt = [];    // registered here, deliberately not checkable from this dump
$matched = 0;

foreach ($registry->sources() as $source) {
    $isExempt = ($source['scan_exempt'] ?? false) === true;

    foreach (['filters', 'functions', 'tests'] as $kind) {
        foreach ($source[$kind] ?? [] as $name) {
            if (isset($available[$kind][$name])) {
                $matched++;
                continue;
            }

            $entry = [
                'name' => $name,
                'kind' => rtrim($kind, 's'),
                'source' => $source['id'],
                'line' => lineOf($registryRaw, $name),
            ];

            $isExempt ? $exempt[] = $entry : $missing[] = $entry;
        }
    }
}

$offered = count($dump['filters'] ?? []) + count($dump['functions'] ?? []) + count($dump['tests'] ?? []);

if ($format === 'github') {
    foreach ($missing as $entry) {
        fwrite(STDOUT, sprintf(
            "::error file=tools/twig-lint/registry/extensions.json,line=%d,title=Registry lists a %s that does not exist::"
            . "'%s' is registered under '%s' but was not found in the scanned sources. "
            . "Until this is resolved the linter may accept templates Archipelago would reject.\n",
            $entry['line'],
            $entry['kind'],
            $entry['name'],
            $entry['source'],
        ));
    }
}

report($missing, $exempt, $matched, $offered, $dump);

if ($summary !== null) {
    file_put_contents($summary, summaryMarkdown($missing, $exempt, $matched, $offered, $dump));
}

exit($missing === [] ? 0 : 1);

// ---------------------------------------------------------------------------

/**
 * Best-effort line number for a name inside the registry JSON, so the GitHub
 * annotation lands somewhere useful.
 */
function lineOf(string $haystack, string $name): int
{
    foreach (explode("\n", $haystack) as $i => $line) {
        if (str_contains($line, '"' . $name . '"')) {
            return $i + 1;
        }
    }

    return 1;
}

/**
 * @param list<array<string, mixed>> $missing
 * @param list<array<string, mixed>> $exempt
 * @param array<string, mixed>       $dump
 */
function report(array $missing, array $exempt, int $matched, int $offered, array $dump): void
{
    $tty = stream_isatty(STDOUT);
    $red = $tty ? "\033[31m" : '';
    $green = $tty ? "\033[32m" : '';
    $yellow = $tty ? "\033[33m" : '';
    $dim = $tty ? "\033[2m" : '';
    $bold = $tty ? "\033[1m" : '';
    $off = $tty ? "\033[0m" : '';

    printf(
        "%sRegistry drift check%s %s(%s, %d names offered by %d files)%s\n\n",
        $bold,
        $off,
        $dim,
        $dump['source'] ?? 'unknown',
        $offered,
        $dump['scanned_files'] ?? 0,
        $off,
    );

    foreach ($missing as $entry) {
        printf(
            "%sMISSING%s %s (%s, registered under '%s')\n         Not found in the scanned sources. Templates using it may pass\n         lint and still be rejected by Archipelago.\n\n",
            $red,
            $off,
            $entry['name'],
            $entry['kind'],
            $entry['source'],
        );
    }

    foreach ($exempt as $entry) {
        printf(
            "%sEXEMPT%s  %s (%s, '%s') -- not verifiable from these sources.\n",
            $yellow,
            $off,
            $entry['name'],
            $entry['kind'],
            $entry['source'],
        );
    }

    if ($exempt !== []) {
        echo "\n";
    }

    printf(
        "%s%d registered name%s confirmed%s",
        $matched > 0 ? $green : '',
        $matched,
        $matched === 1 ? '' : 's',
        $off,
    );
    printf("%s, %d exempt, %d missing.%s\n", $missing === [] ? $green : $red, count($exempt), count($missing), $off);

    if ($missing === []) {
        echo "Every name the linter trusts was found. No false-pass risk from the registry.\n";
    }
}

/**
 * @param list<array<string, mixed>> $missing
 * @param list<array<string, mixed>> $exempt
 * @param array<string, mixed>       $dump
 */
function summaryMarkdown(array $missing, array $exempt, int $matched, int $offered, array $dump): string
{
    $out = "### Twig registry drift check\n\n";
    $out .= sprintf(
        "Source: `%s` — %d names offered across %d files.\n\n",
        $dump['source'] ?? 'unknown',
        $offered,
        $dump['scanned_files'] ?? 0,
    );

    if ($missing === []) {
        $out .= sprintf(
            "All **%d** registered names confirmed to exist%s. No false-pass risk from the registry.\n",
            $matched,
            $exempt === [] ? '' : sprintf(', %d exempt', count($exempt)),
        );
    } else {
        $out .= sprintf(
            "**%d registered name%s could not be found.** The linter trusts %s, so a template using %s "
            . "can pass CI and still be rejected by Archipelago.\n\n"
            . "| Name | Kind | Registered under |\n|---|---|---|\n",
            count($missing),
            count($missing) === 1 ? '' : 's',
            count($missing) === 1 ? 'it' : 'them',
            count($missing) === 1 ? 'it' : 'one of them',
        );

        foreach ($missing as $entry) {
            $out .= sprintf("| `%s` | %s | `%s` |\n", $entry['name'], $entry['kind'], $entry['source']);
        }
    }

    if ($exempt !== []) {
        $out .= "\n<details><summary>" . count($exempt) . " exempt (not verifiable from these sources)</summary>\n\n";
        foreach ($exempt as $entry) {
            $out .= sprintf("- `%s` (%s, `%s`)\n", $entry['name'], $entry['kind'], $entry['source']);
        }
        $out .= "\nConfirm these by running `bin/dump-twig-names.php` against the deployed site.\n</details>\n";
    }

    return $out;
}
