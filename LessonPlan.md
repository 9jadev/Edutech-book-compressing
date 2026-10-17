# Step 3 — Plan (lesson pages for one chapter)

Reads `structure.json` (Step 2) and writes `plan.json`. The plan says which lesson pages the chapter becomes,
in book order, and how each page teaches: blocks, 3D visuals and practice. It does not write lesson text.
Step 4 (Write) does that.

```
pdfs/chapters/1/chapter-1/
├── chapter-1.pdf, pages.json, images/, images_ai/       (Step 1)
├── structure.json, structure-check.json                 (Step 2)
├── plan.json          ← NEW: pages, blocks, visuals, practice, links
├── plan-check.json    ← NEW: coverage/order check, every AI call
├── usage.jsonl        (Step 3 adds its calls)
└── status.json        (Step 3 adds a "plan" section)
```

## How it works

1. **Cut into pages (code, no AI, free).** `PageSplitter` follows the process notes:
   - every `chapter_title` starts a part. Your chapter-1 PDF holds the Introduction and Chapter 1, so it has 2 parts;
   - each part opens with `00-welcome.html`, which also holds anything printed before the first heading;
   - each `heading` (A., B., C.…) starts a page `NN-1-slug.html`;
   - a section with more than 30 items is split at its subheadings (`NN-2`, `NN-3`…);
   - each book activity gets its own page right after its section (`NN-k-activity-….html`);
   - a section with fewer than 3 items joins the page before it.
2. **Plan each page (AI).** Up to 4 pages go in each call to `deepseek-v4.1-flash` on Alibaba. The model
   chooses the blocks (`notes`, `walk`, `explore`, `widget`, `example`, `practice`), describes each 3D or
   panel visual, and lists the practice questions (book questions plus 2–5 of our own).
3. **Check (code).** `PlanChecker` makes sure that:
   - every book item is on its page exactly once;
   - the book's order is kept;
   - worked examples are in example blocks;
   - every book question has a practice entry;
   - activities are widgets;
   - figures have visuals.

   Any page with errors goes back to the model with the problems listed, for up to 2 repair rounds.
4. **Teacher review (optional).** Set `LESSONS_PLAN_REVIEW=true` to stop there until someone runs
   `lessons:plan-approve`.

For your Chapter 1 folder, it produces these pages. You can see them free with `--pages-only`:

```
Part-00-Introduction/00-welcome.html
Part-00-Introduction/01-1-characteristics-of-living-things.html
Chapter-01-Cell-Structure-And-Organisation/00-welcome.html
Chapter-01-Cell-Structure-And-Organisation/01-1-the-structure-of-a-typical-cell.html
Chapter-01-Cell-Structure-And-Organisation/02-1-functions-of-the-different-parts.html
Chapter-01-Cell-Structure-And-Organisation/03-1-functions-of-some-cells.html
Chapter-01-Cell-Structure-And-Organisation/04-1-tissues-organs-and-organ-systems.html
Chapter-01-Cell-Structure-And-Organisation/04-2-activity-exercise.html
```

## Install

1. Copy `app/` and `resources/` into your Laravel project. **`app/Listeners/Lessons/StartPlanStep.php` replaces
   the placeholder from Step 2.** The new version starts Step 3 for real.
2. Open `config/lessons-plan.snippet.php` and paste its `'plan' => [...]` block into `config/lessons.php`,
   next to `'prepare'` and `'structure'`.
3. Add to `.env`:

```dotenv
# Step 3 — Plan (same Alibaba provider as Step 2, different model)
LESSONS_PLAN_PROVIDER=qwen
LESSONS_PLAN_MODEL=deepseek-v4.1-flash
LESSONS_PLAN_THINKING=false
LESSONS_PLAN_AUTO=true
LESSONS_PLAN_REVIEW=false
LESSONS_PLAN_TIMEOUT=1800
```

   `LESSONS_PLAN_PROVIDER` must be the provider name your Step 2 runs show (`qwen`). It is the entry in
   `config/ai.php` that points at `https://dashscope-intl.aliyuncs.com/compatible-mode/v1`. Use the same
   API key; nothing new is needed.

4. Clear caches and restart the worker:

```bash
php artisan optimize:clear
php artisan event:list | grep -i lessons      # StartPlanStep and StartWriteStep should be listed
php artisan queue:restart
```

