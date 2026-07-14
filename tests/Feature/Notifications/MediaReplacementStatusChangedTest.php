<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;

test('maps replacement levels to canonical severities', function (string $level, string $expected): void {
    $notification = new MediaReplacementStatusChanged('sonarr', 'Show S01E01', 'msg', $level);

    expect($notification->severityKey())->toBe($expected);
})->with([
    'verified is info' => ['info', 'info'],
    'needs attention is warning' => ['warning', 'warning'],
    'failed is error' => ['error', 'error'],
]);

test('array payload carries service, message, level, and the actions url', function (): void {
    $notification = new MediaReplacementStatusChanged('radarr', 'A Movie', 'Verified.', 'info');
    $user = User::factory()->create();

    expect($notification->toArray($user))->toMatchArray([
        'service' => 'radarr',
        'title' => 'A Movie',
        'message' => 'Verified.',
        'level' => 'info',
        'url' => '/actions/requests',
    ]);
});

test('routes through the preference resolver with database and broadcast on by default', function (): void {
    $notification = new MediaReplacementStatusChanged('sonarr', 'Show', 'msg', 'warning');
    $user = User::factory()->create();

    expect($notification->via($user))->toContain('database', 'broadcast');
});
