<?php

declare(strict_types=1);

namespace App\Services\Lessons\Eval;

use App\Services\Lessons\Plan\PlanChapterService;
use App\Services\Lessons\Structure\StructureChapterService;
use App\Services\Lessons\Visuals\VisualsChapterService;
use App\Services\Lessons\Write\WriteChapterService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The test set. A case is a FROZEN copy of one chapter's inputs and good outputs, so every model is judged
 * on exactly the same input:
 *
 *   {root}/cases/{name}/  pages.json, images_ai/, structure.json, plan.json, case.json (the expectations)
 *   {root}/runs/{run}/{name}/{step}/   a scratch copy where the step is run
 *   {root}/runs/{run}/report.json      results of one eval run
 *   {root}/history.jsonl               one line per run and step, to compare models over time
 *
 * Each step runs on the frozen input of the step before it (write uses the case's plan.json, not a new one).
 */
final class EvalSuite
{
    public const STEPS = ['structure', 'plan', 'write', 'visuals'];

    /** Which files each step reads and writes. */
    private const INPUTS = [
        'structure' => ['pages.json', 'images_ai'],
        'plan' => ['structure.json', 'pages.json'],
        'write' => ['plan.json', 'structure.json', 'pages.json'],
        'visuals' => ['plan.json', 'content.json', 'structure.json'],
    ];
    private const OUTPUT = ['structure' => 'structure.json', 'plan' => 'plan.json', 'write' => 'content.json', 'visuals' => 'visuals.json'];

    public function __construct(private readonly string $disk, private readonly string $root = 'lessons-eval')
    {
    }

    /** Freeze a chapter folder as a test case, with expectations taken from its current (good) outputs. */
    public function add(string $folder, string $name): array
    {
        $fs = Storage::disk($this->disk);
        $folder = trim($folder, '/');
        $dir = "{$this->root}/cases/$name";
        if (!$fs->exists("$folder/pages.json")) {
            throw new RuntimeException("$folder has no pages.json.");
        }
        foreach (['pages.json', 'structure.json', 'plan.json', 'content.json'] as $f) {
            if ($fs->exists("$folder/$f")) {
                $fs->put("$dir/$f", (string) $fs->get("$folder/$f"));
            }
        }
        foreach ($fs->files("$folder/images_ai") as $img) {
            $fs->put("$dir/images_ai/" . basename($img), (string) $fs->get($img));
        }

        $case = ['name' => $name, 'created_at' => date('c'), 'source_folder' => $folder, 'steps' => []];
        $structure = $fs->exists("$dir/structure.json") ? json_decode((string) $fs->get("$dir/structure.json"), true) : null;
        $plan = $fs->exists("$dir/plan.json") ? json_decode((string) $fs->get("$dir/plan.json"), true) : null;

        if ($structure) {
            $st = $structure['check']['stats'] ?? [];
            $exp = [['file' => 'structure.json', 'path' => 'check.passed', 'op' => '==', 'value' => true, 'why' => 'structure passes its check']];
            foreach (['figures', 'examples', 'exercises', 'questions', 'question_parts', 'activities'] as $k) {
                if (!empty($st[$k])) {
                    $exp[] = ['file' => 'structure.json', 'path' => "check.stats.$k", 'op' => '==', 'value' => $st[$k], 'why' => "finds all $st[$k] $k"];
                }
            }
            $case['steps']['structure'] = ['expect' => $exp];
        }
        if ($plan) {
            $exp = [
                ['file' => 'plan.json', 'path' => 'check.passed', 'op' => '==', 'value' => true, 'why' => 'plan passes its check'],
                ['file' => 'plan.json', 'path' => 'pages', 'op' => 'count==', 'value' => count($plan['pages']), 'why' => 'same number of pages'],
            ];
            foreach ($plan['pages'] as $p) {
                $exp[] = ['file' => 'plan.json', 'path' => 'pages.*.key', 'op' => 'contains', 'value' => $p['key'], 'why' => "has page {$p['key']}"];
            }
            $case['steps']['plan'] = ['expect' => $exp];
            $case['steps']['write'] = ['expect' => [
                ['file' => 'content.json', 'path' => 'check.passed', 'op' => '==', 'value' => true, 'why' => 'content passes its check'],
                ['file' => 'content.json', 'path' => 'pages', 'op' => 'count==', 'value' => count($plan['pages']), 'why' => 'every page written'],
            ]];
        }
        $content = $fs->exists("$dir/content.json") ? json_decode((string) $fs->get("$dir/content.json"), true) : null;
        if ($content && $plan) {
            $case['steps']['visuals'] = ['expect' => [
                ['file' => 'visuals.json', 'path' => 'check.passed', 'op' => '==', 'value' => true, 'why' => 'scenes pass their check'],
                ['file' => 'visuals.json', 'path' => 'pages', 'op' => 'count==', 'value' => count($plan['pages']), 'why' => 'every page has its scenes'],
            ]];
        }
        // Re-freezing a chapter keeps the expectations you already edited; only new steps are added.
        if ($fs->exists("$dir/case.json")) {
            $old = json_decode((string) $fs->get("$dir/case.json"), true) ?: [];
            foreach ((array) ($old['steps'] ?? []) as $step => $s) {
                $case['steps'][$step] = $s;
            }
            $case['created_at'] = $old['created_at'] ?? $case['created_at'];
            $case['updated_at'] = date('c');
        }
        $fs->put("$dir/case.json", json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $case;
    }

    /** @return string[] */
    public function cases(): array
    {
        $fs = Storage::disk($this->disk);
        $out = [];
        foreach ($fs->directories("{$this->root}/cases") as $d) {
            if ($fs->exists("$d/case.json")) {
                $out[] = basename($d);
            }
        }
        sort($out);
        return $out;
    }

    /**
     * @param string[] $cases
     * @param string[] $steps
     */
    public function run(array $cases, array $steps, ?string $provider, ?string $model, ?callable $log = null): array
    {
        $fs = Storage::disk($this->disk);
        $runId = date('Ymd-His') . '-' . preg_replace('/[^a-z0-9.]+/i', '-', $model ?: 'default');
        $results = [];
        foreach ($cases as $name) {
            $dir = "{$this->root}/cases/$name";
            $case = json_decode((string) $fs->get("$dir/case.json"), true);
            foreach ($steps as $step) {
                if (!isset($case['steps'][$step])) {
                    $log && $log("$name: no $step expectations in case.json, skipped");
                    continue;
                }
                $missing = array_filter(self::INPUTS[$step], fn ($f) => !$fs->exists("$dir/$f") && !$fs->files("$dir/$f"));
                if ($missing) {
                    $log && $log("$name: cannot test $step, case has no " . implode(', ', $missing));
                    continue;
                }
                $work = "{$this->root}/runs/$runId/$name/$step";
                foreach (self::INPUTS[$step] as $f) {
                    if ($f === 'images_ai') {
                        foreach ($fs->files("$dir/images_ai") as $img) {
                            $fs->put("$work/images_ai/" . basename($img), (string) $fs->get($img));
                        }
                    } else {
                        $fs->put("$work/$f", (string) $fs->get("$dir/$f"));
                    }
                }
                $log && $log("$name: running {$step}…");
                $t0 = microtime(true);
                $error = null;
                try {
                    $quiet = fn (string $m) => $log && $log("  $m");
                    match ($step) {
                        'structure' => app(StructureChapterService::class)->structure($this->disk, $work, null, $provider, $model, $quiet),
                        'plan' => app(PlanChapterService::class)->plan($this->disk, $work, $provider, $model, $quiet),
                        'write' => app(WriteChapterService::class)->write($this->disk, $work, null, $provider, $model, $quiet, true),
                        'visuals' => app(VisualsChapterService::class)->visuals($this->disk, $work, null, $provider, $model, $quiet, true),
                    };
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
                $out = $fs->exists("$work/" . self::OUTPUT[$step]) ? json_decode((string) $fs->get("$work/" . self::OUTPUT[$step]), true) : null;
                $exp = (new Expectations())->evaluate($case['steps'][$step]['expect'], $out ? [self::OUTPUT[$step] => $out] : []);
                $u = $out['usage'] ?? [];
                $results[] = [
                    'case' => $name,
                    'step' => $step,
                    'error' => $error,
                    'check_passed' => (bool) ($out['check']['passed'] ?? false),
                    'errors_first_pass' => (int) ($out['check']['errors_first_pass'] ?? 0),
                    'expect_passed' => $exp['passed'],
                    'expect_total' => $exp['total'],
                    'failures' => $exp['failures'],
                    'calls' => (int) ($u['calls'] ?? 0),
                    'failed_calls' => (int) ($u['failed_calls'] ?? 0),
                    'input_tokens' => (int) ($u['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($u['output_tokens'] ?? 0),
                    'cost_usd' => $u['cost_usd'] ?? null,
                    'seconds' => round(microtime(true) - $t0, 1),
                    'model' => $out['model'] ?? $model,
                    'provider' => $out['provider'] ?? $provider,
                ];
            }
        }

        $report = ['run' => $runId, 'at' => date('c'), 'provider' => $provider, 'model' => $model, 'results' => $results, 'summary' => $this->summary($results)];
        $fs->put("{$this->root}/runs/$runId/report.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $hist = $fs->exists("{$this->root}/history.jsonl") ? (string) $fs->get("{$this->root}/history.jsonl") : '';
        foreach ($report['summary'] as $step => $s) {
            $hist .= json_encode(['run' => $runId, 'at' => $report['at'], 'step' => $step] + $s, JSON_UNESCAPED_SLASHES) . "\n";
        }
        $fs->put("{$this->root}/history.jsonl", $hist);
        return $report;
    }

    /** Per step: how often it was right first time, after repair, and what it cost. */
    public function summary(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $s = &$out[$r['step']];
            $s ??= ['model' => $r['model'], 'provider' => $r['provider'], 'cases' => 0, 'crashed' => 0, 'checks_passed' => 0, 'clean_first_pass' => 0, 'expect_passed' => 0, 'expect_total' => 0, 'calls' => 0, 'failed_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0, 'cost_known' => true, 'seconds' => 0.0];
            $s['cases']++;
            $s['crashed'] += $r['error'] ? 1 : 0;
            $s['checks_passed'] += $r['check_passed'] ? 1 : 0;
            $s['clean_first_pass'] += (!$r['error'] && $r['errors_first_pass'] === 0) ? 1 : 0;
            foreach (['expect_passed', 'expect_total', 'calls', 'failed_calls', 'input_tokens', 'output_tokens', 'seconds'] as $k) {
                $s[$k] += $r[$k];
            }
            if ($r['cost_usd'] === null && $r['input_tokens'] > 0) {
                $s['cost_known'] = false;
            }
            $s['cost_usd'] += (float) $r['cost_usd'];
            unset($s);
        }
        foreach ($out as &$s) {
            $s['cost_usd'] = $s['cost_known'] ? round($s['cost_usd'], 4) : null;
            unset($s['cost_known']);
            $s['seconds'] = round($s['seconds'], 1);
        }
        return $out;
    }

    public function history(): array
    {
        $fs = Storage::disk($this->disk);
        if (!$fs->exists("{$this->root}/history.jsonl")) {
            return [];
        }
        return array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", (string) $fs->get("{$this->root}/history.jsonl")))));
    }
}
