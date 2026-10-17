<?php

declare(strict_types=1);

namespace App\Services\Lessons\Structure;

/**
 * Anything that can read a group of pages and return the model's raw reply.
 * The real one is LaravelAiPageReader; tests can use a fake.
 */
interface PageReader
{
    /**
     * @param array<int,array<string,mixed>> $pages   pages.json entries for target + context pages
     * @param int[]                          $targets page numbers to list items for
     * @param ?int                           $context look-ahead page number (or null)
     * @param array<int,string>              $images  page number => local image path
     * @param ?string                        $feedback problems from the last attempt, to fix
     * @return array{text:string, input_tokens:int, output_tokens:int, model:string, provider:string}
     */
    public function read(array $pages, array $targets, ?int $context, array $images, ?string $feedback = null): array;
}
