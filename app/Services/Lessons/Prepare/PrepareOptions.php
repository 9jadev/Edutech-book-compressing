<?php

declare(strict_types=1);

namespace App\Services\Lessons\Prepare;

use InvalidArgumentException;

/**
 * Settings for one Prepare run. Build it from config with PrepareOptions::fromConfig().
 */
final class PrepareOptions
{
    public const MODES = ['auto', 'pdf_text', 'ocr'];

    /**
     * @param ?int     $firstPrintedPage Printed page number of the chapter's first PDF page.
     *                                   null = detect it from the page numbers printed on the pages.
     * @param string   $mode             auto | pdf_text | ocr
     * @param string[] $headerPatterns   Regexes for running headers/footers to strip (per book).
     */
    public function __construct(
        public readonly ?int $firstPrintedPage = null,
        public readonly string $mode = 'auto',
        public readonly array $headerPatterns = [],
        public readonly string $ocrLang = 'eng',
        public readonly int $dpi = 150,
        public readonly bool $ocrFigurePages = true,
        public readonly bool $makeAiImages = true,
        public readonly int $aiImageWidth = 1000,
        public readonly int $toolTimeout = 180,
        public readonly int $minChars = 40,
        public readonly float $goodAgreement = 0.85,
        public readonly float $maxGarbage = 0.05,
        public readonly float $minWordlike = 0.70,
        public readonly float $lowOcrConfidence = 80.0,
        public readonly int $probePages = 3,
    ) {
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown mode '$mode'. Use one of: " . implode(', ', self::MODES));
        }
        foreach ($headerPatterns as $re) {
            if (@preg_match($re, '') === false) {
                throw new InvalidArgumentException("Invalid header pattern: $re");
            }
        }
    }

    /**
     * @param array<string,mixed> $config    config('lessons.prepare')
     * @param array<string,mixed> $overrides per-run values (null values are ignored)
     */
    public static function fromConfig(array $config, array $overrides = []): self
    {
        $o = array_filter($overrides, fn ($v) => $v !== null && $v !== []);
        $t = $config['thresholds'] ?? [];
        return new self(
            firstPrintedPage: isset($o['first_printed_page']) ? (int) $o['first_printed_page'] : null,
            mode: (string) ($o['mode'] ?? $config['mode'] ?? 'auto'),
            headerPatterns: array_values($o['header_patterns'] ?? $config['header_patterns'] ?? []),
            ocrLang: (string) ($o['ocr_lang'] ?? $config['ocr_lang'] ?? 'eng'),
            dpi: (int) ($config['dpi'] ?? 150),
            ocrFigurePages: (bool) ($config['ocr_figure_pages'] ?? true),
            makeAiImages: (bool) ($config['ai_images'] ?? true),
            aiImageWidth: (int) ($config['ai_image_width'] ?? 1000),
            toolTimeout: (int) ($config['tool_timeout'] ?? 180),
            minChars: (int) ($t['min_chars'] ?? 40),
            goodAgreement: (float) ($t['good_agreement'] ?? 0.85),
            maxGarbage: (float) ($t['max_garbage'] ?? 0.05),
            minWordlike: (float) ($t['min_wordlike'] ?? 0.70),
            lowOcrConfidence: (float) ($t['low_ocr_confidence'] ?? 80),
            probePages: (int) ($t['probe_pages'] ?? 3),
        );
    }
}
