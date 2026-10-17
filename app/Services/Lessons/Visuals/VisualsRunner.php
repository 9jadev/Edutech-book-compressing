<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

use Throwable;

/**
 * Step 5 — Visuals, in plain PHP (no Laravel). One call per lesson page that has visuals; then SceneValidator
 * (shape, prefabs expanded) and SceneChecker (rules); a page with errors goes back with the problems listed.
 *
 * Writes in the work folder:
 *   visuals.json        every scene, ready for the engine (schema visuals.v1)
 *   visuals-check.json  errors per page and round, warnings, every AI call
 */
final class VisualsRunner
{
    private array $calls = [];

    public function __construct(
        private readonly SceneDesigner $designer,
        private readonly int $maxJsonRetries = 2,
        private readonly int $maxRepairRounds = 2,
        private readonly array $prices = [],
        private $log = null,
    ) {
    }

    public function calls(): array
    {
        return $this->calls;
    }

    public function run(array $plan, array $content, array $structure, string $workDir, ?array $only = null, ?array $existing = null, ?array $existingCheck = null): array
    {
        $t0 = microtime(true);
        $this->calls = [];
        $itemsById = [];
        foreach ($structure['items'] ?? [] as $it) {
            $itemsById[$it['id']] = $it;
        }
        $cpages = [];
        foreach ($content['pages'] ?? [] as $p) {
            $cpages[$p['key']] = $p;
        }
        $old = [];
        foreach ($existing['pages'] ?? [] as $p) {
            $old[$p['key']] = $p;
        }
        $checker = new SceneChecker();
        $pages = [];
        $report = [];

        foreach ($plan['pages'] as $pg) {
            $pg = $this->tidy($pg, $itemsById);
            $key = $pg['key'];
            $need = VisualsBrief::blocksNeeding($pg);
            if ($only !== null && !in_array($key, $only, true)) {
                if (isset($old[$key])) {
                    $pages[] = $old[$key];
                    $report[$key] = $existingCheck['pages'][$key] ?? ['errors_left' => [], 'warnings' => [], 'errors_by_round' => [], 'stats' => []];
                }
                continue;
            }
            if (!$need) {
                $pages[] = ['key' => $key, 'folder' => $pg['folder'], 'file' => $pg['file'], 'scenes' => new \stdClass()];
                $report[$key] = ['errors_left' => [], 'warnings' => [], 'errors_by_round' => [], 'stats' => ['scenes' => 0]];
                continue;
            }
            $cp = $cpages[$key] ?? null;
            $best = null;
            $bestErrors = null;
            $bestResult = ['warnings' => [], 'stats' => []];
            $history = [];
            $feedback = null;
            for ($round = 0; $round <= $this->maxRepairRounds; $round++) {
                $prompt = VisualsBrief::build($pg, $cp, $itemsById, $feedback, $best);
                $got = $this->designPage($prompt, $pg, $need, $round === 0 ? 'design' : "repair$round");
                if ($got === null) {
                    break;
                }
                [$scenes, $shapeErrors] = $got;
                if ($best !== null) {
                    $scenes += $best;   // keep scenes a repair reply left out
                }
                $result = $checker->check($pg, $cp, $scenes, $itemsById);
                $errors = array_values(array_unique(array_merge($shapeErrors, $result['errors'])));
                if ($best === null || count($errors) <= count($bestErrors)) {
                    $best = $scenes;
                    $bestErrors = $errors;
                    $bestResult = $result;
                }
                if (!$errors) {
                    break;
                }
                $history[] = ['round' => $round, 'errors' => $errors];
                $this->say("{$pg['file']}: " . count($errors) . ' problem(s)' . ($round < $this->maxRepairRounds ? ', asking for a fix' : ', giving up'));
                $feedback = '- ' . implode("\n- ", array_slice($bestErrors, 0, 25));
            }
            if ($best === null) {
                $report[$key] = ['errors_left' => ['No usable reply from the model for this page.'], 'warnings' => [], 'errors_by_round' => $history, 'stats' => []];
                continue;
            }
            $this->say("{$pg['file']}: " . ($bestErrors ? count($bestErrors) . ' problem(s) left' : 'ok') . ', ' . count($best) . ' scene(s), ' . ($bestResult['stats']['parts'] ?? 0) . ' parts');
            $pages[] = ['key' => $key, 'folder' => $pg['folder'], 'file' => $pg['file'], 'scenes' => array_intersect_key($best, array_flip($need))];
            $report[$key] = ['errors_left' => $bestErrors, 'warnings' => $bestResult['warnings'], 'errors_by_round' => $history, 'stats' => $bestResult['stats']];
        }

        $order = array_flip(array_column($plan['pages'], 'key'));
        usort($pages, fn ($a, $b) => ($order[$a['key']] ?? 0) <=> ($order[$b['key']] ?? 0));
        $errorsLeft = array_sum(array_map(fn ($r) => count($r['errors_left']), $report));
        $stats = ['pages' => count($pages), 'pages_total' => count($plan['pages']), 'pages_clean_first_time' => count(array_filter($report, fn ($r) => !$r['errors_by_round'] && !$r['errors_left']))];
        foreach ($report as $r) {
            foreach ($r['stats'] as $k => $v) {
                $stats[$k] = ($stats[$k] ?? 0) + $v;
            }
        }
        $missing = array_values(array_diff(array_column($plan['pages'], 'key'), array_column($pages, 'key')));
        $record = [
            'schema' => 'visuals.v1',
            'source_pdf' => $plan['source_pdf'] ?? null,
            'content_created_at' => $content['created_at'] ?? null,
            'created_at' => date('c'),
            'duration_seconds' => round(microtime(true) - $t0, 1),
            'provider' => $this->calls[0]['provider'] ?? ($existing['provider'] ?? null),
            'model' => $this->calls[0]['model'] ?? ($existing['model'] ?? null),
            'engine' => 'lesson-scene.js',
            'check' => [
                'passed' => $errorsLeft === 0 && !$missing,
                'errors_first_pass' => array_sum(array_map(fn ($r) => count($r['errors_by_round'][0]['errors'] ?? []), $report)),
                'errors_left' => $errorsLeft,   // errors in pages that were done; pages not done yet are in missing_pages
                'warnings' => array_sum(array_map(fn ($r) => count($r['warnings']), $report)),
                'missing_pages' => $missing,
                'stats' => $stats,
            ],
            'usage' => $this->usage(),
            'pages' => $pages,
        ];
        $this->write("$workDir/visuals.json", $record);
        $this->write("$workDir/visuals-check.json", $record['check'] + ['pages' => $report, 'calls' => $this->calls]);
        return $record;
    }

