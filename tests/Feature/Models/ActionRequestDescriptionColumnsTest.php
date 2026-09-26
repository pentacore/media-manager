<?php

declare(strict_types=1);

use App\Models\ActionRequest;

test('an action request stores and casts its description columns', function (): void {
    $actionRequest = ActionRequest::factory()->described()->create();

    $fresh = $actionRequest->fresh();

    expect($fresh->title)->toBe('Delete series "Severance (2022)"')
        ->and($fresh->description)->toContain('Sonarr will delete the series')
        ->and($fresh->details)->toBe([
            ['label' => 'Series', 'value' => 'Severance (2022)'],
            ['label' => 'Delete files', 'value' => 'Yes'],
        ])
        ->and($fresh->description_verified)->toBeTrue();
});

test('a legacy action request leaves the description columns null', function (): void {
    $fresh = ActionRequest::factory()->create()->fresh();

    expect($fresh->title)->toBeNull()
        ->and($fresh->description)->toBeNull()
        ->and($fresh->details)->toBeNull()
        ->and($fresh->description_verified)->toBeTrue();
});
