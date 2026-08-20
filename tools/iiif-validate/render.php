<?php
/**
 * Renders an Archipelago IIIF Twig template against a raw Strawberry Field
 * JSON fixture, outside of Drupal.
 *
 * This does NOT boot Drupal. It stubs the handful of Drupal-provided Twig
 * functions/filters/globals the metadata display templates rely on
 * (url(), |render, |markdown_2_html, node, language, iiif_server) with
 * fixed, fake values, so the templates can be exercised for structural/logic
 * regressions. It cannot catch bugs that only show up with real routing,
 * real language negotiation, or real entity loading.
 *
 * Usage: php render.php <fixture.json> <template.twig.html> <output.json>
 */

require __DIR__ . '/vendor/autoload.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php render.php <fixture.json> <template.twig.html> <output.json>\n");
    exit(1);
}

[, $fixturePath, $templatePath, $outputPath] = $argv;

$json = file_get_contents($fixturePath);
if ($json === false) {
    fwrite(STDERR, "Could not read fixture: {$fixturePath}\n");
    exit(1);
}

$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$templateDir = dirname($templatePath);
$templateFile = basename($templatePath);

$loader = new FilesystemLoader($templateDir);
$twig = new Environment($loader, [
    'strict_variables' => false,
    'autoescape' => false,
]);

const MOCK_BASE_URL = 'https://example.org/';
const MOCK_IIIF_SERVER = 'https://iiif.example.org';

// Drupal's url() route-generation function. Real Drupal resolves route
// names to aliased paths; here we fake a stable, predictable URL instead.
$twig->addFunction(new TwigFunction('url', function (string $route, array $params = [], array $options = []) {
    if ($route === '<front>') {
        return MOCK_BASE_URL;
    }
    if ($route === '<current>') {
        return MOCK_BASE_URL . 'node/mock-current';
    }
    if ($route === 'entity.node.canonical' && isset($params['node'])) {
        return MOCK_BASE_URL . 'node/' . $params['node'];
    }
    return MOCK_BASE_URL . ltrim($route, '/');
}));

// Drupal's |render filter renders a render array to markup. url() already
// returns plain strings here, so this is a no-op passthrough.
$twig->addFilter(new TwigFilter('render', static fn($value) => $value));

// Archipelago's |markdown_2_html filter. This is a structural stand-in, not
// a real Markdown parser -- good enough to keep the output valid JSON.
$twig->addFilter(new TwigFilter('markdown_2_html', static fn($value) => is_string($value) ? '<p>' . $value . '</p>' : $value));

// Drupal's |preg_replace filter: preg_replace(pattern, replacement, subject).
$twig->addFilter(new TwigFilter('preg_replace', static fn($subject, $pattern, $replacement = '') => preg_replace($pattern, $replacement, (string) $subject)));

// Mock Drupal Node entity: only the properties this template touches
// (node.id, node.label, node.uuid.value).
$node = new class {
    public int $id = 999;
    public string $label = 'Mock Node Label';
    public object $uuid;

    public function __construct()
    {
        $this->uuid = (object) ['value' => 'mock-node-uuid-0000-0000-000000000000'];
    }
};

// Mock Drupal LanguageInterface: only getId() is used.
$language = new class {
    public function getId(): string
    {
        return 'en';
    }
};

try {
    $output = $twig->render($templateFile, [
        'node' => $node,
        'data' => $data,
        'iiif_server' => MOCK_IIIF_SERVER,
        'language' => $language,
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, "Twig render failed for {$fixturePath} against {$templatePath}:\n" . $e->getMessage() . "\n");
    exit(1);
}

file_put_contents($outputPath, $output);
fwrite(STDOUT, "Rendered {$fixturePath} -> {$outputPath}\n");
