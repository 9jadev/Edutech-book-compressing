<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

/**
 * Ready-made pieces the model can drop into a scene by name. Code expands each one into plain parts
 * (ids "<prefab id>.<part>") BEFORE checking, so the checker and the engine only ever see basic shapes.
 *
 *   {"prefab": "plant_cell", "id": "pc", "pos": [0,0,0], "scale": 1, "labels": {"wall": "Cell wall"}}
 *
 * "labels" renames parts to the book's own words; "hide" leaves parts out.
 */
final class Prefabs
{
    public const NAMES = ['plant_cell', 'animal_cell', 'ladder', 'beaker', 'membrane', 'osmometer', 'test_tube'];

    /** What each prefab offers, for the prompt. */
    public static function describe(): string
    {
        return implode("\n", [
            '- plant_cell: parts wall, lamella, space, membrane, cytoplasm, vacuole, strands, envelope, nucleoplasm, nucleolus, chromatin, nucleus, chloroplast (group of 5), mito (group of 3). Box about 6 x 4 x 4.',
            '- animal_cell: parts membrane, cytoplasm, envelope, nucleoplasm, nucleolus, chromatin, nucleus, rer, ser, golgi (group), lysosome, mito, centrioles (group), ribosome. About 6 x 5 x 5.',
            '- ladder: "levels": ["Atoms", "Molecules", …] smallest first; parts level1, level2, … stacked upwards, each labelled with its level.',
            '- beaker: parts glass, liquid. Options "liquid": colour, "fill": 0 to 1. About 3 wide, 4 high.',
            '- membrane: parts bilayer (a slab 6 x 0.6 x 3), outside, inside (labels above and below).',
            '- osmometer: inverted funnel in a bath; parts bath, bath_water, funnel, stem, membrane, inside (solution in funnel and stem). Option "level": 0 to 1 for the liquid height in the stem.',
            '- test_tube: parts glass, layer1, layer2, layer3 (bottom to top). Options "width": 0.3 to 1.5, "colours": [three colours].',
        ]);
    }

    /** @return array<int,array> plain parts */
    public static function expand(array $p): array
    {
        $id = (string) ($p['id'] ?? $p['prefab']);
        $pos = self::vec($p['pos'] ?? [0, 0, 0]);
        $s = is_numeric($p['scale'] ?? null) ? (float) $p['scale'] : 1.0;
        $parts = match ($p['prefab']) {
            'plant_cell' => self::plantCell(),
            'animal_cell' => self::animalCell(),
            'ladder' => self::ladder((array) ($p['levels'] ?? [])),
            'beaker' => self::beaker((string) ($p['liquid'] ?? '#81d4fa'), (float) ($p['fill'] ?? 0.6)),
            'membrane' => self::membrane(),
            'osmometer' => self::osmometer((float) ($p['level'] ?? 0.3)),
            'test_tube' => self::testTube((float) ($p['width'] ?? 0.6), (array) ($p['colours'] ?? ['#eeeeee', '#1e88e5', '#eeeeee'])),
            default => [],
        };
        $labels = (array) ($p['labels'] ?? []);
        $hide = array_map('strval', (array) ($p['hide'] ?? []));
        $out = [];
        foreach ($parts as $part) {
            $local = $part['id'];
            if (in_array($local, $hide, true) || (isset($part['group']) && in_array($part['group'], $hide, true))) {
                continue;
            }
            $part['id'] = "$id.$local";
            if (isset($part['group'])) {
                $part['group'] = "$id.{$part['group']}";
            }
            if (array_key_exists($local, $labels)) {
                $part['label'] = $labels[$local] === null ? null : (string) $labels[$local];
            } elseif (isset($part['group'], $labels[substr($part['group'], strlen($id) + 1)]) && !empty($part['label'])) {
                $part['label'] = (string) $labels[substr($part['group'], strlen($id) + 1)];
            }
            $part['pos'] = [$pos[0] + $part['pos'][0] * $s, $pos[1] + $part['pos'][1] * $s, $pos[2] + $part['pos'][2] * $s];
            foreach (['radius', 'height', 'length', 'tube'] as $k) {
                if (isset($part[$k])) {
                    $part[$k] *= $s;
                }
            }
            foreach (['size'] as $k) {
                if (isset($part[$k])) {
                    $part[$k] = array_map(fn ($v) => $v * $s, $part[$k]);
                }
            }
            if (isset($part['points'])) {
                $part['points'] = array_map(fn ($q) => [$pos[0] + $q[0] * $s, $pos[1] + $q[1] * $s, $pos[2] + $q[2] * $s], $part['points']);
            }
            if (isset($part['region'])) {
                $part['region']['pos'] = [$pos[0] + $part['region']['pos'][0] * $s, $pos[1] + $part['region']['pos'][1] * $s, $pos[2] + $part['region']['pos'][2] * $s];
                $part['region']['size'] = array_map(fn ($v) => $v * $s, $part['region']['size']);
            }
            $part['prefab'] = $p['prefab'];
            $out[] = $part;
        }
        return $out;
    }

