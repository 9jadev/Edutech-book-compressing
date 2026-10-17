<?php

namespace Tests\Feature;

use App\Enums\ChapterStatus;
use App\Enums\LessonsStatus;
use App\Enums\PdfDocumentStatus;
use App\Jobs\Lessons\PrepareChapterJob;
use App\Jobs\SplitPdfDocumentChapters;
use App\Models\PdfDocument;
use App\Services\PdfChapterSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SplitPdfChaptersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (Process::run([config('services.qpdf.binary'), '--version'])->failed()) {
            $this->markTestSkipped('qpdf is not installed.');
        }

        Storage::fake('public');

        // Step 1 / Step 2 have their own tests; here we only check that they get queued.
        Queue::fake([PrepareChapterJob::class]);
    }

    public function test_each_chapter_is_extracted_into_its_own_pdf_and_the_document_is_marked_done()
    {
        $document = $this->makeDocument(pages: 10, chapters: [
            ['chapter_name' => 'Intro', 'start_page' => 1, 'end_page' => 3],
            ['chapter_name' => 'Middle', 'start_page' => 4, 'end_page' => 8],
            ['chapter_name' => 'End', 'start_page' => 9, 'end_page' => 10],
        ]);

        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertSuccessful();

        $document->refresh();
        $this->assertSame(PdfDocumentStatus::Done, $document->status);
        Queue::assertPushed(PrepareChapterJob::class, 3);
        $first = $document->chapters->first();
        Queue::assertPushed(PrepareChapterJob::class, fn ($job) => $job->disk === 'public'
            && $job->pdfPath === $first->file_path
            && $job->meta['chapter_bound_id'] === $first->id);
        $this->assertSame(LessonsStatus::Preparing, $first->lessons_status);

        $expectedPages = ['Intro' => [1, 2, 3], 'Middle' => [4, 5, 6, 7, 8], 'End' => [9, 10]];

        foreach ($document->chapters as $chapter) {
            $this->assertSame("pdfs/chapters/{$document->id}/chapter-{$chapter->id}.pdf", $chapter->file_path);
            Storage::disk('public')->assertExists($chapter->file_path);
            $this->assertSame($expectedPages[$chapter->chapter_name], $this->pageMarkers($chapter->file_path));
        }
    }

    public function test_the_document_is_running_while_chapters_are_being_extracted()
    {
        $document = $this->makeDocument(pages: 4, chapters: [
            ['chapter_name' => 'A', 'start_page' => 1, 'end_page' => 2],
            ['chapter_name' => 'B', 'start_page' => 3, 'end_page' => 4],
        ]);

        $statuses = [];
        app(PdfChapterSplitter::class)->split($document, function () use ($document, &$statuses) {
            $statuses[] = $document->fresh()->status;
        });

        $this->assertSame([PdfDocumentStatus::Running, PdfDocumentStatus::Running], $statuses);
        $this->assertSame(PdfDocumentStatus::Done, $document->fresh()->status);
    }

    public function test_a_failing_chapter_records_its_error_and_the_other_chapters_still_split()
    {
        $document = $this->makeDocument(pages: 5, chapters: [
            ['chapter_name' => 'Fine', 'start_page' => 1, 'end_page' => 3],
            ['chapter_name' => 'Too far', 'start_page' => 4, 'end_page' => 9],
            ['chapter_name' => 'Also fine', 'start_page' => 4, 'end_page' => 5],
        ]);

        $this->artisan('pdfs:split-chapters', ['document' => $document->id])
            ->expectsOutputToContain('out of range')
            ->assertFailed();

        $this->assertSame(PdfDocumentStatus::Done, $document->fresh()->status);

        $chapters = $document->chapters()->get()->keyBy('chapter_name');

        $this->assertSame(ChapterStatus::Done, $chapters['Fine']->status);
        $this->assertNull($chapters['Fine']->runtime_error);
        Storage::disk('public')->assertExists($chapters['Fine']->file_path);

        $this->assertSame(ChapterStatus::Failed, $chapters['Too far']->status);
        $this->assertSame('Pages 4-9 are out of range: the PDF has 5 pages.', $chapters['Too far']->runtime_error);
        $this->assertNull($chapters['Too far']->file_path);

        $this->assertSame(ChapterStatus::Done, $chapters['Also fine']->status);
        $this->assertSame([4, 5], $this->pageMarkers($chapters['Also fine']->file_path));
    }

    public function test_running_again_retries_only_the_chapters_that_are_not_done()
    {
        $document = $this->makeDocument(pages: 5, chapters: [
            ['chapter_name' => 'Fine', 'start_page' => 1, 'end_page' => 3],
            ['chapter_name' => 'Too far', 'start_page' => 4, 'end_page' => 9],
        ]);
        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertFailed();

        $fine = $document->chapters()->where('chapter_name', 'Fine')->sole();
        $fine->update(['file_path' => 'pdfs/chapters/untouched.pdf']);
        $document->chapters()->where('chapter_name', 'Too far')->update(['end_page' => 5]);

        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertSuccessful();

        $this->assertSame('pdfs/chapters/untouched.pdf', $fine->fresh()->file_path);
        $fixed = $document->chapters()->where('chapter_name', 'Too far')->sole();
        $this->assertSame(ChapterStatus::Done, $fixed->status);
        $this->assertNull($fixed->runtime_error);
    }

    public function test_a_missing_original_file_marks_every_chapter_failed()
    {
        $document = $this->makeDocument(pages: 2, chapters: [
            ['chapter_name' => 'A', 'start_page' => 1, 'end_page' => 1],
            ['chapter_name' => 'B', 'start_page' => 2, 'end_page' => 2],
        ]);
        Storage::disk('public')->delete($document->file_path);

        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertFailed();

        $this->assertSame(PdfDocumentStatus::Done, $document->fresh()->status);
        foreach ($document->chapters as $chapter) {
            $this->assertSame(ChapterStatus::Failed, $chapter->status);
            $this->assertStringContainsString('Original file is missing', $chapter->runtime_error);
        }
    }

    public function test_without_an_id_every_started_document_is_split()
    {
        $first = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'A', 'start_page' => 1, 'end_page' => 2]]);
        $second = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'B', 'start_page' => 2, 'end_page' => 2]]);
        $alreadyDone = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'C', 'start_page' => 1, 'end_page' => 1]]);
        $alreadyDone->update(['status' => PdfDocumentStatus::Done]);

        $this->artisan('pdfs:split-chapters')->assertSuccessful();

        $this->assertSame(PdfDocumentStatus::Done, $first->fresh()->status);
        $this->assertSame(PdfDocumentStatus::Done, $second->fresh()->status);
        $this->assertNull($alreadyDone->chapters()->first()->file_path);
    }

    public function test_a_running_document_is_not_split_again_without_force()
    {
        $document = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'A', 'start_page' => 1, 'end_page' => 2]]);
        $document->update(['status' => PdfDocumentStatus::Running]);

        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertFailed();
        $this->assertNull($document->chapters()->first()->file_path);

        $this->artisan('pdfs:split-chapters', ['document' => $document->id, '--force' => true])->assertSuccessful();
        $this->assertSame(PdfDocumentStatus::Done, $document->fresh()->status);
    }

    public function test_with_the_queue_option_the_command_queues_a_job_per_pdf()
    {
        Queue::fake();
        $first = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'A', 'start_page' => 1, 'end_page' => 2]]);
        $second = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'B', 'start_page' => 1, 'end_page' => 2]]);

        $this->artisan('pdfs:split-chapters', ['--queue' => true])->assertSuccessful();

        Queue::assertPushed(SplitPdfDocumentChapters::class, 2);
        Queue::assertPushed(SplitPdfDocumentChapters::class, fn ($job) => $job->document->is($first));
        Queue::assertPushed(SplitPdfDocumentChapters::class, fn ($job) => $job->document->is($second));
        $this->assertSame(PdfDocumentStatus::Started, $first->fresh()->status);
    }

    public function test_the_job_splits_the_pdf_and_records_failed_chapters()
    {
        $document = $this->makeDocument(pages: 5, chapters: [
            ['chapter_name' => 'Fine', 'start_page' => 1, 'end_page' => 3],
            ['chapter_name' => 'Too far', 'start_page' => 4, 'end_page' => 9],
        ]);

        SplitPdfDocumentChapters::dispatchSync($document);

        $this->assertSame(PdfDocumentStatus::Done, $document->fresh()->status);
        $chapters = $document->chapters()->get()->keyBy('chapter_name');
        $this->assertSame(ChapterStatus::Done, $chapters['Fine']->status);
        $this->assertSame([1, 2, 3], $this->pageMarkers($chapters['Fine']->file_path));
        $this->assertSame(ChapterStatus::Failed, $chapters['Too far']->status);
        $this->assertStringContainsString('out of range', $chapters['Too far']->runtime_error);
    }

    public function test_when_the_job_crashes_unfinished_chapters_get_the_error_and_the_pdf_is_not_left_running()
    {
        $document = $this->makeDocument(pages: 4, chapters: [
            ['chapter_name' => 'A', 'start_page' => 1, 'end_page' => 2],
            ['chapter_name' => 'B', 'start_page' => 3, 'end_page' => 4],
        ]);
        $document->chapters()->where('chapter_name', 'A')->update(['status' => ChapterStatus::Done]);
        $document->update(['status' => PdfDocumentStatus::Running]);

        (new SplitPdfDocumentChapters($document))->failed(new \RuntimeException('Worker timed out'));

        $this->assertSame(PdfDocumentStatus::Done, $document->fresh()->status);
        $chapters = $document->chapters()->get()->keyBy('chapter_name');
        $this->assertSame(ChapterStatus::Done, $chapters['A']->status);
        $this->assertNull($chapters['A']->runtime_error);
        $this->assertSame(ChapterStatus::Failed, $chapters['B']->status);
        $this->assertSame('Split job failed: Worker timed out', $chapters['B']->runtime_error);
    }

    public function test_reset_deletes_chapter_files_and_puts_one_pdf_back_to_the_start()
    {
        $document = $this->makeDocument(pages: 5, chapters: [
            ['chapter_name' => 'Fine', 'start_page' => 1, 'end_page' => 3],
            ['chapter_name' => 'Too far', 'start_page' => 4, 'end_page' => 9],
        ]);
        $other = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'Other', 'start_page' => 1, 'end_page' => 2]]);
        $this->artisan('pdfs:split-chapters')->assertFailed();
        $splitFile = $document->chapters()->where('chapter_name', 'Fine')->sole()->file_path;
        $otherFile = $other->chapters()->sole()->file_path;

        $this->artisan('pdfs:reset-chapters', ['document' => $document->id])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertSuccessful();

        $this->assertSame(PdfDocumentStatus::Started, $document->fresh()->status);
        foreach ($document->chapters as $chapter) {
            $this->assertSame(ChapterStatus::Pending, $chapter->status);
            $this->assertNull($chapter->file_path);
            $this->assertNull($chapter->runtime_error);
        }
        Storage::disk('public')->assertMissing($splitFile);
        Storage::disk('public')->assertExists($document->file_path);

        $this->assertSame(PdfDocumentStatus::Done, $other->fresh()->status);
        Storage::disk('public')->assertExists($otherFile);

        $this->artisan('pdfs:split-chapters', ['document' => $document->id])->assertFailed();
        $this->assertSame(ChapterStatus::Done, $document->chapters()->where('chapter_name', 'Fine')->sole()->status);
    }

    public function test_reset_without_an_id_resets_every_pdf()
    {
        $first = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'A', 'start_page' => 1, 'end_page' => 2]]);
        $second = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'B', 'start_page' => 1, 'end_page' => 2]]);
        $this->artisan('pdfs:split-chapters')->assertSuccessful();

        $this->artisan('pdfs:reset-chapters', ['--force' => true])->assertSuccessful();

        foreach ([$first, $second] as $document) {
            $this->assertSame(PdfDocumentStatus::Started, $document->fresh()->status);
            $this->assertNull($document->chapters()->sole()->file_path);
            $this->assertSame(ChapterStatus::Pending, $document->chapters()->sole()->status);
        }
        $this->assertSame([], Storage::disk('public')->allFiles('pdfs/chapters'));
    }

    public function test_declining_the_reset_changes_nothing()
    {
        $document = $this->makeDocument(pages: 2, chapters: [['chapter_name' => 'A', 'start_page' => 1, 'end_page' => 2]]);
        $this->artisan('pdfs:split-chapters')->assertSuccessful();
        $file = $document->chapters()->sole()->file_path;

        $this->artisan('pdfs:reset-chapters')
            ->expectsConfirmation('Continue?', 'no')
            ->expectsOutput('Nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(PdfDocumentStatus::Done, $document->fresh()->status);
        $this->assertSame($file, $document->chapters()->sole()->file_path);
        Storage::disk('public')->assertExists($file);
    }

    /**
     * @param  array<int, array{chapter_name: string, start_page: int, end_page: int}>  $chapters
     */
    private function makeDocument(int $pages, array $chapters): PdfDocument
    {
        $path = 'pdfs/'.uniqid().'.pdf';
        Storage::disk('public')->put($path, $this->buildPdf($pages));

        $document = PdfDocument::create([
            'original_name' => 'book.pdf',
            'file_path' => $path,
            'file_size' => Storage::disk('public')->size($path),
        ]);
        $document->chapters()->createMany($chapters);

        return $document;
    }

    /**
     * Read back the "PAGE-n" marker written on each page, in order.
     *
     * @return array<int, int>
     */
    private function pageMarkers(string $path): array
    {
        $qdf = Process::run([
            config('services.qpdf.binary'), '--qdf', '--object-streams=disable',
            Storage::disk('public')->path($path), '-',
        ])->output();

        preg_match_all('/\(PAGE-(\d+)\)/', $qdf, $matches);

        return array_map('intval', $matches[1]);
    }

    /**
     * Build a minimal valid PDF where page n shows the text "PAGE-n".
     */
    private function buildPdf(int $pages): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $kids = [];
        for ($n = 1; $n <= $pages; $n++) {
            $pageId = 2 + $n * 2;
            $contentId = $pageId + 1;
            $stream = "BT /F1 24 Tf 72 720 Td (PAGE-{$n}) Tj ET";
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';
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
}
