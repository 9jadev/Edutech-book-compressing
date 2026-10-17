<?php

declare(strict_types=1);

namespace App\Services\Lessons\Build;

/**
 * Step 6 — turns one page of content.json (+ its scenes from visuals.json) into a lesson page.
 * No AI. All text is escaped; the page's behaviour comes from assets/lesson.js and assets/lesson-scene.js.
 */
final class PageRenderer
{
    public const ASSETS = ['three.min.js', 'lesson-scene.js', 'lesson-scene.css', 'lesson.js', 'lesson.css'];

    /** @var string[] problems found while rendering (missing scene, unknown block type…) */
    public array $notes = [];

    public function page(array $pp, array $cp, array $scenes, array $chapter): array
    {
        $this->notes = [];
        $depth = substr_count($pp['key'], '/');
        $up = str_repeat('../', $depth);
        $title = $cp['title'] ?? $pp['title'];
        $data = ['scenes' => [], 'questions' => []];
        $h = [];

        $h[] = '<header class="page"><h1 data-say="' . $this->e($title) . '" data-say-same>' . $this->e($title) . '</h1>';
        $meta = [];
        if (!empty($pp['book_pages'])) {
            $meta[] = 'Book page' . (count($pp['book_pages']) > 1 ? 's ' . min($pp['book_pages']) . '–' . max($pp['book_pages']) : ' ' . $pp['book_pages'][0]);
        }
        $meta[] = '<span class="score"></span>';
        $h[] = '<p class="meta">' . implode(' · ', $meta) . '</p></header>';
        if (!empty($cp['intro'])) {
            $h[] = $this->para($cp['intro'], null, 'intro');
        }
        if (!empty($cp['objectives'])) {
            $h[] = '<section class="objectives"><h2>What you will learn</h2><ul>' . implode('', array_map(fn ($o) => '<li>' . $this->e($o) . '</li>', $cp['objectives'])) . '</ul></section>';
        }

        foreach ($cp['blocks'] as $b) {
            $id = $b['id'];
            $tag = ['notes' => 'Notes', 'walk' => 'Watch', 'example' => 'Worked example', 'explore' => 'Explore', 'widget' => 'Try it', 'practice' => 'Practice'][$b['type']] ?? $b['type'];
            $h[] = '<section class="blk blk-' . $this->e($b['type']) . '" id="' . $this->e($id) . '">';
            $h[] = '<h2><span>' . $this->e($b['heading'] ?? '') . '</span><span class="tag">' . $this->e($tag) . '</span></h2>';
            $sc = $scenes[$id] ?? null;
            switch ($b['type']) {
                case 'notes':
                    foreach ($b['paragraphs'] ?? [] as $p) {
                        $h[] = $this->para($p['text'], $p['say'] ?? null);
                    }
                    if (!empty($b['rule'])) {
                        $h[] = $this->para($b['rule'], null, 'rule');
                    }
                    if ($sc) {
                        $h[] = $this->stage($id);
                        $data['scenes'][$id] = ['scene' => $sc, 'steps' => [], 'tasks' => []];
                    }
                    break;
                case 'walk':
                case 'example':
                    if (!empty($b['intro'])) {
                        $h[] = $this->para($b['intro']);
                    }
                    if ($sc) {
                        $h[] = $this->stage($id);
                        $data['scenes'][$id] = ['scene' => $sc, 'steps' => array_map(fn ($s) => ['text' => $s['text'], 'say' => $s['say'] ?? $s['text']], $b['steps']), 'tasks' => []];
                    } else {
                        $this->notes[] = "{$pp['key']} block $id: no scene, steps shown as a list.";
                        $h[] = '<div class="scene-ctl"></div>';
                    }
                    $h[] = '<ol class="steps walk no-read">';
                    foreach ($b['steps'] as $s) {
                        $say = $s['say'] ?? $s['text'];
                        $role = isset($s['role']) && $s['role'] !== 'step' ? '<b>' . $this->e(ucfirst($s['role'])) . ':</b> ' : '';
                        $same = $role === '' && trim($say) === trim($s['text']) ? ' data-say-same' : '';
                        $h[] = '<li' . $same . '>' . $role . $this->e($s['text']) . '</li>';
                    }
                    $h[] = '</ol>';
                    break;
                case 'explore':
                case 'widget':
                    if (!empty($b['intro'])) {
                        $h[] = $this->para($b['intro']);
                    }
                    if (!empty($b['safety'])) {
                        $h[] = $this->para($b['safety'], null, 'safety');
                    }
                    if ($sc) {
                        $h[] = $this->stage($id);
                        $data['scenes'][$id] = ['scene' => $sc, 'steps' => [], 'tasks' => array_map(fn ($t) => ['do' => $t['do'], 'say' => $t['say'] ?? $t['do']], $b['tasks'])];
                    } else {
                        $this->notes[] = "{$pp['key']} block $id: no scene, tasks shown as a list.";
                    }
                    $h[] = '<ol class="steps tasks no-read">';
                    foreach ($b['tasks'] as $t) {
                        $h[] = '<li>' . $this->e($t['do']) . (!empty($t['notice']) ? ' <span class="meta">— ' . $this->e($t['notice']) . '</span>' : '') . '</li>';
                    }
                    $h[] = '</ol>';
                    break;
                case 'practice':
                    foreach ($b['questions'] ?? [] as $q) {
                        $qid = $id . '-' . $q['n'];
                        $data['questions'][$qid] = $q;
                        $h[] = '<div class="q no-read" data-q="' . $this->e($qid) . '"><p class="q-prompt"><span class="n">' . (int) $q['n'] . '.</span>' . $this->e($q['prompt'])
                            . ($q['source'] === 'book' ? '<span class="q-src">From the book</span>' : '') . '</p></div>';
                    }
                    break;
                default:
                    $this->notes[] = "{$pp['key']} block $id: unknown type {$b['type']}.";
            }
            $h[] = '</section>';
        }

        if (!empty($cp['remember'])) {
            $h[] = '<section class="blk remember"><h2>Remember</h2><ul>' . implode('', array_map(fn ($r) => '<li data-say="' . $this->e($r) . '" data-say-same>' . $this->e($r) . '</li>', $cp['remember'])) . '</ul></section>';
        }
        $tn = $cp['teacher_note'] ?? [];
        $h[] = '<details class="teacher no-read"><summary>Teacher note</summary>'
            . (!empty($tn['scope']) ? '<p><b>Covers:</b> ' . $this->e($tn['scope']) . '</p>' : '')
            . '<p><b>Added beyond the book:</b> ' . (!empty($tn['additions']) ? $this->e(implode('; ', $tn['additions'])) : 'nothing') . '</p>'
            . '<p><b>Corrections to the book:</b> ' . (!empty($tn['corrections']) ? $this->e(implode('; ', $tn['corrections'])) : 'none') . '</p>'
            . '<p class="meta">Built from ' . $this->e($chapter['source_pdf'] ?? 'the chapter PDF') . '. Book figures are not reproduced; the 3D models are drawn from scratch.</p></details>';

        $nav = '<nav class="pager" aria-label="Pages">';
        $nav .= $pp['prev'] ? '<a class="prev" href="' . $this->e($pp['prev']['href']) . '"><small>Back</small>' . $this->e($pp['prev']['title']) . '</a>' : '<a class="prev" href="' . $up . 'index.html"><small>Back</small>Chapter contents</a>';
        $nav .= $pp['next'] ? '<a class="next" href="' . $this->e($pp['next']['href']) . '"><small>Next</small>' . $this->e($pp['next']['title']) . '</a>' : '<a class="next" href="' . $up . 'index.html"><small>Finished</small>Chapter contents</a>';
        $nav .= '</nav>';
        $h[] = $nav;

        $crumb = '<a href="' . $up . 'index.html">' . $this->e($chapter['title'] ?? 'Contents') . '</a> / ' . $this->e($this->partTitle($chapter, $pp['folder']));
        return [
            'html' => $this->shell($title, $up . 'assets/', $crumb, implode("\n", $h), $data),
            'scenes' => count($data['scenes']),
            'questions' => count($data['questions']),
            'kinds' => array_values(array_unique(array_map(fn ($q) => $q['kind'], $data['questions']))),
        ];
    }

