<?php

declare(strict_types=1);

namespace App\Services\Lessons\Write;

/**
 * Turns the model's reply into clean page content (content.v1) and lists what is missing or malformed.
 * Small slips are fixed in code (answer "B" -> 1, "true" -> true, "2.5" -> 2.5) so a good reply is never thrown away.
 */
final class ContentValidator
{
    /**
     * @param array $plan the page from plan.json (block ids and types must match)
     * @return array{ok:bool, page:?array, errors:string[]}
     */
    public function parse(string $raw, array $plan): array
    {
        $data = $this->decode($raw);
        if (!is_array($data)) {
            return ['ok' => false, 'page' => null, 'errors' => ['Your reply was not valid JSON. Reply with one JSON object only, no other text.']];
        }
        // Accept {"pages":[{...}]} or {"page_content":{...}} wrappers.
        foreach (['pages', 'page_content', 'content', 'result'] as $k) {
            if (!isset($data['blocks']) && isset($data[$k]) && is_array($data[$k])) {
                $data = array_is_list($data[$k]) ? ($data[$k][0] ?? []) : $data[$k];
            }
        }
        if (!isset($data['blocks']) || !is_array($data['blocks'])) {
            return ['ok' => false, 'page' => null, 'errors' => ['The JSON must have a "blocks" array.']];
        }

        $errors = [];
        $planned = [];
        foreach ($plan['blocks'] as $pb) {
            $planned[$pb['id']] = $pb;
        }
        $blocks = [];
        $seen = [];
        foreach (array_values($data['blocks']) as $i => $b) {
            if (!is_array($b)) {
                $errors[] = 'Block ' . ($i + 1) . ' is not an object.';
                continue;
            }
            $id = (string) ($b['id'] ?? '');
            if (!isset($planned[$id])) {
                // Same position as the plan, if the id is missing or wrong.
                $id = array_keys($planned)[$i] ?? $id;
            }
            if (!isset($planned[$id])) {
                $errors[] = 'Block ' . ($i + 1) . ' has id "' . ($b['id'] ?? '') . '", which is not in the plan.';
                continue;
            }
            if (isset($seen[$id])) {
                $errors[] = "Block $id appears twice.";
                continue;
            }
            $seen[$id] = true;
            $type = $planned[$id]['type'];   // the plan decides the type
            $blocks[] = $this->block($b, $id, $type, $planned[$id]['heading'] ?? null, $errors, count($planned[$id]['practice'] ?? []));
        }
        foreach ($planned as $id => $pb) {
            if (!isset($seen[$id])) {
                $errors[] = "Block $id ({$pb['type']}, \"{$pb['heading']}\") is missing. Write every planned block.";
            }
        }

        $page = [
            'key' => $plan['key'],
            'title' => $this->str($data['title'] ?? null, 120) ?? $plan['title'],
            'intro' => $this->str($data['intro'] ?? null, 600),
            'objectives' => $this->strings($data['objectives'] ?? [], 300),
            'blocks' => $blocks,
            'remember' => array_slice($this->strings($data['remember'] ?? [], 300), 0, 4),   // 5+ points: keep the first 4
            'teacher_note' => [
                'scope' => $this->str($data['teacher_note']['scope'] ?? null, 600),
                'additions' => $this->strings($data['teacher_note']['additions'] ?? [], 300),
                'corrections' => $this->strings($data['teacher_note']['corrections'] ?? [], 300),
            ],
        ];
        if (!$page['intro']) {
            $errors[] = 'The page has no "intro".';
        }
        return ['ok' => (bool) $blocks, 'page' => $page, 'errors' => $errors];
    }

