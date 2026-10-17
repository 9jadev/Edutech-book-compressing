<?php

declare(strict_types=1);

namespace App\Services\Lessons\Write;

/**
 * Builds the prompt for one lesson page: the page plan, the book items it covers (full wording),
 * and the cleaned book text of its PDF pages (source of truth for wording and numbers).
 */
final class PageBrief
{
    /**
     * @param array               $page       one page from plan.json
     * @param array<string,array> $itemsById  structure items
     * @param array<int,array>    $bookPages  pages.json entries keyed by pdf_page
     * @param string[]            $headerPatterns regexes for running headers to drop from page text
     */
    public static function build(array $page, array $itemsById, array $bookPages, array $headerPatterns = [], ?string $feedback = null, ?array $previous = null): string
    {
        $out = [];
        $book = $page['book_pages'] ? 'book page' . (count($page['book_pages']) > 1 ? 's ' . min($page['book_pages']) . '–' . max($page['book_pages']) : ' ' . $page['book_pages'][0]) : '';
        $out[] = "PAGE KEY: {$page['key']}";
        $out[] = "Kind: {$page['kind']} | Title: {$page['title']} | Book title: {$page['book_title']}" . ($book ? " | $book" : '');
        if (!empty($page['summary'])) {
            $out[] = "Plan summary: {$page['summary']}";
        }
        if (!empty($page['objectives'])) {
            $out[] = 'Planned objectives: ' . implode(' | ', $page['objectives']);
        }
        if (!empty($page['opening'])) {
            $out[] = "Planned opening: {$page['opening']}";
        }

        $out[] = '';
        $out[] = 'BLOCKS TO WRITE (in this order, same id and type):';
        foreach ($page['blocks'] as $b) {
            $out[] = '';
            $out[] = "[{$b['id']}] type: {$b['type']} | heading: " . ($b['heading'] ?? '') . ($b['purpose'] ? " | purpose: {$b['purpose']}" : '');
            $v = $b['visual'] ?? [];
            if (($v['mode'] ?? 'none') !== 'none') {
                $out[] = "  visual ({$v['mode']}): " . trim(($v['subject'] ?? '') . ' — ' . ($v['shows'] ?? '') . ' — interaction: ' . ($v['interaction'] ?? ''), ' —');
            }
            foreach ($b['items'] as $id) {
                if (isset($itemsById[$id])) {
                    $out[] = '  ' . self::item($itemsById[$id]);
                }
            }
            foreach ($b['practice'] ?? [] as $i => $q) {
                $out[] = '  question ' . ($i + 1) . ": kind {$q['kind']}, source {$q['source']}" . ($q['item'] ? ", item {$q['item']}" : '') . ' — ' . ($q['about'] ?? '');
            }
        }

        if (!empty($page['additions'])) {
            $out[] = '';
            $out[] = 'PLANNED ADDITIONS (explain briefly, mark them in teacher_note.additions): ' . implode('; ', array_map(fn ($a) => $a['topic'] . ($a['reason'] ? " ({$a['reason']})" : ''), $page['additions']));
        }

        $out[] = '';
        $out[] = 'BOOK TEXT OF THESE PAGES (for facts, wording and numbers; do not copy sentences into notes):';
        foreach ($page['pdf_pages'] as $n) {
            $text = self::clean((string) ($bookPages[$n]['text'] ?? ''), $headerPatterns);
            if ($text !== '') {
                $out[] = "--- PDF page $n" . (isset($bookPages[$n]['printed_page']) ? " (book page {$bookPages[$n]['printed_page']})" : '') . ' ---';
                $out[] = $text;
            }
        }

        if ($previous !== null) {
            $out[] = '';
            $out[] = 'YOUR LAST VERSION OF THIS PAGE (fix it, keep what was right):';
            $out[] = json_encode($previous, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($feedback) {
            $out[] = '';
            $out[] = 'FIX THESE PROBLEMS:';
            $out[] = $feedback;
        }
        $out[] = '';
        $out[] = 'Reply with the JSON object for this page only.';
        return implode("\n", $out);
    }

    public static function item(array $it): string
    {
        $s = "{$it['id']} {$it['type']}" . ($it['label'] ? " \"{$it['label']}\"" : '') . ($it['parent'] ? " (part of {$it['parent']})" : '');
        $bits = [];
        if ($it['title']) {
            $bits[] = $it['title'];
        }
        if ($it['text']) {
            $bits[] = trim(preg_replace('/\s+/u', ' ', (string) $it['text']) ?? '');
        }
        if (!empty($it['figure_labels'])) {
            $bits[] = 'printed labels: ' . implode('; ', $it['figure_labels']);
        }
        if (!empty($it['rows'])) {
            $bits[] = 'rows: ' . implode(' // ', array_map(fn ($r) => implode(' | ', array_map('strval', (array) $r)), $it['rows']));
        }
        if (!empty($it['exam_tags'])) {
            $bits[] = 'exam tags: ' . implode(', ', $it['exam_tags']);
        }
        return $s . ($bits ? ' — ' . implode(' — ', $bits) : '');
    }

    /** Drop running headers, lone page numbers and extra blank lines. */
    public static function clean(string $text, array $headerPatterns): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $t = trim($line);
            if ($t === '' && (end($lines) === '' || !$lines)) {
                continue;
            }
            if (preg_match('/^\d{1,4}$/', $t)) {
                continue;
            }
            foreach ($headerPatterns as $re) {
                if (@preg_match($re, $t) === 1) {
                    continue 2;
                }
            }
            $lines[] = rtrim(preg_replace('/ {3,}/', '   ', $line) ?? $line);
        }
        return trim(implode("\n", $lines));
    }
}
