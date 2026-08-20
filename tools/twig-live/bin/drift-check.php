#!/usr/bin/env php
<?php

/**
 * Does this repo still match what is actually deployed?
 *
 * These template files are copies of Metadata Display entities that live in a
 * database. Nothing keeps the two in step: someone edits a template in the
 * admin UI and never commits it, or merges a PR here and never pastes it in.
 * Both directions are invisible until something breaks in public.
 *
 * This compares every mapped file against the twig field of its entity on the
 * target site and reports what has diverged. It reads only -- it never writes
 * to the site, and it never edits your files.
 *
 * BY DEFAULT DRIFT DOES NOT FAIL THE BUILD. Most of this repo currently
 * differs from archipelago-dev, so a gate would be noise on day one. Use
 * --strict once you have reconciled the two, and it becomes a real check.
 *
 * Usage:
 *   php tools/twig-live/bin/drift-check.php [options]
 *
 * Options:
 *   --format=pretty|github Output style. Default pretty.
 *   --summary=<file>       Also write Markdown (for $GITHUB_STEP_SUMMARY).
 *   --strict               Exit 1 when anything has drifted.
 *   --mapping=<file>       Override the mapping file.
 *   --help
 *
 * Environment:
 *   ARCHIPELAGO_URL, ARCHIPELAGO_USER, ARCHIPELAGO_PASS
 *
 * Exit code 0 = reported successfully (see --strict), 2 = could not run.
 */

declare(strict_types=1);

use Tamu\ArchipelagoLive\Client;

require __DIR__ . '/../src/Client.php';

$options = [
    'format' => 'pretty',
    'summary' => null,
    'strict' => false,
    'mapping' => __DIR__ . '/../registry/mapping.json',
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, extractDocblock(__FILE__));
        exit(0);
    }
    if ($arg === '--strict') {
        $options['strict'] = true;
        continue;
    }
    foreach (['format', 'summary', 'mapping'] as $name) {
        if (str_starts_with($arg, "--{$name}=")) {
            $options[$name] = substr($arg, strlen($name) + 3);
            continue 2;
        }
    }
    fwrite(STDERR, "Unknown option: {$arg}\n");
    exit(2);
}

if (!in_array($options['format'], ['pretty', 'github'], true)) {
    fwrite(STDERR, "--format must be 'pretty' or 'github'\n");
    exit(2);
}

$repoRoot = realpath(__DIR__ . '/../../..');
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(2);
}

$mapping = loadMapping((string) $options['mapping']);

