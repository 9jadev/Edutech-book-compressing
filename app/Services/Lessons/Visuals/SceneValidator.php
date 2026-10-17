<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

/**
 * Turns the model's reply into clean scenes: fixes small slips (named colours, strings for numbers,
 * a single id for a list), expands prefabs into plain parts, and lists what it cannot fix.
 */
final class SceneValidator
{
    private const NAMED = [
        'red' => '#e53935', 'green' => '#43a047', 'blue' => '#1e88e5', 'yellow' => '#fdd835', 'orange' => '#fb8c00', 'purple' => '#8e24aa',
        'pink' => '#ec407a', 'brown' => '#8d6e63', 'grey' => '#90a4ae', 'gray' => '#90a4ae', 'black' => '#263238', 'white' => '#fafafa',
        'lightblue' => '#81d4fa', 'darkgreen' => '#2e7d32', 'lightgreen' => '#9ccc65', 'cyan' => '#26c6da', 'gold' => '#ffca28',
    ];

    /**
     * @param string[] $blockIds blocks that need a scene on this page
     * @return array{ok:bool, scenes:array<string,array>, errors:string[]}
     */
    public function parse(string $raw, array $blockIds): array
    {
        $data = $this->decode($raw);
        if (!is_array($data)) {
            return ['ok' => false, 'scenes' => [], 'errors' => ['Your reply was not valid JSON. Reply with one JSON object only, no other text.']];
        }
        $list = $data['scenes'] ?? (isset($data['parts']) ? [$data] : (array_is_list($data) ? $data : null));
        if (!is_array($list)) {
            return ['ok' => false, 'scenes' => [], 'errors' => ['The JSON must have a "scenes" array.']];
        }
        $errors = [];
        $scenes = [];
        foreach (array_values($list) as $i => $s) {
            if (!is_array($s)) {
                $errors[] = 'Scene ' . ($i + 1) . ' is not an object.';
                continue;
            }
            $block = (string) ($s['block'] ?? (count($blockIds) === 1 ? $blockIds[0] : ''));
            if (!in_array($block, $blockIds, true)) {
                $errors[] = 'Scene ' . ($i + 1) . ' is for block "' . $block . '", which needs no scene. Use one of: ' . implode(', ', $blockIds) . '.';
                continue;
            }
            $scenes[$block] = $this->scene($s, $block, $errors);
        }
        return ['ok' => $scenes !== [], 'scenes' => $scenes, 'errors' => $errors];
    }

    private function scene(array $s, string $block, array &$errors): array
    {
        $w = "Scene $block";
        $parts = [];
        foreach (array_values((array) ($s['parts'] ?? [])) as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!empty($p['prefab'])) {
                if (!in_array($p['prefab'], Prefabs::NAMES, true)) {
                    $errors[] = "$w part " . ($i + 1) . ' uses prefab "' . $p['prefab'] . '", which does not exist. Use: ' . implode(', ', Prefabs::NAMES) . '.';
                    continue;
                }
                $p['id'] = $this->id($p['id'] ?? $p['prefab']);
                foreach (Prefabs::expand($p) as $q) {
                    $parts[] = $q;
                }
                continue;
            }
            $part = $this->part($p, $w, $errors);
            if ($part) {
                $parts[] = $part;
            }
        }
        $seen = [];
        foreach ($parts as $p) {
            if (isset($seen[$p['id']])) {
                $errors[] = "$w has two parts with id \"{$p['id']}\". Ids must be unique.";
            }
            $seen[$p['id']] = true;
        }

