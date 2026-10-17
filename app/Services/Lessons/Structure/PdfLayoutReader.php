<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

use App\Services\Lessons\Prepare\Toolbox;

/**
 * Reads every line of a PDF with its font size, weight, colour and position (pdftohtml -xml),
 * merges wrapped lines that share a style, and returns them in reading order.
 */
final class PdfLayoutReader
{
    public function __construct(private readonly Toolbox $tools, private readonly int $timeout = 180) {}

    /**
     * @return array<int, TextLine>
     */
    public function read(string $pdfPath): array
    {
        $xml = $this->tools->run('pdftohtml', ['-xml', '-i', '-q', '-fontfullname', '-stdout', $pdfPath], $this->timeout);

        return $this->parse($xml);
    }

    /**
     * @return array<int, TextLine>
     */
    public function parse(string $xml): array
    {
        $fonts = [];
        preg_match_all('/<fontspec id="(\d+)" size="([\d.]+)" family="([^"]*)" color="([^"]*)"\/>/', $xml, $specs, PREG_SET_ORDER);
        foreach ($specs as [, $id, $size, $family, $color]) {
            $fonts[$id] = ['size' => (float) $size, 'bold' => (bool) preg_match('/bold|black|heavy|semibold/i', $family), 'color' => strtolower($color)];
        }

        $rawPages = [];
        preg_match_all('/<page number="(\d+)"[^>]*height="([\d.]+)" width="([\d.]+)">(.*?)<\/page>/s', $xml, $pages, PREG_SET_ORDER);

        foreach ($pages as [, $number, $height, $width, $body]) {
            $raw = [];
            preg_match_all('/<text top="(-?[\d.]+)" left="(-?[\d.]+)" width="([\d.]+)" height="([\d.]+)" font="(\d+)">(.*?)<\/text>/s', $body, $texts, PREG_SET_ORDER);

            foreach ($texts as [, $top, $left, $w, $h, $fontId, $inner]) {
                $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_XML1, 'UTF-8')));
                if ($text === '') {
                    continue;
                }

                $font = $fonts[$fontId] ?? ['size' => (float) $h, 'bold' => false, 'color' => '#000000'];
                $wholeLineBold = (bool) preg_match('/^\s*(<i>)?<b>.*<\/b>(<\/i>)?\s*$/s', $inner);

                $raw[] = [
                    'top' => (float) $top, 'left' => (float) $left, 'width' => (float) $w, 'height' => (float) $h,
                    'size' => $font['size'], 'bold' => $font['bold'] || $wholeLineBold, 'color' => $font['color'],
                    'text' => $text,
                    'column' => $this->column((float) $left, (float) $w, (float) $width),
                    'page_height' => (float) $height,
                ];
            }

            $rawPages[(int) $number] = $raw;
        }

        $bodySize = self::bodySize(array_merge(...array_values($rawPages ?: [[]])));

        $lines = [];
        foreach ($rawPages as $number => $raw) {
            foreach ($this->mergeRows($this->mergeWrappedLines($raw, $bodySize), $bodySize) as $line) {
                $lines[] = new TextLine($number, $line['column'], $line['top'], $line['left'], $line['size'], $line['bold'], $line['color'], $line['text']);
            }
        }

        usort($lines, fn (TextLine $a, TextLine $b) => $a->orderKey() <=> $b->orderKey());

        return $lines;
    }

    /**
     * The font size used for the most characters is the body text size.
     *
     * @param  array<int, array{size: float, text: string}>  $lines
     */
    public static function bodySize(array $lines): float
    {
        $chars = [];
        foreach ($lines as $line) {
            $key = (string) $line['size'];
            $chars[$key] = ($chars[$key] ?? 0) + mb_strlen($line['text']);
        }
        arsort($chars);

        return (float) (array_key_first($chars) ?? 0);
    }

    /**
     * Join pieces of text printed on the same line of a column (pdftohtml splits a line at every
     * subscript, symbol or font change, e.g. "13", "x", "= 12", "10"). Headings are left alone.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function mergeRows(array $lines, float $bodySize): array
    {
        $isHeading = fn (array $l) => $l['bold'] && $l['size'] > $bodySize * 1.05;

        usort($lines, fn ($a, $b) => [$a['column'], $a['top'], $a['left']] <=> [$b['column'], $b['top'], $b['left']]);

        $rows = [];
        foreach ($lines as $line) {
            $key = array_key_last($rows);
            $row = $key === null ? null : $rows[$key];

            $sameRow = $row !== null && ! $isHeading($line) && ! $row['is_heading']
                && $row['column'] === $line['column']
                && abs($line['top'] - $row['top']) <= min($row['height'], $line['height']) * 0.6;

            if ($sameRow) {
                $rows[$key]['pieces'][] = $line;

                continue;
            }

            $rows[] = $line + ['pieces' => [$line], 'is_heading' => $isHeading($line)];
        }

        return array_map(function (array $row) {
            if (count($row['pieces']) > 1) {
                usort($row['pieces'], fn ($a, $b) => $a['left'] <=> $b['left']);
                $row['text'] = implode(' ', array_column($row['pieces'], 'text'));
                // Style of the row is the style of its longest piece.
                usort($row['pieces'], fn ($a, $b) => mb_strlen($b['text']) <=> mb_strlen($a['text']));
                foreach (['size', 'bold', 'color'] as $field) {
                    $row[$field] = $row['pieces'][0][$field];
                }
                $row['left'] = min(array_column($row['pieces'], 'left'));
            }
            unset($row['pieces'], $row['is_heading']);

            return $row;
        }, $rows);
    }

    /**
     * Two-column pages: text that starts in the right half and is narrower than a full line is column 1.
     */
    private function column(float $left, float $width, float $pageWidth): int
    {
        return ($left >= $pageWidth * 0.45 && $width < $pageWidth * 0.55) ? 1 : 0;
    }

    /**
     * Join consecutive lines that share a style and sit directly under each other (a wrapped heading).
     *
     * Only bold text larger than the body is merged: body paragraphs stay line by line.
     *
     * @param  array<int, array<string, mixed>>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function mergeWrappedLines(array $raw, float $bodySize): array
    {
        $merged = [];

        foreach ($raw as $line) {
            $last = $merged === [] ? null : $merged[array_key_last($merged)];

            $continues = $last !== null
                && $line['bold'] && $line['size'] > $bodySize * 1.05
                && $last['size'] === $line['size'] && $last['bold'] === $line['bold'] && $last['color'] === $line['color']
                && $last['column'] === $line['column']
                && abs($last['left'] - $line['left']) <= 40
                && $line['top'] > $last['top']
                && $line['top'] - $last['bottom'] <= $line['height'] * 0.6;

            if ($continues) {
                $joined = str_ends_with($last['text'], '-') && preg_match('/^\p{Ll}/u', $line['text'])
                    ? mb_substr($last['text'], 0, -1).$line['text']
                    : $last['text'].' '.$line['text'];
                $merged[array_key_last($merged)]['text'] = $joined;
                $merged[array_key_last($merged)]['bottom'] = $line['top'] + $line['height'];

                continue;
            }

            $line['bottom'] = $line['top'] + $line['height'];
            $merged[] = $line;
        }

        return $merged;
    }
}
