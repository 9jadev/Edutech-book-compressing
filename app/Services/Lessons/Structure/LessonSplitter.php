<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

use App\Enums\LessonsStatus;
use App\Models\ChapterBound;
use App\Services\Lessons\Prepare\PrepareChapterService;
use App\Services\Qpdf;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Step 2 — split one prepared chapter into lessons.
 *
 * Reads the chapter PDF's layout, finds the lesson headings (see LessonHeadingDetector), and for each lesson
 * saves a row with its title, page range and text, plus a PDF of its pages in <chapter folder>/lessons/.
 * Pages that Step 1 had to OCR (garbled text layer) use the OCR text from pages.json.
 */
class LessonSplitter
{
    private const DISK = 'public';

    public function __construct(
        private readonly PrepareChapterService $prepare,
        private readonly Qpdf $qpdf,
    ) {}

    /**
     * Work out the lessons without saving anything.
     *
     * @return array{strategy: string, lessons: array<int, array<string, mixed>>}
     */
    public function plan(ChapterBound $chapter): array
    {
        $disk = Storage::disk(self::DISK);
        $folder = PrepareChapterService::folderFor((string) $chapter->file_path);

        if (! $chapter->file_path || ! $disk->exists($chapter->file_path)) {
            throw new RuntimeException('The chapter PDF does not exist yet. Split the PDF into chapters first.');
        }
        if (! $disk->exists("{$folder}/pages.json")) {
            throw new RuntimeException("Step 1 has not run for this chapter: {$folder}/pages.json is missing.");
        }

        $pages = collect(json_decode($disk->get("{$folder}/pages.json"), true)['pages'] ?? [])->keyBy('pdf_page');
        $config = (array) config('lessons.structure', []);

        $reader = new PdfLayoutReader($this->prepare->makeToolbox((array) config('lessons.prepare')));
        $lines = $this->clean($reader->read($disk->path($chapter->file_path)), $pages->all(), $this->headerRegexes($chapter));

        $detected = (new LessonHeadingDetector(
            (array) ($config['ignored_titles'] ?? []),
            (int) ($config['min_headings'] ?? 2),
        ))->detect($lines);

        $segments = $this->segments($lines, $detected['headings'], $pages->all());
        $lessons = $this->lessons($segments, $chapter, (int) ($config['intro_min_chars'] ?? 200), $pages->all());

        return ['strategy' => $detected['strategy'], 'lessons' => $lessons];
    }

    /**
     * Split the chapter into lessons and save them, replacing any lessons it had before.
     *
     * @return array{strategy: string, lessons: array<int, array<string, mixed>>}
     */
    public function split(ChapterBound $chapter): array
    {
        $chapter->update(['lessons_status' => LessonsStatus::Splitting, 'lessons_error' => null]);

        try {
            $plan = $this->plan($chapter);
            $this->save($chapter, $plan);
        } catch (Throwable $e) {
            $chapter->update(['lessons_status' => LessonsStatus::Failed, 'lessons_error' => $e->getMessage()]);

            throw $e;
        }

        $chapter->update([
            'lessons_status' => LessonsStatus::Done,
            'lessons_error' => null,
            'lessons_strategy' => $plan['strategy'],
        ]);

        return $plan;
    }

    /**
     * @param  array{strategy: string, lessons: array<int, array<string, mixed>>}  $plan
     */
    private function save(ChapterBound $chapter, array $plan): void
    {
        $disk = Storage::disk(self::DISK);
        $folder = PrepareChapterService::folderFor((string) $chapter->file_path).'/lessons';
        $chapterPdf = $disk->path((string) $chapter->file_path);

        $disk->deleteDirectory($folder);
        $saved = [];

        foreach ($plan['lessons'] as $lesson) {
            $tempPath = tempnam(sys_get_temp_dir(), 'lesson_');

            try {
                $this->qpdf->extractPages($chapterPdf, $lesson['start_page'], $lesson['end_page'], $tempPath);
                $name = sprintf('lesson-%02d.pdf', $lesson['position']);

                if ($disk->putFileAs($folder, new File($tempPath), $name) === false) {
                    throw new RuntimeException("Could not save the PDF for lesson \"{$lesson['title']}\".");
                }
            } finally {
                @unlink($tempPath);
            }

            $saved[] = $lesson + ['file_path' => "{$folder}/{$name}"];
        }

        DB::transaction(function () use ($chapter, $saved) {
            $chapter->lessons()->delete();
            $chapter->lessons()->createMany($saved);
        });
    }

    /**
     * Remove repeated header/footer lines and printed page numbers.
     *
     * @param  array<int, TextLine>  $lines
     * @param  array<int, array<string, mixed>>  $pages  pages.json pages keyed by pdf_page
     * @param  string[]  $headerRegexes
     * @return array<int, TextLine>
     */
    private function clean(array $lines, array $pages, array $headerRegexes): array
    {
        $kept = [];

        foreach ($lines as $line) {
            $printed = $pages[$line->page]['printed_page'] ?? null;
            if ($printed !== null && trim($line->text) === (string) $printed) {
                continue;
            }

            $text = $line->text;
            foreach ($headerRegexes as $re) {
                $text = (string) preg_replace($re, '', $text);
            }
            if (trim($text) !== '') {
                $kept[] = $text === $line->text ? $line : $line->withText(trim($text));
            }
        }

        return $kept;
    }

