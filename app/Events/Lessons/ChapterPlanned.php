<?php

declare(strict_types=1);

namespace App\Events\Lessons;

use App\Services\Lessons\Plan\PlannedChapter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when plan.json is written. $chapter->approved is false while it waits for teacher review. */
class ChapterPlanned
{
    use Dispatchable, SerializesModels;

    public function __construct(public PlannedChapter $chapter, public array $meta = [])
    {
    }
}
