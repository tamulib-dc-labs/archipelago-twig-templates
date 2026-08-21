#!/usr/bin/env php
<?php

/**
 * Render each metadata display against known fixtures and validate the output.
 *
 * tools/twig-lint answers "would Archipelago ACCEPT this template" -- a parse
 * time question. It cannot see that a template emits {"items":[{...},]}, which
 * parses perfectly, saves into Archipelago without complaint, and breaks every
 * viewer downstream.
 *
 * This renders the template against committed Strawberryfield fixtures and
 * checks what comes out: valid JSON, valid XML, and for IIIF Presentation 3.0,
 * valid against schema/iiif_3_0.json from IIIF/presentation-validator.
 *
 * Runs entirely offline. No Drupal, no network, no credentials. Templates that
 * genuinely need a database are declared with a "skip" reason in templates.json
 * rather than quietly omitted -- and if one reaches for such a filter anyway,
 * the renderer THROWS instead of emitting output nobody should trust.
 *
 * Usage:
 *   php tools/twig-render/bin/render-check.php [options] [template ...]
 *
 * Options:
 *   --format=pretty|github  Output style. Default pretty.
 *   --summary=<file>        Also write Markdown (for $GITHUB_STEP_SUMMARY).
 *   --write=<dir>           Dump every rendered document for inspection.
 *   --help
 *
 * Exit code 0 = every rendered document is valid, 1 = at least one is not,
 * 2 = the check could not run.
 */

declare(strict_types=1);

use Tamu\ArchipelagoTwigRender\Context;
use Tamu\ArchipelagoTwigRender\Problem;
use Tamu\ArchipelagoTwigRender\Renderer;
use Tamu\ArchipelagoTwigRender\UnsupportedByRendererException;
use Tamu\ArchipelagoTwigRender\Validator;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies missing. Run: composer install -d tools/twig-render\n");
    exit(2);
}
require $autoload;

$options = ['format' => 'pretty', 'summary' => null, 'write' => null];
$only = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, extractDocblock(__FILE__));
        exit(0);
    }
    $matched = false;
    foreach (['format', 'summary', 'write'] as $name) {
        if (str_starts_with($arg, "--{$name}=")) {
            $options[$name] = substr($arg, strlen($name) + 3);
            $matched = true;
            break;
        }
    }
    if ($matched) {
        continue;
    }
    if (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
    $only[] = basename($arg);
}

if (!in_array($options['format'], ['pretty', 'github'], true)) {
    fwrite(STDERR, "--format must be 'pretty' or 'github'\n");
    exit(2);
}

$root = realpath(__DIR__ . '/..');
$repoRoot = realpath(__DIR__ . '/../../..');
if ($root === false || $repoRoot === false) {
    fwrite(STDERR, "Could not resolve paths.\n");
    exit(2);
}

$config = json_decode((string) file_get_contents($root . '/templates.json'), true);
if (!is_array($config) || !isset($config['templates'])) {
    fwrite(STDERR, "templates.json is missing or malformed.\n");
    exit(2);
}

$fixtures = loadFixtures($root . '/fixtures');
if ($fixtures === []) {
    fwrite(STDERR, "No fixtures found in tools/twig-render/fixtures.\n");
    exit(2);
}

$renderer = new Renderer(new Context());
$validator = new Validator();

if ($options['write'] !== null && !is_dir($options['write'])) {
    mkdir($options['write'], 0o777, true);
}

$results = [];
$index = [];
$skipped = [];

