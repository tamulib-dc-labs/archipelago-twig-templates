<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigLint;

use RuntimeException;

/**
 * Reads registry/extensions.json.
 *
 * Kept as data rather than code so that the drift check (see README) can diff
 * it against upstream module sources without parsing PHP.
 */
final class Registry
{
    /** @var list<array<string, mixed>> */
    private array $sources;

    private function __construct(array $sources)
    {
        $this->sources = $sources;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Registry not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (!isset($decoded['sources']) || !is_array($decoded['sources'])) {
            throw new RuntimeException("Registry {$path} has no 'sources' array.");
        }

        return new self(array_values($decoded['sources']));
    }

    /** @return list<array<string, mixed>> */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Flat list of every registered name of one kind.
     *
     * @param 'filters'|'functions'|'tests' $kind
     *
     * @return list<string>
     */
    public function names(string $kind): array
    {
        $out = [];
        foreach ($this->sources as $source) {
            foreach ($source[$kind] ?? [] as $name) {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Which registry source declared a given name, for error context.
     */
    public function providerOf(string $kind, string $name): ?string
    {
        foreach ($this->sources as $source) {
            if (in_array($name, $source[$kind] ?? [], true)) {
                return $source['id'];
            }
        }

        return null;
    }

    public function total(): int
    {
        return count($this->names('filters'))
            + count($this->names('functions'))
            + count($this->names('tests'));
    }
}
