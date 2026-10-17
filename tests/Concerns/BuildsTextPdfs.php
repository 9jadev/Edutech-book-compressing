<?php

namespace Tests\Concerns;

trait BuildsTextPdfs
{
    /**
     * Build a valid PDF from lines of real text. Each page is a list of [text, size, bold] lines,
     * written top to bottom in Helvetica / Helvetica-Bold.
     *
     * @param  array<int, array<int, array{0: string, 1: int, 2: bool}>>  $pages
     */
    protected function buildTextPdf(array $pages): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];

        $kids = [];
        foreach (array_values($pages) as $i => $lines) {
            $pageId = 5 + $i * 2;
            $contentId = $pageId + 1;
            $y = 760;
            $stream = '';
            foreach ($lines as [$text, $size, $bold]) {
                $font = $bold ? 'F2' : 'F1';
                $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
                $stream .= "BT /{$font} {$size} Tf 60 {$y} Td ({$escaped}) Tj ET\n";
                $y -= (int) round($size * 1.6);
            }
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $size = max(array_keys($objects)) + 1;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * Body text lines that read like a textbook, so Step 1 sees a good text layer.
     *
     * @return array<int, array{0: string, 1: int, 2: bool}>
     */
    protected function bodyLines(string $topic, int $count = 12): array
    {
        $sentences = [
            "The study of {$topic} begins with careful observation of simple examples.",
            'Students should read each worked example slowly and write down every step.',
            'A good method is to compare two cases and explain how they are different.',
            'Practice questions at the end help you remember the main ideas of the lesson.',
        ];

        return array_map(fn ($i) => [$sentences[$i % count($sentences)], 11, false], range(0, $count - 1));
    }
}
