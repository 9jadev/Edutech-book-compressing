<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePdfDocumentRequest;
use App\Jobs\SplitPdfDocumentChapters;
use App\Models\ChapterBound;
use App\Models\Lesson;
use App\Models\PdfDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PdfDocumentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('PdfUploads', [
            'documents' => $this->documents(),
        ]);
    }

    public function library(): Response
    {
        return Inertia::render('PdfLibrary', [
            'documents' => $this->documents(),
        ]);
    }

    public function store(StorePdfDocumentRequest $request): RedirectResponse
    {
        $file = $request->file('pdf');
        $path = $file->store('pdfs', 'public');

        $document = DB::transaction(function () use ($request, $file, $path) {
            $document = PdfDocument::create([
                'player_id' => $request->user()?->id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'header_patterns' => $this->headerLines($request->validated('header_text')),
            ]);

            $document->chapters()->createMany($request->validated('chapters', []));

            return $document;
        });

        if ($document->chapters()->exists()) {
            SplitPdfDocumentChapters::dispatch($document);
        }

        return redirect()->route('pdfs.index')->with('success', 'PDF uploaded successfully.');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function documents(): Collection
    {
        return PdfDocument::with([
            'chapters.lessons:id,chapter_bound_id,position,title,start_page,end_page,printed_start_page,printed_end_page,file_path,warnings',
            'uploader:id,name',
        ])
            ->latest()
            ->get()
            ->map(fn (PdfDocument $doc) => [
                'id' => $doc->id,
                'original_name' => $doc->original_name,
                'file_path' => $doc->file_path,
                'url' => $doc->publicUrl(),
                'file_size' => $doc->file_size,
                'status' => $doc->status->value,
                'header_patterns' => $doc->header_patterns ?? [],
                'uploaded_by' => $doc->uploader?->name,
                'created_at' => $doc->created_at->toDateTimeString(),
                'chapters' => $doc->chapters->map(fn (ChapterBound $chapter) => [
                    'id' => $chapter->id,
                    'chapter_name' => $chapter->chapter_name,
                    'start_page' => $chapter->start_page,
                    'end_page' => $chapter->end_page,
                    'file_path' => $chapter->file_path,
                    'url' => $chapter->publicUrl(),
                    'status' => $chapter->status->value,
                    'runtime_error' => $chapter->runtime_error,
                    'lessons_status' => $chapter->lessons_status->value,
                    'lessons_error' => $chapter->lessons_error,
                    'lessons' => $chapter->lessons->map(fn (Lesson $lesson) => [
                        'id' => $lesson->id,
                        'position' => $lesson->position,
                        'title' => $lesson->title,
                        'start_page' => $lesson->start_page,
                        'end_page' => $lesson->end_page,
                        'printed_start_page' => $lesson->printed_start_page,
                        'printed_end_page' => $lesson->printed_end_page,
                        'url' => $lesson->publicUrl(),
                        'warnings' => $lesson->warnings ?? [],
                    ]),
                ]),
            ]);
    }

    /**
     * One repeated header/footer per line of the textarea, e.g. "Created by Bernard Brochez".
     *
     * @return array<int, string>|null
     */
    private function headerLines(?string $text): ?array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text))));

        return $lines ?: null;
    }
}