        $states = [];
        foreach (array_values((array) ($s['states'] ?? [])) as $st) {
            if (!is_array($st)) {
                continue;
            }
            $labels = $st['labels'] ?? null;
            $set = [];
            foreach ((array) ($st['set'] ?? []) as $k => $v) {
                $set[(string) $k] = is_numeric($v) ? (float) $v : (is_array($v) ? array_map('floatval', array_values($v)) : (is_bool($v) ? $v : $this->colour($v) ?? $v));
            }
            $states[] = array_filter([
                'show' => $this->ids($st['show'] ?? []),
                'hide' => $this->ids($st['hide'] ?? []),
                'highlight' => $this->ids($st['highlight'] ?? []),
                'labels' => in_array($labels, ['all', 'none'], true) ? $labels : ($labels === null ? null : $this->ids($labels)),
                'set' => $set ?: null,
                'camera' => is_array($st['camera'] ?? null) ? $this->camera($st['camera']) : null,
                'caption' => isset($st['caption']) && is_scalar($st['caption']) ? mb_substr(trim((string) $st['caption']), 0, 140) : null,
            ], fn ($v) => $v !== null && $v !== []);
        }
        if (!$states) {
            $states = [['labels' => 'all']];
        }

        $controls = [];
        foreach (array_values((array) ($s['controls'] ?? [])) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $type = strtolower((string) ($c['type'] ?? ''));
            if (!in_array($type, SceneContract::CONTROLS, true)) {
                $errors[] = "$w has a control of type \"$type\". Use slider, toggle or buttons.";
                continue;
            }
            $ctl = ['id' => $this->id($c['id'] ?? 'c' . (count($controls) + 1)), 'type' => $type, 'label' => mb_substr(trim((string) ($c['label'] ?? '')), 0, 60)];
            if ($type === 'buttons') {
                $ctl['options'] = [];
                foreach ((array) ($c['options'] ?? []) as $o) {
                    if (is_array($o) && isset($o['label'], $o['state']) && is_numeric($o['state'])) {
                        $ctl['options'][] = ['label' => mb_substr((string) $o['label'], 0, 40), 'state' => (int) $o['state']];
                    }
                }
            } else {
                $ctl['sets'] = (string) ($c['sets'] ?? '');
                if ($type === 'slider') {
                    $ctl += ['min' => (float) ($c['min'] ?? 0), 'max' => (float) ($c['max'] ?? 1), 'step' => (float) ($c['step'] ?? 0.05)];
                    $ctl['value'] = (float) ($c['value'] ?? $ctl['min']);
                } else {
                    $ctl['value'] = (bool) ($c['value'] ?? false);
                }
            }
            $controls[] = $ctl;
        }

        $tasks = [];
        foreach ((array) ($s['tasks'] ?? []) as $t) {
            if (is_array($t) && isset($t['task']) && is_numeric($t['task'])) {
                $tasks[] = ['task' => (int) $t['task'], 'target' => (string) ($t['target'] ?? '')];
            }
        }

