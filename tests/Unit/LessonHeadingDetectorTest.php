<?php

namespace Tests\Unit;

use App\Services\Lessons\Structure\LessonHeadingDetector;
use App\Services\Lessons\Structure\TextLine;
use PHPUnit\Framework\TestCase;

class LessonHeadingDetectorTest extends TestCase
{
    private function line(int $page, float $top, string $text, float $size = 17, bool $bold = false, string $color = '#231f20', int $column = 0): TextLine
    {
        return new TextLine($page, $column, $top, 90, $size, $bold, $color, $text);
    }

    /**
     * @return array<int, TextLine>
     */
    private function body(int $page, float $top): array
    {
        return array_map(fn ($i) => $this->line($page, $top + $i * 20, 'Body text that explains the topic in plenty of ordinary words.'), range(0, 5));
    }

    public function test_it_uses_the_repeated_heading_style_and_skips_box_titles_and_the_chapter_title()
    {
        $lines = [
            $this->line(1, 50, 'Number base system', 26, true),
            $this->line(1, 100, 'Objectives', 21, true, '#ffffff'),
            ...$this->body(1, 120),
            $this->line(1, 300, 'Conversion of numbers in base 10 to other bases', 21, true, '#ed1b24', 1),
            ...$this->body(2, 50),
            $this->line(2, 400, 'Equations involving number base system', 21, true, '#ed1b24'),
            ...$this->body(3, 50),
            $this->line(3, 300, 'Puzzle', 21, true, '#ed1b24'),
        ];

        $result = (new LessonHeadingDetector(['objectives', 'puzzle']))->detect($lines);

        $this->assertSame('styled', $result['strategy']);
        $this->assertSame(
            ['Conversion of numbers in base 10 to other bases', 'Equations involving number base system'],
            array_map(fn ($h) => $h->text, $result['headings']),
        );
    }

    public function test_it_prefers_bold_sections_labelled_in_sequence()
    {
        $lines = [
            $this->line(1, 50, '2. DIFFUSION AND OSMOSIS.', 32, true, '#7f7f00'),
            $this->line(1, 100, 'A. Movement of molecules- Diffusion', 17, true),
            ...$this->body(1, 120),
            $this->line(2, 50, 'C. Not in sequence yet', 17, true),
            $this->line(2, 100, 'B. OSMOSIS.', 17, true),
            ...$this->body(2, 120),
            $this->line(3, 60, 'C. Movement of water in a plant.', 17, true),
            $this->line(3, 90, 'D. not bold so not a heading', 17, false),
            ...$this->body(3, 120),
        ];

        $result = (new LessonHeadingDetector)->detect($lines);

        $this->assertSame('labelled', $result['strategy']);
        $this->assertSame(
            ['A. Movement of molecules- Diffusion', 'B. OSMOSIS.', 'C. Movement of water in a plant.'],
            array_map(fn ($h) => $h->text, $result['headings']),
        );
    }

    public function test_a_chapter_without_two_headings_is_one_lesson()
    {
        $lines = [$this->line(1, 50, 'Preparatory chapter', 26, true), ...$this->body(1, 100), ...$this->body(2, 50)];

        $result = (new LessonHeadingDetector)->detect($lines);

        $this->assertSame('whole_chapter', $result['strategy']);
        $this->assertSame([], $result['headings']);
    }
}
