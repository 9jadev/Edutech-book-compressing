<?php

declare(strict_types=1);

namespace App\Services\Lessons\Prepare;

use InvalidArgumentException;
use RuntimeException;

/**
 * Step 1 — Prepare (no AI). Framework-free core; the Laravel service wraps it.
 *
 * Turns ONE chapter PDF into, inside $outDir:
 *   images/p-N.png      full-quality page images
 *   images_ai/p-N.jpg   smaller copies (default 1000 px wide) to send to the AI in step 2
 *   pages.json          per page: text, OCR text for figure pages, figure labels,
 *                       marker counts, quality checks, warnings
 *
 * Text modes:
 *   auto      Check the PDF's own text layer on sample pages. If good, use it and OCR only
 *             scanned/garbled pages (plus figure pages, for labels). If bad, OCR everything.
 *   pdf_text  Always use the PDF text (figure pages are still OCR'd for labels).
 *   ocr       OCR every page.
 */
final class ChapterPreparer
{
    /** Markers the AI's structure record is later checked against (step 2b). Tune per book style. */
    public const MARKERS = [
        'figures'    => '/\bFig(?:ure)?\.?\s*\d+(?:\.\d+)?/i',
        'tables'     => '/\bTable\s+\d+(?:\.\d+)?/i',
        'examples'   => '/\bExample\s+\d+(?:\.\d+)?/i',
        'exercises'  => '/\bExercise\s*\d*(?:\.\d+)?/i',
        'activities' => '/\bActivity\s*\d*(?:\.\d+)?|\bEXPERIMENT\b|\bPractical\b/',
        'questions'  => '/^\s*(?:Q\.?\s*)?\d{1,2}[.)]\s+\S/m',
        'parts'      => '/\(\s*[a-h]\s*\)|^\s*[a-h]\)\s/m',
        'exam_tags'  => '/\[(?:WAEC|NECO|JAMB|GCE|BECE|SSCE)[^\]]*\]/i',
        'headings'   => '/^\s*[A-H]\.\s+[A-Z][^\n]{2,}$/m',
    ];

    private ?array $imageIndex = null;
    private int $pageCount = 0;

    public function __construct(
        private readonly Toolbox $tools,
        private readonly PrepareOptions $opt = new PrepareOptions(),
    ) {
    }

