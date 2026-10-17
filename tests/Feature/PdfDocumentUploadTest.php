<?php

namespace Tests\Feature;

use App\Enums\PdfDocumentStatus;
use App\Jobs\SplitPdfDocumentChapters;
use App\Models\PdfDocument;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PdfDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makePlayer(): Player
    {
        return Player::forceCreate([
            'name' => 'Test Player',
            'username' => 'tester'.uniqid(),
            'email' => uniqid().'@example.com',
            'email_verified_at' => now(),
            'password' => 'password',
            'has_setup_character' => true,
        ]);
    }

    public function test_a_pdf_can_be_uploaded_with_chapters()
    {
        Storage::fake('public');
        Queue::fake();
        $player = $this->makePlayer();

        $this->actingAs($player)->post('/pdfs', [
            'pdf' => UploadedFile::fake()->create('book.pdf', 100, 'application/pdf'),
            'chapters' => [
                ['chapter_name' => 'Intro', 'start_page' => 1, 'end_page' => 10],
                ['chapter_name' => 'Body', 'start_page' => 11, 'end_page' => 40],
            ],
        ])->assertRedirect('/pdfs');

        $document = PdfDocument::with('chapters')->sole();

        $this->assertSame($player->id, $document->player_id);
        $this->assertSame('book.pdf', $document->original_name);
        $this->assertSame(PdfDocumentStatus::Started, $document->status);
        $this->assertCount(2, $document->chapters);
        $this->assertNull($document->chapters->first()->file_path);
        Storage::disk('public')->assertExists($document->file_path);
        Queue::assertPushed(SplitPdfDocumentChapters::class, fn ($job) => $job->document->is($document));
    }

    public function test_repeated_header_text_is_saved_one_per_line()
    {
        Storage::fake('public');
        Queue::fake();

        $this->actingAs($this->makePlayer())->post('/pdfs', [
            'pdf' => UploadedFile::fake()->create('biology.pdf', 100, 'application/pdf'),
            'header_text' => "Created by Bernard Brochez\n\n  Page footer  \n",
        ])->assertRedirect('/pdfs');

        $document = PdfDocument::sole();
        $this->assertSame(['Created by Bernard Brochez', 'Page footer'], $document->header_patterns);
        $this->assertSame(['/^.*Created by Bernard Brochez.*$/mi', '/^.*Page footer.*$/mi'], $document->headerRegexes());
    }

    public function test_uploading_without_chapters_does_not_queue_a_split()
    {
        Storage::fake('public');
        Queue::fake();

        $this->actingAs($this->makePlayer())->post('/pdfs', [
            'pdf' => UploadedFile::fake()->create('book.pdf', 100, 'application/pdf'),
        ])->assertRedirect('/pdfs');

        Queue::assertNothingPushed();
    }

    public function test_non_pdf_files_are_rejected()
    {
        Storage::fake('public');

        $this->actingAs($this->makePlayer())->post('/pdfs', [
            'pdf' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('pdf');

        $this->assertSame(0, PdfDocument::count());
    }

    public function test_chapter_end_page_cannot_be_before_start_page()
    {
        Storage::fake('public');

        $this->actingAs($this->makePlayer())->post('/pdfs', [
            'pdf' => UploadedFile::fake()->create('book.pdf', 100, 'application/pdf'),
            'chapters' => [['chapter_name' => 'Bad', 'start_page' => 10, 'end_page' => 5]],
        ])->assertSessionHasErrors('chapters.0.end_page');
    }

    public function test_the_upload_page_renders()
    {
        $this->actingAs($this->makePlayer())->get('/pdfs')->assertOk();
    }

    public function test_the_library_lists_documents_with_links_only_for_split_chapters()
    {
        $document = PdfDocument::create([
            'original_name' => 'book.pdf',
            'file_path' => 'pdfs/book.pdf',
            'file_size' => 100,
        ]);
        $document->chapters()->createMany([
            ['chapter_name' => 'Split', 'start_page' => 1, 'end_page' => 2, 'file_path' => 'pdfs/chapters/1/chapter-1.pdf'],
            ['chapter_name' => 'Not split', 'start_page' => 3, 'end_page' => 4],
        ]);

        $this->actingAs($this->makePlayer())
            ->get('http://gameboxlive.test/pdfs/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PdfLibrary')
                ->has('documents', 1)
                ->where('documents.0.url', 'http://gameboxlive.test/storage/pdfs/book.pdf')
                ->where('documents.0.chapters.0.url', 'http://gameboxlive.test/storage/pdfs/chapters/1/chapter-1.pdf')
                ->where('documents.0.chapters.1.url', null)
            );
    }
}
