<?php

declare(strict_types=1);

namespace App\Services\Lessons\Plan;

use App\Services\Lessons\ModelReply;
use Throwable;

/**
 * Step 3 — Plan, in plain PHP (no Laravel). The service wraps it.
 *
 *   1. PageSplitter cuts the chapter into pages (code, no AI).
 *   2. The model plans a few pages per call (blocks, visuals, practice).
 *   3. PlanChecker checks coverage and order; failing pages go back to the model (repair rounds).
 *
 * Writes, in the work folder:
 *   plan.json        the page plan (schema plan.v1)
 *   plan-check.json  the check report
 */
final class PlanRunner
{
    private array $calls = [];

    public function __construct(
        private readonly PlanDesigner $designer,
        private readonly PageSplitter $splitter,
        private readonly int $pagesPerCall = 4,
        private readonly int $maxItemsPerCall = 80,
        private readonly int $maxJsonRetries = 2,
        private readonly int $maxRepairRounds = 2,
        private readonly bool $reviewRequired = false,
        private readonly array $prices = [],
        private $log = null,
    ) {
    }

    public function calls(): array
    {
        return $this->calls;
    }

    public function run(array $structure, string $workDir): array
    {
        $t0 = microtime(true);
        $this->calls = [];
        $itemsById = [];
        foreach ($structure['items'] ?? [] as $it) {
            $itemsById[$it['id']] = $it;
        }
        if (!$itemsById) {
            throw new \RuntimeException('structure.json has no items.');
        }
        $source = (string) ($structure['source_pdf'] ?? 'chapter.pdf');

        // 1. Pages (no AI).
        $split = $this->splitter->split($structure);
        $outline = $split['pages'];
        $this->say(count($outline) . ' pages in ' . count($split['parts']) . ' part(s): ' . implode(', ', array_map(fn ($p) => $p['file'], $outline)));

        // 2. Plan in batches.
        $plans = [];
        foreach ($this->batches($outline) as $batch) {
            $got = $this->designBatch($source, $outline, $batch, $itemsById, null, [], 'plan');
            $plans = array_merge($plans, $got);
        }

        // 3. Check, then repair the pages with errors.
        $checker = new PlanChecker();
        $check = $checker->check($outline, $plans, $itemsById);
        $firstErrors = count($check['errors']);
        $history = $check['errors'] ? [['round' => 0, 'errors' => $check['errors']]] : [];
        for ($round = 1; $round <= $this->maxRepairRounds && $check['errors']; $round++) {
            $bad = array_values(array_filter($outline, fn ($p) => isset($check['by_page'][$p['key']])));
            $this->say("Repair round $round: " . count($check['errors']) . ' problem(s) on ' . count($bad) . ' page(s)');
            foreach ($this->batches($bad) as $batch) {
                $feedback = [];
                foreach ($batch as $p) {
                    foreach ($check['by_page'][$p['key']] ?? [] as $m) {
                        $feedback[] = "- $m";
                    }
                }
                $previous = array_intersect_key($plans, array_flip(array_column($batch, 'key')));
                $got = $this->designBatch($source, $outline, $batch, $itemsById, implode("\n", $feedback), $previous, "repair$round");
                foreach ($got as $key => $plan) {
                    $page = array_values(array_filter($outline, fn ($p) => $p['key'] === $key));
                    $old = isset($plans[$key]) ? count($checker->check($page, [$key => $plans[$key]], $itemsById)['errors']) : PHP_INT_MAX;
                    $new = count($checker->check($page, [$key => $plan], $itemsById)['errors']);
                    if ($new <= $old) {
                        $plans[$key] = $plan;   // keep the better one
                    }
                }
            }
            $check = $checker->check($outline, $plans, $itemsById);
            if ($check['errors']) {
                $history[] = ['round' => $round, 'errors' => $check['errors']];
            }
        }

        // 4. Merge outline (code) + plans (model) into plan.json.
        $pages = [];
        foreach ($outline as $p) {
            $plan = $plans[$p['key']] ?? ['title' => null, 'summary' => null, 'objectives' => [], 'opening' => null, 'blocks' => [], 'additions' => []];
            $pages[] = [
                'key' => $p['key'],
                'folder' => $p['folder'],
                'file' => $p['file'],
                'kind' => $p['kind'],
                'title' => $plan['title'] ?: $p['title'],
                'book_title' => $p['title'],
                'section_labels' => $p['section_labels'],
                'pdf_pages' => $p['pdf_pages'],
                'book_pages' => $p['book_pages'],
                'items' => $p['items'],
                'prev' => $p['prev'],
                'next' => $p['next'],
                'summary' => $plan['summary'],
                'objectives' => $plan['objectives'],
                'opening' => $p['kind'] === 'welcome' ? $plan['opening'] : null,
                'blocks' => $plan['blocks'],
                'additions' => $plan['additions'],
            ];
        }
        $titles = array_column($pages, 'title', 'key');
        foreach ($pages as $i => &$pg) {
            foreach (['prev' => $i - 1, 'next' => $i + 1] as $dir => $j) {
                if ($pg[$dir] !== null && isset($pages[$j])) {
                    $pg[$dir]['title'] = $titles[$pages[$j]['key']];
                }
            }
        }
        unset($pg);

        $usage = $this->usage();
        $record = [
            'schema' => 'plan.v1',
            'source_pdf' => $source,
            'structure_created_at' => $structure['created_at'] ?? null,
            'created_at' => date('c'),
            'duration_seconds' => round(microtime(true) - $t0, 1),
            'provider' => $this->calls[0]['provider'] ?? null,
            'model' => $this->calls[0]['model'] ?? null,
            'review' => ['required' => $this->reviewRequired, 'approved' => !$this->reviewRequired, 'approved_at' => null, 'approved_by' => null, 'note' => null],
            'check' => [
                'passed' => !$check['errors'],
                'errors_first_pass' => $firstErrors,
                'errors_left' => count($check['errors']),
                'warnings' => count($check['warnings']),
                'stats' => $check['stats'],
            ],
            'usage' => $usage,
            'parts' => $split['parts'],
            'pages' => $pages,
        ];

        $this->write("$workDir/plan.json", $record);
        $this->write("$workDir/plan-check.json", [
            'passed' => !$check['errors'],
            'errors_first_pass' => $firstErrors,
            'errors_left' => $check['errors'],
            'errors_by_round' => $history,   // what the model got wrong before repair (round 0 = first pass)
            'warnings' => $check['warnings'],
            'stats' => $check['stats'],
            'calls' => $this->calls,
        ]);
        return $record;
    }

