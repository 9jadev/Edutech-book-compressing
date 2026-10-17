# Step 1 — Prepare (Laravel)

Takes one chapter PDF, reads its text (PDF text when it is good, OCR when it is not),
makes page images, and writes `pages.json`. Everything goes in a new folder named after
the PDF, beside the PDF:

```
storage/app/books/biology/chapters/
├── 01-cell.pdf                 ← chapter PDF from your split job (left in place)
└── 01-cell/                    ← created by this step
    ├── 01-cell.pdf             ← copy of the PDF that was processed
    ├── pages.json              ← the result (schema pages.v1)
    ├── status.json             ← processing | completed | failed (+ error)
    ├── images/p-1.png …        ← full page images, 150 dpi
    └── images_ai/p-1.jpg …     ← 1000 px copies to send to the AI in Step 2
```

Set `'move_source' => true` in `config/lessons.php` if you want the original PDF removed
after it is copied into the folder.

---

## 1. Install the tools on your Mac (Herd)

```bash
brew install poppler tesseract          # pdfinfo, pdftotext, pdftoppm, pdfimages + OCR
brew install tesseract-lang             # optional: more OCR languages
```

Herd's PHP already includes the GD extension. Check with `php -m | grep -i gd`.

## 2. Copy the files into your Laravel app

```
app/Services/Lessons/Prepare/Toolbox.php
app/Services/Lessons/Prepare/PrepareOptions.php
app/Services/Lessons/Prepare/ChapterPreparer.php
app/Services/Lessons/Prepare/PreparedChapter.php
app/Services/Lessons/Prepare/PrepareChapterService.php
app/Jobs/Lessons/PrepareChapterJob.php
app/Events/Lessons/ChapterPrepared.php
app/Events/Lessons/ChapterPreparationFailed.php
app/Listeners/Lessons/StartStructureStep.php
app/Console/Commands/PrepareChapterCommand.php
config/lessons.php
```

No Composer packages are needed. Requires PHP 8.1+ and Laravel 10, 11 or 12.

## 3. Check the tools from Laravel

```bash
php artisan lessons:prepare --check
```

You should see a ✓ for pdfinfo, pdftotext, pdftoppm, pdfimages, tesseract, the `eng`
language and GD. If a tool shows ✗ even though it works in your terminal, Herd or the
queue worker cannot see Homebrew's folder. Put full paths in `.env`:

```dotenv
LESSONS_PDFTOTEXT_PATH=/opt/homebrew/bin/pdftotext
LESSONS_PDFTOPPM_PATH=/opt/homebrew/bin/pdftoppm
LESSONS_PDFINFO_PATH=/opt/homebrew/bin/pdfinfo
LESSONS_PDFIMAGES_PATH=/opt/homebrew/bin/pdfimages
LESSONS_TESSERACT_PATH=/opt/homebrew/bin/tesseract
```

(`which pdftotext` shows the right path; Intel Macs use `/usr/local/bin`.)

## 4. Queue settings (important)

```dotenv
QUEUE_CONNECTION=database
LESSONS_QUEUE=lessons
LESSONS_PREPARE_TIMEOUT=1800
```

In `config/queue.php`, set the connection's `retry_after` **higher than the timeout**,
for example `'retry_after' => 1900`. Otherwise Laravel starts a second copy of a long
OCR job while the first is still running.

Run a worker that listens to the lessons queue:

```bash
php artisan queue:work --queue=lessons,default --timeout=1900 --tries=2
```

## 5. Try it by hand first

```bash
# runs now and prints a summary (printed page numbers are detected automatically)
php artisan lessons:prepare books/biology/chapters/01-cell.pdf

# options
php artisan lessons:prepare books/biology/chapters/01-cell.pdf --printed-start=9 --mode=ocr
php artisan lessons:prepare books/biology/chapters/01-cell.pdf \
    --header='/^.*Created by Bernard Brochez.*$/m'

# send it to the queue instead
php artisan lessons:prepare books/biology/chapters/01-cell.pdf --queue
```

## 6. Plug it into your split job

Your split job already writes each chapter PDF. When a chapter is written, dispatch
Step 1 for it. Pick ONE of these.

### A. Dispatch per chapter, inside the split job (simplest)

