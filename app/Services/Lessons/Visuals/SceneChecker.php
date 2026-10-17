<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

/**
 * Step 5 check (no AI), the same for every model:
 *  - every block with a planned visual has a scene;
 *  - walk / example scenes have one state per written step;
 *  - every explore / widget task points at a part, group or control that exists;
 *  - every id used in states and controls exists, properties are allowed, slider ranges make sense;
 *  - every printed label of a figure is the label of a part in its scene;
 *  - sizes and positions stay in range; labels are short.
 */
final class SceneChecker
{
    /**
     * @param array               $plan      page from plan.json
     * @param ?array              $content   page from content.json
     * @param array<string,array> $scenes    block id => scene
     * @param array<string,array> $itemsById structure items
     * @return array{errors:string[], warnings:string[], stats:array}
     */
    public function check(array $plan, ?array $content, array $scenes, array $itemsById): array
    {
        $e = [];
        $w = [];
        $cblocks = [];
        foreach ($content['blocks'] ?? [] as $b) {
            $cblocks[$b['id']] = $b;
        }
        $stats = ['scenes' => 0, 'parts' => 0, 'states' => 0, 'controls' => 0, 'labels' => 0];

        foreach ($plan['blocks'] as $pb) {
            if (($pb['visual']['mode'] ?? 'none') === 'none') {
                continue;
            }
            $sc = $scenes[$pb['id']] ?? null;
            $where = "Scene {$pb['id']} ({$pb['type']}, \"{$pb['heading']}\")";
            if (!$sc) {
                $e[] = "$where is missing. Every block with a visual needs a scene.";
                continue;
            }
            $stats['scenes']++;
            $stats['parts'] += count($sc['parts']);
            $stats['states'] += count($sc['states']);
            $stats['controls'] += count($sc['controls'] ?? []);

            // Index parts, groups, controls.
            $parts = [];
            $groups = [];
            $labels = [];
            foreach ($sc['parts'] as $p) {
                $parts[$p['id']] = $p;
                if (!empty($p['group'])) {
                    $groups[$p['group']][] = $p['id'];
                }
                if (!empty($p['label'])) {
                    $labels[] = $p['label'];
                    $stats['labels']++;
                }
            }
            $controls = [];
            foreach ($sc['controls'] ?? [] as $c) {
                $controls[$c['id']] = $c;
            }
            $known = fn (string $id) => isset($parts[$id]) || isset($groups[$id]);

            if (!$sc['parts']) {
                $e[] = "$where has no parts.";
                continue;
            }
            if (count($sc['parts']) > SceneContract::MAX_PARTS) {
                $e[] = "$where has " . count($sc['parts']) . ' parts; keep it under ' . SceneContract::MAX_PARTS . '.';
            }

            // Shapes in range.
            foreach ($sc['parts'] as $p) {
                foreach ([$p['pos'], $p['from'] ?? [0, 0, 0], $p['to'] ?? [0, 0, 0]] as $v) {
                    if (max(array_map('abs', $v)) > SceneContract::MAX_COORD) {
                        $e[] = "$where part \"{$p['id']}\" is placed at " . json_encode($v) . ', outside the scene. Keep positions between -' . SceneContract::MAX_COORD . ' and ' . SceneContract::MAX_COORD . '.';
                        break;
                    }
                }
                foreach (['radius', 'height', 'length'] as $k) {
                    if (isset($p[$k]) && ($p[$k] <= 0 || $p[$k] > SceneContract::MAX_COORD)) {
                        $e[] = "$where part \"{$p['id']}\" has $k {$p[$k]}; it must be above 0 and at most " . SceneContract::MAX_COORD . '.';
                    }
                }
                if ($p['shape'] === 'tube' && count($p['points'] ?? []) < 2) {
                    $e[] = "$where part \"{$p['id']}\" is a tube with fewer than 2 points.";
                }
                if ($p['shape'] === 'text' && (($p['text'] ?? '') === '' || str_word_count($p['text']) > 4)) {
                    $e[] = "$where text \"{$p['id']}\" must be 1 to 4 words (it is \"" . ($p['text'] ?? '') . '").';
                }
                if (!empty($p['label']) && count(preg_split('/\s+/', trim($p['label']))) > SceneContract::MAX_LABEL_WORDS) {
                    $e[] = "$where label \"{$p['label']}\" is too long; use at most " . SceneContract::MAX_LABEL_WORDS . ' words.';
                }
            }

            // States, controls and tasks refer to things that exist.
            foreach ($sc['states'] as $si => $st) {
                foreach (['show', 'hide', 'highlight'] as $k) {
                    foreach ($st[$k] ?? [] as $id) {
                        if (!$known($id)) {
                            $e[] = "$where state " . ($si + 1) . " \"$k\" uses \"$id\", which is not a part or group in this scene.";
                        }
                    }
                }
                if (is_array($st['labels'] ?? null)) {
                    foreach ($st['labels'] as $id) {
                        if (!$known($id)) {
                            $e[] = "$where state " . ($si + 1) . " \"labels\" uses \"$id\", which is not a part or group in this scene.";
                        }
                    }
                }
                foreach (array_keys($st['set'] ?? []) as $t) {
                    $this->target($t, $known, "$where state " . ($si + 1) . ' "set"', $e);
                }
            }
            foreach ($sc['controls'] ?? [] as $c) {
                if ($c['type'] === 'buttons') {
                    if (count($c['options']) < 2) {
                        $e[] = "$where control \"{$c['id']}\" needs at least 2 buttons.";
                    }
                    foreach ($c['options'] as $o) {
                        if ($o['state'] < 1 || $o['state'] > count($sc['states'])) {
                            $e[] = "$where control \"{$c['id']}\" button \"{$o['label']}\" points to state {$o['state']}, but the scene has " . count($sc['states']) . ' states.';
                        }
                    }
                } else {
                    $this->target((string) $c['sets'], $known, "$where control \"{$c['id']}\"", $e);
                    if ($c['type'] === 'slider' && $c['min'] >= $c['max']) {
                        $e[] = "$where slider \"{$c['id']}\" has min {$c['min']} and max {$c['max']}; min must be below max.";
                    }
                }
                if ($c['label'] === '') {
                    $e[] = "$where control \"{$c['id']}\" has no label.";
                }
            }

            // Steps and tasks from content.json.
            $cb = $cblocks[$pb['id']] ?? null;
            if (in_array($pb['type'], ['walk', 'example'], true) && $cb) {
                $n = count($cb['steps'] ?? []);
                if ($n && count($sc['states']) !== $n) {
                    $e[] = "$where has " . count($sc['states']) . " states but the block has $n written steps. Give exactly one state per step, in order.";
                }
            }
            if (in_array($pb['type'], ['explore', 'widget'], true) && $cb) {
                $byTask = [];
                foreach ($sc['tasks'] ?? [] as $t) {
                    $byTask[$t['task']] = $t['target'];
                }
                foreach ($cb['tasks'] ?? [] as $ti => $t) {
                    $target = $byTask[$ti + 1] ?? null;
                    if ($target === null) {
                        $e[] = "$where: task " . ($ti + 1) . ' ("' . mb_substr($t['do'], 0, 60) . '") has no target. Add {"task": ' . ($ti + 1) . ', "target": "<part, group or control id>"}.';
                    } elseif (!$known($target) && !isset($controls[$target])) {
                        $e[] = "$where: task " . ($ti + 1) . " targets \"$target\", which is not a part, group or control in this scene.";
                    }
                }
                if (count(array_filter($sc['parts'], fn ($p) => !empty($p['label']))) < 2) {
                    $e[] = "$where must have at least 2 labelled parts the student can tap.";
                }
                if ($pb['type'] === 'widget' && empty($sc['controls'])) {
                    $w[] = "$where is a hands-on widget but has no controls (slider, toggle or buttons).";
                }
            }

            // Every printed label of the block's figures must be a part label.
            foreach ($pb['items'] as $id) {
                $it = $itemsById[$id] ?? null;
                if (!$it || $it['type'] !== 'figure' || empty($it['figure_labels'])) {
                    continue;
                }
                $missing = array_values(array_filter($it['figure_labels'], fn ($l) => !$this->labelled($l, $labels)));
                if ($missing) {
                    $e[] = "$where shows figure " . ($it['label'] ?: $id) . ' but these printed labels are not on any part: ' . implode(', ', $missing) . '. Give a part each of these labels.';
                }
            }

            if ($sc['mode'] === 'panel') {
                $zs = array_map(fn ($p) => abs($p['pos'][2]), $sc['parts']);
                if ($zs && max($zs) > 1.5) {
                    $w[] = "$where is a flat panel but some parts are far from z = 0; they may look odd from the front.";
                }
            }
        }
        foreach (array_keys($scenes) as $b) {
            $found = false;
            foreach ($plan['blocks'] as $pb) {
                if ($pb['id'] === $b && ($pb['visual']['mode'] ?? 'none') !== 'none') {
                    $found = true;
                }
            }
            if (!$found) {
                $w[] = "Scene $b is not needed (its block has no visual) and will be ignored.";
            }
        }
        return ['errors' => array_values(array_unique($e)), 'warnings' => array_values(array_unique($w)), 'stats' => $stats];
    }