    /** Pages per call, also capped by item count so big sections get a call of their own. */
    private function batches(array $pages): array
    {
        $out = [];
        $cur = [];
        $n = 0;
        foreach ($pages as $p) {
            $c = count($p['items']);
            // Two parts both have 00-welcome.html: keep same-named pages in different calls,
            // so a reply that gives only the file name still points at one page.
            $clash = in_array($p['file'], array_column($cur, 'file'), true);
            if ($cur && ($clash || count($cur) >= $this->pagesPerCall || $n + $c > $this->maxItemsPerCall)) {
                $out[] = $cur;
                $cur = [];
                $n = 0;
            }
            $cur[] = $p;
            $n += $c;
        }
        if ($cur) {
            $out[] = $cur;
        }
        return $out;
    }

    /** One call (with retries for bad JSON). Returns page key => plan for the pages it got. */
    private function designBatch(string $source, array $outline, array $batch, array $itemsById, ?string $feedback, array $previous, string $phase): array
    {
        $keys = array_column($batch, 'key');
        $label = implode(', ', array_map(fn ($p) => $p['file'], $batch));
        $fb = $feedback;
        $best = [];
        for ($attempt = 0; $attempt <= $this->maxJsonRetries; $attempt++) {
            $prompt = PlanPromptBuilder::build($source, $outline, $batch, $itemsById, $fb, $previous);
            $t0 = microtime(true);
            try {
                $reply = $this->designer->design($prompt);
            } catch (Throwable $e) {
                $this->logCall($phase, $keys, $attempt, null, false, $e->getMessage(), $t0, 0);
                $this->say("$label: attempt $attempt failed: " . $e->getMessage());
                if ($attempt < $this->maxJsonRetries) {
                    sleep(min(10, 2 ** $attempt));
                }
                continue;
            }
            $parsed = (new PlanValidator())->parse($reply['text'], $keys);
            if ($cutOff = ModelReply::truncation($reply)) {
                array_unshift($parsed['errors'], $cutOff);
            }
            $this->logCall($phase, $keys, $attempt, $reply, !$parsed['errors'], $parsed['errors'] ? implode(' | ', array_slice($parsed['errors'], 0, 5)) : null, $t0, count($parsed['pages']));
            $best = $parsed['pages'] + $best;
            if (!$parsed['errors']) {
                $this->say("$label: $phase ok");
                return $this->tidyAll($best, $itemsById);
            }
            $this->say("$label: reply had problems, asking again (" . implode(' | ', array_slice($parsed['errors'], 0, 3)) . ')');
            $fb = trim(($feedback ? $feedback . "\n" : '') . "Your last reply had these problems:\n- " . implode("\n- ", array_slice($parsed['errors'], 0, 15)));
            $previous = $best + $previous;
        }
        return $this->tidyAll($best, $itemsById);   // whatever was usable; the checker reports the rest
    }

    /**
     * Fixes the code can make without asking the model again (same result for every model):
     *  - an "example" block with no book worked example in it is a step-by-step "walk", not a worked example.
     */
    private function tidyAll(array $plans, array $itemsById): array
    {
        foreach ($plans as &$plan) {
            foreach ($plan['blocks'] as &$b) {
                if ($b['type'] === 'example') {
                    $hasExample = false;
                    foreach ($b['items'] as $id) {
                        if (in_array($itemsById[$id]['type'] ?? '', ['example', 'example_part'], true)) {
                            $hasExample = true;
                            break;
                        }
                    }
                    if (!$hasExample) {
                        $b['type'] = $b['visual']['mode'] === 'none' ? 'notes' : 'walk';
                        $b['retyped_from'] = 'example';
                    }
                }
            }
            unset($b);
        }
        unset($plan);
        return $plans;
    }

    private function logCall(string $phase, array $pages, int $attempt, ?array $reply, bool $ok, ?string $error, float $t0, int $count): void
    {
        $in = (int) ($reply['input_tokens'] ?? 0);
        $out = (int) ($reply['output_tokens'] ?? 0);
        $model = (string) ($reply['model'] ?? '');
        $price = $this->prices[$model] ?? null;
        $this->calls[] = [
            'at' => date('c'),
            'phase' => $phase,
            'pages' => $pages,
            'attempt' => $attempt,
            'ok' => $ok,
            'planned' => $count,
            'error' => $error,
            'provider' => $reply['provider'] ?? null,
            'model' => $model ?: null,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cost_usd' => $price ? round(($in * $price[0] + $out * $price[1]) / 1e6, 5) : null,
            'seconds' => round(microtime(true) - $t0, 1),
        ] + ModelReply::logFields($reply, $ok);
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
