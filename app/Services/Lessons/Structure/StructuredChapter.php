<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

/** What Step 2 produced. Paths are relative to the storage disk. */
final class StructuredChapter
{
    public function __construct(
        public readonly string $disk,
        public readonly string $folder,
        public readonly string $structureJson,
        public readonly string $checkJson,
        public readonly bool $passed,
        public readonly int $items,
        public readonly int $errorsLeft,
        public readonly int $warnings,
        public readonly array $stats,
        public readonly array $usage,
        public readonly float $durationSeconds,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
