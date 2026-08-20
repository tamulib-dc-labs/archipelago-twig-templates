<?php

/**
 * Dumps every Twig name a REAL Archipelago actually has. Authoritative.
 *
 * bin/build-dump.php can only see the upstream modules we thought to list. This
 * sees the live Twig service, so it also catches TAMU-local modules and
 * anything else installed on the site -- which is the only way to settle names
 * like allmaps_annotation_url.
 *
 * Run it against the deployed site (staging is fine -- it is read-only and
 * touches no content):
 *
 *   docker cp tools/twig-lint/bin/dump-twig-names.php esmero-php:/tmp/
 *   docker exec esmero-php drush php:script /tmp/dump-twig-names.php > dump.json
 *
 * Then check the registry against it:
 *
 *   php tools/twig-lint/bin/compare-registry.php dump.json
 *
 * Drush prints the script's output on stdout, so redirecting gives clean JSON.
 */

declare(strict_types=1);

$twig = \Drupal::service('twig');

$names = static function (array $items): array {
    $list = array_keys($items);
    sort($list);

    return $list;
};

$modules = array_keys(\Drupal::service('module_handler')->getModuleList());
sort($modules);

$declared = [];
foreach (\Drupal::service('twig')->getExtensions() as $class => $extension) {
    foreach (['getFilters', 'getFunctions', 'getTests'] as $method) {
        if (!method_exists($extension, $method)) {
            continue;
        }
        foreach ($extension->{$method}() as $item) {
            if (method_exists($item, 'getName')) {
                $declared[$item->getName()][] = $class;
            }
        }
    }
}

foreach ($declared as $name => $classes) {
    $declared[$name] = array_values(array_unique($classes));
}
ksort($declared);

echo json_encode([
    'source' => 'live-site',
    'drupal_version' => \Drupal::VERSION,
    'php_version' => PHP_VERSION,
    'scanned_files' => count($twig->getExtensions()),
    'generated' => gmdate('c'),
    'filters' => $names($twig->getFilters()),
    'functions' => $names($twig->getFunctions()),
    'tests' => $names($twig->getTests()),
    'declared_in' => $declared,
    'enabled_modules' => $modules,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
