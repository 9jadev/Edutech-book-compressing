<?php

declare(strict_types=1);

namespace App\Services\Lessons\Write;

use App\Services\Lessons\ModelReply;
use App\Ai\Agents\Lessons\StructuredWriteAgent;
use App\Ai\Agents\Lessons\WriteAgent;

/**
 * Calls the WriteAgent through the Laravel AI SDK.
 * $structured = true uses StructuredWriteAgent (JSON schema enforced by the provider: Gemini, Claude, OpenAI).
 * Alibaba Model Studio does not enforce a schema here, so for Qwen/DeepSeek leave it false:
 * the same contract is then enforced by ContentValidator + ContentChecker.
 */
final class LaravelAiPageWriter implements PageWriter
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly int $timeout = 300,
        private readonly bool $structured = false,
    ) {
    }

    public function write(string $prompt): array
    {
        $agent = $this->structured ? new StructuredWriteAgent() : new WriteAgent();
        $response = $agent->prompt($prompt, provider: $this->provider, model: $this->model, timeout: $this->timeout);

        // Structured responses are array-like; turn them back into JSON text so one validator handles both.
        $text = $this->structured && $response instanceof \ArrayAccess && method_exists($response, 'toArray')
            ? (string) json_encode($response->toArray(), JSON_UNESCAPED_UNICODE)
            : (string) $response;

        return [
            'text' => $text,
            'input_tokens' => (int) ($response->usage->inputTokens ?? 0),
            'output_tokens' => (int) ($response->usage->outputTokens ?? 0),
            'finish_reason' => ModelReply::finishReason($response),
            'model' => $this->model,
            'provider' => $this->provider,
        ];
    }
}
