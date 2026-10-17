<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

use App\Services\Lessons\ModelReply;
use RuntimeException;
use Throwable;

/**
 * Step 2 + 2b, framework-free:
 *   read pages in groups -> validate each reply (retry on bad JSON) -> merge in page order
 *   -> check against the page text -> re-read only the pages with problems -> write files.
 *
 * Writes into the chapter folder (beside pages.json):
 *   structure.json        the structure record (schema structure.v1)
 *   structure-check.json  the Step 2b report: errors fixed, errors left, warnings
 *   usage.jsonl           one line per AI call (tokens, cost, time)
 */
final class StructureRunner
{
    /** @var array<int,array> */
    private array $calls = [];

    /**
     * @param array<string,float[]> $prices model => [input $/1M, output $/1M]
     * @param ?callable             $log    fn(string $message)
     */
    public function __construct(
        private readonly PageReader $reader,
        private readonly int $pagesPerCall = 3,
        private readonly bool $lookahead = true,
        private readonly int $maxJsonRetries = 2,
        private readonly int $maxRepairRounds = 2,
        private readonly array $prices = [],
        private readonly int $minChars = 40,
        private $log = null,
    ) {
    }

    /**
     * @param array    $pagesJson decoded pages.json
     * @param string   $folder    absolute chapter folder (holds images_ai/ and gets the output files)
     * @param int[]    $scope     pages to read (empty = all)
     * @return array the structure record that was written
     */
    public function run(array $pagesJson, string $folder, array $scope = []): array
    {
        $started = microtime(true);
        $pages = [];
        foreach ($pagesJson['pages'] ?? [] as $p) {
            $pages[(int) $p['pdf_page']] = $p;
        }
        if (!$pages) {
            throw new RuntimeException('pages.json has no pages');
        }
        $all = array_keys($pages);
        $scope = $scope ?: $all;

        // 1. First pass, group by group.
        $itemsByPage = [];
        $uncertain = [];
        $failedGroups = [];
        foreach (PageGrouper::groups($scope, $all, $this->pagesPerCall, $this->lookahead) as $g) {
            $res = $this->readGroup($pages, $g['targets'], $g['context'], $folder, null, 'read');
            if ($res === null) {
                $failedGroups[] = $g['targets'];
                continue;
            }
            foreach ($g['targets'] as $t) {
                $itemsByPage[$t] = array_values(array_filter($res['items'], fn ($it) => $it['page'] === $t));
            }
            $uncertain = array_merge($uncertain, $res['uncertain']);
        }

        // 2. Check, then repair only the pages with errors.
        $checker = new StructureChecker($this->minChars);
        $check = $checker->check($pages, $this->flatten($itemsByPage), $scope);
        $firstErrors = count($check['errors']);
        for ($round = 1; $round <= $this->maxRepairRounds && $check['errors']; $round++) {
            $badPages = array_values(array_unique(array_merge(array_keys($check['by_page']), ...array_map(fn ($g) => $g, $failedGroups))));
            sort($badPages);
            $badPages = array_values(array_intersect($badPages, $scope));
            if (!$badPages) {
                break;
            }
            $this->say("Repair round $round: pages " . implode(', ', $badPages));
            $failedGroups = [];
            foreach (PageGrouper::groups($badPages, $all, $this->pagesPerCall, $this->lookahead) as $g) {
                $feedback = $this->feedbackFor($g['targets'], $check, $itemsByPage);
                $res = $this->readGroup($pages, $g['targets'], $g['context'], $folder, $feedback, "repair$round");
                if ($res === null) {
                    continue;
                }
                foreach ($g['targets'] as $t) {
                    $new = array_values(array_filter($res['items'], fn ($it) => $it['page'] === $t));
                    // Keep the new reading only if it is not smaller than the old one by much.
                    $old = $itemsByPage[$t] ?? [];
                    if ($new && count($new) >= (int) floor(count($old) * 0.8)) {
                        $itemsByPage[$t] = $new;
                    } elseif ($new && $old) {
                        $itemsByPage[$t] = $this->union($old, $new);
                    } elseif ($new) {
                        $itemsByPage[$t] = $new;
                    }
                }
                $uncertain = array_merge($uncertain, $res['uncertain']);
            }
            $check = $checker->check($pages, $this->flatten($itemsByPage), $scope);
        }

        // 3. Number the items and write the files.
        $items = $this->flatten($itemsByPage);
        foreach ($items as $i => &$it) {
            $it = ['id' => sprintf('s%03d', $i + 1)] + $it;
        }
        unset($it);

        $usage = $this->usageSummary();
        $record = [
            'schema' => 'structure.v1',
            'source_pdf' => $pagesJson['source_pdf'] ?? null,
            'page_count' => count($all),
            'pages_read' => array_values($scope),
            'first_printed_page' => $pagesJson['first_printed_page'] ?? null,
            'created_at' => date('c'),
            'duration_seconds' => round(microtime(true) - $started, 1),
            'model' => $this->calls[0]['model'] ?? null,
            'provider' => $this->calls[0]['provider'] ?? null,
            'check' => [
                'passed' => !$check['errors'],
                'errors_first_pass' => $firstErrors,
                'errors_left' => count($check['errors']),
                'warnings' => count($check['warnings']),
                'stats' => $check['stats'],
            ],
            'usage' => $usage,
            'uncertain' => array_values($uncertain),
            'items' => $items,
        ];

        $this->write("$folder/structure.json", $record);
        $this->write("$folder/structure-check.json", [
            'passed' => !$check['errors'],
            'errors_first_pass' => $firstErrors,
            'errors_left' => $check['errors'],
            'warnings' => $check['warnings'],
            'stats' => $check['stats'],
            'calls' => $this->calls,
        ]);
        return $record;
    }

