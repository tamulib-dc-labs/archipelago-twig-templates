<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigRender;

use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Renders a metadata display template against a fixture.
 */
final class Renderer
{
    private Environment $twig;

    public function __construct(
        private readonly Context $context,
        string $baseUrl = 'https://example.org',
        string $iiifServer = 'https://example.org/iiif/2/',
    ) {
        $this->twig = new Environment(new ArrayLoader([]), [
            // Drupal's TwigEnvironment autoescapes, and Archipelago renders
            // Metadata Displays through it. That is WHY these templates are
            // full of |raw and |json_encode|raw.
            //
            // Turning it off here would be more convenient and less faithful:
            // a value emitted without |raw in a JSON document escapes its
            // quotes and breaks the JSON in production too. That is a bug we
            // want this harness to catch, not paper over.
            'autoescape' => 'html',
            'cache' => false,
            'debug' => false,
            'strict_variables' => false,
        ]);

        $this->twig->addExtension(new ArchipelagoExtension($baseUrl, $iiifServer));
    }

    /**
     * @param array<mixed> $fixture
     *
     * @throws UnsupportedByRendererException when the template needs Drupal
     */
    public function render(string $templateSource, array $fixture, string $fixtureName): string
    {
        $template = $this->twig->createTemplate($templateSource);

        try {
            return $template->render($this->context->build($fixture, $fixtureName));
        } catch (\Throwable $e) {
            // Twig wraps anything thrown inside a filter in a RuntimeError, so
            // our own refusal arrives buried in the previous-exception chain.
            // Dig it out and rethrow it, otherwise every "needs Drupal" case is
            // reported as an anonymous render failure and the reader has to
            // read a stack trace to learn it was never renderable offline.
            for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
                if ($previous instanceof UnsupportedByRendererException) {
                    throw $previous;
                }
            }

            throw $e;
        }
    }
}
