<?php

namespace Tests\Feature;

use App\Enums\LessonsStatus;
use App\Events\Lessons\ChapterPreparationFailed;
use App\Models\Lesson;
use App\Models\PdfDocument;
use App\Services\Lessons\Prepare\PrepareChapterService;
use App\Services\Qpdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTextPdfs;
use Tests\TestCase;
use Throwable;

class LessonPipelineTest extends TestCase
{
    use BuildsTextPdfs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $tools = app(PrepareChapterService::class)->makeToolbox((array) config('lessons.prepare'));
            $tools->assertInstalled();
            $tools->path('pdftohtml');
        } catch (Throwable $e) {
            $this->markTestSkipped($e->getMessage());
        }

        Storage::fake('public');
    }

    public function test_splitting_a_pdf_runs_step_1_and_step_2_and_saves_the_lessons()
    {
        $header = ['Created by Test Author', 9, false];
        $document = $this->makeDocument([
            [$header, ['Number base system', 22, true], ['Counting in tens', 15, true], ...$this->bodyLines('counting')],
            [$header, ...$this->bodyLines('place value', 6), ['Changing between bases', 15, true], ...$this->bodyLines('bases', 6)],
            [$header, ...$this->bodyLines('remainders')],
            [$header, ['Adding numbers in base two', 15, true], ...$this->bodyLines('binary addition')],
        ], headers: ['Created by Test Author']);

        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertSuccessful();

        $chapter = $document->chapters()->sole();
        $this->assertSame(LessonsStatus::Done, $chapter->lessons_status, (string) $chapter->lessons_error);
        $this->assertSame('styled', $chapter->lessons_strategy);

        $lessons = $chapter->lessons()->get();
        $this->assertSame(['Counting in tens', 'Changing between bases', 'Adding numbers in base two'], $lessons->pluck('title')->all());
        $this->assertSame([[1, 2], [2, 3], [4, 4]], $lessons->map(fn ($l) => [$l->start_page, $l->end_page])->all());

        // The chapter title before the first heading is too short for its own lesson, so lesson 1 keeps it.
        $this->assertStringContainsString('Number base system', $lessons[0]->content);
        $this->assertStringContainsString('The study of place value begins', $lessons[0]->content);
        $this->assertStringNotContainsString('The study of bases begins', $lessons[0]->content);
        $this->assertStringContainsString('The study of remainders begins', $lessons[1]->content);
        $this->assertStringNotContainsString('Created by Test Author', $lessons->pluck('content')->implode("\n"));

        $folder = PrepareChapterService::folderFor($chapter->file_path);
        Storage::disk('public')->assertExists("{$folder}/pages.json");
        foreach ($lessons as $lesson) {
            $this->assertSame(sprintf('%s/lessons/lesson-%02d.pdf', $folder, $lesson->position), $lesson->file_path);
            $this->assertSame(
                $lesson->end_page - $lesson->start_page + 1,
                app(Qpdf::class)->pageCount(Storage::disk('public')->path($lesson->file_path)),
            );
        }
    }

    public function test_the_command_splits_bold_lettered_sections_and_can_be_re_run()
    {
        $document = $this->makeDocument([
            [['2. DIFFUSION AND OSMOSIS.', 20, true], ['A. Movement of molecules', 11, true], ...$this->bodyLines('diffusion')],
            [...$this->bodyLines('gradients', 4), ['B. Osmosis.', 11, true], ...$this->bodyLines('osmosis', 8)],
        ]);
        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertSuccessful();
        $chapter = $document->chapters()->sole();
        $this->assertSame(['A. Movement of molecules', 'B. Osmosis'], $chapter->lessons()->pluck('title')->all());
        $firstIds = $chapter->lessons()->pluck('id')->all();

        $this->artisan('pdfs:split-lessons', ['chapter' => $chapter->id])
            ->expectsOutputToContain('Headings found by: labelled')
            ->assertSuccessful();

        $this->assertSame(2, $chapter->lessons()->count());
        $this->assertNotSame($firstIds, $chapter->lessons()->pluck('id')->all());
        $this->assertSame(LessonsStatus::Done, $chapter->fresh()->lessons_status);
    }

    public function test_a_dry_run_shows_the_lessons_without_saving_them()
    {
        $document = $this->makeDocument([
            [['First topic heading', 15, true], ...$this->bodyLines('one')],
            [['Second topic heading', 15, true], ...$this->bodyLines('two')],
        ]);
        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertSuccessful();
        $chapter = $document->chapters()->sole();
        $chapter->lessons()->delete();

        $this->artisan('pdfs:split-lessons', ['chapter' => $chapter->id, '--dry-run' => true])
            ->expectsOutputToContain('Second topic heading')
            ->assertSuccessful();

        $this->assertSame(0, $chapter->lessons()->count());
    }

    public function test_a_chapter_without_headings_becomes_one_lesson_named_after_the_chapter()
    {
        $document = $this->makeDocument([[...$this->bodyLines('review')], [...$this->bodyLines('practice')]]);

        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertSuccessful();

        $chapter = $document->chapters()->sole();
        $this->assertSame('whole_chapter', $chapter->lessons_strategy);
        $lesson = $chapter->lessons()->sole();
        $this->assertSame(['Preparatory chapter', 1, 2], [$lesson->title, $lesson->start_page, $lesson->end_page]);
    }

    public function test_a_step_1_failure_is_saved_on_the_chapter()
    {
        $document = $this->makeDocument([[...$this->bodyLines('x')]]);
        $chapter = $document->chapters()->sole();

        ChapterPreparationFailed::dispatch('public', 'pdfs/chapters/x.pdf', 'tesseract timed out after 180s', ['chapter_bound_id' => $chapter->id]);

        $chapter->refresh();
        $this->assertSame(LessonsStatus::Failed, $chapter->lessons_status);
        $this->assertSame('Step 1 (prepare) failed: tesseract timed out after 180s', $chapter->lessons_error);
    }

    public function test_reset_removes_lessons_and_step_1_output()
    {
        $document = $this->makeDocument([
            [['First topic heading', 15, true], ...$this->bodyLines('one')],
            [['Second topic heading', 15, true], ...$this->bodyLines('two')],
        ]);
        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertSuccessful();
        $chapter = $document->chapters()->sole();
        $folder = PrepareChapterService::folderFor($chapter->file_path);
        $this->assertSame(2, Lesson::count());

        $this->artisan('pdfs:reset-chapters', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Lesson::count());
        $chapter->refresh();
        $this->assertSame(LessonsStatus::Pending, $chapter->lessons_status);
        $this->assertNull($chapter->lessons_strategy);
        Storage::disk('public')->assertMissing("{$folder}/pages.json");
    }

    /**
     * @param  array<int, array<int, array{0: string, 1: int, 2: bool}>>  $pages
     * @param  string[]  $headers
     */
    private function makeDocument(array $pages, array $headers = []): PdfDocument
    {
        $path = 'pdfs/'.uniqid().'.pdf';
        Storage::disk('public')->put($path, $this->buildTextPdf($pages));

        $document = PdfDocument::create([
            'original_name' => 'book.pdf',
            'file_path' => $path,
            'file_size' => Storage::disk('public')->size($path),
            'header_patterns' => $headers ?: null,
        ]);
        $document->chapters()->create(['chapter_name' => 'Preparatory chapter', 'start_page' => 1, 'end_page' => count($pages)]);

        return $document;
    }
}
