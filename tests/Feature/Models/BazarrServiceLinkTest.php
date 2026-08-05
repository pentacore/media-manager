<?php

declare(strict_types=1);

use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('a Bazarr connection maps one Sonarr and one Radarr connection by role', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $radarr = ServiceConnection::factory()->radarr()->create();

    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);
    BazarrServiceLink::factory()->radarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
    ]);

    expect($bazarr->mappedConnection(BazarrServiceRole::Sonarr)?->is($sonarr))->toBeTrue()
        ->and($bazarr->mappedConnection(BazarrServiceRole::Radarr)?->is($radarr))->toBeTrue();
});

test('a Bazarr connection cannot have duplicate roles', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $firstSonarr = ServiceConnection::factory()->sonarr()->create();
    $secondSonarr = ServiceConnection::factory()->sonarr()->create();

    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $firstSonarr->id,
    ]);

    $caught = false;

    try {
        DB::transaction(function () use ($bazarr, $secondSonarr): void {
            BazarrServiceLink::factory()->sonarr()->create([
                'bazarr_connection_id' => $bazarr->id,
                'related_connection_id' => $secondSonarr->id,
            ]);
        });
    } catch (QueryException) {
        $caught = true;
    }

    expect($caught)->toBeTrue();
});

test('a Bazarr connection cannot map the same related connection twice', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();

    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);

    $caught = false;

    try {
        DB::transaction(function () use ($bazarr, $sonarr): void {
            BazarrServiceLink::factory()->radarr()->create([
                'bazarr_connection_id' => $bazarr->id,
                'related_connection_id' => $sonarr->id,
            ]);
        });
    } catch (QueryException) {
        $caught = true;
    }

    expect($caught)->toBeTrue();
});

test('links cascade when either service connection is deleted', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $sonarrLink = BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);

    $sonarr->delete();

    $this->assertModelMissing($sonarrLink);

    $radarr = ServiceConnection::factory()->radarr()->create();
    $radarrLink = BazarrServiceLink::factory()->radarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
    ]);

    $bazarr->delete();

    $this->assertModelMissing($radarrLink);
});

test('a related connection exposes its incoming Bazarr links', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $link = BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);

    expect($sonarr->incomingBazarrServiceLinks()->sole()->is($link))->toBeTrue();
});

test('factory role states create matching related connection types', function (): void {
    $sonarrLink = BazarrServiceLink::factory()->sonarr()->create();
    $radarrLink = BazarrServiceLink::factory()->radarr()->create();

    expect($sonarrLink->role)->toBe(BazarrServiceRole::Sonarr)
        ->and($sonarrLink->relatedConnection->type)->toBe(ServiceType::Sonarr)
        ->and($radarrLink->role)->toBe(BazarrServiceRole::Radarr)
        ->and($radarrLink->relatedConnection->type)->toBe(ServiceType::Radarr);
});

test('related connection lookups have a standalone database index', function (): void {
    $hasRelatedConnectionIndex = collect(Schema::getIndexes('bazarr_service_links'))->contains(
        fn (array $index): bool => ! $index['unique']
            && $index['columns'] === ['related_connection_id'],
    );

    expect($hasRelatedConnectionIndex)->toBeTrue();
});