    /**
     * Walk the lines in reading order and cut them into segments at each heading.
     * Segment 0 is everything before the first heading.
     *
     * @param  array<int, TextLine>  $lines
     * @param  array<int, TextLine>  $headings
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array{heading: ?TextLine, chunks: array<int, array{page: int, text: string}>, pages: array<int, int>, warnings: array<int, string>}>
     */
    private function segments(array $lines, array $headings, array $pages): array
    {
        $segments = [['heading' => null, 'chunks' => [], 'pages' => [], 'warnings' => []]];
        $isHeading = new \SplObjectStorage;
        foreach ($headings as $heading) {
            $isHeading[$heading] = true;
        }

        $firstHeadingOnPage = [];
        $index = 0;
        foreach ($lines as $line) {
            if (isset($isHeading[$line])) {
                $index++;
                $firstHeadingOnPage[$line->page] ??= $index;
            }
        }

        $ocrDone = [];
        $current = 0;

        foreach ($lines as $line) {
            if (isset($isHeading[$line])) {
                $current++;
                $segments[$current] = ['heading' => $line, 'chunks' => [], 'pages' => [$line->page], 'warnings' => []];
            }

            $segments[$current]['pages'][] = $line->page;
            $page = $pages[$line->page] ?? null;

            // Garbled text layer: use the whole page's OCR text once, in the lesson that owns the page.
            if (($page['text_source'] ?? 'pdf_text') !== 'pdf_text') {
                if (! isset($ocrDone[$line->page])) {
                    $ocrDone[$line->page] = true;
                    $owner = $firstHeadingOnPage[$line->page] ?? $current;
                    $segments[$owner]['chunks'][] = ['page' => $line->page, 'text' => trim((string) ($page['text'] ?? ''))];
                    $segments[$owner]['pages'][] = $line->page;

                    if (isset($firstHeadingOnPage[$line->page])) {
                        $segments[$owner]['warnings'][] = "Page {$line->page}: the lesson starts on a page that was read with OCR, so the title and the text before it may be off. Check this page.";
                    }
                }

                continue;
            }

            if (! isset($isHeading[$line])) {
                $segments[$current]['chunks'][] = ['page' => $line->page, 'text' => $line->text];
            }
        }

        return $segments;
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function lessons(array $segments, ChapterBound $chapter, int $introMinChars, array $pages): array
    {
        $intro = array_shift($segments);
        $introChars = mb_strlen((string) preg_replace('/\W+/u', '', implode('', array_column($intro['chunks'], 'text'))));

        if ($segments === []) {
            $intro['title'] = $chapter->chapter_name;
            $segments = [$intro];
        } elseif ($introChars >= $introMinChars) {
            $intro['title'] = 'Introduction';
            array_unshift($segments, $intro);
        } else {
            // A few lines before the first heading: keep them with the first lesson.
            $segments[0]['chunks'] = array_merge($intro['chunks'], $segments[0]['chunks']);
            $segments[0]['pages'] = array_merge($intro['pages'], $segments[0]['pages']);
        }

        $step1Warnings = $this->step1WarningsByPage($chapter);
        $lessons = [];

        foreach (array_values($segments) as $i => $segment) {
            $pageNumbers = array_values(array_unique($segment['pages'] ?: [1]));
            $start = min($pageNumbers);
            $end = max($pageNumbers);

            $warnings = $segment['warnings'];
            for ($p = $start; $p <= $end; $p++) {
                array_push($warnings, ...($step1Warnings[$p] ?? []));
            }

            $lessons[] = [
                'position' => $i + 1,
                'title' => mb_substr($segment['title'] ?? $this->title($segment['heading']), 0, 255),
                'start_page' => $start,
                'end_page' => $end,
                'printed_start_page' => $pages[$start]['printed_page'] ?? null,
                'printed_end_page' => $pages[$end]['printed_page'] ?? null,
                'content' => $this->content($segment['chunks']),
                'warnings' => array_values(array_unique($warnings)) ?: null,
            ];
        }

        return $lessons;
    }

    private function title(?TextLine $heading): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $heading?->text ?? 'Lesson'), " \t.");
    }

    /**
     * @param  array<int, array{page: int, text: string}>  $chunks
     */
    private function content(array $chunks): string
    {
        $text = '';
        $lastPage = null;

        foreach ($chunks as $chunk) {
            if ($lastPage !== null && $chunk['page'] !== $lastPage) {
                $text .= "\n";
            }
            $text .= $chunk['text']."\n";
            $lastPage = $chunk['page'];
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function step1WarningsByPage(ChapterBound $chapter): array
    {
        $disk = Storage::disk(self::DISK);
        $json = json_decode((string) $disk->get(PrepareChapterService::folderFor((string) $chapter->file_path).'/pages.json'), true);

        $byPage = [];
        foreach ((array) ($json['warnings'] ?? []) as $warning) {
            if (preg_match('/^Page (\d+):/', (string) $warning, $m)) {
                $byPage[(int) $m[1]][] = (string) $warning;
            }
        }

        return $byPage;
    }

    /**
     * @return string[]
     */
    private function headerRegexes(ChapterBound $chapter): array
    {
        return array_values(array_unique(array_merge(
            (array) config('lessons.prepare.header_patterns', []),
            $chapter->pdfDocument?->headerRegexes() ?? [],
        )));
    }
}
