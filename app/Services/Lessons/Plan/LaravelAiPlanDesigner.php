<?php

declare(strict_types=1);

namespace App\Services\Lessons\Plan;

use App\Services\Lessons\ModelReply;
use App\Ai\Agents\Lessons\PlanAgent;

/** Calls the PlanAgent through the Laravel AI SDK (DeepSeek or Qwen on Alibaba, Gemini, Claude…). Text only. */
final class LaravelAiPlanDesigner implements PlanDesigner
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly int $timeout = 300,
    ) {
    }

    public function design(string $prompt): array
    {
        $response = (new PlanAgent())->prompt(
            $prompt,
            provider: $this->provider,
            model: $this->model,
            timeout: $this->timeout,
        );

        return [
            'text' => (string) $response,
            'input_tokens' => (int) ($response->usage->inputTokens ?? 0),
            'output_tokens' => (int) ($response->usage->outputTokens ?? 0),
            'finish_reason' => ModelReply::finishReason($response),
            'model' => $this->model,
            'provider' => $this->provider,
        ];
    }
}
