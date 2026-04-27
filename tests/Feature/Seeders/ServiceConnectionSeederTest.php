<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Database\Seeders\ServiceConnectionSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    // Force all env vars to empty so .env-baked values don't leak into tests.
    // putenv('KEY=') with empty RHS sets it to '', which the seeder treats as "unset"
    // (same as getenv returning false) and generates a random token.
    foreach (['SONARR', 'RADARR', 'EMBY', 'SEERR', 'PROWLARR'] as $prefix) {
        putenv($prefix.'_URL=');
        putenv($prefix.'_API_KEY=');
        putenv($prefix.'_WEBHOOK_TOKEN=');
        putenv($prefix.'_NAME=');
    }
});

afterEach(function (): void {
    foreach (['SONARR', 'RADARR', 'EMBY', 'SEERR', 'PROWLARR'] as $prefix) {
        putenv($prefix.'_URL');
        putenv($prefix.'_API_KEY');
        putenv($prefix.'_WEBHOOK_TOKEN');
        putenv($prefix.'_NAME');
    }
});

test('creates connections for services with url and api key set', function (): void {
    putenv('SONARR_URL=http://sonarr.example:8989');
    putenv('SONARR_API_KEY=sonarr-key');
    putenv('RADARR_URL=http://radarr.example:7878');
    putenv('RADARR_API_KEY=radarr-key');
    putenv('EMBY_URL=http://emby.example:8096');
    putenv('EMBY_API_KEY=emby-key');
    putenv('SEERR_URL=http://seerr.example:5055');
    putenv('SEERR_API_KEY=seerr-key');

    $this->seed(ServiceConnectionSeeder::class);

    expect(ServiceConnection::count())->toBe(4);
    expect(ServiceConnection::where('type', ServiceType::Sonarr)->value('url'))->toBe('http://sonarr.example:8989');
    expect(ServiceConnection::where('type', ServiceType::Radarr)->value('url'))->toBe('http://radarr.example:7878');
    expect(ServiceConnection::where('type', ServiceType::Emby)->value('url'))->toBe('http://emby.example:8096');
    expect(ServiceConnection::where('type', ServiceType::Seerr)->value('url'))->toBe('http://seerr.example:5055');
});

test('skips services without both url and api key', function (): void {
    putenv('SONARR_URL=http://sonarr.example:8989');
    putenv('SONARR_API_KEY=');
    putenv('RADARR_URL=http://radarr.example:7878');
    putenv('RADARR_API_KEY=radarr-key');

    $this->seed(ServiceConnectionSeeder::class);

    expect(ServiceConnection::where('type', ServiceType::Sonarr)->exists())->toBeFalse();
    expect(ServiceConnection::where('type', ServiceType::Radarr)->exists())->toBeTrue();
});

test('skips when a connection with the same URL already exists', function (): void {
    ServiceConnection::withoutEvents(fn () => ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.existing:8989',
    ]));

    putenv('SONARR_URL=http://sonarr.existing:8989');
    putenv('SONARR_API_KEY=new-key');

    $this->seed(ServiceConnectionSeeder::class);

    expect(ServiceConnection::where('type', ServiceType::Sonarr)->count())->toBe(1);
});

test('generates a random webhook_token when not provided', function (): void {
    putenv('SONARR_URL=http://sonarr.example:8989');
    putenv('SONARR_API_KEY=sonarr-key');

    $this->seed(ServiceConnectionSeeder::class);

    $connection = ServiceConnection::where('type', ServiceType::Sonarr)->first();
    expect($connection)->not->toBeNull();
    expect($connection->webhook_token)->toBeString();
    expect(strlen((string) $connection->webhook_token))->toBeGreaterThanOrEqual(40);
});

test('does not fire the observer during seed', function (): void {
    Queue::fake();

    putenv('SONARR_URL=http://sonarr.example:8989');
    putenv('SONARR_API_KEY=sonarr-key');

    $this->seed(ServiceConnectionSeeder::class);

    Queue::assertNothingPushed();
});
