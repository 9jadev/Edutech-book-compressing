<?php

declare(strict_types=1);

namespace App\Services\Lessons\Plan;

/**
 * Step 3, part 1 (no AI): cut the chapter into lesson pages, in book order.
 *
 * Rules (from the process notes, Phase 5):
 *  - every chapter_title starts a new part (a split PDF can hold "Introduction" + "Chapter 1");
 *  - each part opens with 00-welcome.html, which also holds anything printed before the first heading;
 *  - every heading starts a new section page NN-1-slug.html;
 *  - a section with more than max_items is split at its subheadings (NN-1, NN-2, ...);
 *  - an activity gets its own page right after its section (NN-k-activity-slug.html);
 *  - a section with fewer than min_items is joined to the page before it.
 * Every structure item lands on exactly one page.
 */
final class PageSplitter
{
    /** Item types that only mark where things start; they are not "content". */
    public const MARKERS = ['chapter_title', 'heading', 'subheading'];

    public function __construct(
        private readonly int $minItems = 3,
        private readonly int $maxItems = 30,
        private readonly bool $activityOwnPage = true,
    ) {
    }

    /**
     * @param array $structure decoded structure.json
     * @return array{parts: array<int,array>, pages: array<int,array>}
     */
    public function split(array $structure): array
    {
        $items = array_values($structure['items'] ?? []);
        $firstPrinted = isset($structure['first_printed_page']) ? (int) $structure['first_printed_page'] : null;
        $fallbackTitle = pathinfo((string) ($structure['source_pdf'] ?? 'chapter'), PATHINFO_FILENAME);

        // 1. Parts: one per chapter_title.
        $parts = [];
        foreach ($items as $it) {
            if ($it['type'] === 'chapter_title' || !$parts) {
                $parts[] = ['title_item' => $it['type'] === 'chapter_title' ? $it : null, 'items' => []];
            }
            $parts[count($parts) - 1]['items'][] = $it;
        }

        $outParts = [];
        $pages = [];
        $usedFolders = [];
        foreach ($parts as $pi => $part) {
            $folder = $this->partFolder($part['title_item'], $pi, $fallbackTitle, $usedFolders);
            $partTitle = $this->partTitle($part['title_item'], $fallbackTitle);

            // 2. Sections: welcome (before first heading), then one per heading.
            $sections = [['heading' => null, 'items' => []]];
            foreach ($part['items'] as $it) {
                if ($it['type'] === 'heading') {
                    $sections[] = ['heading' => $it, 'items' => [$it]];
                } else {
                    $sections[count($sections) - 1]['items'][] = $it;
                }
            }

            // 3. Join small sections to the one before (never into welcome).
            $joined = [$sections[0]];
            foreach (array_slice($sections, 1) as $s) {
                $prev = count($joined) - 1;
                if ($prev >= 1 && $this->contentCount($s['items']) < $this->minItems && !$this->hasActivity($s['items'])) {
                    $joined[$prev]['items'] = array_merge($joined[$prev]['items'], $s['items']);
                    $joined[$prev]['joined'][] = $s['heading'];
                    continue;
                }
                $joined[] = $s;
            }

            // 4. Pages.
            $partPages = [];
            $welcome = $joined[0];
            $partPages[] = $this->page($folder, '00-welcome.html', 'welcome', $partTitle, $welcome['items'], [], $firstPrinted);

            foreach (array_slice($joined, 1) as $si => $s) {
                $nn = sprintf('%02d', $si + 1);
                [$main, $activities] = $this->activityOwnPage ? $this->pullActivities($s['items']) : [$s['items'], []];
                $chunks = $this->chunk($main);
                $m = 0;
                $title = $this->clean((string) ($s['heading']['title'] ?? $s['heading']['label'] ?? 'Section'));
                $labels = array_values(array_filter(array_map(fn ($h) => $h['label'] ?? null, array_merge([$s['heading']], $s['joined'] ?? []))));
                foreach ($chunks as $ci => $chunk) {
                    $m++;
                    $pageTitle = $ci === 0 ? $title : $title . ': ' . $this->clean((string) ($this->firstSub($chunk)['title'] ?? 'continued'));
                    $slug = $this->slug($ci === 0 ? $title : $pageTitle);
                    $partPages[] = $this->page($folder, "$nn-$m-$slug.html", 'section', $pageTitle, $chunk, $labels, $firstPrinted);
                }
                foreach ($activities as $group) {
                    $m++;
                    $a = $group[0];
                    $aTitle = $this->clean(trim(($a['label'] ?? '') . ' ' . ($a['title'] ?? ''))) ?: 'Activity';
                    if (!preg_match('/activity|exercise|experiment|practical/i', $aTitle)) {
                        $aTitle = "Activity: $aTitle";
                    }
                    $aSlug = $this->slug($aTitle === 'Activity' ? $title : $aTitle);
                    $aSlug = str_starts_with($aSlug, 'activity') ? $aSlug : "activity-$aSlug";
                    $partPages[] = $this->page($folder, "$nn-$m-$aSlug", 'activity', $aTitle, $group, $labels, $firstPrinted, true);
                }
            }

            $outParts[] = [
                'part' => $pi + 1,
                'folder' => $folder,
                'title' => $partTitle,
                'chapter_item' => $part['title_item']['id'] ?? null,
                'pages' => array_map(fn ($p) => $p['file'], $partPages),
            ];
            foreach ($partPages as $p) {
                $pages[] = $p;
            }
        }

        // 5. Back / Next links across the whole run, in book order.
        foreach ($pages as $i => &$p) {
            $p['prev'] = $i > 0 ? $this->link($p, $pages[$i - 1]) : null;
            $p['next'] = $i < count($pages) - 1 ? $this->link($p, $pages[$i + 1]) : null;
        }
        unset($p);

        return ['parts' => $outParts, 'pages' => $pages];
    }

