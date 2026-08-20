#!/usr/bin/env php
<?php

/**
 * Fetches the module sources listed in registry/sources.json and scans them for
 * declared Twig extension names, writing a dump that compare-registry.php reads.
 *
 * Usage:
 *   php bin/build-dump.php [--cache=<dir>] [--out=<file>] [--refresh]
 *
 * Options:
 *   --cache=<dir>  Where to keep the fetched sources. Default: a temp dir.
 *   --out=<file>   Where to write the dump. Default: stdout.
 *   --refresh      Re-fetch even if the cache already has the source.
 *
 * Requires `git` on PATH. Shallow single-branch clones only.
 *
 * NOTE: this reports what the UPSTREAM modules declare. It cannot see a
 * TAMU-local module. For the authoritative answer, run bin/dump-twig-names.php
 * against the deployed site instead -- see README, "Registry drift".
 */

declare(strict_types=1);

use Tamu\ArchipelagoTwigLint\NameScanner;

require __DIR__ . '/../vendor/autoload.php';

$options = ['cache' => null, 'out' => null, 'refresh' => false];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--refresh') {
        $options['refresh'] = true;
    } elseif (str_starts_with($arg, '--cache=')) {
        $options['cache'] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--out=')) {
        $options['out'] = substr($arg, 6);
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
}

$cache = $options['cache'] ?? sys_get_temp_dir() . '/archipelago-twig-sources';
if (!is_dir($cache) && !mkdir($cache, 0777, true) && !is_dir($cache)) {
    fwrite(STDERR, "Could not create cache dir: {$cache}\n");
    exit(2);
}

$manifest = json_decode(
    (string) file_get_contents(__DIR__ . '/../registry/sources.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$scanner = new NameScanner();

foreach ($manifest['sources'] as $id => $source) {
    $target = $cache . '/' . $id;

    if ($options['refresh'] && is_dir($target)) {
        removeTree($target);
    }

    if (isset($source['repo'])) {
        fetchRepo($id, $source['repo'], $source['ref'], $target);
    } elseif (isset($source['files'])) {
        fetchFiles($id, $source['files'], $target);
    } else {
        fwrite(STDERR, "Source '{$id}' has neither 'repo' nor 'files'.\n");
        exit(2);
    }

    $scanner->scanPath($target, $id);
}

$dump = $scanner->toArray('static-scan');
$json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($options['out'] !== null) {
    file_put_contents($options['out'], $json);
    fwrite(STDERR, sprintf(
        "Wrote %s: %d filters, %d functions, %d tests from %d files.\n",
        $options['out'],
        count($dump['filters']),
        count($dump['functions']),
        count($dump['tests']),
        $dump['scanned_files'],
    ));
} else {
    echo $json;
}

// ---------------------------------------------------------------------------

function fetchRepo(string $id, string $repo, string $ref, string $target): void
{
    if (is_dir($target)) {
        fwrite(STDERR, "  cached  {$id}\n");

        return;
    }

    fwrite(STDERR, "  clone   {$id} ({$ref})\n");

    $command = sprintf(
        'git clone --quiet --depth 1 --single-branch --branch %s %s %s 2>&1',
        escapeshellarg($ref),
        escapeshellarg($repo),
        escapeshellarg($target),
    );

    exec($command, $output, $status);

    if ($status !== 0) {
        fwrite(STDERR, "Failed to clone {$repo} at {$ref}:\n" . implode("\n", $output) . "\n");
        exit(2);
    }
}

/**
 * @param list<string> $urls
 */
function fetchFiles(string $id, array $urls, string $target): void
{
    if (is_dir($target)) {
        fwrite(STDERR, "  cached  {$id}\n");

        return;
    }

    if (!mkdir($target, 0777, true) && !is_dir($target)) {
        fwrite(STDERR, "Could not create {$target}\n");
        exit(2);
    }

    fwrite(STDERR, "  fetch   {$id} (" . count($urls) . " file" . (count($urls) === 1 ? '' : 's') . ")\n");

    foreach ($urls as $url) {
        $contents = @file_get_contents($url);
        if ($contents === false) {
            fwrite(STDERR, "Failed to fetch {$url}\n");
            exit(2);
        }
        file_put_contents($target . '/' . basename(parse_url($url, PHP_URL_PATH) ?: 'file.php'), $contents);
    }
}

function removeTree(string $path): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}
