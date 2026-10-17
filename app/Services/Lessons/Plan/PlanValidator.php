<?php

declare(strict_types=1);

namespace App\Services\Lessons\Plan;

/**
 * Turns the model's reply into clean page plans. Fixes small slips (type aliases, strings for
 * arrays) and reports what it cannot fix, so the model can be asked again.
 */
final class PlanValidator
{
    /** notes = text + small figures; walk = 3D step-through with narration; explore = free 3D model to turn/tap;
     *  widget = hands-on simulation or virtual activity; example = worked example played step by step;
     *  practice = auto-checked questions. */
    public const BLOCK_TYPES = ['notes', 'walk', 'explore', 'widget', 'example', 'practice'];

    public const VISUAL_MODES = ['3d', 'panel', 'none'];

    public const PRACTICE_KINDS = ['mcq', 'true_false', 'match', 'sort', 'order', 'label', 'numeric', 'short_text'];

    private const BLOCK_ALIASES = [
        'text' => 'notes', 'note' => 'notes', 'explanation' => 'notes', 'reading' => 'notes',
        'walkthrough' => 'walk', 'walk_through' => 'walk', 'animation' => 'walk', 'story' => 'walk',
        '3d' => 'explore', 'model' => 'explore', 'interactive' => 'explore', 'explorer' => 'explore',
        'activity' => 'widget', 'simulation' => 'widget', 'experiment' => 'widget', 'lab' => 'widget', 'virtual_lab' => 'widget',
        'worked_example' => 'example', 'examples' => 'example',
        'quiz' => 'practice', 'questions' => 'practice', 'exercise' => 'practice', 'exercises' => 'practice', 'check' => 'practice',
    ];

    private const MODE_ALIASES = ['3-d' => '3d', 'three_d' => '3d', 'threejs' => '3d', '2d' => 'panel', 'svg' => 'panel', 'diagram' => 'panel', 'chart' => 'panel', '' => 'none', 'null' => 'none'];

    private const KIND_ALIASES = [
        'multiple_choice' => 'mcq', 'choice' => 'mcq', 'tf' => 'true_false', 'truefalse' => 'true_false', 'true/false' => 'true_false',
        'matching' => 'match', 'pair' => 'match', 'pairs' => 'match', 'sorting' => 'sort', 'group' => 'sort', 'classify' => 'sort',
        'sequence' => 'order', 'ordering' => 'order', 'labelling' => 'label', 'labeling' => 'label', 'diagram_label' => 'label',
        'num' => 'numeric', 'number' => 'numeric', 'calculation' => 'numeric', 'fill' => 'short_text', 'fill_in' => 'short_text',
        'fixed' => 'short_text', 'text' => 'short_text', 'short_answer' => 'short_text', 'open' => 'short_text', 'short' => 'short_text',
        'explain' => 'short_text', 'describe' => 'short_text', 'compare' => 'short_text', 'apply' => 'short_text', 'list' => 'short_text',
        'define' => 'short_text', 'written' => 'short_text', 'drag_drop' => 'sort', 'drag_and_drop' => 'sort', 'categorise' => 'sort',
        'categorize' => 'sort', 'arrange' => 'order', 'rank' => 'order', 'calculate' => 'numeric', 'hotspot' => 'label', 'identify' => 'label',
    ];

