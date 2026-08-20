#!/usr/bin/env php
<?php

/**
 * Check every name the offline linter trusts against the live site.
 *
 * This closes tools/twig-lint's one SILENT failure mode. Its registry lists the
 * Twig names it believes the target Archipelago has; a name in there that the
 * site does NOT have means the linter accepts templates the site will reject --
 * a green build over a manifest that renders empty to the public.
 *
 * registry-drift.yml already checks the registry against upstream MODULE SOURCE,
 * but that cannot tell you whether a module is actually installed and enabled
 * here. sbf_datacite exists upstream in Fragaria and is absent from a site
 * without a DataCite subscription. Only the site can answer that.
 *
 * How: for each name, POST a one-line template that uses it and read the
 * verdict. Twig resolves filter, function and test names at PARSE time, so an
 * unknown name is a SyntaxError -- which the twig field's constraint turns into
 * a 422. No data, no rendering, no Drupal access required.
 *
 *   filter    {{ 1|NAME }}
 *   function  {{ NAME() }}
 *   test      {{ 1 is NAME }}
 *
 * LIMIT: this VERIFIES names the registry already lists. It cannot DISCOVER a
 * name nobody wrote down -- for that you still need bin/dump-twig-names.php via
 * drush. Verification is the half that prevents false passes, which is the half
 * that fails silently.
 *
 * Usage:
 *   php tools/twig-live/bin/verify-registry-live.php [options]
 *
 * Options:
 *   --format=pretty|github Output style. Default pretty.
 *   --summary=<file>       Also write Markdown (for $GITHUB_STEP_SUMMARY).
 *   --registry=<file>      Override the registry file.
 *   --help
 *
 * Environment:
 *   ARCHIPELAGO_URL, ARCHIPELAGO_USER, ARCHIPELAGO_PASS
 *
 * Exit code 0 = every registered name exists on the site, 1 = at least one does
 * not, 2 = could not run the check.
 */

declare(strict_types=1);

use Tamu\ArchipelagoLive\Client;

require __DIR__ . '/../src/Client.php';