foreach ($config['templates'] as $entry) {
    $file = (string) ($entry['file'] ?? '');
    if ($file === '' || ($only !== [] && !in_array($file, $only, true))) {
        continue;
    }

    if (isset($entry['skip'])) {
        $skipped[] = ['file' => $file, 'reason' => (string) $entry['skip']];
        continue;
    }

    $path = $repoRoot . '/twig/metadatadisplays/' . $file;
    if (!is_file($path)) {
        $results[] = result($file, '-', [new Problem('missing', 'Template file not found in twig/metadatadisplays.')]);
        continue;
    }

    $source = (string) file_get_contents($path);
    $source = preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source;

    $names = $entry['fixtures'] ?? $config['defaults']['fixtures'] ?? array_keys($fixtures);

    foreach ($names as $name) {
        if (!isset($fixtures[$name])) {
            $results[] = result($file, (string) $name, [new Problem('missing', "Fixture '{$name}' not found.")]);
            continue;
        }

        try {
            $output = $renderer->render($source, $fixtures[$name], (string) $name);
        } catch (UnsupportedByRendererException $e) {
            $results[] = result($file, (string) $name, [new Problem('needs-drupal', $e->getMessage())]);
            continue;
        } catch (\Throwable $e) {
            $results[] = result($file, (string) $name, [new Problem('render-error', get_class($e) . ': ' . $e->getMessage())]);
            continue;
        }

        if ($options['write'] !== null) {
            $extension = str_contains((string) $entry['mimetype'], 'xml') ? 'xml' : 'json';
            $written = rtrim($options['write'], '/') . '/' . pathinfo($file, PATHINFO_FILENAME) . '--' . $name . '.' . $extension;
            file_put_contents($written, $output);

            // Documents flagged iiif are handed to bin/validate_iiif.py, which
            // parses them with iiif-prezi3. Carrying the template and fixture
            // names through means a failure is reported against the template
            // someone can fix, not a temp file nobody recognises.
            if (($entry['iiif'] ?? false) === true) {
                $index[] = ['output' => $written, 'template' => $file, 'fixture' => (string) $name];
            }
        }

        $results[] = result(
            $file,
            (string) $name,
            $validator->validate($output, (string) ($entry['mimetype'] ?? 'text/plain'), (bool) ($entry['geojson'] ?? false)),
            strlen($output),
        );
    }
}