    public function index(array $plan, array $content, array $chapter): string
    {
        $titles = [];
        foreach ($content['pages'] ?? [] as $p) {
            $titles[$p['key']] = [$p['title'] ?? '', $p['intro'] ?? ''];
        }
        $h = ['<header class="page"><h1>' . $this->e($chapter['title']) . '</h1><p class="meta">' . count($plan['pages']) . ' pages · Built from ' . $this->e($plan['source_pdf'] ?? '') . '</p></header>'];
        foreach ($plan['parts'] as $part) {
            $h[] = '<section class="blk toc"><h2>' . $this->e($part['title']) . '</h2><ol>';
            foreach ($part['pages'] as $file) {
                $key = $part['folder'] . '/' . $file;
                [$t, $intro] = $titles[$key] ?? [$file, ''];
                $h[] = '<li><a href="' . $this->e($key) . '">' . $this->e($t) . '</a>' . ($intro ? '<span class="sum">' . $this->e(mb_strimwidth($intro, 0, 160, '…')) . '</span>' : '') . '</li>';
            }
            $h[] = '</ol></section>';
        }
        $first = $plan['pages'][0]['key'] ?? null;
        if ($first) {
            $h[] = '<p><a class="btn" href="' . $this->e($first) . '">Start the lesson</a></p>';
        }
        return $this->shell($chapter['title'], 'assets/', $this->e($chapter['title']), implode("\n", $h), ['scenes' => [], 'questions' => []]);
    }

