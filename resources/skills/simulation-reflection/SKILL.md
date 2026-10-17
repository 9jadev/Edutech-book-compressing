---
name: simulation-reflection
description: Inspect, audit, critique, and self-correct interactive 3D Web scientific simulations and Three.js HTML pages. Enforce 100% compliance with architectural invariants including Three.js r128 pinned stack, KaTeX mathematical typography (rendering all symbols like $P_1$ as math, never raw text), CSS2D pointer-events safety, raycast drag thresholds, Web Speech chunking, physics parameter sliders, and dual render loops.
version: 1.0.0
---

# Interactive 3D Simulation Reflection & Self-Correction Protocol

You are the **Lead Simulation Reflection & Self-Correction Agent**. Your mission is to take an initial interactive 3D simulation, perform an exhaustive architectural and scientific audit, resolve every defect, and produce a perfected, 100% compliant, single-file `.html` simulation.

When invoked, you receive:
1. The **Scientific Topic**: The phenomenon, system, or mechanism being simulated.
2. The **Current Simulation HTML Code**: The initial or draft simulation file.
3. The **Deficiencies & Critique Report**: Specific issues identified by automated validation.

Your responsibility is not just to patch isolated lines, but to ensure the entire simulation is cohesive, mathematically rigorous, visually stunning, and completely free of console errors.

---

## The 11 Mandatory Architectural Invariants

Every simulation you deliver MUST satisfy all 11 invariants with zero exceptions:

### 1. Standalone HTML5 Document Architecture
- Must begin with `<!DOCTYPE html>`.
- Contains `<html lang="en">`, `<head>`, `<meta charset="UTF-8">`, `<meta name="viewport" content="width=device-width, initial-scale=1.0">`, `<title>`, and `<body>`.
- Self-contained in a single file with embedded `<style>` and `<script>` tags. No external local asset dependencies.

### 2. Pinned Three.js r128 Core Library
- Load the pinned CDN:
  ```html
  <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
  ```
- **CRITICAL**: Do NOT use newer Three.js versions (e.g. r148+) via CDN scripts, as the classic `examples/js/` addons (`OrbitControls.js`, `CSS2DRenderer.js`) were removed in later versions. r128 guarantees stability.
- Use `renderer.outputEncoding = THREE.sRGBEncoding` rather than deprecated or r152+ properties.

### 3. Pinned Addons: CSS2DRenderer & OrbitControls
- Load both matching r128 addons:
  ```html
  <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/renderers/CSS2DRenderer.js"></script>
  ```
- Ensure both renderers are initialized, appended to the DOM, and sized to window dimensions.

### 4. KaTeX Core Mathematical Typesetting
- Load KaTeX CSS and JS in `<head>`:
  ```html
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css">
  <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.js"></script>
  ```

### 5. Mathematical Symbol & Formula Typesetting (Strict Non-Raw Rule)
- Load the KaTeX auto-render extension in `<head>`:
  ```html
  <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/contrib/auto-render.min.js"></script>
  ```
- **MANDATORY RULE**: ALL mathematical symbols, variables, parameters, and subscripts (e.g. `$P_1$`, `$m_1$`, `$\omega$`, `$\tau$`, `$\Delta V$`, `$T_2$`, `$F = ma$`) MUST be rendered as formatted visual mathematical typography using KaTeX.
- **FORBIDDEN**: NEVER leave raw LaTeX strings like `"$P_1"` or `"$$...$$"` or `"\omega"` displayed as unrendered plain text in headers, paragraphs, badges, table cells, or inspection modals.
- **Single-Dollar Delimiter Requirement**: In KaTeX's official auto-render extension, single-dollar (`$`) delimiters are disabled by default. You MUST explicitly provide delimiters including single-dollar inline math:
  ```javascript
  function renderDocumentMathematics(rootEl = document.body) {
    if (typeof renderMathInElement !== 'undefined') {
      renderMathInElement(rootEl, {
        delimiters: [
          { left: '$$', right: '$$', display: true },
          { left: '$', right: '$', display: false },
          { left: '\\(', right: '\\)', display: false },
          { left: '\\[', right: '\\]', display: true }
        ],
        throwOnError: false
      });
    }
  }
  ```
- **Dynamic Content & MutationObserver**: Whenever modals open, stage panels change, or table rows are populated, static page-load math rendering will not format newly created DOM nodes. You MUST configure a `MutationObserver` on `document.body` or call `renderDocumentMathematics(modalCard)` so that any dynamically inserted elements with `$P_1$` or math notation are immediately rendered as beautiful typography.

