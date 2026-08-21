<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigLint;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;

/**
 * Parity implementation of MetadataDisplayEntity::validateSource().
 *
 * Archipelago's own gate, verbatim, is:
 *
 *     $source = new Source($twigtemplate, $this->label() ?? $this->uuid(), '');
 *     try {
 *       $this->twigEnvironment()->parse($this->twigEnvironment()->tokenize($source));
 *     }
 *     catch (\Twig\Error\SyntaxError $e) { return $e->getMessage(); }
 *     return TRUE;
 *
 * -- esmero/format_strawberryfield, src/Entity/MetadataDisplayEntity.php
 *
 * We do the same thing against an Environment carrying the same extension
 * names. We deliberately do NOT compile: the real check does not either, and
 * compiling would report problems Archipelago would happily accept.
 */
final class Linter
{
    private Environment $twig;

    public function __construct(private readonly Registry $registry)
    {
        $this->twig = new Environment(new ArrayLoader([]), [
            // Drupal's TwigEnvironment defaults to autoescaping. The escaper
            // runs as a node visitor, i.e. during parse(), so it has to match.
            'autoescape' => 'html',
            'cache' => false,
            'debug' => false,
            'strict_variables' => false,
        ]);
        $this->twig->addExtension(new StubExtension($registry));
    }

    /**
     * @return list<Diagnostic>
     */
    public function lintFile(string $path, string $repoRelative): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return [new Diagnostic($repoRelative, 0, 'Could not read file.', 'io')];
        }

        // Strip a UTF-8 BOM. Drupal stores the twig in a DB text field, so a
        // BOM that survived a copy/paste round trip is not present server-side
        // and should not be reported as a template error.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        // Third Source argument is '' to match validateSource() exactly.
        $source = new Source($contents, $repoRelative, '');

        try {
            $this->twig->parse($this->twig->tokenize($source));
        } catch (SyntaxError $e) {
            return [new Diagnostic(
                $repoRelative,
                max($e->getTemplateLine(), 0),
                $this->cleanMessage($e->getMessage(), $repoRelative),
                'syntax',
            )];
        }

        return [];
    }

    /**
     * Twig appends ' in "<name>" at line N' to every message. The file and line
     * are already carried on the Diagnostic, so drop the tail to keep GitHub
     * annotations readable.
     */
    private function cleanMessage(string $message, string $name): string
    {
        $message = str_replace(' in "' . $name . '"', '', $message);
        // Twig's tail is ' at line 12.', ' at line 12 column 40.', or -- when
        // it appends a "Did you mean" hint -- ' at line 12?'. The Diagnostic
        // already carries the line, so drop all of it.
        $message = (string) preg_replace('/\s*at line \d+(?: column \d+)?\s*[.?]?$/', '', $message);

        return rtrim($message, ' .') . '.';
    }
}
