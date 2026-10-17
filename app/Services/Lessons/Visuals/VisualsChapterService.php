<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Laravel wrapper for Step 5 (Visuals).
 *
 * Reads   {folder}/plan.json, content.json, structure.json      (Steps 2–4)
 * Writes  {folder}/visuals.json, visuals-check.json, preview/…, usage.jsonl
 * Updates {folder}/status.json under "visuals"
 */
final class VisualsChapterService
{
    public function __construct(private readonly ?SceneDesigner $designer = null)
    {
    }

    public function visuals(string $disk, string $folder, ?string $pages = null, ?string $provider = null, ?string $model = null, ?callable $log = null, bool $force = false): VisualizedChapter
    {
        $fs = Storage::disk($disk);
        $folder = trim($folder, '/');
        foreach (['plan.json' => 'Step 3 (lessons:plan)', 'content.json' => 'Step 4 (lessons:write)', 'structure.json' => 'Step 2 (lessons:structure)'] as $f => $step) {
            if (!$fs->exists("$folder/$f")) {
                throw new RuntimeException("No $f in $folder on disk '$disk'. Run $step first.");
            }
        }
        $plan = json_decode((string) $fs->get("$folder/plan.json"), true);
        $content = json_decode((string) $fs->get("$folder/content.json"), true);
        $structure = json_decode((string) $fs->get("$folder/structure.json"), true);
        if (!$force && empty($content['check']['passed'])) {
            throw new RuntimeException("content.json in $folder did not pass its check (see content-check.json). Fix it first (or use --force).");
        }

        $cfg = (array) config('lessons.visuals', []);
        $provider ??= (string) ($cfg['provider'] ?? 'qwen');
        $model ??= (string) ($cfg['model'] ?? 'qwen3.8-flash');
        $runner = new VisualsRunner(
            designer: $this->designer ?? new LaravelAiSceneDesigner($provider, $model, (int) ($cfg['call_timeout'] ?? 300)),
            maxJsonRetries: (int) ($cfg['max_json_retries'] ?? 2),
            maxRepairRounds: (int) ($cfg['max_repair_rounds'] ?? 2),
            prices: (array) ($cfg['prices'] ?? []),
            log: $log ?? fn (string $m) => Log::info("Lessons visuals [$folder]: $m"),
        );
        $only = $this->selectPages($plan, $pages);
        $existing = $only !== null && $fs->exists("$folder/visuals.json") ? json_decode((string) $fs->get("$folder/visuals.json"), true) : null;
        $existingCheck = $only !== null && $fs->exists("$folder/visuals-check.json") ? json_decode((string) $fs->get("$folder/visuals-check.json"), true) : null;

        $this->status($fs, $folder, ['status' => 'processing', 'started_at' => now()->toIso8601String(), 'provider' => $provider, 'model' => $model, 'pages' => $pages ?? 'all']);
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/lesson-visuals-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($tmp);
        try {
            $record = $runner->run($plan, $content, $structure, $tmp, $only, $existing, $existingCheck);
            $fs->put("$folder/visuals.json", (string) file_get_contents("$tmp/visuals.json"));
            $fs->put("$folder/visuals-check.json", (string) file_get_contents("$tmp/visuals-check.json"));
            $usageLines = implode("\n", array_map(fn ($c) => json_encode(['step' => 'visuals'] + $c, JSON_UNESCAPED_SLASHES), $runner->calls())) . "\n";
            $fs->put("$folder/usage.jsonl", ($fs->exists("$folder/usage.jsonl") ? $fs->get("$folder/usage.jsonl") : '') . $usageLines);
            $this->writePreview($fs, $folder, $plan, $content, $record);

            $c = $record['check'];
            $result = new VisualizedChapter(
                disk: $disk, folder: $folder,
                visualsJson: "$folder/visuals.json", checkJson: "$folder/visuals-check.json", previewIndex: "$folder/preview/index.html",
                passed: (bool) $c['passed'], pages: count($record['pages']),
                errorsFirstPass: (int) $c['errors_first_pass'], errorsLeft: (int) $c['errors_left'], warnings: (int) $c['warnings'],
                stats: (array) $c['stats'], usage: (array) $record['usage'], durationSeconds: (float) $record['duration_seconds'],
            );
            $this->status($fs, $folder, [
                'status' => $result->passed ? 'completed' : 'completed_with_issues',
                'finished_at' => now()->toIso8601String(),
                'errors_left' => $result->errorsLeft, 'warnings' => $result->warnings, 'usage' => $result->usage,
                'preview' => $result->previewIndex, 'next_step' => 'build',
            ]);
            return $result;
        } catch (Throwable $e) {
            $this->markFailed($disk, $folder, $e);
            throw $e;
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    /** Rebuild only the preview pages from the existing visuals.json (no AI). */
    public function preview(string $disk, string $folder): string
    {
        $fs = Storage::disk($disk);
        $folder = trim($folder, '/');
        foreach (['plan.json', 'content.json', 'visuals.json'] as $f) {
            if (!$fs->exists("$folder/$f")) {
                throw new RuntimeException("No $f in $folder.");
            }
        }
        $this->writePreview($fs, $folder,
            json_decode((string) $fs->get("$folder/plan.json"), true),
            json_decode((string) $fs->get("$folder/content.json"), true),
            json_decode((string) $fs->get("$folder/visuals.json"), true));
        return "$folder/preview/index.html";
    }

    private function writePreview(Filesystem $fs, string $folder, array $plan, array $content, array $visuals): void
    {
        foreach ((new PreviewBuilder())->build($plan, $content, $visuals) as $rel => $html) {
            $fs->put("$folder/$rel", $html);
        }
        $src = (string) config('lessons.visuals.engine_path', resource_path('lessons/engine'));
        foreach (PreviewBuilder::ASSETS as $a) {
            if (is_file("$src/$a")) {
                $fs->put("$folder/preview/assets/$a", (string) file_get_contents("$src/$a"));
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
        $previous = ($data['status'] ?? '') === 'processing' ? [] : (array) ($current['visuals'] ?? []);
        $current['visuals'] = array_merge($previous, $data);
        $fs->put($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
