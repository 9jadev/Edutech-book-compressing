<?php

declare(strict_types=1);

namespace App\Services\Lessons\Plan;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Laravel wrapper for Step 3 (Plan).
 *
 * Reads   {folder}/structure.json                      (made by Step 2)
 * Writes  {folder}/plan.json, plan-check.json, usage.jsonl
 * Updates {folder}/status.json under "plan"
 */
final class PlanChapterService
{
    public function __construct(private readonly ?PlanDesigner $designer = null)
    {
    }

    public function plan(string $disk, string $folder, ?string $provider = null, ?string $model = null, ?callable $log = null): PlannedChapter
    {
        $fs = Storage::disk($disk);
        $folder = trim($folder, '/');
        if (!$fs->exists("$folder/structure.json")) {
            throw new RuntimeException("No structure.json in $folder on disk '$disk'. Run Step 2 (lessons:structure) first.");
        }
        $structure = json_decode((string) $fs->get("$folder/structure.json"), true);
        if (!is_array($structure) || empty($structure['items'])) {
            throw new RuntimeException("structure.json in $folder is empty or invalid.");
        }

        $cfg = (array) config('lessons.plan', []);
        $provider ??= (string) ($cfg['provider'] ?? 'qwen');
        $model ??= (string) ($cfg['model'] ?? 'deepseek-v4.1-flash');
        $runner = new PlanRunner(
            designer: $this->designer ?? new LaravelAiPlanDesigner($provider, $model, (int) ($cfg['call_timeout'] ?? 300)),
            splitter: new PageSplitter(
                minItems: (int) ($cfg['min_items_per_page'] ?? 3),
                maxItems: (int) ($cfg['max_items_per_page'] ?? 30),
                activityOwnPage: (bool) ($cfg['activity_own_page'] ?? true),
            ),
            pagesPerCall: (int) ($cfg['pages_per_call'] ?? 4),
            maxItemsPerCall: (int) ($cfg['max_items_per_call'] ?? 80),
            maxJsonRetries: (int) ($cfg['max_json_retries'] ?? 2),
            maxRepairRounds: (int) ($cfg['max_repair_rounds'] ?? 2),
            reviewRequired: (bool) ($cfg['require_review'] ?? false),
            prices: (array) ($cfg['prices'] ?? []),
            log: $log ?? fn (string $m) => Log::info("Lessons plan [$folder]: $m"),
        );

        $this->status($fs, $folder, ['status' => 'processing', 'started_at' => now()->toIso8601String(), 'provider' => $provider, 'model' => $model]);

        $local = $this->localFolder($fs, $folder);
        $tmp = null;
        try {
            if ($local === null) {
                $tmp = rtrim(sys_get_temp_dir(), '/') . '/lesson-plan-' . bin2hex(random_bytes(6));
                File::ensureDirectoryExists($tmp);
                $work = $tmp;
            } else {
                $work = $local;
            }

            $record = $runner->run($structure, $work);

            $usageLines = implode("\n", array_map(fn ($c) => json_encode(['step' => 'plan'] + $c, JSON_UNESCAPED_SLASHES), $runner->calls())) . "\n";
            if ($tmp !== null) {
                $fs->put("$folder/plan.json", (string) file_get_contents("$tmp/plan.json"));
                $fs->put("$folder/plan-check.json", (string) file_get_contents("$tmp/plan-check.json"));
                $fs->put("$folder/usage.jsonl", ($fs->exists("$folder/usage.jsonl") ? $fs->get("$folder/usage.jsonl") : '') . $usageLines);
            } else {
                file_put_contents("$work/usage.jsonl", $usageLines, FILE_APPEND);
            }

            $result = $this->result($disk, $folder, $record);
            $this->status($fs, $folder, [
                'status' => !$result->passed ? 'completed_with_issues' : ($result->approved ? 'completed' : 'awaiting_review'),
                'finished_at' => now()->toIso8601String(),
                'provider' => $provider,
                'model' => $model,
                'pages' => $result->pages,
                'errors_left' => $result->errorsLeft,
                'warnings' => $result->warnings,
                'approved' => $result->approved,
                'usage' => $result->usage,
                'next_step' => $result->approved ? 'write' : 'review (php artisan lessons:plan-approve ' . $folder . ')',
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

    /** Teacher approval: marks plan.json approved and returns the chapter so the next step can start. */
    public function approve(string $disk, string $folder, ?string $by = null, ?string $note = null): PlannedChapter
    {
        $fs = Storage::disk($disk);
        $folder = trim($folder, '/');
        if (!$fs->exists("$folder/plan.json")) {
            throw new RuntimeException("No plan.json in $folder. Run Step 3 (lessons:plan) first.");
        }
        $record = json_decode((string) $fs->get("$folder/plan.json"), true);
        $record['review'] = array_merge((array) ($record['review'] ?? []), [
            'approved' => true,
            'approved_at' => now()->toIso8601String(),
            'approved_by' => $by,
            'note' => $note,
        ]);
        $fs->put("$folder/plan.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->status($fs, $folder, ['status' => 'completed', 'approved' => true, 'approved_at' => $record['review']['approved_at'], 'next_step' => 'write']);
        return $this->result($disk, $folder, $record);
    }

    public function markFailed(string $disk, string $folder, Throwable $e): void
    {
        try {
            $this->status(Storage::disk($disk), trim($folder, '/'), ['status' => 'failed', 'failed_at' => now()->toIso8601String(), 'error' => $e->getMessage()]);
        } catch (Throwable) {
        }
    }

    private function result(string $disk, string $folder, array $record): PlannedChapter
    {
        return new PlannedChapter(
            disk: $disk,
            folder: $folder,
            planJson: "$folder/plan.json",
            checkJson: "$folder/plan-check.json",
            passed: (bool) $record['check']['passed'],
            approved: (bool) ($record['review']['approved'] ?? false),
            pages: count($record['pages']),
            files: array_column($record['pages'], 'key'),
            errorsLeft: (int) $record['check']['errors_left'],
            warnings: (int) $record['check']['warnings'],
            stats: (array) $record['check']['stats'],
            usage: (array) $record['usage'],
            durationSeconds: (float) $record['duration_seconds'],
        );
    }

    /** Merge into status.json under "plan", keeping what earlier steps wrote. */
    private function status(Filesystem $fs, string $folder, array $data): void
    {
        $path = "$folder/status.json";
        $current = $fs->exists($path) ? (json_decode((string) $fs->get($path), true) ?: []) : [];
        $previous = ($data['status'] ?? '') === 'processing' ? [] : (array) ($current['plan'] ?? []);
        $current['plan'] = array_merge($previous, $data);
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
