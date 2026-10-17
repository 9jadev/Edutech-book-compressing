---
name: 3d-page-generator
description: Build polished, single-file HTML interactive 3D scientific simulations and physical systems with Three.js, clickable 3D labels and parts that open inspection modals, KaTeX equations, Web Speech narration, a guided stage-by-stage "Play Journey", and live sliders that change the physics in real time. Use this skill whenever the user wants to simulate, visualize, model, animate or explore a scientific concept in 3D or interactively — physics (circuits, orbits, waves, optics, thermodynamics), chemistry (molecules, reactions, orbitals, lattices), electronics (transistors, motors, generators), biology (cells, DNA, organs, neurons) or engineering devices — even if they only say "show me how X works", "make an interactive model of Y" or "a 3D explainer for my class".
version: 1.0.0
---

# Interactive 3D Scientific Simulation & Model Generator

You are producing a single self-contained `.html` file that a teacher, student or engineer can open in a browser and learn from. Three things matter, in this order:

1. **It works the first time.** No console errors, no labels that block the camera, no narration that cuts off mid-sentence. Most failures in this kind of file come from a handful of known traps, listed under "Pitfalls" below — read them before writing code.
2. **The science is right.** Correct equations, correct units, real constants, honest simplifications.
3. **It looks and feels premium.** Realistic materials, soft lighting, smooth motion, a clean UI.

Implement the simulation directly in a single, clean self-contained `.html` file following the architecture and pitfalls below. Do not load external reference files via tool calls; build the complete simulation with the required stack, interactive components, inspection modals, and live physics loop.

## Workflow

1. **Plan the science first** (in your head, not in the output): the governing equations, the variables a user should be able to change, what physically happens when they change, and the 5–7 stages that tell the story from basics to insight. Decide what you are simplifying (e.g. "ideal wire, no resistance", "orbits not to scale") — you'll state this in the UI.
2. **Define the data**: a `COMPONENTS` object (one entry per clickable part: title, description, formula, variable list, narration text) and a `STAGES` array (title, narration, what to highlight, camera target). Putting content in data rather than scattered through code keeps the modal, labels and journey consistent.
3. **Build the scene** from primitives grouped per component, each group tagged with `userData.id` matching a `COMPONENTS` key.
4. **Wire the controls** so each slider updates simulation state, the 3D visuals, and the live readouts/equation values together.
5. **Run the quality checklist** at the end of this file before delivering.

## Required stack

Load exactly these, pinned, in `<head>`. The `examples/js` addons only exist up to three r147 and must match the core version, which is why everything is pinned to r128 — mixing versions is the most common cause of `THREE.OrbitControls is not a con```html
<script src="https://cdn.tailwindcss.com/3.4.17"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/contrib/auto-render.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/renderers/CSS2DRenderer.js"></script>
```

No other external resources: no remote images, textures, fonts beyond a system/Google font stack, or fetch calls. Generate textures procedurally (canvas → `THREE.CanvasTexture`) if you need them. Do not use APIs newer than r128 (e.g. `CapsuleGeometry`, `outputColorSpace`); use `renderer.outputEncoding = THREE.sRGBEncoding`.

## Feature specifications

### Clickable 3D labels and parts
- Every major component gets a `CSS2DObject` pill badge and a raycastable mesh group; clicking either opens the same inspection modal.
- The CSS2D layer sits on top of the WebGL canvas, so its container must be `pointer-events: none` and only the badges `pointer-events: auto`. Otherwise the label layer swallows every drag and the camera stops working.
- Raycast on click, not on raw `pointerdown`: record the pointer position on `pointerdown` and only raycast on `pointerup` if it moved less than ~5px. Otherwise every orbit drag opens a modal.
- Raycast against a maintained `interactive` array (not the whole scene), then walk up `.parent` until you find `userData.id`.
- Give hovered parts feedback: cursor change and a subtle emissive highlight.

### Inspection modal & mathematical symbol rendering
- All mathematical symbols, variables, and expressions (e.g., $P_1$, $m_1$, $\omega$, $\tau$, $F = ma$) MUST be rendered as formatted visual mathematics using KaTeX. NEVER leave raw LaTeX strings like `$P_1$` or `$$...$$` displayed as raw text in the DOM.
- Contains: title, plain-language explanation, governing equations rendered with KaTeX (`displayMode: true, throwOnError: false`), variable table with symbols typeset via KaTeX, meaning and SI units, live values where relevant, and a "🔊 Read aloud / ⏹ Stop" toggle.
- Call `renderMathInElement(document.body, ...)` on DOM load and when opening modals to ensure all symbols across descriptions, tables, and cards render as true mathematical notation. Closes on ✕, backdrop click, and `Esc`. stops any narration.

### Narration (Web Speech API)
- Feature-detect `window.speechSynthesis`; if it's missing, hide the audio buttons and let the journey run on timers.
- Always `speechSynthesis.cancel()` before speaking, on modal close, and on stage change.
- Split long text into sentences and queue them as separate utterances. Chrome silently stops a single utterance after roughly 15 seconds; chunking avoids this.
- Voices load asynchronously — pick a voice in `speechSynthesis.onvoiceschanged`, preferring an English local voice, and fall back to the default.
- Speech must start from a user gesture (a click), which the Play button provides.
- Keep narration text separate from on-screen text when the on-screen version contains math: speak "V equals I times R", not raw LaTeX.

### Play Journey
- 5–7 stages progressing from "what is this" to the key insight. Each stage sets the camera target, highlights its components, adjusts simulation parameters if useful, updates a stage panel and progress dots, and narrates.
- Advance when narration finishes plus a short pause, with a minimum stage duration as a fallback, so the journey never stalls or races ahead if speech fails.
- Provide Play/Pause, Previous, Next, and a pulsing "Narrating" indicator. Manual interaction with a slider or the camera should not break the journey.
- Animate camera moves (lerp position and `controls.target`) rather than jumping.

### Live controls
- Sliders for the physically meaningful variables, each with label, current value and unit, and sensible real-world ranges.
- Changes take effect immediately in the animation (speed, size, colour, field strength, etc.) and in the readout panel showing derived quantities (e.g. current computed from V and R).
- Include Reset, Pause/Resume simulation, and a time-scale control when the motion is fast or slow.

### Mathematics
- Write LaTeX in JS with `String.raw`...\`` so backslashes survive; a plain string like `"\frac{a}{b}"` becomes a form-feed character and renders as garbage.
- Every equation gets a variable breakdown with SI units. Show the general law in the sidebar and the specific relationships in each component's modal.

