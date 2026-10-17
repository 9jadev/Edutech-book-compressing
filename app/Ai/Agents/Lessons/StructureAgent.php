<?php

declare(strict_types=1);

namespace App\Ai\Agents\Lessons;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Step 2 — Structure. Reads a few textbook pages (images + text) and lists everything
 * printed on them, in order, as JSON. The instructions live in
 * resources/prompts/lessons/structure-system.md so you can tune them without code changes.
 *
 * JSON is requested in the prompt (not with HasStructuredOutput) because Qwen does not
 * support strict JSON schema with image input. Our StructureValidator checks the shape.
 */
class StructureAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $path = (string) config('lessons.structure.prompt_path', resource_path('prompts/lessons/structure-system.md'));
        return (string) file_get_contents($path);
    }

    /**
     * Qwen (Alibaba Model Studio): turn off thinking and ask for JSON mode.
     * Other providers: nothing extra; the prompt already demands JSON.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;
        $qwen = (array) config('lessons.structure.qwen_providers', ['qwen']);

        if (in_array($name, $qwen, true)) {
            return [
                'enable_thinking' => false,
                'response_format' => ['type' => 'json_object'],
            ];
        }
        return [];
    }
}
