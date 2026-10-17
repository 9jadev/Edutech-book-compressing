<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

/** Builds the prompt for one lesson page: every block that needs a scene, with what each step/task must show. */
final class VisualsBrief
{
    /** @return string[] block ids on this page that need a scene */
    public static function blocksNeeding(array $plan): array
    {
        return array_values(array_map(fn ($b) => $b['id'], array_filter($plan['blocks'], fn ($b) => ($b['visual']['mode'] ?? 'none') !== 'none')));
    }

    public static function build(array $plan, ?array $content, array $itemsById, ?string $feedback = null, ?array $previous = null): string
    {
        $cb = [];
        foreach ($content['blocks'] ?? [] as $b) {
            $cb[$b['id']] = $b;
        }
        $out = ["PAGE KEY: {$plan['key']}", "Page title: " . ($content['title'] ?? $plan['title']), '', 'SCENES TO DESCRIBE (one per block, same block id):'];
        foreach ($plan['blocks'] as $b) {
            $v = $b['visual'] ?? [];
            if (($v['mode'] ?? 'none') === 'none') {
                continue;
            }
            $out[] = '';
            $out[] = "[{$b['id']}] block type: {$b['type']} | mode: {$v['mode']} | heading: " . ($cb[$b['id']]['heading'] ?? $b['heading']);
            $out[] = '  subject: ' . ($v['subject'] ?? '') . ($v['shows'] ? ' | shows: ' . $v['shows'] : '') . ($v['interaction'] ? ' | interaction: ' . $v['interaction'] : '');
            foreach ($b['items'] as $id) {
                $it = $itemsById[$id] ?? null;
                if ($it && $it['type'] === 'figure') {
                    $out[] = '  FIGURE ' . ($it['label'] ?: $id) . ($it['title'] ? " ({$it['title']})" : '') . (!empty($it['figure_labels']) ? ' — printed labels (every one must be a part label): ' . implode('; ', $it['figure_labels']) : '');
                } elseif ($it && $it['type'] === 'table' && !empty($it['rows'])) {
                    $out[] = '  TABLE rows: ' . implode(' // ', array_map(fn ($r) => implode(' | ', array_map('strval', (array) $r)), $it['rows']));
                }
            }
            $c = $cb[$b['id']] ?? null;
            if ($c && in_array($b['type'], ['walk', 'example'], true)) {
                $out[] = '  STEPS (give exactly ' . count($c['steps']) . ' states, in this order):';
                foreach ($c['steps'] as $i => $s) {
                    $out[] = '   ' . ($i + 1) . '. ' . $s['text'] . ($s['shows'] ? " — the scene shows: {$s['shows']}" : '');
                }
            }
            if ($c && in_array($b['type'], ['explore', 'widget'], true)) {
                $out[] = '  TASKS (link each one with "tasks": [{"task": n, "target": "<part, group or control id>"}]):';
                foreach ($c['tasks'] as $i => $t) {
                    $out[] = '   ' . ($i + 1) . '. do: ' . $t['do'] . ($t['notice'] ? " — notice: {$t['notice']}" : '');
                }
            }
        }
        if ($previous !== null) {
            $out[] = '';
            $out[] = 'YOUR LAST SCENES FOR THIS PAGE (fix them, keep what was right; prefabs were expanded into parts):';
            $out[] = json_encode(['scenes' => array_values($previous)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($feedback) {
            $out[] = '';
            $out[] = 'FIX THESE PROBLEMS:';
            $out[] = $feedback;
        }
        $out[] = '';
        $out[] = 'Reply with the JSON object only, with one scene for each of these blocks: ' . implode(', ', self::blocksNeeding($plan));
        return implode("\n", $out);
    }
}
