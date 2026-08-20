#!/usr/bin/env php
<?php

/**
 * Ask the deployed Archipelago whether it would accept these templates.
 *
 * This is the authoritative check. The offline linter in tools/twig-lint
 * reproduces MetadataDisplayEntity::validateSource() from a registry of
 * extension names we maintain by hand; this asks the real site, which knows
 * its own filters, its own Twig version and its own site-local modules.
 *
 * How it works: POST the template to JSON:API as a temporary Metadata Display
 * entity. The twig base field carries a TwigTemplateConstraint, so entity
 * validation runs on save. 201 means accepted, 422 means rejected. The
 * temporary entity is deleted immediately either way.
 *
 * WHAT IT COSTS: one create and one delete on the target site per template.
 * Every probe is named with Client::PROBE_PREFIX and nothing else is ever
 * touched. Use --sweep to clear probes orphaned by a killed run.
 *
 * Usage:
 *   php tools/twig-live/bin/validate-live.php [options] [path ...]
 *
 * Options:
 *   --changed=<ref>        Only templates changed against <ref> (e.g. origin/main).
 *   --format=pretty|github Output style. Default pretty.
 *   --summary=<file>       Also write Markdown (for $GITHUB_STEP_SUMMARY).
 *   --sweep                Delete orphaned probe entities, then exit.
 *   --sweep-age=<minutes>  Age threshold for --sweep. Default 60.
 *   --no-cross-check       Skip comparing against the offline linter.
 *   --help
 *
 * Environment:
 *   ARCHIPELAGO_URL, ARCHIPELAGO_USER, ARCHIPELAGO_PASS
 *
 * Exit code 0 = every template accepted, 1 = at least one rejected,
 * 2 = could not run the check at all (bad usage, unreachable site, auth).
 * A 2 is never a statement about your templates.
 */

declare(strict_types=1);

use Tamu\ArchipelagoLive\Client;

require __DIR__ . '/../src/Client.php';

const STATUS_ACCEPTED = 'accepted';
const STATUS_REJECTED = 'rejected';

$options = [
    'format' => 'pretty',
    'summary' => null,
    'changed' => null,
    'sweep' => false,
    'sweep-age' => 60,
    'cross-check' => true,
];
$paths = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, extractDocblock(__FILE__));
        exit(0);
    }
    if ($arg === '--sweep') {
        $options['sweep'] = true;
        continue;
    }
    if ($arg === '--no-cross-check') {
        $options['cross-check'] = false;
        continue;
    }
    foreach (['format', 'summary', 'changed', 'sweep-age'] as $name) {
        if (str_starts_with($arg, "--{$name}=")) {
            $options[$name] = substr($arg, strlen($name) + 3);
            continue 2;
        }
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

$repoRoot = realpath(__DIR__ . '/../../..');
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(2);
}