$options = [
    'format' => 'pretty',
    'summary' => null,
    'registry' => __DIR__ . '/../../twig-lint/registry/extensions.json',
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, extractDocblock(__FILE__));
        exit(0);
    }
    foreach (['format', 'summary', 'registry'] as $name) {
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

if (!is_file($options['registry'])) {
    fwrite(STDERR, "Registry not found: {$options['registry']}\n");
    exit(2);
}

$registry = json_decode((string) file_get_contents($options['registry']), true);
if (!is_array($registry) || !isset($registry['sources'])) {
    fwrite(STDERR, "Registry is not valid JSON or has no 'sources' key.\n");
    exit(2);
}

try {
    $client = Client::fromEnvironment();
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$created = new ArrayObject();
register_shutdown_function(static function () use ($created, $client): void {
    foreach ($created as $uuid) {
        $client->delete($uuid);
    }
});

$token = getenv('GITHUB_RUN_ID') ?: gmdate('Ymd-His');
$results = [];
$index = 0;

foreach ($registry['sources'] as $source) {
    $provider = (string) ($source['id'] ?? 'unknown');

    foreach (['filters' => 'filter', 'functions' => 'function', 'tests' => 'test'] as $key => $kind) {
        foreach ($source[$key] ?? [] as $name) {
            $name = (string) $name;
            $probeName = Client::PROBE_PREFIX . $token . '-reg' . $index++;
            $probe = $client->probe($probeName, snippetFor($kind, $name));

            if ($probe['status'] === 201) {
                if ($probe['uuid'] !== null) {
                    $client->delete($probe['uuid']);
                }
                $results[] = ['name' => $name, 'kind' => $kind, 'source' => $provider, 'exists' => true];
                continue;
            }

            if ($probe['status'] === 422) {
                $results[] = ['name' => $name, 'kind' => $kind, 'source' => $provider, 'exists' => false];
                continue;
            }

            fwrite(STDERR, $client->describeFailure("probe {$kind} '{$name}'", [
                'status' => $probe['status'],
                'body' => null,
                'raw' => $probe['raw'],
                'error' => $probe['error'],
            ]) . "\n");
            fwrite(STDERR, "\nInfrastructure failure -- nothing was concluded about the registry.\n");
            exit(2);
        }
    }
}

$options['format'] === 'github' ? reportGithub($results) : reportPretty($results);

if ($options['summary'] !== null) {
    file_put_contents($options['summary'], summaryMarkdown($results), FILE_APPEND);
}

exit(missing($results) === [] ? 0 : 1);

// ---------------------------------------------------------------------------

/**
 * The smallest template that forces Twig to resolve this name at parse time.
 */
function snippetFor(string $kind, string $name): string
{
    return match ($kind) {
        'filter' => '{{ 1|' . $name . ' }}',
        'function' => '{{ ' . $name . '() }}',
        'test' => '{{ 1 is ' . $name . ' }}',
        default => '{{ 1 }}',
    };
}

/**
 * @param list<array{name:string, kind:string, source:string, exists:bool}> $results
 *
 * @return list<array{name:string, kind:string, source:string, exists:bool}>
 */
function missing(array $results): array
{
    return array_values(array_filter($results, static fn (array $r): bool => !$r['exists']));
}

/**
 * @param list<array{name:string, kind:string, source:string, exists:bool}> $results
 */
function reportPretty(array $results): void
{
    $tty = stream_isatty(STDOUT);
    $red = $tty ? "\033[31m" : '';
    $green = $tty ? "\033[32m" : '';
    $bold = $tty ? "\033[1m" : '';
    $off = $tty ? "\033[0m" : '';

    $gone = missing($results);

    fwrite(STDOUT, sprintf(
        "%sRegistry vs live site%s  %s\n\n",
        $bold,
        $off,
        getenv('ARCHIPELAGO_URL') ?: '(unknown site)',
    ));

    foreach ($gone as $item) {
        fwrite(STDOUT, sprintf(
            "%sMISSING%s %s %s\n        registered under \"%s\" but this site does not have it\n\n",
            $red,
            $off,
            $item['kind'],
            $item['name'],
            $item['source'],
        ));
    }

    $total = count($results);
    fwrite(STDOUT, $gone === []
        ? sprintf("%sOK%s all %d registered names exist on this site.\n", $green, $off, $total)
        : sprintf(
            "%s%d of %d registered names do NOT exist on this site.%s\n"
            . "Any template using one lints clean offline and is rejected in production.\n",
            $red,
            count($gone),
            $total,
            $off,
        ));
}

/**
 * @param list<array{name:string, kind:string, source:string, exists:bool}> $results
 */
function reportGithub(array $results): void
{
    foreach (missing($results) as $item) {
        fwrite(STDOUT, sprintf(
            "::error file=tools/twig-lint/registry/extensions.json,title=Registered name missing from the live site::%s\n",
            escapeWorkflowData(sprintf(
                'The %s "%s" is registered under "%s" but does not exist on %s. Templates using it pass the offline linter and are rejected by the site.',
                $item['kind'],
                $item['name'],
                $item['source'],
                getenv('ARCHIPELAGO_URL') ?: 'the target site',
            )),
        ));
    }

    reportPretty($results);
}

function escapeWorkflowData(string $value): string
{
    return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
}

/**
 * @param list<array{name:string, kind:string, source:string, exists:bool}> $results
 */
function summaryMarkdown(array $results): string
{
    $site = getenv('ARCHIPELAGO_URL') ?: 'the target site';
    $gone = missing($results);
    $total = count($results);

    if ($gone === []) {
        return sprintf(
            "### Registry vs live site\n\nAll **%d** registered Twig names exist on `%s`.\n\n"
            . "<sub>Verifies names the registry lists; it cannot discover names nobody wrote down.</sub>\n",
            $total,
            $site,
        );
    }

    $rows = '';
    foreach ($gone as $item) {
        $rows .= sprintf("| `%s` | %s | %s |\n", $item['name'], $item['kind'], $item['source']);
    }

    return sprintf(
        "### Registry vs live site — %d of %d missing\n\n"
        . "These names are trusted by `tools/twig-lint` but do not exist on `%s`. A template using "
        . "one **passes the offline linter and is rejected by the site** — the silent false pass.\n\n"
        . "| Name | Kind | Registered under |\n|---|---|---|\n%s\n",
        count($gone),
        $total,
        $site,
        $rows,
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