    /** Content items (not headings) — what a lesson page actually teaches. */
    public function contentCount(array $items): int
    {
        return count(array_filter($items, fn ($it) => !in_array($it['type'], self::MARKERS, true)));
    }

    private function page(string $folder, string $file, string $kind, string $title, array $items, array $labels, ?int $firstPrinted, bool $isActivity = false): array
    {
        $file = str_ends_with($file, '.html') ? $file : "$file.html";
        $pdfPages = array_values(array_unique(array_map(fn ($it) => (int) $it['page'], $items)));
        sort($pdfPages);
        return [
            'key' => "$folder/$file",
            'folder' => $folder,
            'file' => $file,
            'kind' => $kind,
            'title' => $title,
            'section_labels' => $labels,
            'pdf_pages' => $pdfPages,
            'book_pages' => $firstPrinted !== null ? array_map(fn ($n) => $firstPrinted + $n - 1, $pdfPages) : [],
            'items' => array_map(fn ($it) => $it['id'], $items),
            'content_items' => $this->contentCount($items),
        ];
    }

    /** Activity + the steps/figures/tables that belong to it go to their own page. */
    private function pullActivities(array $items): array
    {
        $main = [];
        $groups = [];
        $n = count($items);
        for ($i = 0; $i < $n; $i++) {
            $it = $items[$i];
            if ($it['type'] !== 'activity') {
                $main[] = $it;
                continue;
            }
            $keys = array_filter([$it['label'] ?? null, $it['title'] ?? null]);
            $group = [$it];
            while ($i + 1 < $n) {
                $nx = $items[$i + 1];
                $child = $nx['parent'] !== null && in_array($nx['parent'], $keys, true);
                $attached = in_array($nx['type'], ['list', 'figure', 'table', 'note'], true) && ($nx['parent'] === null || $child);
                if (!$child && !$attached) {
                    break;
                }
                $group[] = $nx;
                $i++;
            }
            $groups[] = $group;
        }
        if (!$main && $groups) {
            // A section that is only an activity stays a normal page.
            return [array_merge(...$groups), []];
        }
        return [$main, $groups];
    }

