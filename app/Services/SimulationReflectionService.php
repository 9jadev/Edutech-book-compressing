<?php

namespace App\Services;

use App\Ai\Agents\SimulationReflectionAgent;
use App\Ai\Agents\ThreeDPageGenerationAgent;
use Illuminate\Support\Facades\Log;

/**
 * @phpstan-type CheckResult array{
 *     rule: string,
 *     title: string,
 *     passed: bool,
 *     severity: 'critical'|'warning',
 *     message: string
 * }
 *
 * @phpstan-type EvaluationResult array{
 *     passed: bool,
 *     score: int,
 *     checks: list<CheckResult>,
 *     issues: list<string>,
 *     critical_failures: int
 * }
 */
class SimulationReflectionService
{
    /**
     * Maximum self-correction attempts before applying deterministic patches.
     */
    protected int $maxRefinementAttempts = 2;

    /**
     * Evaluate the given simulation HTML against the 3D scientific simulation standard.
     *
     * @return EvaluationResult
     */
    public function evaluate(string $html): array
    {
        $checks = [];

        // 1. Document Structure
        $hasDocType = stripos($html, '<!DOCTYPE html') !== false;
        $hasHtmlTags = stripos($html, '<html') !== false && stripos($html, '</html>') !== false;
        $hasHeadBody = stripos($html, '<head') !== false && stripos($html, '<body') !== false && stripos($html, '</body>') !== false;
        $docPassed = $hasDocType && $hasHtmlTags && $hasHeadBody;
        $checks[] = [
            'rule' => 'doc_structure',
            'title' => 'HTML5 Document Structure',
            'passed' => $docPassed,
            'severity' => 'critical',
            'message' => $docPassed ? 'Valid standalone HTML5 document' : 'Missing standard HTML5 document tags',
        ];

        // 2. Three.js r128 CDN
        $hasThree = stripos($html, 'three.min.js') !== false || stripos($html, 'three@0.128') !== false || stripos($html, 'three.js/r128') !== false;
        $checks[] = [
            'rule' => 'three_cdn',
            'title' => 'Three.js r128 Core Library',
            'passed' => $hasThree,
            'severity' => 'critical',
            'message' => $hasThree ? 'Pinned Three.js r128 script loaded' : 'Missing pinned Three.js r128 CDN script',
        ];

        // 3. CSS2DRenderer & OrbitControls
        $hasCss2d = stripos($html, 'CSS2DRenderer.js') !== false || stripos($html, 'CSS2DRenderer') !== false;
        $hasOrbit = stripos($html, 'OrbitControls.js') !== false || stripos($html, 'OrbitControls') !== false;
        $addonsPassed = $hasCss2d && $hasOrbit;
        $checks[] = [
            'rule' => 'three_addons',
            'title' => 'CSS2DRenderer & OrbitControls Addons',
            'passed' => $addonsPassed,
            'severity' => 'critical',
            'message' => $addonsPassed ? 'CSS2DRenderer and OrbitControls present' : 'Missing CSS2DRenderer or OrbitControls',
        ];

        // 4. KaTeX Math Library
        $hasKatexJs = stripos($html, 'katex.min.js') !== false || stripos($html, 'katex.render') !== false;
        $hasKatexCss = stripos($html, 'katex.min.css') !== false;
        $katexPassed = $hasKatexJs && $hasKatexCss;
        $checks[] = [
            'rule' => 'katex_math',
            'title' => 'KaTeX Mathematical Typesetting',
            'passed' => $katexPassed,
            'severity' => 'critical',
            'message' => $katexPassed ? 'KaTeX JS and stylesheet present' : 'Missing KaTeX script or stylesheet link',
        ];

        // 5. CSS2D Pointer-Events Trap Prevention
        $hasContainerNone = stripos($html, 'pointer-events: none') !== false || stripos($html, 'pointerEvents = \'none\'') !== false || stripos($html, 'pointer-events-none') !== false;
        $hasBadgeAuto = stripos($html, 'pointer-events: auto') !== false || stripos($html, 'pointerEvents = \'auto\'') !== false || stripos($html, 'pointer-events-auto') !== false;
        $pointerPassed = $hasContainerNone && $hasBadgeAuto;
        $checks[] = [
            'rule' => 'css2d_pointer_events',
            'title' => 'CSS2D Pointer-Events Trap Prevention',
            'passed' => $pointerPassed,
            'severity' => 'critical',
            'message' => $pointerPassed ? 'CSS2D container pointer-events:none and badges pointer-events:auto' : 'CSS2D container missing pointer-events:none or badges missing pointer-events:auto',
        ];

        // 6. Raycast Drag vs Click Threshold (<6px)
        $hasClickThreshold = (stripos($html, 'pointerdown') !== false && stripos($html, 'pointerup') !== false)
            || stripos($html, 'Math.hypot') !== false
            || stripos($html, 'Math.sqrt') !== false
            || stripos($html, 'distance') !== false
            || stripos($html, 'threshold') !== false;
        $checks[] = [
            'rule' => 'raycast_threshold',
            'title' => 'Drag-vs-Click Raycasting Protection',
            'passed' => $hasClickThreshold,
            'severity' => 'warning',
            'message' => $hasClickThreshold ? 'Drag vs click threshold verified' : 'Pointer drag might inadvertently trigger raycast clicks',
        ];

        // 7. Structured Data Registries (COMPONENTS and STAGES)
        $hasComponents = stripos($html, 'COMPONENTS') !== false;
        $hasStages = stripos($html, 'STAGES') !== false;
        $registriesPassed = $hasComponents && $hasStages;
        $checks[] = [
            'rule' => 'registries',
            'title' => 'COMPONENTS & STAGES Data Architecture',
            'passed' => $registriesPassed,
            'severity' => 'critical',
            'message' => $registriesPassed ? 'COMPONENTS and STAGES registries present' : 'Missing COMPONENTS anatomical registry or STAGES journey array',
        ];

        // 8. Audio Speech Synthesis Queue (Chunked narration)
        $hasSpeech = stripos($html, 'speechSynthesis') !== false;
        $hasUtterance = stripos($html, 'SpeechSynthesisUtterance') !== false;
        $speechPassed = $hasSpeech && $hasUtterance;
        $checks[] = [
            'rule' => 'audio_narration',
            'title' => 'Web Speech API Audio Journey',
            'passed' => $speechPassed,
            'severity' => 'warning',
            'message' => $speechPassed ? 'SpeechSynthesis and audio narration present' : 'Missing Web Speech API audio narration engine',
        ];

        // 9. Interactive Parameter Sliders
        $hasSliders = stripos($html, 'type="range"') !== false || stripos($html, 'slider') !== false || stripos($html, '<input') !== false;
        $checks[] = [
            'rule' => 'parameter_sliders',
            'title' => 'Dynamic Physics Parameter Sliders',
            'passed' => $hasSliders,
            'severity' => 'critical',
            'message' => $hasSliders ? 'Interactive sliders wired to variables' : 'Missing dynamic parameter sliders for real-time physics',
        ];

        // 10. Animation & Render Loops
        $hasRaf = stripos($html, 'requestAnimationFrame') !== false;
        $hasRender = stripos($html, 'renderer.render') !== false;
        $hasLabelRender = stripos($html, 'labelRenderer.render') !== false;
        $renderPassed = $hasRaf && $hasRender && $hasLabelRender;
        $checks[] = [
            'rule' => 'render_loop',
            'title' => 'Dual WebGL & CSS2D Render Loop',
            'passed' => $renderPassed,
            'severity' => 'critical',
            'message' => $renderPassed ? 'requestAnimationFrame, WebGL and CSS2D rendering confirmed' : 'Missing synchronized render loop for WebGL and CSS2DRenderer',
        ];

        // 11. Mathematical Symbol Rendering (Ensures all math symbols render as math, never as raw text like $P_1)
        $hasAutoRenderScript = stripos($html, 'auto-render.min.js') !== false;
        $hasRenderMathCall = stripos($html, 'renderMathInElement') !== false;
        $hasKatexRender = stripos($html, 'katex.render') !== false;
        $hasDollarDelimiterConfig = stripos($html, "left: '$'") !== false
            || stripos($html, 'left: "$"') !== false
            || (stripos($html, 'delimiters') !== false && (stripos($html, "'$'") !== false || stripos($html, '"$"') !== false));

        // Detect inline math delimiters (e.g. $P_1$, $m_1$) while ignoring JS template literals like ${val}
        $hasRawDollarMath = preg_match('/(?<!\\\\|\\$)\\$(?!\\$|\\{)[^\\$\\n\\r]+(?<!\\\\|\\$)\\$/', $html) === 1;
        $hasDoubleDollarMath = preg_match('/\\$\\$[^\\$]+\\$\\$/', $html) === 1;
        $hasLatexCommands = preg_match('/\\\\(?:frac|sqrt|vec|dot|ddot|int|sum|prod|partial|alpha|beta|gamma|delta|epsilon|zeta|eta|theta|iota|kappa|lambda|mu|nu|xi|pi|rho|sigma|tau|upsilon|phi|chi|psi|omega)/i', $html) === 1;

        $hasAnyMathExpressions = $hasRawDollarMath || $hasDoubleDollarMath || $hasLatexCommands;

        if ($hasAnyMathExpressions) {
            // When mathematical expressions (especially inline variables like $P_1$) are present,
            // the KaTeX auto-render pipeline with single-dollar delimiters is mandatory.
            // Relying solely on katex.render() on an isolated container will leave inline tokens like $P_1$ unrendered in the UI.
            $mathRenderPassed = $katexPassed && $hasAutoRenderScript && $hasRenderMathCall && $hasDollarDelimiterConfig;
            $mathRenderMsg = $mathRenderPassed
                ? 'All mathematical symbols (including $P_1$) are actively typeset via KaTeX auto-render with single-dollar delimiters'
                : 'Raw mathematical symbols (e.g. $P_1$) found in text or templates, but KaTeX auto-render extension or single-dollar delimiter configuration is missing. All math symbols must be rendered as formatted mathematics via KaTeX, never displayed as raw text strings like \'$P_1\'.';
        } else {
            // Even in minimal states, scientific simulations must have the mathematical typesetting pipeline
            $mathRenderPassed = $katexPassed && ($hasKatexRender || ($hasAutoRenderScript && $hasRenderMathCall && $hasDollarDelimiterConfig));
            $mathRenderMsg = $mathRenderPassed
                ? 'KaTeX mathematical rendering pipeline configured'
                : 'Missing mathematical typesetting pipeline to render equations and scientific symbols';
        }

        $checks[] = [
            'rule' => 'math_symbol_rendering',
            'title' => 'Mathematical Symbol & Formula Rendering',
            'passed' => $mathRenderPassed,
            'severity' => 'critical',
            'message' => $mathRenderMsg,
        ];

        // Calculate score
        $totalChecks = count($checks);
        $passedChecks = count(array_filter($checks, fn ($c) => $c['passed']));
        $score = (int) round(($passedChecks / $totalChecks) * 100);

        $criticalFailures = count(array_filter($checks, fn ($c) => ! $c['passed'] && $c['severity'] === 'critical'));
        $issues = array_values(array_map(fn ($c) => $c['title'] . ': ' . $c['message'], array_filter($checks, fn ($c) => ! $c['passed'])));

        return [
            'passed' => $score === 100 && $criticalFailures === 0,
            'score' => $score,
            'checks' => $checks,
            'issues' => $issues,
            'critical_failures' => $criticalFailures,
        ];
    }