    /**
     * @param string[] $expectedFiles page keys asked for in this call
     * @return array{ok:bool, pages:array<string,array>, errors:string[]}
     */
    public function parse(string $raw, array $expectedFiles): array
    {
        $data = $this->decode($raw);
        if (!is_array($data)) {
            return ['ok' => false, 'pages' => [], 'errors' => ['Your reply was not valid JSON. Reply with one JSON object only, no other text.']];
        }
        $rawPages = $this->findPages($data, $expectedFiles);
        if ($rawPages === null) {
            return ['ok' => false, 'pages' => [], 'errors' => ['The JSON must have a "pages" array.']];
        }

        $errors = [];
        $pages = [];
        foreach ($rawPages as $i => $p) {
            if (!is_array($p)) {
                $errors[] = 'Page entry ' . ($i + 1) . ' is not an object.';
                continue;
            }
            $key = $this->matchKey((string) ($p['page'] ?? $p['file'] ?? $p['key'] ?? ''), $expectedFiles);
            if ($key === null) {
                $errors[] = 'Page entry ' . ($i + 1) . ' has page "' . ($p['page'] ?? $p['file'] ?? '') . '", which was not asked for. Use the exact page keys given.';
                continue;
            }
            $blocks = [];
            foreach (array_values(is_array($p['blocks'] ?? null) ? $p['blocks'] : []) as $bi => $b) {
                if (!is_array($b)) {
                    $errors[] = "$key: block " . ($bi + 1) . ' is not an object.';
                    continue;
                }
                $type = $this->alias((string) ($b['type'] ?? ''), self::BLOCK_ALIASES);
                if (!in_array($type, self::BLOCK_TYPES, true)) {
                    $errors[] = "$key: block " . ($bi + 1) . ' has unknown type "' . ($b['type'] ?? '') . '". Use one of: ' . implode(', ', self::BLOCK_TYPES) . '.';
                    continue;
                }
                $blocks[] = [
                    'id' => 'b' . (count($blocks) + 1),
                    'type' => $type,
                    'heading' => $this->str($b['heading'] ?? $b['title'] ?? null, 120),
                    'items' => $this->ids($b['items'] ?? []),
                    'purpose' => $this->str($b['purpose'] ?? $b['goal'] ?? null, 400),
                    'visual' => $this->visual($b['visual'] ?? null, $key, $bi + 1, $errors),
                    'practice' => $this->practice($b['practice'] ?? [], $key, $bi + 1, $errors),
                ];
            }
            $pages[$key] = [
                'title' => $this->str($p['title'] ?? null, 120),
                'summary' => $this->str($p['summary'] ?? null, 600),
                'objectives' => $this->strings($p['objectives'] ?? []),
                'opening' => $this->str($p['opening'] ?? null, 600),
                'blocks' => $blocks,
                'additions' => $this->additions($p['additions'] ?? []),
            ];
        }
        foreach ($expectedFiles as $f) {
            if (!isset($pages[$f])) {
                $errors[] = "You did not return a plan for page \"$f\".";
            }
        }
        return ['ok' => $pages !== [], 'pages' => $pages, 'errors' => $errors];
    }