try {
    $client = Client::fromEnvironment();
    $entities = $client->metadataDisplays();
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$byUuid = [];
foreach ($entities as $entity) {
    $byUuid[$entity['uuid']] = $entity;
}

$inSync = [];
$drifted = [];
$missingEntity = [];

foreach ($mapping['templates'] as $file => $record) {
    $absolute = $repoRoot . '/twig/metadatadisplays/' . $file;
    if (!is_file($absolute)) {
        $missingEntity[] = ['file' => $file, 'reason' => 'file listed in mapping but not present in the repo'];
        continue;
    }

    $entity = $byUuid[$record['uuid']] ?? null;
    if ($entity === null) {
        $missingEntity[] = ['file' => $file, 'reason' => sprintf('no entity %s on the site (deleted or recreated?)', $record['uuid'])];
        continue;
    }

    $local = normalise((string) file_get_contents($absolute));
    $remote = normalise($entity['twig']);

    if ($local === $remote) {
        $inSync[] = $file;
        continue;
    }

    $localLines = explode("\n", $local);
    $remoteLines = explode("\n", $remote);

    $drifted[] = [
        'file' => $file,
        'name' => $entity['name'],
        'local_lines' => count($localLines),
        'remote_lines' => count($remoteLines),
        'first_diff' => firstDifferingLine($localLines, $remoteLines),
        'changed' => $entity['changed'],
    ];
}

$options['format'] === 'github'
    ? reportGithub($drifted, $missingEntity, $mapping, count($inSync))
    : reportPretty($drifted, $missingEntity, $mapping, count($inSync));

if ($options['summary'] !== null) {
    file_put_contents(
        $options['summary'],
        summaryMarkdown($drifted, $missingEntity, $mapping, count($inSync)),
        FILE_APPEND,
    );
}

$problems = count($drifted) + count($missingEntity);

exit($options['strict'] && $problems > 0 ? 1 : 0);

// ---------------------------------------------------------------------------

/**
 * Compare the way a human would: ignore the things a copy/paste round trip
 * changes but Twig does not care about. A BOM, CRLF and trailing whitespace
 * are all artefacts of moving text between a browser textarea and a file.
 */
function normalise(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = str_replace("\r\n", "\n", $value);
    $value = (string) preg_replace('/[ \t]+\n/', "\n", $value);

    return trim($value);
}

/**
 * @param list<string> $local
 * @param list<string> $remote
 */
function firstDifferingLine(array $local, array $remote): int
{
    $limit = min(count($local), count($remote));
    for ($i = 0; $i < $limit; $i++) {
        if (trim($local[$i]) !== trim($remote[$i])) {
            return $i + 1;
        }
    }

    return $limit + 1;
}

/**
 * @return array{templates: array<string, array{uuid:string, id:int, name:string}>, site: string, unmapped_files: list<string>, entities_without_file: list<string>}
 */
function loadMapping(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "Mapping file not found: {$path}\n");
        fwrite(STDERR, "Generate one with: php tools/twig-live/bin/generate-mapping.php\n");
        exit(2);
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['templates'])) {
        fwrite(STDERR, "Mapping file is not valid JSON or has no 'templates' key: {$path}\n");
        exit(2);
    }

    $decoded['unmapped_files'] ??= [];
    $decoded['entities_without_file'] ??= [];
    $decoded['site'] ??= '(unrecorded)';

    return $decoded;
}

/**
 * @param list<array{file:string, name:string, local_lines:int, remote_lines:int, first_diff:int, changed:string}> $drifted
 * @param list<array{file:string, reason:string}> $missing
 * @param array<mixed> $mapping
 */
function reportPretty(array $drifted, array $missing, array $mapping, int $inSync): void
{
    $tty = stream_isatty(STDOUT);
    $green = $tty ? "\033[32m" : '';
    $yellow = $tty ? "\033[33m" : '';
    $bold = $tty ? "\033[1m" : '';
    $dim = $tty ? "\033[2m" : '';
    $off = $tty ? "\033[0m" : '';

    fwrite(STDOUT, sprintf(
        "%sRepo vs deployed%s %s(%s)%s\n\n",
        $bold,
        $off,
        $dim,
        getenv('ARCHIPELAGO_URL') ?: $mapping['site'],
        $off,
    ));

    foreach ($drifted as $item) {
        fwrite(STDOUT, sprintf(
            "%sDRIFT%s %s\n      entity \"%s\" differs from line %d  (repo %d lines, site %d lines)\n      site last changed %s\n\n",
            $yellow,
            $off,
            $item['file'],
            $item['name'],
            $item['first_diff'],
            $item['local_lines'],
            $item['remote_lines'],
            $item['changed'] !== '' ? $item['changed'] : 'unknown',
        ));
    }

    foreach ($missing as $item) {
        fwrite(STDOUT, sprintf("%sMISSING%s %s\n        %s\n\n", $yellow, $off, $item['file'], $item['reason']));
    }

    foreach ($mapping['unmapped_files'] as $file) {
        fwrite(STDOUT, sprintf("%sNO ENTITY%s %s\n          exists in the repo, nowhere on the site\n\n", $yellow, $off, $file));
    }

    foreach ($mapping['entities_without_file'] as $name) {
        fwrite(STDOUT, sprintf("%sUNTRACKED%s \"%s\"\n          exists on the site, no file in this repo\n\n", $yellow, $off, $name));
    }

    $problems = count($drifted) + count($missing) + count($mapping['unmapped_files']) + count($mapping['entities_without_file']);

    fwrite(STDOUT, $problems === 0
        ? sprintf("%sIN SYNC%s all %d mapped templates match the deployed entity.\n", $green, $off, $inSync)
        : sprintf("%d in sync, %d drifted, %d unmatched.\n", $inSync, count($drifted), $problems - count($drifted)));
}

