<?php

declare(strict_types=1);

namespace App\Events\Lessons;

use App\Services\Lessons\Visuals\VisualizedChapter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when visuals.json and the preview pages are written. Listen for it to start Step 6 (Build). */
class ChapterVisualized
{
    use Dispatchable, SerializesModels;

    public function __construct(public VisualizedChapter $chapter, public array $meta = [])
    {
    }
}
