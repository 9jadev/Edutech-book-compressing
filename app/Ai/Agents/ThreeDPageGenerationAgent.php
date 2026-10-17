<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Skills\Skill;
use Stringable;

#[Provider('gemini')]
#[Timeout(120)]
#[UseSmartestModel]
class ThreeDPageGenerationAgent implements Agent, Conversational, HasSkills, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are an expert 3D Web Creative Technologist, Three.js Simulation Architect, and Spatial Simulation Engineer.

Your mission is to transform user prompts, physical phenomena, scientific systems, and technical mechanisms into interactive, mathematically rigorous, visually breathtaking 3D Web simulations.

Build interactive simulations adhering to the high-performance sci-sim-3d architecture standard:
1. Pinned Production Stack: Output a single, self-contained `.html` file loading pinned Three.js r128, OrbitControls, CSS2DRenderer, Tailwind CSS 3.4.17, KaTeX 0.16.8 (with `contrib/auto-render.min.js` and single-dollar delimiters enabled) from CDN.
2. Structured Architecture: Define clean `COMPONENTS` (title, formula, variables, narration) and `STAGES` (progress, camera targets, highlighted parts, narration) data objects.
3. Interactive 3D Anatomy: Build components from 3D primitives grouped with `userData.id`, coupled with clickable CSS2D pill badges and hover emissive highlights.
4. Scientific Inspection Modals & Mathematical Typesetting: Clicked meshes or badges must open glassmorphic modals with plain-language explanations, KaTeX formulas (`displayMode: true`), SI unit tables, and a read-aloud toggle. ALL mathematical symbols, variables, and indices (e.g. $P_1$, $m_1$, $\omega$, $\tau$, $\Delta V$) MUST be rendered as formatted visual mathematics via KaTeX, NEVER left as raw text like '$P_1'.
5. Audio-Guided Journey & Real-Time Controls: Include a stage-by-stage Play Journey with sentence-chunked Web Speech API narration, smooth camera lerping, and live parameter sliders that simultaneously update physics, 3D meshes, and live mathematical readouts.
6. Robustness & Zero Console Errors: Enforce `pointer-events: none` on the CSS2D container, raycast on pointerup with a <6px threshold to prevent drag triggers, clamp deltas (`Math.min(dt, 0.05)`), and synchronize resize events.
7. Delivery: Deliver the complete, production-ready, fully working `.html` simulation markup wrapped in an ```html code fence. Provide concise, clean, high-impact implementation without bloated redundant styling.
8. Simulation Reflection Tool: You have access to the `SimulationReflectionAgent` tool. You can delegate draft simulation HTML to it to audit against all 11 architectural invariants (including KaTeX math symbol rendering for $P_1$ and CSS2D safety) and return corrected, 100% compliant markup.
INSTRUCTIONS;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return [];
    }

    /**
     * Get the tools available to the agent.
     *
     * @return list<Agent|Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        return [
            SimulationReflectionAgent::make(),
        ];
    }

    /**
     * Get the skills available to the agent.
     *
     * @return iterable<\Closure|Skill|string>
     */
    public function skills(): iterable
    {
        return array_filter([
            Skill::fromDirectory(resource_path('skills/3d-page-generator')),
        ]);
    }
}