/**
 * @param list<array{file:string, name:string, local_lines:int, remote_lines:int, first_diff:int, changed:string}> $drifted
 * @param list<array{file:string, reason:string}> $missing
 * @param array<mixed> $mapping
 */
function reportGithub(array $drifted, array $missing, array $mapping, int $inSync): void
{
    // Drift is a warning, not an error: a file may legitimately be ahead of a
    // given environment. Only --strict turns it into a failing build.
    foreach ($drifted as $item) {
        fwrite(STDOUT, sprintf(
            "::warning file=twig/metadatadisplays/%s,line=%d,title=Differs from the deployed entity::%s\n",
            $item['file'],
            $item['first_diff'],
            escapeWorkflowData(sprintf(
                'This file and the "%s" entity on the target site diverge from line %d. Repo has %d lines, site has %d.',
                $item['name'],
                $item['first_diff'],
                $item['local_lines'],
                $item['remote_lines'],
            )),
        ));
    }

    reportPretty($drifted, $missing, $mapping, $inSync);
}

function escapeWorkflowData(string $value): string
{
    return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
}

/**
 * @param list<array{file:string, name:string, local_lines:int, remote_lines:int, first_diff:int, changed:string}> $drifted
 * @param list<array{file:string, reason:string}> $missing
 * @param array<mixed> $mapping
 */
function summaryMarkdown(array $drifted, array $missing, array $mapping, int $inSync): string
{
    $site = getenv('ARCHIPELAGO_URL') ?: $mapping['site'];

    if ($drifted === [] && $missing === [] && $mapping['unmapped_files'] === [] && $mapping['entities_without_file'] === []) {
        return sprintf(
            "### Repo vs deployed\n\nAll **%d** mapped templates match `%s` exactly.\n",
            $inSync,
            $site,
        );
    }

    $out = sprintf(
        "### Repo vs deployed — %d in sync, %d drifted\n\nCompared against `%s`.\n\n",
        $inSync,
        count($drifted),
        $site,
    );

    if ($drifted !== []) {
        $out .= "| Template | Entity | Diverges from | Repo | Site |\n|---|---|---|---|---|\n";
        foreach ($drifted as $item) {
            $out .= sprintf(
                "| `%s` | %s | line %d | %d lines | %d lines |\n",
                $item['file'],
                str_replace('|', '\\|', $item['name']),
                $item['first_diff'],
                $item['local_lines'],
                $item['remote_lines'],
            );
        }
        $out .= "\n";
    }

    if ($mapping['unmapped_files'] !== []) {
        $out .= "**In the repo, not on the site:** " . implode(', ', array_map(
            static fn (string $f): string => "`{$f}`",
            $mapping['unmapped_files'],
        )) . "\n\n";
    }

    if ($mapping['entities_without_file'] !== []) {
        $out .= "**On the site, not in the repo:** " . implode(', ', array_map(
            static fn (string $n): string => "`{$n}`",
            $mapping['entities_without_file'],
        )) . "\n\n";
    }

    foreach ($missing as $item) {
        $out .= sprintf("- `%s`: %s\n", $item['file'], $item['reason']);
    }

    return $out . "\n<sub>Drift is reported, not enforced. Run with `--strict` to make it fail.</sub>\n";
}

function extractDocblock(string $file): string
{
    $contents = (string) file_get_contents($file);
    if (preg_match('#/\*\*(.*?)\*/#s', $contents, $matches) !== 1) {
        return "No help available.\n";
    }

    return trim((string) preg_replace('/^\s*\* ?/m', '', $matches[1])) . "\n";
}
