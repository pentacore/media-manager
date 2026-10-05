<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('the first active connection of the type is found, the same one resolveActive returns', function (): void {
    ServiceConnection::factory()->sonarr()->inactive()->create();
    $first = ServiceConnection::factory()->sonarr()->create();
    ServiceConnection::factory()->sonarr()->create();
    ServiceConnection::factory()->radarr()->create();

    expect(ServiceConnection::findActive(ServiceType::Sonarr)?->id)->toBe($first->id)
        ->and(ServiceConnection::resolveActive(ServiceType::Sonarr)->id)->toBe($first->id);
});

test('no active connection of the type is null, while resolveActive still throws', function (): void {
    ServiceConnection::factory()->sonarr()->inactive()->create();
    ServiceConnection::factory()->radarr()->create();

    expect(ServiceConnection::findActive(ServiceType::Sonarr))->toBeNull()
        ->and(fn (): ServiceConnection => ServiceConnection::resolveActive(ServiceType::Sonarr))->toThrow(ModelNotFoundException::class);
});
