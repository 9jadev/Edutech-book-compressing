<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Laravel wrapper for Step 2 (Structure) + 2b (Check).
 *
 * Reads   {folder}/pages.json and {folder}/images_ai/*.jpg   (made by Step 1)
 * Writes  {folder}/structure.json, structure-check.json, usage.jsonl
 * Updates {folder}/status.json under "structure"
 */
final class StructureChapterService
{
    public function __construct(private readonly ?PageReader $reader = null)
    {
    }

    /**
     * @param string  $folder chapter folder on the disk, e.g. books/biology/chapters/01-cell
     * @param ?string $pages  optional page range to read, e.g. "1-3" (for cheap tests)
     * @param ?callable $log  fn(string) for progress lines
     */
    public function structure(string $disk, string $folder, ?string $pages = null, ?string $provider = null, ?string $model = null, ?callable $log = null): StructuredChapter
    {
        $fs = Storage::disk($disk);
        $folder = trim($folder, '/');
        if (!$fs->exists("$folder/pages.json")) {
            throw new RuntimeException("No pages.json in $folder on disk '$disk'. Run Step 1 (lessons:prepare) first.");
        }
        $pagesJson = json_decode((string) $fs->get("$folder/pages.json"), true);
        if (!is_array($pagesJson) || empty($pagesJson['pages'])) {
            throw new RuntimeException("pages.json in $folder is empty or invalid.");
        }

        $cfg = (array) config('lessons.structure', []);
        $provider ??= (string) ($cfg['provider'] ?? 'qwen');
        $model ??= (string) ($cfg['model'] ?? 'qwen3.5-plus');
        $reader = $this->reader ?? new LaravelAiPageReader($provider, $model, (int) ($cfg['call_timeout'] ?? 180));
        $runner = new StructureRunner(
            reader: $reader,
            pagesPerCall: (int) ($cfg['pages_per_call'] ?? 3),
            lookahead: (bool) ($cfg['lookahead_page'] ?? true),
            maxJsonRetries: (int) ($cfg['max_json_retries'] ?? 2),
            maxRepairRounds: (int) ($cfg['max_repair_rounds'] ?? 2),
            prices: (array) ($cfg['prices'] ?? []),
            log: $log ?? fn (string $m) => Log::info("Lessons structure [$folder]: $m"),
        );
        $scope = PageGrouper::parseRange($pages, count($pagesJson['pages']));

        $this->status($fs, $folder, ['status' => 'processing', 'started_at' => now()->toIso8601String(), 'provider' => $provider, 'model' => $model, 'pages' => $pages ?? 'all']);

        $local = $this->localFolder($fs, $folder);
        $tmp = null;
        try {
            if ($local === null) {
                // Cloud disk: copy pages.json + AI images to a temp folder, run, upload results.
                $tmp = rtrim(sys_get_temp_dir(), '/') . '/lesson-structure-' . bin2hex(random_bytes(6));
                File::ensureDirectoryExists("$tmp/images_ai");
                foreach ($fs->files("$folder/images_ai") as $f) {
                    file_put_contents("$tmp/images_ai/" . basename($f), $fs->get($f));
                }
                $work = $tmp;
            } else {
                $work = $local;
            }

            $record = $runner->run($pagesJson, $work, $scope);

            $usageLines = implode("\n", array_map(fn ($c) => json_encode(['step' => 'structure'] + $c, JSON_UNESCAPED_SLASHES), $runner->calls())) . "\n";
            if ($tmp !== null) {
                $fs->put("$folder/structure.json", (string) file_get_contents("$tmp/structure.json"));
                $fs->put("$folder/structure-check.json", (string) file_get_contents("$tmp/structure-check.json"));
                $fs->put("$folder/usage.jsonl", ($fs->exists("$folder/usage.jsonl") ? $fs->get("$folder/usage.jsonl") : '') . $usageLines);
            } else {
                file_put_contents("$work/usage.jsonl", $usageLines, FILE_APPEND);
            }

            $result = new StructuredChapter(
                disk: $disk,
                folder: $folder,
                structureJson: "$folder/structure.json",
                checkJson: "$folder/structure-check.json",
                passed: (bool) $record['check']['passed'],
                items: count($record['items']),
                errorsLeft: (int) $record['check']['errors_left'],
                warnings: (int) $record['check']['warnings'],
                stats: $record['check']['stats'],
                usage: $record['usage'],
                durationSeconds: (float) $record['duration_seconds'],
            );

            $this->status($fs, $folder, [
                'status' => $result->passed ? 'completed' : 'completed_with_issues',
                'finished_at' => now()->toIso8601String(),
                'provider' => $provider,
                'model' => $model,
                'items' => $result->items,
                'errors_left' => $result->errorsLeft,
                'warnings' => $result->warnings,
                'usage' => $result->usage,
                'next_step' => 'plan',
            ]);
            return $result;
        } catch (Throwable $e) {
            $this->markFailed($disk, $folder, $e);
            throw $e;
        } finally {
            if ($tmp !== null) {
                File::deleteDirectory($tmp);
            }
        }
    }

    public function markFailed(string $disk, string $folder, Throwable $e): void
    {
        try {
            $this->status(Storage::disk($disk), trim($folder, '/'), ['status' => 'failed', 'failed_at' => now()->toIso8601String(), 'error' => $e->getMessage()]);
        } catch (Throwable) {
        }
    }

    /** Merge into status.json under "structure", keeping what Step 1 wrote. */
    private function status(Filesystem $fs, string $folder, array $data): void
    {
        $path = "$folder/status.json";
        $current = $fs->exists($path) ? (json_decode((string) $fs->get($path), true) ?: []) : [];
        $previous = ($data['status'] ?? '') === 'processing' ? [] : (array) ($current['structure'] ?? []);
        $current['structure'] = array_merge($previous, $data);
        $fs->put($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function localFolder(Filesystem $fs, string $folder): ?string
    {
        try {
            $p = $fs->path($folder);
            return is_dir($p) ? $p : null;
        } catch (Throwable) {
            return null;
        }
    }
}