if ($options['write'] !== null) {
    file_put_contents(rtrim($options['write'], '/') . '/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$options['format'] === 'github' ? reportGithub($results, $skipped) : reportPretty($results, $skipped);

if ($options['summary'] !== null) {
    file_put_contents($options['summary'], summaryMarkdown($results, $skipped), FILE_APPEND);
}

exit(hasFailure($results) ? 1 : 0);

// ---------------------------------------------------------------------------

/**
 * @param list<Problem> $problems
 *
 * @return array{file:string, fixture:string, problems:list<Problem>, bytes:int}
 */
function result(string $file, string $fixture, array $problems, int $bytes = 0): array
{
    return ['file' => $file, 'fixture' => $fixture, 'problems' => $problems, 'bytes' => $bytes];
}

/**
 * @return array<string, array<mixed>>
 */
function loadFixtures(string $directory): array
{
    $out = [];
    foreach (glob($directory . '/*.json') ?: [] as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $out[basename($path, '.json')] = $decoded;
        } else {
            fwrite(STDERR, "Skipping unreadable fixture: {$path}\n");
        }
    }

    return $out;
}

/**
 * @param list<array{file:string, fixture:string, problems:list<Problem>, bytes:int}> $results
 */
function hasFailure(array $results): bool
{
    foreach ($results as $r) {
        if ($r['problems'] !== []) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{file:string, fixture:string, problems:list<Problem>, bytes:int}> $results
 * @param list<array{file:string, reason:string}> $skipped
 */
function reportPretty(array $results, array $skipped): void
{
    $tty = stream_isatty(STDOUT);
    $red = $tty ? "\033[31m" : '';
    $green = $tty ? "\033[32m" : '';
    $dim = $tty ? "\033[2m" : '';
    $bold = $tty ? "\033[1m" : '';
    $off = $tty ? "\033[0m" : '';

    fwrite(STDOUT, sprintf("%sRender and validate%s %s(offline, against committed fixtures)%s\n\n", $bold, $off, $dim, $off));

    $failed = 0;
    foreach ($results as $r) {
        if ($r['problems'] === []) {
            fwrite(STDOUT, sprintf("%s  ok  %s%s  %s(%s, %d bytes)%s\n", $green, $off, $r['file'], $dim, $r['fixture'], $r['bytes'], $off));
            continue;
        }

        $failed++;
        fwrite(STDOUT, sprintf("%sFAIL%s  %s  %s(%s)%s\n", $red, $off, $r['file'], $dim, $r['fixture'], $off));
        foreach ($r['problems'] as $p) {
            fwrite(STDOUT, sprintf("        [%s] %s%s\n", $p->kind, $p->pointer !== null ? $p->pointer . ' — ' : '', $p->message));
        }
    }

    foreach ($skipped as $s) {
        fwrite(STDOUT, sprintf("%s  --  %s  skipped: %s%s\n", $dim, $s['file'], $s['reason'], $off));
    }

    fwrite(STDOUT, "\n");
    fwrite(STDOUT, $failed === 0
        ? sprintf("%sOK%s %d rendered document%s valid.\n", $green, $off, count($results), count($results) === 1 ? '' : 's')
        : sprintf("%s%d of %d rendered documents invalid.%s\n", $red, $failed, count($results), $off));
}

/**
 * @param list<array{file:string, fixture:string, problems:list<Problem>, bytes:int}> $results
 * @param list<array{file:string, reason:string}> $skipped
 */
function reportGithub(array $results, array $skipped): void
{
    foreach ($results as $r) {
        foreach ($r['problems'] as $p) {
            fwrite(STDOUT, sprintf(
                "::error file=twig/metadatadisplays/%s,line=1,title=Invalid output (%s fixture)::%s\n",
                $r['file'],
                $r['fixture'],
                escapeWorkflowData(($p->pointer !== null ? $p->pointer . ' — ' : '') . $p->message),
            ));
        }
    }

    reportPretty($results, $skipped);
}

function escapeWorkflowData(string $value): string
{
    return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
}

/**
 * @param list<array{file:string, fixture:string, problems:list<Problem>, bytes:int}> $results
 * @param list<array{file:string, reason:string}> $skipped
 */
function summaryMarkdown(array $results, array $skipped): string
{
    $failed = array_values(array_filter($results, static fn (array $r): bool => $r['problems'] !== []));

    if ($failed === []) {
        $out = sprintf(
            "### Render and validate\n\nAll **%d** rendered documents are valid.\n\n",
            count($results),
        );
    } else {
        $out = sprintf(
            "### Render and validate — %d of %d invalid\n\n"
            . "These templates produce output that will not parse or does not satisfy the IIIF schema. "
            . "Archipelago accepts them; consumers will not.\n\n"
            . "| Template | Fixture | Problem |\n|---|---|---|\n",
            count($failed),
            count($results),
        );
        foreach ($failed as $r) {
            foreach ($r['problems'] as $p) {
                $out .= sprintf(
                    "| `%s` | %s | %s |\n",
                    $r['file'],
                    $r['fixture'],
                    str_replace('|', '\\|', ($p->pointer !== null ? '`' . $p->pointer . '` ' : '') . $p->message),
                );
            }
        }
        $out .= "\n";
    }

    if ($skipped !== []) {
        $out .= "<details><summary>Not covered offline (" . count($skipped) . ")</summary>\n\n";
        foreach ($skipped as $s) {
            $out .= sprintf("- `%s` — %s\n", $s['file'], $s['reason']);
        }
        $out .= "\n</details>\n";
    }

    return $out;
}

function extractDocblock(string $file): string
{
    $contents = (string) file_get_contents($file);
    if (preg_match('#/\*\*(.*?)\*/#s', $contents, $matches) !== 1) {
        return "No help available.\n";
    }

    return trim((string) preg_replace('/^\s*\* ?/m', '', $matches[1])) . "\n";
}
