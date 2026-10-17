<?php

declare(strict_types=1);

namespace App\Services\Lessons\Write;

use App\Services\Lessons\ModelReply;
use Throwable;

/**
 * Step 4 — Write, in plain PHP (no Laravel). The service wraps it.
 * One call per lesson page; then ContentValidator (shape) and ContentChecker (rules);
 * a page with errors is sent back with the problems listed (repair rounds), keeping the best version.
 *
 * Writes in the work folder:
 *   content.json        every page's notes, narration and practice (schema content.v1)
 *   content-check.json  the check report: errors per page and round, warnings, every AI call
 */
final class WriteRunner
{
    private array $calls = [];

    public function __construct(
        private readonly PageWriter $writer,
        private readonly int $maxJsonRetries = 2,
        private readonly int $maxRepairRounds = 2,
        private readonly array $headerPatterns = [],
        private readonly array $prices = [],
        private $log = null,
    ) {
    }

    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @param array      $plan      decoded plan.json
     * @param array      $structure decoded structure.json
     * @param array      $pagesJson decoded pages.json
     * @param ?array     $only      page keys to (re)write; others are kept from $existing
     * @param ?array     $existing  decoded content.json from an earlier run
     * @param ?array     $existingCheck decoded content-check.json from that run
     */
    public function run(array $plan, array $structure, array $pagesJson, string $workDir, ?array $only = null, ?array $existing = null, ?array $existingCheck = null): array
    {
        $t0 = microtime(true);
        $this->calls = [];
        $itemsById = [];
        foreach ($structure['items'] ?? [] as $it) {
            $itemsById[$it['id']] = $it;
        }
        $bookPages = [];
        foreach ($pagesJson['pages'] ?? [] as $p) {
            $bookPages[(int) $p['pdf_page']] = $p;
        }
        $oldPages = [];
        $oldChecks = [];
        foreach ($existing['pages'] ?? [] as $p) {
            $oldPages[$p['key']] = $p;
        }
        foreach ($existingCheck['pages'] ?? [] as $k => $c) {
            $oldChecks[$k] = $c;
        }

        $checker = new ContentChecker();
        $pages = [];
        $report = [];
        foreach ($plan['pages'] as $pg) {
            $pg = $this->tidy($pg, $itemsById);
            $key = $pg['key'];
            if ($only !== null && !in_array($key, $only, true)) {
                if (isset($oldPages[$key])) {
                    $pages[] = $oldPages[$key];
                    $report[$key] = $oldChecks[$key] ?? ['errors_left' => [], 'warnings' => [], 'errors_by_round' => [], 'stats' => []];
                }
                continue;
            }
            $bookText = implode("\n", array_map(fn ($n) => PageBrief::clean((string) ($bookPages[$n]['text'] ?? ''), $this->headerPatterns), $pg['pdf_pages']));

            $best = null;
            $bestErrors = null;
            $bestResult = ['warnings' => [], 'stats' => []];
            $history = [];
            $feedback = null;
            for ($round = 0; $round <= $this->maxRepairRounds; $round++) {
                $prompt = PageBrief::build($pg, $itemsById, $bookPages, $this->headerPatterns, $feedback, $best);
                $got = $this->writePage($prompt, $pg, $round === 0 ? 'write' : "repair$round");
                if ($got === null) {
                    break;   // every attempt failed (network or unreadable): asking again with no feedback would not help
                }
                [$content, $shapeErrors] = $got;
                $result = $checker->check($pg, $content, $itemsById, $bookText);
                $errors = array_values(array_unique(array_merge($shapeErrors, $result['errors'])));
                if ($best === null || count($errors) <= count($bestErrors)) {
                    $best = $content;
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
            $this->say("{$pg['file']}: " . ($bestErrors ? count($bestErrors) . ' problem(s) left' : 'ok') . ', ' . ($bestResult['stats']['questions'] ?? 0) . ' questions');
            $pages[] = [
                'key' => $key,
                'folder' => $pg['folder'],
                'file' => $pg['file'],
                'kind' => $pg['kind'],
                'book_pages' => $pg['book_pages'],
                'prev' => $pg['prev'],
                'next' => $pg['next'],
            ] + $best;
            $report[$key] = ['errors_left' => $bestErrors, 'warnings' => $bestResult['warnings'], 'errors_by_round' => $history, 'stats' => $bestResult['stats']];
        }

        // Keep plan order for pages carried over from an earlier run.
        $order = array_flip(array_column($plan['pages'], 'key'));
        usort($pages, fn ($a, $b) => ($order[$a['key']] ?? 0) <=> ($order[$b['key']] ?? 0));

        $errorsLeft = array_sum(array_map(fn ($r) => count($r['errors_left']), $report));
        $firstPass = array_sum(array_map(fn ($r) => count($r['errors_by_round'][0]['errors'] ?? []), $report));
        $stats = ['pages' => count($pages), 'pages_total' => count($plan['pages'])];
        foreach ($report as $r) {
            foreach ($r['stats'] as $k => $v) {
                $stats[$k] = ($stats[$k] ?? 0) + $v;
            }
        }
        $missing = array_values(array_diff(array_column($plan['pages'], 'key'), array_column($pages, 'key')));
        $usage = $this->usage();
        $record = [
            'schema' => 'content.v1',
            'source_pdf' => $plan['source_pdf'] ?? null,
            'plan_created_at' => $plan['created_at'] ?? null,
            'created_at' => date('c'),
            'duration_seconds' => round(microtime(true) - $t0, 1),
            'provider' => $this->calls[0]['provider'] ?? ($existing['provider'] ?? null),
            'model' => $this->calls[0]['model'] ?? ($existing['model'] ?? null),
            'check' => [
                'passed' => $errorsLeft === 0 && !$missing,
                'errors_first_pass' => $firstPass,
                'errors_left' => $errorsLeft,   // errors in pages that were done; pages not done yet are in missing_pages
                'warnings' => array_sum(array_map(fn ($r) => count($r['warnings']), $report)),
                'missing_pages' => $missing,
                'stats' => $stats,
                'pages' => $report,
            ],
            'usage' => $usage,
            'parts' => $plan['parts'] ?? [],
            'pages' => $pages,
        ];

        $checkOut = $record['check'];
        $checkOut['calls'] = $this->calls;
        unset($record['check']['pages']);
        $record['check']['pages'] = array_map(fn ($r) => ['errors_left' => count($r['errors_left']), 'warnings' => count($r['warnings'])], $report);
        $this->write("$workDir/content.json", $record);
        $this->write("$workDir/content-check.json", $checkOut);
        $record['check']['pages_detail'] = $report;
        return $record;
    }

    /** One call with retries for unreadable replies. Returns [content, shapeErrors] or null. */
    private function writePage(string $prompt, array $pg, string $phase): ?array
    {
        $validator = new ContentValidator();
        $p = $prompt;
        $kept = null;
        for ($attempt = 0; $attempt <= $this->maxJsonRetries; $attempt++) {
            $t0 = microtime(true);
            try {
                $reply = $this->writer->write($p);
            } catch (Throwable $e) {
                $this->logCall($phase, $pg['key'], $attempt, null, false, $e->getMessage(), $t0);
                $this->say("{$pg['file']}: attempt $attempt failed: " . $e->getMessage());
                if ($attempt < $this->maxJsonRetries) {
                    sleep(min(10, 2 ** $attempt));
                }
                continue;
            }
            $parsed = $validator->parse($reply['text'], $pg);
            if ($cutOff = ModelReply::truncation($reply)) {
                $parsed['ok'] = false;
                array_unshift($parsed['errors'], $cutOff);
            }
            $this->logCall($phase, $pg['key'], $attempt, $reply, $parsed['ok'], $parsed['errors'] ? implode(' | ', array_slice($parsed['errors'], 0, 5)) : null, $t0);
            if ($parsed['ok']) {
                return [$parsed['page'], $parsed['errors']];   // shape errors go to the repair round with the rule errors
            }
            $this->say("{$pg['file']}: unreadable reply, asking again (" . implode(' | ', array_slice($parsed['errors'], 0, 2)) . ')');
            $p = $prompt . "\n\nYour last reply could not be used: " . implode(' ', $parsed['errors']) . ' Reply with the JSON object only.';
        }
        return $kept;
    }

    /** Same code-only fix as Step 3: an "example" block without a book worked example is a step-through walk. */
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
            'at' => date('c'),
            'phase' => $phase,
            'page' => $page,
            'attempt' => $attempt,
            'ok' => $ok,
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
