<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigLint;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Extracts declared Twig extension names from PHP source.
 *
 * Finds every `new TwigFilter('x', ...)`, `new TwigFunction('x', ...)` and
 * `new TwigTest('x', ...)`.
 *
 * Uses PHP's tokenizer rather than a regex, so an occurrence inside a comment
 * or a string literal cannot produce a phantom name.
 */
final class NameScanner
{
    private const KINDS = [
        'TwigFilter' => 'filters',
        'TwigFunction' => 'functions',
        'TwigTest' => 'tests',
    ];

    private const EXTENSIONS = ['php', 'module', 'inc', 'theme'];

    /** @var array<string, array<string, true>> */
    private array $found = ['filters' => [], 'functions' => [], 'tests' => []];

    /** @var array<string, array<string, true>> */
    private array $provenance = [];

    private int $scanned = 0;

    /**
     * @param string $label how to attribute anything found under this path
     */
    public function scanPath(string $path, string $label): void
    {
        foreach ($this->phpFiles($path) as $file) {
            $this->scanned++;
            foreach ($this->namesIn($file) as [$kind, $name]) {
                $this->found[$kind][$name] = true;
                $this->provenance[$name][$label] = true;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $source): array
    {
        $out = [];
        foreach ($this->found as $kind => $names) {
            $list = array_keys($names);
            sort($list);
            $out[$kind] = $list;
        }

        $provenance = [];
        foreach ($this->provenance as $name => $labels) {
            $provenance[$name] = array_keys($labels);
        }
        ksort($provenance);

        return [
            'source' => $source,
            'scanned_files' => $this->scanned,
            'generated' => gmdate('c'),
            'filters' => $out['filters'],
            'functions' => $out['functions'],
            'tests' => $out['tests'],
            'declared_in' => $provenance,
        ];
    }

    /**
     * @return iterable<string>
     */
    private function phpFiles(string $path): iterable
    {
        if (is_file($path)) {
            yield $path;

            return;
        }

        if (!is_dir($path)) {
            fwrite(STDERR, "Skipping (not found): {$path}\n");

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && in_array($file->getExtension(), self::EXTENSIONS, true)) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * Walks the token stream looking for `new <Kind> ( '<name>'`.
     *
     * Tolerates a leading namespace separator and a fully qualified name, i.e.
     * both `new TwigFilter(...)` and `new \Twig\TwigFilter(...)`.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function namesIn(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $tokens = @token_get_all($source);
        $out = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                continue;
            }

            $j = $i + 1;
            $segment = $this->className($tokens, $j, $count);

            if ($segment === null || !isset(self::KINDS[$segment])) {
                continue;
            }

            $this->skipTrivia($tokens, $j, $count);
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }

            $j++;
            $this->skipTrivia($tokens, $j, $count);

            $token = $tokens[$j] ?? null;
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $name = trim($token[1], "'\"");
                if ($name !== '') {
                    $out[] = [self::KINDS[$segment], $name];
                }
            }
        }

        return $out;
    }

    /**
     * Reads the class name after `new`, returning only its last segment.
     *
     * @param array<int, mixed> $tokens
     */
    private function className(array $tokens, int &$j, int $count): ?string
    {
        $segment = null;

        for (; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($token[0] === T_STRING) {
                    $segment = $token[1];
                    continue;
                }
                if (defined('T_NAME_QUALIFIED')
                    && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $parts = explode('\\', $token[1]);
                    $segment = (string) end($parts);
                    continue;
                }
                if ($token[0] === T_NS_SEPARATOR) {
                    continue;
                }

                break;
            }

            if ($token === '\\') {
                continue;
            }

            break;
        }

        return $segment;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function skipTrivia(array $tokens, int &$j, int $count): void
    {
        for (; $j < $count; $j++) {
            if (is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return;
        }
    }
}
