<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lessons\Eval\EvalSuite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Freeze a chapter you have checked by hand as a test case.
 *
 *   php artisan lessons:eval-add pdfs/chapters/1/chapter-1 --name=bio-ch01
 *
 * Copies pages.json, images_ai/, structure.json and plan.json into lessons-eval/cases/bio-ch01/ and writes
 * case.json with expectations taken from those (good) outputs. Edit case.json to add your own.
 */
class EvalAddCommand extends Command
{
    protected $signature = 'lessons:eval-add
        {folder : A chapter folder whose outputs you have checked}
        {--name= : Case name (default: the folder name)}
        {--disk= : Storage disk (default: lessons.prepare.disk)}';

    protected $description = 'Add a checked chapter to the lessons test set (frozen inputs + expectations)';

    public function handle(): int
    {
        $disk = $this->option('disk') ?: config('lessons.prepare.disk', 'local');
        $name = $this->option('name') ?: basename(trim((string) $this->argument('folder'), '/'));
        try {
            $case = (new EvalSuite($disk, (string) config('lessons.eval.root', 'lessons-eval')))->add((string) $this->argument('folder'), $name);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info("Added case \"$name\".");
        foreach ($case['steps'] as $step => $s) {
            $this->line("  $step: " . count($s['expect']) . ' expectations');
        }
        $this->line('  Edit ' . config('lessons.eval.root', 'lessons-eval') . "/cases/$name/case.json to add your own (e.g. a figure's labels, a question count).");
        return self::SUCCESS;
    }
}
