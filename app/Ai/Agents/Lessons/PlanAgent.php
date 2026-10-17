<?php

declare(strict_types=1);

namespace App\Ai\Agents\Lessons;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 3 — Plan. Reads the chapter's structure items for a few lesson pages and plans the blocks,
 * visuals and practice for each page, as JSON. Instructions live in
 * resources/prompts/lessons/plan-system.md so you can tune them without code changes.
 */
class PlanAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $path = (string) config('lessons.plan.prompt_path', resource_path('prompts/lessons/plan-system.md'));
        return (string) file_get_contents($path);
    }

    /**
     * Alibaba Model Studio (DeepSeek or Qwen): extra request fields from config('lessons.plan.provider_options'),
     * e.g. thinking off + JSON mode. Other providers: nothing extra; the prompt already asks for JSON.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;
        $alibaba = (array) config('lessons.plan.alibaba_providers', ['qwen', 'openai-compatible']);

        return in_array($name, $alibaba, true) ? (array) config('lessons.plan.provider_options', []) : [];
    }
}
