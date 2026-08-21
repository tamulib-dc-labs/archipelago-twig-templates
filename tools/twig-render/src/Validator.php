<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigRender;

/**
 * One rendered string, checked against what its mimetype promises.
 */
final class Problem
{
    public function __construct(
        public readonly string $kind,
        public readonly string $message,
        public readonly ?string $pointer = null,
    ) {
    }
}

/**
 * Checks rendered output is well formed for the mimetype it claims.
 *
 * Deliberately stops short of IIIF. Documents flagged `iiif` in templates.json
 * are listed in the render index and validated by bin/validate_iiif.py using
 * iiif-prezi3 -- the library maintained alongside the specification, whose
 * errors name the offending field and whose findings are reportable upstream.
 * Duplicating that here in PHP would mean two definitions of "valid IIIF"
 * drifting apart.
 *
 * The mimetype is not a guess: every Metadata Display entity declares one, and
 * that is what Archipelago serves the document as. A display promising
 * application/ld+json that emits something json_decode rejects breaks every
 * consumer downstream, which is the failure this tier exists for.
 */
final class Validator
{
    /**
     * @return list<Problem>
     */
    public function validate(string $output, string $mimetype, bool $geojson = false): array
    {
        $problems = match (true) {
            str_contains($mimetype, 'json') => $this->validateJson($output),
            str_contains($mimetype, 'xml') => $this->validateXml($output),
            default => $this->validateNonEmpty($output),
        };

        if ($problems === [] && $geojson) {
            $problems = $this->validateGeoJson($output);
        }

        return $problems;
    }

    /**
     * Structural GeoJSON checks, per RFC 7946.
     *
     * Only the shapes a Twig-built document actually gets wrong. The specific
     * reason this exists: GeoJSON.twig.html emitted
     *
     *     {"type":"FeatureCollection","features":[null]}
     *
     * whenever the record had no geojson_feature. That is well formed JSON, so
     * the ordinary check passed it, and it is not GeoJSON at all -- a consumer
     * iterating features gets a null and breaks.
     *
     * @return list<Problem>
     */
    private function validateGeoJson(string $output): array
    {
        $document = json_decode(trim($output), true);
        if (!is_array($document)) {
            return [];
        }

        $type = $document['type'] ?? null;

        if ($type === 'FeatureCollection') {
            if (!array_key_exists('features', $document) || !is_array($document['features'])) {
                return [new Problem('invalid-geojson', 'A FeatureCollection must have a "features" array.', '/features')];
            }

            $problems = [];
            foreach ($document['features'] as $i => $feature) {
                if (!is_array($feature)) {
                    $problems[] = new Problem(
                        'invalid-geojson',
                        sprintf('features must be Feature objects, got %s', $feature === null ? 'null' : gettype($feature)),
                        "/features/{$i}",
                    );
                    continue;
                }
                if (($feature['type'] ?? null) !== 'Feature') {
                    $problems[] = new Problem(
                        'invalid-geojson',
                        sprintf('expected "type": "Feature", got %s', json_encode($feature['type'] ?? null)),
                        "/features/{$i}/type",
                    );
                }
            }

            return array_slice($problems, 0, 8);
        }

        if ($type === 'Feature' && !array_key_exists('geometry', $document)) {
            return [new Problem('invalid-geojson', 'A Feature must have a "geometry" member.', '/geometry')];
        }

        return [];
    }

    /**
     * @return list<Problem>
     */
    private function validateJson(string $output): array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return [new Problem('empty', 'Rendered to an empty document.')];
        }

        json_decode($trimmed);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [new Problem(
                'invalid-json',
                sprintf('%s (%s)', json_last_error_msg(), $this->locateJsonError($trimmed)),
            )];
        }

        return [];
    }

    /**
     * @return list<Problem>
     */
    private function validateXml(string $output): array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return [new Problem('empty', 'Rendered to an empty document.')];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new \DOMDocument();
        $document->loadXML($trimmed);
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $problems = [];
        foreach (array_slice($errors, 0, 8) as $error) {
            $problems[] = new Problem(
                'invalid-xml',
                sprintf('line %d: %s', $error->line, trim($error->message)),
            );
        }

        return $problems;
    }

    /**
     * @return list<Problem>
     */
    private function validateNonEmpty(string $output): array
    {
        return trim($output) === ''
            ? [new Problem('empty', 'Rendered to an empty document.')]
            : [];
    }

    /**
     * json_decode reports a reason but not a place. Give the reader something
     * to search for.
     */
    private function locateJsonError(string $json): string
    {
        // A trailing comma before a closing bracket is far and away the most
        // common way a Twig-built JSON document breaks, so name it directly.
        if (preg_match('/,\s*[\]}]/', $json, $m, PREG_OFFSET_CAPTURE) === 1) {
            $line = substr_count(substr($json, 0, $m[0][1]), "\n") + 1;

            return sprintf('trailing comma near line %d', $line);
        }

        return sprintf('%d bytes rendered', strlen($json));
    }
}
