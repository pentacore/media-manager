<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Emby\WatchHistoryTool;
use App\Models\EmbyActivity;
use Laravel\Ai\Tools\Request;

test('returns recent watched entries with default 30-day window', function (): void {
    EmbyActivity::factory()->count(3)->create(['action' => 'played', 'created_at' => now()->subDays(2)]);
    EmbyActivity::factory()->create(['action' => 'played', 'created_at' => now()->subDays(60)]);

    $result = json_decode((new WatchHistoryTool)->handle(new Request(['since_days' => null, 'limit' => null])), true);

    expect($result['entries'])->toHaveCount(3);
});

test('honors limit', function (): void {
    EmbyActivity::factory()->count(5)->create(['action' => 'played', 'created_at' => now()->subDay()]);

    $result = json_decode((new WatchHistoryTool)->handle(new Request(['since_days' => 30, 'limit' => 2])), true);

    expect($result['entries'])->toHaveCount(2);
});

test('risk is Read', function (): void {
    expect((new WatchHistoryTool)->risk())->toBe(Risk::Read);
});
