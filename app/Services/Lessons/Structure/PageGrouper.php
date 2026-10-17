<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

/**
 * Splits a chapter into groups: N target pages + 1 look-ahead context page.
 * Every page is a target exactly once, so items never need de-duplicating.
 *   7 pages, 3 per call => [1,2,3]+4, [4,5,6]+7, [7]+none
 */
final class PageGrouper
{
    /**
     * @param int[] $pageNumbers sorted page numbers to cover
     * @param int[] $allPages    every page in the chapter (to find the context page)
     * @return array<int,array{targets:int[], context:?int}>
     */
    public static function groups(array $pageNumbers, array $allPages, int $perCall = 3, bool $lookahead = true): array
    {
        sort($pageNumbers);
        sort($allPages);
        $groups = [];
        foreach (array_chunk($pageNumbers, max(1, $perCall)) as $targets) {
            $last = end($targets);
            $next = null;
            if ($lookahead) {
                foreach ($allPages as $p) {
                    if ($p > $last) {
                        $next = $p;
                        break;
                    }
                }
            }
            $groups[] = ['targets' => array_values($targets), 'context' => $next];
        }
        return $groups;
    }

    /** "1-3,7" => [1,2,3,7] */
    public static function parseRange(?string $spec, int $max): array
    {
        if ($spec === null || trim($spec) === '') {
            return range(1, $max);
        }
        $out = [];
        foreach (explode(',', $spec) as $part) {
            if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', $part, $m)) {
                $out = array_merge($out, range((int) $m[1], (int) $m[2]));
            } elseif (ctype_digit(trim($part))) {
                $out[] = (int) trim($part);
            }
        }
        $out = array_values(array_unique(array_filter($out, fn ($p) => $p >= 1 && $p <= $max)));
        sort($out);
        return $out;
    }
}
