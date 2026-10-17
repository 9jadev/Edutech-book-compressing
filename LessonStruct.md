# Step 2 — Structure (+ 2b Check), Laravel AI SDK

Reads each prepared chapter folder from Step 1 and lists **everything printed on its pages,
in book order**: headings, notes, figures (with the labels inside them), tables (every cell),
activities, worked examples and their parts, exercises, every question and lettered part,
and exam tags. Then code checks the list against the page text and re-reads only the pages
with problems.

It writes into the same chapter folder:

```
pdfs/chapters/1/chapter-1/
├── pages.json              ← from Step 1
├── images_ai/ …            ← from Step 1 (sent to the AI)
├── structure.json          ← NEW: the structure record (schema structure.v1)
├── structure-check.json    ← NEW: Step 2b report (errors fixed, errors left, warnings, every call)
├── usage.jsonl             ← NEW: one line per AI call: tokens, cost, seconds
└── status.json             ← updated: a "structure" section is added
```

Works with Alibaba Qwen now, and with Gemini or Claude later by changing two settings.

---

## 1. Copy the files into your app

```
app/Ai/Agents/Lessons/StructureAgent.php
app/Services/Lessons/Structure/PageReader.php
app/Services/Lessons/Structure/LaravelAiPageReader.php
app/Services/Lessons/Structure/PromptBuilder.php
app/Services/Lessons/Structure/PageGrouper.php
app/Services/Lessons/Structure/StructureValidator.php
app/Services/Lessons/Structure/StructureChecker.php
app/Services/Lessons/Structure/StructureRunner.php
app/Services/Lessons/Structure/StructuredChapter.php
app/Services/Lessons/Structure/StructureChapterService.php
app/Jobs/Lessons/StructureChapterJob.php
app/Events/Lessons/ChapterStructured.php
app/Events/Lessons/ChapterStructureFailed.php
app/Listeners/Lessons/StartStructureStep.php     ← REPLACES the Step 1 placeholder
app/Listeners/Lessons/StartPlanStep.php
app/Console/Commands/StructureChapterCommand.php
resources/prompts/lessons/structure-system.md    ← the instructions the AI follows
```

## 2. Config

Open `config/lessons-structure.snippet.php` and paste its `'structure' => [...]` block into
your `config/lessons.php`, next to `'prepare'`.

Your provider in `config/ai.php` (from the probe test) should look like this. Name it `qwen`:

```php
'qwen' => [
    'driver' => 'openai-compatible',
    'url' => env('QWEN_URL'),
    'key' => env('DASHSCOPE_API_KEY'),
    'models' => ['text' => ['default' => env('QWEN_MODEL', 'qwen3.5-plus')]],
],
```

If you kept the name `openai-compatible`, that works too: both names are in
`structure.qwen_providers`, so thinking is turned off and JSON mode is on for either.

`.env`:

```dotenv
LESSONS_STRUCTURE_PROVIDER=qwen          # or openai-compatible, whatever your config/ai.php key is
LESSONS_STRUCTURE_MODEL=qwen3.5-plus
LESSONS_STRUCTURE_AUTO=false             # start with false: run Step 2 by hand until you trust it
LESSONS_STRUCTURE_TIMEOUT=1800
```

Then `php artisan config:clear` and restart your queue worker.

## 3. Test cheaply on 3 pages first

```bash
php artisan lessons:structure pdfs/chapters/1/chapter-1 --pages=1-3
```

Use the **folder** Step 1 made (the one with `pages.json`), as a path on your storage disk.
You will see each call as it happens, then a summary: items, figures, tables, questions,
errors left, tokens and cost. Open `structure.json` and compare it with the real pages.

Then the whole chapter:

```bash
php artisan lessons:structure pdfs/chapters/1/chapter-1
```

Try a different model on the same chapter to compare:

```bash
php artisan lessons:structure pdfs/chapters/1/chapter-1 --model=qwen3.6-plus
```

