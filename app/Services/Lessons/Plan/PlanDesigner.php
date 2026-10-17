<?php

declare(strict_types=1);

namespace App\Services\Lessons\Plan;

/** Sends one plan prompt to a model. Swap in a fake for tests. */
interface PlanDesigner
{
    /** @return array{text:string, input_tokens:int, output_tokens:int, model:string, provider:string} */
    public function design(string $prompt): array;
}
