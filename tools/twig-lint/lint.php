#!/usr/bin/env php
<?php

/**
 * Archipelago Twig template linter.
 *
 * Answers exactly one question: would Archipelago accept this template?
 * It reproduces MetadataDisplayEntity::validateSource() offline, so a template
 * that passes here will save cleanly into a Metadata Display entity, and one
 * that fails here renders EMPTY to anonymous visitors in production.
 *
 * Usage:
 *   php tools/twig-lint/lint.php [options] [path ...]
 *
 * Options:
 *   --format=pretty|github   Output style. Default pretty.
 *                            'github' emits ::error workflow commands so the
 *                            problem is annotated inline on the PR diff.
 *   --summary=<file>         Also write a Markdown report (for $GITHUB_STEP_SUMMARY).
 *   --help
 *
 * Exit code 0 = every template parses, 1 = at least one does not.
 */

declare(strict_types=1);

use Tamu\ArchipelagoTwigLint\Diagnostic;
use Tamu\ArchipelagoTwigLint\Linter;
use Tamu\ArchipelagoTwigLint\Registry;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies missing. Run: composer install -d tools/twig-lint\n");
    exit(2);
}
require $autoload;

const DEFAULT_GLOB = 'twig/**/*.twig.html';

$options = ['format' => 'pretty', 'summary' => null];
$paths = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, extractDocblock(__FILE__));
        exit(0);
    }
    if (str_starts_with($arg, '--format=')) {
        $options['format'] = substr($arg, 9);
        continue;
    }
    if (str_starts_with($arg, '--summary=')) {
        $options['summary'] = substr($arg, 10);
        continue;
    }
    if (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
    $paths[] = $arg;
}

if (!in_array($options['format'], ['pretty', 'github'], true)) {
    fwrite(STDERR, "--format must be 'pretty' or 'github'\n");
    exit(2);
}

$repoRoot = realpath(__DIR__ . '/../..');
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(2);
}

$files = $paths === [] ? discover($repoRoot) : normalise($paths, $repoRoot);

if ($files === []) {
    fwrite(STDERR, "No .twig.html files found.\n");
    exit(2);
}

$registry = Registry::fromFile(__DIR__ . '/registry/extensions.json');
$linter = new Linter($registry);

/** @var list<Diagnostic> $diagnostics */
$diagnostics = [];
foreach ($files as $absolute => $relative) {
    foreach ($linter->lintFile($absolute, $relative) as $diagnostic) {
        $diagnostics[] = $diagnostic;
    }
}

$options['format'] === 'github'
    ? reportGithub($diagnostics, count($files), $registry)
    : reportPretty($diagnostics, count($files), $registry);

if ($options['summary'] !== null) {
    file_put_contents($options['summary'], summaryMarkdown($diagnostics, count($files), $registry));
}

exit($diagnostics === [] ? 0 : 1);

// ---------------------------------------------------------------------------

/**
 * The IIIF templates, and only those.
 *
 * Scope is IIIF by decision, not by accident. The MODS, Dublin Core, OAI-PMH,
 * GeoJSON, schema.org and DataCite templates are deliberately NOT checked here
 * and are expected to get their own tier later.
 *
 * The list comes from tools/twig-render/templates.json rather than a filename
 * pattern or a second copy kept here. That file already has to enumerate every
 * IIIF template -- the five it renders and the four it cannot -- so reading it
 * means there is exactly one definition of "which templates are IIIF" and the
 * two tools cannot drift apart. Pass paths explicitly to lint anything else.
 *
 * @return array<string, string> absolute path => repo-relative path
 */
function discover(string $repoRoot): array
{
    $manifest = $repoRoot . '/tools/twig-render/templates.json';
    if (!is_file($manifest)) {
        fwrite(STDERR, "Missing {$manifest}, which defines the IIIF template set.\n");
        fwrite(STDERR, "Pass template paths explicitly to lint without it.\n");
        exit(2);
    }

    $config = json_decode((string) file_get_contents($manifest), true);
    if (!is_array($config) || !isset($config['templates'])) {
        fwrite(STDERR, "templates.json is malformed: no 'templates' key.\n");
        exit(2);
    }

    $found = [];
    foreach ($config['templates'] as $entry) {
        $name = (string) ($entry['file'] ?? '');
        if ($name === '') {
            continue;
        }

        // Templates marked "skip" cannot be RENDERED offline, but they are
        // still Twig and still have to parse, so they are linted like the rest.
        $absolute = $repoRoot . '/twig/metadatadisplays/' . $name;
        if (is_file($absolute)) {
            $found[$absolute] = relative($absolute, $repoRoot);
        } else {
            fwrite(STDERR, "Listed in templates.json but missing: {$name}\n");
        }
    }

    ksort($found);

    return $found;
}

