<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

/**
 * Finds the lines that start each lesson in a chapter. Two signals are tried, in this order:
 *
 *  1. "labelled": bold lines labelled in sequence, "A. Movement of molecules", "B. Osmosis" (or 1., 2., 3.).
 *  2. "styled":   one heading style (size, weight, colour) that is bigger than the body text and is
 *                 used for several headings, e.g. the red 21pt bold titles of a maths textbook.
 *
 * If neither finds at least two headings, the chapter is one lesson ("whole_chapter").
 */
final class LessonHeadingDetector
{
    /**
     * @param  string[]  $ignoredTitles  box titles that are never lessons, matched at the start (case-insensitive)
     */
    public function __construct(
        private readonly array $ignoredTitles = [],
        private readonly int $minHeadings = 2,
    ) {}

    /**
     * @param  array<int, TextLine>  $lines  in reading order
     * @return array{strategy: string, headings: array<int, TextLine>, body_size: float}
     */
    public function detect(array $lines): array
    {
        $bodySize = PdfLayoutReader::bodySize(array_map(fn (TextLine $l) => ['size' => $l->size, 'text' => $l->text], $lines));
        $bodyColor = $this->bodyColor($lines, $bodySize);

        foreach (['labelled' => fn () => $this->labelled($lines, $bodySize), 'styled' => fn () => $this->styled($lines, $bodySize, $bodyColor)] as $strategy => $find) {
            $headings = $find();
            if (count($headings) >= $this->minHeadings) {
                return ['strategy' => $strategy, 'headings' => $headings, 'body_size' => $bodySize];
            }
        }

        return ['strategy' => 'whole_chapter', 'headings' => [], 'body_size' => $bodySize];
    }

    /**
     * @param  array<int, TextLine>  $lines
     * @return array<int, TextLine>
     */
    private function labelled(array $lines, float $bodySize): array
    {
        $best = [];

        foreach ([range('A', 'Z'), array_map('strval', range(1, 40))] as $sequence) {
            $found = [];
            $next = 0;

            foreach ($lines as $line) {
                if (! $line->bold || $line->size > $bodySize * 1.3 || $line->size < $bodySize * 0.95) {
                    continue;
                }
                if (! preg_match('/^\s*([A-Z]|\d{1,2})[.)]\s+(\p{Lu}|\p{L}{2})/u', $line->text, $m)) {
                    continue;
                }
                if (isset($sequence[$next]) && $m[1] === $sequence[$next]) {
                    $found[] = $line;
                    $next++;
                }
            }

            if (count($found) > count($best)) {
                $best = $found;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, TextLine>  $lines
     * @return array<int, TextLine>
     */
    private function styled(array $lines, float $bodySize, string $bodyColor): array
    {
        $byStyle = [];

        foreach ($lines as $line) {
            $standsOut = $line->bold || $line->color !== $bodyColor;
            if ($line->size < $bodySize * 1.1 || ! $standsOut || ! $this->looksLikeTitle($line->text)) {
                continue;
            }
            $byStyle[$line->style()][] = $line;
        }

        // The lesson style is the one used most often; a style used once is a chapter title or a one-off box.
        uasort($byStyle, fn ($a, $b) => [count($b), $a[0]->size] <=> [count($a), $b[0]->size]);

        return array_values(reset($byStyle) ?: []);
    }

    private function looksLikeTitle(string $text): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $text);
        if (mb_strlen((string) $letters) < 4) {
            return false;
        }

        $normalized = mb_strtolower(trim($text));
        foreach ($this->ignoredTitles as $ignored) {
            if (preg_match('/^'.preg_quote(mb_strtolower($ignored), '/').'\b/u', $normalized)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, TextLine>  $lines
     */
    private function bodyColor(array $lines, float $bodySize): string
    {
        $chars = [];
        foreach ($lines as $line) {
            if ($line->size === $bodySize) {
                $chars[$line->color] = ($chars[$line->color] ?? 0) + mb_strlen($line->text);
            }
        }
        arsort($chars);

        return (string) (array_key_first($chars) ?? '#000000');
    }
}
