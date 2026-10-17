<?php

declare(strict_types=1);

namespace App\Services\Lessons\Write;

/**
 * Step 4 check (no AI). The same rules for every model:
 *  - every planned question is written; book questions keep the book's wording;
 *  - answers are well formed and hints do not give them away;
 *  - notes are in our own words (no 12-word run copied from the book);
 *  - narration has no symbols or abbreviations a voice would stumble on;
 *  - worked examples go set-up -> steps -> check -> answer;
 *  - label questions only use labels printed on the page's figures.
 */
final class ContentChecker
{
    private const COPY_RUN = 12;   // words in a row that count as copied

    private const EAR_BAD = [
        '/[=<>^_*|{}\[\]#~→←↑↓⇒⇐↔]/u' => 'symbols',
        '/-->|<--|-{2,}/u' => 'arrows',
        '/\bsol\'n\b|\bconc\.|\be\.g\.|\bi\.e\.|\betc\.|\bapprox\./iu' => 'abbreviations',
    ];

    /**
     * @param array               $plan      page from plan.json
     * @param array               $content   page from ContentValidator
     * @param array<string,array> $itemsById structure items
     * @param string              $bookText  cleaned text of the page's PDF pages
     * @return array{errors:string[], warnings:string[], stats:array}
     */
    public function check(array $plan, array $content, array $itemsById, string $bookText): array
    {
        $e = [];
        $w = [];
        $grams = $this->grams($bookText);
        $byId = [];
        foreach ($content['blocks'] as $b) {
            $byId[$b['id']] = $b;
        }
        $figureLabels = [];
        foreach ($plan['items'] as $id) {
            foreach ((array) ($itemsById[$id]['figure_labels'] ?? []) as $l) {
                $figureLabels[$this->norm($l)] = $l;
            }
        }
        $stats = ['blocks' => count($content['blocks']), 'paragraphs' => 0, 'steps' => 0, 'tasks' => 0, 'questions' => 0, 'questions_book' => 0, 'questions_ours' => 0, 'words' => 0];

        // Page-level parts.
        if (count($content['remember']) < 3 || count($content['remember']) > 4) {
            $e[] = 'The "remember" box must have 3 or 4 points (it has ' . count($content['remember']) . ').';
        }
        if (count($content['objectives']) < 2) {
            $e[] = 'Give at least 2 objectives ("By the end you can …").';
        }
        if (!$content['teacher_note']['scope']) {
            $e[] = 'teacher_note.scope is empty. Say what this page covers from the book.';
        }
        if (!empty($plan['additions']) && !$content['teacher_note']['additions']) {
            $e[] = 'The plan has additions (' . implode('; ', array_column($plan['additions'], 'topic')) . '). List each one in teacher_note.additions.';
        }
        $this->ear($content['intro'] ?? '', 'The page intro', $e);
        $this->copied($content['intro'] ?? '', 'The page intro', $grams, $e);

        $prevHeading = null;
        $prompts = [];
        foreach ($plan['blocks'] as $pb) {
            $b = $byId[$pb['id']] ?? null;
            if (!$b) {
                continue;   // reported by the validator
            }
            $where = "Block {$b['id']} ({$b['type']})";
            $h = mb_strtolower(trim((string) $b['heading']));
            if ($h !== '' && $h === $prevHeading) {
                $e[] = "$where has the same heading as the block before it (\"{$b['heading']}\"). Never put two identical headings in a row.";
            }
            $prevHeading = $h;

            switch ($b['type']) {
                case 'notes':
                    foreach ($b['paragraphs'] as $i => $p) {
                        $stats['paragraphs']++;
                        $stats['words'] += str_word_count($p['text']);
                        $this->copied($p['text'], "$where paragraph " . ($i + 1), $grams, $e);
                        $this->ear($p['say'] ?? $p['text'], "$where paragraph " . ($i + 1) . ($p['say'] ? ' "say"' : ' (add a "say" version)'), $e);
                        if (str_word_count($p['text']) > 90) {
                            $w[] = "$where paragraph " . ($i + 1) . ' is long (' . str_word_count($p['text']) . ' words). One idea per paragraph.';
                        }
                    }
                    break;
                case 'walk':
                case 'example':
                    $this->copied((string) $b['intro'], "$where intro", $grams, $e);
                    foreach ($b['steps'] as $i => $s) {
                        $stats['steps']++;
                        $this->ear($s['say'], "$where step " . ($i + 1) . ' "say"', $e);
                        if (!$s['shows'] && ($pb['visual']['mode'] ?? 'none') !== 'none') {
                            $e[] = "$where step " . ($i + 1) . ' has no "shows" (what the visual shows at this step).';
                        }
                    }
                    if ($b['type'] === 'example') {
                        $roles = array_column($b['steps'], 'role');
                        if (($roles[0] ?? null) !== 'setup' || end($roles) !== 'answer' || !in_array('check', $roles, true)) {
                            $e[] = "$where must go: a \"setup\" step first, then the steps, a \"check\" step, and an \"answer\" step last.";
                        }
                    }
                    break;
                case 'explore':
                case 'widget':
                    $this->copied((string) $b['intro'], "$where intro", $grams, $e);
                    foreach ($b['tasks'] as $i => $t) {
                        $stats['tasks']++;
                        $this->ear($t['say'], "$where task " . ($i + 1) . ' "say"', $e);
                        if (!$t['notice']) {
                            $w[] = "$where task " . ($i + 1) . ' has no "notice".';
                        }
                    }
                    break;
                case 'practice':
                    $this->practice($pb, $b, $where, $itemsById, $figureLabels, $prompts, $stats, $e, $w);
                    break;
            }
        }

        return ['errors' => array_values(array_unique($e)), 'warnings' => array_values(array_unique($w)), 'stats' => $stats];
    }

