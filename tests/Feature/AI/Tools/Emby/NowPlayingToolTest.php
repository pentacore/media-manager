<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Emby\NowPlayingTool;
use App\Models\EmbyActivity;
use Laravel\Ai\Tools\Request;

test('returns currently-playing emby sessions (action=played, recent)', function (): void {
    EmbyActivity::factory()->create(['action' => 'played', 'updated_at' => now()->subMinute(), 'media_title' => 'Demo Movie']);
    EmbyActivity::factory()->create(['action' => 'stopped', 'updated_at' => now()->subMinute()]);
    EmbyActivity::factory()->create(['action' => 'played', 'updated_at' => now()->subHour()]);

    $result = json_decode((new NowPlayingTool)->handle(new Request([])), true);

    expect($result['sessions'])->toHaveCount(1);
    expect($result['sessions'][0]['media_title'])->toBe('Demo Movie');
});

test('risk is Read', function (): void {
    expect((new NowPlayingTool)->risk())->toBe(Risk::Read);
});
