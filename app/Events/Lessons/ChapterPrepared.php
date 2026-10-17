<?php

declare(strict_types=1);

namespace App\Events\Lessons;

use App\Services\Lessons\Prepare\PreparedChapter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when Step 1 has written pages.json. Listen for it to start Step 2 (Structure). */
class ChapterPrepared
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PreparedChapter $chapter,
        public array $meta = [],
    ) {
    }
}
