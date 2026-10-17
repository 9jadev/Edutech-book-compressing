<?php

declare(strict_types=1);

namespace App\Events\Lessons;

use App\Services\Lessons\Plan\PlannedChapter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired by lessons:plan-approve (or your admin screen) when a teacher approves plan.json. */
class ChapterPlanApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public PlannedChapter $chapter, public array $meta = [])
    {
    }
}
