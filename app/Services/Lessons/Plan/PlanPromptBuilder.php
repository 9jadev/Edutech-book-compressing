<?php

declare(strict_types=1);

namespace App\Services\Lessons\Plan;

/** Builds the user message for one plan call: outline for context + full detail for the pages to plan. */
final class PlanPromptBuilder
{
    /**
     * @param array<int,array>    $outline   all pages (PageSplitter)
     * @param array<int,array>    $targets   the pages to plan in this call
     * @param array<string,array> $itemsById structure items
     * @param array<string,array> $previous  page key => last plan (repair calls), so the model fixes instead of rewriting
     */
    public static function build(string $source, array $outline, array $targets, array $itemsById, ?string $feedback = null, array $previous = []): string
    {
        $want = array_column($targets, 'key');
        $out = ["Book file: $source", '', 'CHAPTER OUTLINE (for context; plan only the pages marked PLAN):'];
        foreach ($outline as $p) {
            $mark = in_array($p['key'], $want, true) ? 'PLAN ' : '     ';
            $out[] = "$mark{$p['key']} — {$p['title']} ({$p['kind']})";
        }
        $out[] = '';
        $out[] = 'PAGES TO PLAN:';
        foreach ($targets as $p) {
            $book = $p['book_pages'] ? 'book page' . (count($p['book_pages']) > 1 ? 's ' : ' ') . self::range($p['book_pages']) : 'PDF page ' . self::range($p['pdf_pages']);
            $out[] = '';
            $out[] = "=== PAGE {$p['key']} | kind: {$p['kind']} | title: {$p['title']} | $book";
            $out[] = 'Items in book order:';
            foreach ($p['items'] as $id) {
                $out[] = self::item($itemsById[$id]);
            }
            if (isset($previous[$p['key']])) {
                $out[] = 'Your last plan for this page (fix it, keep what was right):';
                $out[] = json_encode(['page' => $p['key']] + $previous[$p['key']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        if ($feedback) {
            $out[] = '';
            $out[] = 'FIX THESE PROBLEMS:';
            $out[] = $feedback;
        }
        $out[] = '';
        $out[] = 'Reply with the JSON object only, with one entry for each of these page keys: ' . implode(', ', $want);
        return implode("\n", $out);
    }

    private static function item(array $it): string
    {
        $head = "{$it['id']} {$it['type']}";
        if ($it['label']) {
            $head .= " \"{$it['label']}\"";
        }
        if ($it['parent']) {
            $head .= " (part of {$it['parent']})";
        }
        $bits = [];
        if ($it['title']) {
            $bits[] = $it['title'];
        }
        if ($it['text']) {
            $bits[] = self::cut($it['text'], 400);
        }
        if (!empty($it['figure_labels'])) {
            $bits[] = 'labels: ' . implode('; ', $it['figure_labels']);
        }
        if (!empty($it['rows'])) {
            $rows = array_map(fn ($r) => implode(' | ', array_map('strval', (array) $r)), array_slice($it['rows'], 0, 6));
            $bits[] = 'rows: ' . self::cut(implode(' // ', $rows), 400) . (count($it['rows']) > 6 ? ' // … (' . count($it['rows']) . ' rows)' : '');
        }
        if (!empty($it['exam_tags'])) {
            $bits[] = 'exam tags: ' . implode(', ', $it['exam_tags']);
        }
        return $head . ($bits ? ' — ' . implode(' — ', $bits) : '');
    }

    private static function cut(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }

    private static function range(array $n): string
    {
        if (!$n) {
            return '?';
        }
        return count($n) === 1 ? (string) $n[0] : min($n) . '–' . max($n);
    }
}
