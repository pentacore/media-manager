<?php

declare(strict_types=1);

use App\Ai\Tools\QueryActivityTool;
use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use Laravel\Ai\Tools\Request;

test('returns emby activities by default', function (): void {
    $link = EmbyUserLink::factory()->create();
    EmbyActivity::factory()->count(3)->create(['emby_user_link_id' => $link->id]);

    $result = json_decode((string) (new QueryActivityTool)->handle(new Request([])), true);

    expect($result['scope'])->toBe('emby');
    expect($result['entries'])->toHaveCount(3);
});

test('media_type filter works for emby scope', function (): void {
    $link = EmbyUserLink::factory()->create();
    EmbyActivity::factory()->count(2)->create(['emby_user_link_id' => $link->id, 'media_type' => 'movie']);
    EmbyActivity::factory()->count(3)->create(['emby_user_link_id' => $link->id, 'media_type' => 'episode']);

    $result = json_decode((string) (new QueryActivityTool)->handle(new Request(['media_type' => 'movie'])), true);

    expect($result['entries'])->toHaveCount(2);
});

test('system scope returns activity logs', function (): void {
    ActivityLog::factory()->count(4)->create();

    $result = json_decode((string) (new QueryActivityTool)->handle(new Request(['scope' => 'system'])), true);

    expect($result['scope'])->toBe('system');
    expect($result['entries'])->toHaveCount(4);
});

test('since_days restricts window', function (): void {
    $link = EmbyUserLink::factory()->create();
    EmbyActivity::factory()->create(['emby_user_link_id' => $link->id, 'created_at' => now()->subDays(5)]);
    EmbyActivity::factory()->create(['emby_user_link_id' => $link->id, 'created_at' => now()->subDays(60)]);

    $result = json_decode((string) (new QueryActivityTool)->handle(new Request(['since_days' => 10])), true);

    expect($result['entries'])->toHaveCount(1);
});

test('limit is clamped to 50', function (): void {
    $link = EmbyUserLink::factory()->create();
    EmbyActivity::factory()->count(60)->create(['emby_user_link_id' => $link->id]);

    $result = json_decode((string) (new QueryActivityTool)->handle(new Request(['limit' => 1000])), true);

    expect($result['entries'])->toHaveCount(50);
});