/**
 * @param list<string> $paths
 *
 * @return array<string, string>
 */
function normalise(array $paths, string $repoRoot): array
{
    $found = [];
    foreach ($paths as $path) {
        $absolute = realpath($path);
        if ($absolute === false || !is_file($absolute)) {
            fwrite(STDERR, "Skipping (not a file): {$path}\n");
            continue;
        }
        $found[$absolute] = relative($absolute, $repoRoot);
    }

    ksort($found);

    return $found;
}

function relative(string $absolute, string $repoRoot): string
{
    $normalised = str_replace('\\', '/', $absolute);
    $root = str_replace('\\', '/', $repoRoot);

    return str_starts_with($normalised, $root . '/')
        ? substr($normalised, strlen($root) + 1)
        : $normalised;
}

/**
 * @param list<Diagnostic> $diagnostics
 */
function reportPretty(array $diagnostics, int $total, Registry $registry): void
{
    $tty = stream_isatty(STDOUT);
    $red = $tty ? "\033[31m" : '';
    $green = $tty ? "\033[32m" : '';
    $dim = $tty ? "\033[2m" : '';
    $bold = $tty ? "\033[1m" : '';
    $off = $tty ? "\033[0m" : '';

    fwrite(STDOUT, sprintf(
        "%sArchipelago Twig lint%s %s(validateSource parity, %d stub names)%s\n\n",
        $bold,
        $off,
        $dim,
        $registry->total(),
        $off,
    ));

    foreach ($diagnostics as $diagnostic) {
        fwrite(STDOUT, sprintf(
            "%sFAIL%s %s:%d\n     %s\n\n",
            $red,
            $off,
            $diagnostic->file,
            $diagnostic->line,
            $diagnostic->message,
        ));
    }

    $failed = count(array_unique(array_map(static fn (Diagnostic $d): string => $d->file, $diagnostics)));

    fwrite(STDOUT, $diagnostics === []
        ? sprintf("%sOK%s %d template%s parse cleanly.\n", $green, $off, $total, $total === 1 ? '' : 's')
        : sprintf("%s%d of %d template%s would be rejected by Archipelago.%s\n", $red, $failed, $total, $total === 1 ? '' : 's', $off));
}

/**
 * @param list<Diagnostic> $diagnostics
 */
function reportGithub(array $diagnostics, int $total, Registry $registry): void
{
    foreach ($diagnostics as $diagnostic) {
        // ::error carries the annotation onto the exact line in Files changed.
        fwrite(STDOUT, sprintf(
            "::error file=%s,line=%d,title=Archipelago would reject this template::%s\n",
            $diagnostic->file,
            max($diagnostic->line, 1),
            escapeWorkflowData($diagnostic->message),
        ));
    }

    reportPretty($diagnostics, $total, $registry);
}

/**
 * GitHub workflow commands are newline delimited and use % for escaping.
 */
function escapeWorkflowData(string $value): string
{
    return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
}

/**
 * @param list<Diagnostic> $diagnostics
 */
function summaryMarkdown(array $diagnostics, int $total, Registry $registry): string
{
    if ($diagnostics === []) {
        return sprintf(
            "### Archipelago Twig lint\n\nAll **%d** templates parse cleanly — Archipelago would accept every one.\n\n"
            . "<sub>Parity check against `MetadataDisplayEntity::validateSource()`, %d stubbed extension names.</sub>\n",
            $total,
            $registry->total(),
        );
    }

    $rows = '';
    foreach ($diagnostics as $diagnostic) {
        $rows .= sprintf(
            "| `%s` | %d | %s |\n",
            $diagnostic->file,
            $diagnostic->line,
            str_replace('|', '\\|', $diagnostic->message),
        );
    }

    return sprintf(
        "### Archipelago Twig lint — %d problem%s\n\n"
        . "These templates fail `MetadataDisplayEntity::validateSource()`. In production they render "
        . "the error text to logged-in users and **empty output to anonymous visitors**.\n\n"
        . "| Template | Line | Problem |\n|---|---|---|\n%s\n"
        . "<sub>Checked %d templates against %d stubbed extension names.</sub>\n",
        count($diagnostics),
        count($diagnostics) === 1 ? '' : 's',
        $rows,
        $total,
        $registry->total(),
    );
}

function extractDocblock(string $file): string
{
    $contents = (string) file_get_contents($file);
    if (preg_match('#/\*\*(.*?)\*/#s', $contents, $matches) !== 1) {
        return "No help available.\n";
    }

    return trim((string) preg_replace('/^\s*\* ?/m', '', $matches[1])) . "\n";
}
