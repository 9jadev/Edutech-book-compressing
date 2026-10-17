<?php

declare(strict_types=1);

namespace App\Events\Lessons;

use App\Services\Lessons\Write\WrittenChapter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when content.json is written. Listen for it to start Step 5 (Visuals). */
class ChapterWritten
{
    use Dispatchable, SerializesModels;

    public function __construct(public WrittenChapter $chapter, public array $meta = [])
    {
    }
}
