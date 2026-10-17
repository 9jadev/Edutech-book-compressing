<?php

return [

    /*
    | Step 1 — Prepare (no AI)
    */
    'prepare' => [

        // Disk the chapter PDFs live on. Results go in a folder named after each PDF, beside it.
        'disk' => env('LESSONS_DISK', 'local'),

        // Queue for PrepareChapterJob. Run a worker for it:
        //   php artisan queue:work --queue=lessons,default --timeout=1900
        'queue' => env('LESSONS_QUEUE', 'lessons'),

        // Max seconds for one chapter. Keep config/queue.php 'retry_after' ABOVE this value.
        'job_timeout' => (int) env('LESSONS_PREPARE_TIMEOUT', 1800),

        // auto | pdf_text | ocr  (auto checks the PDF's text layer first)
        'mode' => env('LESSONS_PREPARE_MODE', 'auto'),

        // Tesseract language(s): 'eng', or 'eng+fra'. Extra languages: brew install tesseract-lang
        'ocr_lang' => env('LESSONS_OCR_LANG', 'eng'),

        // Running headers/footers to remove from the text (regex). Set per book when you dispatch.
        'header_patterns' => [
            // '/^.*Created by Bernard Brochez.*$/m',
        ],

        'dpi' => 150,                 // page image resolution
        'ocr_figure_pages' => true,   // also OCR pages with pictures, to collect diagram labels
        'ai_images' => true,          // make smaller JPG copies for the AI (needs PHP GD)
        'ai_image_width' => 1000,
        'tool_timeout' => 180,        // seconds per command (one page of OCR is usually 3-8 s)

        // true = delete the original chapter PDF after copying it into its folder
        'move_source' => false,

        // Full paths if Herd/queue workers cannot find the tools. null = search automatically.
        'binaries' => [
            'pdfinfo'   => env('LESSONS_PDFINFO_PATH'),
            'pdftotext' => env('LESSONS_PDFTOTEXT_PATH'),
            'pdftoppm'  => env('LESSONS_PDFTOPPM_PATH'),
            'pdfimages' => env('LESSONS_PDFIMAGES_PATH'),
            'tesseract' => env('LESSONS_TESSERACT_PATH'),
            'pdftohtml' => env('LESSONS_PDFTOHTML_PATH'),   // Step 2 reads font sizes/bold with it
        ],
        'search_dirs' => ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin'],

        'thresholds' => [
            'min_chars' => 40,            // fewer letters/digits => scanned page
            'good_agreement' => 0.85,     // OCR words also found in the PDF text
            'max_garbage' => 0.05,        // odd symbols allowed in the PDF text
            'min_wordlike' => 0.70,       // tokens that look like real words
            'low_ocr_confidence' => 80,   // below this, warn that numbers need checking
            'probe_pages' => 3,           // sample pages for the text-layer check
        ],
    ],

    /*
    | Step 2 — Split each prepared chapter into lessons (no AI), and the AI Structure step
    | (php artisan lessons:structure) that reads the pages with a vision model.
    |
    | Lesson headings are found from the PDF's fonts: either bold lines labelled in sequence
    | ("A. Diffusion", "B. Osmosis") or one heading style bigger than the body text that is used
    | for several headings (e.g. red bold titles). Runs on the same queue as Step 1.
    */
    'structure' => [

        // Max seconds for one chapter (lesson split or AI structure). Keep DB_QUEUE_RETRY_AFTER above it.
        'job_timeout' => (int) env('LESSONS_STRUCTURE_TIMEOUT', 1800),

        // --- AI Structure step ---------------------------------------------------------------

        // Provider name from config/ai.php and the model to use.
        'provider' => env('LESSONS_STRUCTURE_PROVIDER', 'qwen'),
        'model' => env('LESSONS_STRUCTURE_MODEL', 'qwen3.5-plus'),

        // Provider names that are Alibaba Qwen (turns off thinking, asks for JSON mode).
        'qwen_providers' => ['qwen', 'openai-compatible'],

        // Start the AI Structure step automatically when Step 1 finishes.
        'auto_start' => (bool) env('LESSONS_STRUCTURE_AUTO', true),

        'queue' => env('LESSONS_QUEUE', 'lessons'),
        'call_timeout' => 180,          // seconds per AI call

        'pages_per_call' => 3,          // target pages per call
        'lookahead_page' => true,       // also send the next page so items that run over are complete
        'max_json_retries' => 2,        // re-ask when the reply is not valid JSON
        'max_repair_rounds' => 2,       // re-read pages that fail the Step 2b check

        'prompt_path' => resource_path('prompts/lessons/structure-system.md'),

        // List prices per 1M tokens [input, output] in USD, for the cost line in usage.jsonl.
        'prices' => [
            'qwen3.5-plus' => [0.40, 2.40],
            'qwen-plus' => [0.40, 1.20],
            'qwen-flash' => [0.05, 0.40],
            'gemini-3.8-flash' => [1.50, 7.50],
            'claude-sonnet-5-5' => [2.00, 10.00],
        ],

        // --- Lesson split (fonts, no AI) -----------------------------------------------------

        // A chapter needs at least this many headings to be split; otherwise it is one lesson.
        'min_headings' => 2,

        // Text before the first heading becomes an "Introduction" lesson when it has this many
        // letters/digits; shorter text is kept with the first lesson.
        'intro_min_chars' => 200,

        // Box titles that look like headings but are never lessons (matched at the start, any case).
        'ignored_titles' => [
            'objectives', 'puzzle', 'summary', 'exercise', 'activity', 'example', 'note',
            'table', 'fig', 'figure', 'revision exercise', 'key words', 'keywords',
            'did you know', 'project', 'assessment', 'answers',
        ],
    ],

     'plan' => [
        // Provider name from config/ai.php and the model. Text only, so a cheap text model is fine.
        // Same Alibaba provider as Step 2; only the model changes.
        'provider' => env('LESSONS_PLAN_PROVIDER', 'qwen'),
        'model' => env('LESSONS_PLAN_MODEL', 'qwen3.8-flash'),

        // Providers that are Alibaba Model Studio: these extra request fields are sent to them.
        'alibaba_providers' => ['qwen', 'openai-compatible'],
        'provider_options' => [
            'enable_thinking' => (bool) env('LESSONS_PLAN_THINKING', false),   // DeepSeek V4 thinks by default: slower, more output tokens
            'temperature' => 0.2,                                              // low = similar plans run after run
            'response_format' => ['type' => 'json_object'],
        ],

        // Start Step 3 automatically when Step 2 finishes (only if the Step 2 check passed).
        'auto_start' => (bool) env('LESSONS_PLAN_AUTO', true),
        'run_if_structure_has_issues' => false,

        // Teacher review: true = plan waits for `php artisan lessons:plan-approve {folder}` before Step 4.
        'require_review' => (bool) env('LESSONS_PLAN_REVIEW', false),

        'queue' => env('LESSONS_QUEUE', 'lessons'),
        'job_timeout' => (int) env('LESSONS_PLAN_TIMEOUT', 1800),
        'call_timeout' => 300,          // seconds per AI call

        // How pages are cut (code, no AI).
        'min_items_per_page' => 3,      // smaller sections join the page before
        'max_items_per_page' => 30,     // bigger sections split at subheadings
        'activity_own_page' => true,    // each book activity gets its own page

        'pages_per_call' => 4,          // lesson pages planned per AI call
        'max_items_per_call' => 80,
        'max_json_retries' => 2,
        'max_repair_rounds' => 2,

        'prompt_path' => resource_path('prompts/lessons/plan-system.md'),

        // List prices per 1M tokens [input, output] in USD, for the cost line in usage.jsonl.
        'prices' => [
            'deepseek-v4.1-flash' => [0.30, 1.20],
            'qwen3.5-plus' => [0.40, 2.40],
            'qwen-flash' => [0.05, 0.40],
            'gemini-3.8-flash' => [1.50, 7.50],
            'claude-sonnet-5-5' => [2.00, 10.00],
        ],
    ],

     'write' => [
        // Provider name from config/ai.php and the model. Text only.
        'provider' => env('LESSONS_WRITE_PROVIDER', 'qwen'),
        'model' => env('LESSONS_WRITE_MODEL', 'qwen3.8-flash'),

        // true = the provider enforces the JSON schema (Gemini, Claude, OpenAI). Keep false on Alibaba:
        // there the same contract is enforced by our ContentValidator + ContentChecker.
        'structured_output' => (bool) env('LESSONS_WRITE_STRUCTURED', false),

        'alibaba_providers' => ['qwen', 'openai-compatible'],
        'provider_options' => [
            'enable_thinking' => false,
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
        ],

        // Start Step 4 automatically when the plan is ready (and approved, if review is on).
        'auto_start' => (bool) env('LESSONS_WRITE_AUTO', true),

        'queue' => env('LESSONS_QUEUE', 'lessons'),
        'job_timeout' => (int) env('LESSONS_WRITE_TIMEOUT', 3600),
        'call_timeout' => 300,
        'max_json_retries' => 2,      // re-ask when a reply cannot be read
        'max_repair_rounds' => 2,     // re-ask a page that fails the content check

        // Running headers/footers to drop from the book text, e.g. '/^Created by Bernard Brochez$/i'.
        // Leave empty to use lessons.prepare.header_patterns.
        'header_patterns' => [],

        'prompt_path' => resource_path('prompts/lessons/write-system.md'),

        // List prices per 1M tokens [input, output] in USD. Fill in from your Model Studio console.
        'prices' => [
            // 'qwen3.8-flash' => [0.00, 0.00],
            'deepseek-v4.1-flash' => [0.30, 1.20],
            'qwen3.5-plus' => [0.40, 2.40],
        ],
    ],

    'eval' => [
        // Folder on the lessons disk for test cases, runs and history.
        'root' => env('LESSONS_EVAL_ROOT', 'lessons-eval'),
    ],

     'visuals' => [
        'provider' => env('LESSONS_VISUALS_PROVIDER', 'qwen'),
        'model' => env('LESSONS_VISUALS_MODEL', 'qwen3.8-flash'),

        'alibaba_providers' => ['qwen', 'openai-compatible'],
        'provider_options' => [
            'enable_thinking' => false,
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
        ],

        // Start Step 5 automatically when Step 4 finishes (only if the content check passed).
        'auto_start' => (bool) env('LESSONS_VISUALS_AUTO', true),

        'queue' => env('LESSONS_QUEUE', 'lessons'),
        'job_timeout' => (int) env('LESSONS_VISUALS_TIMEOUT', 3600),
        'call_timeout' => 300,
        'max_json_retries' => 2,
        'max_repair_rounds' => 2,

        'prompt_path' => resource_path('prompts/lessons/visuals-system.md'),
        // three.min.js, lesson-scene.js and lesson-scene.css (copied next to the preview pages, and used by Step 6).
        'engine_path' => resource_path('lessons/engine'),

        'prices' => [
            // 'qwen3.8-flash' => [0.00, 0.00],   // fill in from your Model Studio console
            'deepseek-v4.1-flash' => [0.30, 1.20],
            'qwen3.5-plus' => [0.40, 2.40],
        ],
    ],
    'build' => [
        // Start Step 6 automatically when Step 5 finishes (only if the visuals check passed).
        'auto_start' => (bool) env('LESSONS_BUILD_AUTO', true),
        'queue' => env('LESSONS_QUEUE', 'lessons'),
        // Folder inside the chapter folder for the finished pages.
        'dir' => 'build',
        // Engine and page assets (three.min.js, lesson-scene.js/.css, lesson.js/.css).
        'engine_path' => resource_path('lessons/engine'),
    ],
];
