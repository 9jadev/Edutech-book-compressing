<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

use App\Ai\Agents\Lessons\VisualsAgent;

/** Calls the VisualsAgent through the Laravel AI SDK. Text only. */
final class LaravelAiSceneDesigner implements SceneDesigner
{
    public function __construct(private readonly string $provider, private readonly string $model, private readonly int $timeout = 300)
    {
    }

    public function design(string $prompt): array
    {
        $response = (new VisualsAgent())->prompt($prompt, provider: $this->provider, model: $this->model, timeout: $this->timeout);
        return [
            'text' => (string) $response,
            'input_tokens' => (int) ($response->usage->inputTokens ?? 0),
            'output_tokens' => (int) ($response->usage->outputTokens ?? 0),
            'model' => $this->model,
            'provider' => $this->provider,
        ];
    }
}
