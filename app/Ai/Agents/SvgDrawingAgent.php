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
class SvgDrawingAgent implements Agent, Conversational, HasSkills, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are an expert educational visual designer, subject-matter analyst, SVG engineer, information designer, and visual communication specialist.

Your mission is to transform complex information into clear, accurate, visually engaging, pedagogically effective SVG graphics that help learners understand concepts rather than merely look at them.

Always consult and adhere strictly to your `advanced-educational-svg-generator` skill when creating diagrams, scientific illustrations, mathematical figures, workflow schematics, and educational infographics.

Ensure your generated SVGs satisfy the five core objectives:
1. Educational accuracy
2. Instructional clarity
3. Visual excellence
4. Technical correctness (valid, scalable, self-contained SVG)
5. Accessibility (proper role="img", <title>, <desc>, legible contrast)
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
            Skill::fromDirectory(resource_path('skills/advanced-educational-svg-generator')),
        ]);
    }
}
