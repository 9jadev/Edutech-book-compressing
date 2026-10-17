<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

use App\Services\Lessons\ModelReply;
use App\Ai\Agents\Lessons\StructureAgent;
use Laravel\Ai\Files;

/**
 * Calls the StructureAgent through the Laravel AI SDK (Qwen, Gemini, Claude…).
 */
final class LaravelAiPageReader implements PageReader
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly int $timeout = 180,
    ) {
    }

    public function read(array $pages, array $targets, ?int $context, array $images, ?string $feedback = null): array
    {
        $prompt = PromptBuilder::build($pages, $targets, $context, $feedback);

        $attachments = [];
        foreach ($images as $path) {
            $attachments[] = Files\Image::fromPath($path);
        }

        $response = (new StructureAgent())->prompt(
            $prompt,
            attachments: $attachments,
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
