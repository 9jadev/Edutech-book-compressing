<?php

declare(strict_types=1);

namespace App\Events\Lessons;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when Step 1 failed after all retries. status.json in the chapter folder has the error. */
class ChapterPreparationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $disk,
        public string $pdfPath,
        public string $error,
        public array $meta = [],
    ) {
    }
}
