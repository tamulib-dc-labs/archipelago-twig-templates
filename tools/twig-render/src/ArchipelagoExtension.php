<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigRender;

use League\CommonMark\CommonMarkConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Real implementations of the non-core Twig names these templates use.
 *
 * This is the critical difference from tools/twig-lint's StubExtension. That
 * one registers every name as a no-op, which is correct for PARSING -- Twig
 * only needs the name to exist. Here we EXECUTE, so a no-op would silently
 * produce empty output that then "validates" as meaningless, and the whole
 * exercise would report green over nothing.
 *
 * So this class has exactly two kinds of member:
 *
 *   - implemented   a faithful-enough version of what Drupal/Archipelago does
 *   - unsupported   registered, but THROWS when called
 *
 * Nothing silently returns null. If a template reaches for something that
 * cannot work outside Drupal (drupal_view, sbf_search_api -- anything wanting
 * a database, Solr or the file system) the run fails loudly and names the
 * filter, rather than emitting output nobody should trust.
 */
final class ArchipelagoExtension extends AbstractExtension
{
    /**
     * Names that genuinely cannot work without a running Drupal.
     *
     * A template using one of these is out of scope for offline rendering and
     * must be excluded in templates.json with a stated reason.
     *
     * @var list<string>
     */
    public const REQUIRES_DRUPAL = [
        'drupal_view',
        'sbf_drupal_view_paged',
        'sbf_search_api',
        'sbf_entity_ids_by_label',
        'sbf_render',
        'sbf_file_content',
        'bamboo_load_entity',
        'sbf_datacite',
        'allmaps_annotation_url',
        'drupal_escape',
        'attach_library',
        'active_theme',
        'active_theme_path',
        'clipboard_copy',
        'bibliography',
        'html_2_markdown',
    ];

