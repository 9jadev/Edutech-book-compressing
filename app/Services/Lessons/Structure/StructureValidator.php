<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

/**
 * Turns the model's raw reply into clean items, or explains what is wrong so the model can fix it.
 * Our own check, so it works the same on Qwen (JSON mode only) and on Gemini/Claude.
 */
final class StructureValidator
{
    public const TYPES = [
        'chapter_title', 'heading', 'subheading', 'paragraph', 'list', 'definition', 'rule_box',
        'figure', 'table', 'activity', 'example', 'example_part', 'exercise', 'question',
        'question_part', 'summary', 'note', 'other',
    ];

    /** Types whose full wording must be copied. */
    public const NEEDS_TEXT = ['question', 'question_part', 'example', 'example_part', 'definition', 'rule_box'];

    /** Common slips models make, mapped to our types. */
    private const TYPE_ALIASES = [
        'title' => 'chapter_title', 'section' => 'heading', 'section_heading' => 'heading', 'sub_heading' => 'subheading',
        'text' => 'paragraph', 'bullet_list' => 'list', 'bullets' => 'list', 'image' => 'figure', 'diagram' => 'figure',
        'photo' => 'figure', 'experiment' => 'activity', 'practical' => 'activity', 'question_set' => 'exercise',
        'subquestion' => 'question_part', 'sub_question' => 'question_part', 'part' => 'question_part', 'box' => 'rule_box',
        'formula' => 'rule_box', 'footnote' => 'note', 'caption' => 'figure',
    ];

    /**
     * @param int[] $targets
     * @return array{ok:bool, items:array, uncertain:array, errors:string[], dropped:int}
     */
    public function parse(string $raw, array $targets): array
    {
        $data = $this->decode($raw);
        if (!is_array($data)) {
            return ['ok' => false, 'items' => [], 'uncertain' => [], 'errors' => ['Your reply was not valid JSON. Reply with one JSON object only, no other text.'], 'dropped' => 0];
        }
        if (array_is_list($data) && isset($data[0]['type'])) {
            $data = ['items' => $data];   // model returned the bare items array
        }
        $rawItems = $data['items'] ?? null;
        if (!is_array($rawItems)) {
            return ['ok' => false, 'items' => [], 'uncertain' => [], 'errors' => ['The JSON must have an "items" array.'], 'dropped' => 0];
        }

        $errors = [];
        $items = [];
        $dropped = 0;
        foreach (array_values($rawItems) as $i => $it) {
            $n = $i + 1;
            if (!is_array($it)) {
                $errors[] = "Item $n is not an object.";
                continue;
            }
            $type = strtolower(trim((string) ($it['type'] ?? '')));
            $type = self::TYPE_ALIASES[$type] ?? $type;
            if (!in_array($type, self::TYPES, true)) {
                $errors[] = "Item $n has unknown type \"" . ($it['type'] ?? '') . '". Use one of: ' . implode(', ', self::TYPES) . '.';
                continue;
            }
            $page = filter_var($it['page'] ?? null, FILTER_VALIDATE_INT);
            if ($page === false || $page === null) {
                $errors[] = "Item $n ($type) has no valid page number.";
                continue;
            }
            if (!in_array($page, $targets, true)) {
                $dropped++;   // starts on the context page or outside the group: the next group lists it
                continue;
            }
            $item = [
                'page' => $page,
                'type' => $type,
                'label' => $this->str($it['label'] ?? null, 80),
                'title' => $this->str($it['title'] ?? null, 400),
                'text' => $this->str($it['text'] ?? null, 6000),
                'rows' => $this->rows($it['rows'] ?? null),
                'figure_labels' => $this->strings($it['figure_labels'] ?? null),
                'parent' => $this->str($it['parent'] ?? null, 80),
                'continues_on_next_page' => (bool) ($it['continues_on_next_page'] ?? $it['continues'] ?? false),
                'exam_tags' => $this->strings($it['exam_tags'] ?? []) ?? [],
            ];
             // Lettered section titles ("A.", "B.") are headings, whatever the model called them.
            if (in_array($type, ['subheading', 'paragraph'], true) && $item['label'] !== null && preg_match('/^[A-H]\.$/', $item['label']) && $item['title'] !== null) {
                $item['type'] = $type = 'heading';
            }
            $what = $type . ($item['label'] ? " \"{$item['label']}\"" : '') . " on page $page";
            if (in_array($type, self::NEEDS_TEXT, true) && ($item['text'] === null || mb_strlen($item['text']) < 2)) {
                $errors[] = "The $what has no text. Copy its full wording into \"text\".";
            }
            if ($type === 'table' && !$item['rows']) {
                $errors[] = "The $what has no rows. Copy every cell into \"rows\".";
            }
            if (in_array($type, ['question_part', 'example_part'], true) && $item['parent'] === null) {
                $errors[] = "The $what has no parent. Set \"parent\" to its question or example label.";
            }
            $items[] = $item;
        }

        if (!$items && !$errors) {
            $errors[] = 'No items were listed for the target pages ' . implode(', ', $targets) . '. List everything printed on them.';
        }

        $uncertain = [];
        foreach ((array) ($data['uncertain'] ?? []) as $u) {
            if (is_array($u) && isset($u['note'])) {
                $uncertain[] = ['page' => (int) ($u['page'] ?? 0), 'note' => (string) $u['note']];
            } elseif (is_string($u)) {
                $uncertain[] = ['page' => 0, 'note' => $u];
            }
        }

        return ['ok' => !$errors, 'items' => $items, 'uncertain' => $uncertain, 'errors' => $errors, 'dropped' => $dropped];
    }

    /** Accepts plain JSON, JSON inside ``` fences, or JSON with text around it. */
    public function decode(string $raw): ?array
    {
        $s = trim($raw);
        $s = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $s) ?? $s;
        $d = json_decode($s, true);
        if (is_array($d)) {
            return $d;
        }
        $a = strpos($s, '{');
        $b = strrpos($s, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $d = json_decode(substr($s, $a, $b - $a + 1), true);
            if (is_array($d)) {
                return $d;
            }
        }
        return null;
    }

    private function str(mixed $v, int $max): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' || strtolower($s) === 'null' ? null : mb_substr($s, 0, $max);
    }

    private function strings(mixed $v): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        $out = array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v), fn ($x) => $x !== ''));
        return $out ?: null;
    }

    private function rows(mixed $v): ?array
    {
        if (!is_array($v) || !$v) {
            return null;
        }
        $rows = [];
        foreach ($v as $r) {
            if (is_array($r)) {
                $rows[] = array_map(fn ($c) => is_scalar($c) || $c === null ? trim((string) $c) : json_encode($c), array_values($r));
            }
        }
        return $rows ?: null;
    }
}