try {
    $client = Client::fromEnvironment();
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

// Probes created by this process. Deleted by the shutdown handler even if we
// die unexpectedly, so a crash cannot leave junk on a shared site.
$created = new ArrayObject();
register_shutdown_function(static function () use ($created, $client): void {
    foreach ($created as $uuid) {
        $client->delete($uuid);
    }
});

if ($options['sweep']) {
    exit(sweep($client, (int) $options['sweep-age']));
}

try {
    $files = resolveFiles($paths, $options['changed'], $repoRoot);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

if ($files === []) {
    fwrite(STDOUT, "No .twig.html files to check.\n");
    exit(0);
}

$results = [];
$token = probeToken();

foreach ($files as $absolute => $relative) {
    $contents = (string) file_get_contents($absolute);
    // Match the offline linter: a BOM that survived a copy/paste round trip is
    // not present server-side, so it must not change the verdict.
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

    $name = Client::PROBE_PREFIX . $token . '-' . substr(sha1($relative), 0, 8);
    $probe = $client->probe($name, $contents);

    if ($probe['status'] === 201) {
        if ($probe['uuid'] !== null) {
            $created[] = $probe['uuid'];
            $client->delete($probe['uuid']);
            unsetValue($created, $probe['uuid']);
        }
        $results[] = ['file' => $relative, 'status' => STATUS_ACCEPTED, 'detail' => null, 'offline' => null];
        continue;
    }

    if ($probe['status'] === 422) {
        $results[] = [
            'file' => $relative,
            'status' => STATUS_REJECTED,
            'detail' => $probe['detail'] ?? 'Rejected without a stated reason.',
            'offline' => $options['cross-check'] ? offlineVerdict($repoRoot, $absolute) : null,
        ];
        continue;
    }

    // Anything else -- 401, 403, 500, a timeout, DNS failure -- says nothing
    // about the template. Fail loudly as infrastructure, never as content.
    fwrite(STDERR, $client->describeFailure("validate {$relative}", [
        'status' => $probe['status'],
        'body' => null,
        'raw' => $probe['raw'],
        'error' => $probe['error'],
    ]) . "\n");
    fwrite(STDERR, "\nThis is an infrastructure failure, not a template failure. Nothing was concluded\n");
    fwrite(STDERR, "about your templates. Check network reachability, credentials and site health.\n");
    exit(2);
}

$options['format'] === 'github' ? reportGithub($results) : reportPretty($results);

if ($options['summary'] !== null) {
    file_put_contents($options['summary'], summaryMarkdown($results), FILE_APPEND);
}

exit(hasRejection($results) ? 1 : 0);

// ---------------------------------------------------------------------------

/**
 * A token unique to this run, so concurrent PR builds cannot collide and a
 * leftover probe can be traced back to the run that made it.
 */
function probeToken(): string
{
    $runId = getenv('GITHUB_RUN_ID');
    if (is_string($runId) && $runId !== '') {
        return $runId . '-' . (getenv('GITHUB_RUN_ATTEMPT') ?: '1');
    }

    return gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
}

/**
 * @param list<string> $paths
 *
 * @return array<string, string> absolute path => repo-relative path
 */
function resolveFiles(array $paths, ?string $changedAgainst, string $repoRoot): array
{
    if ($paths !== []) {
        return normalise($paths, $repoRoot);
    }

    if ($changedAgainst !== null) {
        return normalise(changedFiles($changedAgainst, $repoRoot), $repoRoot);
    }

    return discover($repoRoot);
}

/**
 * @return list<string>
 */
function changedFiles(string $ref, string $repoRoot): array
{
    // Three-dot against HEAD, not two-dot against the working tree. Two-dot
    // would also report files the BASE branch changed since this branch
    // diverged, and would pick up working-tree noise (a Windows checkout read
    // from a Linux container reports every file as modified on CRLF alone).
    // Three-dot diffs from the merge base -- the same set GitHub shows under
    // "Files changed".
    $command = sprintf(
        'git -C %s diff --name-only --diff-filter=ACMR %s...HEAD -- twig',
        escapeshellarg($repoRoot),
        escapeshellarg($ref),
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Could not diff against '{$ref}'. Make sure the ref is fetched (actions/checkout needs fetch-depth: 0)."
        );
    }

    $files = [];
    foreach ($output as $line) {
        $line = trim($line);
        if ($line !== '' && str_ends_with($line, '.twig.html')) {
            $files[] = $repoRoot . '/' . $line;
        }
    }

    return $files;
}

/**
 * @return array<string, string>
 */
function discover(string $repoRoot): array
{
    $directory = $repoRoot . '/twig';
    if (!is_dir($directory)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    $found = [];
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && str_ends_with($file->getFilename(), '.twig.html')) {
            $found[$file->getPathname()] = relative($file->getPathname(), $repoRoot);
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
 * What does the offline linter think of this file?
 *
 * The interesting case is disagreement. If the offline linter passes a file
 * the live site rejects, tools/twig-lint/registry/extensions.json trusts a
 * name the site does not actually have -- the registry's one SILENT failure
 * mode, caught here automatically.
 *
 * Returns 'pass', 'fail', or null when the linter could not be consulted.
 */
function offlineVerdict(string $repoRoot, string $absolute): ?string
{
    $linter = $repoRoot . '/tools/twig-lint/lint.php';
    if (!is_file($linter) || !is_file($repoRoot . '/tools/twig-lint/vendor/autoload.php')) {
        return null;
    }

    $command = sprintf(
        '%s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($linter),
        escapeshellarg($absolute),
    );

    exec($command . ' 2>&1', $ignored, $exitCode);

    return match ($exitCode) {
        0 => 'pass',
        1 => 'fail',
        default => null,
    };
}

/**
 * Delete probe entities left behind by a run that was killed mid-flight.
 */
function sweep(Client $client, int $ageMinutes): int
{
    try {
        $entities = $client->metadataDisplays();
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 2;
    }

    $cutoff = time() - ($ageMinutes * 60);
    $deleted = 0;

    foreach ($entities as $entity) {
        if (!str_starts_with($entity['name'], Client::PROBE_PREFIX)) {
            continue;
        }

        // Age guard so a sweep never deletes a probe a concurrent run is using.
        $changed = strtotime($entity['changed']);
        if ($changed !== false && $changed > $cutoff) {
            fwrite(STDOUT, sprintf("  skip (too recent) %s\n", $entity['name']));
            continue;
        }

        $status = $client->delete($entity['uuid']);
        fwrite(STDOUT, sprintf("  %s %s\n", $status === 204 ? 'deleted' : "FAILED ({$status})", $entity['name']));
        $deleted++;
    }

    fwrite(STDOUT, $deleted === 0
        ? "No orphaned probe entities found.\n"
        : sprintf("Swept %d orphaned probe entit%s.\n", $deleted, $deleted === 1 ? 'y' : 'ies'));

    return 0;
}

/**
 * @param list<array{file:string, status:string, detail:?string, offline:?string}> $results
 */
function hasRejection(array $results): bool
{
    foreach ($results as $result) {
        if ($result['status'] === STATUS_REJECTED) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{file:string, status:string, detail:?string, offline:?string}> $results
 */
function reportPretty(array $results): void
{
    $tty = stream_isatty(STDOUT);
    $red = $tty ? "\033[31m" : '';
    $green = $tty ? "\033[32m" : '';
    $yellow = $tty ? "\033[33m" : '';
    $bold = $tty ? "\033[1m" : '';
    $off = $tty ? "\033[0m" : '';

    $site = getenv('ARCHIPELAGO_URL') ?: '(unknown site)';
    fwrite(STDOUT, sprintf("%sArchipelago live validation%s  %s\n\n", $bold, $off, $site));

    $rejected = 0;
    foreach ($results as $result) {
        if ($result['status'] === STATUS_ACCEPTED) {
            continue;
        }

        $rejected++;
        fwrite(STDOUT, sprintf("%sREJECTED%s %s\n         %s\n", $red, $off, $result['file'], $result['detail']));

        if ($result['offline'] === 'pass') {
            fwrite(STDOUT, sprintf(
                "%s         The offline linter ACCEPTS this file. The registry trusts a name this\n"
                . "         site does not have -- see tools/twig-lint/registry/extensions.json.%s\n",
                $yellow,
                $off,
            ));
        } elseif ($result['offline'] === 'fail') {
            fwrite(STDOUT, "         The offline linter agrees. Run it for the line number:\n");
            fwrite(STDOUT, sprintf("           php tools/twig-lint/lint.php %s\n", $result['file']));
        }

        fwrite(STDOUT, "\n");
    }

    $total = count($results);
    fwrite(STDOUT, $rejected === 0
        ? sprintf("%sOK%s %d template%s accepted by the live site.\n", $green, $off, $total, $total === 1 ? '' : 's')
        : sprintf("%s%d of %d template%s rejected by the live site.%s\n", $red, $rejected, $total, $total === 1 ? '' : 's', $off));
}

/**
 * @param list<array{file:string, status:string, detail:?string, offline:?string}> $results
 */
function reportGithub(array $results): void
{
    foreach ($results as $result) {
        if ($result['status'] !== STATUS_REJECTED) {
            continue;
        }

        // No line number is available: the constraint reports a verdict, not a
        // position. Annotate line 1 and point at the offline linter.
        $message = $result['detail'] ?? 'Rejected.';
        if ($result['offline'] === 'pass') {
            $message .= ' The offline linter accepts this file, so the extension registry trusts a name this site does not have.';
        } elseif ($result['offline'] === 'fail') {
            $message .= ' Run tools/twig-lint/lint.php on this file for the line number.';
        }

        fwrite(STDOUT, sprintf(
            "::error file=%s,line=1,title=Live Archipelago rejected this template::%s\n",
            $result['file'],
            escapeWorkflowData($message),
        ));
    }

    reportPretty($results);
}

function escapeWorkflowData(string $value): string
{
    return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
}

/**
 * @param list<array{file:string, status:string, detail:?string, offline:?string}> $results
 */
function summaryMarkdown(array $results): string
{
    $site = getenv('ARCHIPELAGO_URL') ?: 'the configured site';
    $total = count($results);
    $rejected = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === STATUS_REJECTED));

    if ($rejected === []) {
        return sprintf(
            "### Archipelago live validation\n\nAll **%d** template%s accepted by `%s`.\n\n"
            . "<sub>Each template was saved as a temporary Metadata Display entity and deleted again. "
            . "This is the same validation the entity edit form runs.</sub>\n",
            $total,
            $total === 1 ? ' was' : 's were',
            $site,

        );
    }

    $rows = '';
    foreach ($rejected as $result) {
        $note = match ($result['offline']) {
            'pass' => 'Offline linter **accepts** it — registry trusts a name this site lacks',
            'fail' => 'Offline linter agrees — run it for the line number',
            default => '—',
        };
        $rows .= sprintf(
            "| `%s` | %s | %s |\n",
            $result['file'],
            str_replace('|', '\\|', (string) $result['detail']),
            $note,
        );
    }

    return sprintf(
        "### Archipelago live validation — %d rejected\n\n"
        . "`%s` refused to save %d of %d template%s. A template the site will not accept renders "
        . "the error to logged-in users and **empty output to anonymous visitors**.\n\n"
        . "| Template | Verdict | Cross-check |\n|---|---|---|\n%s\n"
        . "<sub>The constraint reports a verdict, not a position — use `tools/twig-lint/lint.php` for line numbers.</sub>\n",
        count($rejected),
        $site,
        count($rejected),
        $total,
        $total === 1 ? '' : 's',
        $rows,
    );
}

/**
 * Drop a value from the pending-cleanup list once it is already deleted.
 */
function unsetValue(ArrayObject $list, string $value): void
{
    foreach ($list as $key => $item) {
        if ($item === $value) {
            unset($list[$key]);

            return;
        }
    }
}

function extractDocblock(string $file): string
{
    $contents = (string) file_get_contents($file);
    if (preg_match('#/\*\*(.*?)\*/#s', $contents, $matches) !== 1) {
        return "No help available.\n";
    }

    return trim((string) preg_replace('/^\s*\* ?/m', '', $matches[1])) . "\n";
}
