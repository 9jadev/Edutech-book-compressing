<?php

declare(strict_types=1);

namespace App\Services\Lessons\Prepare;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Laravel wrapper for Step 1.
 *
 * Given a chapter PDF on a storage disk, e.g.  books/biology/chapters/01-cell.pdf
 * it creates a folder named after the PDF next to it:
 *
 *   books/biology/chapters/01-cell/
 *     01-cell.pdf        copy of the chapter PDF that was processed
 *     pages.json         the Step 1 result
 *     status.json        processing | completed | failed, with timings and errors
 *     images/p-1.png ...
 *     images_ai/p-1.jpg ...
 *
 * Works with local disks directly. For cloud disks (s3 etc.) it processes in a temp
 * folder and uploads the results to the same place.
 */
final class PrepareChapterService
{
    public function __construct(private readonly ?Toolbox $tools = null)
    {
    }

    /**
     * @param array<string,mixed> $overrides first_printed_page, mode, header_patterns, ocr_lang
     */
    public function prepare(string $disk, string $pdfPath, array $overrides = []): PreparedChapter
    {
        $fs = Storage::disk($disk);
        $pdfPath = ltrim($pdfPath, '/');
        if (!str_ends_with(strtolower($pdfPath), '.pdf')) {
            throw new RuntimeException("Not a PDF path: $pdfPath");
        }
        if (!$fs->exists($pdfPath)) {
            throw new RuntimeException("PDF not found on disk '$disk': $pdfPath");
        }

        $config = (array) config('lessons.prepare', []);
        $options = PrepareOptions::fromConfig($config, $overrides);
        $preparer = new ChapterPreparer($this->tools ?? $this->makeToolbox($config), $options);

        $folder = self::folderFor($pdfPath);
        $pdfInFolder = $folder . '/' . basename($pdfPath);
        $started = now();

        $this->writeStatus($fs, $folder, ['status' => 'processing', 'source_pdf' => $pdfPath, 'started_at' => $started->toIso8601String()]);

        try {
            $local = $this->localPath($fs, $pdfPath);
            if ($local !== null) {
                // Local disk: work straight in the destination folder.
                $absFolder = $fs->path($folder);
                File::ensureDirectoryExists($absFolder);
                if (!@copy($local, $fs->path($pdfInFolder))) {
                    throw new RuntimeException("Could not copy the PDF into $folder");
                }
                $result = $preparer->run($fs->path($pdfInFolder), $absFolder);
            } else {
                // Cloud disk: download, process in a temp folder, upload everything back.
                $tmp = rtrim(sys_get_temp_dir(), '/') . '/lesson-prepare-' . bin2hex(random_bytes(6));
                File::ensureDirectoryExists($tmp);
                try {
                    $tmpPdf = $tmp . '/' . basename($pdfPath);
                    $in = $fs->readStream($pdfPath);
                    $out = fopen($tmpPdf, 'wb');
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    fclose($out);
                    $result = $preparer->run($tmpPdf, $tmp);
                    foreach (File::allFiles($tmp) as $file) {
                        $fs->put($folder . '/' . $file->getRelativePathname(), fopen($file->getPathname(), 'rb'));
                    }
                } finally {
                    File::deleteDirectory($tmp);
                }
            }

            if ((bool) ($config['move_source'] ?? false)) {
                $fs->delete($pdfPath);   // keep only the copy inside the folder
            }

            $prepared = new PreparedChapter(
                disk: $disk,
                sourcePdf: $pdfPath,
                folder: $folder,
                pdf: $pdfInFolder,
                pagesJson: $folder . '/pages.json',
                strategy: (string) $result['strategy_used'],
                pageCount: (int) $result['page_count'],
                firstPrintedPage: $result['first_printed_page'],
                totals: $result['totals'],
                warnings: $result['warnings'],
                durationSeconds: (float) $result['duration_seconds'],
            );

            $this->writeStatus($fs, $folder, [
                'status' => 'completed',
                'source_pdf' => $pdfPath,
                'started_at' => $started->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
                'strategy' => $prepared->strategy,
                'page_count' => $prepared->pageCount,
                'first_printed_page' => $prepared->firstPrintedPage,
                'warnings' => $prepared->warnings,
                'next_step' => 'structure',
            ]);
            Log::info('Lessons: chapter prepared', ['folder' => $folder, 'strategy' => $prepared->strategy, 'warnings' => count($prepared->warnings)]);

            return $prepared;
        } catch (Throwable $e) {
            $this->markFailed($disk, $pdfPath, $e);
            throw $e;
        }
    }

    public function markFailed(string $disk, string $pdfPath, Throwable $e): void
    {
        try {
            $this->writeStatus(Storage::disk($disk), self::folderFor($pdfPath), [
                'status' => 'failed',
                'source_pdf' => $pdfPath,
                'failed_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // never hide the original error
        }
    }

    /** books/biology/chapters/01-cell.pdf  =>  books/biology/chapters/01-cell */
    public static function folderFor(string $pdfPath): string
    {
        $dir = dirname($pdfPath);
        $name = pathinfo($pdfPath, PATHINFO_FILENAME);
        return ($dir === '.' || $dir === '') ? $name : $dir . '/' . $name;
    }

    public function makeToolbox(array $config): Toolbox
    {
        return new Toolbox(
            (array) ($config['binaries'] ?? []),
            (array) ($config['search_dirs'] ?? ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin']),
        );
    }

    private function localPath(Filesystem $fs, string $path): ?string
    {
        try {
            $p = $fs->path($path);
            return is_file($p) ? $p : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function writeStatus(Filesystem $fs, string $folder, array $data): void
    {
        $fs->put($folder . '/status.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
