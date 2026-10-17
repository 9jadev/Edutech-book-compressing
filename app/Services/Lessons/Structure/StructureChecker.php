<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

/**
 * Step 2b — compare what the AI listed with what the code can see in the page text.
 * No AI here. Errors trigger a repair call for that page; warnings go in the report.
 */
final class StructureChecker
{
    private const CAPTIONS = [
        'figure'   => '/^\s*(Fig(?:ure)?\.?\s*\d+(?:\.\d+)?)/i',
        'table'    => '/^\s*(Table\s+\d+(?:\.\d+)?)/i',
        'example'  => '/^\s*(Example\s+\d+(?:\.\d+)?)/i',
        'exercise' => '/^\s*(Exercise\s+\d+(?:\.\d+)?)/i',
        'activity' => '/^\s*(Activity\s+\d+(?:\.\d+)?)/i',
    ];

    public function __construct(private readonly int $minChars = 40)
    {
    }

    /**
     * @param array<int,array> $pages  pages.json entries keyed by pdf_page
     * @param array            $items  merged items (all pages)
     * @param int[]            $scope  pages that were read
     * @return array{errors:array, warnings:array, by_page:array<int,string[]>, stats:array}
     */
    public function check(array $pages, array $items, array $scope): array
    {
        $errors = [];
        $warnings = [];
        $byPage = [];
        $labels = [];
        foreach ($items as $it) {
            foreach ([$it['label'] ?? null, $it['title'] ?? null] as $l) {
                if ($l) {
                    $labels[$this->norm($l)] = true;
                }
            }
        }
        $itemsOn = [];
        foreach ($items as $it) {
            $itemsOn[$it['page']][] = $it;
        }

        foreach ($scope as $n) {
            $p = $pages[$n] ?? null;
            if (!$p) {
                continue;
            }
            $text = (string) ($p['text'] ?? '');
            $real = (int) preg_match_all('/[\p{L}\p{N}]/u', $text);
            $here = $itemsOn[$n] ?? [];

            // 1. Every page with text has items.
            if ($real >= $this->minChars && !$here) {
                $this->add($errors, $byPage, $n, 'empty_page', "Page $n has text but no items were listed for it.");
            }

            // 2. Captions/headings the code can see at the start of a line must be listed.
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                if (mb_strlen(trim($line)) > 120) {
                    continue;   // long lines are sentences that mention a figure, not captions
                }
                foreach (self::CAPTIONS as $type => $re) {
                    if (preg_match($re, $line, $m) && !isset($labels[$this->norm($m[1])])) {
                        $this->add($errors, $byPage, $n, "missing_$type", "Page $n shows \"" . trim($m[1]) . "\" but no item has that label. List it as a $type (label \"" . trim($m[1]) . '").');
                    }
                }
            }
            foreach ($p['markers']['headings']['found'] ?? [] as $h) {
                $short = $this->norm(mb_substr($h, 0, 40));
                $found = false;
                foreach ($here as $it) {
                    $cand = $this->norm(trim(($it['label'] ?? '') . ' ' . ($it['title'] ?? '')));
                    if ($cand !== '' && (str_contains($cand, mb_substr($short, 0, 15)) || str_contains($short, mb_substr($cand, 0, 15)))) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $this->add($errors, $byPage, $n, 'missing_heading', "Page $n has the heading \"$h\" but it is not listed as a heading.");
                }
            }

            // 3. Exam tags in the text must be kept.
            foreach ($p['markers']['exam_tags']['found'] ?? [] as $tag) {
                $kept = false;
                foreach ($here as $it) {
                    if (in_array($tag, $it['exam_tags'] ?? [], true) || str_contains((string) ($it['text'] ?? ''), $tag)) {
                        $kept = true;
                        break;
                    }
                }
                if (!$kept) {
                    $this->add($errors, $byPage, $n, 'missing_exam_tag', "Page $n has the exam tag \"$tag\" but no item carries it in exam_tags.");
                }
            }

            // 4. Pages with a real picture should have a figure item.
            if (!empty($p['has_figure_image']) && !array_filter($here, fn ($it) => $it['type'] === 'figure')
                && !$this->continuedFigure($items, $n)) {
                $this->add($errors, $byPage, $n, 'missing_figure', "Page $n contains a picture or diagram but no figure item was listed. List it (label null if it has no number).");
            }

            // 5. Figures should carry their labels when OCR found words in the diagrams.
            if (count($p['figure_labels'] ?? []) >= 3) {
                $figs = array_filter($here, fn ($it) => $it['type'] === 'figure');
                if ($figs && !array_filter($figs, fn ($it) => !empty($it['figure_labels']))) {
                    $warnings[] = ['page' => $n, 'kind' => 'figure_labels', 'message' => "Page $n: the diagrams have printed labels but no figure item lists figure_labels."];
                }
            }
        }