```php
use App\Jobs\Lessons\PrepareChapterJob;

// inside your SplitBookJob::handle(), after each chapter PDF is saved:
foreach ($chapters as $chapter) {
    // ... your existing qpdf / split code that writes $chapterPath ...

    PrepareChapterJob::dispatch(
        disk: 'local',
        pdfPath: $chapterPath,                       // e.g. books/biology/chapters/01-cell.pdf
        firstPrintedPage: $chapter['printed_start'] ?? null,   // null = detect
        headerPatterns: $book->header_patterns ?? [],          // optional, per book
        meta: ['book_id' => $book->id, 'chapter' => $chapter['number']],
    );
}
```

Each chapter becomes its own job, so a failure in one chapter does not stop the others.

### B. One batch for the whole book, with a callback when every chapter is done

Needs the batches table once: `php artisan make:queue-batches-table && php artisan migrate`
(on Laravel 10: `php artisan queue:batches-table`).

```php
use App\Jobs\Lessons\PrepareChapterJob;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

// at the end of SplitBookJob::handle():
Bus::batch(
    collect($chapterPaths)->map(fn (string $path, int $i) => new PrepareChapterJob(
        disk: 'local', pdfPath: $path, meta: ['book_id' => $book->id, 'chapter' => $i + 1],
    ))->all()
)
    ->name("Prepare chapters: {$book->title}")
    ->onQueue('lessons')
    ->allowFailures()
    ->then(fn (Batch $b) => logger()->info("All chapters prepared for book {$book->id}"))
    ->dispatch();
```

## 7. What happens next

When a chapter is done, `ChapterPrepared` fires with a `PreparedChapter` object:

```php
$event->chapter->folder;      // books/biology/chapters/01-cell
$event->chapter->pagesJson;   // books/biology/chapters/01-cell/pages.json
$event->chapter->strategy;    // pdf_text | ocr_all
$event->chapter->warnings;    // things to check
$event->meta;                 // what you passed: book_id, chapter …
```

`app/Listeners/Lessons/StartStructureStep.php` receives it. Laravel 11+ finds the
listener automatically. On Laravel 10, register it in `EventServiceProvider`:

```php
protected $listen = [
    \App\Events\Lessons\ChapterPrepared::class => [\App\Listeners\Lessons\StartStructureStep::class],
];
```

When Step 2 exists, dispatch it from that listener.

If a chapter fails after its retries, `status.json` in its folder says `failed` with the
error, and `ChapterPreparationFailed` fires.

## 8. Modes

| Mode | Use for | What it does |
|---|---|---|
| `auto` (default) | Every new book | OCRs 3 sample pages and compares them with the PDF's text. Good text layer → use it (OCR only scanned or garbled pages and figure pages). Bad or missing → OCR everything. |
| `pdf_text` | Books you have already checked | Uses the PDF text; still OCRs figure pages for diagram labels. |
| `ocr` | Fully scanned books, or text layers you know are broken | OCRs every page. About 2× slower. |

## 9. Timing (Apple Silicon is usually faster than these test figures)

- 5-page chapter, `auto`: about 20 s
- 10-page chapter with 8 figure pages: about 35 s
- Full OCR: about 6–8 s per page

## 10. What was fixed compared with the first version

- Tools are found even when Herd or the queue worker cannot see Homebrew (config paths + search folders).
- Every command has a timeout, so a stuck OCR cannot hang the queue.
- Printed page numbers are detected automatically from the numbers printed on the pages
  (also on scanned pages, using the OCR text), or you can pass them.
- Running headers are set per book (config or per dispatch), not hard-coded.
- Missing OCR language is reported clearly before any work starts.
- Re-running a chapter clears the old images first, so old and new files never mix.
- `pages.json` is written atomically, so the next step never reads a half-written file.
- One run per PDF at a time (duplicate dispatches are dropped).
- `status.json` shows processing, completed or failed with the error.
- Works on cloud disks too (processes in a temp folder, uploads the results).



php artisan queue:work --queue=default --timeout=1900
php artisan queue:work --queue=lessons --timeout=1900
php artisan lessons:structure pdfs/chapters/1/chapter-1 --pages=1-3


use App\Ai\Agents\PageReaderProbe;
use Laravel\Ai\Files;

$r = (new PageReaderProbe)->prompt(
    'Read this page and return the JSON.',
    attachments: [Files\Image::fromStorage('pdfs/chapters/1/chapter-1/images_ai/p-1.jpg', disk: 'public')],
    provider: 'openai-compatible',
    model: 'qwen3.5-plus',
);
echo (string) $r;
dump($r->usage);