(Each run overwrites `structure.json`; `usage.jsonl` keeps every run's calls.)

## 4. Turn on the automatic hand-off

When you are happy with the results, set `LESSONS_STRUCTURE_AUTO=true`. From then on:

```
your split job ─▶ PrepareChapterJob ─▶ ChapterPrepared
                                         └▶ StartStructureStep ─▶ StructureChapterJob ─▶ ChapterStructured
                                                                                         └▶ StartPlanStep (Step 3, later)
```

Laravel 11+ finds the listeners automatically. On Laravel 10, register in `EventServiceProvider`:

```php
protected $listen = [
    \App\Events\Lessons\ChapterPrepared::class   => [\App\Listeners\Lessons\StartStructureStep::class],
    \App\Events\Lessons\ChapterStructured::class => [\App\Listeners\Lessons\StartPlanStep::class],
];
```

## 5. How it works

1. **Groups of pages.** 3 target pages per call, plus the next page as "context" so an item
   that runs over a page break is complete. Every page is a target exactly once, so nothing
   is listed twice. A 10-page chapter = 4 calls.
2. **What the AI gets** for each page: the image from `images_ai/`, the extracted text, the
   labels the text mentions ("Fig 1.2", "Exercise 2.1", "[WAEC 2015]"), and the words OCR read
   inside the diagrams. The instructions are in `resources/prompts/lessons/structure-system.md`.
3. **Our own JSON check** (`StructureValidator`). Qwen can't enforce a JSON schema when images
   are attached, so the code checks every item: known type, page in range, full wording for
   questions and examples, every cell for tables, a parent for every part. Bad JSON or missing
   fields → the problems are sent back and the AI tries again (up to 2 times).
4. **Step 2b check** (`StructureChecker`, no AI):
   - every page with text has items
   - every caption the code can see at the start of a line ("Fig 1.3", "Table 2", "Example 4",
     "Exercise 1.1", "Activity 2") is listed
   - every lettered heading ("B. Functions of …") is listed
   - every exam tag is kept
   - every page with a real picture has a figure item
   - question numbers inside an exercise have no gaps
   Pages with errors are read again with the exact problems listed (up to 2 rounds).
   Anything still wrong is reported in `structure-check.json` → `errors_left`.

## 6. What `structure.json` looks like

```json
{
  "schema": "structure.v1",
  "source_pdf": "chapter-1.pdf",
  "pages_read": [1, 2, 3, 4, 5],
  "model": "qwen3.5-plus",
  "check": { "passed": true, "errors_first_pass": 3, "errors_left": 0, "warnings": 0,
             "stats": { "items": 21, "figures": 3, "tables": 2, "questions": 0, "...": "..." } },
  "usage": { "calls": 4, "input_tokens": 34500, "output_tokens": 5450, "cost_usd": 0.0269 },
  "uncertain": [],
  "items": [
    { "id": "s001", "page": 1, "type": "chapter_title", "label": "1.", "title": "CELL STRUCTURE AND ORGANISATION",
      "text": null, "rows": null, "figure_labels": null, "parent": null, "continues_on_next_page": false, "exam_tags": [] },
    { "id": "s005", "page": 1, "type": "figure", "label": "Fig. 1.1", "title": "Plant cell",
      "figure_labels": ["Plasma membrane", "Cell wall", "Nucleolus", "..."], "...": "..." },
    { "id": "s012", "page": 3, "type": "question", "label": "3", "parent": "Exercise 1.1",
      "text": "Convert 45 to base 2.", "exam_tags": ["[WAEC 2015]"], "...": "..." }
  ]
}
```

Paragraph and note items keep only their first 12 words (the full text is already in
`pages.json`); questions, examples, definitions and tables are copied in full.

## 7. Cost on Qwen

About 4 calls per 10 pages, each ~8–10K tokens in and ~2K out. At qwen3.5-plus list price
($0.40 / $2.40 per 1M) that is roughly **$0.02–0.04 per 10-page chapter**, and the free quota
(1M tokens per model for 90 days) covers it while it lasts. `usage.jsonl` records the real
numbers for every call.

## 8. Tuning

| Setting | When to change it |
|---|---|
| `pages_per_call` | Dense pages (many questions or big tables): use 2. Light pages: 4. |
| `max_repair_rounds` | 0 to save tokens while testing; 2 is a good default. |
| `structure-system.md` | Add rules for a book's style, e.g. how it labels examples. |
| `StructureChecker::CAPTIONS` | Add patterns such as `Worked Example`, `Ex.`, `Practice`. |
| `--model` | Compare `qwen3.5-plus`, `qwen3.6-plus`, `qwen3.7-plus` on the same chapter. |

## 9. Moving to Gemini or Claude later

1. Add the provider to `config/ai.php` (`gemini` / `anthropic`) with its key.
2. Set `LESSONS_STRUCTURE_PROVIDER=gemini` and `LESSONS_STRUCTURE_MODEL=gemini-3.8-flash`
   (or `anthropic` + `claude-sonnet-5-5`).
Nothing else changes: the same prompt, validator, checker and files.

## 10. Tested here

Without an Alibaba key in this environment, the AI was replaced by a fake reader on the real
Biology Chapter 1 `pages.json`. Verified: invalid JSON is retried; items that start on the
context page are dropped; replies wrapped in ``` fences or as a bare array are accepted;
type slips like "image" or "subquestion" are mapped; missing "Fig 1.3", a missing heading and
a picture page without a figure were caught by Step 2b and fixed in one repair round; files,
status and usage were written on a local disk and on a cloud-style disk. The real Qwen run on
your machine is the first live test, so start with `--pages=1-3`.
