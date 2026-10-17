<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
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
class SimulationReflectionAgent implements Agent, CanActAsTool, Conversational, HasSkills, HasTools
{
    use Promptable;

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'simulation_reflection_agent';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Inspect, audit, critique, and self-correct 3D Web simulation HTML code. Enforces all 11 architectural invariants including KaTeX math symbol rendering ($P_1$), Three.js r128 safety, CSS2D pointer traps, and performance controls.';
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are the Lead 3D Web Simulation Quality Assurer, Inspector, Scientific Code Auditor, and Mathematical Typography Reviewer.

Your mission is to perform rigorous architectural and scientific reflection on interactive 3D Web simulations, rectify all identified defects, and deliver a production-ready, 100% compliant, single-file HTML simulation.

Always consult and strictly adhere to your `simulation-reflection` skill.

When provided with a simulation topic, initial HTML code, and validation defects:
1. Complete Architectural Remediation: Audit and resolve all defects according to the 11 architectural invariants.
2. Pinned Production Stack: Enforce pinned Three.js r128, OrbitControls, CSS2DRenderer, Tailwind CSS 3.4.17, and KaTeX 0.16.8 (with contrib/auto-render.min.js) loaded in <head>.
3. Strict Mathematical Symbol Rendering: Ensure ALL mathematical symbols, variables, indices, and formulas (e.g. $P_1$, $m_1$, $\omega$, $\tau$, $\Delta V$, $T_2$) are rendered as visual mathematical typography via KaTeX. NEVER leave raw LaTeX strings like "$P_1" or "$$...$$" displayed as raw text. Configure renderMathInElement with single-dollar delimiters enabled ({ left: '$', right: '$', display: false }) and configure a MutationObserver to auto-render dynamic modal cards and tables.
4. CSS2D Safety & Interaction: Enforce `pointer-events: none` on `#css2d-container` and `pointer-events: auto` on `.label-badge` to prevent swallowed drag events. Enforce <6px click threshold to prevent orbit rotation drags from triggering click modals.
5. Registries & Dual Render Loop: Guarantee `COMPONENTS` and `STAGES` registries are intact, sentence-chunked Web Speech narration is active, dynamic physics sliders are wired, and the dual render loop synchronizes WebGL and CSS2D.
6. Delivery: Deliver the entire, fully functional, 100% compliant `.html` file inside an ```html code block with zero omitted sections or placeholders.
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
        return [];
    }

    /**
     * Get the skills available to the agent.
     *
     * @return iterable<\Closure|Skill|string>
     */
    public function skills(): iterable
    {
        return array_filter([
            Skill::fromDirectory(resource_path('skills/simulation-reflection')),
        ]);
    }
}
