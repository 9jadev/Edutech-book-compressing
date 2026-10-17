<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

/**
 * Builds the user message for one group of pages: which pages to list, the extracted text
 * of each page, the labels the text layer mentions (a completeness hint), and any feedback.
 */
final class PromptBuilder
{
    public static function build(array $pages, array $targets, ?int $context, ?string $feedback = null): string
    {
        $order = array_merge($targets, $context !== null ? [$context] : []);
        $lines = [];
        $lines[] = 'TARGET pages: ' . implode(', ', $targets) . '. List every item that starts on these pages.';
        $lines[] = $context !== null
            ? "CONTEXT page: $context. Use it only to finish items that run over from page " . end($targets) . '. Do not list items that start on it.'
            : 'There is no context page: this is the end of the chapter.';
        $lines[] = 'The images are attached in this order: page ' . implode(', page ', $order) . '.';
        $lines[] = '';

        foreach ($order as $n) {
            $p = $pages[$n] ?? null;
            if (!$p) {
                continue;
            }
            $role = in_array($n, $targets, true) ? 'TARGET' : 'CONTEXT';
            $lines[] = "=== Page $n ($role" . (isset($p['printed_page']) && $p['printed_page'] ? ", printed page {$p['printed_page']}" : '') . ') ===';
            $hints = self::hints($p);
            if ($hints) {
                $lines[] = 'Labels the text mentions on this page (make sure each caption or heading is listed): ' . implode('; ', $hints);
            }
            if (!empty($p['has_figure_image'])) {
                $lines[] = 'This page contains at least one picture or diagram.';
            }
            if (!empty($p['figure_labels'])) {
                $lines[] = 'Words read from inside the diagrams by OCR (may contain errors): ' . implode(' | ', array_slice($p['figure_labels'], 0, 40));
            }
            $lines[] = 'Extracted text:';
            $lines[] = trim((string) ($p['text'] ?? ''));
            $lines[] = '';
        }

        if ($feedback) {
            $lines[] = '=== Fix these problems from your previous answer ===';
            $lines[] = $feedback;
            $lines[] = 'Return the complete JSON again for the TARGET pages, with the problems fixed.';
        } else {
            $lines[] = 'Return the JSON now.';
        }
        return implode("\n", $lines);
    }

    /** @return string[] */
    private static function hints(array $p): array
    {
        $out = [];
        foreach (['figures', 'tables', 'examples', 'exercises', 'activities', 'headings', 'exam_tags'] as $k) {
            foreach ($p['markers'][$k]['found'] ?? [] as $f) {
                $out[] = $f;
            }
        }
        return array_values(array_unique($out));
    }
}
