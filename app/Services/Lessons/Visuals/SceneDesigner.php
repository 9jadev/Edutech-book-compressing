<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

/** Sends one page prompt to a model. Swap in a fake for tests. */
interface SceneDesigner
{
    /** @return array{text:string, input_tokens:int, output_tokens:int, model:string, provider:string} */
    public function design(string $prompt): array;
}