    /** Split a long section at subheadings so no page has more than maxItems content items. */
    private function chunk(array $items): array
    {
        if ($this->contentCount($items) <= $this->maxItems) {
            return [$items];
        }
        // Blocks that must stay together: a subheading starts a new block.
        $blocks = [];
        foreach ($items as $it) {
            if (!$blocks || $it['type'] === 'subheading') {
                $blocks[] = [];
            }
            $blocks[count($blocks) - 1][] = $it;
        }
        $chunks = [[]];
        foreach ($blocks as $b) {
            $cur = count($chunks) - 1;
            if ($chunks[$cur] && $this->contentCount($chunks[$cur]) + $this->contentCount($b) > $this->maxItems) {
                $chunks[] = [];
                $cur++;
            }
            $chunks[$cur] = array_merge($chunks[$cur], $b);
        }
        return $chunks;
    }

    private function hasActivity(array $items): bool
    {
        return (bool) array_filter($items, fn ($it) => $it['type'] === 'activity');
    }

    private function firstSub(array $items): array
    {
        foreach ($items as $it) {
            if ($it['type'] === 'subheading') {
                return $it;
            }
        }
        return [];
    }

    private function partTitle(?array $title, string $fallback): string
    {
        if (!$title) {
            return $this->clean(str_replace(['-', '_'], ' ', $fallback));
        }
        $t = $this->clean((string) ($title['title'] ?? ''));
        $num = $this->number($title);
        return $num !== null ? "Chapter $num: $t" : $t;
    }

    private function partFolder(?array $title, int $index, string $fallback, array &$used): string
    {
        $name = $title ? $this->clean((string) ($title['title'] ?? '')) : $this->clean(str_replace(['-', '_'], ' ', $fallback));
        $slug = implode('-', array_map('ucfirst', explode('-', $this->slug($name, 40))));
        $num = $title ? $this->number($title) : null;
        $folder = $num !== null ? sprintf('Chapter-%02d-%s', $num, $slug) : sprintf('Part-%02d-%s', $index, $slug);
        $base = $folder;
        for ($k = 2; isset($used[$folder]); $k++) {
            $folder = "$base-$k";
        }
        $used[$folder] = true;
        return $folder;
    }

    private function number(array $title): ?int
    {
        foreach ([$title['label'] ?? '', $title['title'] ?? ''] as $s) {
            if (preg_match('/^\s*(?:chapter\s*)?(\d{1,3})\b/i', (string) $s, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    private function link(array $from, array $to): array
    {
        $href = $from['folder'] === $to['folder'] ? $to['file'] : "../{$to['folder']}/{$to['file']}";
        return ['href' => $href, 'title' => $to['title']];
    }

    /** "THE STRUCTURE OF A TYPICAL CELL." -> "The structure of a typical cell" */
    public function clean(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        $s = preg_replace('/^(?:[A-H]|\d{1,2}|[a-h])[.)]\s+/u', '', $s) ?? $s;   // drop "A. " / "1. " / "a) "
        $s = rtrim($s, " .:;,-");
        $letters = preg_replace('/[^\p{L}]/u', '', $s) ?? '';
        if ($letters !== '' && mb_strtoupper($letters) === $letters) {
            $s = mb_strtoupper(mb_substr(mb_strtolower($s), 0, 1)) . mb_substr(mb_strtolower($s), 1);
        }
        return $s;
    }

    public function slug(string $s, int $max = 40): string
    {
        $s = $this->clean($s);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            $s = $t !== false ? $t : $s;
        }
        $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '');
        $s = trim($s, '-');
        if (strlen($s) > $max) {
            $cut = substr($s, 0, $max);
            $s = str_contains($cut, '-') ? substr($cut, 0, (int) strrpos($cut, '-')) : $cut;
        }
        return $s !== '' ? $s : 'section';
    }
}
