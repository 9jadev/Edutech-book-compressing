<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class PageReaderProbe implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): string
    {
        return 'You read textbook page images. Reply with JSON only, in this shape: '
             . '{"heading": string|null, "figures": [string], "labels_in_figures": [string], "first_sentence": string}';
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $provider === 'qwen'
            ? ['enable_thinking' => false, 'response_format' => ['type' => 'json_object']]
            : [];
    }
}
