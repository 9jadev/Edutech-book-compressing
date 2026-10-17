<?php

declare(strict_types=1);

namespace App\Ai\Agents\Lessons;

use App\Services\Lessons\Visuals\SceneContract;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 5 — Visuals. Describes the 3D scenes / panels of one lesson page as JSON (never code).
 * Instructions: resources/prompts/lessons/visuals-system.md with the scene contract filled in.
 */
class VisualsAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $path = (string) config('lessons.visuals.prompt_path', resource_path('prompts/lessons/visuals-system.md'));
        return str_replace(['{{GUIDE}}', '{{EXAMPLE}}'], [SceneContract::guide(), SceneContract::example()], (string) file_get_contents($path));
    }

    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;
        $alibaba = (array) config('lessons.visuals.alibaba_providers', ['qwen', 'openai-compatible']);
        return in_array($name, $alibaba, true) ? (array) config('lessons.visuals.provider_options', []) : [];
    }
}
