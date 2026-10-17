<?php

declare(strict_types=1);

namespace App\Services\Lessons\Build;

/**
 * Step 6 — Build, in plain PHP. Renders every page and the contents page, copies the assets, checks the result.
 * Returns the files (relative path => contents) plus a report; the service writes them to {folder}/build/.
 */
final class BuildRunner
{
    public function run(array $plan, array $content, array $visuals, string $enginePath): array
    {
        $t0 = microtime(true);
        $renderer = new PageRenderer();
        $chapter = [
            'title' => $this->chapterTitle($plan),
            'source_pdf' => $plan['source_pdf'] ?? null,
            'parts' => $plan['parts'] ?? [],
        ];
        $cpages = [];
        foreach ($content['pages'] ?? [] as $p) {
            $cpages[$p['key']] = $p;
        }
        $vpages = [];
        foreach ($visuals['pages'] ?? [] as $p) {
            $vpages[$p['key']] = (array) ($p['scenes'] ?? []);
        }

        $files = [];
        $pages = [];
        $notes = [];
        foreach ($plan['pages'] as $pp) {
            $cp = $cpages[$pp['key']] ?? null;
            if (!$cp) {
                continue;
            }
            // Same code-only fix as Steps 3–5: links use the titles written in Step 4.
            foreach (['prev', 'next'] as $dir) {
                if (!empty($pp[$dir])) {
                    $target = (new BuildChecker())->resolve($pp['key'], $pp[$dir]['href']);
                    if (isset($cpages[$target]['title'])) {
                        $pp[$dir]['title'] = $cpages[$target]['title'];
                    }
                }
            }
            $r = $renderer->page($pp, $cp, $vpages[$pp['key']] ?? [], $chapter);
            $files[$pp['key']] = $r['html'];
            $notes = array_merge($notes, $renderer->notes);
            $pages[] = ['key' => $pp['key'], 'title' => $cp['title'] ?? $pp['title'], 'scenes' => $r['scenes'], 'questions' => $r['questions'], 'kinds' => $r['kinds'], 'bytes' => strlen($r['html'])];
        }
        $files['index.html'] = $renderer->index($plan, $content, $chapter);
        foreach (PageRenderer::ASSETS as $a) {
            $files["assets/$a"] = is_file("$enginePath/$a") ? (string) file_get_contents("$enginePath/$a") : '';
        }

        $check = (new BuildChecker())->check($files, $plan, $content, $visuals);
        $check['warnings'] = array_values(array_unique(array_merge($check['warnings'], $notes)));
        $manifest = [
            'schema' => 'build.v1',
            'title' => $chapter['title'],
            'source_pdf' => $chapter['source_pdf'],
            'created_at' => date('c'),
            'duration_seconds' => round(microtime(true) - $t0, 2),
            'check' => ['passed' => !$check['errors'], 'errors' => $check['errors'], 'warnings' => $check['warnings']],
            'stats' => [
                'pages' => count($pages),
                'pages_planned' => count($plan['pages']),
                'scenes' => array_sum(array_column($pages, 'scenes')),
                'questions' => array_sum(array_column($pages, 'questions')),
                'bytes' => array_sum(array_map('strlen', $files)),
            ],
            'start' => 'index.html',
            'pages' => $pages,
        ];
        $files['manifest.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return ['files' => $files, 'manifest' => $manifest];
    }

    private function chapterTitle(array $plan): string
    {
        $parts = $plan['parts'] ?? [];
        foreach ($parts as $p) {
            if (str_starts_with($p['folder'], 'Chapter-')) {
                return $p['title'];
            }
        }
        return $parts[0]['title'] ?? pathinfo((string) ($plan['source_pdf'] ?? 'Chapter'), PATHINFO_FILENAME);
    }
}