    private function shell(string $title, string $assets, string $crumb, string $body, array $data): string
    {
        $t = $this->e($title);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        return <<<HTML
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$t</title>
<link rel="stylesheet" href="{$assets}lesson-scene.css">
<link rel="stylesheet" href="{$assets}lesson.css">
</head>
<body>
<div class="top"><div class="wrap">
  <div class="crumb">$crumb</div>
  <button type="button" class="btn small" id="listen" aria-label="Listen to this page">🔊 <span>Listen</span></button>
  <button type="button" class="btn ghost small" id="theme" aria-label="Switch light or dark">◐</button>
</div></div>
<main class="wrap">
$body
</main>
<div class="listen-bar" id="listen-bar" role="region" aria-label="Narration">
  <button type="button" class="btn ghost small" id="listen-pause">Pause</button>
  <button type="button" class="btn small" id="listen-stop">Stop</button>
</div>
<script>window.LESSON = $json;</script>
<script src="{$assets}three.min.js"></script>
<script src="{$assets}lesson-scene.js"></script>
<script src="{$assets}lesson.js"></script>
</body>
</html>
HTML;
    }

    private function para(string $text, ?string $say = null, string $cls = ''): string
    {
        $sayText = $say ?: $text;
        $same = trim($sayText) === trim($text) ? ' data-say-same' : '';
        return '<p' . ($cls ? ' class="' . $cls . '"' : '') . ' data-say="' . $this->e($sayText) . '"' . $same . '>' . $this->e($text) . '</p>';
    }

    private function stage(string $id): string
    {
        return '<div class="no-read"><div class="stage" data-scene="' . $this->e($id) . '" role="img" aria-label="Interactive 3D model"></div><div class="scene-ctl"></div></div>';
    }

    private function partTitle(array $chapter, string $folder): string
    {
        foreach ($chapter['parts'] ?? [] as $p) {
            if ($p['folder'] === $folder) {
                return $p['title'];
            }
        }
        return $folder;
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
