# Step 4 — Write (lesson content), plus the test set (`lessons:eval`)

Step 4 reads `plan.json`, `structure.json` and `pages.json` and writes `content.json`. That file holds the real
words of every page: notes, narration, walk-through steps, tasks and every practice question with its answer.
There is no HTML yet; Step 7 (Build) turns `content.json` into pages.

```
pdfs/chapters/1/chapter-1/
├── pages.json, structure.json, plan.json        (Steps 1–3)
├── content.json         ← NEW: every page's text, narration and questions (schema content.v1)
├── content-check.json   ← NEW: errors per page and per round, warnings, every AI call
└── usage.jsonl, status.json ("write" section added)
```

## How it works

1. **One call per page.** The prompt holds:
   - the page plan (blocks, visuals, planned questions);
   - the full wording of the book items on the page;
   - the book text of its PDF pages, so facts, wording and numbers come from the book.
2. **ContentValidator (code)** checks the shape, using the contract in `ContentContract.php`. It fixes small slips
   without asking the model again: an answer given as "B" becomes index 1, "true" becomes `true`, "2.5" becomes 2.5.
3. **ContentChecker (code)** applies the same rules to every model:
   - every planned block and question is written;
   - book questions keep the book's exact wording;
   - each question type is complete: mcq has 3–5 options and one valid answer, match has at least 3 pairs, numeric has a number;
   - hints never contain the answer;
   - notes never copy 12 or more words in a row from the book;
   - narration (`say`) has no symbols or abbreviations a voice would stumble on;
   - worked examples go setup → steps → check → answer;
   - label questions use only labels printed on that page's figures;
   - the Remember box has 3–4 points, plus objectives and a teacher note.
4. **Repair.** A page with errors goes back with the problems listed, up to 2 rounds, and the best version is kept.
   Every round's errors are saved in `content-check.json`.
5. **Plan clean-up (code).** Like Step 3, an "example" block that holds no book worked example is treated as a
   step-through walk.

## Install

1. Copy `app/` and `resources/` into your project.
   - **`app/Listeners/Lessons/StartWriteStep.php` replaces the Step 3 placeholder.** The new version starts Step 4.
   - `StartVisualsStep.php` is the new placeholder for Step 5.
   - **`app/Services/Lessons/Plan/*` is not in this zip.** Keep your Step 3 files.
2. Paste the `'write'` and `'eval'` blocks from `config/lessons-write.snippet.php` into `config/lessons.php`.
   - Fill in the price for `qwen3.8-flash`.
   - Add your book's running header to `header_patterns`, for example `'/^Created by Bernard Brochez$/i'`.
     Otherwise the header shows up in the "copied from the book" check.
3. Add to `.env`:

```dotenv
LESSONS_WRITE_PROVIDER=qwen
LESSONS_WRITE_MODEL=qwen3.8-flash
LESSONS_WRITE_STRUCTURED=false
LESSONS_WRITE_AUTO=true
LESSONS_WRITE_TIMEOUT=3600
LESSONS_EVAL_ROOT=lessons-eval
```

4. Run `php artisan optimize:clear && php artisan queue:restart`.

`LESSONS_WRITE_STRUCTURED=true` makes the provider enforce the JSON schema itself (`StructuredWriteAgent`). Use
it with Gemini, Claude or OpenAI. Keep it `false` on Alibaba, where our own validator and checker enforce the same
contract. Either way, the checker gives the final answer.

## Run Step 4

```bash
php artisan lessons:write pdfs/chapters/1/chapter-1 --pages=3     # one page first (cheap)
php artisan lessons:write pdfs/chapters/1/chapter-1               # all pages
php artisan lessons:write pdfs/chapters/1/chapter-1 --pages=osmosis
php artisan lessons:write pdfs/chapters/1/chapter-1 --queue
```

`--pages` rewrites only those pages and keeps the rest of `content.json`.

**Automatic flow:** … → `ChapterPlanned` (or `ChapterPlanApproved`) → `StartWriteStep` → `WriteChapterJob` →
`ChapterWritten` → `StartVisualsStep` (placeholder).

## The test set: decide models with numbers

A **case** is a frozen copy of a chapter you have checked by hand. Every model is tested on exactly the same input.

```bash
# 1. Freeze your two good chapters (do this once)
php artisan lessons:eval-add pdfs/chapters/1/chapter-1 --name=bio-ch01
php artisan lessons:eval-add pdfs/chapters/1/chapter-2 --name=bio-ch02

# 2. Test the current models (Plan and Write)
php artisan lessons:eval

# 3. Try another model on the same cases
php artisan lessons:eval --step=write --model=deepseek-v4.1-flash
php artisan lessons:eval --step=structure            # Step 2 too (re-reads the page images)

# 4. Compare every run so far
php artisan lessons:eval --history
```

**What the report shows, for each step and model:**
- **Check passed:** how many cases passed the step's own check.
- **Right first time:** cases with no errors before repair. This is the best single measure of a model.
- **Expectations:** how many of the case's expectations were met (`case.json`).
- **Calls (failed), tokens, cost, time.**

Everything is saved in `lessons-eval/runs/<run>/report.json` and `lessons-eval/history.jsonl`.

**Expectations** live in `lessons-eval/cases/<name>/case.json`. `eval-add` fills in the basics: the check
passes, the page count, every page key and the figure count. Add your own as you find things that matter:

```json
{"file": "plan.json", "path": "pages.*.key", "op": "contains", "value": "Chapter-01-Cell-Structure-And-Organisation/04-2-activity-exercise.html", "why": "the microscope activity has its own page"}
{"file": "content.json", "path": "check.stats.questions", "op": ">=", "value": 15, "why": "enough practice"}
{"file": "structure.json", "path": "check.stats.figures", "op": "==", "value": 14, "why": "all Chapter 2 figures found"}
```

Operators: `==`, `!=`, `>=`, `<=`, `contains`, `not_contains`, `count==`, `count>=`, `count<=`.

**The rule from now on:** change a checker only for a real lesson requirement, and add a case or expectation for
it at the same time. To switch models, run `lessons:eval` and compare the numbers.

## If something goes wrong

| Message | Fix |
|---|---|
| `plan.json … waiting for teacher review` | `php artisan lessons:plan-approve <folder>`, or use `--force` |
| `N problem(s), asking for a fix` | Normal. The page is sent back with the problems listed. |
| `problem(s) left` / Check passed: no | Open `content-check.json` → `pages` → `errors_left`. Re-run just that page with `--pages=`. |
| Many "copies the book word for word" errors that quote the header | Add the running header to `lessons.write.header_patterns`. |
| Cost n/a | Add the model to `lessons.write.prices`. |




brew install node
npm install --save-dev playwright
npx playwright install chromium
