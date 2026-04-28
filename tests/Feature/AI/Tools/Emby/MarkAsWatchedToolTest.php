<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Emby\MarkAsWatchedTool;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();

    ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'emby-test-key',
        'is_active' => true,
    ]);
});

test('marks an emby item as played', function (): void {
    Http::fake([
        'emby.local:8096/Users/user1/PlayedItems/item1*' => Http::response(['Played' => true]),
    ]);

    $result = json_decode((new MarkAsWatchedTool)->handle(new Request([
        'emby_user_id' => 'user1',
        'emby_item_id' => 'item1',
    ])), true);

    expect($result['played'])->toBeTrue();
    expect($result['item_id'])->toBe('item1');
    expect($result['user_id'])->toBe('user1');
});

test('risk is SafeWrite', function (): void {
    expect((new MarkAsWatchedTool)->risk())->toBe(Risk::SafeWrite);
});
