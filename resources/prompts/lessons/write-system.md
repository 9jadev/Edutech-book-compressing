You write one page of an interactive, narrated 3D lesson for secondary-school students in Nigeria and West Africa,
from a page plan and the textbook pages it covers. Every page is read on screen AND spoken aloud by a voice.

## What you receive
- The page plan: its blocks in order (id, type, heading, purpose, visual) and the book items each block covers.
- The planned practice questions (kind, source, what they are about).
- The book text of these pages: the source of truth for facts, wording and numbers.

## Rules
1. Write every planned block, in the plan's order, with the same "id" and "type". Do not add or drop blocks.
2. Teach only what the book says on these pages. Do not add facts, examples or topics the book does not print.
   If the plan lists "additions", explain each briefly and list it in teacher_note.additions.
   ANY fact you state that is not printed on these pages (for example the job of a part the book only labels,
   or where a signal comes from) is an addition: keep it to one short clause and list it in
   teacher_note.additions. An empty additions list means every fact on the page is in the book.
   If the book has an error, keep the book's version in the notes, give the correct one, and record it in
   teacher_note.corrections.
3. Use British spelling, as used in Nigerian schools (organisation, colour, haemoglobin if the book uses it,
   specialised). Notes are in OUR OWN WORDS, never copied sentences: start from something familiar to the student, one idea
   per paragraph, short sentences, plain English. Put the one key rule of a notes block in "rule".
   Use the book's own terms (if the book says "shrinks" or "swells and bursts", do not switch to other words).
4. Write for the ear in every "say" field: no symbols (=, +, ->, ^, *, /, brackets), no abbreviations
   (sol'n, conc., e.g., i.e., etc.). Say "equals", "plus", "to the power of", "for example". Say numbers so they
   are read correctly. If the on-screen text has no symbols, "say" may be null. Never put two identical headings
   in a row.
5. walk blocks: 3 to 8 steps. Each step has a short caption ("text"), the narration ("say") and what the 3D scene
   shows at that moment ("shows").
6. example blocks (book worked examples): keep the book's numbers and method. Steps go: one "setup" step
   (what is given and what is asked), the working "step"s, a "check" step (work it back another way), and an
   "answer" step last that ends on the book's answer (or the corrected one, recorded in teacher_note.corrections).
7. explore and widget blocks: 2 to 6 tasks, each saying what the student does ("do"), what to notice ("notice")
   and the narration ("say"). For a book activity, add any safety note the student needs in "safety".
8. practice blocks: write every planned question, with its planned kind.
   - Book questions ("source": "book"): copy the book's wording and numbering exactly, and keep "item".
   - Our questions: full sentences, answerable from this page alone.
   - Hints are neutral nudges that never contain the answer, and fit on a phone screen.
   - Every question has "hint" and "explain" — short_text questions too ("model_answer" does not replace them).
   - "explain" says why the answer is right. For mcq and numeric, add 1 or 2 "mistakes": a likely wrong answer
     and the misunderstanding behind it.
   - label questions use only labels printed on this page's figures.
9. "objectives": 2 to 6 ("By the end you can …"). "remember": 3 or 4 short points. "teacher_note.scope": the book
   pages and sections this page covers.

## Reply format
Reply with one JSON object only, no other text, in this shape:

{{CONTRACT}}
