# Step 5 — Visuals (3D scenes), with preview pages

Step 5 reads `plan.json`, `content.json` and `structure.json` and writes `visuals.json`: one scene description for
every block that has a visual. The AI **never writes code**. It fills in a JSON description (shapes, colours, named
parts, what changes at each step), and our one engine, `lesson-scene.js`, draws it with three.js. This works offline.

```
pdfs/chapters/1/chapter-1/
├── plan.json, content.json, structure.json          (Steps 2–4)
├── visuals.json         ← NEW: every scene (schema visuals.v1)
├── visuals-check.json   ← NEW: errors per page and per round, warnings, every AI call
├── preview/             ← NEW: open preview/index.html in a browser to see and turn every scene
│   ├── index.html, assets/ (three.min.js, lesson-scene.js, lesson-scene.css)
│   └── Chapter-01-…/01-1-the-structure-of-a-typical-cell.html …
└── usage.jsonl, status.json ("visuals" section added)
```

## How it works

1. **One call per lesson page that has visuals.** The prompt gives:
   - each block's planned visual;
   - the printed labels of its figures;
   - the written steps or tasks the scene must support.
2. **SceneValidator (code)** fixes small slips, such as named colours or numbers sent as text. It expands
   ready-made pieces into plain parts:
   - `plant_cell`, `animal_cell`
   - `ladder`, `beaker`, `membrane`, `osmometer`, `test_tube`

   It then checks the shape of every scene.
3. **SceneChecker (code)**, with the same rules for every model:
   - every block with a visual has a scene;
   - walk and worked-example scenes have **one state per written step**;
   - every explore or widget task points at a part, group or control that exists;
   - every id used in a state or control exists, and only allowed properties are changed;
   - slider ranges make sense, and button sets have at least 2 buttons;
   - **every printed label of a figure is the label of a part**;
   - positions and sizes stay in range, there are no more than 160 parts, and labels are 6 words at most.
4. **Repair rounds.** A page with errors goes back with the problems listed, up to 2 rounds, and the best version
   is kept.
5. **Preview pages** are written for every lesson page. Each one shows:
   - the notes;
   - every scene, with its steps (Back / Next / Play all, narrated with the browser's voice);
   - its tasks (tap one and its part lights up) and its controls;
   - its questions, as plain text.

**What the code cannot judge is whether a scene looks good.** For example, whether parts overlap or a camera angle
is right. That's what the preview is for. Step 7 adds automatic screenshots.

## Install

1. Copy `app/` and `resources/` into your project.
   - **`app/Listeners/Lessons/StartVisualsStep.php` replaces the Step 4 placeholder.**
   - `StartBuildStep.php` is the new placeholder for Step 6.
   - `resources/lessons/engine/` holds the engine: `three.min.js` (r128), `lesson-scene.js` and `lesson-scene.css`.
2. Paste the `'visuals'` block from `config/lessons-visuals.snippet.php` into `config/lessons.php`, and fill in the
   `qwen3.8-flash` price.
3. **Test set update:** copy `update-eval/EvalSuite.php` to `app/Services/Lessons/Eval/` and
   `update-eval/EvalCommand.php` to `app/Console/Commands/`. They add the `visuals` step. If you changed your own
   `EvalCommand.php`, keep yours and only replace `EvalSuite.php`.
4. Add to `.env`:

```dotenv
LESSONS_VISUALS_PROVIDER=qwen
LESSONS_VISUALS_MODEL=qwen3.8-flash
LESSONS_VISUALS_AUTO=true
LESSONS_VISUALS_TIMEOUT=3600
```

5. Run `php artisan optimize:clear && php artisan queue:restart`.

## Run

Type each command on its own, with no `#` comments after it:

```bash
php artisan lessons:write pdfs/chapters/1/chapter-1
php artisan lessons:visuals pdfs/chapters/1/chapter-1 --pages=3
php artisan lessons:visuals pdfs/chapters/1/chapter-1
php artisan lessons:visuals pdfs/chapters/1/chapter-1 --preview-only
```

- The first command writes `content.json`, if the chapter folder doesn't have one yet.
- `--pages=3` does one page first, as a cheap test.
- `--preview-only` rebuilds the preview pages from `visuals.json`, with no AI.

Then open the preview in a browser. On a `public` disk with `php artisan storage:link`, the address is:
`http://<your-app>.test/storage/pdfs/chapters/1/chapter-1/preview/index.html`

**Automatic flow:** … → `ChapterWritten` → `StartVisualsStep` → `VisualsChapterJob` → `ChapterVisualized` →
`StartBuildStep` (placeholder for Step 6).

## Test set

```bash
php artisan lessons:eval-add pdfs/chapters/1/chapter-1 --name=bio-ch01
php artisan lessons:eval-add pdfs/chapters/1/chapter-2 --name=bio-ch02
php artisan lessons:eval --step=visuals
php artisan lessons:eval --step=visuals --model=deepseek-v4.1-flash
php artisan lessons:eval --history
```

- Run `eval-add` again once the chapters have `content.json`. It adds the visuals step and **keeps the
  expectations you already edited**.
- The `--model=deepseek-v4.1-flash` line compares a second model on the same scenes.

## If something goes wrong

| Message | Fix |
|---|---|
| `content.json … did not pass its check` | Re-run Step 4 for the failing page (`lessons:write … --pages=`), or use `--force`. |
| `N problem(s), asking for a fix` | Normal. The page goes back with the problems listed. |
| `… has 3 states but the block has 5 written steps` | The model skipped steps. Repair usually fixes it; if not, re-run that page. |
| `… printed labels are not on any part` | Repair adds them. If a label is a scale word like "Smallest" it is ignored automatically. |
| Preview shows "The 3D view could not load" | The `preview/assets` folder is missing. Run `--preview-only`. |
| A scene looks crowded or the camera is too close | Re-run that page (`--pages=`), or try another model on the test set. |