    /**
     * @return array<string,mixed> the same data that is written to pages.json
     */
    public function run(string $pdf, string $outDir): array
    {
        if (!is_file($pdf)) {
            throw new InvalidArgumentException("PDF not found: $pdf");
        }
        $this->tools->assertInstalled();
        $this->assertOcrLanguage();

        $started = microtime(true);
        $this->ensureDir("$outDir/images");
        if ($this->opt->makeAiImages) {
            $this->ensureDir("$outDir/images_ai");
        }
        $this->clearOld($outDir);

        $n = $this->pageCount = $this->readPageCount($pdf);
        $this->tools->run('pdftoppm', ['-r', (string) $this->opt->dpi, '-png', $pdf, "$outDir/images/p"], max($this->opt->toolTimeout, 60 * $n));

        // 1. The PDF's own text for every page.
        $raw = [];
        $pdfText = [];
        for ($i = 1; $i <= $n; $i++) {
            $raw[$i] = $this->tools->run('pdftotext', ['-layout', '-f', (string) $i, '-l', (string) $i, $pdf, '-'], $this->opt->toolTimeout);
            $pdfText[$i] = $this->clean($raw[$i]);
        }

        // 2. Printed page numbers: given, or detected from numbers printed on the pages.
        $printed = $this->opt->firstPrintedPage !== null
            ? ['first_printed_page' => $this->opt->firstPrintedPage, 'source' => 'given', 'votes' => null]
            : $this->detectPrintedStart($raw, $n);

        // 3. Text-layer check on sample pages, then the strategy for this chapter.
        $check = $this->textLayerCheck($pdfText, $n, $outDir);
        $ocrCache = $check['ocr_cache'];
        unset($check['ocr_cache']);
        $strategy = match ($this->opt->mode) {
            'ocr'      => 'ocr_all',
            'pdf_text' => 'pdf_text',
            default    => $check['verdict'] === 'good' ? 'pdf_text' : 'ocr_all',
        };

        // 4. One record per page.
        $pages = [];
        for ($i = 1; $i <= $n; $i++) {
            $img = $this->imagePath($outDir, $i);
            $hasFigure = $this->hasImages($pdf, $i);
            $own = $pdfText[$i];
            $q = $this->quality($own);

            $needOcr = $strategy === 'ocr_all'
                || $q['real_chars'] < $this->opt->minChars
                || !$q['looks_ok']
                || ($hasFigure && $this->opt->ocrFigurePages);
            $ocr = $needOcr ? ($ocrCache[$i] ?? $this->ocr($img)) : null;

            if ($strategy === 'ocr_all') {
                [$text, $source, $reason] = [$ocr['text'], 'ocr', $this->opt->mode === 'ocr' ? 'ocr mode chosen' : 'chapter text layer failed the check'];
            } elseif ($q['real_chars'] < $this->opt->minChars) {
                [$text, $source, $reason] = [$ocr['text'], 'ocr', 'scanned page (no text layer)'];
            } elseif (!$q['looks_ok']) {
                [$text, $source, $reason] = [$ocr['text'], 'ocr', 'garbled text layer on this page'];
            } else {
                [$text, $source, $reason] = [$own, 'pdf_text', 'text layer is good'];
            }

            $figureLabels = ($ocr && $source === 'pdf_text') ? $this->extraLines($ocr['text'], $own) : [];
            $size = @getimagesize($img) ?: [0, 0];
            $aiImg = $this->opt->makeAiImages ? $this->aiCopy($img, $outDir, $i) : null;
            $first = $printed['first_printed_page'];

            $pages[] = [
                'pdf_page'           => $i,
                'printed_page'       => $first !== null ? $first + $i - 1 : null,
                'image'              => 'images/' . basename($img),
                'image_ai'           => $aiImg ? 'images_ai/' . basename($aiImg) : null,
                'image_size'         => [$size[0], $size[1]],
                'has_figure_image'   => $hasFigure,
                'text_source'        => $source,
                'text_source_reason' => $reason,
                'text'               => $text,
                'ocr_text'           => $ocr['text'] ?? null,
                'ocr_confidence'     => $ocr['confidence'] ?? null,
                'figure_labels'      => $figureLabels,
                'quality'            => [
                    'pdf_text_real_chars' => $q['real_chars'],
                    'pdf_text_garbage'    => $q['garbage'],
                    'pdf_text_wordlike'   => $q['wordlike'],
                    'ocr_agreement'       => $ocr ? $this->agreement($ocr['text'], $own) : null,
                ],
                'markers'            => $this->markers($text),
            ];
        }

        // Scanned chapters have no text layer to find page numbers in: try again on the OCR text.
        if ($printed['first_printed_page'] === null && $this->opt->firstPrintedPage === null) {
            $retry = $this->detectPrintedStart(array_combine(range(1, $n), array_column($pages, 'text')), $n);
            if ($retry['first_printed_page'] !== null) {
                $printed = $retry + ['from' => 'ocr_text'];
                foreach ($pages as $k => $pg) {
                    $pages[$k]['printed_page'] = $retry['first_printed_page'] + $pg['pdf_page'] - 1;
                }
            }
        }

        $result = [
            'schema'           => 'pages.v1',
            'source_pdf'       => basename($pdf),
            'page_count'       => $n,
            'first_printed_page' => $printed['first_printed_page'],
            'page_offset'      => $printed['first_printed_page'] !== null ? $printed['first_printed_page'] - 1 : null,
            'printed_page_detection' => $printed,
            'mode'             => $this->opt->mode,
            'strategy_used'    => $strategy,
            'text_layer_check' => $check,
            'created_at'       => date('c'),
            'duration_seconds' => round(microtime(true) - $started, 1),
            'tools'            => $this->tools->versions() + ['ocr_lang' => $this->opt->ocrLang],
            'totals'           => $this->totals($pages),
            'warnings'         => $this->warnings($pages, $check, $printed),
            'pages'            => $pages,
        ];

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Could not encode pages.json: ' . json_last_error_msg());
        }
        $this->atomicWrite("$outDir/pages.json", $json);
        return $result;
    }

    /* ---------------------------------------------------------------- printed page numbers */

    /**
     * Looks for a bare page number in the first/last lines of each page and votes on
     * "printed number - pdf page index". Works for "9", "- 9 -", "Page 9", "9 | Chapter 1".
     */
    private function detectPrintedStart(array $raw, int $n): array
    {
        $votes = [];
        for ($i = 1; $i <= $n; $i++) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw[$i]) ?: []), fn ($l) => $l !== ''));
            $edge = array_unique(array_merge(array_slice($lines, 0, 3), array_slice($lines, -3)));
            foreach ($edge as $line) {
                if (preg_match('/^(?:page\s+)?[-–—]?\s*(\d{1,4})\s*[-–—]?$/i', $line, $m)
                    || preg_match('/^(\d{1,4})\s+[|•·]\s+\S/', $line, $m)
                    || preg_match('/\S\s+[|•·]\s+(\d{1,4})$/', $line, $m)) {
                    $start = (int) $m[1] - ($i - 1);
                    if ($start >= 0) {
                        $votes[$start] = ($votes[$start] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($votes);
        $best = array_key_first($votes);
        $need = $n === 1 ? 1 : max(2, (int) ceil($n * 0.4));
        if ($best !== null && $votes[$best] >= $need) {
            return ['first_printed_page' => (int) $best, 'source' => 'detected', 'votes' => $votes[$best] . " of $n pages"];
        }
        return ['first_printed_page' => null, 'source' => 'not_found', 'votes' => $votes ? (reset($votes) . " of $n pages") : '0'];
    }

    /* ---------------------------------------------------------------- checks */

    private function textLayerCheck(array $pdfText, int $n, string $outDir): array
    {
        if ($this->opt->mode !== 'auto') {
            return ['verdict' => 'skipped', 'reason' => "mode '{$this->opt->mode}' chosen", 'samples' => [], 'ocr_cache' => []];
        }
        $candidates = range(1, $n);
        usort($candidates, fn ($a, $b) => abs($a - ($n + 1) / 2) <=> abs($b - ($n + 1) / 2));
        $withText = array_values(array_filter($candidates, fn ($i) => $this->quality($pdfText[$i])['real_chars'] >= $this->opt->minChars));
        $picked = array_slice($withText, 0, $this->opt->probePages);

        if (!$picked) {
            return ['verdict' => 'scanned', 'reason' => 'no page has a usable text layer', 'samples' => [], 'ocr_cache' => []];
        }
        $samples = [];
        $cache = [];
        foreach ($picked as $i) {
            $ocr = $this->ocr($this->imagePath($outDir, $i));
            $cache[$i] = $ocr;
            $q = $this->quality($pdfText[$i]);
            $samples[] = [
                'pdf_page'       => $i,
                'agreement'      => $this->agreement($ocr['text'], $pdfText[$i]),
                'garbage'        => $q['garbage'],
                'wordlike'       => $q['wordlike'],
                'ocr_confidence' => $ocr['confidence'],
            ];
        }
        $avg = fn (string $k) => round(array_sum(array_column($samples, $k)) / count($samples), 3);
        $agreement = $avg('agreement');
        $garbage = $avg('garbage');
        $wordlike = $avg('wordlike');
        $ocrConf = $avg('ocr_confidence');

        $problems = [];
        if ($garbage > $this->opt->maxGarbage) {
            $problems[] = "too many odd symbols in the PDF text ($garbage)";
        }
        if ($wordlike < $this->opt->minWordlike) {
            $problems[] = "too few real-looking words in the PDF text ($wordlike)";
        }
        if ($agreement < $this->opt->goodAgreement && $ocrConf >= $this->opt->lowOcrConfidence) {
            $problems[] = "PDF text and OCR disagree (agreement $agreement)";
        }
        $verdict = $problems ? 'bad' : 'good';
        // A chapter that is mostly scanned pages with one or two typed pages is still "scanned".
        if (count($withText) < max(1, (int) floor($n / 3))) {
            $verdict = 'scanned';
            $problems[] = 'most pages have no text layer';
        }
        return [
            'verdict'        => $verdict,
            'reason'         => $problems ? implode('; ', $problems) : 'PDF text matches what is printed on the page',
            'agreement'      => $agreement,
            'garbage'        => $garbage,
            'wordlike'       => $wordlike,
            'ocr_confidence' => $ocrConf,
            'samples'        => $samples,
            'ocr_cache'      => $cache,
        ];
    }

    private function quality(string $t): array
    {
        $real = (int) preg_match_all('/[\p{L}\p{N}]/u', $t);
        $visible = (int) preg_match_all('/\S/u', $t) ?: 1;
        $odd = (int) preg_match_all('/[\x{FFFD}\x{E000}-\x{F8FF}\x{0000}-\x{0008}\x{000E}-\x{001F}]|[^\p{L}\p{N}\s.,;:!?\'"()\[\]{}\-–—+=×÷*\/%°<>^_|~&#@$€£₦•·…≈≤≥±√πθλμσΩ∆’‘“”]/u', $t);
        $tokens = preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $alpha = array_filter($tokens, fn ($w) => (bool) preg_match('/\p{L}/u', $w));
        $wordlike = array_filter($alpha, fn ($w) => (bool) preg_match('/^[\p{L}\'’\-]{1,25}[.,;:!?)]*$/u', (string) preg_replace('/^[(\["\'“‘]+/u', '', $w))
            && (bool) preg_match('/[aeiouyAEIOUY]/', $w));
        $wordRatio = $alpha ? count($wordlike) / count($alpha) : 0.0;
        return [
            'real_chars' => $real,
            'garbage'    => round($odd / $visible, 3),
            'wordlike'   => round($wordRatio, 3),
            'looks_ok'   => $real < $this->opt->minChars
                ? true
                : ($odd / $visible) <= $this->opt->maxGarbage && (!$alpha || $wordRatio >= $this->opt->minWordlike),
        ];
    }

    private function agreement(string $ocr, string $pdf): float
    {
        $o = $this->words($ocr);
        if (!$o) {
            return 0.0;
        }
        $p = array_flip($this->words($pdf));
        $hit = count(array_filter($o, fn ($w) => isset($p[$w])));
        return round($hit / count($o), 3);
    }

    /** Lines OCR read that are mostly missing from the PDF text: usually labels inside diagrams. */
    private function extraLines(string $ocr, string $pdf): array
    {
        $p = array_flip($this->words($pdf));
        $extra = [];
        foreach (preg_split('/\R/', $ocr) ?: [] as $line) {
            $ws = $this->words($line);
            if (!$ws) {
                continue;
            }
            $missing = array_filter($ws, fn ($w) => !isset($p[$w]));
            if (count($missing) / count($ws) >= 0.5) {
                $extra[] = trim((string) preg_replace('/\s+/', ' ', $line));
            }
        }
        return array_values(array_unique(array_filter($extra, fn ($l) => mb_strlen($l) >= 3 && mb_strlen($l) <= 60)));
    }

    private function words(string $t): array
    {
        preg_match_all('/\p{L}{3,}/u', mb_strtolower($t), $m);
        return $m[0];
    }

    private function warnings(array $pages, array $check, array $printed): array
    {
        $w = [];
        if ($check['verdict'] === 'bad') {
            $w[] = "Text layer check failed ({$check['reason']}). The whole chapter was read with OCR.";
        }
        if ($check['verdict'] === 'scanned') {
            $w[] = 'Scanned chapter: no usable text layer. All text comes from OCR.';
        }
        if ($printed['first_printed_page'] === null) {
            $w[] = 'Printed page numbers could not be detected. Pass first_printed_page so pages match the book.';
        }
        foreach ($pages as $p) {
            $n = $p['pdf_page'];
            if ($p['text_source'] === 'ocr' && $p['ocr_confidence'] !== null && $p['ocr_confidence'] < $this->opt->lowOcrConfidence) {
                $w[] = "Page $n: OCR confidence {$p['ocr_confidence']}. Numbers and symbols on this page need extra checking.";
            }
            if ($p['text_source_reason'] === 'garbled text layer on this page') {
                $w[] = "Page $n: the PDF text was garbled, so OCR text is used for this page.";
            }
            if ((int) preg_match_all('/[\p{L}\p{N}]/u', $p['text']) < $this->opt->minChars) {
                $w[] = "Page $n: almost no text (a full-page picture or a blank page?).";
            }
            if ($p['has_figure_image'] && !$p['markers']['figures']['count']) {
                $w[] = "Page $n: has a picture but no 'Fig' caption was found. Step 2 should check for an unnumbered figure.";
            }
        }
        if ($this->opt->mode === 'pdf_text') {
            $w[] = 'Text layer check was skipped (mode pdf_text). Use auto mode for a new book.';
        }
        return $w;
    }

    /* ---------------------------------------------------------------- helpers */

    private function assertOcrLanguage(): void
    {
        $have = $this->tools->tesseractLanguages();
        foreach (explode('+', $this->opt->ocrLang) as $lang) {
            if ($have && !in_array($lang, $have, true)) {
                throw new RuntimeException("Tesseract language '$lang' is not installed (have: " . implode(', ', $have) . '). macOS: brew install tesseract-lang');
            }
        }
    }

    private function ocr(string $img): array
    {
        $tsv = $this->tools->run('tesseract', [$img, 'stdout', '-l', $this->opt->ocrLang, '--psm', '3', 'tsv'], $this->opt->toolTimeout);
        $lines = [];
        $confs = [];
        foreach (array_slice(explode("\n", $tsv), 1) as $row) {
            $c = explode("\t", $row);
            if (count($c) < 12 || trim($c[11]) === '' || (float) $c[10] < 0) {
                continue;
            }
            $lines["$c[2]-$c[3]-$c[4]"][] = $c[11];
            $confs[] = (float) $c[10];
        }
        return [
            'text'       => $this->clean(implode("\n", array_map(fn ($w) => implode(' ', $w), $lines))),
            'confidence' => $confs ? round(array_sum($confs) / count($confs), 1) : 0.0,
        ];
    }

    private function markers(string $t): array
    {
        $out = [];
        foreach (self::MARKERS as $k => $re) {
            preg_match_all($re, $t, $m);
            $found = array_values(array_unique(array_map(fn ($s) => trim((string) preg_replace('/\s+/', ' ', $s)), $m[0])));
            $out[$k] = ['count' => count($found), 'found' => $found];
        }
        return $out;
    }

    private function totals(array $pages): array
    {
        $t = [];
        foreach (array_keys(self::MARKERS) as $k) {
            $t[$k] = array_sum(array_map(fn ($p) => $p['markers'][$k]['count'], $pages));
        }
        $t['ocr_pages'] = count(array_filter($pages, fn ($p) => $p['text_source'] === 'ocr'));
        $t['figure_pages'] = count(array_filter($pages, fn ($p) => $p['has_figure_image']));
        $t['figure_labels'] = array_sum(array_map(fn ($p) => count($p['figure_labels']), $pages));
        return $t;
    }

    /** A real picture on the page, ignoring backgrounds/logos repeated on most pages, faded backgrounds and icons. */
    private function hasImages(string $pdf, int $page): bool
    {
        if ($this->imageIndex === null) {
            $this->imageIndex = [];
            $list = $this->tools->run('pdfimages', ['-list', $pdf], $this->opt->toolTimeout, true);
            foreach (array_slice(explode("\n", trim($list)), 2) as $row) {
                $c = preg_split('/\s+/', trim($row)) ?: [];
                if (count($c) < 14 || !ctype_digit($c[0])) {
                    continue;
                }
                $this->imageIndex[] = ['page' => (int) $c[0], 'obj' => $c[10], 'w' => (int) $c[3], 'h' => (int) $c[4], 'ppi' => (int) $c[12]];
            }
        }
        $n = $this->pageCount;
        $seenOn = [];
        foreach ($this->imageIndex as $im) {
            $seenOn[$im['obj']][$im['page']] = true;
        }
        foreach ($this->imageIndex as $im) {
            if ($im['page'] !== $page) {
                continue;
            }
            $repeated = $n >= 3 && count($seenOn[$im['obj']]) >= max(2, (int) ceil($n * 0.5));
            $faded = $im['ppi'] > 0 && $im['ppi'] < 60;
            $tiny = $im['w'] < 40 || $im['h'] < 40;
            if (!$repeated && !$faded && !$tiny) {
                return true;
            }
        }
        return false;
    }

    private function aiCopy(string $img, string $outDir, int $i): ?string
    {
        if (!function_exists('imagecreatefrompng')) {
            return null;
        }
        $src = @imagecreatefrompng($img);
        if (!$src) {
            return null;
        }
        $width = min($this->opt->aiImageWidth, imagesx($src));
        $dst = imagescale($src, $width);
        $path = sprintf('%s/images_ai/p-%d.jpg', $outDir, $i);
        imagejpeg($dst, $path, 85);
        imagedestroy($src);
        imagedestroy($dst);
        return $path;
    }

    private function clean(string $t): string
    {
        $t = str_replace(["\f", "\u{00AD}"], '', $t);
        foreach ($this->opt->headerPatterns as $re) {
            $t = (string) preg_replace($re, '', $t);
        }
        $t = (string) preg_replace("/[ \t]+\n/", "\n", $t);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $t));
    }

    private function readPageCount(string $pdf): int
    {
        preg_match('/^Pages:\s+(\d+)/m', $this->tools->run('pdfinfo', [$pdf], $this->opt->toolTimeout), $m);
        $n = (int) ($m[1] ?? 0);
        if ($n === 0) {
            throw new RuntimeException("Could not read the page count of $pdf (encrypted or damaged PDF?)");
        }
        return $n;
    }

    /** pdftoppm pads page numbers to the digit count of the last page (p-1.png, p-01.png, p-001.png). */
    private function imagePath(string $outDir, int $i): string
    {
        foreach (array_unique([strlen((string) $this->pageCount), 1, 2, 3, 4]) as $d) {
            $f = sprintf("%s/images/p-%0{$d}d.png", $outDir, $i);
            if (is_file($f)) {
                return $f;
            }
        }
        throw new RuntimeException("Image for page $i not found in $outDir/images");
    }

    /** Remove images and pages.json from an earlier run so a re-run never mixes old and new files. */
    private function clearOld(string $outDir): void
    {
        foreach (array_merge(glob("$outDir/images/p-*.png") ?: [], glob("$outDir/images_ai/p-*.jpg") ?: []) as $f) {
            @unlink($f);
        }
        @unlink("$outDir/pages.json");
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create folder $dir");
        }
    }

    private function atomicWrite(string $path, string $data): void
    {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $data) === false || !rename($tmp, $path)) {
            throw new RuntimeException("Could not write $path");
        }
    }
}
