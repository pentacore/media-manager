<?php

declare(strict_types=1);

use App\Enums\SecondOpinionMode;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;

test('an action rule defaults to no second opinion and casts the mode', function (): void {
    $actionTypeConfig = ActionTypeConfig::factory()->create(['type' => 'monitor_series']);

    expect($actionTypeConfig->fresh()->second_opinion)->toBe(SecondOpinionMode::Off);

    $actionTypeConfig->update(['second_opinion' => SecondOpinionMode::Reject]);
    expect($actionTypeConfig->fresh()->second_opinion)->toBe(SecondOpinionMode::Reject);
});

test('an action request stores its second opinion verdict', function (): void {
    $actionRequest = ActionRequest::factory()->secondOpinion(agrees: false, reason: 'Wrong series.')->create();

    expect($actionRequest->fresh()->second_opinion)->toMatchArray([
        'engine' => 'llm', 'mode' => 'hold', 'agrees' => false, 'failed' => false, 'reason' => 'Wrong series.',
    ])
        ->and(ActionRequest::factory()->create()->fresh()->second_opinion)->toBeNull();
});