    private static function plantCell(): array
    {
        $parts = [
            ['id' => 'wall', 'shape' => 'box', 'size' => [6, 4, 4], 'colour' => '#7cb342', 'opacity' => 0.22, 'edges' => true, 'label' => 'Cell wall', 'pos' => [0, 0, 0], 'anchor' => [3, 1.4, 2]],
            ['id' => 'lamella', 'shape' => 'box', 'size' => [0.06, 4.2, 4.2], 'colour' => '#bcaaa4', 'opacity' => 0.4, 'label' => 'Middle lamella', 'pos' => [-3.06, 0, 0]],
            ['id' => 'space', 'shape' => 'sphere', 'radius' => 0.28, 'colour' => '#37474f', 'label' => 'Intercellular space', 'pos' => [-3.05, 2.05, 2.05]],
            ['id' => 'membrane', 'shape' => 'box', 'size' => [5.75, 3.75, 3.75], 'colour' => '#ffb300', 'opacity' => 0.16, 'edges' => true, 'label' => 'Plasma membrane', 'pos' => [0, 0, 0], 'anchor' => [2.88, -1.2, 1.88]],
            ['id' => 'cytoplasm', 'shape' => 'box', 'size' => [5.6, 3.6, 3.6], 'colour' => '#fff59d', 'opacity' => 0.12, 'label' => 'Cytoplasm', 'pos' => [0, 0, 0], 'anchor' => [-0.4, -1.3, 1.7]],
            ['id' => 'vacuole', 'shape' => 'sphere', 'radius' => 1, 'scale' => [2.3, 1.35, 1.35], 'colour' => '#4fc3f7', 'opacity' => 0.5, 'label' => 'Vacuole', 'pos' => [0.7, 0, 0]],
            ['id' => 'strands', 'shape' => 'tube', 'points' => [[-1.2, 0.4, 0.3], [0.2, 0.6, 0.6], [1.6, 0.2, 0.4], [2.9, 0.3, 0.2]], 'radius' => 0.05, 'colour' => '#fdd835', 'label' => 'Cytoplasmic strands', 'pos' => [0, 0, 0]],
            ['id' => 'nucleus', 'shape' => 'sphere', 'radius' => 0.86, 'colour' => '#ce93d8', 'opacity' => 0.08, 'label' => 'Nucleus', 'pos' => [-1.85, 0.55, 0.85]],
            ['id' => 'envelope', 'shape' => 'sphere', 'radius' => 0.8, 'colour' => '#ab47bc', 'opacity' => 0.35, 'label' => 'Nuclear envelope', 'pos' => [-1.85, 0.55, 0.85], 'anchor' => [-2.5, 0.9, 1.2]],
            ['id' => 'nucleoplasm', 'shape' => 'sphere', 'radius' => 0.7, 'colour' => '#e1bee7', 'opacity' => 0.55, 'label' => 'Nucleoplasm', 'pos' => [-1.85, 0.55, 0.85]],
            ['id' => 'nucleolus', 'shape' => 'sphere', 'radius' => 0.24, 'colour' => '#6a1b9a', 'label' => 'Nucleolus', 'pos' => [-1.7, 0.75, 1.2]],
            ['id' => 'chromatin', 'shape' => 'particles', 'count' => 30, 'radius' => 0.045, 'colour' => '#4a148c', 'motion' => 'jiggle', 'speed' => 0.15, 'region' => ['pos' => [-1.95, 0.4, 0.9], 'size' => [0.8, 0.8, 0.8]], 'label' => 'Chromatin', 'pos' => [-1.95, 0.4, 0.9]],
        ];
        foreach ([[1.9, 1.4, 1.3], [2.3, -1.3, 0.9], [-0.3, -1.4, 1.5], [0.6, 1.5, -1.2], [-2.4, -1.2, -1]] as $i => $q) {
            $parts[] = ['id' => 'chloroplast' . ($i ? $i + 1 : ''), 'group' => 'chloroplast', 'shape' => 'capsule', 'radius' => 0.2, 'length' => 0.55, 'rot' => [0, 0, 70 - 25 * $i], 'colour' => '#2e7d32', 'label' => $i ? null : 'Chloroplast', 'pos' => $q];
        }
        foreach ([[-0.6, 1.45, 1.4], [2.6, 0.9, -1.4], [-2.3, -1.5, 1.2]] as $i => $q) {
            $parts[] = ['id' => 'mito' . ($i ? $i + 1 : ''), 'group' => 'mito', 'shape' => 'capsule', 'radius' => 0.13, 'length' => 0.38, 'rot' => [30 * $i, 0, 40], 'colour' => '#ef6c00', 'label' => $i ? null : 'Mitochondria', 'pos' => $q];
        }
        return $parts;
    }

