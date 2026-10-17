<?php

declare(strict_types=1);

namespace App\Services\Lessons;

/**
 * Small helpers shared by the AI steps (structure, plan, write) for what a model call returned.
 *
 *  - finishReason(): why the model stopped. "length" means it hit its output-token limit and the reply is cut off.
 *  - truncation():   a clear error for a cut-off reply, instead of a confusing "invalid JSON" one.
 *  - logFields():    extra usage.jsonl fields: finish_reason always, and the raw reply when the call failed.
 */
final class ModelReply
{
    /** Longest raw reply kept in usage.jsonl for a failed call. */
    public const MAX_SAVED_CHARS = 20000;

    public static function finishReason(object $response): ?string
    {
        $step = isset($response->steps) ? $response->steps->last() : null;

        return $step?->finishReason?->value;
    }

    /**
     * @param  array<string, mixed>|null  $reply
     */
    public static function truncation(?array $reply): ?string
    {
        if (($reply['finish_reason'] ?? null) !== 'length') {
            return null;
        }

        return 'Reply cut off: the model hit its output-token limit after '.(int) ($reply['output_tokens'] ?? 0)
            .' output tokens (finish_reason=length), so the JSON is incomplete. Send fewer pages per call or raise the model\'s max output tokens.';
    }

    /**
     * @param  array<string, mixed>|null  $reply
     * @return array<string, mixed>
     */
    public static function logFields(?array $reply, bool $ok): array
    {
        $fields = ['finish_reason' => $reply['finish_reason'] ?? null];

        if (! $ok && isset($reply['text'])) {
            $text = (string) $reply['text'];
            $fields['reply'] = mb_strlen($text) > self::MAX_SAVED_CHARS
                ? mb_substr($text, 0, self::MAX_SAVED_CHARS).'… ['.(mb_strlen($text) - self::MAX_SAVED_CHARS).' more characters]'
                : $text;
        }

        return $fields;
    }
}