### 6. CSS2D Pointer-Events Trap Safety
- The CSS2D container sits in front of the WebGL canvas (`z-index: 10`). If not configured correctly, it swallows all mouse/touch events and completely breaks camera orbit rotation.
- **Enforce in `<style>`**:
  ```css
  #css2d-container {
    position: absolute;
    inset: 0;
    pointer-events: none !important;
    z-index: 10;
  }
  .label-badge {
    pointer-events: auto !important;
    cursor: pointer;
    user-select: none;
  }
  ```

### 7. Raycast Drag vs Click Protection (<6px threshold)
- Do NOT open inspection modals on raw `pointerdown` or `mousedown`.
- Record pointer position on `pointerdown`: `startX = e.clientX; startY = e.clientY;`.
- On `pointerup`, compute distance: `Math.hypot(e.clientX - startX, e.clientY - startY)`.
- Only execute raycasting and modal inspection if `distance < 6` pixels. This ensures dragging to rotate or pan the camera never accidentally triggers click modals.

### 8. Structured Registries: `COMPONENTS` & `STAGES`
- **`COMPONENTS` Object**: Centralized dictionary of clickable anatomical parts.
  Each entry has: `title`, `badge`, `description`, `formula` (LaTeX string), `variables` (array of objects with `symbol`, `name`, `unit`, `valKey`), and `narration`.
  Every mesh group must have `userData.id = '<componentId>'` matching a dictionary key.
- **`STAGES` Array**: Guided 5–7 stage learning journey.
  Each entry has: `title`, `camera: { pos: [x,y,z], target: [x,y,z] }`, `highlight: '<componentId>'`, `narration`, and optional `params`.

### 9. Web Speech API Narration Engine
- Feature detect `window.speechSynthesis`.
- Always call `speechSynthesis.cancel()` before speaking new narration, on modal close, or when changing stages.
- **Sentence Chunking**: Long speech utterances (>15 seconds) are silently killed by Chromium browsers. Split narration into sentence chunks (`text.match(/[^.!?]+[.!?]+/g)`) and queue them as individual `SpeechSynthesisUtterance` instances.

### 10. Dynamic Physical Parameter Sliders
- Provide interactive `<input type="range">` controls in an accessible overlay panel.
- Wire input events to real-time physics variables, updating mesh positions/geometries, live HUD readouts, and governing equation values simultaneously.

### 11. Dual WebGL & CSS2D Render Loop
- In the animation loop:
  ```javascript
  function animate() {
    requestAnimationFrame(animate);
    const dt = Math.min(clock.getDelta(), 0.05); // Clamp dt to prevent physics tunneling
    updatePhysics(dt);
    controls.update();
    renderer.render(scene, camera);
    labelRenderer.render(scene, camera);
  }
  ```
- Keep resize listeners synchronized:
  ```javascript
  window.addEventListener('resize', () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
    labelRenderer.setSize(window.innerWidth, window.innerHeight);
  });
  ```

---

## Reflection & Self-Correction Workflow

When correcting an HTML simulation:

1. **Audit the Critique**:
   Carefully examine every reported failure. Determine which invariants are broken or incomplete.

2. **Inspect the `<head>`**:
   Verify that Tailwind CSS 3.4.17, KaTeX CSS, KaTeX JS, KaTeX `auto-render.min.js`, Three.js r128, OrbitControls, and CSS2DRenderer are all loaded in `<head>`.

3. **Check the Stylesheet**:
   Ensure `#css2d-container` has `pointer-events: none` and `.label-badge` has `pointer-events: auto`.

4. **Verify Mathematical Formatting**:
   Ensure all variable descriptions and formula cards contain properly delimited math (e.g. `$P_1$`, `$m_1$`), that `renderMathInElement` with single-dollar delimiters is invoked, and that dynamic DOM updates are watched by a `MutationObserver`.

5. **Verify JavaScript Registries & Loops**:
   Check that `COMPONENTS`, `STAGES`, speech narration functions, physics sliders, and the dual render loop (`renderer.render` + `labelRenderer.render`) are intact and functional.

6. **Deliver Complete Production Code**:
   Output the full, revised, and unified `.html` file inside an ```html code block. Do NOT truncate code, do NOT use placeholder comments like `/* rest of code unchanged */`, and do NOT omit required libraries.