    private static function animalCell(): array
    {
        $parts = [
            ['id' => 'membrane', 'shape' => 'sphere', 'radius' => 1, 'scale' => [3, 2.4, 2.4], 'colour' => '#ffb300', 'opacity' => 0.18, 'label' => 'Plasma membrane', 'pos' => [0, 0, 0]],
            ['id' => 'cytoplasm', 'shape' => 'sphere', 'radius' => 1, 'scale' => [2.85, 2.25, 2.25], 'colour' => '#fff59d', 'opacity' => 0.1, 'label' => 'Cytoplasm', 'pos' => [0, 0, 0]],
            ['id' => 'nucleus', 'shape' => 'sphere', 'radius' => 0.95, 'colour' => '#ce93d8', 'opacity' => 0.08, 'label' => 'Nucleus', 'pos' => [0, 0.2, 0]],
            ['id' => 'envelope', 'shape' => 'sphere', 'radius' => 0.88, 'colour' => '#ab47bc', 'opacity' => 0.35, 'label' => 'Nuclear envelope', 'pos' => [0, 0.2, 0]],
            ['id' => 'nucleoplasm', 'shape' => 'sphere', 'radius' => 0.78, 'colour' => '#e1bee7', 'opacity' => 0.55, 'label' => 'Nucleoplasm', 'pos' => [0, 0.2, 0]],
            ['id' => 'nucleolus', 'shape' => 'sphere', 'radius' => 0.26, 'colour' => '#6a1b9a', 'label' => 'Nucleolus', 'pos' => [0.2, 0.4, 0.4]],
            ['id' => 'chromatin', 'shape' => 'particles', 'count' => 30, 'radius' => 0.045, 'colour' => '#4a148c', 'motion' => 'jiggle', 'speed' => 0.15, 'region' => ['pos' => [-0.15, 0.1, 0], 'size' => [0.9, 0.9, 0.9]], 'label' => 'Chromatin', 'pos' => [-0.15, 0.1, 0]],
            ['id' => 'rer', 'shape' => 'torus', 'radius' => 1.3, 'tube' => 0.07, 'arc' => 200, 'rot' => [90, 0, 20], 'colour' => '#5c6bc0', 'label' => 'Rough endoplasmic reticulum', 'pos' => [0, 0.2, 0]],
            ['id' => 'ser', 'shape' => 'tube', 'points' => [[0.9, -1.2, 0.6], [1.3, -1.0, 0.9], [1.7, -1.3, 0.5], [2.1, -1.0, 0.7]], 'radius' => 0.07, 'colour' => '#26a69a', 'label' => 'Smooth endoplasmic reticulum', 'pos' => [0, 0, 0]],
            ['id' => 'lysosome', 'shape' => 'sphere', 'radius' => 0.22, 'colour' => '#8d6e63', 'label' => 'Lysosome', 'pos' => [-1.6, -0.9, 0.7]],
            ['id' => 'mito', 'shape' => 'capsule', 'radius' => 0.2, 'length' => 0.6, 'rot' => [0, 30, 60], 'colour' => '#ef6c00', 'label' => 'Mitochondrion', 'pos' => [-1.7, 0.9, -0.4]],
            ['id' => 'ribosome', 'shape' => 'particles', 'count' => 45, 'radius' => 0.04, 'colour' => '#283593', 'motion' => 'none', 'region' => ['pos' => [0, 0, 0], 'size' => [4.2, 3.2, 3.2]], 'label' => 'Ribosome', 'pos' => [1.2, -0.3, 1.3]],
        ];
        for ($i = 0; $i < 4; $i++) {
            $parts[] = ['id' => 'golgi' . ($i ? $i + 1 : ''), 'group' => 'golgi', 'shape' => 'cylinder', 'radius' => 0.5 - 0.06 * $i, 'height' => 0.06, 'rot' => [0, 0, 15], 'colour' => '#ec407a', 'label' => $i ? null : 'Golgi body', 'pos' => [1.7, 0.6 + 0.13 * $i, -0.3]];
        }
        $parts[] = ['id' => 'centrioles', 'group' => 'centrioles', 'shape' => 'cylinder', 'radius' => 0.07, 'height' => 0.36, 'colour' => '#455a64', 'label' => 'Centrioles', 'pos' => [0.9, 1.35, 0.7]];
        $parts[] = ['id' => 'centrioles2', 'group' => 'centrioles', 'shape' => 'cylinder', 'radius' => 0.07, 'height' => 0.36, 'rot' => [0, 0, 90], 'colour' => '#455a64', 'label' => null, 'pos' => [1.1, 1.35, 0.7]];
        return $parts;
    }

