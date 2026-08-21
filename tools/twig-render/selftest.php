#!/usr/bin/env php
<?php

/**
 * Prove the harness still detects the bug classes it exists to detect.
 *
 * Guards two ways this could quietly stop working:
 *
 *  1. Someone loosens the validator, or the vendored schema is replaced with
 *     one that accepts anything, and every template reports green forever.
 *  2. An unimplemented filter starts returning null instead of throwing, so
 *     templates needing Drupal render to empty output that "validates".
 *
 * Both failures look exactly like success in CI, which is why this runs first.
 *
 * Exit 0 = the harness behaves, 1 = it does not.
 */

declare(strict_types=1);

use Tamu\ArchipelagoTwigRender\Context;
use Tamu\ArchipelagoTwigRender\Renderer;
use Tamu\ArchipelagoTwigRender\UnsupportedByRendererException;
use Tamu\ArchipelagoTwigRender\Validator;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies missing. Run: composer install -d tools/twig-render\n");
    exit(2);
}
require $autoload;

$renderer = new Renderer(new Context());
$validator = new Validator();

// IIIF conformance is bin/validate_iiif.py's job now, guarded by its own
// --selftest. What is asserted here is everything the PHP half owns: that
// broken JSON is caught, that a template needing Drupal refuses to render
// rather than emitting something, and that ground truth exists at all.
/** @var array<string, array{expect:string}> $expectations */
$expectations = [
    'bad_trailing_comma' => ['expect' => 'invalid'],
    'bad_needs_drupal' => ['expect' => 'throws'],
    'good_minimal_manifest' => ['expect' => 'valid'],
];

$fixture = ['label' => 'Selftest Object', 'dr:uuid' => '11111111-2222-4333-8444-555555555555'];
$failures = 0;

foreach ($expectations as $name => $spec) {
    $path = __DIR__ . '/tests/templates/' . $name . '.twig.html';
    if (!is_file($path)) {
        printf("  FAIL %-26s fixture missing\n", $name);
        $failures++;
        continue;
    }

    $source = (string) file_get_contents($path);

    try {
        $output = $renderer->render($source, $fixture, 'selftest');
        $problems = $validator->validate($output, 'application/ld+json');
        $actual = $problems === [] ? 'valid' : 'invalid';
        $detail = $problems === [] ? '' : $problems[0]->message;
    } catch (UnsupportedByRendererException $e) {
        $actual = 'throws';
        $detail = 'refused, as it should';
    } catch (\Throwable $e) {
        $actual = 'error';
        $detail = get_class($e) . ': ' . $e->getMessage();
    }

    if ($actual === $spec['expect']) {
        printf("  ok   %-26s %s as expected%s\n", $name, $actual, $detail !== '' && $actual !== 'valid' ? " ({$detail})" : '');
        continue;
    }

    printf("  FAIL %-26s expected %s, got %s. %s\n", $name, $spec['expect'], $actual, $detail);
    $failures++;
}

// The whole tier is worthless without ground truth, so make its absence loud
// rather than letting "0 documents checked" pass as success.
$fixtureCount = count(glob(__DIR__ . '/fixtures/*.json') ?: []);
if ($fixtureCount === 0) {
    printf("  FAIL %-26s no fixtures committed -- nothing would be checked\n", 'fixtures');
    $failures++;
} else {
    printf("  ok   %-26s %d committed\n", 'fixtures', $fixtureCount);
}

printf("\n%d check%s, %d failure%s\n", count($expectations) + 1, count($expectations) === 0 ? '' : 's', $failures, $failures === 1 ? '' : 's');

exit($failures === 0 ? 0 : 1);