        $mode = strtolower((string) ($s['mode'] ?? '3d')) === 'panel' ? 'panel' : '3d';
        return array_filter([
            'block' => $block,
            'mode' => $mode,
            'camera' => is_array($s['camera'] ?? null) ? $this->camera($s['camera']) : null,
            'parts' => $parts,
            'states' => $states,
            'controls' => $controls,
            'tasks' => $tasks,
        ], fn ($v) => $v !== null);
    }

    private function part(array $p, string $w, array &$errors): ?array
    {
        $shape = strtolower((string) ($p['shape'] ?? ''));
        $shape = ['cube' => 'box', 'ball' => 'sphere', 'rod' => 'capsule', 'ring' => 'torus', 'label' => 'text', 'dots' => 'particles', 'line' => 'tube'][$shape] ?? $shape;
        $id = $this->id($p['id'] ?? '');
        if ($id === '') {
            $errors[] = "$w has a part without an id.";
            return null;
        }
        if (!in_array($shape, SceneContract::SHAPES, true)) {
            $errors[] = "$w part \"$id\" has shape \"" . ($p['shape'] ?? '') . '". Use one of: ' . implode(', ', SceneContract::SHAPES) . '.';
            return null;
        }
        $out = ['id' => $id, 'shape' => $shape, 'pos' => $this->vec($p['pos'] ?? ($p['from'] ?? [0, 0, 0]))];
        $out['colour'] = $this->colour($p['colour'] ?? $p['color'] ?? null) ?? '#90a4ae';
        foreach (['opacity'] as $k) {
            if (isset($p[$k]) && is_numeric($p[$k])) {
                $out[$k] = max(0.02, min(1, (float) $p[$k]));
            }
        }
        foreach (['radius', 'height', 'length', 'tube', 'arc', 'speed', 'size_text'] as $k) {
            if (isset($p[$k]) && is_numeric($p[$k])) {
                $out[$k] = (float) $p[$k];
            }
        }
        if (isset($p['count']) && is_numeric($p['count'])) {
            $out['count'] = max(1, min(600, (int) $p['count']));
        }
        if (isset($p['rot'])) {
            $out['rot'] = $this->vec($p['rot']);
        }
        if (isset($p['scale'])) {
            $out['scale'] = is_array($p['scale']) ? $this->vec($p['scale'], 1) : (float) $p['scale'];
        }
        if (isset($p['size'])) {
            $out['size'] = is_array($p['size']) ? array_map('floatval', array_values($p['size'])) : (float) $p['size'];
        }
        foreach (['open', 'edges', 'hidden'] as $k) {
            if (!empty($p[$k])) {
                $out[$k] = true;
            }
        }
        if ($shape === 'tube') {
            $out['points'] = array_values(array_map(fn ($q) => $this->vec($q), array_filter((array) ($p['points'] ?? []), 'is_array')));
        }
        if ($shape === 'arrow') {
            $out['from'] = $this->vec($p['from'] ?? $p['pos'] ?? [0, 0, 0]);
            $out['to'] = $this->vec($p['to'] ?? [1, 0, 0]);
        }
        if ($shape === 'text') {
            $out['text'] = mb_substr(trim((string) ($p['text'] ?? $p['label'] ?? '')), 0, 40);
        }
        if ($shape === 'particles') {
            $r = (array) ($p['region'] ?? []);
            $out['region'] = ['pos' => $this->vec($r['pos'] ?? $out['pos']), 'size' => $this->vec($r['size'] ?? [2, 2, 2], 2)];
            $m = strtolower((string) ($p['motion'] ?? 'jiggle'));
            $out['motion'] = in_array($m, SceneContract::MOTIONS, true) ? $m : 'jiggle';
            if (isset($p['direction'])) {
                $out['direction'] = $this->vec($p['direction']);
            }
        }
        if (isset($p['anchor'])) {
            $out['anchor'] = $this->vec($p['anchor']);
        }
        $label = $p['label'] ?? null;
        $out['label'] = is_scalar($label) && trim((string) $label) !== '' ? mb_substr(trim((string) $label), 0, 60) : null;
        if (!empty($p['group'])) {
            $out['group'] = $this->id($p['group']);
        }
        return $out;
    }

    private function camera(array $c): array
    {
        return array_filter([
            'dist' => isset($c['dist']) && is_numeric($c['dist']) ? (float) $c['dist'] : null,
            'rx' => isset($c['rx']) && is_numeric($c['rx']) ? (float) $c['rx'] : null,
            'ry' => isset($c['ry']) && is_numeric($c['ry']) ? (float) $c['ry'] : null,
            'target' => isset($c['target']) && is_array($c['target']) ? $this->vec($c['target']) : null,
        ], fn ($v) => $v !== null);
    }

    private function colour(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = strtolower(trim(str_replace(' ', '', $v)));
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v)) {
            return $v;
        }
        if (preg_match('/^[0-9a-f]{6}$/', $v)) {
            return "#$v";
        }
        return self::NAMED[$v] ?? null;
    }

    private function ids(mixed $v): array
    {
        if (is_string($v)) {
            $v = preg_split('/\s*,\s*/', $v) ?: [];
        }
        return array_values(array_filter(array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', is_array($v) ? $v : []), fn ($x) => $x !== ''));
    }

    private function id(mixed $v): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_.\-]+/', '_', (string) $v), '_');
    }

    private function vec(mixed $v, float $default = 0): array
    {
        $v = array_values(is_array($v) ? $v : []);
        return [is_numeric($v[0] ?? null) ? (float) $v[0] : $default, is_numeric($v[1] ?? null) ? (float) $v[1] : $default, is_numeric($v[2] ?? null) ? (float) $v[2] : $default];
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
}