    private static function ladder(array $levels): array
    {
        $levels = array_values(array_filter(array_map('strval', $levels))) ?: ['Level 1', 'Level 2', 'Level 3'];
        $n = count($levels);
        $parts = [];
        $palette = ['#ef5350', '#ab47bc', '#5c6bc0', '#29b6f6', '#26a69a', '#9ccc65', '#ffca28', '#ff7043', '#8d6e63', '#78909c', '#ec407a', '#7e57c2'];
        foreach ($levels as $i => $name) {
            $w = 1.2 + 4.2 * $i / max(1, $n - 1);
            $parts[] = ['id' => 'level' . ($i + 1), 'shape' => 'box', 'size' => [$w, 0.45, 1], 'colour' => $palette[$i % count($palette)], 'label' => $name, 'pos' => [0, -($n - 1) * 0.3 + $i * 0.6, 0]];
        }
        return $parts;
    }

    private static function beaker(string $liquid, float $fill): array
    {
        $fill = max(0.05, min(1, $fill));
        return [
            ['id' => 'glass', 'shape' => 'cylinder', 'radius' => 1.5, 'height' => 4, 'open' => true, 'colour' => '#b0bec5', 'opacity' => 0.25, 'edges' => true, 'label' => 'Beaker', 'pos' => [0, 0, 0]],
            ['id' => 'liquid', 'shape' => 'cylinder', 'radius' => 1.45, 'height' => 3.9 * $fill, 'colour' => $liquid, 'opacity' => 0.45, 'label' => 'Water', 'pos' => [0, -2 + 1.95 * $fill, 0]],
        ];
    }

