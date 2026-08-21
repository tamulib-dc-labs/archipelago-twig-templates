<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigLint;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * Registers every non-core Twig name that the target Archipelago install has.
 *
 * The callables are deliberately no-ops. Nothing here is ever executed: this
 * linter only tokenizes and parses, exactly like
 * MetadataDisplayEntity::validateSource(). Twig resolves filter/function/test
 * NAMES at parse time, so registration alone is what makes an unknown name an
 * error and a known name not one.
 */
final class StubExtension extends AbstractExtension
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function getFilters(): array
    {
        $out = [];
        foreach ($this->registry->names('filters') as $name) {
            // is_safe => all keeps the escaper node visitor from wrapping the
            // result, matching how the real markup-producing filters declare
            // themselves. It cannot cause a parse error either way.
            $out[] = new TwigFilter($name, self::noop(...), ['is_safe' => ['all']]);
        }

        return $out;
    }

    public function getFunctions(): array
    {
        $out = [];
        foreach ($this->registry->names('functions') as $name) {
            $out[] = new TwigFunction($name, self::noop(...), ['is_safe' => ['all']]);
        }

        return $out;
    }

    public function getTests(): array
    {
        $out = [];
        foreach ($this->registry->names('tests') as $name) {
            $out[] = new TwigTest($name, self::noop(...));
        }

        return $out;
    }

    public static function noop(mixed ...$args): null
    {
        return null;
    }
}
