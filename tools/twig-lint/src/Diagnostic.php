<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoTwigLint;

final class Diagnostic
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $message,
        public readonly string $kind,
    ) {
    }
}
