<?php

declare(strict_types=1);

namespace App\Services\Lessons\Eval;

/**
 * Checks one output JSON against a list of expectations, e.g.
 *   {"file": "plan.json", "path": "check.passed", "op": "==", "value": true, "why": "plan passes its check"}
 *   {"file": "plan.json", "path": "pages.*.file", "op": "contains", "value": "04-2-activity-exercise.html"}
 *   {"file": "structure.json", "path": "check.stats.figures", "op": ">=", "value": 3}
 *   {"file": "plan.json", "path": "pages", "op": "count==", "value": 7}
 * Paths use dots; "*" walks every element of a list.
 */
final class Expectations
{
    public const OPS = ['==', '!=', '>=', '<=', 'contains', 'not_contains', 'count==', 'count>=', 'count<='];

    /**
     * @param array<string,array> $files file name => decoded JSON
     * @return array{passed:int, total:int, failures:array}
     */
    public function evaluate(array $expect, array $files): array
    {
        $passed = 0;
        $failures = [];
        foreach ($expect as $e) {
            $data = $files[$e['file']] ?? null;
            $actual = $data === null ? null : $this->get($data, (string) $e['path']);
            $ok = $data !== null && $this->test((string) $e['op'], $actual, $e['value'] ?? null);
            if ($ok) {
                $passed++;
            } else {
                $failures[] = [
                    'why' => $e['why'] ?? "{$e['file']} {$e['path']} {$e['op']} " . json_encode($e['value'] ?? null),
                    'expected' => "{$e['op']} " . json_encode($e['value'] ?? null, JSON_UNESCAPED_SLASHES),
                    'actual' => $data === null ? "{$e['file']} missing" : (str_starts_with((string) $e['op'], 'count') && is_array($actual) ? count($actual) . ' items' : $this->short($actual)),
                ];
            }
        }
        return ['passed' => $passed, 'total' => count($expect), 'failures' => $failures];
    }

    public function get(mixed $data, string $path): mixed
    {
        $parts = $path === '' ? [] : explode('.', $path);
        return $this->walk($data, $parts);
    }

    private function walk(mixed $data, array $parts): mixed
    {
        if (!$parts) {
            return $data;
        }
        $p = array_shift($parts);
        if ($p === '*') {
            if (!is_array($data)) {
                return [];
            }
            $out = [];
            foreach ($data as $v) {
                $r = $this->walk($v, $parts);
                if (is_array($r) && $parts && in_array('*', $parts, true)) {
                    array_push($out, ...array_values($r));
                } else {
                    $out[] = $r;
                }
            }
            return $out;
        }
        return is_array($data) && array_key_exists($p, $data) ? $this->walk($data[$p], $parts) : null;
    }

    private function test(string $op, mixed $a, mixed $v): bool
    {
        return match ($op) {
            '==' => $a == $v,
            '!=' => $a != $v,
            '>=' => is_numeric($a) && $a >= $v,
            '<=' => is_numeric($a) && $a <= $v,
            'contains' => is_array($a) && in_array($v, $a, false),
            'not_contains' => is_array($a) && !in_array($v, $a, false),
            'count==' => is_array($a) && count($a) == $v,
            'count>=' => is_array($a) && count($a) >= $v,
            'count<=' => is_array($a) && count($a) <= $v,
            default => false,
        };
    }

    private function short(mixed $a): string
    {
        $s = is_array($a) ? (array_is_list($a) && count($a) > 8 ? 'list of ' . count($a) : json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : json_encode($a);
        return mb_substr((string) $s, 0, 200);
    }
}