    /** @return array{0:array,1:string[]}|null */
    private function designPage(string $prompt, array $pg, array $need, string $phase): ?array
    {
        $validator = new SceneValidator();
        $p = $prompt;
        for ($attempt = 0; $attempt <= $this->maxJsonRetries; $attempt++) {
            $t0 = microtime(true);
            try {
                $reply = $this->designer->design($p);
            } catch (Throwable $e) {
                $this->logCall($phase, $pg['key'], $attempt, null, false, $e->getMessage(), $t0);
                $this->say("{$pg['file']}: attempt $attempt failed: " . $e->getMessage());
                if ($attempt < $this->maxJsonRetries) {
                    sleep(min(10, 2 ** $attempt));
                }
                continue;
            }
            $parsed = $validator->parse($reply['text'], $need);
            $this->logCall($phase, $pg['key'], $attempt, $reply, $parsed['ok'], $parsed['errors'] ? implode(' | ', array_slice($parsed['errors'], 0, 5)) : null, $t0);
            if ($parsed['ok']) {
                return [$parsed['scenes'], $parsed['errors']];
            }
            $this->say("{$pg['file']}: unreadable reply, asking again (" . implode(' | ', array_slice($parsed['errors'], 0, 2)) . ')');
            $p = $prompt . "\n\nYour last reply could not be used: " . implode(' ', $parsed['errors']) . ' Reply with the JSON object only.';
        }
        return null;
    }

    /** Same code-only fix as Steps 3 and 4: an "example" block without a book worked example is a walk. */
    private function tidy(array $page, array $itemsById): array
    {
        foreach ($page['blocks'] as &$b) {
            if ($b['type'] === 'example' && !array_filter($b['items'], fn ($id) => in_array($itemsById[$id]['type'] ?? '', ['example', 'example_part'], true))) {
                $b['type'] = ($b['visual']['mode'] ?? 'none') === 'none' ? 'notes' : 'walk';
            }
        }
        unset($b);
        return $page;
    }

    private function logCall(string $phase, string $page, int $attempt, ?array $reply, bool $ok, ?string $error, float $t0): void
    {
        $in = (int) ($reply['input_tokens'] ?? 0);
        $out = (int) ($reply['output_tokens'] ?? 0);
        $model = (string) ($reply['model'] ?? '');
        $price = $this->prices[$model] ?? null;
        $this->calls[] = [
            'at' => date('c'), 'phase' => $phase, 'page' => $page, 'attempt' => $attempt, 'ok' => $ok, 'error' => $error,
            'provider' => $reply['provider'] ?? null, 'model' => $model ?: null, 'input_tokens' => $in, 'output_tokens' => $out,
            'cost_usd' => $price ? round(($in * $price[0] + $out * $price[1]) / 1e6, 5) : null, 'seconds' => round(microtime(true) - $t0, 1),
        ];
    }

    private function usage(): array
    {
        $cost = 0.0;
        $known = true;
        foreach ($this->calls as $c) {
            if ($c['cost_usd'] === null && $c['input_tokens'] > 0) {
                $known = false;
            }
            $cost += (float) $c['cost_usd'];
        }
        return [
            'calls' => count($this->calls),
            'failed_calls' => count(array_filter($this->calls, fn ($c) => !$c['ok'])),
            'input_tokens' => array_sum(array_column($this->calls, 'input_tokens')),
            'output_tokens' => array_sum(array_column($this->calls, 'output_tokens')),
            'cost_usd' => $known ? round($cost, 4) : null,
            'note' => 'List price; free-quota tokens are not charged.',
        ];
    }

    private function write(string $path, array $data): void
    {
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);
    }

    private function say(string $m): void
    {
        if ($this->log) {
            ($this->log)($m);
        }
    }
}