    private function target(string $t, callable $known, string $where, array &$e): void
    {
        $dot = strrpos($t, '.');
        if ($dot === false) {
            $e[] = "$where uses \"$t\"; write it as \"<part id>.<property>\".";
            return;
        }
        $id = substr($t, 0, $dot);
        $prop = substr($t, $dot + 1);
        if (!$known($id)) {
            $e[] = "$where changes \"$id\", which is not a part or group in this scene.";
        }
        if (!in_array($prop, SceneContract::PROPS, true)) {
            $e[] = "$where changes \"$prop\". Allowed: " . implode(', ', SceneContract::PROPS) . '.';
        }
    }

    /** "Red Blood Cells (RBC)" matches "Red blood cells"; "Mitochondria" matches "Mitochondrion"; "Rough ER" matches. */
    private function labelled(string $printed, array $labels): bool
    {
        $norm = function (string $s): string {
            $s = mb_strtolower(preg_replace('/\([^)]*\)/u', ' ', $s) ?? $s);
            $s = str_replace(['endoplasmic reticulum', "sol'n", 'haemoglobin'], ['er', 'solution', 'hemoglobin'], $s);
            return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s);
        };
        $p = $norm($printed);
        if ($p === '' || in_array($p, ['smallest', 'biggest', 'largest'], true) || mb_strlen($p) < 3) {
            return true;
        }
        $stem = fn (string $w) => mb_substr($w, 0, max(4, mb_strlen($w) - 3));
        foreach ($labels as $l) {
            $l = $norm($l);
            if ($l === $p) {
                return true;
            }
            $ok = true;
            foreach (explode(' ', $p) as $word) {
                if (mb_strlen($word) >= 3 && !preg_match('/(?:^| )' . preg_quote($stem($word), '/') . '/u', $l)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }
}