    /**
     * Models often wrap the pages differently. Accept:
     *   {"pages": [...]}  [...]  {"page": "...", "blocks": [...]}  {"plans"|"result"|"data": [...]}
     *   {"pages": {"<key>": {...}}}  {"<key>": {...}, ...}  {"result": {"pages": [...]}}
     */
    private function findPages(array $data, array $expected, int $depth = 0): ?array
    {
        $isPage = fn ($v) => is_array($v) && (isset($v['blocks']) || isset($v['page']) || isset($v['file']));
        if (array_is_list($data)) {
            return array_filter($data, $isPage) ? $data : null;
        }
        if ($isPage($data) && isset($data['blocks'])) {
            return [$data];
        }
        foreach (['pages', 'plans', 'page_plans', 'lesson_pages', 'result', 'data', 'plan'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $v = $data[$k];
                if (array_is_list($v)) {
                    return $v;
                }
                if ($isPage($v) && isset($v['blocks'])) {
                    return [$v];
                }
                $found = $depth < 2 ? $this->findPages($v, $expected, $depth + 1) : null;
                if ($found !== null) {
                    return $found;
                }
            }
        }
        // Keyed by page: {"Chapter-01/02-1-x.html": {...}} or {"02-1-x.html": {...}}
        $keyed = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && is_array($v) && isset($v['blocks']) && preg_match('/\.html$|^\d{2}-/', $k)) {
                $keyed[] = ['page' => $v['page'] ?? $k] + $v;
            }
        }
        return $keyed ?: null;
    }

    public function decode(string $raw): ?array
    {
        $s = trim($raw);
        $s = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $s) ?? $s;
        $s = preg_replace('/^<think>.*?<\/think>\s*/s', '', $s) ?? $s;   // in case thinking text leaks into the reply
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

    /** Accept "Chapter-01-x/02-1-y.html", "02-1-y.html" or "02-1-y". */
    private function matchKey(string $given, array $expected): ?string
    {
        $g = trim($given);
        if ($g === '') {
            return count($expected) === 1 ? $expected[0] : null;   // one page asked for: that must be it
        }
        if (in_array($g, $expected, true)) {
            return $g;
        }
        $base = basename($g);
        $base = str_ends_with($base, '.html') ? $base : "$base.html";
        $hits = array_values(array_filter($expected, fn ($e) => basename($e) === $base));
        if (count($hits) === 1) {
            return $hits[0];
        }
        // Small typo in the file name ("…-in-a-plants.html"): take the one close match, if only one is close.
        $close = array_values(array_filter($expected, fn ($e) => levenshtein(substr(basename($e), 0, 255), substr($base, 0, 255)) <= 3));
        return count($close) === 1 ? $close[0] : null;
    }

    private function visual(mixed $v, string $key, int $n, array &$errors): array
    {
        if (!is_array($v)) {
            return ['mode' => 'none', 'subject' => null, 'shows' => null, 'interaction' => null];
        }
        $mode = $this->alias((string) ($v['mode'] ?? $v['type'] ?? 'none'), self::MODE_ALIASES);
        if (!in_array($mode, self::VISUAL_MODES, true)) {
            $errors[] = "$key: block $n has visual mode \"$mode\". Use 3d, panel or none.";
            $mode = 'none';
        }
        return [
            'mode' => $mode,
            'subject' => $this->str($v['subject'] ?? null, 200),
            'shows' => $this->str($v['shows'] ?? $v['description'] ?? null, 600),
            'interaction' => $this->str($v['interaction'] ?? null, 400),
        ];
    }

    private function practice(mixed $list, string $key, int $n, array &$errors): array
    {
        $out = [];
        foreach (is_array($list) ? array_values($list) : [] as $q) {
            if (!is_array($q)) {
                continue;
            }
            $given = (string) ($q['kind'] ?? $q['type'] ?? '');
            $kind = $this->alias($given, self::KIND_ALIASES);
            if (!in_array($kind, self::PRACTICE_KINDS, true)) {
                // Unknown kind ("compare", "apply", "explain"…): keep the question as a written answer
                // instead of throwing the whole reply away. Step 4 can still change the kind.
                $kind = 'short_text';
            }
            $source = strtolower((string) ($q['source'] ?? 'ours')) === 'book' ? 'book' : 'ours';
            $out[] = [
                'kind' => $kind,
                'source' => $source,
                'item' => $this->str($q['item'] ?? null, 20),
                'about' => $this->str($q['about'] ?? $q['question'] ?? null, 300),
            ];
        }
        return $out;
    }

    private function additions(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $a) {
            if (is_string($a) && trim($a) !== '') {
                $out[] = ['topic' => mb_substr(trim($a), 0, 200), 'reason' => null];
            } elseif (is_array($a) && !empty($a['topic'])) {
                $out[] = ['topic' => mb_substr(trim((string) $a['topic']), 0, 200), 'reason' => $this->str($a['reason'] ?? null, 300)];
            }
        }
        return $out;
    }

    private function ids(mixed $v): array
    {
        if (is_string($v)) {
            $v = preg_split('/[\s,]+/', $v) ?: [];
        }
        $out = [];
        foreach (is_array($v) ? $v : [] as $id) {
            if (is_scalar($id) && preg_match('/^s?\d+$/i', trim((string) $id))) {
                $id = trim((string) $id);
                $out[] = 's' . str_pad(ltrim(ltrim($id, 'sS'), '0') ?: '0', 3, '0', STR_PAD_LEFT);
            }
        }
        return $out;
    }

    private function alias(string $v, array $map): string
    {
        $v = strtolower(trim($v));
        $v = str_replace([' ', '-'], '_', $v);
        return $map[$v] ?? $v;
    }

    private function str(mixed $v, int $max): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' || strtolower($s) === 'null' ? null : mb_substr($s, 0, $max);
    }

    private function strings(mixed $v): array
    {
        if (is_string($v)) {
            $v = [$v];
        }
        return array_values(array_filter(array_map(fn ($s) => is_scalar($s) ? mb_substr(trim((string) $s), 0, 300) : '', is_array($v) ? $v : [])));
    }
}
