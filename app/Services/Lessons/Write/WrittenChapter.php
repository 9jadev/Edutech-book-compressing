<?php

declare(strict_types=1);

namespace App\Services\Lessons\Write;

/** What Step 4 produced. Paths are relative to the storage disk. */
final class WrittenChapter
{
    public function __construct(
        public readonly string $disk,
        public readonly string $folder,
        public readonly string $contentJson,
        public readonly string $checkJson,
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