    private ?CommonMarkConverter $markdown = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $iiifServer,
    ) {
    }

    public function getFilters(): array
    {
        $filters = [
            // Drupal's render filter turns a render array / Url object into a
            // string. Everything reaching it here is already a scalar.
            new TwigFilter('render', $this->render(...), ['is_safe' => ['all']]),

            // twig_tweak. Argument order matches: value|preg_replace(pattern, replacement)
            new TwigFilter('preg_replace', $this->pregReplace(...)),

            new TwigFilter('markdown_2_html', $this->markdownToHtml(...), ['is_safe' => ['all']]),
            new TwigFilter('edtf_2_iso_date', $this->edtfToIso(...)),
            new TwigFilter('edtf_2_human_date', $this->edtfToHuman(...)),

            new TwigFilter('sbf_json_decode', $this->jsonDecode(...)),
            new TwigFilter('format_strawberry_safe_escape', $this->safeEscape(...), ['is_safe' => ['all']]),

            // Drupal core filters that are safe to approximate.
            new TwigFilter('t', $this->passthroughString(...)),
            new TwigFilter('trans', $this->passthroughString(...)),
            new TwigFilter('clean_class', $this->cleanClass(...)),
            new TwigFilter('clean_id', $this->cleanClass(...)),
            new TwigFilter('format_date', $this->formatDate(...)),
            new TwigFilter('safe_join', $this->safeJoin(...), ['is_safe' => ['all'], 'needs_environment' => true]),
            new TwigFilter('without', $this->without(...)),
            new TwigFilter('placeholder', $this->passthroughString(...)),
            new TwigFilter('add_suggestion', $this->passthroughString(...)),
        ];

        foreach (self::REQUIRES_DRUPAL as $name) {
            $filters[] = new TwigFilter($name, fn (mixed ...$a): mixed => $this->refuse($name));
        }

        return $filters;
    }

    public function getFunctions(): array
    {
        $functions = [
            new TwigFunction('url', $this->url(...)),
            new TwigFunction('path', $this->url(...)),
            new TwigFunction('file_url', $this->fileUrl(...)),
            new TwigFunction('link', $this->link(...), ['is_safe' => ['all']]),
            new TwigFunction('render_var', $this->render(...), ['is_safe' => ['all']]),
            new TwigFunction('create_attribute', fn (array $a = []): array => $a),
        ];

        foreach (self::REQUIRES_DRUPAL as $name) {
            $functions[] = new TwigFunction($name, fn (mixed ...$a): mixed => $this->refuse($name));
        }

        return $functions;
    }

    public function getTests(): array
    {
        return [
            new \Twig\TwigTest('instanceof', static fn (mixed $v, string $c): bool => $v instanceof $c),
        ];
    }

    /**
     * @return never
     */
    private function refuse(string $name): mixed
    {
        throw new UnsupportedByRendererException(sprintf(
            '"%s" needs a running Drupal (database, Solr or file system) and cannot be rendered offline. '
            . 'Exclude this template in templates.json with a reason, or move it to a live-site check.',
            $name,
        ));
    }

    // -- implemented ---------------------------------------------------------

    private function render(mixed $value): string
    {
        if (is_array($value)) {
            // A render array reached us. '#markup' is the only shape that has
            // a meaningful string form without Drupal's renderer.
            return (string) ($value['#markup'] ?? '');
        }

        return $value === null ? '' : (string) $value;
    }

    private function pregReplace(mixed $value, string $pattern, string $replacement): string
    {
        return (string) preg_replace($pattern, $replacement, (string) $value);
    }

    private function markdownToHtml(mixed $value): string
    {
        $this->markdown ??= new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return trim((string) $this->markdown->convert((string) $value));
    }

    /**
     * EDTF to ISO 8601, covering the level 0/1 forms these records use.
     *
     * Not a complete EDTF implementation. Qualifiers (~ ? %) are dropped,
     * intervals yield their start, and unspecified digits (X) are floored.
     * Enough to exercise the templates; not a substitute for the real filter.
     */
    private function edtfToIso(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        // Interval -- take the start.
        if (str_contains($raw, '/')) {
            $raw = explode('/', $raw)[0];
        }

        // Sets and lists -- take the first member.
        $raw = trim($raw, '[]{}');
        if (str_contains($raw, ',')) {
            $raw = explode(',', $raw)[0];
        }

        // Drop approximate/uncertain qualifiers.
        $raw = str_replace(['~', '?', '%'], '', trim($raw));

        // Floor unspecified digits: 19XX -> 1900, 1990-XX -> 1990-01.
        $raw = preg_replace('/X/', '0', $raw) ?? $raw;

        if (preg_match('/^-?\d{4}$/', $raw) === 1) {
            return $raw;
        }
        if (preg_match('/^-?\d{4}-(\d{2})$/', $raw, $m) === 1) {
            return $m[1] === '00' ? substr($raw, 0, -3) . '-01' : $raw;
        }
        if (preg_match('/^-?\d{4}-\d{2}-\d{2}/', $raw) === 1) {
            return substr($raw, 0, 10);
        }

        return $raw;
    }

    private function edtfToHuman(mixed $value): string
    {
        $iso = $this->edtfToIso($value);
        if (preg_match('/^(-?\d{4})-(\d{2})-(\d{2})$/', $iso, $m) === 1) {
            return date('F j, Y', (int) mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]));
        }

        return $iso;
    }

    private function jsonDecode(mixed $value): mixed
    {
        return json_decode((string) $value, true);
    }

    private function safeEscape(mixed $value): string
    {
        return (string) $value;
    }

    private function passthroughString(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function cleanClass(mixed $value): string
    {
        $out = strtolower((string) $value);
        $out = (string) preg_replace('/[^a-z0-9_-]+/', '-', $out);

        return trim($out, '-');
    }

    private function formatDate(mixed $value, string $type = 'medium'): string
    {
        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if ($timestamp === false) {
            return '';
        }

        return date($type === 'html_date' ? 'Y-m-d' : DATE_ATOM, $timestamp);
    }

    /**
     * @param iterable<mixed> $value
     */
    private function safeJoin(\Twig\Environment $env, mixed $value, string $glue = ''): string
    {
        if (!is_iterable($value)) {
            return (string) $value;
        }

        $parts = [];
        foreach ($value as $item) {
            $parts[] = (string) $item;
        }

        return implode($glue, $parts);
    }

    /**
     * @param array<mixed> $value
     * @param list<string>|string $keys
     *
     * @return array<mixed>
     */
    private function without(mixed $value, mixed ...$keys): array
    {
        if (!is_array($value)) {
            return [];
        }

        $drop = [];
        foreach ($keys as $key) {
            foreach ((array) $key as $one) {
                $drop[] = (string) $one;
            }
        }

        return array_diff_key($value, array_flip($drop));
    }

    /**
     * Drupal's url()/path(). Templates call url('<front>') to get the site root.
     *
     * @param array<mixed> $parameters
     */
    private function url(string $name = '<front>', array $parameters = [], array $options = []): string
    {
        if ($name === '<front>' || $name === '<none>') {
            return rtrim($this->baseUrl, '/') . '/';
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($name, '/');
    }

    private function fileUrl(string $uri): string
    {
        $path = preg_replace('#^(public|private|s3)://#', '', $uri) ?? $uri;

        return rtrim($this->baseUrl, '/') . '/sites/default/files/' . ltrim($path, '/');
    }

    private function link(string $text, string $url = '', mixed $attributes = null): string
    {
        return sprintf('<a href="%s">%s</a>', htmlspecialchars($url), htmlspecialchars($text));
    }

    public function iiifServer(): string
    {
        return $this->iiifServer;
    }
}