    private function block(array $b, string $id, string $type, ?string $plannedHeading, array &$errors, int $plannedQuestions = 0): array
    {
        $out = ['id' => $id, 'type' => $type, 'heading' => $this->str($b['heading'] ?? null, 120) ?? $plannedHeading];
        $where = "Block $id ($type)";
        switch ($type) {
            case 'notes':
                $paras = [];
                foreach ((array) ($b['paragraphs'] ?? []) as $p) {
                    $p = is_string($p) ? ['text' => $p, 'say' => null] : (array) $p;
                    $text = $this->str($p['text'] ?? null, 2000);
                    if ($text) {
                        $paras[] = ['text' => $text, 'say' => $this->str($p['say'] ?? null, 2000)];
                    }
                }
                if (!$paras) {
                    $errors[] = "$where has no paragraphs.";
                }
                $out['paragraphs'] = $paras;
                $out['rule'] = $this->str($b['rule'] ?? null, 300);
                break;
            case 'walk':
            case 'example':
                $steps = [];
                foreach ((array) ($b['steps'] ?? []) as $s) {
                    $s = is_string($s) ? ['text' => $s] : (array) $s;
                    $text = $this->str($s['text'] ?? null, 600);
                    if (!$text) {
                        continue;
                    }
                    $step = ['text' => $text, 'say' => $this->str($s['say'] ?? null, 1200) ?? $text, 'shows' => $this->str($s['shows'] ?? null, 600)];
                    if ($type === 'example') {
                        $role = strtolower((string) ($s['role'] ?? 'step'));
                        $step = ['role' => in_array($role, ContentContract::STEP_ROLES, true) ? $role : 'step'] + $step;
                    }
                    $steps[] = $step;
                }
                if (count($steps) < 2) {
                    $errors[] = "$where needs at least 2 steps.";
                }
                $out['intro'] = $this->str($b['intro'] ?? null, 600);
                $out['steps'] = $steps;
                break;
            case 'explore':
            case 'widget':
                $tasks = [];
                foreach ((array) ($b['tasks'] ?? []) as $t) {
                    $t = (array) $t;
                    $do = $this->str($t['do'] ?? null, 400);
                    if ($do) {
                        $tasks[] = ['do' => $do, 'notice' => $this->str($t['notice'] ?? null, 400), 'say' => $this->str($t['say'] ?? null, 800) ?? $do];
                    }
                }
                if (!$tasks) {
                    $errors[] = "$where has no tasks.";
                }
                $out['intro'] = $this->str($b['intro'] ?? null, 600);
                $out['tasks'] = $tasks;
                if ($type === 'widget') {
                    $out['safety'] = $this->str($b['safety'] ?? null, 400);
                }
                break;
            case 'practice':
                $qs = [];
                foreach (array_values((array) ($b['questions'] ?? [])) as $qi => $q) {
                    if (is_array($q)) {
                        $qs[] = $this->question($q, $qi + 1, $where, $errors);
                    }
                }
                if (!$qs && $plannedQuestions > 0) {
                    $errors[] = "$where has no questions.";
                }
                $out['questions'] = $qs;
                break;
        }
        return $out;
    }

