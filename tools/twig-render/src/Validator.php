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
     * @param bool $fragment the template emits an embeddable snippet rather
     *                       than a whole document
     *
     * @return list<Problem>
     */
    public function validate(string $output, string $mimetype, bool $fragment = false): array
    {
        if ($fragment && str_contains($mimetype, 'json')) {
            return $this->validateJsonFragment($output);
        }

        return match (true) {
            str_contains($mimetype, 'json') => $this->validateJson($output),
            default => $this->validateNonEmpty($output),
        };
    }

    /**
     * Check a snippet that is only valid inside something else.
     *
     * The thumbnail templates emit a bare member:
     *
     *     "thumbnail": [{ "id": "...", "type": "Image" }]
     *
     * which is not a JSON document, so parsing it directly always fails and
     * tells you nothing. Wrapping it in braces makes it one, and then the
     * checks that matter still apply: a trailing comma, an unbalanced bracket
     * or an unescaped quote in here corrupts every manifest that embeds it.
     *
     * @return list<Problem>
     */
    private function validateJsonFragment(string $output): array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return [new Problem('empty', 'Rendered to an empty fragment.')];
        }

        // A snippet ending in a comma is legal mid-object, so allow it before
        // closing the wrapper rather than reporting a false trailing comma.
        $wrapped = '{' . rtrim($trimmed, ", \t\n\r") . '}';

        json_decode($wrapped);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [new Problem(
                'invalid-json-fragment',
                sprintf(
                    '%s — the snippet does not parse when embedded (%s)',
                    json_last_error_msg(),
                    $this->locateJsonError($trimmed),
                ),
            )];
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