### Visual design
- Default to a light theme: off-white background (`#f8fafc`), glassmorphism panels (`bg-white/70 backdrop-blur-md`, subtle border and shadow), slate text with high contrast, one accent colour. Switch to dark only if the user asks.
- `MeshPhysicalMaterial` for hero objects: metals (`metalness ~1, roughness 0.2–0.4`), plastics (`metalness 0, clearcoat`), glass (`transmission ~0.9, roughness ~0.05, thickness`). Transmission is expensive — use it on at most a few meshes and fall back to `transparent + opacity` elsewhere.
- Lighting: hemisphere or ambient fill, a key directional light casting `PCFSoftShadowMap` shadows with a tight shadow camera, and a back light behind the subject for rim highlights. Add a soft ground plane (`ShadowMaterial`) so objects feel grounded.
- Colour-code consistently and meaningfully (e.g. electrons blue, positive charge red) and include a small legend.
- Layout: canvas fills the viewport; sidebar with controls and equations on the right (collapsible drawer on narrow screens); stage panel at the bottom; title at top-left.

### Scientific integrity
- Use real constants and SI units. When visuals are scaled or slowed down for visibility, say so in a small "Model notes" section (e.g. "electron drift speed exaggerated ~10⁹×").
- Derived readouts must be computed from the same equations shown, not hardcoded.
- For time integration use a fixed timestep with an accumulator and clamp the frame delta (`Math.min(dt, 0.05)`) so switching tabs doesn't explode the simulation.

### Performance, robustness and accessibility
- `renderer.setPixelRatio(Math.min(devicePixelRatio, 2))`; handle `resize` for both renderers and the camera.
- Reuse geometries and materials for repeated particles; use `InstancedMesh` above a few hundred objects. Never allocate new vectors inside the animation loop.
- Respect `prefers-reduced-motion` by slowing auto-rotation and camera tweens.
- Buttons get `aria-label`s and are keyboard-reachable; the modal traps focus while open.
- Wrap initialisation in a check for `THREE`, `katex` and the addons, and show a friendly message if a CDN failed to load instead of a blank page.

## Pitfalls (each of these has broken real outputs)

| Symptom | Cause | Fix |
|---|---|---|
| Camera won't rotate | CSS2D layer capturing pointer events | Label container `pointer-events:none`, badges `auto` |
| Modal opens on every drag | Raycasting on `pointerdown` | Click threshold on `pointerup` |
| `OrbitControls is not a constructor` | Addon/core version mismatch or ES-module URL | Pinned r128 `examples/js` URLs above |
| Equations show `rac` or blank | Unescaped backslashes | `String.raw` |
| Narration stops mid-paragraph | Chrome long-utterance cutoff | Sentence chunking queue |
| Voices overlap | Missing `cancel()` | Cancel before every speak and on close |
| Objects look flat or washed out | Missing `outputEncoding` / no shadows | sRGB encoding, key + rim lights, ground shadow |
| Labels drift after resize | Only WebGL renderer resized | Resize the CSS2DRenderer too |

## Output

Deliver one `.html` file named after the topic (e.g. `ohms-law-circuit.html`). In the reply, give two or three sentences on what's in it and how to use it (click parts, press Play, drag sliders) — the file speaks for itself.

## Quality checklist (verify before delivering)

- [ ] Opens with no console errors; all six CDN URLs exactly as pinned
- [ ] Camera orbits, zooms and pans with labels present
- [ ] Every badge and every major mesh opens its modal; drags don't
- [ ] Every modal has KaTeX equation, variable table with units, and a working read-aloud toggle
- [ ] Journey runs all stages start to finish, with camera moves and narration, and can be paused/stepped
- [ ] Every slider visibly changes the simulation and updates derived readouts
- [ ] Model notes state simplifications and scale factors
- [ ] Usable at 390px width (sidebar collapses)