Events and listeners are auto-discovered (Laravel 11+). `StartWriteStep` listens to both `ChapterPlanned`
and `ChapterPlanApproved`.

## Run

```bash
# 1. See the page split first (no AI, no cost)
php artisan lessons:plan pdfs/chapters/1/chapter-1 --pages-only

# 2. Plan with AI
php artisan lessons:plan pdfs/chapters/1/chapter-1

# Same, through the queue
php artisan lessons:plan pdfs/chapters/1/chapter-1 --queue

# Try another model without touching .env
php artisan lessons:plan pdfs/chapters/1/chapter-1 --model=qwen3.5-plus

# Teacher review (when LESSONS_PLAN_REVIEW=true)
php artisan lessons:plan-approve pdfs/chapters/1/chapter-1 --by="Mr Solomon" --note="Looks right"
```

**Automatic flow:** your split job → Step 1 → `ChapterPrepared` → Step 2 → `ChapterStructured` →
`StartPlanStep` → `PlanChapterJob` → `ChapterPlanned`. Step 3 only starts automatically when Step 2's
check passed.

## What to look at in plan.json

```jsonc
{
  "schema": "plan.v1",
  "check":  { "passed": true, "errors_left": 0, "warnings": 2, "stats": { "pages": 8, "visuals_3d": 9, "practice_book": 0, "practice_ours": 24 } },
  "review": { "required": false, "approved": true },
  "parts":  [ { "folder": "Chapter-01-Cell-Structure-And-Organisation", "title": "Chapter 1: Cell structure and organisation", "pages": ["00-welcome.html", "..."] } ],
  "pages":  [ {
      "key": "Chapter-01-…/01-1-the-structure-of-a-typical-cell.html",
      "kind": "section",                    // welcome | section | activity
      "title": "Inside a typical cell",     // model's student-friendly title
      "book_title": "The structure of a typical cell",
      "book_pages": [9, 10],
      "items": ["s022", "s023", "s024"],    // structure items on this page, in book order
      "prev": {"href": "00-welcome.html", "title": "…"}, "next": {"href": "02-1-…html", "title": "…"},
      "summary": "…", "objectives": [], "opening": null,
      "blocks": [
        { "type": "explore", "heading": "Turn a plant cell", "items": ["s023"], "purpose": "…",
          "visual": { "mode": "3d", "subject": "plant cell", "shows": "14 labelled parts", "interaction": "drag to turn, tap a part" },
          "practice": [] },
        { "type": "practice", "heading": "Check yourself", "items": [],
          "practice": [ { "kind": "label", "source": "ours", "item": null, "about": "label the plant cell" } ] }
      ],
      "additions": [ { "topic": "Mitochondria", "reason": "labelled in Fig 1.1 but not explained" } ]
  } ]
}
```

**Teacher checklist:**
- the pages match the book's sections;
- every `additions` entry is really needed;
- the visuals make sense for the topic;
- the practice tests what the page teaches.

## Cost

This is text only. Chapter 1 is about 8 pages, 3 calls and ~15k tokens in / ~8k out, about **$0.01–0.02** at
list price. New Model Studio accounts usually get some free tokens per model; check your console.

## If something goes wrong

| Message | Fix |
|---|---|
| `model not found` / `InvalidParameter` for deepseek | Your workspace region may not list it. Set `LESSONS_PLAN_MODEL=qwen3.5-plus` (already works for you in Step 2). |
| Replies start with `<think>` or are very slow | Thinking is on. Keep `LESSONS_PLAN_THINKING=false`, then `php artisan optimize:clear`. |
| `reply had problems, asking again` | Normal. The model is told what was wrong and tries again (up to 2 retries). |
| `Repair round 1: N problem(s)` | Normal. Pages that failed the check are planned again with the problems listed. |
| `Check passed: no` | Open `plan-check.json` → `errors_left`. Usually a missing item or wrong order on one page. Run again, or switch model. |
| Step 3 didn't start after Step 2 | Step 2's check didn't pass (see log), or `LESSONS_PLAN_AUTO=false`. Run `lessons:plan` by hand. |
| You want fewer/more pages | Change `min_items_per_page` / `max_items_per_page` in `config/lessons.php` and check with `--pages-only`. |