    private static function membrane(): array
    {
        return [
            ['id' => 'bilayer', 'shape' => 'box', 'size' => [6, 0.6, 3], 'colour' => '#ffcc80', 'opacity' => 0.85, 'label' => 'Cell membrane', 'pos' => [0, 0, 0]],
            ['id' => 'outside', 'shape' => 'text', 'text' => 'OUTSIDE', 'size' => 0.35, 'colour' => '#546e7a', 'label' => null, 'pos' => [-2.4, 1.2, 0]],
            ['id' => 'inside', 'shape' => 'text', 'text' => 'INSIDE', 'size' => 0.35, 'colour' => '#546e7a', 'label' => null, 'pos' => [-2.4, -1.2, 0]],
        ];
    }

    private static function osmometer(float $level): array
    {
        $level = max(0, min(1, $level));
        return [
            ['id' => 'bath', 'shape' => 'cylinder', 'radius' => 2.2, 'height' => 2.4, 'open' => true, 'colour' => '#b0bec5', 'opacity' => 0.22, 'edges' => true, 'label' => 'Bath', 'pos' => [0, -1.6, 0]],
            ['id' => 'bath_water', 'shape' => 'cylinder', 'radius' => 2.15, 'height' => 1.8, 'colour' => '#4fc3f7', 'opacity' => 0.3, 'label' => 'Water', 'pos' => [0, -1.9, 0]],
            ['id' => 'funnel', 'shape' => 'cone', 'radius' => 1.1, 'height' => 1.4, 'open' => true, 'colour' => '#b0bec5', 'opacity' => 0.35, 'edges' => true, 'label' => 'Inverted funnel', 'pos' => [0, -1.5, 0]],
            ['id' => 'membrane', 'shape' => 'cylinder', 'radius' => 1.1, 'height' => 0.05, 'colour' => '#6d4c41', 'opacity' => 0.9, 'label' => 'Selectively permeable membrane', 'pos' => [0, -2.2, 0]],
            ['id' => 'stem', 'shape' => 'cylinder', 'radius' => 0.18, 'height' => 3.2, 'open' => true, 'colour' => '#b0bec5', 'opacity' => 0.35, 'edges' => true, 'label' => 'Funnel stem', 'pos' => [0, 0.8, 0]],
            ['id' => 'inside', 'shape' => 'cylinder', 'radius' => 0.15, 'height' => 0.6 + 2.4 * $level, 'colour' => '#ffb74d', 'opacity' => 0.75, 'label' => 'Glucose solution', 'pos' => [0, -0.8 + 1.2 * $level, 0]],
        ];
    }

    private static function testTube(float $width, array $colours): array
    {
        $r = max(0.15, min(0.75, $width / 2));
        $c = array_values($colours) + ['#eeeeee', '#1e88e5', '#eeeeee'];
        return [
            ['id' => 'glass', 'shape' => 'cylinder', 'radius' => $r + 0.04, 'height' => 4.2, 'open' => true, 'colour' => '#b0bec5', 'opacity' => 0.25, 'edges' => true, 'label' => 'Test tube', 'pos' => [0, 0, 0]],
            ['id' => 'layer1', 'shape' => 'cylinder', 'radius' => $r, 'height' => 1.4, 'colour' => (string) $c[0], 'opacity' => 0.7, 'label' => 'Bottom layer', 'pos' => [0, -1.35, 0]],
            ['id' => 'layer2', 'shape' => 'cylinder', 'radius' => $r, 'height' => 0.5, 'colour' => (string) $c[1], 'opacity' => 0.85, 'label' => 'Middle layer', 'pos' => [0, -0.4, 0]],
            ['id' => 'layer3', 'shape' => 'cylinder', 'radius' => $r, 'height' => 1.4, 'colour' => (string) $c[2], 'opacity' => 0.7, 'label' => 'Top layer', 'pos' => [0, 0.55, 0]],
        ];
    }

    private static function vec(mixed $v): array
    {
        $v = array_values(is_array($v) ? $v : []);
        return [(float) ($v[0] ?? 0), (float) ($v[1] ?? 0), (float) ($v[2] ?? 0)];
    }
}
