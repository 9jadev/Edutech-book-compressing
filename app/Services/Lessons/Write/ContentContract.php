<?php

declare(strict_types=1);

namespace App\Services\Lessons\Write;

/**
 * The one definition of what Step 4 must produce for a page (content.v1).
 * Used by the prompt (as the JSON shape), by ContentValidator (enforced in code for every model)
 * and by StructuredWriteAgent (sent as a JSON schema to providers that support it).
 */
final class ContentContract
{
    public const BLOCK_TYPES = ['notes', 'walk', 'explore', 'widget', 'example', 'practice'];

    public const KINDS = ['mcq', 'true_false', 'match', 'sort', 'order', 'label', 'numeric', 'short_text'];

    public const STEP_ROLES = ['setup', 'step', 'check', 'answer'];

    /** Fields each block type must fill. */
    public const BLOCK_FIELDS = [
        'notes' => ['paragraphs'],
        'walk' => ['intro', 'steps'],
        'example' => ['intro', 'steps'],
        'explore' => ['intro', 'tasks'],
        'widget' => ['intro', 'tasks'],
        'practice' => ['questions'],
    ];

    /** Fields each question kind must fill (besides prompt, hint, explain). */
    public const KIND_FIELDS = [
        'mcq' => ['options', 'answer'],
        'true_false' => ['answer'],
        'match' => ['pairs'],
        'sort' => ['groups'],
        'order' => ['sequence'],
        'label' => ['targets'],
        'numeric' => ['answer'],
        'short_text' => ['accept', 'model_answer'],
    ];

    /** JSON shape shown to the model (the prompt) — kept in step with the validator. */
    public static function example(): string
    {
        return <<<'JSON'
{"page": "<exact page key>",
 "title": "<page title>",
 "intro": "<1-2 sentences that open the page, in our own words>",
 "objectives": ["By the end you can …"],
 "blocks": [
  {"id": "b1", "type": "notes", "heading": "…",
   "paragraphs": [{"text": "<what the student reads>", "say": "<the same, written for the ear, or null if identical>"}],
   "rule": "<one-line key rule for a highlighted box, or null>"},
  {"id": "b2", "type": "walk", "heading": "…", "intro": "…",
   "steps": [{"text": "<caption on screen>", "say": "<narration>", "shows": "<what the 3D scene shows at this step>"}]},
  {"id": "b3", "type": "example", "heading": "…", "intro": "…",
   "steps": [{"role": "setup|step|check|answer", "text": "…", "say": "…", "shows": "…"}]},
  {"id": "b4", "type": "explore|widget", "heading": "…", "intro": "…", "safety": "<widget only, or null>",
   "tasks": [{"do": "<what the student does>", "notice": "<what they should notice>", "say": "<narration>"}]},
  {"id": "b5", "type": "practice", "heading": "…",
   "questions": [
    {"n": 1, "source": "book|ours", "item": "<book item id or null>", "kind": "mcq",
     "prompt": "<full sentence>", "say": "<prompt for the ear, or null>",
     "options": ["…", "…", "…", "…"], "answer": 0,
     "hint": "<neutral nudge that does not give the answer away>",
     "explain": "<why the answer is right>",
     "mistakes": [{"wrong": "<a likely wrong answer>", "why": "<the misunderstanding behind it>"}]},
    {"n": 2, "source": "ours", "item": null, "kind": "short_text",
     "prompt": "<full sentence>", "say": null,
     "accept": ["<key word or phrase>", "…"], "model_answer": "<a full correct answer>",
     "hint": "<neutral nudge>", "explain": "<why that answer is right>", "mistakes": []}
   ]}
 ],
 "remember": ["<3 or 4 short points>"],
 "teacher_note": {"scope": "<what this page covers from the book>", "additions": ["…"], "corrections": ["…"]}}

EVERY question, of every kind, has "prompt", "hint" and "explain". Then add the fields for its kind:
- mcq: "options" (3 to 5 strings), "answer" (index of the one correct option, from 0)
- true_false: "answer" (true or false)
- match: "pairs" ([["left", "right"], …], at least 3)
- sort: "groups" ([{"name": "…", "members": ["…"]}], at least 2 groups)
- order: "sequence" (the items in the correct order, at least 3)
- label: "targets" (the label names the student places on the figure, at least 2)
- numeric: "answer" (a number), "unit" (or null), "tolerance" (a number, 0 for exact)
- short_text: "accept" (key words or phrases a correct answer must contain), "model_answer"
JSON;
    }
}