    private function question(array $q, int $n, string $where, array &$errors): array
    {
        $kind = strtolower(str_replace([' ', '-'], '_', (string) ($q['kind'] ?? '')));
        $kind = ['multiple_choice' => 'mcq', 'truefalse' => 'true_false', 'tf' => 'true_false', 'matching' => 'match', 'ordering' => 'order', 'sequence' => 'order', 'labelling' => 'label', 'labeling' => 'label', 'number' => 'numeric', 'short_answer' => 'short_text', 'text' => 'short_text'][$kind] ?? $kind;
        if (!in_array($kind, ContentContract::KINDS, true)) {
            $kind = 'short_text';
        }
        $w = "$where question $n ($kind)";
        $out = [
            'n' => (int) ($q['n'] ?? $n) ?: $n,
            'source' => strtolower((string) ($q['source'] ?? 'ours')) === 'book' ? 'book' : 'ours',
            'item' => $this->str($q['item'] ?? null, 20),
            'kind' => $kind,
            'prompt' => $this->str($q['prompt'] ?? $q['question'] ?? null, 1500),
            'say' => $this->str($q['say'] ?? null, 1500),
            'hint' => $this->str($q['hint'] ?? null, 400),
            'explain' => $this->str($q['explain'] ?? $q['explanation'] ?? null, 1500),
            'mistakes' => array_values(array_filter(array_map(fn ($m) => is_array($m) && !empty($m['wrong']) ? ['wrong' => $this->str($m['wrong'], 200), 'why' => $this->str($m['why'] ?? null, 400)] : null, (array) ($q['mistakes'] ?? [])))),
        ];
        foreach (['prompt', 'hint', 'explain'] as $f) {
            if (!$out[$f]) {
                $errors[] = "$w has no \"$f\".";
            }
        }
        switch ($kind) {
            case 'mcq':
                $opts = $this->strings($q['options'] ?? [], 300);
                $ans = $q['answer'] ?? null;
                if (is_string($ans) && preg_match('/^[A-Ea-e]$/', trim($ans))) {
                    $ans = ord(strtoupper(trim($ans))) - 65;            // "B" -> 1
                } elseif (is_string($ans) && !is_numeric($ans)) {
                    $hit = array_search(mb_strtolower(trim($ans)), array_map('mb_strtolower', $opts), true);
                    $ans = $hit === false ? null : $hit;                  // answer given as the option text
                }
                $ans = is_numeric($ans) ? (int) $ans : null;
                if (count($opts) < 3 || count($opts) > 5) {
                    $errors[] = "$w must have 3 to 5 options.";
                } elseif (count(array_unique(array_map('mb_strtolower', $opts))) !== count($opts)) {
                    $errors[] = "$w has two options that are the same.";
                }
                if ($ans === null || $ans < 0 || $ans >= count($opts)) {
                    $errors[] = "$w: \"answer\" must be the index (from 0) of the one correct option.";
                }
                $out['options'] = $opts;
                $out['answer'] = $ans;
                break;
            case 'true_false':
                $a = $q['answer'] ?? null;
                $a = is_bool($a) ? $a : (in_array(strtolower((string) $a), ['true', 't', 'yes', '1'], true) ? true : (in_array(strtolower((string) $a), ['false', 'f', 'no', '0'], true) ? false : null));
                if ($a === null) {
                    $errors[] = "$w: \"answer\" must be true or false.";
                }
                $out['answer'] = $a;
                break;
            case 'match':
                $pairs = [];
                foreach ((array) ($q['pairs'] ?? []) as $p) {
                    $p = is_array($p) ? array_values(array_key_exists('left', $p) ? [$p['left'] ?? null, $p['right'] ?? null] : $p) : [];
                    if (count($p) >= 2 && is_scalar($p[0]) && is_scalar($p[1]) && trim((string) $p[0]) !== '' && trim((string) $p[1]) !== '') {
                        $pairs[] = [trim((string) $p[0]), trim((string) $p[1])];
                    }
                }
                if (count($pairs) < 3) {
                    $errors[] = "$w needs at least 3 pairs.";
                } elseif (count(array_unique(array_column($pairs, 0))) !== count($pairs) || count(array_unique(array_column($pairs, 1))) !== count($pairs)) {
                    $errors[] = "$w: every left and every right side must be different.";
                }
                $out['pairs'] = $pairs;
                break;
            case 'sort':
                $groups = [];
                foreach ((array) ($q['groups'] ?? []) as $g) {
                    $g = (array) $g;
                    $name = $this->str($g['name'] ?? null, 120);
                    $members = $this->strings($g['members'] ?? [], 200);
                    if ($name && $members) {
                        $groups[] = ['name' => $name, 'members' => $members];
                    }
                }
                $all = array_merge(...array_map(fn ($g) => array_map('mb_strtolower', $g['members']), $groups ?: [['members' => []]]));
                if (count($groups) < 2) {
                    $errors[] = "$w needs at least 2 groups, each with members.";
                } elseif (count(array_unique($all)) !== count($all)) {
                    $errors[] = "$w: a member is in more than one group.";
                }
                $out['groups'] = $groups;
                break;
            case 'order':
                $seq = $this->strings($this->listOf($q['sequence'] ?? []), 300);
                if (count($seq) < 3) {
                    $errors[] = "$w needs a \"sequence\" of at least 3 items.";
                } elseif (count(array_unique(array_map('mb_strtolower', $seq))) !== count($seq)) {
                    $errors[] = "$w: the sequence repeats an item.";
                }
                $out['sequence'] = $seq;
                break;
            case 'label':
                $t = $this->strings($this->listOf($q['targets'] ?? []), 120);
                if (count($t) < 2) {
                    $errors[] = "$w needs at least 2 \"targets\".";
                }
                $out['targets'] = $t;
                break;
            case 'numeric':
                $a = $q['answer'] ?? null;
                $a = is_numeric(is_string($a) ? str_replace([',', ' '], ['', ''], $a) : $a) ? (float) (is_string($a) ? str_replace([',', ' '], ['', ''], $a) : $a) : null;
                if ($a === null) {
                    $errors[] = "$w: \"answer\" must be a number.";
                }
                $out['answer'] = $a;
                $out['unit'] = $this->str($q['unit'] ?? null, 40);
                $out['tolerance'] = is_numeric($q['tolerance'] ?? null) ? abs((float) $q['tolerance']) : 0.0;
                break;
            case 'short_text':
                $acc = $this->strings($this->listOf($q['accept'] ?? []), 200);
                if (!$acc) {
                    $errors[] = "$w needs \"accept\": key words a correct answer must contain.";
                }
                $out['accept'] = $acc;
                $out['model_answer'] = $this->str($q['model_answer'] ?? null, 1200);
                if (!$out['model_answer']) {
                    $errors[] = "$w has no \"model_answer\".";
                }
                break;
        }
        return $out;
    }

    public function decode(string $raw): ?array
    {
        $s = trim($raw);
        $s = preg_replace('/^<think>.*?<\/think>\s*/s', '', $s) ?? $s;
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

    /** "a, b, c" or "a; b" given where a list belongs -> ["a", "b", "c"]. */
    private function listOf(mixed $v): mixed
    {
        return is_string($v) && preg_match('/[,;]/', $v) ? preg_split('/\s*[,;]\s*/', trim($v)) : $v;
    }

    private function str(mixed $v, int $max): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' || strtolower($s) === 'null' ? null : mb_substr($s, 0, $max);
    }

    private function strings(mixed $v, int $max): array
    {
        if (is_string($v)) {
            $v = [$v];
        }
        return array_values(array_filter(array_map(fn ($s) => is_scalar($s) ? mb_substr(trim((string) $s), 0, $max) : '', is_array($v) ? $v : []), fn ($s) => $s !== ''));
    }
}