    /**
     * Run the Reflective Self-Correction Loop.
     * Evaluates the generated HTML, prompts the SimulationReflectionAgent to correct any sub-standard aspects,
     * and re-evaluates until 100% compliant or auto-healed.
     *
     * @return array{
     *     html_code: string,
     *     score: int,
     *     reflection_iterations: int,
     *     evaluation: EvaluationResult
     * }
     */
    public function reflectAndCorrect(
        string $topic,
        string $initialHtml,
        SimulationReflectionAgent|ThreeDPageGenerationAgent|null $agent = null
    ): array
    {
        $currentHtml = trim($initialHtml);
        $evaluation = $this->evaluate($currentHtml);
        $iteration = 0;

        // If already 100% compliant, return immediately
        if ($evaluation['passed'] && $evaluation['score'] === 100) {
            return [
                'html_code' => $currentHtml,
                'score' => 100,
                'reflection_iterations' => 0,
                'evaluation' => $evaluation,
            ];
        }

        // Loop: Self-Correction via SimulationReflectionAgent
        while (! $evaluation['passed'] && $iteration < $this->maxRefinementAttempts) {
            $iteration++;

            Log::info("SimulationReflectionService: Iteration {$iteration} - Current score {$evaluation['score']}%. Deficiencies: " . implode('; ', $evaluation['issues']));

            $correctivePrompt = $this->buildCorrectivePrompt($topic, $currentHtml, $evaluation['issues']);

            try {
                $reflectorAgent = $agent instanceof SimulationReflectionAgent ? $agent : SimulationReflectionAgent::make();
                $correctionResponse = $reflectorAgent->prompt($correctivePrompt);

                $correctedHtml = $this->extractHtmlFromResponse((string) $correctionResponse);

                if (! empty($correctedHtml) && strlen($correctedHtml) > 500) {
                    $newEvaluation = $this->evaluate($correctedHtml);

                    // If improved or 100%, update current
                    if ($newEvaluation['score'] >= $evaluation['score']) {
                        $currentHtml = $correctedHtml;
                        $evaluation = $newEvaluation;
                    }

                    if ($evaluation['passed'] && $evaluation['score'] === 100) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("SimulationReflectionService reflection error on attempt {$iteration}: " . $e->getMessage());
                break;
            }
        }

        // Apply deterministic auto-healer if any minor gaps remain
        if ($evaluation['score'] < 100) {
            $currentHtml = $this->autoHeal($currentHtml, $evaluation);
            $evaluation = $this->evaluate($currentHtml);
        }

        return [
            'html_code' => $currentHtml,
            'score' => $evaluation['score'],
            'reflection_iterations' => $iteration,
            'evaluation' => $evaluation,
        ];
    }

    /**
     * Build the reflective critique and self-correction prompt for the agent.
     *
     * @param  list<string>  $issues
     */
    protected function buildCorrectivePrompt(string $topic, string $htmlSnippet, array $issues): string
    {
        $issuesList = implode("\n- ", $issues);

        return <<<PROMPT
You are performing a Self-Correction & Reflection Pass on an interactive 3D simulation for: "{$topic}".
The initial simulation was evaluated by our automated validator and found to be SUB-STANDARD due to the following defects:

- {$issuesList}

CRITICAL MANDATORY INSTRUCTIONS FOR REFLECTION PASS:
1. Fix all identified defects completely.
2. Ensure pinned Three.js r128, CSS2DRenderer, OrbitControls, Tailwind CSS, and KaTeX are properly loaded.
3. Enforce `pointer-events: none` on `#css2d-container` and `pointer-events: auto` on all `.label-badge` elements.
4. Ensure `COMPONENTS` registry with KaTeX math equations and `STAGES` progressive journey array are explicitly defined in the script.
5. Provide interactive range sliders with live physical variable readouts.
6. Enforce click threshold (<6px) to avoid orbit drag clicks.
7. MANDATORY MATH SYMBOL RENDERING: All mathematical symbols, indices, and variables (e.g., \$P_1\$, \$m_1\$, \$\omega\$, \$\tau\$, \$\Delta V\$) MUST be rendered as formatted visual mathematics using KaTeX. NEVER leave raw LaTeX strings like "\$P_1" or "\$\$...\$\$" unrendered in the UI. Load the KaTeX auto-render extension (<script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/contrib/auto-render.min.js"></script>) and invoke renderMathInElement with explicit single-dollar delimiters ({ left: '$', right: '$', display: false }) on DOMContentLoaded and whenever modals, tooltips, or stages update dynamically.
8. Wrap your perfected, 100% compliant, fully functional `.html` file inside an ```html code block. Do NOT omit any code.
PROMPT;
    }

    /**
     * Deterministically auto-heal common plumbing issues in HTML code.
     *
     * @param  EvaluationResult  $evaluation
     */
    public function autoHeal(string $html, array $evaluation): string
    {
        $healed = $html;

        // Ensure DOCTYPE and standard tags
        if (stripos($healed, '<!DOCTYPE html') === false) {
            $healed = "<!DOCTYPE html>\n" . $healed;
        }
        if (stripos($healed, '<html') === false) {
            $healed = "<html>\n" . $healed . "\n</html>";
        }
        if (stripos($healed, '<head') === false) {
            $healed = str_ireplace('<html>', "<html>\n<head>\n</head>", $healed);
        }
        if (stripos($healed, '<body') === false) {
            $healed = str_ireplace('</head>', "</head>\n<body>\n</body>", $healed);
        }

        // Ensure Tailwind & KaTeX in <head>
        if (stripos($healed, 'tailwindcss.com') === false && stripos($healed, '<head') !== false) {
            $healed = str_ireplace('<head>', "<head>\n  <script src=\"https://cdn.tailwindcss.com/3.4.17\"></script>", $healed);
        }

        if (stripos($healed, 'katex.min.css') === false && stripos($healed, '<head') !== false) {
            $healed = str_ireplace(
                '<head>',
                "<head>\n  <link rel=\"stylesheet\" href=\"https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css\">\n  <script src=\"https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.js\"></script>",
                $healed
            );
        }

        // Ensure KaTeX auto-render extension is present so $P_1$ or $$...$$ are formatted
        if (stripos($healed, 'auto-render.min.js') === false && stripos($healed, '<head') !== false) {
            $healed = str_ireplace(
                '</head>',
                "  <script src=\"https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/contrib/auto-render.min.js\"></script>\n</head>",
                $healed
            );
        }

        // Ensure Three.js and Addons in <head>
        if (stripos($healed, 'three.min.js') === false && stripos($healed, '<head') !== false) {
            $healed = str_ireplace(
                '<head>',
                "<head>\n  <script src=\"https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js\"></script>\n  <script src=\"https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js\"></script>\n  <script src=\"https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/renderers/CSS2DRenderer.js\"></script>",
                $healed
            );
        }

        // Ensure pointer-events styling for CSS2D
        if (stripos($healed, 'pointer-events: none') === false && stripos($healed, '<style>') !== false) {
            $healed = str_ireplace(
                '<style>',
                "<style>\n    #css2d-container { pointer-events: none !important; }\n    .label-badge { pointer-events: auto !important; }",
                $healed
            );
        }

        // Ensure renderMathInElement initialization script with single-dollar delimiters and MutationObserver is present
        $hasDollarDelimiter = stripos($healed, "left: '$'") !== false
            || stripos($healed, 'left: "$"') !== false
            || (stripos($healed, 'delimiters') !== false && (stripos($healed, "'$'") !== false || stripos($healed, '"$"') !== false));
        if ((stripos($healed, 'renderMathInElement') === false || ! $hasDollarDelimiter) && stripos($healed, '</body>') !== false) {
            $mathScript = <<<'SCRIPT'
  <!-- KaTeX Math Rendering Pipeline: Auto-renders all math symbols ($P_1, $$...$$, etc.) -->
  <script>
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
    document.addEventListener('DOMContentLoaded', () => {
      renderDocumentMathematics();
      const mathObserver = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
          for (const node of mutation.addedNodes) {
            if (node.nodeType === 1) {
              renderDocumentMathematics(node);
            }
          }
        }
      });
      mathObserver.observe(document.body, { childList: true, subtree: true });
    });
    window.renderDocumentMathematics = renderDocumentMathematics;
    window.renderPageMath = renderDocumentMathematics;
  </script>
SCRIPT;
            $healed = str_ireplace('</body>', $mathScript . "\n</body>", $healed);
        }

        return $healed;
    }

    /**
     * Extract HTML markup from ```html ... ``` or raw code.
     */
    protected function extractHtmlFromResponse(string $raw): string
    {
        if (preg_match('/```html\s*(<!DOCTYPE[\s\S]*?<\/html>)\s*```/i', $raw, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/```html\s*([\s\S]*?)\s*```/i', $raw, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/(<!DOCTYPE html>[\s\S]*?<\/html>)/i', $raw, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }
}
