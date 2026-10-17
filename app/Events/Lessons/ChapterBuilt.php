<?php

declare(strict_types=1);

namespace App\Events\Lessons;

use App\Services\Lessons\Build\BuiltChapter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when build/ is written. Listen for it to start Step 7 (Test). */
class ChapterBuilt
{
    use Dispatchable, SerializesModels;

    public function __construct(public BuiltChapter $chapter, public array $meta = [])
    {
    }
}

