<?php

declare(strict_types=1);

namespace App\Services\Lessons\Build;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Laravel wrapper for Step 6 (Build). No AI, no cost.
 *
 * Reads   {folder}/plan.json, content.json, visuals.json      (Steps 3–5)
 * Writes  {folder}/build/ (index.html, assets/, one folder per part, manifest.json)
 * Updates {folder}/status.json under "build"
 */
final class BuildChapterService
{
    public function build(string $disk, string $folder, bool $force = false): BuiltChapter
    {
        $fs = Storage::disk($disk);
        $folder = trim($folder, '/');
        foreach (['plan.json' => 'Step 3 (lessons:plan)', 'content.json' => 'Step 4 (lessons:write)', 'visuals.json' => 'Step 5 (lessons:visuals)'] as $f => $step) {
            if (!$fs->exists("$folder/$f")) {
                throw new RuntimeException("No $f in $folder on disk '$disk'. Run $step first.");
            }
        }
        $plan = json_decode((string) $fs->get("$folder/plan.json"), true);
        $content = json_decode((string) $fs->get("$folder/content.json"), true);
        $visuals = json_decode((string) $fs->get("$folder/visuals.json"), true);
        if (!$force) {
            if (empty($content['check']['passed'])) {
                throw new RuntimeException("content.json in $folder did not pass its check. Fix it first (or use --force).");
            }
            if (empty($visuals['check']['passed'])) {
                throw new RuntimeException("visuals.json in $folder did not pass its check (or some pages are not done). Run lessons:visuals for the whole chapter first (or use --force).");
            }
        }

        $this->status($fs, $folder, ['status' => 'processing', 'started_at' => now()->toIso8601String()]);
        try {
            $engine = (string) config('lessons.build.engine_path', config('lessons.visuals.engine_path', resource_path('lessons/engine')));
            $out = (new BuildRunner())->run($plan, $content, $visuals, $engine);
            $dir = "$folder/" . trim((string) config('lessons.build.dir', 'build'), '/');
            foreach ($out['files'] as $rel => $data) {
                $fs->put("$dir/$rel", $data);
            }
            $m = $out['manifest'];
            $fs->put("$folder/build-check.json", json_encode($m['check'] + ['stats' => $m['stats']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $result = new BuiltChapter(
                disk: $disk, folder: $folder, buildDir: $dir, index: "$dir/index.html",
                passed: (bool) $m['check']['passed'], pages: (int) $m['stats']['pages'], pagesPlanned: (int) $m['stats']['pages_planned'],
                scenes: (int) $m['stats']['scenes'], questions: (int) $m['stats']['questions'],
                errors: $m['check']['errors'], warnings: $m['check']['warnings'], durationSeconds: (float) $m['duration_seconds'],
            );
            $this->status($fs, $folder, [
                'status' => $result->passed ? 'completed' : 'completed_with_issues',
                'finished_at' => now()->toIso8601String(),
                'pages' => $result->pages, 'errors' => count($result->errors), 'warnings' => count($result->warnings),
                'index' => $result->index, 'next_step' => 'test',
            ]);
            return $result;
        } catch (Throwable $e) {
            $this->markFailed($disk, $folder, $e);
            throw $e;
        }
    }

    public function markFailed(string $disk, string $folder, Throwable $e): void
    {
        try {
            $this->status(Storage::disk($disk), trim($folder, '/'), ['status' => 'failed', 'failed_at' => now()->toIso8601String(), 'error' => $e->getMessage()]);
        } catch (Throwable) {
        }
    }

    private function status(Filesystem $fs, string $folder, array $data): void
    {
        $path = "$folder/status.json";
        $current = $fs->exists($path) ? (json_decode((string) $fs->get($path), true) ?: []) : [];
        $previous = ($data['status'] ?? '') === 'processing' ? [] : (array) ($current['build'] ?? []);
        $current['build'] = array_merge($previous, $data);
        $fs->put($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
