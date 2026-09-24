<?php

declare(strict_types=1);

use App\Services\AiUsage\UsageColumns;
use Laravel\Ai\Responses\Data\TextUsage;

test('inclusive 1.0 usage maps onto the exclusive 0.10 columns', function (): void {
    // input 1000 incl. 600 cache-read + 100 cache-write; output 300 incl. 120 reasoning.
    $columns = UsageColumns::fromText(new TextUsage(1000, 300, 600, 100, 120));

    expect($columns)->toBe([
        'prompt_tokens' => 300,
        'completion_tokens' => 180,
        'cache_read_input_tokens' => 600,
        'cache_write_input_tokens' => 100,
        'reasoning_tokens' => 120,
    ]);
});

test('unreported optional counts are zero', function (): void {
    expect(UsageColumns::fromText(new TextUsage(50, 20)))->toBe([
        'prompt_tokens' => 50,
        'completion_tokens' => 20,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
    ]);
});
