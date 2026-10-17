<?php

declare(strict_types=1);

namespace App\Services\Lessons\Build;

/** What Step 6 produced. Paths are relative to the storage disk. */
final class BuiltChapter
{
    public function __construct(
        public readonly string $disk,
        public readonly string $folder,
        public readonly string $buildDir,
        public readonly string $index,
        public readonly bool $passed,
        public readonly int $pages,
        public readonly int $pagesPlanned,
        public readonly int $scenes,
        public readonly int $questions,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly float $durationSeconds,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