    /** One group, with retries for invalid JSON. Returns null if every attempt failed. */
    private function readGroup(array $pages, array $targets, ?int $context, string $folder, ?string $feedback, string $phase): ?array
    {
        $subset = [];
        $images = [];
        foreach (array_merge($targets, $context !== null ? [$context] : []) as $n) {
            $subset[$n] = $pages[$n];
            $img = $pages[$n]['image_ai'] ?? $pages[$n]['image'] ?? null;
            if ($img && is_file("$folder/$img")) {
                $images[$n] = "$folder/$img";
            }
        }
        if (count($images) < count($subset)) {
            $this->say('Warning: some page images are missing in ' . $folder . '; the model will rely on text for those pages.');
        }

        $fb = $feedback;
        for ($attempt = 0; $attempt <= $this->maxJsonRetries; $attempt++) {
            $t0 = microtime(true);
            try {
                $reply = $this->reader->read($subset, $targets, $context, $images, $fb);
            } catch (Throwable $e) {
                $this->logCall($phase, $targets, $attempt, null, false, $e->getMessage(), $t0);
                $this->say("Pages " . implode(',', $targets) . " attempt $attempt failed: " . $e->getMessage());
                if ($attempt >= $this->maxJsonRetries) {
                    return null;
                }
                sleep(min(10, 2 ** $attempt));
                continue;
            }
            $parsed = (new StructureValidator())->parse($reply['text'], $targets);
            if ($cutOff = ModelReply::truncation($reply)) {
                $parsed['ok'] = false;
                array_unshift($parsed['errors'], $cutOff);
            }
            $this->logCall($phase, $targets, $attempt, $reply, $parsed['ok'], $parsed['ok'] ? null : implode(' | ', $parsed['errors']), $t0, count($parsed['items']));
            if ($parsed['ok']) {
                $this->say('Pages ' . implode(',', $targets) . ": {$phase} ok, " . count($parsed['items']) . ' items');
                return $parsed;
            }
            $this->say('Pages ' . implode(',', $targets) . ": invalid reply, retrying (" . implode(' | ', array_slice($parsed['errors'], 0, 3)) . ')');
            $fb = trim(($feedback ? $feedback . "\n" : '') . "Your last reply had these problems:\n- " . implode("\n- ", array_slice($parsed['errors'], 0, 15)));
            if ($attempt === $this->maxJsonRetries && $parsed['items']) {
                return $parsed;   // usable items despite small problems: keep them, the checker will report the rest
            }
        }
        return null;
    }

