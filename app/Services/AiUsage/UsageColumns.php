<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * laravel/ai 1.0 reports inclusive totals (input includes cache read/write,
 * output includes reasoning). ai_usage_records keeps the 0.10 exclusive
 * columns, which AiUsageReporting prices one tier each — mapping here keeps
 * historical and new rows on the same cost basis.
 */
final class UsageColumns
{
    /**
     * @return array{prompt_tokens: int, completion_tokens: int, cache_read_input_tokens: int, cache_write_input_tokens: int, reasoning_tokens: int}
     */
    public static function fromText(TextUsage $textUsage): array
    {
        $reasoning = $textUsage->reasoningTokens ?? 0;

        return [
            'prompt_tokens' => max(0, $textUsage->uncachedInputTokens()),
            'completion_tokens' => max(0, $textUsage->outputTokens - $reasoning),
            'cache_read_input_tokens' => $textUsage->cacheReadInputTokens ?? 0,
            'cache_write_input_tokens' => $textUsage->cacheWriteInputTokens ?? 0,
            'reasoning_tokens' => $reasoning,
        ];
    }
}
