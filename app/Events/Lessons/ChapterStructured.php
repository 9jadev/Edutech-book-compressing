<?php

declare(strict_types=1);

namespace App\Events\Lessons;

use App\Services\Lessons\Structure\StructuredChapter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when structure.json is written. Listen for it to start Step 3 (Plan). */
class ChapterStructured
{
    use Dispatchable, SerializesModels;

    public function __construct(public StructuredChapter $chapter, public array $meta = [])
    {
    }
}
