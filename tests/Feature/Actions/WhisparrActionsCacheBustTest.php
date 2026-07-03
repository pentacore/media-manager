<?php

declare(strict_types=1);

use App\Cache\Services\WhisparrCache;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Whisparr\WhisparrActions;
use Illuminate\Support\Facades\Http;

test('deleteItem busts the Whisparr cache', function (): void {
    Http::preventStrayRequests();
    Http::fake(['whisparr.local:6969/api/v3/movie/3*' => Http::response(null, 200)]);
    $connection = ServiceConnection::factory()->whisparr()->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'is_active' => true,
    ]);

    // Seed a cached list, then confirm the action clears it.
    new WhisparrCache($connection)->rememberList('list', fn (): array => [['id' => 1]]);

    (new WhisparrActions)->execute(ActionRequest::factory()->create([
        'type' => 'whisparr_delete_item',
        'payload' => ['whisparr_item_id' => 3],
    ]));

    $fresh = new WhisparrCache($connection)->rememberList('list', fn (): array => ['miss']);
    expect($fresh)->toBe(['miss']);
});
