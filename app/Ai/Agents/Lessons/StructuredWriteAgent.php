<?php

declare(strict_types=1);

namespace App\Ai\Agents\Lessons;

use App\Services\Lessons\Write\ContentContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;

/**
 * Same as WriteAgent, but the provider itself enforces the JSON shape (enums for block types,
 * question kinds and step roles). Use with Gemini / Claude / OpenAI: LESSONS_WRITE_STRUCTURED=true.
 * Fields that only some block types or question kinds use are nullable; ContentValidator still
 * checks which ones are required, so both agents are held to the same contract.
 */
class StructuredWriteAgent extends WriteAgent implements HasStructuredOutput
{
    public function providerOptions(Lab|string $provider): array
    {
        return [];   // the schema replaces JSON mode
    }

    public function schema(JsonSchema $schema): array
    {
        $str = fn () => $schema->string();
        $strs = fn () => $schema->array()->items($schema->string());
        $say = fn () => $schema->string()->nullable();

        $step = $schema->object([
            'role' => $schema->string()->enum(ContentContract::STEP_ROLES)->nullable(),
            'text' => $str()->required(),
            'say' => $say(),
            'shows' => $say(),
        ]);
        $task = $schema->object([
            'do' => $str()->required(),
            'notice' => $say(),
            'say' => $say(),
        ]);
        $question = $schema->object([
            'n' => $schema->integer()->required(),
            'source' => $schema->string()->enum(['book', 'ours'])->required(),
            'item' => $say(),
            'kind' => $schema->string()->enum(ContentContract::KINDS)->required(),
            'prompt' => $str()->required(),
            'say' => $say(),
            'options' => $strs()->nullable(),
            'answer' => $schema->string()->nullable()->description('mcq: index of the correct option; true_false: "true"/"false"; numeric: the number'),
            'pairs' => $schema->array()->items($strs())->nullable(),
            'groups' => $schema->array()->items($schema->object([
                'name' => $str()->required(),
                'members' => $strs()->required(),
            ]))->nullable(),
            'sequence' => $strs()->nullable(),
            'targets' => $strs()->nullable(),
            'unit' => $say(),
            'tolerance' => $schema->number()->nullable(),
            'accept' => $strs()->nullable(),
            'model_answer' => $say(),
            'hint' => $str()->required(),
            'explain' => $str()->required(),
            'mistakes' => $schema->array()->items($schema->object([
                'wrong' => $str()->required(),
                'why' => $str()->required(),
            ]))->nullable(),
        ]);

        return [
            'page' => $str()->required(),
            'title' => $str()->required(),
            'intro' => $str()->required(),
            'objectives' => $strs()->required(),
            'blocks' => $schema->array()->items($schema->object([
                'id' => $str()->required(),
                'type' => $schema->string()->enum(ContentContract::BLOCK_TYPES)->required(),
                'heading' => $str()->required(),
                'intro' => $say(),
                'safety' => $say(),
                'rule' => $say(),
                'paragraphs' => $schema->array()->items($schema->object([
                    'text' => $str()->required(),
                    'say' => $say(),
                ]))->nullable(),
                'steps' => $schema->array()->items($step)->nullable(),
                'tasks' => $schema->array()->items($task)->nullable(),
                'questions' => $schema->array()->items($question)->nullable(),
            ]))->required(),
            'remember' => $strs()->required(),
            'teacher_note' => $schema->object([
                'scope' => $str()->required(),
                'additions' => $strs()->required(),
                'corrections' => $strs()->required(),
            ])->required(),
        ];
    }
}