    private function practice(array $pb, array $b, string $where, array $itemsById, array $figureLabels, array &$prompts, array &$stats, array &$e, array &$w): void
    {
        $planned = $pb['practice'] ?? [];
        if (count($b['questions']) < count($planned)) {
            $e[] = "$where has " . count($b['questions']) . ' questions but the plan has ' . count($planned) . '. Write every planned question.';
        }
        $bookIds = [];
        foreach ($b['questions'] as $q) {
            if ($q['source'] === 'book' && $q['item']) {
                $bookIds[$q['item']] = $q;
            }
        }
        foreach ($planned as $pq) {
            if ($pq['source'] === 'book' && $pq['item'] && !isset($bookIds[$pq['item']])) {
                $e[] = "$where: book question {$pq['item']} is missing. Write it with \"source\": \"book\" and \"item\": \"{$pq['item']}\".";
            }
        }

        foreach ($b['questions'] as $i => $q) {
            $stats['questions']++;
            $stats[$q['source'] === 'book' ? 'questions_book' : 'questions_ours']++;
            $qw = "$where question " . ($i + 1) . " ({$q['kind']})";
            $p = mb_strtolower((string) $q['prompt']);
            if (isset($prompts[$p])) {
                $e[] = "$qw repeats another question on this page.";
            }
            $prompts[$p] = true;
            $this->ear($q['say'] ?? (string) $q['prompt'], $qw . ($q['say'] ? ' "say"' : ' prompt (add a "say" version)'), $e);

            // Book wording kept.
            if ($q['source'] === 'book' && $q['item'] && isset($itemsById[$q['item']])) {
                $src = (string) ($itemsById[$q['item']]['text'] ?? '');
                if ($src !== '' && $this->overlap($src, (string) $q['prompt']) < 0.8) {
                    $e[] = "$qw is book question {$q['item']} but its wording was changed. Copy the book's wording exactly: \"" . mb_substr(trim(str_replace('…', '', $src)), 0, 200) . '"';
                }
            }

            // Hints must not give the answer away.
            $hint = $this->norm((string) $q['hint']);
            $leak = null;
            switch ($q['kind']) {
                case 'mcq':
                    $right = $q['options'][$q['answer'] ?? -1] ?? null;
                    if ($right !== null && mb_strlen($this->norm($right)) >= 4 && str_contains($hint, $this->norm($right))) {
                        $leak = $right;
                    }
                    if (!$q['mistakes']) {
                        $w[] = "$qw has no \"mistakes\" (why students pick the wrong options).";
                    }
                    break;
                case 'numeric':
                    if ($q['answer'] !== null && preg_match('/(?<![\d.])' . preg_quote($this->num($q['answer']), '/') . '(?![\d.])/', (string) $q['hint'])) {
                        $leak = $this->num($q['answer']);
                    }
                    break;
                case 'short_text':
                    $acc = array_filter($q['accept'], fn ($a) => mb_strlen($this->norm($a)) >= 4);
                    $inHint = array_filter($acc, fn ($a) => str_contains($hint, $this->norm($a)));
                    if ($acc && count($inHint) >= max(1, (int) ceil(count($acc) / 2))) {
                        $leak = implode(', ', $inHint);
                    }
                    break;
                case 'label':
                    if ($figureLabels) {
                        foreach ($q['targets'] as $t) {
                            if (!$this->labelKnown($t, $figureLabels)) {
                                $e[] = "$qw uses the label \"$t\", which is not printed on this page's figures. Use only: " . implode(', ', array_values($figureLabels)) . '.';
                            }
                        }
                    }
                    break;
            }
            if ($leak !== null) {
                $e[] = "$qw: the hint gives the answer away (\"$leak\"). Make the hint a neutral nudge.";
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /** 12-word runs of the book text, normalised. */
    private function grams(string $text): array
    {
        $words = $this->spoken($this->words($text));
        $out = [];
        for ($i = 0, $n = count($words) - self::COPY_RUN; $i <= $n; $i++) {
            $out[implode(' ', array_slice($words, $i, self::COPY_RUN))] = true;
        }
        return $out;
    }

    private function copied(string $text, string $where, array $grams, array &$e): void
    {
        if (!$grams || $text === '') {
            return;
        }
        $words = $this->spoken($this->words($text));
        for ($i = 0, $n = count($words) - self::COPY_RUN; $i <= $n; $i++) {
            $run = implode(' ', array_slice($words, $i, self::COPY_RUN));
            if (isset($grams[$run])) {
                $e[] = "$where copies the book word for word (\"" . $run . '…"). Explain it in our own words.';
                return;
            }
        }
    }

    private function ear(string $say, string $where, array &$e): void
    {
        foreach (self::EAR_BAD as $re => $what) {
            if (preg_match($re, $say, $m)) {
                $e[] = "$where has $what a voice cannot read (\"{$m[0]}\"). Write it out in words for the narration.";
                return;
            }
        }
    }

    /** Share of the source's words (3+ letters) that appear in the target. */
    private function overlap(string $src, string $target): float
    {
        $a = array_unique(array_filter($this->words(str_replace('…', '', $src)), fn ($x) => mb_strlen($x) >= 3));
        if (!$a) {
            return 1.0;
        }
        $b = array_flip($this->words($target));
        return count(array_filter($a, fn ($x) => isset($b[$x]))) / count($a);
    }

    private function labelKnown(string $t, array $labels): bool
    {
        $n = $this->norm($t);
        if (isset($labels[$n])) {
            return true;
        }
        foreach (array_keys($labels) as $l) {
            if ($l !== '' && (str_contains($l, $n) || str_contains($n, $l)) && min(mb_strlen($l), mb_strlen($n)) >= 4) {
                return true;
            }
        }
        return false;
    }

    /** "2" and "two" count as the same word, so "molecules of 2 or more" = "molecules of two or more". */
    private function spoken(array $words): array
    {
        static $n = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];
        return array_map(fn ($w) => ctype_digit($w) && (int) $w <= 12 ? $n[(int) $w] : $w, $words);
    }

    private function words(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        return array_values(array_filter(explode(' ', $s), fn ($x) => $x !== ''));
    }

    private function norm(string $s): string
    {
        return implode(' ', $this->words($s));
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');
    }
}
