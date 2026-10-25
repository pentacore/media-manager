<?php

declare(strict_types=1);

use App\Services\HostMonitoring\AgentRelease;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('the latest lookout release is cached', function (): void {
    Http::fake([
        'api.github.com/repos/pentacore/lookout/releases/latest' => Http::response(['tag_name' => 'v0.3.0']),
    ]);

    $this->artisan('servers:check-agent-version')->assertSuccessful();

    expect(AgentRelease::latest())->toBe('0.3.0');
});

test('a repo without releases logs only at debug and caches nothing', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
    Log::spy();

    $this->artisan('servers:check-agent-version')->assertSuccessful();

    Log::shouldHaveReceived('debug')->atLeast()->once();
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');

    expect(AgentRelease::latest())->toBeNull();
});

test('a failed lookup keeps the cached release', function (): void {
    Cache::put(AgentRelease::CACHE_KEY, '0.2.0', 60);
    Http::fake(['api.github.com/*' => Http::response(null, 500)]);

    $this->artisan('servers:check-agent-version')->assertSuccessful();

    expect(AgentRelease::latest())->toBe('0.2.0');
});

test('other non-success answers still log at info', function (): void {
    Http::fake(['api.github.com/*' => Http::response(null, 403)]);
    Log::spy();

    $this->artisan('servers:check-agent-version')->assertSuccessful();

    Log::shouldHaveReceived('info')->once();
});

test('an agent below the latest release is outdated', function (?string $agentVersion, bool $expected): void {
    Cache::put(AgentRelease::CACHE_KEY, '0.3.0', 60);

    expect(AgentRelease::isOutdated($agentVersion))->toBe($expected);
})->with([
    'older patch' => ['0.2.9', true],
    'older with v prefix' => ['v0.2.9', true],
    'same version' => ['0.3.0', false],
    'newer with v prefix' => ['v0.3.1', false],
    'older minor' => ['0.10.0', false],
    'prerelease of the latest' => ['0.3.0-rc1', true],
    'prerelease of a newer version' => ['0.4.0-rc1', false],
    'unreported version' => [null, false],
    'dev build' => ['dev', false],
    'empty string' => ['', false],
    'not a version' => ['unknown', false],
]);

test('an agent is never outdated while the latest release is unknown', function (): void {
    expect(AgentRelease::latest())->toBeNull()
        ->and(AgentRelease::isOutdated('0.1.0'))->toBeFalse();
});

test('the version check runs daily without overlapping', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'servers:check-agent-version'));

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
