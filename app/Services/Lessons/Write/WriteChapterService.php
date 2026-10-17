<?php

declare(strict_types=1);

namespace App\Services\Lessons\Write;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Laravel wrapper for Step 4 (Write).
 *
 * Reads   {folder}/plan.json, structure.json, pages.json      (Steps 1–3)
 * Writes  {folder}/content.json, content-check.json, usage.jsonl
 * Updates {folder}/status.json under "write"
 */
final class WriteChapterService
{
    public function __construct(private readonly ?PageWriter $writer = null)
    {
    }

    /**
     * @param ?string $pages only these pages, e.g. "2,3" (positions in plan.json) or "02-1-osmosis.html"; others are kept
     * @param bool    $force write even if the plan is not approved or did not pass its check
     */
    public function write(string $disk, string $folder, ?string $pages = null, ?string $provider = null, ?string $model = null, ?callable $log = null, bool $force = false): WrittenChapter
    {
        $fs = Storage::disk($disk);
        $folder = trim($folder, '/');
        foreach (['plan.json' => 'Step 3 (lessons:plan)', 'structure.json' => 'Step 2 (lessons:structure)', 'pages.json' => 'Step 1 (lessons:prepare)'] as $f => $step) {
            if (!$fs->exists("$folder/$f")) {
                throw new RuntimeException("No $f in $folder on disk '$disk'. Run $step first.");
            }
        }
        $plan = json_decode((string) $fs->get("$folder/plan.json"), true);
        $structure = json_decode((string) $fs->get("$folder/structure.json"), true);
        $pagesJson = json_decode((string) $fs->get("$folder/pages.json"), true);
        if (!is_array($plan) || empty($plan['pages'])) {
            throw new RuntimeException("plan.json in $folder is empty or invalid.");
        }
        if (!$force && empty($plan['review']['approved'])) {
            throw new RuntimeException("plan.json in $folder is waiting for teacher review. Run lessons:plan-approve first (or use --force).");
        }
        if (!$force && empty($plan['check']['passed'])) {
            throw new RuntimeException("plan.json in $folder did not pass its check (see plan-check.json). Fix it first (or use --force).");
        }

        $cfg = (array) config('lessons.write', []);
        $provider ??= (string) ($cfg['provider'] ?? 'qwen');
        $model ??= (string) ($cfg['model'] ?? 'qwen3.8-flash');
        $runner = new WriteRunner(
            writer: $this->writer ?? new LaravelAiPageWriter($provider, $model, (int) ($cfg['call_timeout'] ?? 300), (bool) ($cfg['structured_output'] ?? false)),
            maxJsonRetries: (int) ($cfg['max_json_retries'] ?? 2),
            maxRepairRounds: (int) ($cfg['max_repair_rounds'] ?? 2),
            headerPatterns: (array) ($cfg['header_patterns'] ?? config('lessons.prepare.header_patterns', [])),
            prices: (array) ($cfg['prices'] ?? []),
            log: $log ?? fn (string $m) => Log::info("Lessons write [$folder]: $m"),
        );

        $only = $this->selectPages($plan, $pages);
        $existing = $only !== null && $fs->exists("$folder/content.json") ? json_decode((string) $fs->get("$folder/content.json"), true) : null;
        $existingCheck = $only !== null && $fs->exists("$folder/content-check.json") ? json_decode((string) $fs->get("$folder/content-check.json"), true) : null;

        $this->status($fs, $folder, ['status' => 'processing', 'started_at' => now()->toIso8601String(), 'provider' => $provider, 'model' => $model, 'pages' => $pages ?? 'all']);

        $local = $this->localFolder($fs, $folder);
        $tmp = null;
        try {
            if ($local === null) {
                $tmp = rtrim(sys_get_temp_dir(), '/') . '/lesson-write-' . bin2hex(random_bytes(6));
                File::ensureDirectoryExists($tmp);
                $work = $tmp;
            } else {
                $work = $local;
            }

            $record = $runner->run($plan, $structure, (array) $pagesJson, $work, $only, $existing, $existingCheck);

            $usageLines = implode("\n", array_map(fn ($c) => json_encode(['step' => 'write'] + $c, JSON_UNESCAPED_SLASHES), $runner->calls())) . "\n";
            if ($tmp !== null) {
                $fs->put("$folder/content.json", (string) file_get_contents("$tmp/content.json"));
                $fs->put("$folder/content-check.json", (string) file_get_contents("$tmp/content-check.json"));
                $fs->put("$folder/usage.jsonl", ($fs->exists("$folder/usage.jsonl") ? $fs->get("$folder/usage.jsonl") : '') . $usageLines);
            } else {
                file_put_contents("$work/usage.jsonl", $usageLines, FILE_APPEND);
            }

            $c = $record['check'];
            $result = new WrittenChapter(
                disk: $disk,
                folder: $folder,
                contentJson: "$folder/content.json",
                checkJson: "$folder/content-check.json",
                passed: (bool) $c['passed'],
                pages: count($record['pages']),
                errorsFirstPass: (int) $c['errors_first_pass'],
                errorsLeft: (int) $c['errors_left'],
                warnings: (int) $c['warnings'],
                stats: (array) $c['stats'],
                usage: (array) $record['usage'],
                durationSeconds: (float) $record['duration_seconds'],
            );
            $this->status($fs, $folder, [
                'status' => $result->passed ? 'completed' : 'completed_with_issues',
                'finished_at' => now()->toIso8601String(),
                'pages' => $result->pages,
                'errors_left' => $result->errorsLeft,
                'warnings' => $result->warnings,
                'usage' => $result->usage,
                'next_step' => 'visuals',
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

    /** "2,3" -> the 2nd and 3rd pages of plan.json; "osmosis" -> pages whose key contains it. */
    private function selectPages(array $plan, ?string $pages): ?array
    {
        if ($pages === null || trim($pages) === '') {
            return null;
        }
        $keys = array_column($plan['pages'], 'key');
        $out = [];
        foreach (preg_split('/\s*,\s*/', trim($pages)) ?: [] as $p) {
            if (ctype_digit($p) && isset($keys[(int) $p - 1])) {
                $out[] = $keys[(int) $p - 1];
            } else {
                foreach ($keys as $k) {
                    if ($p !== '' && str_contains($k, $p)) {
                        $out[] = $k;
                    }
                }
            }
        }
        if (!$out) {
            throw new RuntimeException("No page matches --pages=$pages. Pages: " . implode(', ', array_map(fn ($i, $k) => ($i + 1) . '=' . basename($k), array_keys($keys), $keys)));
        }
        return array_values(array_unique($out));
    }

    private function status(Filesystem $fs, string $folder, array $data): void
    {
        $path = "$folder/status.json";
        $current = $fs->exists($path) ? (json_decode((string) $fs->get($path), true) ?: []) : [];
        $previous = ($data['status'] ?? '') === 'processing' ? [] : (array) ($current['write'] ?? []);
        $current['write'] = array_merge($previous, $data);
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
