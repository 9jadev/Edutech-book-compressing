<?php

declare(strict_types=1);

namespace App\Services\Lessons\Plan;

/**
 * Step 3 check (no AI): does the plan cover the book exactly, in order?
 * Errors send that page back to the model; warnings go in plan-check.json.
 */
final class PlanChecker
{
    private const QUESTION_TYPES = ['exercise', 'question', 'question_part'];

    /**
     * @param array<int,array>    $outline  pages from PageSplitter
     * @param array<string,array> $plans    page key => page plan from PlanValidator
     * @param array<string,array> $itemsById structure items
     * @return array{errors:array, warnings:array, by_page:array<string,string[]>, stats:array}
     */
    public function check(array $outline, array $plans, array $itemsById): array
    {
        $errors = [];
        $warnings = [];
        $byPage = [];
        $order = array_flip(array_keys($itemsById));
        $home = [];
        foreach ($outline as $p) {
            foreach ($p['items'] as $id) {
                $home[$id] = $p['key'];
            }
        }
        $stats = ['pages' => count($outline), 'blocks' => 0, 'visuals_3d' => 0, 'visuals_panel' => 0, 'practice_book' => 0, 'practice_ours' => 0, 'additions' => 0];

        foreach ($outline as $p) {
            $k = $p['key'];
            $plan = $plans[$k] ?? null;
            if ($plan === null) {
                $this->add($errors, $byPage, $k, 'missing_page', "No plan was returned for page $k.");
                continue;
            }
            $blocks = $plan['blocks'];
            $stats['blocks'] += count($blocks);
            $stats['additions'] += count($plan['additions']);
            if (!$blocks) {
                $this->add($errors, $byPage, $k, 'no_blocks', "Page $k has no blocks.");
                continue;
            }

            // 1. Items must belong to this page; each content item exactly once.
            $seen = [];
            $sequence = [];
            $blockOf = [];
            foreach ($blocks as $b) {
                foreach ($b['items'] as $id) {
                    if (!isset($itemsById[$id])) {
                        $this->add($errors, $byPage, $k, 'unknown_item', "Block \"{$b['heading']}\" on $k uses item $id, which does not exist.");
                        continue;
                    }
                    if (($home[$id] ?? null) !== $k) {
                        $this->add($errors, $byPage, $k, 'wrong_page', "Item $id belongs to page " . ($home[$id] ?? '?') . ", not $k. Remove it from this page.");
                        continue;
                    }
                    if (isset($seen[$id])) {
                        $this->add($errors, $byPage, $k, 'duplicate_item', "Item $id is used in more than one block on $k. Use each item once.");
                        continue;
                    }
                    $seen[$id] = true;
                    $sequence[] = $id;
                    $blockOf[$id] = $b;
                }
            }
            $missing = [];
            foreach ($p['items'] as $id) {
                $it = $itemsById[$id];
                if (!isset($seen[$id]) && !in_array($it['type'], PageSplitter::MARKERS, true)) {
                    $missing[] = $id . ' (' . $it['type'] . ($it['label'] ? ' "' . $it['label'] . '"' : '') . ')';
                }
            }
            if ($missing) {
                $this->add($errors, $byPage, $k, 'missing_items', "Page $k leaves out these book items: " . implode(', ', $missing) . '. Put each one in a block.');
            }

            // 2. Book order.
            for ($i = 1, $n = count($sequence); $i < $n; $i++) {
                if ($order[$sequence[$i]] < $order[$sequence[$i - 1]]) {
                    $this->add($errors, $byPage, $k, 'order', "On $k, item {$sequence[$i]} comes before {$sequence[$i - 1]} in the book. Keep the book's order across blocks.");
                    break;
                }
            }

            // 3. Item types must sit in the right kind of block.
            $bookQs = [];
            foreach ($blocks as $b) {
                foreach ($b['practice'] as $q) {
                    $stats[$q['source'] === 'book' ? 'practice_book' : 'practice_ours']++;
                    if ($q['source'] === 'book' && $q['item']) {
                        $bookQs[$q['item']] = true;
                    }
                }
                if ($b['visual']['mode'] === '3d') {
                    $stats['visuals_3d']++;
                } elseif ($b['visual']['mode'] === 'panel') {
                    $stats['visuals_panel']++;
                }
                if ($b['type'] === 'practice' && !$b['practice']) {
                    if ($b['items']) {
                        $warnings[] = ['page' => $k, 'kind' => 'empty_practice', 'message' => "Practice block \"{$b['heading']}\" on $k holds book items but lists no questions."];
                    } else {
                        $this->add($errors, $byPage, $k, 'empty_practice', "Practice block \"{$b['heading']}\" on $k lists no questions.");
                    }
                }
                if (in_array($b['type'], ['walk', 'explore', 'widget'], true) && $b['visual']['mode'] === 'none') {
                    $this->add($errors, $byPage, $k, 'no_visual', "Block \"{$b['heading']}\" on $k is a {$b['type']} block but has no visual. Give it a 3d or panel visual.");
                }
            }
            $hasParts = [];
            foreach ($p['items'] as $id) {
                $it = $itemsById[$id];
                if ($it['type'] === 'question_part' && $it['parent']) {
                    $hasParts[mb_strtolower(trim((string) $it['parent']))] = true;
                }
            }
            foreach ($p['items'] as $id) {
                $it = $itemsById[$id];
                $b = $blockOf[$id] ?? null;
                if (!$b) {
                    continue;
                }
                $name = $id . ($it['label'] ? ' "' . $it['label'] . '"' : '');
                switch ($it['type']) {
                    case 'example':
                    case 'example_part':
                        if (!in_array($b['type'], ['example', 'walk'], true)) {
                            $this->add($errors, $byPage, $k, 'example_block', "Worked example $name on $k must be in an example block (played step by step), not {$b['type']}.");
                        }
                        break;
                    case 'exercise':
                        // An "Exercises:" header can introduce solved examples, so example blocks are fine too.
                        if (!in_array($b['type'], ['practice', 'example'], true)) {
                            $this->add($errors, $byPage, $k, 'question_block', "Exercise heading $name on $k must be in a practice block (or the example block it introduces), not {$b['type']}.");
                        }
                        break;
                    case 'question':
                    case 'question_part':
                        if ($b['type'] !== 'practice') {
                            $this->add($errors, $byPage, $k, 'question_block', "Book question $name on $k must be in a practice block, not {$b['type']}.");
                        }
                        $needs = $it['type'] === 'question_part'
                            || ($it['type'] === 'question' && !isset($hasParts[mb_strtolower(trim((string) $it['label']))]));
                        if ($needs && !isset($bookQs[$id])) {
                            $this->add($errors, $byPage, $k, 'question_not_planned', "Book question $name on $k has no practice entry. Add one with source \"book\" and item \"$id\".");
                        }
                        break;
                    case 'activity':
                        if (!in_array($b['type'], ['widget', 'walk', 'explore'], true)) {
                            $this->add($errors, $byPage, $k, 'activity_block', "Activity $name on $k must be a widget (hands-on) block, not {$b['type']}.");
                        }
                        break;
                    case 'figure':
                        if ($b['visual']['mode'] === 'none') {
                            $this->add($errors, $byPage, $k, 'figure_no_visual', "Figure $name on $k is in a block with no visual. Give that block a 3d or panel visual of it.");
                            break;
                        }
                        // Every printed label must reach the visual, or Step 6 will build a model with parts missing.
                        $said = $this->norm(implode(' ', array_filter([$b['visual']['subject'], $b['visual']['shows'], $b['visual']['interaction']])));
                        $lost = array_values(array_filter((array) ($it['figure_labels'] ?? []), fn ($l) => !$this->mentions($said, (string) $l)));
                        if ($lost) {
                            $this->add($errors, $byPage, $k, 'figure_labels_missing', "Figure $name on $k: the visual leaves out these printed labels: " . implode(', ', $lost) . '. Name every label of the figure in "shows" or "interaction".');
                        }
                        break;
                }
            }

            // 4. Book practice entries must point at questions on this page.
            foreach ($blocks as $b) {
                foreach ($b['practice'] as $q) {
                    if ($q['source'] !== 'book') {
                        continue;
                    }
                    $qi = $q['item'] ? ($itemsById[$q['item']] ?? null) : null;
                    if (!$qi || ($home[$q['item']] ?? null) !== $k || !in_array($qi['type'], self::QUESTION_TYPES, true)) {
                        $this->add($errors, $byPage, $k, 'bad_book_question', "A practice entry on $k says source \"book\" with item \"" . ($q['item'] ?? '') . "\", but that is not a book question on this page. Use source \"ours\" for questions you make up.");
                    }
                }
            }

            // Warnings: style rules that a teacher can judge.
            if ($p['kind'] === 'welcome' && count($plan['objectives']) < 2) {
                $warnings[] = ['page' => $k, 'kind' => 'objectives', 'message' => "$k: the welcome page should list learning objectives."];
            }
            if ($p['kind'] !== 'welcome' && !array_filter($blocks, fn ($b) => $b['visual']['mode'] === '3d')) {
                $warnings[] = ['page' => $k, 'kind' => 'no_3d', 'message' => "$k has no 3D visual."];
            }
            if ($p['kind'] === 'section' && !array_filter($blocks, fn ($b) => $b['type'] === 'practice')) {
                $warnings[] = ['page' => $k, 'kind' => 'no_practice', 'message' => "$k has no practice block."];
            }
            if (count($blocks) > 10) {
                $warnings[] = ['page' => $k, 'kind' => 'long_page', 'message' => "$k has " . count($blocks) . ' blocks; it may be too long for one page.'];
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'by_page' => $byPage, 'stats' => $stats];
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(['endoplasmic reticulum', 'haemoglobin', 'organisation'], ['er', 'hemoglobin', 'organization'], $s);
        return ' ' . trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s) . ' ';
    }

    /** "Mitochondria" matches "mitochondrion"; "Rough endoplasmic reticulum" matches "rough ER". */
    private function mentions(string $said, string $label): bool
    {
        $label = trim(preg_replace('/\([^)]*\)/u', ' ', $label) ?? $label);   // "Red Blood Cells (RBC)" -> "Red Blood Cells"
        $l = trim($this->norm($label));
        if ($l === '' || mb_strlen($l) < 3 || in_array($l, ['smallest', 'biggest', 'largest'], true)) {
            return true;   // arrows/scale words, not parts
        }
        if (str_contains($said, " $l ")) {
            return true;
        }
        $stem = fn (string $w) => mb_substr($w, 0, max(4, mb_strlen($w) - 3));
        foreach (explode(' ', $l) as $w) {
            if (mb_strlen($w) >= 3 && !preg_match('/(?:^| )' . preg_quote($stem($w), '/') . '/u', $said)) {
                return false;
            }
        }
        return true;
    }

    private function add(array &$errors, array &$byPage, string $page, string $kind, string $msg): void
    {
        $errors[] = ['page' => $page, 'kind' => $kind, 'message' => $msg];
        $byPage[$page][] = $msg;
    }
}
