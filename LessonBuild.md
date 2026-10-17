# Step 6 — Build (the lesson pages)

Step 6 turns `content.json` (Step 4) and `visuals.json` (Step 5) into the finished lesson pages. It is code only:
**no AI and no cost**, and it takes about a second.

```
pdfs/chapters/1/chapter-1/
├── build/
│   ├── index.html                 chapter contents, in book order, with a "Start the lesson" button
│   ├── assets/                    three.min.js, lesson-scene.js/.css, lesson.js/.css (works offline)
│   ├── Part-00-Introduction/00-welcome.html
│   ├── Chapter-01-Cell-Structure-And-Organisation/00-welcome.html, 01-1-…html …
│   └── manifest.json              list of pages, scenes and questions (Step 7 uses it)
└── build-check.json               what the build check found
```

## What every page has

- **Narration.** "Listen" reads the page aloud with the browser's voice (British or Nigerian English when the
  device has one). Words light up as they are read, and Pause and Stop are at the bottom. Walk-throughs read
  each step's narration. Every question has a "Read it to me" button.
- **3D scenes** from Step 5:
  - Back / Next / Play all for steps;
  - tap a part to see its name;
  - sliders, toggles and buttons;
  - tap a task and its part lights up.
- **Practice that marks itself**, for all 8 types:

  | Type | How it works on the page |
  |---|---|
  | multiple choice, true/false | Pick one option. |
  | match | Choose from a list beside each item. |
  | sort | Choose a group for each item. |
  | order | Move items with ↑ ↓. |
  | label | Uses the page's 3D model: "Show part 1", then pick its name. |
  | number | Commas and units are allowed. |
  | short answer | Marked against the key words, then shows the model answer. |

  More about how answers are marked:
  - "Hint" is there from the start.
  - After a wrong try, "Show the answer" appears.
  - A known wrong answer gets its own explanation, for example "Confusing the solute with the solvent".
  - The page shows how many were right first time.
- **Remember box, teacher note** (closed until opened: book pages covered, additions, corrections), **Back /
  Next** across the whole chapter, light and dark mode, phone layout, print layout.

## The build check (code)

- Every planned page was built, plus the contents page and all 5 assets.
- Every link and script on every page points to a file inside the build. That includes Back / Next across parts,
  so it works from a USB stick or an offline folder.
- Every question has a kind `lesson.js` can mark, and a usable answer.
- Every scene in `visuals.json` is on its page.
- Every page has text for the narration.

## Install

1. Copy `app/` and `resources/` into your project.
   - **`app/Listeners/Lessons/StartBuildStep.php` replaces the Step 5 placeholder.**
   - `StartTestStep.php` is the placeholder for Step 7.
   - `resources/lessons/engine/` now holds all 5 assets. `lesson.js` and `lesson.css` are new; the other three
     are the same as Step 5.
2. Paste the `'build'` block from `config/lessons-build.snippet.php` into `config/lessons.php`.
3. Optional, in `.env`: `LESSONS_BUILD_AUTO=true`
4. Run `php artisan optimize:clear && php artisan queue:restart`.

## Run

```bash
php artisan lessons:visuals pdfs/chapters/1/chapter-1
php artisan lessons:build pdfs/chapters/1/chapter-1
```

- The first line finishes the visuals for the whole chapter. That has to be done before building.
- The second line builds the pages.

Open the result on a `public` disk with `php artisan storage:link`:
`http://<your-app>.test/storage/pdfs/chapters/1/chapter-1/build/index.html`

You can also copy the `build` folder anywhere (a laptop or a USB stick) and open `index.html` directly.

**Automatic flow:** … → `ChapterVisualized` → `StartBuildStep` → `BuildChapterJob` → `ChapterBuilt` →
`StartTestStep` (placeholder for Step 7).

## If something goes wrong

| Message | Fix |
|---|---|
| `visuals.json … did not pass its check (or some pages are not done)` | Run `lessons:visuals` for the whole chapter (no `--pages`). |
| `… has a planned visual but no scene` (warning) | That block shows without 3D. Re-run Step 5 for that page. |
| "Listen" does nothing | The device has no voice installed, or the browser blocks speech until you tap. Try Chrome or Safari. On a Mac, add voices in System Settings → Accessibility → Spoken Content. |
| 3D shows "could not load" | The `build/assets` folder is missing. Re-run `lessons:build`. |
