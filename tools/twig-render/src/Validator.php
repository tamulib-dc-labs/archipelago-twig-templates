<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigRender;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as JsonSchemaValidator;

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
 * Validates rendered output according to the Metadata Display's mimetype.
 *
 * The mimetype is not a guess: every Metadata Display entity declares one, and
 * that is what Archipelago serves the document as. If a display says
 * application/ld+json and emits something json_decode rejects, every consumer
 * downstream breaks -- which is the failure this whole tier exists for.
 */
final class Validator
{
    private ?object $iiifSchema = null;

    public function __construct(private readonly string $schemaPath)
    {
    }

    /**
     * @return list<Problem>
     */
    public function validate(string $output, string $mimetype, bool $iiif): array
    {
        return match (true) {
            str_contains($mimetype, 'json') => $this->validateJson($output, $iiif),
            str_contains($mimetype, 'xml') => $this->validateXml($output),
            default => $this->validateNonEmpty($output),
        };
    }

    /**
     * @return list<Problem>
     */
    private function validateJson(string $output, bool $iiif): array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return [new Problem('empty', 'Rendered to an empty document.')];
        }

        $decoded = json_decode($trimmed);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [new Problem(
                'invalid-json',
                sprintf('%s (%s)', json_last_error_msg(), $this->locateJsonError($trimmed)),
            )];
        }

        if (!$iiif) {
            return [];
        }

        return $this->validateIiif($decoded);
    }

    /**
     * @return list<Problem>
     */
    private function validateIiif(mixed $decoded): array
    {
        if (!is_object($decoded) && !is_array($decoded)) {
            return [new Problem('iiif', 'IIIF documents must be a JSON object.')];
        }

        $this->iiifSchema ??= json_decode((string) file_get_contents($this->schemaPath));

        $validator = new JsonSchemaValidator();
        $result = $validator->validate($decoded, $this->schemaFor($decoded));

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();
        if ($error === null) {
            return [new Problem('iiif', 'Failed IIIF schema validation.')];
        }

        $problems = [];
        $formatted = (new ErrorFormatter())->format($error, false);
        foreach ($formatted as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $problems[] = new Problem('iiif', (string) $message, $pointer === '' ? '/' : $pointer);
            }
        }

        return array_slice($problems, 0, 12);
    }

    /**
     * Validate against the ONE class the document claims to be.
     *
     * The schema's root is a oneOf across manifest / collection /
     * annotationCollection / annotationPage / annotation. Validating a
     * Manifest against that root reports every failed branch, so a perfectly
     * good manifest still produces
     *
     *     /type — The string should match pattern: ^Collection
     *
     * which is true, useless, and buries the real errors. Reading the
     * document's own "type" and validating against just that class keeps the
     * output to problems someone can act on.
     */
    private function schemaFor(mixed $decoded): object
    {
        $classes = [
            'Manifest' => 'manifest',
            'Collection' => 'collection',
            'AnnotationCollection' => 'annotationCollection',
            'AnnotationPage' => 'annotationPage',
            'Annotation' => 'annotation',
        ];

        $type = is_object($decoded) ? ($decoded->type ?? null) : null;
        $class = is_string($type) ? ($classes[$type] ?? null) : null;

        if ($class === null) {
            // No usable type: fall back to the root oneOf and accept the noise,
            // because "which of these is it" is genuinely unanswerable.
            return $this->iiifSchema;
        }

        // draft-07: a $ref beside other keywords wins, and the rest of the
        // document stays available so #/classes/... still resolves.
        $scoped = clone $this->iiifSchema;
        unset($scoped->oneOf);
        $scoped->{'$ref'} = '#/classes/' . $class;

        return $scoped;
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
