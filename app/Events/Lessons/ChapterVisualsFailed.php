<?php

declare(strict_types=1);

namespace App\Events\Lessons;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChapterVisualsFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public string $disk, public string $folder, public string $error, public array $meta = [])
    {
    }
}
