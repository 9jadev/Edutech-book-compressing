<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

/** What Step 5 produced. Paths are relative to the storage disk. */
final class VisualizedChapter
{
    public function __construct(
        public readonly string $disk,
        public readonly string $folder,
        public readonly string $visualsJson,
        public readonly string $checkJson,
        public readonly string $previewIndex,
        public readonly bool $passed,
        public readonly int $pages,
        public readonly int $errorsFirstPass,
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
