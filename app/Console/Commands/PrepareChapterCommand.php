<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Lessons\PrepareChapterJob;
use App\Services\Lessons\Prepare\PrepareChapterService;
use App\Services\Lessons\Prepare\Toolbox;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run Step 1 by hand, or check the tools are installed.
 *
 *   php artisan lessons:prepare books/biology/chapters/01-cell.pdf
 *   php artisan lessons:prepare books/biology/chapters/01-cell.pdf --printed-start=9 --mode=ocr
 *   php artisan lessons:prepare books/biology/chapters/01-cell.pdf --queue
 *   php artisan lessons:prepare --check
 */
class PrepareChapterCommand extends Command
{
    protected $signature = 'lessons:prepare
        {pdf? : Chapter PDF path on the disk}
        {--disk= : Storage disk (default: config lessons.prepare.disk)}
        {--printed-start= : Printed page number of the first page (default: detect)}
        {--mode= : auto | pdf_text | ocr}
        {--lang= : Tesseract language, e.g. eng}
        {--header=* : Running header regex to remove (repeatable)}
        {--queue : Dispatch the job instead of running now}
        {--check : Only check that the tools are installed}';

    protected $description = 'Step 1: prepare a chapter PDF (text, OCR when needed, images, pages.json)';

    public function handle(PrepareChapterService $service): int
    {
        if ($this->option('check')) {
            return $this->checkTools($service);
        }
        $pdf = $this->argument('pdf');
        if (!$pdf) {
            $this->error('Give a chapter PDF path, or use --check.');
            return self::INVALID;
        }
        $disk = $this->option('disk') ?: config('lessons.prepare.disk', 'local');
        $printed = $this->option('printed-start') !== null ? (int) $this->option('printed-start') : null;

        if ($this->option('queue')) {
            PrepareChapterJob::dispatch($disk, $pdf, $printed, $this->option('mode'), $this->option('header'), $this->option('lang'));
            $this->info("Queued on '" . config('lessons.prepare.queue', 'default') . "'. Folder: " . PrepareChapterService::folderFor($pdf));
            return self::SUCCESS;
        }

        $this->info("Preparing $pdf on disk '$disk' …");
        try {
            $r = $service->prepare($disk, $pdf, [
                'first_printed_page' => $printed,
                'mode' => $this->option('mode'),
                'header_patterns' => $this->option('header'),
                'ocr_lang' => $this->option('lang'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['Folder', $r->folder],
            ['pages.json', $r->pagesJson],
            ['Strategy', $r->strategy],
            ['Pages', $r->pageCount],
            ['First printed page', $r->firstPrintedPage ?? 'not detected'],
            ['Figure pages', $r->totals['figure_pages'] ?? 0],
            ['OCR pages', $r->totals['ocr_pages'] ?? 0],
            ['Time', $r->durationSeconds . ' s'],
        ]);
        foreach ($r->warnings as $w) {
            $this->warn($w);
        }
        return self::SUCCESS;
    }

    private function checkTools(PrepareChapterService $service): int
    {
        $tools = $service->makeToolbox((array) config('lessons.prepare', []));
        $ok = true;
        foreach (Toolbox::TOOLS as $t) {
            try {
                $this->line("<info>✓</info> $t  " . $tools->path($t));
            } catch (Throwable $e) {
                $ok = false;
                $this->line("<error>✗</error> $t  " . $e->getMessage());
            }
        }
        if ($ok) {
            $langs = $tools->tesseractLanguages();
            $want = explode('+', (string) config('lessons.prepare.ocr_lang', 'eng'));
            foreach ($want as $l) {
                $in = in_array($l, $langs, true);
                $ok = $ok && $in;
                $this->line(($in ? '<info>✓</info>' : '<error>✗</error>') . " tesseract language $l");
            }
        }
        $gd = function_exists('imagecreatefrompng');
        $this->line(($gd ? '<info>✓</info>' : '<comment>!</comment>') . ' PHP GD extension' . ($gd ? '' : ' (missing: images_ai will be skipped)'));
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