        // 5b. A figure listed on a page with no picture is usually a list or table drawn with lines/arrows.
        foreach ($items as $it) {
            if ($it['type'] === 'figure' && empty($pages[$it['page']]['has_figure_image']) && in_array($it['page'], $scope, true)) {
                $warnings[] = ['page' => $it['page'], 'kind' => 'figure_without_picture', 'message' => "Page {$it['page']}: \"" . ($it['label'] ?? $it['title'] ?? 'figure') . '" is listed as a figure, but the page has no picture. It may be a list or table drawn with arrows or lines.'];
            }
        }

        // 6. Question numbering: no gaps or repeats inside one exercise.
        $byParent = [];
        foreach ($items as $it) {
            if ($it['type'] === 'question' && preg_match('/^\D*(\d+)/', (string) $it['label'], $m)) {
                $byParent[$it['parent'] ?? '(none)'][] = ['n' => (int) $m[1], 'page' => $it['page']];
            }
        }
        foreach ($byParent as $parent => $qs) {
            $nums = array_column($qs, 'n');
            $dupes = array_keys(array_filter(array_count_values($nums), fn ($c) => $c > 1));
            sort($nums);
            $nums = array_values(array_unique($nums));
            $gaps = array_diff(range($nums[0], end($nums)), $nums);
            foreach ($gaps as $g) {
                $page = $this->pageBefore($qs, $g);
                $this->add($errors, $byPage, $page, 'question_gap', "In $parent, question $g is missing (questions jump past it). Check page $page.");
            }
            foreach ($dupes as $d) {
                $warnings[] = ['page' => 0, 'kind' => 'question_repeat', 'message' => "In $parent, question $d is listed more than once (could be a misprint in the book)."];
            }
        }

        // 7. Parts must point at a parent that exists.
        foreach ($items as $it) {
            if (in_array($it['type'], ['question_part', 'example_part'], true) && $it['parent'] && !isset($labels[$this->norm($it['parent'])])) {
                $warnings[] = ['page' => $it['page'], 'kind' => 'orphan_part', 'message' => "Page {$it['page']}: {$it['type']} \"{$it['label']}\" points to parent \"{$it['parent']}\", which is not listed."];
            }
        }

        $count = fn (string $t) => count(array_filter($items, fn ($it) => $it['type'] === $t));
        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'by_page' => $byPage,
            'stats' => [
                'items' => count($items),
                'figures' => $count('figure'),
                'tables' => $count('table'),
                'examples' => $count('example'),
                'example_parts' => $count('example_part'),
                'exercises' => $count('exercise'),
                'questions' => $count('question'),
                'question_parts' => $count('question_part'),
                'activities' => $count('activity'),
                'headings' => $count('heading') + $count('subheading'),
            ],
        ];
    }

    private function continuedFigure(array $items, int $page): bool
    {
        foreach ($items as $it) {
            if ($it['type'] === 'figure' && $it['page'] === $page - 1 && !empty($it['continues_on_next_page'])) {
                return true;
            }
        }
        return false;
    }

    private function pageBefore(array $qs, int $missing): int
    {
        $best = $qs[0]['page'];
        foreach ($qs as $q) {
            if ($q['n'] < $missing) {
                $best = $q['page'];
            }
        }
        return $best;
    }

    private function add(array &$errors, array &$byPage, int $page, string $kind, string $msg): void
    {
        $errors[] = ['page' => $page, 'kind' => $kind, 'message' => $msg];
        $byPage[$page][] = $msg;
    }

    /** "Fig. 1.2" / "Figure 1.2" / "fig 1.2" => "fig1.2" */
    private function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/\bfigure\b/u', 'fig', $s) ?? $s;
        return (string) preg_replace('/[^\p{L}\p{N}.]+|\.(?!\d)/u', '', $s);
    }
}
