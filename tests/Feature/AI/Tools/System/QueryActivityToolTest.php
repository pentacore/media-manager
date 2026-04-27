<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\System\QueryActivityTool;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use Laravel\Ai\Tools\Request;

test('emby scope returns recent playback entries', function (): void {
    EmbyActivity::factory()->count(3)->create();

    $tool = new QueryActivityTool;

    $result = json_decode((string) $tool->handle(new Request(['scope' => 'emby', 'since_days' => null, 'media_type' => null, 'limit' => null])), true);

    expect($result['scope'])->toBe('emby');
    expect($result['entries'])->toHaveCount(3);
});

test('system scope returns recent activity logs', function (): void {
    ActivityLog::factory()->count(2)->create();

    $tool = new QueryActivityTool;

    $result = json_decode((string) $tool->handle(new Request(['scope' => 'system', 'since_days' => null, 'media_type' => null, 'limit' => null])), true);

    expect($result['scope'])->toBe('system');
    expect($result['entries'])->toHaveCount(2);
});

test('risk is Read', function (): void {
    expect((new QueryActivityTool)->risk())->toBe(Risk::Read);
});
