<?php

declare(strict_types=1);

namespace App\Ai\Agents\Lessons;

use App\Services\Lessons\Write\ContentContract;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 4 — Write. Writes one lesson page (notes, narration, practice) from its plan, as JSON.
 * Instructions: resources/prompts/lessons/write-system.md + the content contract (ContentContract::example()).
 */
class WriteAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $path = (string) config('lessons.write.prompt_path', resource_path('prompts/lessons/write-system.md'));
        return str_replace('{{CONTRACT}}', ContentContract::example(), (string) file_get_contents($path));
    }

    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;
        $alibaba = (array) config('lessons.write.alibaba_providers', ['qwen', 'openai-compatible']);

        return in_array($name, $alibaba, true) ? (array) config('lessons.write.provider_options', []) : [];
    }
}
