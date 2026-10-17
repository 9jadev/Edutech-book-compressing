<?php

declare(strict_types=1);

namespace App\Services\Lessons\Build;

/**
 * Step 6 check (no AI, no browser): is the built chapter complete and wired up?
 *  - every planned page was built, and the contents page exists;
 *  - every link and asset a page uses points to a file in the build;
 *  - every question kind has a marker in lesson.js and its answer data is usable;
 *  - every block with a planned visual got its scene;
 *  - every page has something to read aloud.
 * Step 7 then opens the pages in a real browser.
 */
final class BuildChecker
{
    public const KINDS = ['mcq', 'true_false', 'match', 'sort', 'order', 'label', 'numeric', 'short_text'];

    /** @param array<string,string> $files relative path => contents */
    public function check(array $files, array $plan, array $content, array $visuals): array
    {
        $e = [];
        $w = [];
        if (!isset($files['index.html'])) {
            $e[] = 'The chapter contents page (index.html) was not built.';
        }
        foreach (PageRenderer::ASSETS as $a) {
            if (!isset($files["assets/$a"]) || $files["assets/$a"] === '') {
                $e[] = "The asset assets/$a is missing.";
            }
        }
        $vscenes = [];
        foreach ($visuals['pages'] ?? [] as $vp) {
            $vscenes[$vp['key']] = (array) ($vp['scenes'] ?? []);
        }
        $cpages = [];
        foreach ($content['pages'] ?? [] as $cp) {
            $cpages[$cp['key']] = $cp;
        }

        foreach ($plan['pages'] as $pp) {
            $k = $pp['key'];
            if (!isset($files[$k])) {
                $e[] = "Page $k was not built" . (isset($cpages[$k]) ? '.' : ' (it has no content in content.json).');
                continue;
            }
            $html = $files[$k];
            // Links and assets resolve.
            preg_match_all('/\b(?:href|src)="([^"#?]+)/', $html, $m);
            foreach (array_unique($m[1]) as $ref) {
                if (preg_match('#^(https?:|mailto:|data:)#', $ref)) {
                    $w[] = "$k links outside the build ($ref); it will not work offline.";
                    continue;
                }
                $target = $this->resolve($k, $ref);
                if (!isset($files[$target])) {
                    $e[] = "$k links to $ref, which is not in the build.";
                }
            }
            // Data for the page's behaviour.
            if (!preg_match('/<script>window\.LESSON = (.*?);<\/script>/s', $html, $dm) || !is_array($data = json_decode($dm[1], true))) {
                $e[] = "$k has no readable lesson data.";
                continue;
            }
            foreach ($data['questions'] ?? [] as $qid => $q) {
                if (!in_array($q['kind'] ?? '', self::KINDS, true)) {
                    $e[] = "$k question $qid has kind \"" . ($q['kind'] ?? '') . '", which lesson.js cannot mark.';
                    continue;
                }
                $bad = match ($q['kind']) {
                    'mcq' => !isset($q['options'][$q['answer'] ?? -1]),
                    'true_false' => !is_bool($q['answer'] ?? null),
                    'match' => count($q['pairs'] ?? []) < 2,
                    'sort' => count($q['groups'] ?? []) < 2,
                    'order' => count($q['sequence'] ?? []) < 2,
                    'label' => count($q['targets'] ?? []) < 1,
                    'numeric' => !is_numeric($q['answer'] ?? null),
                    'short_text' => empty($q['accept']),
                };
                if ($bad) {
                    $e[] = "$k question $qid ({$q['kind']}) has no usable answer.";
                }
                if (!str_contains($html, 'data-q="' . htmlspecialchars((string) $qid, ENT_QUOTES) . '"')) {
                    $e[] = "$k question $qid has data but no place on the page.";
                }
            }
            // Scenes.
            foreach ($pp['blocks'] as $b) {
                if (($b['visual']['mode'] ?? 'none') === 'none') {
                    continue;
                }
                if (!isset($vscenes[$k][$b['id']])) {
                    $w[] = "$k block {$b['id']} has a planned visual but no scene in visuals.json; it is shown without 3D.";
                } elseif (!isset($data['scenes'][$b['id']])) {
                    $e[] = "$k block {$b['id']} has a scene in visuals.json but it was not put on the page.";
                }
            }
            if (!str_contains($html, 'data-say=')) {
                $e[] = "$k has nothing for the narration to read.";
            }
            if (strlen($html) > 3_000_000) {
                $w[] = "$k is large (" . round(strlen($html) / 1e6, 1) . ' MB); it may load slowly on phones.';
            }
        }
        return ['errors' => array_values(array_unique($e)), 'warnings' => array_values(array_unique($w))];
    }

    /** "Chapter-01/02-1-x.html" + "../Part-00/00-welcome.html" -> "Part-00/00-welcome.html" */
    public function resolve(string $from, string $ref): string
    {
        $parts = explode('/', $from);
        array_pop($parts);
        foreach (explode('/', $ref) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }
        return implode('/', $parts);
    }
}