    private function feedbackFor(array $targets, array $check, array $itemsByPage): string
    {
        $lines = [];
        foreach ($targets as $t) {
            foreach ($check['by_page'][$t] ?? [] as $msg) {
                $lines[] = "- $msg";
            }
            $have = $itemsByPage[$t] ?? [];
            if ($have) {
                $labels = array_filter(array_map(fn ($it) => $it['type'] . (($it['label'] ?? $it['title']) ? ' "' . mb_substr((string) ($it['label'] ?? $it['title']), 0, 50) . '"' : ''), $have));
                $lines[] = "- Last time you listed for page $t: " . implode(', ', array_slice($labels, 0, 40)) . '. Keep those and add what is missing.';
            }
        }
        return $lines ? implode("\n", $lines) : 'Check that nothing on these pages is missing.';
    }

    private function union(array $old, array $new): array
    {
        $key = fn ($it) => $it['type'] . '|' . mb_strtolower((string) $it['label']) . '|' . mb_substr((string) ($it['text'] ?? $it['title'] ?? ''), 0, 40);
        $seen = [];
        $out = [];
        foreach (array_merge($old, $new) as $it) {
            $k = $key($it);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $it;
            }
        }
        return $out;
    }

    private function flatten(array $itemsByPage): array
    {
        ksort($itemsByPage);
        $out = [];
        foreach ($itemsByPage as $list) {
            foreach ($list as $it) {
                $out[] = $it;
            }
        }
        return $out;
    }

    private function logCall(string $phase, array $targets, int $attempt, ?array $reply, bool $ok, ?string $error, float $t0, int $items = 0): void
    {
        $in = (int) ($reply['input_tokens'] ?? 0);
        $out = (int) ($reply['output_tokens'] ?? 0);
        $model = $reply['model'] ?? null;
        [$pi, $po] = $this->prices[$model] ?? [null, null];
        $this->calls[] = [
            'at' => date('c'),
            'phase' => $phase,
            'pages' => $targets,
            'attempt' => $attempt,
            'ok' => $ok,
            'items' => $items,
            'error' => $error,
            'provider' => $reply['provider'] ?? null,
            'model' => $model,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cost_usd' => $pi !== null ? round(($in * $pi + $out * $po) / 1_000_000, 5) : null,
            'seconds' => round(microtime(true) - $t0, 1),
        ] + ModelReply::logFields($reply, $ok);
    }

    private function usageSummary(): array
    {
        $in = array_sum(array_column($this->calls, 'input_tokens'));
        $out = array_sum(array_column($this->calls, 'output_tokens'));
        $costs = array_filter(array_column($this->calls, 'cost_usd'), fn ($c) => $c !== null);
        return [
            'calls' => count($this->calls),
            'failed_calls' => count(array_filter($this->calls, fn ($c) => !$c['ok'])),
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cost_usd' => $costs ? round(array_sum($costs), 4) : null,
            'note' => $costs ? 'List price; free-quota tokens are not charged.' : 'Add the model to lessons.structure.prices to see cost.',
        ];
    }

    /** @return array<int,array> every AI call made in this run (for usage.jsonl) */
    public function calls(): array
    {
        return $this->calls;
    }

    private function write(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents("$path.tmp", $json) === false || !rename("$path.tmp", $path)) {
            throw new RuntimeException("Could not write $path");
        }
    }

    private function say(string $m): void
    {
        if ($this->log) {
            ($this->log)($m);
        }
    }
}
